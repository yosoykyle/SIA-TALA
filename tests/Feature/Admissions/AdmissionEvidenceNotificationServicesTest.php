<?php

namespace Tests\Feature\Admissions;

use App\Actions\Admissions\AdmissionEvidenceService;
use App\Actions\Admissions\AdmissionNotificationLedger;
use App\Actions\Admissions\ReviewPreliminaryEvidence;
use App\Models\AdmissionApplication;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationSubmissionVersion;
use App\Models\DocumentEvidence;
use App\Models\OperationalEvent;
use App\Models\PreliminaryEvidenceReview;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
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
            $this->assertSame(0, DocumentEvidence::query()->count());
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
            $this->assertSame(0, DocumentEvidence::query()->count());
        }

        $first = $service->store(
            $application,
            $requirement,
            $applicant,
            UploadedFile::fake()->createWithContent('form-138.pdf', "%PDF-1.4\nfirst-version\n%%EOF"),
            $submission,
        );
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

    /** @return array{AdmissionApplication, AdmissionRequirement, ApplicationSubmissionVersion} */
    private function applicationRequirementAndSubmission(): array
    {
        $application = AdmissionApplication::factory()->create();
        $application->user->forceFill(['status' => User::StatusActive])->save();
        $application->user->assignRole('applicant');
        $set = AdmissionRequirementSet::factory()->for($application->admissionCycle)->create([
            'application_path' => $application->application_path,
        ]);
        $requirement = AdmissionRequirement::factory()->for($set, 'requirementSet')->create([
            'requires_preliminary_evidence' => true,
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
