<?php

namespace Tests\Feature;

use App\Actions\Admissions\AdmissionReviewGuard;
use App\Actions\Admissions\ChangeAdmissionApplicationLifecycle;
use App\Filament\Applicant\Pages\Application;
use App\Filament\Applicant\Pages\SubmissionReceipt;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationSubmissionVersion;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Issue64ApplicationRecoveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_review_start_refuses_a_mounted_edit_without_a_false_success_or_lost_input(): void
    {
        [$application, $applicant, $registrar] = $this->source();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $page = Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id])
            ->fillForm(['first_name' => 'Retained input', 'accuracy_declared' => true]);
        app(AdmissionReviewGuard::class)->start($application, $registrar, $application->current_submission_version_id);
        $page->call('submitApplication')->assertSet('saveStatus', 'failed')
            ->assertHasErrors(['data.application_state'])->assertNoRedirect()
            ->assertSet('data.first_name', 'Retained input');
        $this->assertSame(1, $application->submissionVersions()->count());
        $this->assertNotSame('Retained input', $application->fresh()->first_name);
    }

    public function test_review_lock_survives_withdrawal_and_authorized_reopening(): void
    {
        [$application, $applicant, $registrar] = $this->source();
        app(AdmissionReviewGuard::class)->start($application, $registrar, $application->current_submission_version_id);
        $withdrawn = app(ChangeAdmissionApplicationLifecycle::class)->withdrawByApplicant($application, $applicant, 'Synthetic withdrawal');
        $reopened = app(ChangeAdmissionApplicationLifecycle::class)->reopen($withdrawn, $registrar, 'Synthetic authorized reopening', 'SYNTHETIC-64');
        $this->assertSame($application->application_reference, $reopened->application_reference);
        $this->assertFalse($reopened->allowsSubmittedEdit());
        $this->assertSame(1, $reopened->submissionVersions()->count());
    }

    public function test_another_tab_first_submission_is_not_reported_as_this_pages_success(): void
    {
        [$application, $applicant] = $this->source(submitted: false);
        $application->program->update(['is_active' => true]);
        $application->admissionCycle->programs()->attach($application->program_id, ['accepts_first_year' => true]);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $originalName = $application->first_name;
        $page = Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id])
            ->fillForm(['first_name' => 'Uncommitted other tab input', 'accuracy_declared' => true]);
        $page->assertSet('mountedSubmissionVersionId', null);
        $version = ApplicationSubmissionVersion::factory()->for($application, 'application')
            ->for($application->admissionCycle->requirementSets()->sole(), 'requirementSet')->create(['submitted_by' => $applicant->id]);
        $application->update(['application_state' => AdmissionApplication::StateSubmitted, 'current_submission_version_id' => $version->id]);

        $page->call('submitApplication')->assertSet('saveStatus', 'failed')
            ->assertHasErrors(['data.application_state'])->assertNoRedirect()
            ->assertSet('data.first_name', 'Uncommitted other tab input');
        $this->assertSame(1, $application->submissionVersions()->count());
        $this->assertSame($originalName, $application->fresh()->first_name);
    }

    public function test_public_program_entry_does_not_restore_submitted_history_from_an_earlier_cycle(): void
    {
        [$application, $applicant, $registrar] = $this->source();
        app(AdmissionReviewGuard::class)->start($application, $registrar, $application->current_submission_version_id);
        $application->admissionCycle->update(['closes_at' => now()->subSecond()]);
        $next = AdmissionCycle::factory()->published()->create([
            'term_id' => $application->term_id, 'opens_at' => now()->subDay(), 'closes_at' => now()->addDay(),
        ]);
        $application->program->update(['is_active' => true]);
        $next->programs()->attach($application->program_id, ['accepts_first_year' => true]);
        $set = AdmissionRequirementSet::factory()->for($next)->published()->create();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $page = Livewire::actingAs($applicant)->test(Application::class, ['entryProgramId' => $application->program_id])
            ->set('data.application_path', AdmissionApplication::PathFirstYear);
        $this->assertNull($page->instance()->currentApplication());
        $page->assertSet('data.admission_cycle_id', $next->id)->assertSet('data.requirement_set_id', $set->id);
        $this->assertSame(1, $application->submissionVersions()->count());
    }

    public function test_receipt_uses_the_owned_immutable_source_and_omits_private_office_notes(): void
    {
        [$application, $applicant] = $this->source(requirements: [
            ['label' => 'Synthetic copy', 'applicant_instructions' => 'Synthetic source instruction'],
            ['label' => 'Received private copy', 'due_stage' => 'PreliminaryReview',
                'evidence' => ['id' => 1], 'applicant_instructions' => 'Upload the private copy again'],
        ]);
        $version = $application->currentSubmissionVersion;
        $laterVersion = ApplicationSubmissionVersion::factory()->for($application, 'application')
            ->for($version->requirementSet, 'requirementSet')->create([
                'version' => 2, 'submitted_by' => $applicant->id,
                'snapshot' => ['first_name' => 'Later current facts', 'requirements' => []],
            ]);
        $application->update(['first_name' => 'Later current facts', 'current_submission_version_id' => $laterVersion->id]);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $url = SubmissionReceipt::getUrl(['application' => $application->id, 'version' => $version->id]);
        $this->actingAs($applicant)->get($url)->assertOk()->assertSee('Retained source name')
            ->assertSee('Synthetic Program')->assertSee('Synthetic source instruction')
            ->assertSee('Earlier submitted version')->assertSee('Instructions issued with version 1')
            ->assertSee('Copies received with this version')->assertSee('Received private copy')
            ->assertDontSee('Upload the private copy again')
            ->assertSee($application->application_reference)->assertDontSee('Later current facts');
        $outsider = User::factory()->create(['status' => User::StatusActive]);
        $outsider->assignRole('applicant');
        $this->actingAs($outsider)->get($url)->assertNotFound()->assertDontSee($application->application_reference);
    }

    /**
     * @param  list<array<string, mixed>>  $requirements
     * @return array{AdmissionApplication, User, User}
     */
    private function source(bool $submitted = true, array $requirements = []): array
    {
        Role::findOrCreate('applicant', 'web');
        Role::findOrCreate(User::StaffRoleRegistrar, 'web')->givePermissionTo(Permission::findOrCreate('approve-documents', 'web'));
        $applicant = User::factory()->create(['status' => User::StatusActive]);
        $applicant->assignRole('applicant');
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $cycle = AdmissionCycle::factory()->published()->create(['term_id' => Term::factory()->create(['state' => Term::StateActive])->id]);
        $application = AdmissionApplication::factory()->create([
            'user_id' => $applicant->id, 'admission_cycle_id' => $cycle->id,
            'application_state' => $submitted ? AdmissionApplication::StateSubmitted : AdmissionApplication::StateDraft,
        ]);
        $set = AdmissionRequirementSet::factory()->for($cycle)->published()->create();
        if (! $submitted) {
            return [$application, $applicant, $registrar];
        }
        $version = ApplicationSubmissionVersion::factory()->for($application, 'application')->for($set, 'requirementSet')->create([
            'submitted_by' => $applicant->id,
            'snapshot' => ['first_name' => 'Retained source name', 'last_name' => 'Applicant',
                'program' => ['name' => 'Synthetic Program'], 'application_path' => 'FirstYear',
                'requirements' => $requirements ?: [['label' => 'Synthetic copy', 'applicant_instructions' => 'Synthetic source instruction']]],
        ]);
        $application->update(['current_submission_version_id' => $version->id]);

        return [$application->fresh(), $applicant, $registrar];
    }
}
