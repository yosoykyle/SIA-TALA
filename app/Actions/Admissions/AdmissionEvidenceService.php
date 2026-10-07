<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionApplication;
use App\Models\AdmissionRequirement;
use App\Models\ApplicationCorrectionItem;
use App\Models\ApplicationCorrectionRequest;
use App\Models\ApplicationSubmissionVersion;
use App\Models\DocumentEvidence;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdmissionEvidenceService
{
    public function __construct(private readonly string $disk = 'local') {}

    public function storageIsPrivateAndAvailable(): bool
    {
        if ($this->disk === 'public' || config("filesystems.disks.{$this->disk}.visibility") === 'public') {
            return false;
        }

        $probe = '.tala-admissions-readiness/'.str()->uuid().'.txt';

        try {
            $stored = Storage::disk($this->disk)->put($probe, 'readiness-probe');
            $available = $stored && Storage::disk($this->disk)->exists($probe);
            Storage::disk($this->disk)->delete($probe);

            return $available;
        } catch (Throwable) {
            return false;
        }
    }

    public function store(
        AdmissionApplication $application,
        AdmissionRequirement $requirement,
        User $actor,
        UploadedFile $file,
        ?ApplicationSubmissionVersion $submissionVersion = null,
    ): DocumentEvidence {
        $this->authorize($application, $actor);
        $this->validateFile($file);
        $checksum = hash_file('sha256', $file->getRealPath());
        $path = null;

        try {
            return DB::transaction(function () use ($application, $requirement, $actor, $file, $submissionVersion, $checksum, &$path): DocumentEvidence {
                $locked = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);
                $this->authorize($locked, $actor);
                if ($locked->privacy_acknowledged_at === null
                    || $locked->privacy_notice_reference !== $locked->admissionCycle()->firstOrFail()->privacy_notice_reference) {
                    throw ValidationException::withMessages(['privacy_acknowledged' => 'Acknowledge the current privacy notice before uploading a private copy.']);
                }
                if (str_contains(strtolower($requirement->label), 'photo') && ! str_starts_with((string) $file->getMimeType(), 'image/')) {
                    throw ValidationException::withMessages(['evidence' => 'Upload the ID photo as a JPEG or PNG image.']);
                }
                $this->assertApplicantEvidenceScope($locked, $requirement, $actor);
                $this->assertRequirementApplies($locked, $requirement, $submissionVersion);
                if ($locked->evidenceVersions()
                    ->where('admission_requirement_id', $requirement->id)
                    ->where('checksum', $checksum)
                    ->exists()) {
                    throw ValidationException::withMessages([
                        "evidence.{$requirement->id}" => 'This exact file is already retained. Choose a different corrected copy.',
                    ]);
                }
                $directory = "admission-applications/{$locked->id}/requirements/{$requirement->id}";
                $path = Storage::disk($this->disk)->putFile($directory, $file);

                if (! is_string($path)) {
                    throw ValidationException::withMessages([
                        'evidence' => 'The private evidence file could not be stored. Try again or contact support.',
                    ]);
                }

                return DocumentEvidence::query()->create([
                    'checklist_item_id' => null,
                    'admission_application_id' => $application->id,
                    'admission_requirement_id' => $requirement->id,
                    'application_submission_version_id' => $submissionVersion?->id,
                    'disk' => $this->disk,
                    'path' => $path,
                    'checksum' => $checksum,
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'evidence_method' => 'DIGITAL_UPLOAD',
                    'status' => DocumentEvidence::StatusSubmitted,
                    'uploaded_by' => $actor->id,
                    'uploaded_at' => now(config('app.timezone')),
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'replaces_document_evidence_id' => null,
                ]);
            });
        } catch (Throwable $exception) {
            if (is_string($path)) {
                Storage::disk($this->disk)->delete($path);
            }

            throw $exception;
        }
    }

    public function replace(
        DocumentEvidence $evidence,
        User $actor,
        UploadedFile $file,
        ?ApplicationSubmissionVersion $submissionVersion = null,
    ): DocumentEvidence {
        $application = $evidence->admissionApplication()->firstOrFail();
        $requirement = $evidence->admissionRequirement()->firstOrFail();
        $replacement = $this->store($application, $requirement, $actor, $file, $submissionVersion);
        $replacement->forceFill(['replaces_document_evidence_id' => $evidence->id])->save();

        return $replacement->refresh();
    }

    public function contents(DocumentEvidence $evidence, User $actor): string
    {
        $application = $evidence->admissionApplication()->firstOrFail();
        $this->authorize($application, $actor);
        $this->assertStoredPathBelongsToEvidence($evidence, $application);

        if (! Storage::disk($evidence->disk)->exists($evidence->path)) {
            throw ValidationException::withMessages([
                'evidence' => 'The private evidence file is unavailable. Contact the Registrar.',
            ]);
        }

        activity()
            ->performedOn($evidence)
            ->causedBy($actor)
            ->event('admission_evidence_accessed')
            ->withProperties([
                'admission_application_id' => $application->id,
                'admission_requirement_id' => $evidence->admission_requirement_id,
            ])
            ->log('Private admission evidence accessed.');

        return Storage::disk($evidence->disk)->get($evidence->path);
    }

    private function assertStoredPathBelongsToEvidence(
        DocumentEvidence $evidence,
        AdmissionApplication $application,
    ): void {
        $path = str_replace('\\', '/', $evidence->path);
        $expectedPrefix = "admission-applications/{$application->id}/requirements/{$evidence->admission_requirement_id}/";

        if ($evidence->disk !== $this->disk
            || ! str_starts_with($path, $expectedPrefix)
            || str_contains($path, '../')) {
            throw ValidationException::withMessages([
                'evidence' => 'The private evidence reference is invalid. Contact the Registrar.',
            ]);
        }
    }

    public function discardTemporaryEvidence(AdmissionApplication $application, User $actor): void
    {
        $this->authorize($application, $actor);

        DB::transaction(function () use ($application, $actor): void {
            $locked = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);
            $this->authorize($locked, $actor);

            if ($locked->application_state !== AdmissionApplication::StateDraft
                || $locked->current_submission_version_id !== null
                || $locked->submissionVersions()->exists()
                || $locked->evidenceVersions()->where(function ($query): void {
                    $query->whereNotNull('application_submission_version_id')->orWhereHas('preliminaryReviews');
                })->exists()) {
                throw ValidationException::withMessages([
                    'application_state' => 'Only an unsubmitted Draft may have temporary evidence discarded.',
                ]);
            }

            $temporaryEvidence = $locked->evidenceVersions()
                ->whereNull('application_submission_version_id')
                ->whereDoesntHave('preliminaryReviews')
                ->lockForUpdate()
                ->get();

            foreach ($temporaryEvidence as $evidence) {
                $this->assertStoredPathBelongsToEvidence($evidence, $locked);
            }

            foreach ($temporaryEvidence as $evidence) {
                $evidence->delete();
            }

            DB::afterCommit(function () use ($temporaryEvidence): void {
                foreach ($temporaryEvidence as $evidence) {
                    try {
                        if (! Storage::disk($evidence->disk)->delete($evidence->path)) {
                            throw new \RuntimeException('Discarded temporary evidence could not be removed from private storage.');
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            });
        }, attempts: 3);
    }

    private function authorize(AdmissionApplication $application, User $actor): void
    {
        $ownsApplication = $application->user_id === $actor->id
            && $actor->hasRole('applicant')
            && $actor->canAuthenticate();
        $isRegistrar = $actor->hasRole(User::StaffRoleRegistrar)
            && $actor->canAuthenticate()
            && $actor->can('approve-documents');

        if (! $ownsApplication && ! $isRegistrar) {
            throw new AuthorizationException('You are not authorized to access this private admission evidence.');
        }
    }

    private function assertRequirementApplies(
        AdmissionApplication $application,
        AdmissionRequirement $requirement,
        ?ApplicationSubmissionVersion $submissionVersion,
    ): void {
        $requirementSet = $requirement->requirementSet()->firstOrFail();
        $applies = $requirementSet->admission_cycle_id === $application->admission_cycle_id
            && $requirementSet->application_path === $application->application_path
            && ($submissionVersion === null
                || ($submissionVersion->admission_application_id === $application->id
                    && $submissionVersion->admission_requirement_set_id === $requirementSet->id));

        if (! $applies) {
            throw ValidationException::withMessages([
                'admission_requirement_id' => 'The evidence requirement does not belong to this application version.',
            ]);
        }
    }

    private function assertApplicantEvidenceScope(
        AdmissionApplication $application,
        AdmissionRequirement $requirement,
        User $actor,
    ): void {
        if ($actor->hasRole(User::StaffRoleRegistrar)) {
            return;
        }

        if ($application->application_state === AdmissionApplication::StateDraft) {
            return;
        }

        $isNamedCorrection = $application->application_state === AdmissionApplication::StateActionNeeded
            && $application->correctionRequests()
                ->where('state', ApplicationCorrectionRequest::StateActive)
                ->whereHas('items', function ($query) use ($requirement): void {
                    $query->where('scope_type', ApplicationCorrectionItem::ScopeEvidence)
                        ->where('admission_requirement_id', $requirement->id)
                        ->where(function ($scopeQuery) use ($requirement): void {
                            $scopeQuery
                                ->where('scope_key', $requirement->code)
                                ->orWhere('scope_key', 'requirement:'.$requirement->id);
                        });
                })
                ->exists();

        if (! $isNamedCorrection) {
            throw ValidationException::withMessages([
                'correction_scope' => 'This evidence requirement is not open for Applicant replacement.',
            ]);
        }
    }

    private function validateFile(UploadedFile $file): void
    {
        Validator::make(
            ['evidence' => $file],
            ['evidence' => [
                'required',
                File::types(['pdf', 'jpg', 'jpeg', 'png'])->max(10 * 1024),
            ]],
        )->validate();

        $header = file_get_contents($file->getRealPath(), false, null, 0, 8);
        $matchesType = is_string($header) && match ($file->getMimeType()) {
            'application/pdf' => str_starts_with($header, '%PDF-'),
            'image/jpeg' => str_starts_with($header, "\xFF\xD8\xFF"),
            'image/png' => $header === "\x89PNG\r\n\x1A\n",
            default => false,
        };
        if (! $matchesType) {
            throw ValidationException::withMessages([
                'evidence' => 'The file contents must match a PDF, JPEG, or PNG review copy.',
            ]);
        }
    }
}
