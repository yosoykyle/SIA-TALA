<?php

namespace Tests\Feature;

use App\Filament\Applicant\Pages\Application;
use App\Models\AdmissionApplication;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationSubmissionVersion;
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

    public function test_verified_applicant_can_access_the_bounded_empty_workspace(): void
    {
        $user = $this->applicant();

        $this->actingAs($user)
            ->get('/applicant')
            ->assertOk()
            ->assertSee('TALA Applicant Workspace')
            ->assertSee('No application yet')
            ->assertSee('Current and earlier Applications');
    }

    public function test_requirements_empty_state_explains_its_version_boundary_and_links_to_application(): void
    {
        $user = $this->applicant();

        $this->actingAs($user)
            ->get('/applicant/requirements')
            ->assertOk()
            ->assertSee('Requirements are not available yet')
            ->assertSee('Open application')
            ->assertSee(Application::getUrl(), false);
    }

    public function test_application_page_exposes_the_five_step_journey_and_partial_draft_action(): void
    {
        $source = file_get_contents(app_path('Filament/Applicant/Pages/Application.php'));
        $view = file_get_contents(resource_path('views/filament/applicant/pages/application.blade.php'));

        $this->assertIsString($source);
        $this->assertIsString($view);
        foreach ([
            'Application choice',
            'Identity and contact',
            'Prior education',
            'Preliminary evidence',
            'Review and submit',
        ] as $step) {
            $this->assertStringContainsString("Step::make('{$step}')", $source);
        }
        $this->assertStringContainsString('Save draft', $view);
        $this->assertStringContainsString('Submit application', file_get_contents(
            resource_path('views/filament/applicant/components/application-submit-action.blade.php'),
        ));
    }

    public function test_draft_has_no_version_bound_requirement_set_yet(): void
    {
        $user = $this->applicant();
        AdmissionApplication::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get('/applicant/requirements')
            ->assertOk()
            ->assertSee('Requirements are not available yet')
            ->assertSee('Open application');
    }

    public function test_submitted_application_projects_status_and_separate_preliminary_and_official_requirements(): void
    {
        $user = $this->applicant();
        $application = AdmissionApplication::factory()->submitted()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'application_reference' => 'APP-2026-TEST-001',
        ]);
        $set = AdmissionRequirementSet::factory()->create([
            'admission_cycle_id' => $application->admission_cycle_id,
            'application_path' => $application->application_path,
        ]);
        AdmissionRequirement::factory()->create([
            'admission_requirement_set_id' => $set->id,
            'label' => 'Form 138 or equivalent',
            'requires_preliminary_evidence' => true,
        ]);
        $set->update([
            'state' => AdmissionRequirementSet::StatePublished,
            'effective_at' => now()->subMinute(),
            'published_at' => now()->subMinute(),
        ]);
        $version = ApplicationSubmissionVersion::factory()->create([
            'admission_application_id' => $application->id,
            'admission_requirement_set_id' => $set->id,
            'submitted_by' => $user->id,
        ]);
        $application->update(['current_submission_version_id' => $version->id]);

        $this->actingAs($user)
            ->get('/applicant')
            ->assertOk()
            ->assertSee('APP-2026-TEST-001')
            ->assertSee('Submitted')
            ->assertSee('Preliminary evidence readiness')
            ->assertSee('Official credential readiness')
            ->assertDontSee('Student Record Created');

        $this->actingAs($user)
            ->get('/applicant/requirements')
            ->assertOk()
            ->assertSee('Preliminary digital review')
            ->assertSee('Official credential verification')
            ->assertSee('Form 138 or equivalent')
            ->assertDontSee('Blocks Handover');
    }

    private function applicant(): User
    {
        $user = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('applicant');

        return $user;
    }
}
