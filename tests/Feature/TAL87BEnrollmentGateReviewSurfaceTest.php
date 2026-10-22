<?php

namespace Tests\Feature;

use App\Actions\Enrollment\EnrollmentGateReviewSummary;
use App\Filament\Resources\Enrollments\Pages\ListEnrollments;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\EnrollmentGateResult;
use App\Models\Section;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL87BEnrollmentGateReviewSurfaceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_test_codex', DB::connection()->getDatabaseName());

        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleAccounting,
            User::StaffRoleSystemSuperAdmin,
            User::StaffRoleFaculty,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_gate_review_summary_uses_recorded_rows_and_synthetic_not_checked_rows_without_writes(): void
    {
        $enrollment = Enrollment::factory()->create(['status' => 'pending_review']);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => TermOffering::factory()->for($enrollment->term)->create()->id,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);

        EnrollmentGateResult::query()->create([
            'enrollment_id' => $enrollment->id,
            'gate_type' => 'finance',
            'sequence' => 4,
            'result' => EnrollmentGateResult::ResultFailed,
            'responsible_office' => 'accounting',
            'blocker_code' => 'payment_pending',
            'blocker_message' => 'Payment still requires Accounting confirmation.',
            'source_type' => CourseEnrollment::class,
            'source_id' => $courseEnrollment->id,
            'checked_at' => now(),
            'rule_version' => 'tal-87b-test',
        ]);

        $summary = app(EnrollmentGateReviewSummary::class);
        $rows = $summary->rows($enrollment);

        $this->assertCount(9, $rows);
        $this->assertSame(1, EnrollmentGateResult::query()
            ->whereBelongsTo($enrollment)
            ->count());
        $this->assertSame('Identity', $rows[0]['label']);
        $this->assertSame('Not Checked', $rows[0]['result_label']);

        $finance = collect($rows)->firstWhere('gate_type', 'finance');

        $this->assertSame('Failed', $finance['result_label']);
        $this->assertSame('Accounting Office', $finance['office_label']);
        $this->assertSame('payment_pending', $finance['blocker_code']);
        $this->assertSame('Payment still requires Accounting confirmation.', $finance['blocker_message']);
        $this->assertSame('Course Enrollment #'.$courseEnrollment->id, $finance['source_reference']);
        $this->assertTrue($finance['is_recorded']);
        $this->assertSame('Finance: Payment still requires Accounting confirmation.', $summary->compactStatus($enrollment));
    }

    public function test_staff_registration_surface_uses_canonical_case_facts_and_hides_generic_gate_actions(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $systemSuperAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $enrollment = Enrollment::factory()->create([
            'student_type' => 'irregular',
            'status' => 'pending_review',
        ]);
        $termOffering = TermOffering::factory()->for($enrollment->term)->create();
        $proposedSection = Section::factory()->for($termOffering)->create();

        CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $termOffering->id,
            'proposed_section_id' => $proposedSection->id,
            'proposed_at' => now(),
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);

        EnrollmentGateResult::query()->create([
            'enrollment_id' => $enrollment->id,
            'gate_type' => 'finance',
            'sequence' => 4,
            'result' => EnrollmentGateResult::ResultFailed,
            'responsible_office' => 'accounting',
            'blocker_code' => 'payment_pending',
            'blocker_message' => 'Payment still requires Accounting confirmation.',
            'source_type' => Enrollment::class,
            'source_id' => $enrollment->id,
            'checked_at' => now(),
            'rule_version' => 'tal-87b-test',
        ]);

        foreach ([$registrar, $academicHead, $accounting] as $viewer) {
            $this->assertTrue(Gate::forUser($viewer)->allows('view', $enrollment));
        }

        $this->assertFalse(Gate::forUser($systemSuperAdmin)->allows('view', $enrollment));
        $this->assertFalse(Gate::forUser($faculty)->allows('view', $enrollment));

        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $enrollment->getRouteKey()])
            ->assertSee($enrollment->term->label)
            ->assertSee('Prepare subjects')
            ->assertSee('Enrollment history')
            ->assertDontSee('Enrollment Gate Review');

        Livewire::actingAs($registrar)->withQueryParams(['view' => 'history'])
            ->test(ViewEnrollment::class, ['record' => $enrollment->getRouteKey()])
            ->assertSee('Activity')
            ->assertSee('Supporting records')
            ->assertSee($enrollment->case_reference);

        Livewire::actingAs($academicHead)->withQueryParams([])
            ->test(ViewEnrollment::class, ['record' => $enrollment->getRouteKey()])
            ->assertSee($enrollment->term->label)
            ->assertSee('Subjects being prepared')
            ->assertSee('Read-only')
            ->assertDontSee('Enrollment Gate Review');

        Livewire::actingAs($accounting)->withQueryParams([])
            ->test(ViewEnrollment::class, ['record' => $enrollment->getRouteKey()])
            ->assertSee($enrollment->term->label)
            ->assertSee('Waiting for the Registrar')
            ->assertSee('Enrollment details')
            ->assertDontSee('Enrollment Gate Review');

        Livewire::actingAs($registrar)->withQueryParams([])
            ->test(ListEnrollments::class)
            ->set('activeTab', 'all_cases')
            ->filterTable('term', $enrollment->term_id)
            ->assertCanSeeTableRecords([$enrollment])
            ->assertDontSee('Finance: Payment still requires Accounting confirmation.');
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
