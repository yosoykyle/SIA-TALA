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
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
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

    public function test_staff_gate_review_surface_is_read_only_policy_aligned_and_keeps_placement_action_scoped(): void
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

        foreach ([$registrar, $academicHead, $accounting, $systemSuperAdmin] as $viewer) {
            $this->assertTrue(Gate::forUser($viewer)->allows('view', $enrollment));
        }

        $this->assertFalse(Gate::forUser($faculty)->allows('view', $enrollment));

        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $enrollment->getRouteKey()])
            ->assertSee('Enrollment Gate Review')
            ->assertSee('Finance')
            ->assertSee('Failed')
            ->assertSee('Accounting Office')
            ->assertSee('Payment still requires Accounting confirmation.')
            ->assertSee('Not Checked')
            ->assertSee('Final Approval')
            ->assertActionVisible('confirmPlacement');

        foreach ([$academicHead, $accounting] as $viewer) {
            Livewire::actingAs($viewer)
                ->test(ViewEnrollment::class, ['record' => $enrollment->getRouteKey()])
                ->assertSee('Enrollment Gate Review')
                ->assertActionHidden('confirmPlacement');
        }

        Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->assertSee('Next Step')
            ->assertSee('Finance: Payment still requires Accounting confirmation.');
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
