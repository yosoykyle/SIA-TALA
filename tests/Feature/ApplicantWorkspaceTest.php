<?php

namespace Tests\Feature;

use App\Filament\Applicant\Pages\Application;
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
            ->assertSee('No active application')
            ->assertSee('Application History');
    }

    public function test_applicant_dashboard_empty_state_uses_bounded_icon_and_action_layout_hooks(): void
    {
        $dashboard = file_get_contents(resource_path('views/filament/applicant/pages/dashboard.blade.php'));
        $styles = file_get_contents(public_path('css/tala-filament.css'));

        $this->assertIsString($dashboard);
        $this->assertIsString($styles);
        $this->assertStringContainsString('<x-filament::icon', $dashboard);
        $this->assertStringNotContainsString('<svg class="size-16"', $dashboard);
        $this->assertStringContainsString('tala-empty-state__icon', $dashboard);
        $this->assertStringContainsString('tala-empty-state__actions', $dashboard);
        $this->assertMatchesRegularExpression(
            '/\.tala-empty-state__icon\s*\{[^}]*width:\s*4rem;[^}]*height:\s*4rem;/s',
            $styles,
        );
        $this->assertMatchesRegularExpression(
            '/\.tala-empty-state__actions\s*\{[^}]*margin-top:\s*0\.5rem;/s',
            $styles,
        );
    }

    public function test_requirements_empty_state_explains_its_purpose_and_links_to_the_application(): void
    {
        $user = User::factory()->create([
            'status' => User::StatusApplicantPending,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('applicant');

        $this->actingAs($user)
            ->get('/applicant/requirements')
            ->assertOk()
            ->assertSee('Admission Requirements')
            ->assertSee('Start Application')
            ->assertSee(Application::getUrl(), false);
    }

    public function test_application_validates_wizard_progress_without_blocking_partial_drafts(): void
    {
        $application = file_get_contents(app_path('Filament/Applicant/Pages/Application.php'));

        $this->assertIsString($application);
        $this->assertStringContainsString('Fields marked * are required for final submission', $application);
        $this->assertGreaterThanOrEqual(
            15,
            substr_count($application, '->required(fn (): bool => ! $this->savingDraft)'),
        );
        $this->assertStringContainsString(
            '->required(fn (): bool => $isBlocking && ! $this->savingDraft)',
            $application,
        );
    }

    public function test_requirements_page_explains_that_a_draft_has_no_registrar_checklist_yet(): void
    {
        $user = User::factory()->create([
            'status' => User::StatusApplicantPending,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('applicant');

        ApplicantIntake::factory()->create([
            'user_id' => $user->id,
            'status' => ApplicantIntake::StatusDraft,
            'submitted_at' => null,
        ]);

        $this->actingAs($user)
            ->get('/applicant/requirements')
            ->assertOk()
            ->assertSee('Your application is still a draft')
            ->assertSee('Continue Application')
            ->assertSee(Application::getUrl(), false)
            ->assertDontSee('Registrar Feedback / Instruction')
            ->assertDontSee('No requirements are recorded yet.');
    }

    public function test_applicant_home_stays_compact_and_requirements_displays_checklist_detail(): void
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
            ->assertSee('0 of 1 requirement resolved; 1 outstanding; 1 blocks handover')
            ->assertSee('Review Requirements')
            ->assertDontSee('Birth Certificate')
            ->assertDontSee('Submit original copy')
            ->assertDontSee('id.pdf')
            ->assertDontSee('Submitted Digital Documents')
            ->assertDontSee('Start Your Application');

        $this->actingAs($user)
            ->get('/applicant/requirements')
            ->assertOk()
            ->assertSee('Your Requirements')
            ->assertSeeHtml('class="tala-requirements-list"')
            ->assertSee('Birth Certificate')
            ->assertSee('Blocks Handover')
            ->assertSee('Submit original copy')
            ->assertSee('Upload Online')
            ->assertSee('Latest evidence')
            ->assertSee('File submitted');
    }
}
