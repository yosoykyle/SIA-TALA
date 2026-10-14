<?php

namespace Tests\Feature;

use App\Actions\Admissions\AdmissionEvidenceService;
use App\Actions\Admissions\AdmissionReviewGuard;
use App\Actions\Admissions\RecordAdmissionOutcome;
use App\Actions\Admissions\RecordRegistrarEnrollmentClearance;
use App\Actions\Admissions\RequestAdmissionCorrection;
use App\Actions\Admissions\ResolveAdmissionEntryCycle;
use App\Actions\Admissions\ResolveAdmissionRequirementSet;
use App\Actions\Admissions\ReviewPreliminaryEvidence;
use App\Actions\Admissions\SaveAdmissionApplication;
use App\Actions\Admissions\SubmitAdmissionApplication;
use App\Actions\Enrollment\StartRegistrationCase;
use App\Filament\Applicant\Pages\Application as ApplicationPage;
use App\Filament\Applicant\Pages\SubmissionReceipt;
use App\Filament\Resources\AdmissionApplications\Pages\ViewAdmissionApplication;
use App\Models\AdmissionApplication;
use App\Models\AdmissionApplicationEvent;
use App\Models\AdmissionCycle;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationCorrectionRequest;
use App\Models\Enrollment;
use App\Models\IdentityMatchReview;
use App\Models\PreliminaryEvidenceReview;
use App\Models\Program;
use App\Models\RegistrarEnrollmentClearance;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\User;
use App\Policies\AdmissionApplicationPolicy;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Issue64AdmissionsJourneyTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('applicant', 'web');
        Role::findOrCreate(User::StaffRoleRegistrar, 'web')->givePermissionTo(Permission::findOrCreate('approve-documents', 'web'));
        Storage::fake('local');
        Mail::fake();
    }

    public function test_entry_requires_unique_context_and_never_guesses_an_intake(): void
    {
        [$cycle, $program] = $this->context();
        $resolver = app(ResolveAdmissionEntryCycle::class);
        $this->assertSame($cycle->id, $resolver->resolve($program->id, AdmissionApplication::PathFirstYear)->id);
        $cycle->update(['closes_at' => now()->subSecond()]);
        $this->refuses(fn () => $resolver->resolve($program->id, AdmissionApplication::PathFirstYear), 'program_id');
        $cycle->update(['closes_at' => now()->addDay()]);
        $other = AdmissionCycle::factory()->published()->create(['term_id' => $cycle->term_id, 'opens_at' => now()->subDay(), 'closes_at' => now()->addDay()]);
        $other->programs()->attach($program, ['accepts_first_year' => true]);
        AdmissionRequirementSet::factory()->for($other)->published()->create();
        $this->refuses(fn () => $resolver->resolve($program->id, AdmissionApplication::PathFirstYear), 'program_id');
        $this->assertSame(0, AdmissionApplication::query()->count());
    }

    public function test_successor_retains_reference_governing_set_history_and_files_after_public_closing(): void
    {
        [$application, $applicant, $cycle, $set] = $this->submitted();
        $old = $application->currentSubmissionVersion;
        $files = $application->evidenceVersions()->orderBy('id')->get()->map->getAttributes()->all();
        $cycle->update(['closes_at' => now()->subSecond()]);
        AdmissionRequirementSet::factory()->for($cycle)->published()->create(['version' => 2]);
        $edited = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, ['current_province' => 'Cavite', 'accuracy_declared' => true], $application);
        $this->assertSame(2, $edited->submissionVersions()->count());
        $this->assertSame($application->application_reference, $edited->application_reference);
        $this->assertSame($set->id, $edited->currentSubmissionVersion->admission_requirement_set_id);
        $this->assertSame('Laguna', $old->fresh()->snapshot['current_province']);
        $this->assertSame('Cavite', $edited->currentSubmissionVersion->snapshot['current_province']);
        $this->assertSame($files, $edited->evidenceVersions()->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_failed_successor_rolls_back_facts_replacement_file_and_snapshot(): void
    {
        [$application, $applicant, $cycle, $set] = $this->submitted();
        $attributes = $application->getAttributes();
        $files = Storage::disk('local')->allFiles();
        $requirement = $set->requirements()->firstOrFail();
        $this->refuses(fn () => app(SaveAdmissionApplication::class)->execute($applicant, $cycle,
            ['first_name' => null, 'accuracy_declared' => true], $application, evidenceFiles: [$requirement->id => $this->pdf('replacement')]), 'first_name');
        $this->assertSame($attributes, $application->fresh()->getAttributes());
        $this->assertSame(1, $application->submissionVersions()->count());
        $this->assertSame(2, $application->evidenceVersions()->count());
        $this->assertSame($files, Storage::disk('local')->allFiles());
    }

    public function test_student_type_change_requires_the_new_set_and_retains_every_previous_source(): void
    {
        [$application, $applicant, $cycle] = $this->submitted();
        $newSet = $this->requirements($cycle, AdmissionApplication::PathTransferee);
        $oldSource = $application->current_submission_version_id;
        $oldIds = $application->evidenceVersions()->pluck('id')->all();
        $changes = ['application_path' => AdmissionApplication::PathTransferee, 'credential_basis' => AdmissionApplication::CredentialTransfer, 'accuracy_declared' => true];
        $this->refuses(fn () => app(SaveAdmissionApplication::class)->execute($applicant, $cycle, $changes, $application), 'evidence');
        $this->assertSame($oldSource, $application->fresh()->current_submission_version_id);
        $uploads = $newSet->requirements()->get()->mapWithKeys(fn (AdmissionRequirement $requirement): array => [$requirement->id => $this->pdf($requirement->code)])->all();
        $edited = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, $changes, $application, evidenceFiles: $uploads, expectedRequirementSetId: $newSet->id);
        $this->assertSame($newSet->id, $edited->currentSubmissionVersion->admission_requirement_set_id);
        $this->assertSame($cycle->term_id, $edited->term_id);
        $this->assertSame($cycle->id, $edited->admission_cycle_id);
        $this->assertSame(4, $edited->evidenceVersions()->count());
        $this->assertSame(2, $edited->evidenceVersions()->whereIn('id', $oldIds)->count());
        $this->assertSame(AdmissionApplication::PathFirstYear, $edited->submissionVersions()->whereKey($oldSource)->firstOrFail()->snapshot['application_path']);
    }

    public function test_edit_review_ordering_rejects_stale_work_and_start_is_durable_and_idempotent(): void
    {
        [$application, $applicant, $cycle] = $this->submitted();
        $registrar = $this->registrar();
        $oldSource = $application->current_submission_version_id;
        $edited = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, ['current_province' => 'Cavite', 'accuracy_declared' => true], $application);
        $this->refuses(fn () => app(AdmissionReviewGuard::class)->start($application, $registrar, $oldSource), 'review');
        $this->assertFalse($edited->reviewHasStarted());
        app(AdmissionReviewGuard::class)->start($edited, $registrar, $edited->current_submission_version_id);
        app(AdmissionReviewGuard::class)->start($edited, $registrar, $edited->current_submission_version_id);
        $this->assertSame(1, $edited->events()->where('event_type', AdmissionApplicationEvent::TypeReviewStarted)->count());
        $event = $edited->events()->where('event_type', AdmissionApplicationEvent::TypeReviewStarted)->firstOrFail();
        $this->assertSame($registrar->id, $event->actor_id);
        $this->assertSame($edited->current_submission_version_id, $event->source_id);
        $this->refuses(fn () => app(SaveAdmissionApplication::class)->execute($applicant, $cycle, ['current_province' => 'Batangas'], $edited), 'application_state');
        $this->assertSame('Cavite', $edited->fresh()->current_province);
    }

    public function test_named_type_correction_pins_the_target_and_submits_one_atomic_successor(): void
    {
        [$application, $applicant, $cycle] = $this->submitted();
        $registrar = $this->registrar();
        $target = $this->requirements($cycle, AdmissionApplication::PathTransferee);
        $oldSource = $application->current_submission_version_id;
        $oldFiles = $application->evidenceVersions()->orderBy('id')->get()->map->getAttributes()->all();
        $candidate = AdmissionApplication::factory()->submitted()->create([
            'first_name' => $application->first_name, 'last_name' => $application->last_name, 'birth_date' => $application->birth_date,
        ]);
        $application->identityMatchReviews()->create([
            'review_key' => 'identity-warning:'.$application->id.':ExactNameBirthDate:'.$candidate->user_id,
            'candidate_user_id' => $candidate->user_id, 'match_type' => IdentityMatchReview::TypeExactNameBirthDate,
            'outcome' => IdentityMatchReview::OutcomeDifferentPerson, 'resolved_by' => $registrar->id, 'resolved_at' => now(),
        ]);
        $this->acceptCopies($application, $registrar);
        $request = app(RequestAdmissionCorrection::class)->execute($application, $registrar, [], 'Synthetic misclassified type', 'Applicant', now()->addHour(),
            targetApplicationPath: AdmissionApplication::PathTransferee, expectedTargetRequirementSetId: $target->id);
        $this->assertSame($oldSource, $request->source_submission_version_id);
        $this->assertSame($target->id, $request->target_requirement_set_id);
        $this->assertSame(10, $request->items()->count());
        AdmissionRequirementSet::factory()->for($cycle)->published()->create(['application_path' => AdmissionApplication::PathTransferee, 'version' => 2]);
        $cycle->update(['closes_at' => now()->subDay(), 'correction_closes_at' => now()->subHour()]);
        $this->assertSame($target->id, app(ResolveAdmissionRequirementSet::class)->forApplication($application->fresh())->id);
        $uploads = $target->requirements()->get()->mapWithKeys(fn (AdmissionRequirement $item): array => [$item->id => $this->pdf('type-'.$item->code)])->all();
        $edited = app(SaveAdmissionApplication::class)->execute($applicant, $cycle,
            ['application_path' => AdmissionApplication::PathTransferee, 'credential_basis' => AdmissionApplication::CredentialTransfer, 'accuracy_declared' => true],
            $application->fresh(), evidenceFiles: $uploads, expectedSubmissionVersionId: $oldSource, expectedRequirementSetId: $target->id, typeCorrectionCommit: true);
        $this->assertSame($target->id, $edited->currentSubmissionVersion->admission_requirement_set_id);
        $this->assertSame(AdmissionApplication::StateSubmitted, $edited->application_state);
        $this->assertSame(ApplicationCorrectionRequest::StateCompleted, $request->fresh()->state);
        $this->assertSame($oldFiles, $edited->evidenceVersions()->whereIn('id', array_column($oldFiles, 'id'))->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame(2, $edited->submissionVersions()->count());
        $this->assertFalse($edited->allowsSubmittedEdit());
        $this->assertFalse(app(ReadyApplicantProjectionQuery::class)->preliminaryReviewIsComplete($edited));
        $this->assertFalse(app(ReadyApplicantProjectionQuery::class)->forApplication($edited)['ready']);
        $this->assertTrue($edited->identityMatchReviews()->where('outcome', IdentityMatchReview::OutcomePending)
            ->where('review_key', 'like', '%:source:'.$edited->current_submission_version_id)->exists());
    }

    public function test_type_correction_refuses_partial_unscoped_and_failed_file_work_without_changing_the_source(): void
    {
        [$application, $applicant, $cycle] = $this->submitted();
        $registrar = $this->registrar();
        $target = $this->requirements($cycle, AdmissionApplication::PathTransferee);
        app(AdmissionReviewGuard::class)->start($application, $registrar, $application->current_submission_version_id);
        $request = app(RequestAdmissionCorrection::class)->execute($application, $registrar, [], 'Synthetic type correction', 'Applicant', now()->addHour(),
            targetApplicationPath: AdmissionApplication::PathTransferee, expectedTargetRequirementSetId: $target->id);
        $application = $application->fresh();
        $prior = $application->getAttributes();
        $files = Storage::disk('local')->allFiles();
        $changes = ['application_path' => AdmissionApplication::PathTransferee, 'credential_basis' => AdmissionApplication::CredentialTransfer, 'accuracy_declared' => true];
        $this->refuses(fn () => app(SaveAdmissionApplication::class)->execute($applicant, $cycle, $changes, $application), 'correction_scope');
        $this->refuses(fn () => app(SaveAdmissionApplication::class)->execute($applicant, $cycle, [...$changes, 'first_name' => 'Unscoped'], $application, typeCorrectionCommit: true), 'correction_scope');
        $item = $target->requirements()->firstOrFail();
        $this->refuses(fn () => app(SaveAdmissionApplication::class)->execute($applicant, $cycle, $changes, $application,
            evidenceFiles: [$item->id => $this->pdf('rolled-back-type-copy')], typeCorrectionCommit: true), 'evidence');
        $this->assertSame($prior, $application->fresh()->getAttributes());
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame(ApplicationCorrectionRequest::StateActive, $request->fresh()->state);
        $this->assertSame(1, $application->submissionVersions()->count());
    }

    public function test_returning_type_correction_retains_valid_copies_restores_a_missing_file_and_requires_fresh_review(): void
    {
        [$application, $applicant, $cycle, $originalSet] = $this->submitted();
        $registrar = $this->registrar();
        $target = $this->requirements($cycle, AdmissionApplication::PathTransferee);
        $this->acceptCopies($application, $registrar);
        $missing = $application->evidenceVersions()->firstOrFail();
        $retained = $application->evidenceVersions()->whereKeyNot($missing->id)->firstOrFail();
        $retainedAttributes = $retained->getAttributes();
        app(RequestAdmissionCorrection::class)->execute($application, $registrar, [], 'Synthetic transfer correction', 'Applicant', now()->addHour(),
            targetApplicationPath: AdmissionApplication::PathTransferee, expectedTargetRequirementSetId: $target->id);
        $uploads = $target->requirements()->get()->mapWithKeys(fn (AdmissionRequirement $item): array => [$item->id => $this->pdf('transfer-'.$item->code)])->all();
        $transferee = app(SaveAdmissionApplication::class)->execute($applicant, $cycle,
            ['application_path' => AdmissionApplication::PathTransferee, 'credential_basis' => AdmissionApplication::CredentialTransfer,
                'prior_college_identifier' => 'SYNTHETIC-COLLEGE-64', 'accuracy_declared' => true],
            $application->fresh(), evidenceFiles: $uploads, typeCorrectionCommit: true);
        Storage::disk('local')->delete($missing->path);
        $request = app(RequestAdmissionCorrection::class)->execute($transferee, $registrar, [], 'Synthetic returning type correction', 'Applicant', now()->addHour(),
            targetApplicationPath: AdmissionApplication::PathFirstYear, expectedTargetRequirementSetId: $originalSet->id);
        $this->assertSame([$missing->admission_requirement_id], $request->items()->where('scope_type', 'Evidence')->pluck('admission_requirement_id')->all());
        $returned = app(SaveAdmissionApplication::class)->execute($applicant, $cycle,
            ['application_path' => AdmissionApplication::PathFirstYear, 'credential_basis' => AdmissionApplication::CredentialSeniorHighSchool, 'accuracy_declared' => true],
            $transferee->fresh(), evidenceFiles: [$missing->admission_requirement_id => $this->pdf($missing->admissionRequirement->code)], typeCorrectionCommit: true);
        $restored = $returned->evidenceVersions()->where('admission_requirement_id', $missing->admission_requirement_id)->latest('id')->firstOrFail();
        $this->assertSame($missing->checksum, $restored->checksum);
        $this->assertSame($missing->id, $restored->replaces_document_evidence_id);
        $this->assertTrue(Storage::disk('local')->exists($restored->path));
        $this->assertSame($retainedAttributes, $retained->fresh()->getAttributes());
        $this->assertNull($returned->prior_college_identifier);
        $this->assertSame(['AwaitingReview'], array_values(array_unique(array_column($returned->currentSubmissionVersion->snapshot['requirements'], 'preliminary_result'))));
        $this->assertFalse(app(ReadyApplicantProjectionQuery::class)->preliminaryReviewIsComplete($returned));
        $this->refuses(fn () => $this->outcome($returned, $registrar, false), 'preliminary_evidence');
        app(RequestAdmissionCorrection::class)->execute($returned, $registrar, [['type' => 'Field', 'key' => 'current_province', 'admission_requirement_id' => null]],
            'Synthetic subsequent scoped correction', 'Applicant', now()->addHour());
        $saved = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, ['current_province' => 'Cavite'], $returned->fresh());
        $corrected = app(SubmitAdmissionApplication::class)->execute($saved, $applicant);
        $this->assertFalse(app(ReadyApplicantProjectionQuery::class)->preliminaryReviewIsComplete($corrected));
        foreach ($originalSet->requirements as $item) {
            $copy = $corrected->evidenceVersions()->where('admission_requirement_id', $item->id)->latest('id')->firstOrFail();
            app(ReviewPreliminaryEvidence::class)->execute($copy, $registrar, PreliminaryEvidenceReview::ResultAccepted, null,
                expectedCurrentReviewId: $copy->preliminaryReviews()->whereDoesntHave('successor')->value('id'));
        }
        $this->assertTrue(app(ReadyApplicantProjectionQuery::class)->preliminaryReviewIsComplete($corrected));
        $this->assertSame($corrected->current_submission_version_id, $this->outcome($corrected, $registrar, false)->application_submission_version_id);
    }

    public function test_type_correction_request_refuses_an_unconfirmed_set_or_recorded_decision(): void
    {
        [$application, , $cycle] = $this->submitted();
        $registrar = $this->registrar();
        $target = $this->requirements($cycle, AdmissionApplication::PathTransferee);
        $this->acceptCopies($application, $registrar);
        $this->refuses(fn () => app(RequestAdmissionCorrection::class)->execute($application, $registrar, [], 'Synthetic type', 'Applicant', now()->addHour(),
            targetApplicationPath: AdmissionApplication::PathTransferee, expectedTargetRequirementSetId: $target->id + 10000), 'target_application_path');
        $this->assertSame(0, $application->correctionRequests()->count());
        $this->outcome($application, $registrar, false);
        $this->refuses(fn () => app(RequestAdmissionCorrection::class)->studentTypeScope($application->fresh(), AdmissionApplication::PathTransferee), 'target_application_path');
    }

    public function test_type_correction_page_keeps_unrelated_answers_locked_and_submits_the_confirmed_target(): void
    {
        [$application, $applicant, $cycle] = $this->submitted();
        $target = $this->requirements($cycle, AdmissionApplication::PathTransferee);
        $registrar = $this->registrar();
        app(AdmissionReviewGuard::class)->start($application, $registrar, $application->current_submission_version_id);
        app(RequestAdmissionCorrection::class)->execute($application, $registrar, [], 'Correct the synthetic Student type', 'Applicant', now()->addHour(),
            targetApplicationPath: AdmissionApplication::PathTransferee, expectedTargetRequirementSetId: $target->id);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $page = Livewire::actingAs($applicant)->test(ApplicationPage::class, ['sourceApplicationId' => $application->id]);
        $page->assertSet('data.application_path', AdmissionApplication::PathTransferee)
            ->assertSet('data.requirement_set_id', $target->id);
        $this->assertTrue($page->instance()->requiresCompleteSubmission());
        $this->assertTrue($page->instance()->form->getComponent('first_name')->isDisabled());
        $page->set('data.first_name', 'Unrelated client change');
        $uploads = $target->requirements()->get()->mapWithKeys(fn (AdmissionRequirement $item): array => [$item->id => $this->pdf('page-type-'.$item->code)])->all();
        $page->fillForm(['evidence' => $uploads, 'accuracy_declared' => true])->call('submitApplication')->assertHasNoFormErrors();
        $edited = $application->fresh();
        $this->assertSame('Synthetic', $edited->first_name);
        $this->assertSame(AdmissionApplication::PathTransferee, $edited->application_path);
        $this->assertSame(2, $edited->submissionVersions()->count());
        $page->assertRedirect(SubmissionReceipt::getUrl(['application' => $edited->id, 'version' => $edited->current_submission_version_id]));
    }

    public function test_registrar_type_correction_requires_confirmation_of_the_complete_preview(): void
    {
        [$application, , $cycle] = $this->submitted();
        $target = $this->requirements($cycle, AdmissionApplication::PathTransferee);
        $registrar = $this->registrar();
        app(AdmissionReviewGuard::class)->start($application, $registrar, $application->current_submission_version_id);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id]);
        $data = ['fields' => ['application_path'], 'target_application_path' => AdmissionApplication::PathTransferee,
            'expected_submission_version_id' => $application->current_submission_version_id,
            'expected_target_requirement_set_id' => $target->id, 'type_scope_confirmed' => false,
            'applicant_instruction' => 'Synthetic confirmed type correction', 'due_at' => now(config('app.display_timezone'))->addHour()->toDateTimeString()];
        $page->mountAction('requestCorrection')->setActionData($data)->callMountedAction()->assertHasActionErrors(['type_scope_confirmed']);
        $this->assertSame(0, $application->correctionRequests()->count());
        $page->setActionData([...$data, 'type_scope_confirmed' => true])->callMountedAction()->assertHasNoActionErrors();
        $request = $application->correctionRequests()->sole();
        $this->assertSame($target->id, $request->target_requirement_set_id);
        $this->assertSame(10, $request->items()->count());
    }

    public function test_inspection_is_read_only_and_legacy_review_locks_without_an_invented_start(): void
    {
        [$application] = $this->submitted();
        $this->assertTrue($application->allowsSubmittedEdit());
        $this->assertTrue(app(AdmissionApplicationPolicy::class)->view($this->registrar(), $application));
        $this->assertFalse($application->reviewHasStarted());
        $application->evidenceVersions()->firstOrFail()->preliminaryReviews()->create([
            'result' => 'UnderReview', 'reviewed_by' => $this->registrar()->id, 'reviewed_at' => now(),
        ]);
        $this->assertTrue($application->reviewHasStarted());
        $this->assertFalse($application->allowsSubmittedEdit());
        $this->assertSame(0, $application->events()->where('event_type', AdmissionApplicationEvent::TypeReviewStarted)->count());
    }

    public function test_review_and_upload_guards_reject_old_and_unstarted_work(): void
    {
        [$application, $applicant, $cycle] = $this->submitted();
        $registrar = $this->registrar();
        $old = $application->evidenceVersions()->firstOrFail();
        $this->refuses(fn () => app(ReviewPreliminaryEvidence::class)->execute($old, $registrar, PreliminaryEvidenceReview::ResultAccepted, null), 'review');
        $this->refuses(fn () => app(AdmissionEvidenceService::class)->replace($old, $registrar, $this->pdf('staff')), 'evidence');
        $edited = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, ['accuracy_declared' => true], $application, evidenceFiles: [$old->admission_requirement_id => $this->pdf('successor')]);
        app(AdmissionReviewGuard::class)->start($edited, $registrar, $edited->current_submission_version_id);
        $this->refuses(fn () => app(ReviewPreliminaryEvidence::class)->execute($old, $registrar, PreliminaryEvidenceReview::ResultAccepted, null, expectedSubmissionVersionId: $edited->current_submission_version_id), 'evidence');
        $this->assertSame(0, $old->preliminaryReviews()->count());
        $current = $edited->evidenceVersions()->where('admission_requirement_id', $old->admission_requirement_id)->latest('id')->firstOrFail();
        app(ReviewPreliminaryEvidence::class)->execute($current, $registrar, PreliminaryEvidenceReview::ResultAccepted, null, expectedSubmissionVersionId: $edited->current_submission_version_id);
        $this->assertSame($old->id, $current->replaces_document_evidence_id);
    }

    public function test_correction_resubmission_retains_unaffected_files_and_the_review_lock(): void
    {
        [$application, $applicant, $cycle] = $this->submitted();
        $registrar = $this->registrar();
        app(AdmissionReviewGuard::class)->start($application, $registrar, $application->current_submission_version_id);
        $files = $application->evidenceVersions()->orderBy('id')->get()->map->getAttributes()->all();
        app(RequestAdmissionCorrection::class)->execute($application, $registrar, [['type' => 'Field', 'key' => 'current_province', 'admission_requirement_id' => null]], 'Correct the province.', 'Applicant', now()->addHour());
        $corrected = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, ['current_province' => 'Cavite', 'accuracy_declared' => true], $application->fresh());
        $resubmitted = app(SubmitAdmissionApplication::class)->execute($corrected, $applicant);
        $this->assertSame($files, $resubmitted->evidenceVersions()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertFalse($resubmitted->allowsSubmittedEdit());
        $this->assertSame(2, $resubmitted->submissionVersions()->count());
    }

    public function test_confirmed_papers_is_atomic_and_matching_readiness_creates_no_enrollment(): void
    {
        [$application] = $this->submitted();
        $registrar = $this->registrar();
        $this->acceptCopies($application, $registrar);
        $failOnce = true;
        RegistrarEnrollmentClearance::creating(function () use (&$failOnce): void {
            if ($failOnce) {
                $failOnce = false;
                throw ValidationException::withMessages(['result' => 'Synthetic persistence failure.']);
            }
        });
        $this->refuses(fn () => $this->outcome($application, $registrar, true), 'result');
        $this->assertSame(0, $application->decisions()->count());
        $this->assertSame(0, $application->enrollmentClearances()->count());
        $this->assertSame(AdmissionApplication::StateSubmitted, $application->fresh()->application_state);
        $decision = $this->outcome($application->fresh(), $registrar, true);
        $retried = $this->outcome($application->fresh(), $registrar, true);
        $this->assertSame($decision->id, $retried->id);
        $this->assertSame(1, $application->decisions()->count());
        $this->assertSame(1, $application->enrollmentClearances()->count());
        $clearance = $application->enrollmentClearances()->firstOrFail();
        $this->assertSame($decision->id, $clearance->admission_decision_id);
        $this->assertSame($application->current_submission_version_id, $clearance->application_submission_version_id);
        $this->assertTrue(app(ReadyApplicantProjectionQuery::class)->forApplication($application)['ready']);
        $this->assertSame(0, StudentProfile::query()->count());
        $this->assertSame(0, Enrollment::query()->count());
    }

    public function test_pending_papers_preserves_admission_without_fabricating_clearance(): void
    {
        [$application] = $this->submitted();
        $registrar = $this->registrar();
        $this->acceptCopies($application, $registrar);
        $this->outcome($application, $registrar, false);
        $this->assertSame(AdmissionApplication::StateAdmitted, $application->fresh()->application_state);
        $this->assertSame(0, $application->enrollmentClearances()->count());
        $this->assertFalse(app(ReadyApplicantProjectionQuery::class)->forApplication($application)['ready']);
    }

    public function test_later_unused_intake_keeps_earlier_application_in_the_same_term(): void
    {
        [$application, $applicant, $cycle] = $this->submitted();
        $cycle->update(['closes_at' => now()->subSecond()]);
        $next = AdmissionCycle::factory()->published()->create([
            'term_id' => $cycle->term_id, 'opens_at' => now()->subDay(), 'closes_at' => now()->addDay(),
        ]);
        $program = $application->program;
        $next->programs()->attach($program, ['accepts_first_year' => true]);
        $this->requirements($next, AdmissionApplication::PathFirstYear);
        $draft = app(SaveAdmissionApplication::class)->execute($applicant, $next, $this->answers($program));
        $this->assertNotSame($application->id, $draft->id);
        $this->assertSame($cycle->term_id, $draft->term_id);
        $this->assertSame(2, $applicant->admissionApplications()->count());
        $this->assertSame(1, $application->submissionVersions()->count());
        $this->assertSame(2, $application->evidenceVersions()->count());
    }

    /** @return array<string, array{string}> */
    public static function handoffCredentialChanges(): array
    {
        return ['account disabled' => ['disabled'], 'application owner changed' => ['owner']];
    }

    #[DataProvider('handoffCredentialChanges')]
    public function test_handoff_rechecks_credential_owner_and_active_actor(string $change): void
    {
        [$application, $applicant] = $this->submitted();
        $registrar = $this->registrar();
        $this->acceptCopies($application, $registrar);
        $this->outcome($application, $registrar, true);
        $this->openEnrollment($application);
        $projection = app(ReadyApplicantProjectionQuery::class);
        $first = true;
        $this->mock(ReadyApplicantProjectionQuery::class)->shouldReceive('forApplication')->andReturnUsing(
            function (AdmissionApplication $source) use ($projection, $applicant, $change, &$first): array {
                $result = $projection->forApplication($source);
                if ($first) {
                    $first = false;
                    if ($change === 'disabled') {
                        $applicant->fresh()->update(['status' => User::StatusInactive]);
                    } else {
                        $source->fresh()->update(['user_id' => $this->applicant()->id]);
                    }
                }

                return $result;
            },
        );
        try {
            app(StartRegistrationCase::class)->forReadyApplicant($application, $application->term, $applicant);
            $this->fail('Changed admission credentials must refuse the handoff.');
        } catch (AuthorizationException|ValidationException $exception) {
            $this->assertInstanceOf($change === 'disabled' ? AuthorizationException::class : ValidationException::class, $exception);
        }
        $this->assertSame(0, Enrollment::query()->count());
    }

    public function test_handoff_rechecks_a_changed_clearance_inside_the_transaction(): void
    {
        [$application, $applicant] = $this->submitted();
        $registrar = $this->registrar();
        $this->acceptCopies($application, $registrar);
        $decision = $this->outcome($application, $registrar, true);
        $clearance = $application->enrollmentClearances()->firstOrFail();
        $this->openEnrollment($application);
        $projection = app(ReadyApplicantProjectionQuery::class);
        $first = true;
        $this->mock(ReadyApplicantProjectionQuery::class)->shouldReceive('forApplication')->andReturnUsing(
            function (AdmissionApplication $source) use ($projection, $registrar, $decision, $clearance, &$first): array {
                $result = $projection->forApplication($source);
                if ($first) {
                    $first = false;
                    app(RecordRegistrarEnrollmentClearance::class)->execute($source, $registrar, 'ActionNeeded', false,
                        safeInstruction: 'Synthetic external check needs attention.', reason: 'Synthetic source changed.',
                        expectedCurrentClearanceId: $clearance->id, expectedDecisionId: $decision->id,
                        expectedSubmissionVersionId: $source->current_submission_version_id);
                }

                return $result;
            },
        );
        $this->refuses(fn () => app(StartRegistrationCase::class)->forReadyApplicant($application, $application->term, $applicant), 'application');
        $this->assertSame(0, Enrollment::query()->count());
        $this->assertSame('ActionNeeded', $application->enrollmentClearances()->whereDoesntHave('successor')->firstOrFail()->result);
    }

    public function test_handoff_never_returns_a_term_case_bound_to_a_different_application(): void
    {
        [$application, $applicant] = $this->submitted();
        $registrar = $this->registrar();
        $this->acceptCopies($application, $registrar);
        $this->outcome($application, $registrar, true);
        $this->openEnrollment($application);
        $existing = Enrollment::factory()->create(['credential_user_id' => $applicant->id, 'term_id' => $application->term_id, 'admission_application_id' => null]);
        $this->refuses(fn () => app(StartRegistrationCase::class)->forReadyApplicant($application, $application->term, $applicant), 'application');
        $this->assertSame(1, Enrollment::query()->count());
        $this->assertNull($existing->fresh()->admission_application_id);
        $availability = app(ReadyApplicantProjectionQuery::class)->enrollmentAvailability($application);
        $this->assertFalse($availability['available']);
        $this->assertSame('existing_case', $availability['status']);
    }

    private function openEnrollment(AdmissionApplication $application): void
    {
        $package = TermCalendarPackage::factory()->create(['term_id' => $application->term_id, 'state' => TermCalendarPackage::StateActive, 'activated_at' => now()]);
        TermCalendarWindow::factory()->create(['term_calendar_package_id' => $package->id, 'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => now('Asia/Manila')->subDay()->toDateString(), 'closes_on' => now('Asia/Manila')->addDay()->toDateString()]);
    }

    public function test_wrong_term_and_foreign_submission_never_produce_readiness_or_start_review(): void
    {
        [$application] = $this->submitted();
        $registrar = $this->registrar();
        $application->update(['term_id' => Term::factory()->create()->id]);
        $this->assertFalse(app(ReadyApplicantProjectionQuery::class)->forApplication($application)['ready']);
        $this->refuses(fn () => app(AdmissionReviewGuard::class)->start($application, $registrar, $application->current_submission_version_id), 'review');
        $this->assertFalse($application->reviewHasStarted());
    }

    /** @return array{AdmissionCycle, Program, AdmissionRequirementSet} */
    private function context(): array
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $program = Program::factory()->create(['is_active' => true]);
        $cycle = AdmissionCycle::factory()->published()->create(['term_id' => $term->id, 'opens_at' => now()->subDay(), 'closes_at' => now()->addDay(), 'correction_closes_at' => now()->addDays(2)]);
        $cycle->programs()->attach($program, ['accepts_first_year' => true, 'accepts_transferee' => true]);

        return [$cycle, $program, $this->requirements($cycle, AdmissionApplication::PathFirstYear)];
    }

    private function requirements(AdmissionCycle $cycle, string $path): AdmissionRequirementSet
    {
        $set = AdmissionRequirementSet::factory()->for($cycle)->create(['application_path' => $path]);
        foreach (['PSA birth certificate', 'Supporting credential'] as $label) {
            AdmissionRequirement::factory()->for($set, 'requirementSet')->create(['label' => $label, 'due_stage' => AdmissionRequirement::DuePreliminaryReview]);
        }
        $set->update(['state' => 'Published', 'effective_at' => now()->subMinute(), 'published_at' => now()]);

        return $set;
    }

    /** @return array{AdmissionApplication, User, AdmissionCycle, AdmissionRequirementSet} */
    private function submitted(): array
    {
        [$cycle, $program, $set] = $this->context();
        $applicant = $this->applicant();
        $application = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, $this->answers($program));
        foreach ($set->requirements()->get() as $requirement) {
            app(AdmissionEvidenceService::class)->store($application, $requirement, $applicant, $this->pdf($requirement->code));
        }

        return [app(SubmitAdmissionApplication::class)->execute($application, $applicant), $applicant, $cycle, $set];
    }

    /** @return array<string, mixed> */
    private function answers(Program $program): array
    {
        return ['program_id' => $program->id, 'application_path' => AdmissionApplication::PathFirstYear,
            'credential_basis' => AdmissionApplication::CredentialSeniorHighSchool, 'first_name' => 'Synthetic', 'last_name' => 'Applicant',
            'birth_date' => now()->subYears(22)->toDateString(), 'citizenship_country_code' => 'PH', 'phone' => '09477379208',
            'current_city_municipality' => 'Calamba', 'current_province' => 'Laguna', 'prior_school_name' => 'Synthetic school',
            'prior_school_country_code' => 'PH', 'prior_school_completion_year' => now()->year - 1, 'lrn_availability' => 'NotIssued',
            'privacy_acknowledged' => true, 'accuracy_declared' => true];
    }

    private function applicant(): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole('applicant');

        return $user;
    }

    private function registrar(): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole(User::StaffRoleRegistrar);

        return $user;
    }

    private function pdf(string $marker): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('synthetic.pdf', "%PDF-1.4\n{$marker}\n%%EOF");
    }

    private function acceptCopies(AdmissionApplication $application, User $registrar): void
    {
        app(AdmissionReviewGuard::class)->start($application, $registrar, $application->current_submission_version_id);
        foreach ($application->evidenceVersions()->get() as $evidence) {
            app(ReviewPreliminaryEvidence::class)->execute($evidence, $registrar, PreliminaryEvidenceReview::ResultAccepted, null);
        }
    }

    private function outcome(AdmissionApplication $application, User $registrar, bool $confirmed): AdmissionDecision
    {
        return app(RecordAdmissionOutcome::class)->execute($application, $registrar, 'Admitted', 'Synthetic checked evidence', null,
            'Contact the Registrar for the next step.', null, $application->current_submission_version_id, papersConfirmed: $confirmed);
    }

    private function refuses(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected refusal for '.$field);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
