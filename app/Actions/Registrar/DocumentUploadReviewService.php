<?php

namespace App\Actions\Registrar;

use App\Actions\Applicants\RetentionDocumentUndertakingService;
use App\Models\ApplicantDocumentRequirement;
use App\Models\DocumentUpload;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentUploadReviewService
{
    public function __construct(
        private RetentionDocumentUndertakingService $retentionDocumentUndertakings,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function approve(DocumentUpload $documentUpload, User $registrar): DocumentUpload
    {
        return $this->transition(
            documentUpload: $documentUpload,
            registrar: $registrar,
            status: DocumentUpload::ReviewStatusRegistrarApproved,
            event: 'document_upload_approved',
        );
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function needsCorrection(DocumentUpload $documentUpload, User $registrar, string $reason): DocumentUpload
    {
        return $this->transition(
            documentUpload: $documentUpload,
            registrar: $registrar,
            status: DocumentUpload::ReviewStatusNeedsCorrection,
            event: 'document_upload_needs_correction',
            reason: $reason,
        );
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function reject(DocumentUpload $documentUpload, User $registrar, string $reason): DocumentUpload
    {
        return $this->transition(
            documentUpload: $documentUpload,
            registrar: $registrar,
            status: DocumentUpload::ReviewStatusRejected,
            event: 'document_upload_rejected',
            reason: $reason,
        );
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function transition(
        DocumentUpload $documentUpload,
        User $registrar,
        string $status,
        string $event,
        ?string $reason = null,
    ): DocumentUpload {
        $this->authorize($registrar);

        $normalizedReason = $this->normalizeReason($reason, $status);

        return DB::transaction(function () use ($documentUpload, $registrar, $status, $event, $normalizedReason): DocumentUpload {
            $locked = DocumentUpload::query()
                ->lockForUpdate()
                ->findOrFail($documentUpload->id);

            if (! $locked->isRegistrarReviewable()) {
                throw ValidationException::withMessages([
                    'ocr_review_status' => 'Only active Registrar review documents can transition.',
                ]);
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));

            $locked->forceFill([
                'ocr_review_status' => $status,
                'registrar_reviewed_by' => $registrar->id,
                'registrar_reviewed_at' => $timestamp,
                'registrar_approved_payload' => $status === DocumentUpload::ReviewStatusRegistrarApproved
                    ? ($locked->student_confirmed_payload ?? [])
                    : $locked->registrar_approved_payload,
            ])->save();

            $this->syncApplicantRequirementState($locked, $registrar, $status, $timestamp);

            $this->recordActivity($locked, $registrar, $event, $status, $normalizedReason, $timestamp);

            return $locked->refresh();
        });
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $registrar): void
    {
        if (! $registrar->can('approve-documents')) {
            throw new AuthorizationException;
        }
    }

    /**
     * @throws ValidationException
     */
    private function normalizeReason(?string $reason, string $status): ?string
    {
        if ($status === DocumentUpload::ReviewStatusRegistrarApproved) {
            return null;
        }

        $normalized = trim((string) $reason);

        if ($normalized === '') {
            throw ValidationException::withMessages([
                'reason' => 'A review reason is required for this document transition.',
            ]);
        }

        return $normalized;
    }

    private function syncApplicantRequirementState(
        DocumentUpload $documentUpload,
        User $registrar,
        string $status,
        CarbonImmutable $timestamp,
    ): void {
        $requirement = $documentUpload->applicantDocumentRequirement;

        if (! $requirement instanceof ApplicantDocumentRequirement && $documentUpload->applicant_intake_id !== null) {
            $requirement = ApplicantDocumentRequirement::query()
                ->where('applicant_intake_id', $documentUpload->applicant_intake_id)
                ->where('item_key', $documentUpload->document_type)
                ->first();
        }

        if (! $requirement instanceof ApplicantDocumentRequirement) {
            return;
        }

        $state = match ($status) {
            DocumentUpload::ReviewStatusRegistrarApproved => ApplicantDocumentRequirement::EvidenceStateSatisfied,
            DocumentUpload::ReviewStatusNeedsCorrection => ApplicantDocumentRequirement::EvidenceStateNeedsCorrection,
            DocumentUpload::ReviewStatusRejected => ApplicantDocumentRequirement::EvidenceStateRejected,
            default => ApplicantDocumentRequirement::EvidenceStateSubmitted,
        };

        $requirement->forceFill([
            'evidence_state' => $state,
            'satisfied_by_document_upload_id' => $status === DocumentUpload::ReviewStatusRegistrarApproved
                ? $documentUpload->id
                : $requirement->satisfied_by_document_upload_id,
            'satisfied_method' => $status === DocumentUpload::ReviewStatusRegistrarApproved
                ? 'registrar_approved_upload'
                : $requirement->satisfied_method,
            'satisfied_by' => $status === DocumentUpload::ReviewStatusRegistrarApproved
                ? $registrar->id
                : $requirement->satisfied_by,
            'satisfied_at' => $status === DocumentUpload::ReviewStatusRegistrarApproved
                ? $timestamp
                : $requirement->satisfied_at,
        ])->save();

        if ($status === DocumentUpload::ReviewStatusRegistrarApproved) {
            $this->retentionDocumentUndertakings->resolveForRequirement(
                requirement: $requirement,
                documentUpload: $documentUpload,
                registrar: $registrar,
                resolvedAt: $timestamp,
            );
        }
    }

    private function recordActivity(
        DocumentUpload $documentUpload,
        User $registrar,
        string $event,
        string $statusAfter,
        ?string $reason,
        CarbonImmutable $timestamp,
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'document_review',
            'description' => 'Registrar document review transition.',
            'subject_type' => DocumentUpload::class,
            'subject_id' => $documentUpload->id,
            'event' => $event,
            'causer_type' => User::class,
            'causer_id' => $registrar->id,
            'properties' => json_encode([
                'status_after' => $statusAfter,
                'reason' => $reason,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }
}
