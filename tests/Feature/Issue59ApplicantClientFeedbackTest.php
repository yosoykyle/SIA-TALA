<?php

namespace Tests\Feature;

use App\Actions\Admissions\AdmissionCycleReadinessService;
use App\Filament\Applicant\Pages\Application;
use App\Filament\Components\ResumableWizard;
use App\Filament\Resources\AdmissionApplications\Pages\ViewAdmissionApplication;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\DocumentEvidence;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Component;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Issue59ApplicantClientFeedbackTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('applicant', 'web');
        Role::findOrCreate(User::StaffRoleRegistrar, 'web')->givePermissionTo(Permission::findOrCreate('approve-documents', 'web'));
    }

    public function test_applicant_shell_pairs_school_identity_with_the_applicant_name_only(): void
    {
        $applicant = $this->applicant(['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Reyes']);

        $this->actingAs($applicant)
            ->get('/applicant')
            ->assertOk()
            ->assertSee('Servitech Institute Asia')
            ->assertSee('data-tala-brand-person', false)
            ->assertSee('Ana Reyes')
            ->assertDontSee('Applicant Workspace')
            ->assertDontSee('Powered by TALA')
            ->assertDontSee('tala-workspace-context', false)
            ->assertSee('js/tala-wizard.js', false);
    }

    public function test_name_free_applicant_account_uses_the_current_application_name(): void
    {
        [$application, $applicant] = $this->editableDraft();
        $applicant->forceFill(['name' => null])->saveQuietly();
        $application->update(['first_name' => 'Lito', 'last_name' => 'Santos']);

        $this->actingAs($applicant->fresh())
            ->get('/applicant')
            ->assertOk()
            ->assertSee('Lito Santos');
    }

    public function test_applicant_account_entry_omits_tala_attribution(): void
    {
        $this->get('/applicant/login')
            ->assertOk()
            ->assertSee('Servitech Institute Asia')
            ->assertDontSee('Applicant Workspace')
            ->assertDontSee('Powered by TALA');
    }

    public function test_staff_account_entry_keeps_tala_attribution(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Powered by TALA')
            ->assertDontSee('js/tala-wizard.js', false);
    }

    public function test_student_type_labels_follow_registrar_terminology_without_changing_stored_paths(): void
    {
        $this->assertSame([
            AdmissionCycle::PathFirstYear => 'Freshman',
            AdmissionCycle::PathTransferee => 'Transferee',
        ], AdmissionCycle::studentTypeOptions());
        $this->assertSame('FirstYear', AdmissionApplication::PathFirstYear);
        $this->assertSame('Freshman', AdmissionCycle::studentTypeLabel(AdmissionApplication::PathFirstYear));
    }

    public function test_cycle_readiness_blockers_name_the_student_type_instead_of_the_stored_path(): void
    {
        $cycle = AdmissionCycle::factory()->create();
        $cycle->programs()->attach(Program::factory()->create(['is_active' => true]), ['accepts_first_year' => true, 'accepts_transferee' => false]);

        $blockers = collect(app(AdmissionCycleReadinessService::class)->for($cycle->fresh())['blockers'])
            ->filter(fn (array $blocker): bool => str_starts_with($blocker['code'], 'requirement_set_'));

        $this->assertContains('Published Admission Requirement Set for Freshman applicants', $blockers->pluck('source')->all());
        $this->assertStringNotContainsString('FirstYear', $blockers->map(fn (array $blocker): string => $blocker['source'].' '.$blocker['reason'].' '.$blocker['recovery'])->implode(' '));
        $this->assertContains('requirement_set_first_year', $blockers->pluck('code')->all());
    }

    public function test_application_step_one_uses_the_task_heading_and_student_type(): void
    {
        [$application, $applicant] = $this->editableDraft();

        $this->wizardPage($applicant, $application)
            ->assertSee("Let's get your application started.")
            ->assertSee('Student type')
            ->assertSee('Freshman')
            ->assertDontSee('Choose your admission cycle and program')
            ->assertDontSee('Applying as')
            ->assertDontSee('First year');
    }

    public function test_registrar_application_view_shows_student_type(): void
    {
        [$application] = $this->editableDraft();
        $application->update(['application_path' => AdmissionApplication::PathFirstYear]);
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(ViewAdmissionApplication::class, ['record' => $application->getRouteKey()])
            ->assertSee('Student type')
            ->assertSee('Freshman')
            ->assertDontSee('First Year');
    }

    public function test_wizard_keeps_completed_steps_reachable_after_going_back(): void
    {
        [$application, $applicant] = $this->editableDraft();
        $application->update(['prior_school_name' => null]);

        $page = $this->wizardPage($applicant, $application)->assertWizardCurrentStep(3);

        $this->assertSame(3, $this->wizard($page)->getReachedStep());
        $page->assertSeeHtml('window.talaResumableWizard($el._x_dataStack?.[0] ?? $data, $watch')
            ->assertSeeHtml('isStepCompleted(0)')
            ->assertSeeHtml('requestStep(')
            ->assertDontSeeHtml('x-on:click="step = ');
    }

    public function test_forward_header_jump_validates_and_saves_the_current_step_first(): void
    {
        [$application, $applicant] = $this->editableDraft();
        $application->update(['prior_school_name' => null]);
        $page = $this->wizardPage($applicant, $application);
        $wizard = $this->wizard($page);

        $page->fillForm(['first_name' => 'Jumped Name'])
            ->call('callSchemaComponentMethod', $wizard->getKey(), 'jumpToStep', [1, 2])
            ->assertHasNoFormErrors()
            ->assertDispatched('draft-saved')
            ->assertDispatched('tala-wizard-jump', key: $wizard->getKey(), step: $this->stepKey($wizard, 2));

        $this->assertSame('Jumped Name', $application->fresh()->first_name);
        $this->assertSame(AdmissionApplication::StateDraft, $application->fresh()->application_state);
    }

    public function test_forward_header_jump_is_refused_beyond_saved_progress(): void
    {
        [$application, $applicant] = $this->editableDraft();
        $application->update(['prior_school_name' => null]);
        $page = $this->wizardPage($applicant, $application);

        $page->call('callSchemaComponentMethod', $this->wizard($page)->getKey(), 'jumpToStep', [0, 4])
            ->assertNotDispatched('tala-wizard-jump');
    }

    public function test_save_and_continue_reports_recomputed_saved_progress(): void
    {
        [$application, $applicant] = $this->editableDraft();
        $application->update(['prior_school_name' => null]);
        $page = $this->wizardPage($applicant, $application);

        $page->fillForm(['prior_school_name' => 'Synthetic High School'])
            ->goToNextWizardStep()
            ->assertHasNoFormErrors()
            ->assertDispatched('draft-saved')
            ->assertDispatched('tala-wizard-reached', key: $this->wizard($page)->getKey(), reachedStepIndex: 3);
    }

    public function test_refused_jump_reopens_the_step_and_explains_what_to_finish(): void
    {
        [$application, $applicant, $requirement] = $this->editableDraft();
        $evidence = DocumentEvidence::factory()->canonical($application, $requirement)->create();
        $page = $this->wizardPage($applicant, $application)->assertWizardCurrentStep(5);
        $wizard = $this->wizard($page);
        $evidence->delete();

        $page->call('callSchemaComponentMethod', $wizard->getKey(), 'jumpToStep', [0, 4])
            ->assertHasNoFormErrors()
            ->assertDispatched('draft-saved')
            ->assertDispatched('tala-wizard-reached', key: $wizard->getKey(), reachedStepIndex: 3)
            ->assertNotDispatched('tala-wizard-jump')
            ->assertNotified('Finish the earlier steps first');
    }

    public function test_forward_header_jump_stops_when_the_current_step_is_invalid(): void
    {
        [$application, $applicant] = $this->editableDraft();
        $application->update(['prior_school_name' => null]);
        $page = $this->wizardPage($applicant, $application);

        $page->fillForm(['first_name' => null])
            ->call('callSchemaComponentMethod', $this->wizard($page)->getKey(), 'jumpToStep', [1, 2])
            ->assertHasFormErrors(['first_name' => 'required'])
            ->assertNotDispatched('draft-saved')
            ->assertNotDispatched('tala-wizard-jump');

        $this->assertNotNull($application->fresh()->first_name);
    }

    private function wizardPage(User $applicant, AdmissionApplication $application): Testable
    {
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        return Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id]);
    }

    private function wizard(Testable $page): ResumableWizard
    {
        $wizard = $page->instance()->form->getComponent(fn (Component $component): bool => $component instanceof ResumableWizard);
        $this->assertInstanceOf(ResumableWizard::class, $wizard);

        return $wizard;
    }

    private function stepKey(ResumableWizard $wizard, int $index): string
    {
        return array_values($wizard->getChildSchema()->getComponents())[$index]->getKey();
    }

    /** @param array<string, mixed> $attributes */
    private function applicant(array $attributes = []): User
    {
        $applicant = User::factory()->create(['status' => User::StatusActive, ...$attributes]);
        $applicant->assignRole('applicant');

        return $applicant;
    }

    /** @return array{AdmissionApplication, User, AdmissionRequirement} */
    private function editableDraft(): array
    {
        $applicant = $this->applicant();
        $cycle = AdmissionCycle::factory()->published()->create(['term_id' => Term::query()->value('id') ?? Term::factory()->create()->id, 'opens_at' => now()->subDay(), 'closes_at' => now()->addDay(), 'correction_closes_at' => now()->addDays(2)]);
        $application = AdmissionApplication::factory()->submitted()->create(['user_id' => $applicant->id, 'admission_cycle_id' => $cycle->id, 'application_state' => AdmissionApplication::StateDraft]);
        $set = AdmissionRequirementSet::factory()->create(['admission_cycle_id' => $cycle->id, 'application_path' => $application->application_path]);
        $requirement = AdmissionRequirement::factory()->create(['admission_requirement_set_id' => $set->id, 'label' => 'School review copy', 'requires_preliminary_evidence' => true, 'due_stage' => AdmissionRequirement::DueEnrollmentReadiness]);
        $set->update(['state' => AdmissionRequirementSet::StatePublished, 'published_at' => now(), 'effective_at' => now()]);
        $application->program->update(['is_active' => true]);
        $cycle->programs()->attach($application->program_id, ['accepts_first_year' => true, 'accepts_transferee' => true]);

        return [$application->fresh(), $applicant, $requirement];
    }
}
