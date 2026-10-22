<?php

namespace Tests\Feature;

use App\Filament\Applicant\Pages\Application;
use App\Filament\Applicant\Pages\Requirements;
use App\Models\AdmissionApplication;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationSubmissionVersion;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
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
            ->assertSee('Servitech Institute Asia')
            ->assertDontSee('Applicant Workspace')
            ->assertDontSee('Powered by TALA')
            ->assertDontSee('Current and earlier applications');

        $this->actingAs($user)
            ->get('/applicant/history')
            ->assertOk()
            ->assertSee("Applicant's History")
            ->assertSee('Current and earlier applications')
            ->assertSee('No application yet');
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

    public function test_application_navigation_follows_the_owned_current_case_without_opening_an_empty_form(): void
    {
        $user = $this->applicant();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $this->assertSame(Application::getUrl(), Application::getNavigationUrl());

        $application = AdmissionApplication::factory()->recycle(Term::factory()->create())->create([
            'user_id' => $user->id,
        ]);
        $this->assertSame(Application::getUrl(['application' => $application->id]), Application::getNavigationUrl());

        $foreign = AdmissionApplication::factory()->recycle($application->term)->create([
            'updated_at' => now()->addHour(),
        ]);

        foreach ([
            AdmissionApplication::StateDraft,
            AdmissionApplication::StateActionNeeded,
            AdmissionApplication::StateSubmitted,
            AdmissionApplication::StateAdmitted,
            AdmissionApplication::StateNotAdmitted,
            AdmissionApplication::StateWithdrawn,
        ] as $state) {
            $application->forceFill(['application_state' => $state])->save();
            $expected = in_array($state, [AdmissionApplication::StateDraft, AdmissionApplication::StateActionNeeded], true)
                ? Application::getUrl(['application' => $application->id])
                : Requirements::getUrl(['application' => $application->id]);
            $this->assertSame($expected, Application::getNavigationUrl());
            $this->get('/applicant')->assertOk()->assertSee('href="'.$expected.'"', false);
        }
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
            ->assertSee('Requirements')
            ->assertSee('Enrollment clearance')
            ->assertDontSee('Preliminary copies')
            ->assertDontSee('Student Record Created');

        $this->actingAs($user)
            ->get('/applicant/requirements')
            ->assertOk()
            ->assertSee('Review copies')
            ->assertSee('Physical credentials are checked after admission.')
            ->assertDontSee('Registrar enrollment clearance')
            ->assertSee('Form 138 or equivalent')
            ->assertDontSee('Blocks Handover');

        $application->update(['application_state' => AdmissionApplication::StateAdmitted]);

        $this->get('/applicant/requirements')
            ->assertOk()
            ->assertSee('Review copies')
            ->assertSee('Registrar enrollment clearance')
            ->assertSee('The Registrar checks your physical credentials')
            ->assertSee('Awaiting current Registrar clearance');
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
