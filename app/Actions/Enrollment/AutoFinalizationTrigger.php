<?php

namespace App\Actions\Enrollment;

final readonly class AutoFinalizationTrigger
{
    public const TypePaymentEvidenceVerified = 'payment_evidence_verified';

    public const TypeManualPaymentConfirmed = 'manual_payment_confirmed';

    public const TypeApprovedCoverageRecorded = 'approved_coverage_recorded';

    public const TypeAssessmentEffectsRevalidated = 'assessment_effects_revalidated';

    public const TypeZeroDueAssessmentCreated = 'zero_due_assessment_created';

    public const TypeProposalConfirmed = 'proposal_confirmed';

    public function __construct(
        public string $triggerType,
        public ?string $triggerReference = null,
        public ?int $clearedByUserId = null,
        public ?int $paymentId = null,
        public ?string $paymentSummary = null,
    ) {}
}
