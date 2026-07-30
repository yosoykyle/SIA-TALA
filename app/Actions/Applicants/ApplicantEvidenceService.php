<?php

namespace App\Actions\Applicants;

use App\Models\ApplicantIntake;
use App\Models\ChecklistItem;
use App\Models\DocumentEvidence;
use App\Models\OutputAccessLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApplicantEvidenceService
{
    public const DecisionAccept = 'accept';

    public const DecisionReject = 'reject';

    public function __construct(
        private readonly ApplicantStatusNotificationService $statusNotifications,
    ) {}

    /**
     * @param  self::DecisionAccept|self::DecisionReject  $decision
     */
    public function review(
        ChecklistItem $checklistItem,
        User $actor,
        string $decision,
        ?string $reason = null,
    ): ChecklistItem {
        $this->authorizeRegistrar($actor);

        if (! in_array($decision, [self::DecisionAccept, self::DecisionReject], true)) {
            throw ValidationException::withMessages(['decision' => 'Select a supported evidence decision.']);
        }

        if ($decision === self::DecisionReject && blank($reason)) {
            throw ValidationException::withMessages(['notes' => 'Explain what the applicant must correct.']);
        }

        return DB::transaction(function () use ($checklistItem, $actor, $decision, $reason): ChecklistItem {
            $item = ChecklistItem::query()->lockForUpdate()->findOrFail($checklistItem->id);

            if ($item->isResolved() || $item->verification_status === ChecklistItem::VerificationRejected) {
                throw ValidationException::withMessages([
                    'status' => 'This requirement was already reviewed. Refresh before taking another action.',
                ]);
            }

            $evidence = $item->documentEvidence()->latest('uploaded_at')->latest('id')->lockForUpdate()->first();

            if ($item->evidence_method === ChecklistItem::EvidenceMethodPhysicalCopy
                && $item->status !== ChecklistItem::StatusReceivedPhysical) {
                throw ValidationException::withMessages([
                    'evidence' => 'Record the physical requirement as received before verifying or rejecting it.',
                ]);
            }

            if ($item->evidence_method === ChecklistItem::EvidenceMethodDigitalUpload) {
                if (! $evidence instanceof DocumentEvidence) {
                    throw ValidationException::withMessages(['evidence' => 'No uploaded evidence is available for review.']);
                }

                if ($evidence->status !== DocumentEvidence::StatusSubmitted) {
                    throw ValidationException::withMessages([
                        'evidence' => 'This evidence version was already reviewed. Refresh before taking another action.',
                    ]);
                }
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $accepted = $decision === self::DecisionAccept;
            $item->forceFill([
                'status' => $accepted ? ChecklistItem::StatusAccepted : ChecklistItem::StatusRejected,
                'verification_status' => $accepted ? ChecklistItem::VerificationVerified : ChecklistItem::VerificationRejected,
                'reviewed_by' => $actor->id,
                'reviewed_at' => $timestamp,
                'waiver_reason' => $accepted ? null : trim((string) $reason),
            ])->save();

            if ($evidence instanceof DocumentEvidence) {
                $evidence->forceFill([
                    'status' => $accepted ? DocumentEvidence::StatusAccepted : DocumentEvidence::StatusRejected,
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => $timestamp,
                ])->save();
            }

            $statusChangedIntake = $this->synchronizeApplicantAfterReview($item, $actor, $accepted, $timestamp);
            $this->recordActivity(
                intakeId: $item->applicant_intake_id,
                subjectId: $item->id,
                actor: $actor,
                event: $accepted ? 'applicant_evidence_accepted' : 'applicant_evidence_rejected',
                properties: ['reason' => $accepted ? null : trim((string) $reason)],
                timestamp: $timestamp,
            );

            if ($statusChangedIntake instanceof ApplicantIntake) {
                $this->statusNotifications->record($statusChangedIntake);
            }

            return $item->refresh()->load(['documentEvidence', 'applicantIntake.user']);
        }, attempts: 3);
    }

    public function waive(ChecklistItem $checklistItem, User $actor, string $reason): ChecklistItem
    {
        return $this->recordAuthorityResolution(
            checklistItem: $checklistItem,
            actor: $actor,
            status: ChecklistItem::StatusWaived,
            detail: $reason,
            detailField: 'waiver_reason',
            event: 'applicant_requirement_waived',
        );
    }

    public function approveUndertaking(ChecklistItem $checklistItem, User $actor, string $terms): ChecklistItem
    {
        return $this->recordAuthorityResolution(
            checklistItem: $checklistItem,
            actor: $actor,
            status: ChecklistItem::StatusUndertakingApproved,
            detail: $terms,
            detailField: 'undertaking_terms',
            event: 'applicant_undertaking_approved',
        );
    }

    public function recordPhysicalReceipt(
        ChecklistItem $checklistItem,
        User $actor,
        ?string $reference = null,
    ): ChecklistItem {
        $this->authorizeRegistrar($actor);
        $reference = filled($reference) ? trim((string) $reference) : null;

        if ($reference !== null && mb_strlen($reference) > 120) {
            throw ValidationException::withMessages([
                'receipt_reference' => 'The physical receipt reference may not exceed 120 characters.',
            ]);
        }

        return DB::transaction(function () use ($checklistItem, $actor, $reference): ChecklistItem {
            $item = ChecklistItem::query()->lockForUpdate()->findOrFail($checklistItem->id);

            if ($item->evidence_method !== ChecklistItem::EvidenceMethodPhysicalCopy) {
                throw ValidationException::withMessages([
                    'evidence' => 'Only a physical-copy requirement can be recorded as physically received.',
                ]);
            }

            if ($item->isResolved() || $item->status === ChecklistItem::StatusReceivedPhysical) {
                throw ValidationException::withMessages([
                    'status' => 'This physical requirement was already received or resolved. Refresh before taking another action.',
                ]);
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $item->forceFill([
                'status' => ChecklistItem::StatusReceivedPhysical,
                'verification_status' => ChecklistItem::VerificationNotReviewed,
                'reviewed_by' => $actor->id,
                'reviewed_at' => $timestamp,
                'waiver_reason' => null,
            ])->save();

            if ($item->owner_type === ChecklistItem::OwnerApplicant && $item->applicant_intake_id !== null) {
                $intake = ApplicantIntake::query()->lockForUpdate()->findOrFail($item->applicant_intake_id);

                if (! in_array($intake->status, [
                    ApplicantIntake::StatusPending,
                    ApplicantIntake::StatusActionRequired,
                    ApplicantIntake::StatusForEvaluation,
                ], true) || $intake->handed_over_at !== null) {
                    throw ValidationException::withMessages([
                        'status' => 'This application is no longer open for physical evidence receipt.',
                    ]);
                }

                if ($intake->status === ApplicantIntake::StatusActionRequired
                    && ! $intake->checklistItems()->whereKeyNot($item->id)->where('status', ChecklistItem::StatusRejected)->exists()) {
                    $intake->forceFill(['status' => ApplicantIntake::StatusPending])->save();
                    $intake->user()->lockForUpdate()->firstOrFail()->forceFill([
                        'status' => User::StatusApplicantPending,
                    ])->save();
                }
            }

            $this->recordActivity(
                intakeId: $item->applicant_intake_id,
                subjectId: $item->id,
                actor: $actor,
                event: 'applicant_physical_evidence_received',
                properties: ['receipt_reference' => $reference],
                timestamp: $timestamp,
            );

            return $item->refresh()->load(['applicantIntake.user']);
        }, attempts: 3);
    }

    public function replace(
        ApplicantIntake $intake,
        ChecklistItem $checklistItem,
        User $applicant,
        string $path,
    ): DocumentEvidence {
        if ($intake->user_id !== $applicant->id
            || ! $applicant->hasRole('applicant')
            || ! $applicant->canAuthenticate()) {
            throw new AuthorizationException('Applicants may replace only their own rejected evidence.');
        }

        if (! $this->pathIsInside($path, "applicant-evidence-replacements/{$applicant->id}")) {
            throw ValidationException::withMessages([
                'replacement_file' => 'The selected replacement path is not permitted. Upload the file again.',
            ]);
        }

        return DB::transaction(function () use ($intake, $checklistItem, $applicant, $path): DocumentEvidence {
            $lockedIntake = ApplicantIntake::query()->lockForUpdate()->findOrFail($intake->id);
            $item = ChecklistItem::query()->lockForUpdate()->findOrFail($checklistItem->id);

            if ($lockedIntake->user_id !== $applicant->id) {
                throw new AuthorizationException('Applicants may replace only their own rejected evidence.');
            }

            if ($item->applicant_intake_id !== $lockedIntake->id
                || $lockedIntake->status !== ApplicantIntake::StatusActionRequired
                || $item->status !== ChecklistItem::StatusRejected
                || $item->evidence_method !== ChecklistItem::EvidenceMethodDigitalUpload) {
                throw ValidationException::withMessages([
                    'requirement_id' => 'Only a rejected digital requirement on your action-required application can be replaced.',
                ]);
            }

            $disk = Storage::disk('local');

            if (! $disk->exists($path)) {
                throw ValidationException::withMessages(['replacement_file' => 'The replacement file is unavailable.']);
            }

            $mimeType = $disk->mimeType($path) ?: 'application/octet-stream';
            $size = $disk->size($path);

            if (! in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png'], true) || $size > 5 * 1024 * 1024) {
                $disk->delete($path);

                throw ValidationException::withMessages([
                    'replacement_file' => 'Upload a PDF, JPG, or PNG file no larger than 5 MB.',
                ]);
            }

            $checksum = hash_file('sha256', $disk->path($path));
            $previous = DocumentEvidence::query()
                ->where('checklist_item_id', $item->id)
                ->latest('uploaded_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! is_string($checksum) || ($previous instanceof DocumentEvidence && hash_equals((string) $previous->checksum, $checksum))) {
                $disk->delete($path);

                throw ValidationException::withMessages([
                    'replacement_file' => 'Upload a corrected file that differs from the rejected version.',
                ]);
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $replacement = DocumentEvidence::query()->create([
                'checklist_item_id' => $item->id,
                'disk' => 'local',
                'path' => $path,
                'checksum' => $checksum,
                'mime_type' => $mimeType,
                'size_bytes' => $size,
                'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
                'status' => DocumentEvidence::StatusSubmitted,
                'uploaded_by' => $applicant->id,
                'uploaded_at' => $timestamp,
                'replaces_document_evidence_id' => $previous?->id,
            ]);

            $item->forceFill([
                'status' => ChecklistItem::StatusReceivedDigital,
                'verification_status' => ChecklistItem::VerificationNotReviewed,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ])->save();

            if ($item->requirement_type === 'IDENTITY_DOCUMENT') {
                $lockedIntake->identity_evidence_reference = $path;
            }

            $documentReferences = (array) ($lockedIntake->getAttribute('draft_document_references') ?? []);
            $documentReferences[(int) $item->source_policy_id] = $path;
            $lockedIntake->setAttribute('draft_document_references', $documentReferences);

            $hasOtherRejection = $lockedIntake->checklistItems()
                ->whereKeyNot($item->id)
                ->where('status', ChecklistItem::StatusRejected)
                ->exists();

            if (! $hasOtherRejection) {
                $lockedIntake->status = ApplicantIntake::StatusPending;
                $applicant->forceFill(['status' => User::StatusApplicantPending])->save();
            }

            $lockedIntake->save();
            $this->recordActivity(
                intakeId: $lockedIntake->id,
                subjectId: $item->id,
                actor: $applicant,
                event: 'applicant_evidence_replaced',
                properties: [
                    'previous_evidence_id' => $previous?->id,
                    'replacement_evidence_id' => $replacement->id,
                ],
                timestamp: $timestamp,
            );

            return $replacement->refresh();
        }, attempts: 3);
    }

    private function pathIsInside(string $path, string $directory): bool
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedDirectory = trim(str_replace('\\', '/', $directory), '/').'/';

        return ! str_contains($normalizedPath, '../')
            && str_starts_with($normalizedPath, $normalizedDirectory)
            && strlen($normalizedPath) > strlen($normalizedDirectory);
    }

    public function downloadIdentityEvidence(ApplicantIntake $intake, User $actor): StreamedResponse
    {
        $this->authorizeRegistrar($actor);
        $path = (string) $intake->identity_evidence_reference;
        $disk = Storage::disk('local');

        if (blank($path) || ! $disk->exists($path)) {
            throw ValidationException::withMessages(['evidence' => 'The private identity evidence file is unavailable.']);
        }

        OutputAccessLog::query()->create([
            'output_type' => 'ADMISSION_EVIDENCE',
            'source_record_type' => ApplicantIntake::class,
            'source_record_id' => $intake->id,
            'actor_user_id' => $actor->id,
            'actor_role' => $actor->getRoleNames()->first(),
            'action' => 'DOWNLOAD',
            'purpose' => 'Registrar admissions evidence review',
            'sensitivity' => 'RESTRICTED',
            'stored_file_reference' => $path,
            'request_context' => [
                'route' => request()->route()?->getName(),
                'ip' => request()->ip(),
            ],
            'status' => 'SUCCESS',
            'occurred_at' => now(config('app.timezone')),
        ]);

        return $disk->download($path, basename($path));
    }

    public function downloadChecklistEvidence(ChecklistItem $checklistItem, User $actor): StreamedResponse
    {
        $this->authorizeRegistrar($actor);
        $evidence = $checklistItem->documentEvidence()->latest('uploaded_at')->latest('id')->first();

        if (! $evidence instanceof DocumentEvidence) {
            throw ValidationException::withMessages(['evidence' => 'No digital evidence is available for this requirement.']);
        }

        $disk = Storage::disk($evidence->disk);

        if (! $disk->exists($evidence->path)) {
            throw ValidationException::withMessages(['evidence' => 'The private evidence file is unavailable.']);
        }

        OutputAccessLog::query()->create([
            'output_type' => 'ADMISSION_EVIDENCE',
            'source_record_type' => ChecklistItem::class,
            'source_record_id' => $checklistItem->id,
            'student_profile_id' => $checklistItem->student_profile_id,
            'actor_user_id' => $actor->id,
            'actor_role' => $actor->getRoleNames()->first(),
            'action' => 'DOWNLOAD',
            'purpose' => 'Registrar checklist evidence review',
            'sensitivity' => 'RESTRICTED',
            'stored_file_reference' => $evidence->path,
            'request_context' => [
                'route' => request()->route()?->getName(),
                'ip' => request()->ip(),
            ],
            'status' => 'SUCCESS',
            'occurred_at' => now(config('app.timezone')),
        ]);

        return $disk->download($evidence->path, basename($evidence->path));
    }

    private function authorizeRegistrar(User $actor): void
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)
            || ! $actor->can('approve-documents')
            || ! $actor->canAuthenticate()) {
            throw new AuthorizationException('Only Registrar staff with document-approval permission may review private evidence.');
        }
    }

    /**
     * @param  ChecklistItem::StatusWaived|ChecklistItem::StatusUndertakingApproved  $status
     * @param  'waiver_reason'|'undertaking_terms'  $detailField
     */
    private function recordAuthorityResolution(
        ChecklistItem $checklistItem,
        User $actor,
        string $status,
        string $detail,
        string $detailField,
        string $event,
    ): ChecklistItem {
        $this->authorizeRegistrar($actor);

        $trimmedDetail = trim($detail);

        if ($trimmedDetail === '') {
            $message = $detailField === 'waiver_reason'
                ? 'Explain why the Registrar is waiving this requirement.'
                : 'Record the approved undertaking terms.';

            throw ValidationException::withMessages([$detailField => $message]);
        }

        if (mb_strlen($trimmedDetail) > 1000) {
            throw ValidationException::withMessages([
                $detailField => 'Keep the recorded reason or terms to 1,000 characters or fewer.',
            ]);
        }

        return DB::transaction(function () use (
            $checklistItem,
            $actor,
            $status,
            $trimmedDetail,
            $detailField,
            $event,
        ): ChecklistItem {
            $item = ChecklistItem::query()->lockForUpdate()->findOrFail($checklistItem->id);

            if ($item->isResolved()) {
                throw ValidationException::withMessages([
                    'status' => 'This requirement is already resolved. Refresh before taking another action.',
                ]);
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $item->forceFill([
                'status' => $status,
                'reviewed_by' => $actor->id,
                'reviewed_at' => $timestamp,
                'waiver_reason' => $detailField === 'waiver_reason' ? $trimmedDetail : null,
                'undertaking_terms' => $detailField === 'undertaking_terms' ? $trimmedDetail : null,
            ])->save();

            $statusChangedIntake = $this->synchronizeApplicantAfterReview(
                $item,
                $actor,
                accepted: true,
                timestamp: $timestamp,
            );
            $this->recordActivity(
                intakeId: $item->applicant_intake_id,
                subjectId: $item->id,
                actor: $actor,
                event: $event,
                properties: [$detailField => $trimmedDetail],
                timestamp: $timestamp,
            );

            if ($statusChangedIntake instanceof ApplicantIntake) {
                $this->statusNotifications->record($statusChangedIntake);
            }

            return $item->refresh()->load(['documentEvidence', 'applicantIntake.user']);
        }, attempts: 3);
    }

    private function synchronizeApplicantAfterReview(
        ChecklistItem $item,
        User $actor,
        bool $accepted,
        CarbonImmutable $timestamp,
    ): ?ApplicantIntake {
        if ($item->owner_type !== ChecklistItem::OwnerApplicant || $item->applicant_intake_id === null) {
            return null;
        }

        $intake = ApplicantIntake::query()->lockForUpdate()->findOrFail($item->applicant_intake_id);
        $previousStatus = $intake->status;

        if (! in_array($intake->status, [
            ApplicantIntake::StatusPending,
            ApplicantIntake::StatusActionRequired,
            ApplicantIntake::StatusForEvaluation,
        ], true) || $intake->handed_over_at !== null) {
            throw ValidationException::withMessages([
                'status' => 'This application is no longer open for evidence review. Refresh before taking another action.',
            ]);
        }

        $status = $accepted ? $intake->status : ApplicantIntake::StatusActionRequired;

        if ($accepted
            && $intake->status === ApplicantIntake::StatusActionRequired
            && ! $intake->checklistItems()->whereKeyNot($item->id)->where('status', ChecklistItem::StatusRejected)->exists()) {
            $status = ApplicantIntake::StatusPending;
        }

        $intake->forceFill([
            'status' => $status,
            'reviewed_by' => $actor->id,
            'reviewed_at' => $status === ApplicantIntake::StatusActionRequired ? $timestamp : $intake->reviewed_at,
        ])->save();
        $intake->user()->lockForUpdate()->firstOrFail()->forceFill([
            'status' => match ($status) {
                ApplicantIntake::StatusActionRequired => User::StatusApplicantActionRequired,
                ApplicantIntake::StatusForEvaluation => User::StatusApplicantForEvaluation,
                default => User::StatusApplicantPending,
            },
        ])->save();

        if ($previousStatus === $status) {
            return null;
        }

        return $intake->refresh()->load('user');
    }

    /** @param array<string, mixed> $properties */
    private function recordActivity(
        ?int $intakeId,
        int $subjectId,
        User $actor,
        string $event,
        array $properties,
        CarbonImmutable $timestamp,
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'applicant_evidence',
            'description' => 'Applicant evidence workflow transition.',
            'subject_type' => ChecklistItem::class,
            'subject_id' => $subjectId,
            'event' => $event,
            'causer_type' => User::class,
            'causer_id' => $actor->id,
            'properties' => json_encode([
                'applicant_intake_id' => $intakeId,
                ...$properties,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }
}
