<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionApplication;
use App\Models\AdmissionApplicationEvent;
use App\Models\AdmissionDecision;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RecordAdmissionOutcome
{
    public function execute(AdmissionApplication $application, User $actor, string $decision, string $reason,
        ?string $authorityReference, string $applicantExplanation, ?int $expectedCurrentDecisionId,
        ?int $expectedSubmissionVersionId, bool $requiresExternalApproval = false, bool $papersConfirmed = false): AdmissionDecision
    {
        return DB::transaction(function () use ($application, $actor, $decision, $reason, $authorityReference, $applicantExplanation,
            $expectedCurrentDecisionId, $expectedSubmissionVersionId, $requiresExternalApproval, $papersConfirmed): AdmissionDecision {
            $locked = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);
            $locked->assertReviewSource($actor, $expectedSubmissionVersionId);
            $currentDecision = $locked->decisions()->whereDoesntHave('successor')->first();
            $currentClearance = $locked->enrollmentClearances()->whereDoesntHave('successor')->first();
            if ($currentDecision !== null && $currentDecision->supersedes_admission_decision_id === $expectedCurrentDecisionId
                && $currentDecision->application_submission_version_id === $expectedSubmissionVersionId
                && $currentDecision->decision === $decision && $currentDecision->reason === trim($reason)
                && $currentDecision->authority_reference === (filled($authorityReference) ? trim($authorityReference) : null)
                && $currentDecision->applicant_explanation === trim($applicantExplanation)
                && $currentDecision->decided_by === $actor->id
                && (bool) ($locked->events()->where('event_type', AdmissionApplicationEvent::TypeDecisionRecorded)
                    ->where('source_id', $currentDecision->id)->first()?->payload['requires_external_approval'] ?? false) === $requiresExternalApproval
                && (! $papersConfirmed || ($currentClearance?->admission_decision_id === $currentDecision->id
                    && $currentClearance->result === 'Cleared' && $currentClearance->external_checks_confirmed))) {
                return $currentDecision;
            }
            $recorded = app(RecordAdmissionDecision::class)->execute($locked, $actor, $decision, $reason,
                $authorityReference, $applicantExplanation, $expectedCurrentDecisionId, $expectedSubmissionVersionId, $requiresExternalApproval);
            if ($papersConfirmed && $decision === AdmissionDecision::DecisionAdmitted) {
                $current = $application->enrollmentClearances()->whereDoesntHave('successor')->first();
                app(RecordRegistrarEnrollmentClearance::class)->execute($application->fresh(), $actor, 'Cleared', true,
                    reason: $current !== null ? $reason : null,
                    authorityReference: $authorityReference, expectedCurrentClearanceId: $current?->id,
                    expectedDecisionId: $recorded->id, expectedSubmissionVersionId: $expectedSubmissionVersionId);
            }

            return $recorded;
        }, attempts: 3);
    }
}
