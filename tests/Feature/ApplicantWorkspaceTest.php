<?php

namespace Tests\Feature;

use App\Models\AdmissionRequirementPolicy;
use App\Models\ApplicantIntake;
use App\Models\ChecklistItem;
use App\Models\DocumentEvidence;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApplicantWorkspaceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('test_tala_db', config('database.connections.mysql.database'));
        Role::findOrCreate('applicant', 'web');
        Role::findOrCreate('student', 'web');
    }

    public function test_guest_is_redirected_to_applicant_login(): void
    {
        $this->get('/applicant')
            ->assertRedirect(route('filament.applicant.auth.login'));
    }

    public function test_non_applicant_roles_are_forbidden_from_applicant_workspace(): void
    {
        $user = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('student');

        $this->actingAs($user)
            ->get('/applicant')
            ->assertForbidden();
    }

    public function test_applicant_with_pending_status_and_verified_email_can_access_applicant_workspace(): void
    {
        $user = User::factory()->create([
            'status' => User::StatusApplicantPending,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('applicant');

        $this->actingAs($user)
            ->get('/applicant')
            ->assertOk()
            ->assertSee('TALA Applicant Workspace')
            ->assertSee('Start Your Application'); // Empty state when no intake exists
    }

    public function test_applicant_with_intake_displays_status_and_checklist(): void
    {
        $user = User::factory()->create([
            'status' => User::StatusApplicantPending,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('applicant');

        $term = Term::factory()->create([
            'label' => 'First Semester 2026-2027',
            'state' => Term::StateActive,
        ]);
        $program = Program::factory()->create([
            'name' => 'Bachelor of Science in Information Technology',
        ]);

        $intake = ApplicantIntake::factory()->create([
            'user_id' => $user->id,
            'term_id' => $term->id,
            'program_id' => $program->id,
            'status' => ApplicantIntake::StatusPending,
            'admission_category' => ApplicantIntake::AdmissionCategoryFirstTimeCollege,
            'submitted_at' => now(),
        ]);

        $policy = AdmissionRequirementPolicy::factory()->create([
            'requirement_type' => 'BIRTH_CERTIFICATE',
            'evidence_method' => 'DIGITAL_UPLOAD',
            'blocking_level' => ChecklistItem::BlockingHandover,
        ]);
        $checklist = ChecklistItem::create([
            'owner_type' => ChecklistItem::OwnerApplicant,
            'applicant_intake_id' => $intake->id,
            'student_profile_id' => null,
            'source_policy_id' => $policy->id,
            'requirement_type' => 'BIRTH_CERTIFICATE',
            'status' => ChecklistItem::StatusPending,
            'blocking_level' => ChecklistItem::BlockingHandover,
            'evidence_method' => 'DIGITAL_UPLOAD',
            'verification_status' => ChecklistItem::VerificationNotReviewed,
            'undertaking_terms' => 'Submit original copy',
        ]);

        DocumentEvidence::factory()->create([
            'checklist_item_id' => $checklist->id,
            'disk' => 'local',
            'path' => 'uploads/id.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'status' => 'SUBMITTED',
            'uploaded_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get('/applicant')
            ->assertOk()
            ->assertSee('TALA Applicant Workspace')
            ->assertSee('Application Status')
            ->assertSee('Pending Review')
            ->assertSee('First Semester 2026-2027')
            ->assertSee('Bachelor of Science in Information Technology')
            ->assertSee('Birth certificate')
            ->assertSee('Blocks Handover')
            ->assertSee('Submit original copy')
            ->assertSee('Birth certificate')
            ->assertSee('id.pdf')
            ->assertSee('Submitted')
            ->assertDontSee('Start Your Application');
    }
}
