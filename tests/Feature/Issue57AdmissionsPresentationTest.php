<?php

namespace Tests\Feature;

use App\Actions\Admissions\AdmissionEvidenceService;
use App\Actions\Admissions\DiscardAdmissionApplication;
use App\Actions\Admissions\RecordAdmissionDecision;
use App\Actions\Admissions\RecordRegistrarEnrollmentClearance;
use App\Actions\Admissions\RequestAdmissionCorrection;
use App\Actions\Admissions\SaveAdmissionApplication;
use App\Actions\Admissions\SubmitAdmissionApplication;
use App\Filament\Applicant\Pages\Application;
use App\Filament\Applicant\Pages\Dashboard;
use App\Filament\Applicant\Pages\Requirements;
use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications;
use App\Filament\Resources\AdmissionApplications\Pages\ViewAdmissionApplication;
use App\Filament\Resources\AdmissionCycles\Pages\ListAdmissionCycles;
use App\Filament\Resources\AdmissionCycles\Pages\ViewAdmissionCycle;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationCorrectionItem;
use App\Models\ApplicationCorrectionRequest;
use App\Models\ApplicationSubmissionVersion;
use App\Models\DocumentEvidence;
use App\Models\Enrollment;
use App\Models\IdentityMatchReview;
use App\Models\PreliminaryEvidenceReview;
use App\Models\RegistrarEnrollmentClearance;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Carbon\CarbonImmutable;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Wizard;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Issue57AdmissionsPresentationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('applicant', 'web');
        Role::findOrCreate(User::StaffRoleRegistrar, 'web')->givePermissionTo(Permission::findOrCreate('approve-documents', 'web'));
    }

    public function test_relevant_states_have_consistent_responsible_parties_across_roles(): void
    {
        [$application, $applicant] = $this->application();
        $registrar = $this->registrar();

        foreach ([
            AdmissionApplication::StateDraft => 'Applicant',
            AdmissionApplication::StateSubmitted => 'Registrar',
            AdmissionApplication::StateActionNeeded => 'Applicant',
            AdmissionApplication::StateAdmitted => 'Applicant / Registrar',
            AdmissionApplication::StateNotAdmitted => 'No active task',
            AdmissionApplication::StateWithdrawn => 'No active task',
        ] as $state => $party) {
            $application->update(['application_state' => $state]);
            Filament::setCurrentPanel(Filament::getPanel('applicant'));
            $dashboard = Livewire::actingAs($applicant)->test(Dashboard::class, ['sourceApplicationId' => $application->id]);
            $this->assertSame($party, $dashboard->instance()->responsibleParty($application->fresh()));
            Filament::setCurrentPanel(Filament::getPanel('admin'));
            $record = Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id]);
            $this->assertSame($party, $record->instance()->infolist->getComponentByStatePath('responsible_party')->getState());
            if ($state === AdmissionApplication::StateDraft) {
                $record->assertSee('Applicant completes and submits the five-step Application; Registrar assistance does not submit it.');
            }
        }
    }

    public function test_historical_result_and_requirements_bind_the_source_owned_application(): void
    {
        [$application, $applicant] = $this->application();
        $newTerm = Term::factory()->create(['academic_year_id' => $application->term->academic_year_id, 'type' => Term::TypeSecondSemester]);
        $newCycle = AdmissionCycle::factory()->create(['term_id' => $newTerm->id]);
        AdmissionApplication::factory()->create(['user_id' => $applicant->id, 'admission_cycle_id' => $newCycle->id, 'updated_at' => now()->addMinute()]);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $dashboard = Livewire::actingAs($applicant)->test(Dashboard::class, ['sourceApplicationId' => $application->id]);
        $this->assertTrue($application->is($dashboard->instance()->currentApplication()));
        $dashboard->assertSee($application->application_reference)
            ->assertDontSee('The latest request could not be confirmed');
        $requirements = Livewire::actingAs($applicant)->test(Requirements::class, ['sourceApplicationId' => $application->id]);
        $this->assertTrue($application->is($requirements->instance()->application()));
        $outsider = User::factory()->create(['status' => User::StatusActive]);
        $outsider->assignRole('applicant');
        $this->actingAs($outsider)->get(Dashboard::getUrl(['application' => $application->id]))->assertNotFound();
        $this->actingAs($outsider)->get(Requirements::getUrl(['application' => $application->id]))->assertNotFound();
    }

    public function test_replacement_instructions_require_an_active_named_evidence_correction(): void
    {
        [$application, $applicant, $requirement] = $this->application();
        $evidence = DocumentEvidence::factory()->canonical($application, $requirement)->create([
            'admission_application_id' => $application->id,
            'admission_requirement_id' => $requirement->id,
            'application_submission_version_id' => $application->current_submission_version_id,
        ]);
        PreliminaryEvidenceReview::factory()->create(['document_evidence_id' => $evidence->id, 'result' => PreliminaryEvidenceReview::ResultActionNeeded]);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $component = Livewire::actingAs($applicant)->test(Requirements::class, ['sourceApplicationId' => $application->id]);
        $component->assertDontSee('Open Application to replace only this evidence item.');
        $registrar = $this->registrar();
        app(RequestAdmissionCorrection::class)->execute($application, $registrar, [[
            'type' => ApplicationCorrectionItem::ScopeEvidence, 'key' => $requirement->code, 'admission_requirement_id' => $requirement->id,
        ]], 'Replace the named copy.', 'Applicant', now()->addHour());
        Livewire::actingAs($applicant)->test(Requirements::class, ['sourceApplicationId' => $application->id])
            ->assertSee('Open Application to replace only this evidence item.');
        Livewire::actingAs($applicant)->test(Dashboard::class, ['sourceApplicationId' => $application->id])
            ->assertSee('Replace the named copy.');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id])
            ->assertSee('Public closing')->assertSee('Active correction due')->assertSee('Replace the named copy.');
    }

    public function test_historical_correction_edit_url_redirects_to_the_owned_current_result_after_resubmission(): void
    {
        [$application, $applicant] = $this->application();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id])
            ->assertRedirect(Dashboard::getUrl(['application' => $application->id]));
        $outsider = User::factory()->create(['status' => User::StatusActive]);
        $outsider->assignRole('applicant');
        $this->actingAs($outsider)->get(Application::getUrl(['application' => $application->id]))->assertNotFound();
    }

    public function test_registrar_queue_searches_exact_lrn_and_record_exposes_protected_file(): void
    {
        [$application, , $requirement] = $this->application();
        $application->update(['lrn' => '123456789012']);
        $evidence = DocumentEvidence::factory()->canonical($application, $requirement)->create([
            'admission_application_id' => $application->id,
            'admission_requirement_id' => $requirement->id,
            'application_submission_version_id' => $application->current_submission_version_id,
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $registrar = $this->registrar();
        Livewire::actingAs($registrar)->test(ListAdmissionApplications::class)
            ->searchTable('123456789012')->assertCanSeeTableRecords([$application])
            ->searchTable('123456789')->assertCanNotSeeTableRecords([$application]);
        $record = Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id])
            ->assertSee('Current evidence')->assertSee('View')->assertSee('Download')
            ->assertSee($application->application_reference)
            ->assertSeeHtml(route('admissions.evidence.download', ['evidence' => $evidence]))
            ->mountAction('reviewEvidence')
            ->setActionData(['document_evidence_id' => $evidence->id])
            ->assertActionDataSet(['document_evidence_id' => $evidence->id]);
        $this->assertSame('Review application — '.$application->first_name.' '.$application->last_name, $record->instance()->getTitle());
        $this->assertSame('Review application', $record->instance()->getBreadcrumb());
    }

    public function test_reached_recovery_preserves_response_and_context_safe_actions(): void
    {
        [, $applicant] = $this->application();
        $this->actingAs($applicant)->get('/applicant/missing-issue57-page')->assertNotFound()
            ->assertSee('HTTP 404')->assertDontSee('Pause background animation')
            ->assertSee('Return to Servitech Institute Asia home')->assertDontSee('Try submitting again');
    }

    public function test_current_review_copies_are_separated_from_retained_earlier_versions(): void
    {
        [$application, , $requirement] = $this->application();
        $earlier = DocumentEvidence::factory()->canonical($application, $requirement)->create();
        $current = DocumentEvidence::factory()->canonical($application, $requirement)->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($this->registrar())->test(ViewAdmissionApplication::class, ['record' => $application->id])
            ->assertSee('Current evidence')->assertSee('Earlier evidence versions')->assertSee('Superseded — retained history');
        $this->assertSame([$current->id], $page->instance()->infolist->getComponentByStatePath('evidenceVersions')->getState()->modelKeys());
        $this->assertSame([$earlier->id], $page->instance()->infolist->getComponentByStatePath('earlier_evidence')->getState()->modelKeys());
    }

    public function test_queue_readiness_uses_current_evidence_and_date_time_filters(): void
    {
        [$application, , $requirement] = $this->application(preDecision: true);
        $old = DocumentEvidence::factory()->canonical($application, $requirement)->create();
        PreliminaryEvidenceReview::factory()->create(['document_evidence_id' => $old->id, 'result' => PreliminaryEvidenceReview::ResultAccepted]);
        DocumentEvidence::factory()->canonical($application, $requirement)->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($this->registrar())->test(ListAdmissionApplications::class)
            ->assertTableColumnStateSet('preliminary_readiness', 'Current preliminary review pending', $application)
            ->filterTable('preliminary_readiness', 'complete')->assertCanNotSeeTableRecords([$application])
            ->resetTableFilters()
            ->filterTable('submitted_at', ['from' => now()->addMinute()->toDateTimeString()])->assertCanNotSeeTableRecords([$application])
            ->resetTableFilters()
            ->filterTable('activity_at', ['until' => now()->subMinute()->toDateTimeString()])->assertCanNotSeeTableRecords([$application]);
    }

    public function test_scoped_correction_rejects_a_newer_submission_than_the_opened_form(): void
    {
        [$application] = $this->application();
        try {
            app(RequestAdmissionCorrection::class)->execute($application, $this->registrar(), [[
                'type' => ApplicationCorrectionItem::ScopeField, 'key' => 'phone', 'admission_requirement_id' => null,
            ]], 'Correct the phone.', 'Applicant', now()->addHour(), $application->current_submission_version_id + 1);
            $this->fail('A stale correction form must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('submitted version changed', $exception->getMessage());
            $this->assertSame(0, $application->correctionRequests()->count());
            $this->assertSame(AdmissionApplication::StateSubmitted, $application->fresh()->application_state);
        }
    }

    public function test_graduation_year_errors_are_attached_to_the_year_field(): void
    {
        [$application, $applicant] = $this->application();
        $application->update(['application_state' => AdmissionApplication::StateDraft]);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        foreach (['20x5', (string) (now('Asia/Manila')->year + 1)] as $year) {
            Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id])
                ->fillForm(['prior_school_completion_year' => $year])
                ->call('saveDraft')
                ->assertHasFormErrors(['prior_school_completion_year'])
                ->assertSet('saveStatus', 'failed');
            $this->assertNotSame($year, (string) $application->fresh()->prior_school_completion_year);
        }
    }

    public function test_reactive_optional_identity_rejects_values_before_consent(): void
    {
        [$application, $applicant] = $this->application();
        $application->update(['application_state' => AdmissionApplication::StateDraft]);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id])
            ->set('data.gender', 'Female')
            ->assertHasErrors(['data.gender']);
        $this->assertNull($application->fresh()->gender);
        $this->assertNull($application->fresh()->optional_identity_consented_at);
    }

    public function test_not_admitted_records_its_own_basis_without_accepting_an_unreviewed_copy(): void
    {
        [$application, , $requirement] = $this->application(preDecision: true);
        DocumentEvidence::factory()->canonical($application, $requirement)->create();
        $decision = app(RecordAdmissionDecision::class)->execute(
            $application, $this->registrar(), AdmissionDecision::DecisionNotAdmitted,
            'The required review copy was not supplied in usable form.', 'Synthetic Registrar authority',
            'Contact the Registrar for the retained decision explanation.',
            expectedSubmissionVersionId: $application->current_submission_version_id,
        );
        $this->assertSame(AdmissionApplication::StateNotAdmitted, $application->fresh()->application_state);
        $this->assertSame($application->current_submission_version_id, $decision->application_submission_version_id);
        $this->assertSame(0, PreliminaryEvidenceReview::query()->whereIn('document_evidence_id', $application->evidenceVersions()->pluck('id'))->count());
    }

    public function test_current_clearance_instruction_is_prominent_across_roles(): void
    {
        [$application, $applicant] = $this->application();
        $application->update(['application_state' => AdmissionApplication::StateAdmitted]);
        $registrar = $this->registrar();
        $decision = AdmissionDecision::factory()->create([
            'admission_application_id' => $application->id,
            'application_submission_version_id' => $application->current_submission_version_id,
            'decision' => AdmissionDecision::DecisionAdmitted,
            'decided_by' => $registrar->id,
            'applicant_explanation' => 'Earlier admission explanation.',
        ]);
        app(RecordRegistrarEnrollmentClearance::class)->execute(
            $application->fresh(), $registrar, 'ActionNeeded', false,
            safeInstruction: 'Bring the named external credential to the Registrar.',
            expectedDecisionId: $decision->id,
            expectedSubmissionVersionId: $application->current_submission_version_id,
        );
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        Livewire::actingAs($applicant)->test(Dashboard::class, ['sourceApplicationId' => $application->id])
            ->assertSee('Bring the named external credential to the Registrar.');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $record = Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id]);
        $this->assertSame('Bring the named external credential to the Registrar.',
            $record->instance()->infolist->getComponentByStatePath('applicant_instruction')->getState());
    }

    public function test_admitted_primary_action_follows_credential_blockers_then_readiness_checkpoint(): void
    {
        [$application, , $requirement] = $this->application();
        $application->update(['application_state' => AdmissionApplication::StateAdmitted]);
        $registrar = $this->registrar();
        AdmissionDecision::factory()->create([
            'admission_application_id' => $application->id,
            'application_submission_version_id' => $application->current_submission_version_id,
            'decision' => AdmissionDecision::DecisionAdmitted,
            'decided_by' => $registrar->id,
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $blocked = Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id]);
        $this->assertSame('recordEnrollmentClearance', $blocked->instance()->getCachedHeaderActions()[0]->getName());
        app(RecordRegistrarEnrollmentClearance::class)->execute(
            $application->fresh(), $registrar, 'Cleared', true,
            expectedDecisionId: $application->decisions()->whereDoesntHave('successor')->value('id'),
            expectedSubmissionVersionId: $application->current_submission_version_id,
        );
        $ready = Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id]);
        $this->assertSame('reviewReadiness', $ready->instance()->getCachedHeaderActions()[0]->getName());
        $this->assertSame('Enrollment clearance is complete. Follow the published enrollment availability.',
            $ready->instance()->infolist->getComponentByStatePath('applicant_instruction')->getState());
        $ready->mountAction('reviewReadiness')->assertActionMounted('reviewReadiness');
        $this->assertSame(AdmissionApplication::StateAdmitted, $application->fresh()->application_state);
    }

    public function test_discard_holds_the_application_lock_during_cleanup_against_a_second_connection(): void
    {
        $application = AdmissionApplication::query()->where('application_state', AdmissionApplication::StateDraft)
            ->whereHas('user', fn ($query) => $query->whereIn('email', [
                'applicant.demo@example.test', 'applicant.transfer.demo@example.test', 'applicant.minor.demo@example.test',
            ]))->first();
        if ($application === null) {
            $this->markTestSkipped('Run the guarded Issue57 exploration seed first for the committed-row lock probe.');
        }
        $this->assertSame('test_tala_db', DB::selectOne('SELECT DATABASE() AS db')->db);
        config(['database.connections.issue57_lock_probe' => config('database.connections.mysql')]);
        $probe = DB::connection('issue57_lock_probe');
        $this->assertSame('test_tala_db', $probe->selectOne('SELECT DATABASE() AS db')->db);
        $probe->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $before = $application->getAttributes();
        $service = $this->mock(AdmissionEvidenceService::class);
        $service->shouldReceive('discardTemporaryEvidence')->once()->andReturnUsing(function () use ($probe, $application): never {
            $probe->beginTransaction();
            try {
                $probe->table($application->getTable())->where('id', $application->id)->lockForUpdate()->first();
                $this->fail('A second submission writer acquired the Application during discard cleanup.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, $exception->errorInfo[1]);
            } finally {
                $probe->rollBack();
            }
            throw new RuntimeException('Stop the probe before retained data or files are deleted.');
        });
        try {
            (new DiscardAdmissionApplication($service))->execute($application, $application->user);
            $this->fail('The probe must stop before deletion.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Stop the probe before retained data or files are deleted.', $exception->getMessage());
            $this->assertEquals($before, $application->fresh()->getAttributes());
        } finally {
            DB::purge('issue57_lock_probe');
        }
    }

    public function test_closed_or_cancelled_owned_draft_is_read_only_even_when_another_cycle_is_open(): void
    {
        foreach ([AdmissionCycle::StatePublished, AdmissionCycle::StateCancelled] as $state) {
            [$application, $applicant] = $this->application(submitted: false);
            $application->admissionCycle->update(['state' => $state, 'closes_at' => now()->subMinute()]);
            AdmissionCycle::factory()->published()->create(['term_id' => $application->term_id, 'opens_at' => now()->subDay(), 'closes_at' => now()->addDay()]);
            Filament::setCurrentPanel(Filament::getPanel('applicant'));
            $page = Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id]);
            $this->assertFalse($page->instance()->admissionsAreOpen());
            $this->assertFalse($page->instance()->submissionIsAvailable());
            $wizard = collect($page->instance()->form->getComponents())->first(fn ($component): bool => $component instanceof Wizard);
            $this->assertFalse($wizard->getNextAction()->isDisabled());
            $this->assertFalse($wizard->getPreviousAction()->isDisabled());
            $this->assertTrue($page->instance()->form->getComponentByStatePath('first_name')->isDisabled());
            $this->assertTrue($page->instance()->form->getComponentByStatePath('admission_cycle_id')->isDisabled());
            $page->assertActionVisible('discardDraft')
                ->assertSee('Saving, uploading, and first submission are unavailable')
                ->assertDontSee('Save partial work at any step.');
            $page->assertActionDisabled('saveAndExit');
            $facts = $application->fresh()->getAttributes();
            $page->call('saveDraft')->assertHasErrors(['data.admission_cycle_id']);
            $page->call('submitApplication')->assertHasErrors(['data.admission_cycle_id']);
            $this->assertSame($facts, $application->fresh()->getAttributes());
            $this->assertSame(0, $application->submissionVersions()->count());
            Livewire::actingAs($applicant)->test(Dashboard::class, ['sourceApplicationId' => $application->id])
                ->assertSee('Inspect saved draft')->assertSee('saving, uploading, and first submission are unavailable.')
                ->assertActionHasLabel(TestAction::make('continue')->table($application), 'Inspect saved draft')
                ->assertActionHasUrl(TestAction::make('continue')->table($application), Application::getUrl(['application' => $application->id]));
        }
    }

    public function test_active_overdue_named_correction_remains_editable_after_cycle_cancellation(): void
    {
        [$application, $applicant] = $this->application();
        app(RequestAdmissionCorrection::class)->execute($application, $this->registrar(), [[
            'type' => ApplicationCorrectionItem::ScopeField, 'key' => 'phone', 'admission_requirement_id' => null,
        ]], 'Correct the phone.', 'Applicant', now()->addHour());
        $application->admissionCycle->update(['state' => AdmissionCycle::StateCancelled, 'closes_at' => now()->subMinute()]);
        $this->travel(2)->hours();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $page = Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id]);
        $this->assertTrue($page->instance()->submissionIsAvailable());
        $this->assertFalse($page->instance()->form->getComponentByStatePath('phone')->isDisabled());
        $this->assertTrue($page->instance()->form->getComponentByStatePath('first_name')->isDisabled());
        $page->assertSee('Correction overdue')->assertSee('Correct the phone.');
    }

    public function test_submitted_primary_promotes_decision_after_current_reviews_and_identity_resolution(): void
    {
        [$application, , $requirement] = $this->application(preDecision: true);
        $registrar = $this->registrar();
        $evidence = DocumentEvidence::factory()->canonical($application, $requirement)->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $pending = Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id]);
        $this->assertSame('reviewEvidence', $pending->instance()->getCachedHeaderActions()[0]->getName());
        PreliminaryEvidenceReview::factory()->create([
            'document_evidence_id' => $evidence->id, 'reviewed_by' => $registrar->id,
            'result' => PreliminaryEvidenceReview::ResultAccepted,
        ]);
        $reviewed = Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id]);
        $this->assertSame('recordDecision', $reviewed->instance()->getCachedHeaderActions()[0]->getName());
        $reviewed->assertActionVisible('reviewEvidence');
        $reviewed->assertSee('Record the admission decision using the current reviewed evidence.');
        Livewire::actingAs($registrar)->test(ListAdmissionApplications::class)
            ->assertTableColumnStateSet('owner_next_action', 'Registrar — Record the admission decision using the current reviewed evidence.', $application);
        IdentityMatchReview::factory()->create(['admission_application_id' => $application->id]);
        $identity = Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id]);
        $this->assertSame('resolveIdentity', $identity->instance()->getCachedHeaderActions()[0]->getName());
    }

    public function test_ready_ownership_and_next_action_agree_for_enrollment_windows_and_existing_cases(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04 00:30:00', 'Asia/Manila'));
        [$application, $applicant] = $this->application();
        $application->update(['application_state' => AdmissionApplication::StateAdmitted]);
        $registrar = $this->registrar();
        $decision = AdmissionDecision::factory()->create([
            'admission_application_id' => $application->id, 'application_submission_version_id' => $application->current_submission_version_id,
            'decided_by' => $registrar->id,
        ]);
        app(RecordRegistrarEnrollmentClearance::class)->execute(
            $application->fresh(), $registrar, 'Cleared', true,
            expectedDecisionId: $decision->id, expectedSubmissionVersionId: $application->current_submission_version_id,
        );
        $package = TermCalendarPackage::factory()->create(['term_id' => $application->term_id, 'state' => TermCalendarPackage::StateActive]);
        $window = TermCalendarWindow::factory()->create([
            'term_calendar_package_id' => $package->id, 'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => '2026-09-01', 'closes_on' => '2026-10-03', 'cutoff_at' => '23:59:59',
        ]);
        $assertSurfaces = function (string $party, string $instruction) use ($application, $applicant, $registrar): void {
            Filament::setCurrentPanel(Filament::getPanel('applicant'));
            $home = Livewire::actingAs($applicant)->test(Dashboard::class, ['sourceApplicationId' => $application->id]);
            $this->assertSame($party, $home->instance()->responsibleParty($application->fresh()));
            $this->assertStringContainsString($instruction, $home->instance()->nextAction($application->fresh()));
            Filament::setCurrentPanel(Filament::getPanel('admin'));
            $record = Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id]);
            $this->assertSame($party, $record->instance()->infolist->getComponentByStatePath('responsible_party')->getState());
            $this->assertSame($home->instance()->nextAction($application->fresh()), $record->instance()->infolist->getComponentByStatePath('next_action')->getState());
            $queue = Livewire::actingAs($registrar)->test(ListAdmissionApplications::class)->set('activeTab', 'ready_for_enrollment');
            $queue->assertTableColumnStateSet('owner_next_action', $party.' — '.$home->instance()->nextAction($application->fresh()), $application);
        };
        $assertSurfaces('Registrar', 'Ordinary enrollment for this Term closed');
        $window->update(['opens_on' => '2026-10-05', 'closes_on' => '2026-10-30']);
        $assertSurfaces('Registrar', 'Enrollment will open');
        $window->update(['opens_on' => '2026-10-04']);
        $assertSurfaces('Applicant', 'Enrollment is open');
        Enrollment::factory()->create([
            'admission_application_id' => $application->id, 'credential_user_id' => $applicant->id,
            'term_id' => $application->term_id, 'student_profile_id' => null,
        ]);
        $assertSurfaces('Registrar', 'existing Registration Case');
    }

    public function test_guardian_ui_requiredness_changes_at_the_manila_birthday(): void
    {
        [$application, $applicant] = $this->application(submitted: false);
        $application->update(['birth_date' => '2008-10-04']);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $this->travelTo(CarbonImmutable::parse('2026-10-03 23:59:00', 'Asia/Manila'));
        $minor = Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id]);
        $this->assertTrue($minor->instance()->form->getComponentByStatePath('guardian_full_name')->isRequired());
        $this->travelTo(CarbonImmutable::parse('2026-10-04 00:30:00', 'Asia/Manila'));
        $adult = Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id]);
        $this->assertFalse($adult->instance()->form->getComponentByStatePath('guardian_full_name')->isRequired());
    }

    public function test_native_wizard_saves_identity_before_advancing_without_submitting(): void
    {
        [$application, $applicant] = $this->editableDraft();
        $application->update(['first_name' => null, 'prior_school_name' => null, 'accuracy_declared_at' => null]);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        $page = Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id])
            ->assertWizardCurrentStep(2)
            ->fillForm(['first_name' => 'Saved name'])
            ->goToNextWizardStep()
            ->assertHasNoFormErrors()
            ->assertDispatched('draft-saved')
            ->assertDispatched('next-wizard-step')
            ->assertNoRedirect()
            ->assertWizardCurrentStep(3);

        $this->assertSame('Saved name', $application->fresh()->first_name);
        $this->assertSame(AdmissionApplication::StateDraft, $application->fresh()->application_state);
        $this->assertNull($application->fresh()->accuracy_declared_at);
        $this->assertSame(0, $application->submissionVersions()->count());
        $this->assertSame($application->id, $page->get('sourceApplicationId'));
    }

    public function test_native_wizard_retains_entered_work_and_stops_after_save_failure(): void
    {
        [$application, $applicant] = $this->editableDraft();
        $application->update(['first_name' => null]);
        $this->mock(SaveAdmissionApplication::class)->shouldReceive('execute')->once()->andThrow(new RuntimeException('Synthetic save failure'));
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id])
            ->fillForm(['first_name' => 'Retained name'])
            ->goToNextWizardStep()
            ->assertSet('saveStatus', 'failed')
            ->assertSet('data.first_name', 'Retained name')
            ->assertNotDispatched('draft-saved')
            ->assertNotDispatched('next-wizard-step')
            ->assertNoRedirect();

        $this->assertNull($application->fresh()->first_name);
        $this->assertSame(0, $application->submissionVersions()->count());
    }

    public function test_native_wizard_requires_current_step_answers_before_saving(): void
    {
        [$application, $applicant] = $this->editableDraft();
        $application->update(['first_name' => null]);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id])
            ->goToNextWizardStep()
            ->assertHasFormErrors(['first_name' => 'required'])
            ->assertNotDispatched('draft-saved')
            ->assertNotDispatched('next-wizard-step');

        $this->assertNull($application->fresh()->first_name);
    }

    public function test_save_and_exit_keeps_an_incomplete_draft_and_uses_its_owned_home(): void
    {
        [$application, $applicant] = $this->editableDraft();
        $application->update(['first_name' => null, 'prior_school_name' => null]);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id])
            ->fillForm(['middle_name' => 'Partial work'])
            ->callAction('saveAndExit')
            ->assertHasNoFormErrors()
            ->assertRedirect(Dashboard::getUrl(['application' => $application->id]));

        $this->assertSame('Partial work', $application->fresh()->middle_name);
        $this->assertNull($application->fresh()->first_name);
        $this->assertSame(0, $application->submissionVersions()->count());
    }

    public static function draftDiscardPages(): array
    {
        return [
            'Applicant Home' => [Dashboard::class],
            'Draft form' => [Application::class],
        ];
    }

    #[DataProvider('draftDiscardPages')]
    public function test_discard_confirmation_preserves_the_owned_draft_until_confirmed(string $pageClass): void
    {
        [$application, $applicant] = $this->editableDraft();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $page = Livewire::actingAs($applicant)->test($pageClass, ['sourceApplicationId' => $application->id]);

        $page->assertActionVisible('discardDraft')
            ->mountAction('discardDraft')
            ->assertActionMounted('discardDraft')
            ->unmountAction()
            ->assertActionNotMounted();
        $this->assertModelExists($application);

        $page->mountAction('discardDraft')->callMountedAction()->assertHasNoActionErrors()
            ->assertRedirect(Dashboard::getUrl());
        $this->assertModelMissing($application);
    }

    public function test_home_discard_rejects_a_draft_that_was_submitted_after_confirmation_opened(): void
    {
        [$application, $applicant] = $this->editableDraft();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $page = Livewire::actingAs($applicant)->test(Dashboard::class, ['sourceApplicationId' => $application->id])
            ->mountAction('discardDraft');
        $application->update(['application_state' => AdmissionApplication::StateSubmitted]);

        $page->callMountedAction();
        $this->assertModelExists($application);
        $this->assertSame(AdmissionApplication::StateSubmitted, $application->fresh()->application_state);

        Livewire::actingAs($applicant)->test(Dashboard::class, ['sourceApplicationId' => $application->id])
            ->assertActionHidden('discardDraft');
    }

    public function test_home_discard_dialog_is_outside_the_collapsed_application_history(): void
    {
        [$application, $applicant] = $this->editableDraft();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        $page = Livewire::actingAs($applicant)->test(Dashboard::class, ['sourceApplicationId' => $application->id]);
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($page->html());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $dialog = $xpath->query('//*[@*[name()="wire:partial" and .="action-modals"]]')->item(0);

        $this->assertInstanceOf(\DOMElement::class, $dialog);
        $this->assertSame(0, $xpath->query('ancestor::*[contains(concat(" ", normalize-space(@class), " "), " fi-section ")]', $dialog)->length);
        $this->assertModelExists($application);
    }

    public function test_shared_sign_out_trigger_opens_confirmation_and_keeps_the_protected_post_in_the_dialog(): void
    {
        [$application, $applicant] = $this->editableDraft();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        $response = $this->actingAs($applicant)->get(Dashboard::getUrl(['application' => $application->id]));
        $response->assertSee('Stay signed in')->assertSee('Save your work before signing out.');
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $trigger = $xpath->query('//button[@aria-label="Sign out"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $trigger);
        $this->assertStringContainsString("\$dispatch('open-modal', { id: 'tala-sign-out' })", $trigger->getAttribute('x-on:click'));
        $this->assertSame(0, $xpath->query('//form[button[@aria-label="Sign out"]]')->length);

        $form = $xpath->query('//*[@id="tala-sign-out"]//form')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $form);
        $this->assertSame('POST', $form->getAttribute('method'));
        $this->assertSame(route('filament.applicant.auth.logout'), $form->getAttribute('action'));
        $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form)->length);
        $this->assertAuthenticatedAs($applicant);
        $this->assertModelExists($application);
    }

    public function test_stale_submit_returns_to_the_owned_recorded_version_without_another_submission(): void
    {
        [$application, $applicant, $requirement] = $this->editableDraft();
        DocumentEvidence::factory()->canonical($application, $requirement)->create();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $page = Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id]);
        $version = ApplicationSubmissionVersion::factory()->create([
            'admission_application_id' => $application->id,
            'admission_requirement_set_id' => $requirement->admission_requirement_set_id,
            'submitted_by' => $applicant->id,
        ]);
        $application->update([
            'application_state' => AdmissionApplication::StateSubmitted,
            'current_submission_version_id' => $version->id,
        ]);

        $page->call('submitApplication')->assertSet('saveStatus', 'saved')
            ->assertRedirect(Dashboard::getUrl(['application' => $application->id]));

        $this->assertSame($version->id, $application->fresh()->current_submission_version_id);
        $this->assertSame(1, $application->submissionVersions()->count());
        $this->assertSame(1, $application->evidenceVersions()->count());
    }

    public static function submissionFailureOutcomes(): array
    {
        return ['before commit' => [false], 'after commit' => [true]];
    }

    #[DataProvider('submissionFailureOutcomes')]
    public function test_submit_failure_reports_the_authoritative_commit_outcome(bool $recorded): void
    {
        [$application, $applicant, $requirement] = $this->editableDraft();
        DocumentEvidence::factory()->canonical($application, $requirement)->create();
        $this->mock(SubmitAdmissionApplication::class)
            ->shouldReceive('execute')->once()->andReturnUsing(function () use ($application, $applicant, $requirement, $recorded): never {
                if ($recorded) {
                    $version = ApplicationSubmissionVersion::factory()->create([
                        'admission_application_id' => $application->id,
                        'admission_requirement_set_id' => $requirement->admission_requirement_set_id,
                        'submitted_by' => $applicant->id,
                    ]);
                    $application->update([
                        'application_state' => AdmissionApplication::StateSubmitted,
                        'current_submission_version_id' => $version->id,
                    ]);
                }

                throw new RuntimeException('Synthetic submission boundary failure');
            });
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $page = Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id])
            ->fillForm(['privacy_acknowledged' => true, 'accuracy_declared' => true])
            ->call('submitApplication');

        if ($recorded) {
            $page->assertSet('saveStatus', 'saved')
                ->assertRedirect(Dashboard::getUrl(['application' => $application->id]));
            $this->assertSame(1, $application->submissionVersions()->count());
        } else {
            $page->assertSet('saveStatus', 'failed')->assertNoRedirect()->assertNotDispatched('draft-saved');
            $this->assertNull($application->fresh()->current_submission_version_id);
            $this->assertSame(0, $application->submissionVersions()->count());
        }
    }

    public function test_recorded_submit_recovery_does_not_accept_another_owners_source(): void
    {
        [$application, $applicant, $requirement] = $this->editableDraft();
        $otherOwner = User::factory()->create(['status' => User::StatusActive]);
        $otherOwner->assignRole('applicant');
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        $page = Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id]);
        $version = ApplicationSubmissionVersion::factory()->create([
            'admission_application_id' => $application->id,
            'admission_requirement_set_id' => $requirement->admission_requirement_set_id,
            'submitted_by' => $otherOwner->id,
        ]);
        $application->update([
            'user_id' => $otherOwner->id,
            'application_state' => AdmissionApplication::StateSubmitted,
            'current_submission_version_id' => $version->id,
        ]);

        $page->call('submitApplication')
            ->assertSet('saveStatus', 'failed')->assertNoRedirect()->assertNotDispatched('draft-saved');

        $this->assertSame($otherOwner->id, $application->fresh()->user_id);
        $this->assertSame($version->id, $application->fresh()->current_submission_version_id);
        $this->assertSame(1, $application->submissionVersions()->count());
    }

    public function test_active_correction_does_not_recover_its_prior_submitted_version(): void
    {
        [$application, $applicant] = $this->application();
        $application->update(['application_state' => AdmissionApplication::StateActionNeeded]);
        ApplicationCorrectionRequest::factory()->for($application, 'application')->create();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id])
            ->fillForm(['accuracy_declared' => false])
            ->call('submitApplication')
            ->assertSet('saveStatus', 'failed')->assertNoRedirect()->assertNotDispatched('draft-saved');

        $this->assertSame(AdmissionApplication::StateActionNeeded, $application->fresh()->application_state);
        $this->assertSame(1, $application->submissionVersions()->count());
    }

    public function test_submit_without_current_privacy_acknowledgment_is_not_reported_as_recorded(): void
    {
        [$application, $applicant, $requirement] = $this->editableDraft();
        DocumentEvidence::factory()->canonical($application, $requirement)->create();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id])
            ->fillForm(['privacy_acknowledged' => false, 'accuracy_declared' => true])
            ->call('submitApplication')
            ->assertSet('saveStatus', 'failed')->assertNoRedirect()->assertNotDispatched('draft-saved');

        $this->assertNull($application->fresh()->current_submission_version_id);
        $this->assertSame(0, $application->submissionVersions()->count());
    }

    public function test_saved_review_copy_is_reused_without_another_evidence_version(): void
    {
        Storage::fake('local');
        [$application, $applicant, $requirement] = $this->editableDraft();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        $page = Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id])
            ->assertWizardCurrentStep(4)
            ->assertSee('Choose School review copy')
            ->fillForm(['privacy_acknowledged' => true, "evidence.{$requirement->id}" => UploadedFile::fake()->createWithContent('review-copy.pdf', "%PDF-1.4\nsynthetic review copy\n%%EOF")])
            ->goToNextWizardStep()
            ->assertHasNoFormErrors()
            ->assertDispatched('next-wizard-step')
            ->assertWizardCurrentStep(5)
            ->assertSee('School review copy: Saved review copy')
            ->assertDontSee('previously saved required copies apply')
            ->assertNoRedirect();

        $this->assertSame(1, $application->evidenceVersions()->count());
        $page->callAction('saveAndExit')->assertHasNoFormErrors();
        $this->assertSame(1, $application->evidenceVersions()->count());
        $this->assertSame(0, $application->submissionVersions()->count());
    }

    public function test_review_summary_reports_missing_copies_without_claiming_saved_evidence(): void
    {
        [$application, $applicant] = $this->editableDraft();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id])
            ->assertSee('School review copy: Not provided')
            ->assertDontSee('Saved review copy')
            ->assertDontSee('previously saved required copies apply');
    }

    public function test_review_summary_identifies_a_new_selected_copy_without_persisting_it(): void
    {
        Storage::fake('local');
        [$application, $applicant, $requirement] = $this->editableDraft();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id])
            ->fillForm(['privacy_acknowledged' => true, "evidence.{$requirement->id}" => UploadedFile::fake()->createWithContent('new-review-copy.pdf', "%PDF-1.4\nsynthetic pending copy\n%%EOF")])
            ->assertSee('School review copy: New file selected')
            ->assertSee('new-review-copy.pdf');

        $this->assertSame(0, $application->evidenceVersions()->count());
    }

    public function test_review_summary_identifies_a_pending_replacement_and_keeps_private_paths_hidden(): void
    {
        Storage::fake('local');
        [$application, $applicant, $requirement] = $this->editableDraft();
        $evidence = DocumentEvidence::factory()->canonical($application, $requirement)->create();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id])
            ->fillForm(['privacy_acknowledged' => true, "evidence.{$requirement->id}" => UploadedFile::fake()->createWithContent('replacement-copy.pdf', "%PDF-1.4\nsynthetic replacement copy\n%%EOF")])
            ->assertSee('School review copy: Replacement selected')
            ->assertSee('replacement-copy.pdf')
            ->assertDontSee($evidence->path);

        $this->assertSame(1, $application->evidenceVersions()->count());
    }

    public function test_declining_optional_identity_consent_excludes_values_from_wizard_save(): void
    {
        [$application, $applicant] = $this->editableDraft();
        $application->update(['first_name' => null, 'gender' => 'Female', 'civil_status' => 'Single', 'optional_identity_consented_at' => now()]);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id])
            ->fillForm(['first_name' => 'Consent declined', 'optional_identity_consent' => false])
            ->goToNextWizardStep()
            ->assertHasNoFormErrors()
            ->assertDispatched('next-wizard-step');

        $this->assertNull($application->fresh()->gender);
        $this->assertNull($application->fresh()->civil_status);
        $this->assertNull($application->fresh()->optional_identity_consented_at);
    }

    public function test_copy_failure_reports_partial_save_and_keeps_the_selected_upload(): void
    {
        Storage::fake('local');
        [$application, $applicant, $requirement] = $this->editableDraft();
        $this->mock(AdmissionEvidenceService::class)->shouldReceive('store')->once()->andThrow(new RuntimeException('Synthetic storage failure'));
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        $page = Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id])
            ->fillForm(['middle_name' => 'Saved before copy failure', "evidence.{$requirement->id}" => UploadedFile::fake()->createWithContent('review-copy.pdf', "%PDF-1.4\nsynthetic failed copy\n%%EOF")])
            ->goToNextWizardStep()
            ->assertSet('saveStatus', 'failed')
            ->assertSee('Some answers may have saved before a copy failed')
            ->assertNotDispatched('draft-saved')
            ->assertNotDispatched('next-wizard-step')
            ->assertNoRedirect();

        $this->assertSame('Saved before copy failure', $application->fresh()->middle_name);
        $this->assertNotEmpty($page->get("data.evidence.{$requirement->id}"));
        $this->assertSame(0, $application->evidenceVersions()->count());
        $this->assertSame(0, $application->submissionVersions()->count());
    }

    public function test_correction_form_reopens_named_fields_and_evidence_requirements_and_clears_primary_action(): void
    {
        [$application, , $requirement] = $this->application();
        $registrar = $this->registrar();
        $due = now()->timezone(config('app.display_timezone'))->addDay()->startOfMinute();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id])
            ->callAction('requestCorrection', data: [
                'fields' => ['phone', 'first_name'],
                'evidence_requirements' => [(string) $requirement->id],
                'applicant_instruction' => 'Please provide phone and replacement school review copy.',
                'due_at' => $due->toDateTimeString(),
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(AdmissionApplication::StateActionNeeded, $application->fresh()->application_state);
        $activeRequest = $application->fresh()->correctionRequests()->first();
        $this->assertNotNull($activeRequest);
        $this->assertSame('Please provide phone and replacement school review copy.', $activeRequest->applicant_instruction);
        $this->assertCount(3, $activeRequest->items);
        $this->assertTrue($activeRequest->items->contains(fn ($item): bool => $item->scope_type === ApplicationCorrectionItem::ScopeField && $item->scope_key === 'phone'));
        $this->assertTrue($activeRequest->items->contains(fn ($item): bool => $item->scope_type === ApplicationCorrectionItem::ScopeField && $item->scope_key === 'first_name'));
        $this->assertTrue($activeRequest->items->contains(fn ($item): bool => $item->scope_type === ApplicationCorrectionItem::ScopeEvidence && $item->admission_requirement_id === $requirement->id));

        $waitingComponent = Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id]);
        $headerActions = $waitingComponent->instance()->getCachedHeaderActions();
        $this->assertInstanceOf(ActionGroup::class, $headerActions[0]);
    }

    public function test_record_decision_blocks_admitted_when_preliminary_review_is_incomplete(): void
    {
        [$application, , $requirement] = $this->application(preDecision: true);
        $registrar = $this->registrar();
        DocumentEvidence::factory()->canonical($application, $requirement)->create();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id])
            ->callAction('recordDecision', data: [
                'decision' => AdmissionDecision::DecisionAdmitted,
                'reason' => 'Trying to admit without review',
                'applicant_explanation' => 'Unreviewed explanation',
            ])
            ->assertHasActionErrors(['decision']);

        $this->assertSame(AdmissionApplication::StateSubmitted, $application->fresh()->application_state);
        $this->assertSame(0, $application->decisions()->count());
    }

    public function test_view_preserves_queue_parameter(): void
    {
        [$application] = $this->application();
        $registrar = $this->registrar();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, [
            'record' => $application->id,
            'queue' => 'waiting_for_applicant',
        ]);

        $this->assertSame('waiting_for_applicant', $component->get('queue'));

        // 1. Breadcrumb return contains queue contextual URL
        $breadcrumbs = $component->instance()->getBreadcrumbs();
        $expectedQueueUrl = ListAdmissionApplications::getUrl(['tab' => 'waiting_for_applicant']);
        $this->assertArrayHasKey($expectedQueueUrl, $breadcrumbs);
        $this->assertSame('Application queue', $breadcrumbs[$expectedQueueUrl]);

        // 2. Blocked save preserves mounted form, retained input, and queue context
        $component->mountAction('recordDecision')
            ->setActionData([
                'decision' => AdmissionDecision::DecisionNotAdmitted,
                'reason' => 'Tentative decision reason',
                'applicant_explanation' => 'Tentative explanation',
                'expected_submission_version_id' => 999999,
            ])
            ->callMountedAction();

        $component->assertNotified('Registrar action blocked');
        $component->assertActionMounted('recordDecision');
        $component->assertActionDataSet([
            'decision' => AdmissionDecision::DecisionNotAdmitted,
            'reason' => 'Tentative decision reason',
            'applicant_explanation' => 'Tentative explanation',
        ]);
        $this->assertSame('waiting_for_applicant', $component->get('queue'));
        $this->assertArrayHasKey($expectedQueueUrl, $component->instance()->getBreadcrumbs());

        // 3. Successful save redirects with preserved queue parameter
        $successComponent = Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, [
            'record' => $application->id,
            'queue' => 'waiting_for_applicant',
        ]);
        $successComponent->mountAction('recordDecision')
            ->setActionData([
                'decision' => AdmissionDecision::DecisionNotAdmitted,
                'reason' => 'Valid not admitted decision',
                'applicant_explanation' => 'Requirements not met',
            ])
            ->callMountedAction();

        $expectedRedirect = AdmissionApplicationResource::getUrl('view', ['record' => $application->id, 'queue' => 'waiting_for_applicant']);
        $successComponent->assertRedirect($expectedRedirect);

        // 4. Following breadcrumb returns to the queue with the tab active
        $savedRecord = Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, [
            'record' => $application->id,
            'queue' => 'waiting_for_applicant',
        ]);
        $this->assertArrayHasKey($expectedQueueUrl, $savedRecord->instance()->getBreadcrumbs());

        parse_str((string) parse_url($expectedQueueUrl, PHP_URL_QUERY), $returnQuery);
        Livewire::withQueryParams($returnQuery)->actingAs($registrar)->test(ListAdmissionApplications::class)
            ->assertSet('activeTab', 'waiting_for_applicant');
    }

    public function test_correction_picker_offers_only_published_preliminary_digital_requirements(): void
    {
        [$application, , $digital] = $this->application(withPaperRequirement: true);
        $paper = $digital->requirementSet->requirements()->where('requires_preliminary_evidence', false)->sole();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($this->registrar())->test(ViewAdmissionApplication::class, ['record' => $application->id])
            ->mountAction('requestCorrection');

        $options = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName())->getComponentByStatePath('evidence_requirements')->getOptions();

        $this->assertSame([$digital->id => $digital->label], $options);
        $this->assertArrayNotHasKey($paper->id, $options);
    }

    public function test_correction_service_rejects_a_paper_only_scope_without_changing_the_application(): void
    {
        [$application, , $digital] = $this->application(withPaperRequirement: true);
        $paper = $digital->requirementSet->requirements()->where('requires_preliminary_evidence', false)->sole();
        $versionId = $application->current_submission_version_id;
        $eventCount = $application->events()->count();

        try {
            app(RequestAdmissionCorrection::class)->execute(
                $application,
                $this->registrar(),
                [[
                    'type' => ApplicationCorrectionItem::ScopeEvidence,
                    'key' => $paper->code,
                    'admission_requirement_id' => $paper->id,
                ]],
                'Replace this paper-only credential.',
                'Applicant',
                now()->addDay(),
            );
            $this->fail('An external school-paper requirement cannot reopen an Applicant upload.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scopes', $exception->errors());
        }

        $this->assertSame(AdmissionApplication::StateSubmitted, $application->fresh()->application_state);
        $this->assertSame($versionId, $application->fresh()->current_submission_version_id);
        $this->assertSame(0, $application->correctionRequests()->count());
        $this->assertSame($eventCount, $application->events()->count());
        $this->assertSame(1, $application->submissionVersions()->count());
    }

    public function test_correction_form_validates_at_least_one_scope_selected(): void
    {
        [$application] = $this->application();
        $registrar = $this->registrar();
        $due = now()->timezone(config('app.display_timezone'))->addDay()->startOfMinute();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id])
            ->callAction('requestCorrection', data: [
                'fields' => [],
                'evidence_requirements' => [],
                'applicant_instruction' => 'Missing scope test',
                'due_at' => $due->toDateTimeString(),
            ])
            ->assertHasActionErrors(['fields']);

        $this->assertSame(AdmissionApplication::StateSubmitted, $application->fresh()->application_state);
        $this->assertSame(0, $application->correctionRequests()->count());
    }

    public function test_not_admitted_application_does_not_render_acknowledgment_as_primary_action(): void
    {
        [$application] = $this->application();
        $registrar = $this->registrar();
        $application->update(['application_state' => AdmissionApplication::StateNotAdmitted]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id]);
        $headerActions = $component->instance()->getCachedHeaderActions();

        $this->assertCount(1, $headerActions);
        $this->assertInstanceOf(ActionGroup::class, $headerActions[0]);
    }

    public function test_review_evidence_action_hydrates_concurrency_review_id_when_preselected(): void
    {
        [$application, , $requirement] = $this->application(preDecision: true);
        $registrar = $this->registrar();
        $evidence = DocumentEvidence::factory()->canonical($application, $requirement)->create();

        $firstReview = PreliminaryEvidenceReview::factory()->create([
            'document_evidence_id' => $evidence->id,
            'reviewed_by' => $registrar->id,
            'result' => PreliminaryEvidenceReview::ResultUnderReview,
            'reviewed_at' => now(),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id])
            ->mountAction('reviewEvidence', ['document_evidence_id' => $evidence->id])
            ->assertActionDataSet([
                'document_evidence_id' => $evidence->id,
                'expected_current_review_id' => $firstReview->id,
            ]);
    }

    public function test_evidence_row_opens_the_shared_review_for_that_exact_copy(): void
    {
        [$application, , $requirement] = $this->application();
        $registrar = $this->registrar();
        $evidence = DocumentEvidence::factory()->canonical($application, $requirement)->create();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id])
            ->call('mountAction', 'reviewEvidenceRow', [], [
                'recordKey' => (string) $evidence->id,
                'schemaComponent' => 'infolist.evidenceVersions.0',
            ])
            ->assertActionMounted('reviewEvidence')
            ->assertActionDataSet([
                'document_evidence_id' => $evidence->id,
                'expected_current_review_id' => null,
            ]);

        $this->assertSame(0, $evidence->preliminaryReviews()->count());
    }

    public function test_action_blocked_by_backend_validation_displays_notification_and_retains_form_values(): void
    {
        [$application] = $this->application();
        $registrar = $this->registrar();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($registrar)
            ->test(ViewAdmissionApplication::class, ['record' => $application->id])
            ->mountAction('requestCorrection')
            ->setActionData([
                'fields' => ['first_name'],
                'applicant_instruction' => 'Retained instruction after blocked action',
                'due_at' => now()->addHour()->toDateTimeString(),
            ]);

        // Concurrently bump current submission version to trigger domain concurrency guard
        $newVersion = ApplicationSubmissionVersion::factory()->create([
            'admission_application_id' => $application->id,
            'admission_requirement_set_id' => $application->currentSubmissionVersion->admission_requirement_set_id,
            'version' => 2,
            'submitted_by' => $application->user_id,
        ]);
        $application->update(['current_submission_version_id' => $newVersion->id]);

        $component->callMountedAction();

        $component->assertNotified('Registrar action blocked');
        $component->assertActionMounted('requestCorrection');
        $component->assertActionDataSet([
            'applicant_instruction' => 'Retained instruction after blocked action',
        ]);

        $this->assertSame(AdmissionApplication::StateSubmitted, $application->fresh()->application_state);
        $this->assertSame(0, $application->correctionRequests()->count());
    }

    public function test_cycle_action_blocked_by_backend_validation_halts_and_retains_form_values(): void
    {
        $registrar = $this->registrar();
        $registrar->givePermissionTo(Permission::findOrCreate('manage-admission-setup', 'web'));

        $cycle = AdmissionCycle::factory()->published()->create([
            'term_id' => Term::query()->value('id') ?? Term::factory()->create()->id,
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addDay(),
            'correction_closes_at' => now()->addDays(2),
        ]);

        $originalClosesAt = $cycle->closes_at;
        $originalCorrectionClosesAt = $cycle->correction_closes_at;
        $eventsBefore = $cycle->events()->count();
        $invalidClosesAt = now()->addDays(5)->toDateTimeString();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($registrar)
            ->test(ViewAdmissionCycle::class, ['record' => $cycle->id])
            ->mountAction('extend')
            ->setActionData([
                'closes_at' => $invalidClosesAt,
                'authority_reference' => 'MEMO-2026-EXT',
                'reason' => 'Invalid extension beyond correction boundary',
            ])
            ->callMountedAction();

        $component->assertNotified('Admission Cycle action blocked');
        $component->assertActionMounted('extend');
        $component->assertActionDataSet([
            'closes_at' => CarbonImmutable::parse($invalidClosesAt, config('app.display_timezone'))
                ->timezone(config('app.timezone'))->toDateTimeString(),
            'reason' => 'Invalid extension beyond correction boundary',
            'authority_reference' => 'MEMO-2026-EXT',
        ]);

        $fresh = $cycle->fresh();
        $this->assertSame(AdmissionCycle::StatePublished, $fresh->state);
        $this->assertTrue($originalClosesAt->eq($fresh->closes_at));
        $this->assertTrue($originalCorrectionClosesAt->eq($fresh->correction_closes_at));
        $this->assertSame($eventsBefore, $fresh->events()->count());
    }

    public function test_concurrent_first_review_rejects_mounted_form_with_null_snapshot(): void
    {
        [$application, , $requirement] = $this->application(preDecision: true);
        $registrar = $this->registrar();
        $evidence = DocumentEvidence::factory()->canonical($application, $requirement)->create();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($registrar)
            ->test(ViewAdmissionApplication::class, ['record' => $application->id])
            ->mountAction('reviewEvidence', ['document_evidence_id' => $evidence->id]);

        $component->assertActionDataSet([
            'document_evidence_id' => $evidence->id,
            'expected_current_review_id' => null,
        ]);

        // Concurrently record a first review before the mounted action is submitted
        PreliminaryEvidenceReview::factory()->create([
            'document_evidence_id' => $evidence->id,
            'reviewed_by' => $registrar->id,
            'result' => PreliminaryEvidenceReview::ResultUnderReview,
            'reviewed_at' => now(),
        ]);

        // Submit mounted form with review details without re-triggering evidence selection hydration
        $component->setActionData([
            'result' => PreliminaryEvidenceReview::ResultAccepted,
            'reason' => 'Review recorded concurrently',
        ])->callMountedAction();

        $component->assertNotified('Registrar action blocked');
        $component->assertActionMounted('reviewEvidence');
        $component->assertActionDataSet([
            'reason' => 'Review recorded concurrently',
        ]);

        $this->assertSame(1, $evidence->preliminaryReviews()->count());
    }

    public function test_missing_required_preliminary_copy_shows_missing_copy_and_guards_admitted(): void
    {
        [$application, , $requirement] = $this->application(preDecision: true);
        $registrar = $this->registrar();

        $this->assertSame(0, $application->evidenceVersions()->count());

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id]);

        $component->assertSee('Missing copy — not uploaded');
        $component->assertSee('Missing preliminary copy');
        $component->assertSee('Cannot record review without submitted copy. Request via scoped correction.');

        // 1. Controls are unavailable for missing copy: review has null selection and no persisted files
        $component->mountAction('reviewEvidence');
        $component->assertActionDataSet([
            'document_evidence_id' => null,
        ]);

        try {
            app(RecordAdmissionDecision::class)->execute(
                $application,
                $registrar,
                AdmissionDecision::DecisionAdmitted,
                'Admitted despite missing preliminary evidence',
                'AUTH-FORCE-1',
                'Explanation',
            );
            $this->fail('Recording Admitted without required preliminary review must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('preliminary_evidence', $exception->errors());
        }

        $this->assertSame(AdmissionApplication::StateSubmitted, $application->fresh()->application_state);
        $this->assertSame(0, $application->decisions()->count());

        // Submit the named missing requirement as an actual scoped correction
        $due = now()->timezone(config('app.display_timezone'))->addDay()->startOfMinute();
        $component->mountAction('requestCorrection')
            ->setActionData([
                'evidence_requirements' => [$requirement->id],
                'applicant_instruction' => 'Please upload the missing preliminary copy.',
                'due_at' => $due->format('Y-m-d H:i:s'),
            ])
            ->callMountedAction();

        $component->assertNotified('Scoped correction requested');
        $freshApplication = $application->fresh();
        $this->assertSame(AdmissionApplication::StateActionNeeded, $freshApplication->application_state);
        $activeRequest = $freshApplication->correctionRequests->where('state', ApplicationCorrectionRequest::StateActive)->first();
        $this->assertNotNull($activeRequest);
        $this->assertTrue($activeRequest->items->contains(fn ($item) => (int) $item->admission_requirement_id === (int) $requirement->id));

        $this->assertFalse(app(ReadyApplicantProjectionQuery::class)->preliminaryReviewIsComplete($freshApplication));
        $this->assertSame(AdmissionApplication::StateActionNeeded, $freshApplication->fresh()->application_state);
        $this->assertSame(0, $freshApplication->decisions()->count());
    }

    public function test_setup_only_registrar_cannot_access_applications_queue_and_invalid_correction_scope_is_rejected(): void
    {
        $registrarRole = Role::findByName(User::StaffRoleRegistrar, 'web');
        $registrarRole->revokePermissionTo('approve-documents');
        $evaluationPermission = Permission::findOrCreate('evaluate-transferees', 'web');
        $couldEvaluateTransferees = $registrarRole->hasPermissionTo($evaluationPermission);
        $registrarRole->revokePermissionTo($evaluationPermission);

        try {
            $setupRegistrar = User::factory()->create(['status' => User::StatusActive]);
            $setupRegistrar->assignRole(User::StaffRoleRegistrar);
            $setupRegistrar->givePermissionTo(Permission::findOrCreate('manage-admission-setup', 'web'));

            [$application] = $this->application();

            Filament::setCurrentPanel(Filament::getPanel('admin'));
            Livewire::actingAs($setupRegistrar)->test(ListAdmissionApplications::class)->assertForbidden();
            Livewire::actingAs($setupRegistrar)->test(ListAdmissionCycles::class)->assertSuccessful();

            // Setup-only registrar compatibility HTTP routes are forbidden via Gate
            $this->actingAs($setupRegistrar)->get('/admin/admission-applications')->assertForbidden();
            $this->actingAs($setupRegistrar)->get("/admin/admission-applications/{$application->id}")->assertForbidden();

            // Denied applicant compatibility HTTP routes are forbidden via Gate
            $deniedApplicant = User::factory()->create(['status' => User::StatusActive]);
            $deniedApplicant->assignRole('applicant');
            $this->actingAs($deniedApplicant)->get('/admin/admission-applications')->assertForbidden();
            $this->actingAs($deniedApplicant)->get("/admin/admission-applications/{$application->id}")->assertForbidden();
        } finally {
            $registrarRole->givePermissionTo('approve-documents');
            if ($couldEvaluateTransferees) {
                $registrarRole->givePermissionTo('evaluate-transferees');
            }
        }

        $authorizedRegistrar = $this->registrar();

        // Authorized registrar compatibility HTTP routes preserve query parameters on redirect
        $this->actingAs($authorizedRegistrar)->get('/admin/admission-applications?queue=needs_review')
            ->assertRedirect(AdmissionApplicationResource::getUrl().'?queue=needs_review');
        $this->actingAs($authorizedRegistrar)->get("/admin/admission-applications/{$application->id}?queue=needs_review")
            ->assertRedirect(AdmissionApplicationResource::getUrl('view', ['record' => $application]).'?queue=needs_review');

        // Invalid correction scope: invalid field key
        try {
            app(RequestAdmissionCorrection::class)->execute($application, $authorizedRegistrar, [
                [
                    'type' => ApplicationCorrectionItem::ScopeField,
                    'key' => 'invalid_field_key',
                    'admission_requirement_id' => null,
                ],
            ], 'Invalid field instruction', 'Applicant', now()->addHour());
            $this->fail('Invalid field key in correction scope must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('scopes', $e->errors());
        }

        // Invalid correction scope: existing evidence requirement outside retained set
        $otherCycle = AdmissionCycle::factory()->published()->create([
            'term_id' => Term::query()->value('id') ?? Term::factory()->create()->id,
        ]);
        $otherSet = AdmissionRequirementSet::factory()->create([
            'admission_cycle_id' => $otherCycle->id,
            'state' => AdmissionRequirementSet::StateDraft,
        ]);
        $unrelatedRequirement = AdmissionRequirement::factory()->create([
            'admission_requirement_set_id' => $otherSet->id,
        ]);

        try {
            app(RequestAdmissionCorrection::class)->execute($application, $authorizedRegistrar, [
                [
                    'type' => ApplicationCorrectionItem::ScopeEvidence,
                    'key' => $unrelatedRequirement->code,
                    'admission_requirement_id' => $unrelatedRequirement->id,
                ],
            ], 'Unrelated requirement instruction', 'Applicant', now()->addHour());
            $this->fail('Requirement outside current requirement set must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('scopes', $e->errors());
        }
    }

    /** @return array<string, array{bool}> */
    public static function staleSnapshotCases(): array
    {
        return [
            'concurrent first record' => [false],
            'concurrent replacement' => [true],
        ];
    }

    #[DataProvider('staleSnapshotCases')]
    public function test_stale_decision_and_clearance_forms_preserve_captured_snapshots(bool $replacement): void
    {
        $registrar = $this->registrar();
        $recordCount = $replacement ? 2 : 1;

        // 1. Decision replacement: stale captured decision ID rejects concurrent replacement
        [$application, , $requirement] = $this->application(preDecision: true);
        $evidence = DocumentEvidence::factory()->canonical($application, $requirement)->create();
        PreliminaryEvidenceReview::factory()->create([
            'document_evidence_id' => $evidence->id,
            'reviewed_by' => $registrar->id,
            'result' => PreliminaryEvidenceReview::ResultAccepted,
            'reviewed_at' => now(),
        ]);

        $initialDecision = $replacement ? app(RecordAdmissionDecision::class)->execute(
            $application,
            $registrar,
            AdmissionDecision::DecisionAdmitted,
            'Initial admitted decision',
            null,
            'Admitted based on verified review',
        ) : null;
        $this->assertSame($replacement ? AdmissionApplication::StateAdmitted : AdmissionApplication::StateSubmitted, $application->fresh()->application_state);
        $this->assertSame($replacement ? 1 : 0, $application->fresh()->decisions()->count());

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $decisionComponent = Livewire::actingAs($registrar)
            ->test(ViewAdmissionApplication::class, ['record' => $application->id])
            ->mountAction('recordDecision');

        $decisionComponent->assertActionDataSet([
            'expected_current_decision_id' => $initialDecision?->id,
            'expected_submission_version_id' => $application->current_submission_version_id,
        ]);

        // Concurrently record a replacement decision
        $concurrentDecision = app(RecordAdmissionDecision::class)->execute(
            $application->fresh(),
            $registrar,
            AdmissionDecision::DecisionNotAdmitted,
            'Concurrent replacement reason',
            'AUTH-CONCURRENT-DEC',
            'Concurrent replacement explanation',
            expectedCurrentDecisionId: $initialDecision?->id,
            expectedSubmissionVersionId: $application->current_submission_version_id,
        );
        $this->assertSame($recordCount, $application->fresh()->decisions()->count());

        // Submit mounted form with stale captured snapshot
        $decisionComponent->setActionData([
            'decision' => AdmissionDecision::DecisionNotAdmitted,
            'reason' => 'Stale attempt reason',
            'authority_reference' => 'AUTH-STALE-DEC',
            'applicant_explanation' => 'Stale explanation',
        ])->callMountedAction();

        $decisionComponent->assertNotified('Registrar action blocked');
        $decisionComponent->assertActionMounted('recordDecision');
        $decisionComponent->assertActionDataSet([
            'reason' => 'Stale attempt reason',
            'authority_reference' => 'AUTH-STALE-DEC',
            'applicant_explanation' => 'Stale explanation',
        ]);
        $this->assertSame($recordCount, $application->fresh()->decisions()->count());
        $this->assertSame($concurrentDecision->id, $application->fresh()->decisions()->whereDoesntHave('successor')->value('id'));

        // 2. Clearance replacement: stale captured clearance ID rejects concurrent replacement
        [$clearanceApp, , $clearanceReq] = $this->application(preDecision: true);
        $clearanceEv = DocumentEvidence::factory()->canonical($clearanceApp, $clearanceReq)->create();
        PreliminaryEvidenceReview::factory()->create([
            'document_evidence_id' => $clearanceEv->id,
            'reviewed_by' => $registrar->id,
            'result' => PreliminaryEvidenceReview::ResultAccepted,
            'reviewed_at' => now(),
        ]);
        $admittedDecision = app(RecordAdmissionDecision::class)->execute(
            $clearanceApp,
            $registrar,
            AdmissionDecision::DecisionAdmitted,
            'Admitted for clearance test',
            null,
            'Clearance admitted basis',
        );
        $initialClearance = $replacement ? app(RecordRegistrarEnrollmentClearance::class)->execute(
            $clearanceApp,
            $registrar,
            RegistrarEnrollmentClearance::ResultActionNeeded,
            false,
            'Submit physical document',
            null,
            null,
            null,
            $admittedDecision->id,
            $clearanceApp->current_submission_version_id,
        ) : null;
        $this->assertSame($replacement ? 1 : 0, $clearanceApp->fresh()->enrollmentClearances()->count());

        $clearanceComponent = Livewire::actingAs($registrar)
            ->test(ViewAdmissionApplication::class, ['record' => $clearanceApp->id])
            ->mountAction('recordEnrollmentClearance');

        $clearanceComponent->assertActionDataSet([
            'expected_current_clearance_id' => $initialClearance?->id,
            'expected_decision_id' => $admittedDecision->id,
            'expected_submission_version_id' => $clearanceApp->current_submission_version_id,
        ]);

        // Concurrently record replacement clearance
        $concurrentClearance = app(RecordRegistrarEnrollmentClearance::class)->execute(
            $clearanceApp->fresh(),
            $registrar,
            RegistrarEnrollmentClearance::ResultCleared,
            true,
            null,
            'Concurrent clearance replacement reason',
            'AUTH-CLEAR-CONCURRENT',
            $initialClearance?->id,
            $admittedDecision->id,
            $clearanceApp->current_submission_version_id,
        );
        $this->assertSame($recordCount, $clearanceApp->fresh()->enrollmentClearances()->count());

        // Submit mounted clearance form with stale captured snapshot
        $clearanceComponent->setActionData([
            'result' => RegistrarEnrollmentClearance::ResultCleared,
            'external_checks_confirmed' => true,
            'reason' => 'Stale clearance replacement attempt',
            'authority_reference' => 'AUTH-STALE-CLEAR',
        ])->callMountedAction();

        $clearanceComponent->assertNotified('Registrar action blocked');
        $clearanceComponent->assertActionMounted('recordEnrollmentClearance');
        $clearanceComponent->assertActionDataSet([
            'reason' => 'Stale clearance replacement attempt',
            'authority_reference' => 'AUTH-STALE-CLEAR',
        ]);
        $this->assertSame($recordCount, $clearanceApp->fresh()->enrollmentClearances()->count());
        $this->assertSame($concurrentClearance->id, $clearanceApp->fresh()->enrollmentClearances()->whereDoesntHave('successor')->value('id'));
    }

    public function test_admission_decision_summary_identifies_matching_and_earlier_retained_submission_source(): void
    {
        [$application, , $requirement] = $this->application(preDecision: true);
        $registrar = $this->registrar();
        $evidence = DocumentEvidence::factory()->canonical($application, $requirement)->create();
        PreliminaryEvidenceReview::factory()->create([
            'document_evidence_id' => $evidence->id,
            'reviewed_by' => $registrar->id,
            'result' => PreliminaryEvidenceReview::ResultAccepted,
            'reviewed_at' => now(),
        ]);

        $submissionVersion1 = $application->currentSubmissionVersion;
        $decision = app(RecordAdmissionDecision::class)->execute(
            $application,
            $registrar,
            AdmissionDecision::DecisionAdmitted,
            'Admitted with verified documents',
            null,
            'Explanation of admission',
        );

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // 1. Current submitted version match
        $page = Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id]);
        $page->assertSee("Submission Version {$submissionVersion1->version} · Current submitted version")
            ->assertSee('Admitted')
            ->assertSee('Admitted with verified documents')
            ->assertSee($registrar->name);

        // 2. Application updated with newer submission version: decision retains earlier submitted version indicator
        $cycle = $application->admissionCycle;
        $set = $application->currentSubmissionVersion->requirementSet;
        $submissionVersion2 = ApplicationSubmissionVersion::factory()->create([
            'admission_application_id' => $application->id,
            'admission_requirement_set_id' => $set->id,
            'version' => 2,
            'submitted_by' => $application->user_id,
        ]);
        $application->update(['current_submission_version_id' => $submissionVersion2->id]);

        $pageUpdated = Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id]);
        $pageUpdated->assertSee("Submission Version {$submissionVersion1->version} · Earlier submitted version (retained)")
            ->assertSee('Admitted')
            ->assertSee('Admitted with verified documents')
            ->assertSee($registrar->name);
    }

    /** @return array{AdmissionApplication, User, AdmissionRequirement} */
    private function editableDraft(): array
    {
        [$application, $applicant, $requirement] = $this->application(submitted: false);
        $application->program->update(['is_active' => true]);
        $application->admissionCycle->programs()->attach($application->program_id, ['accepts_first_year' => true, 'accepts_transferee' => true]);

        return [$application->fresh(), $applicant, $requirement];
    }

    /** @return array{AdmissionApplication, User, AdmissionRequirement} */
    private function application(bool $preDecision = false, bool $submitted = true, bool $withPaperRequirement = false): array
    {
        $applicant = User::factory()->create(['status' => User::StatusActive]);
        $applicant->assignRole('applicant');
        $cycle = AdmissionCycle::factory()->published()->create(['term_id' => Term::query()->value('id') ?? Term::factory()->create()->id, 'opens_at' => now()->subDay(), 'closes_at' => now()->addDay(), 'correction_closes_at' => now()->addDays(2)]);
        $application = AdmissionApplication::factory()->submitted()->create(['user_id' => $applicant->id, 'admission_cycle_id' => $cycle->id, 'application_state' => $submitted ? AdmissionApplication::StateSubmitted : AdmissionApplication::StateDraft]);
        $set = AdmissionRequirementSet::factory()->create(['admission_cycle_id' => $cycle->id, 'application_path' => $application->application_path]);
        $requirement = AdmissionRequirement::factory()->create(['admission_requirement_set_id' => $set->id, 'label' => 'School review copy', 'requires_preliminary_evidence' => true, 'due_stage' => $preDecision ? AdmissionRequirement::DuePreliminaryReview : AdmissionRequirement::DueEnrollmentReadiness]);
        if ($withPaperRequirement) {
            AdmissionRequirement::factory()->create([
                'admission_requirement_set_id' => $set->id,
                'label' => 'External school-paper instruction',
                'requires_preliminary_evidence' => false,
            ]);
        }
        $set->update(['state' => AdmissionRequirementSet::StatePublished, 'published_at' => now(), 'effective_at' => now()]);
        if ($submitted) {
            $version = ApplicationSubmissionVersion::factory()->create(['admission_application_id' => $application->id, 'admission_requirement_set_id' => $set->id, 'submitted_by' => $applicant->id]);
            $application->update(['current_submission_version_id' => $version->id]);
        }

        return [$application->fresh(), $applicant, $requirement];
    }

    private function registrar(): User
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        return $registrar;
    }
}
