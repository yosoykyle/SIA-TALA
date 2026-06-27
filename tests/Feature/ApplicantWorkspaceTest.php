<?php

namespace Tests\Feature;

use App\Models\ApplicantIntake;
use App\Models\ChecklistItem;
use App\Models\DocumentUpload;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApplicantWorkspaceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure canonical roles exist
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

        // Create mock term and program for relationship
        $term = Term::factory()->create([
            'term_name' => 'First Semester 2026-2027',
            'is_active' => true,
        ]);
        $program = Program::factory()->create([
            'name' => 'Bachelor of Science in Information Technology',
        ]);

        // Create applicant intake
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $user->id,
            'term_id' => $term->id,
            'program_id' => $program->id,
            'status' => ApplicantIntake::StatusPending,
            'applicant_type' => ApplicantIntake::ApplicantTypeNew,
            'submitted_at' => now(),
        ]);

        // Create checklist items
        $checklist = ChecklistItem::create([
            'owner_type' => ApplicantIntake::class,
            'owner_id' => $intake->id,
            'requirement_type' => 'birth_certificate',
            'status' => 'pending',
            'blocking_level' => 'blocks_handover',
            'evidence_method' => 'physical_copy',
            'notes' => 'Submit original copy',
        ]);

        // Create document upload
        $upload = DocumentUpload::forceCreate([
            'applicant_intake_id' => $intake->id,
            'user_id' => $user->id,
            'term_id' => $term->id,
            'document_type' => 'identity_document',
            'file_disk' => 'local',
            'file_path' => 'uploads/id.pdf',
            'file_name' => 'id.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'upload_status' => 'uploaded',
            'review_status' => DocumentUpload::ReviewStatusPendingRegistrarReview,
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
            ->assertSee('Identity document')
            ->assertSee('id.pdf')
            ->assertSee('Pending Registrar Review')
            ->assertDontSee('Start Your Application');
    }
}
