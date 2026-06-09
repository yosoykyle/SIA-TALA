<?php

namespace Tests\Feature;

use App\Models\FacultySubjectEligibility;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FacultySubjectEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_global_or_matching_term_eligibility_allows_assignment_lookup(): void
    {
        $faculty = User::factory()->create();
        $subject = Subject::factory()->create();
        $term = Term::factory()->create();

        FacultySubjectEligibility::factory()->create([
            'faculty_id' => $faculty->id,
            'subject_id' => $subject->id,
            'term_id' => null,
        ]);

        $this->assertTrue(FacultySubjectEligibility::isActiveFor($faculty->id, $subject->id, $term->id));
    }

    public function test_inactive_or_different_term_eligibility_does_not_allow_assignment_lookup(): void
    {
        $faculty = User::factory()->create();
        $subject = Subject::factory()->create();
        $term = Term::factory()->create();
        $otherTerm = Term::factory()->create();

        FacultySubjectEligibility::factory()->inactive()->create([
            'faculty_id' => $faculty->id,
            'subject_id' => $subject->id,
            'term_id' => null,
        ]);

        FacultySubjectEligibility::factory()->create([
            'faculty_id' => $faculty->id,
            'subject_id' => $subject->id,
            'term_id' => $otherTerm->id,
        ]);

        $this->assertFalse(FacultySubjectEligibility::isActiveFor($faculty->id, $subject->id, $term->id));
    }

    public function test_faculty_can_view_only_their_own_eligibility_and_cannot_manage_it(): void
    {
        $faculty = $this->facultyUser();
        $otherFaculty = $this->facultyUser();
        $eligibility = FacultySubjectEligibility::factory()->create([
            'faculty_id' => $faculty->id,
        ]);
        $otherEligibility = FacultySubjectEligibility::factory()->create([
            'faculty_id' => $otherFaculty->id,
        ]);

        $this->assertTrue($faculty->can('viewAny', FacultySubjectEligibility::class));
        $this->assertTrue($faculty->can('view', $eligibility));
        $this->assertFalse($faculty->can('view', $otherEligibility));
        $this->assertFalse($faculty->can('create', FacultySubjectEligibility::class));
        $this->assertFalse($faculty->can('update', $eligibility));
    }

    public function test_authorized_staff_can_manage_eligibilities(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo(Permission::findOrCreate('manage-faculty-subject-eligibilities'));
        $eligibility = FacultySubjectEligibility::factory()->create();

        $this->assertTrue($manager->can('viewAny', FacultySubjectEligibility::class));
        $this->assertTrue($manager->can('view', $eligibility));
        $this->assertTrue($manager->can('create', FacultySubjectEligibility::class));
        $this->assertTrue($manager->can('update', $eligibility));
        $this->assertFalse($manager->can('delete', $eligibility));
    }

    private function facultyUser(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $facultyRole = Role::findOrCreate(User::StaffRoleFaculty);
        $faculty = User::factory()->create();
        $faculty->assignRole($facultyRole);

        return $faculty;
    }
}
