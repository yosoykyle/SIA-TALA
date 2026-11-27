<?php

namespace Tests\Feature;

use App\Filament\Resources\Enrollments\Pages\ListEnrollments;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
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

    public function test_retired_gate_review_summary_action_is_removed(): void
    {
        $this->assertFileDoesNotExist(app_path('Actions/Enrollment/EnrollmentGateReviewSummary.php'));
        $this->assertFalse(class_exists('App\\Actions\\Enrollment\\EnrollmentGateReviewSummary'));
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

        foreach ([$registrar, $academicHead, $accounting] as $viewer) {
            $this->assertTrue(Gate::forUser($viewer)->allows('view', $enrollment));
        }

        $this->assertFalse(Gate::forUser($systemSuperAdmin)->allows('view', $enrollment));
        $this->assertFalse(Gate::forUser($faculty)->allows('view', $enrollment));

        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $enrollment->getRouteKey()])
            ->assertSee($enrollment->term->label)
            ->assertSee('Retry automatic preparation')
            ->assertSee('Enrollment history')
            ->assertDontSee('Enrollment Gate Review');

        Livewire::actingAs($registrar)->withQueryParams(['view' => 'history'])
            ->test(ViewEnrollment::class, ['record' => $enrollment->getRouteKey()])
            ->assertSee('Recorded changes')
            ->assertSee('No enrollment activity recorded')
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
