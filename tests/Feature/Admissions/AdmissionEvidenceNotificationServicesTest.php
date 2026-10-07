<?php

namespace Tests\Feature\Admissions;

use App\Actions\Admissions\AdmissionEvidenceService;
use App\Actions\Admissions\AdmissionNotificationLedger;
use App\Actions\Admissions\DiscardAdmissionApplication;
use App\Actions\Admissions\RecordAdmissionDecision;
use App\Actions\Admissions\ReviewPreliminaryEvidence;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationSubmissionVersion;
use App\Models\DocumentEvidence;
use App\Models\OperationalEvent;
use App\Models\PreliminaryEvidenceReview;
use App\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdmissionEvidenceNotificationServicesTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('applicant', 'web');
        Role::findOrCreate(User::StaffRoleRegistrar, 'web')->givePermissionTo(
            Permission::findOrCreate('approve-documents', 'web'),
        );
    }

    public function test_private_evidence_validates_file_content_preserves_versions_and_authorizes_reads(): void
    {
        Storage::fake('local');
        [$application, $requirement, $submission] = $this->applicationRequirementAndSubmission();
        $applicant = $application->user;
        $service = app(AdmissionEvidenceService::class);

        try {
            $service->store(
                $application,
                $requirement,
                $applicant,
                UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream'),
                $submission,
            );
            $this->fail('Unsupported evidence must not be persisted.');
        } catch (ValidationException) {
            $this->assertSame(0, $application->evidenceVersions()->count());
        }

        try {
            $service->store(
                $application,
                $requirement,
                $applicant,
                UploadedFile::fake()->create('too-large.pdf', 10241, 'application/pdf'),
                $submission,
            );
            $this->fail('Evidence larger than 10 MiB must not be persisted.');
        } catch (ValidationException) {
            $this->assertSame(0, $application->evidenceVersions()->count());
        }

        $first = $service->store(
            $application,
            $requirement,
            $applicant,
            UploadedFile::fake()->createWithContent('form-138.pdf', "%PDF-1.4\nfirst-version\n%%EOF"),
            $submission,
        );
        try {
            $service->replace($first, $applicant,
                UploadedFile::fake()->createWithContent('same-copy.pdf', "%PDF-1.4\nfirst-version\n%%EOF"), $submission);
            $this->fail('An identical retained file must produce a useful validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey("evidence.{$requirement->id}", $exception->errors());
            $this->assertSame(1, $application->evidenceVersions()->count());
            $this->assertCount(1, Storage::disk('local')->allFiles());
        }
        $replacement = $service->replace(
            $first,
            $applicant,
            UploadedFile::fake()->createWithContent('form-138-corrected.pdf', "%PDF-1.4\ncorrected-version\n%%EOF"),
            $submission,
        );

        Storage::disk('local')->assertExists($first->path);
        Storage::disk('local')->assertExists($replacement->path);
        $this->assertSame($first->id, $replacement->replaces_document_evidence_id);
        $this->assertNotSame($first->checksum, $replacement->checksum);
        $this->assertNotSame('', $service->contents($replacement, $applicant));

        $application->admissionCycle->forceFill([
            'closes_at' => now()->subDays(2),
            'correction_closes_at' => now()->subDay(),
        ])->save();

        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $underReview = app(ReviewPreliminaryEvidence::class)->execute(
            $replacement,
            $registrar,
            PreliminaryEvidenceReview::ResultUnderReview,
            null,
        );
        $accepted = app(ReviewPreliminaryEvidence::class)->execute(
            $replacement,
            $registrar,
            PreliminaryEvidenceReview::ResultAccepted,
            'The private review copy is legible and complete.',
            $underReview->id,
        );
        $this->assertSame($underReview->id, $accepted->supersedes_preliminary_evidence_review_id);
        $this->assertSame(2, $replacement->preliminaryReviews()->count());

        $outsider = User::factory()->create(['status' => User::StatusActive]);
        $outsider->assignRole('applicant');

        $this->expectException(AuthorizationException::class);
        $service->contents($replacement, $outsider);
    }

    public function test_private_evidence_path_tampering_is_rejected(): void
    {
        Storage::fake('local');
        [$application, $requirement, $submission] = $this->applicationRequirementAndSubmission();
        $evidence = app(AdmissionEvidenceService::class)->store(
            $application,
            $requirement,
            $application->user,
            UploadedFile::fake()->createWithContent('form-138.pdf', "%PDF-1.4\nprivate\n%%EOF"),
            $submission,
        );
        $evidence->forceFill(['path' => '../outside-private-boundary.pdf'])->save();

        $this->expectException(ValidationException::class);
        app(AdmissionEvidenceService::class)->contents($evidence->fresh(), $application->user);
    }

    public function test_notification_ledger_is_idempotent_and_supports_one_claim_per_authorized_retry(): void
    {
        [$application] = $this->applicationRequirementAndSubmission();
        $recipient = $application->user;
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $ledger = app(AdmissionNotificationLedger::class);

        $first = $ledger->recordPending(
            $application,
            $recipient,
            eventType: 'admission_application_submitted',
            sourceKey: 'submission:'.$application->current_submission_version_id,
            safePayload: ['application_reference' => $application->application_reference],
        );
        $duplicate = $ledger->recordPending(
            $application,
            $recipient,
            eventType: 'admission_application_submitted',
            sourceKey: 'submission:'.$application->current_submission_version_id,
            safePayload: ['application_reference' => $application->application_reference],
        );

        $this->assertTrue($first->is($duplicate));
        $this->assertTrue($ledger->claimForDispatch($first));
        $this->assertFalse($ledger->claimForDispatch($first->fresh()));

        $failed = $ledger->markFailed($first->fresh(), 'Synthetic queue failure.');
        $retried = $ledger->authorizeRetry($failed, $registrar);

        $this->assertSame(OperationalEvent::StatusPending, $retried->status);
        $this->assertTrue($ledger->claimForDispatch($retried));
        $this->assertFalse($ledger->claimForDispatch($retried->fresh()));
    }

    public function test_older_accepted_evidence_cannot_substitute_for_the_current_replacement(): void
    {
        [$application, $requirement, $submission] = $this->applicationRequirementAndSubmission(preDecision: true);
        $application->forceFill(['application_state' => AdmissionApplication::StateSubmitted])->save();
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $old = DocumentEvidence::factory()->canonical($application, $requirement, $submission)->create();
        app(ReviewPreliminaryEvidence::class)->execute($old, $registrar, PreliminaryEvidenceReview::ResultAccepted, null);
        $replacement = DocumentEvidence::factory()->canonical($application, $requirement, $submission)->create([
            'replaces_document_evidence_id' => $old->id,
        ]);

        foreach ([null, PreliminaryEvidenceReview::ResultActionNeeded] as $result) {
            if ($result !== null) {
                $review = app(ReviewPreliminaryEvidence::class)->execute($replacement, $registrar, $result, 'Replace the unreadable page.');
            }

            try {
                app(RecordAdmissionDecision::class)->execute($application->fresh(), $registrar,
                    AdmissionDecision::DecisionAdmitted, 'Reviewed', 'Registrar authority', 'Admission complete');
                $this->fail('The latest evidence needs its own current accepted review.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('preliminary_evidence', $exception->errors());
                $this->assertSame(0, $application->decisions()->count());
                $this->assertSame(AdmissionApplication::StateSubmitted, $application->fresh()->application_state);
            }
        }

        app(ReviewPreliminaryEvidence::class)->execute($replacement, $registrar,
            PreliminaryEvidenceReview::ResultAccepted, null, $review->id);
        $decision = app(RecordAdmissionDecision::class)->execute($application->fresh(), $registrar,
            AdmissionDecision::DecisionAdmitted, 'Reviewed', 'Registrar authority', 'Admission complete');
        $this->assertSame(AdmissionDecision::DecisionAdmitted, $decision->decision);
        $this->assertModelExists($old);
    }

    public function test_stale_draft_upload_rechecks_current_application_state_before_creating_a_private_file(): void
    {
        Storage::fake('local');
        [$application, $requirement] = $this->applicationRequirementAndSubmission();
        $staleDraft = clone $application;
        $application->forceFill(['application_state' => AdmissionApplication::StateSubmitted])->save();
        $before = $application->evidenceVersions()->count();

        try {
            app(AdmissionEvidenceService::class)->store($staleDraft, $requirement, $application->user,
                UploadedFile::fake()->createWithContent('stale.pdf', "%PDF-1.4\nprivate\n%%EOF"));
            $this->fail('A stale Draft must not reopen Submitted evidence for an Applicant upload.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('correction_scope', $exception->errors());
            $this->assertSame($before, $application->evidenceVersions()->count());
            $this->assertSame([], Storage::disk('local')->allFiles('admission-applications/'.$application->id));
        }
    }

    public function test_stale_draft_discard_and_direct_cleanup_preserve_concurrently_submitted_evidence(): void
    {
        Storage::fake('local');
        [$application, $requirement] = $this->applicationRequirementAndSubmission();
        $staleDraft = clone $application;
        $staleDraft->forceFill(['application_state' => AdmissionApplication::StateDraft, 'current_submission_version_id' => null]);
        $application->forceFill(['application_state' => AdmissionApplication::StateSubmitted])->save();
        $path = "admission-applications/{$application->id}/requirements/{$requirement->id}/protected.pdf";
        $evidence = DocumentEvidence::factory()->canonical($application, $requirement)->create(['path' => $path]);
        Storage::disk('local')->put($path, 'protected submitted content');

        foreach ([
            fn () => app(DiscardAdmissionApplication::class)->execute($staleDraft, $application->user),
            fn () => app(AdmissionEvidenceService::class)->discardTemporaryEvidence($staleDraft, $application->user),
        ] as $discard) {
            try {
                $discard();
                $this->fail('A stale Draft must not delete submitted records or files.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('application_state', $exception->errors());
                $this->assertModelExists($application);
                $this->assertModelExists($evidence);
                Storage::disk('local')->assertExists($path);
            }
        }
    }

    public function test_temporary_evidence_files_survive_failure_after_database_cleanup_and_delete_after_commit(): void
    {
        Storage::fake('local');
        $draft = AdmissionApplication::factory()->create();
        $draft->user->forceFill(['status' => User::StatusActive])->save();
        $draft->user->assignRole('applicant');
        $set = AdmissionRequirementSet::factory()->for($draft->admissionCycle)->create([
            'application_path' => $draft->application_path,
        ]);
        $requirement = AdmissionRequirement::factory()->for($set, 'requirementSet')->create();
        $paths = [];
        foreach (['first.pdf', 'second.pdf'] as $name) {
            $path = "admission-applications/{$draft->id}/requirements/{$requirement->id}/{$name}";
            DocumentEvidence::factory()->canonical($draft, $requirement)->create(['path' => $path]);
            Storage::disk('local')->put($path, 'private draft content');
            $paths[] = $path;
        }

        try {
            DB::transaction(function () use ($draft): void {
                app(AdmissionEvidenceService::class)->discardTemporaryEvidence($draft, $draft->user);
                $this->assertSame(0, $draft->evidenceVersions()->count());
                throw new \RuntimeException('Failure after cleanup before enclosing commit.');
            });
            $this->fail('The enclosing failure must roll back the database cleanup.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Failure after cleanup before enclosing commit.', $exception->getMessage());
        }

        $this->assertSame(2, $draft->evidenceVersions()->count());
        foreach ($paths as $path) {
            Storage::disk('local')->assertExists($path);
        }
        app(DiscardAdmissionApplication::class)->execute($draft->fresh(), $draft->user);
        $this->assertModelMissing($draft);
        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
    }

    public function test_misleading_mime_type_and_missing_privacy_acknowledgment_do_not_store_files(): void
    {
        Storage::fake('local');
        [$application, $requirement, $submission] = $this->applicationRequirementAndSubmission();
        $service = app(AdmissionEvidenceService::class);
        try {
            $service->store($application, $requirement, $application->user,
                UploadedFile::fake()->create('misleading.pdf', 5, 'application/pdf'), $submission);
            $this->fail('A reported PDF MIME type cannot replace a matching file header.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evidence', $exception->errors());
        }
        $application->update(['privacy_acknowledged_at' => null]);
        try {
            $service->store($application, $requirement, $application->user,
                UploadedFile::fake()->createWithContent('review.pdf', "%PDF-1.4\nsynthetic\n%%EOF"), $submission);
            $this->fail('The current privacy notice must be acknowledged before private storage.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('privacy_acknowledged', $exception->errors());
        }
        $this->assertSame(0, $application->evidenceVersions()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /** @return array{AdmissionApplication, AdmissionRequirement, ApplicationSubmissionVersion} */
    private function applicationRequirementAndSubmission(bool $preDecision = false): array
    {
        $application = AdmissionApplication::factory()->create([
            'admission_cycle_id' => AdmissionCycle::factory()->create([
                'term_id' => Term::query()->value('id') ?? Term::factory()->create()->id,
            ])->id,
        ]);
        $application->user->forceFill(['status' => User::StatusActive])->save();
        $application->user->assignRole('applicant');
        $set = AdmissionRequirementSet::factory()->for($application->admissionCycle)->create([
            'application_path' => $application->application_path,
        ]);
        $requirement = AdmissionRequirement::factory()->for($set, 'requirementSet')->create([
            'requires_preliminary_evidence' => true,
            'due_stage' => $preDecision ? AdmissionRequirement::DuePreliminaryReview : AdmissionRequirement::DueEnrollmentReadiness,
        ]);
        $set->update([
            'state' => AdmissionRequirementSet::StatePublished,
            'effective_at' => now()->subMinute(),
            'published_at' => now()->subMinute(),
        ]);
        $submission = ApplicationSubmissionVersion::factory()
            ->for($application, 'application')
            ->for($set, 'requirementSet')
            ->create();
        $application->forceFill(['current_submission_version_id' => $submission->id])->save();

        return [$application->refresh(), $requirement, $submission];
    }
}
