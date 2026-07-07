<?php

namespace App\Actions\StudentHub;

use App\Actions\Enrollment\EnrollmentGateReviewSummary;
use App\Actions\Finance\FinanceEvidenceService;
use App\Actions\Finance\PaymentStatusResolver;
use App\Actions\StudentLifecycle\HoldEvaluationService;
use App\Models\Enrollment;
use App\Models\EnrollmentGateResult;
use App\Models\Hold;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Resolves the single highest-priority actionable notice for the Student Hub
 * Dashboard, per PRD `12_student_hub.md` §12.2 (Student Hub Display Priority).
 *
 * TAL-91A implements the tiers whose signals already exist in
 * {@see HoldEvaluationService}, {@see FinanceEvidenceService}, and the
 * user's database notifications: security/account notice, enrollment
 * blocked, payment pending or rejected, COR blocked, and informational
 * notices.
 *
 * TAL-91C additionally implements the Capacity Pending tier and the
 * Pending Review gate-reason tier, both reusing already-persisted
 * `EnrollmentGateResult` rows via {@see EnrollmentGateReviewSummary} rather
 * than evaluating any new gate logic. Tiers still deferred to TAL-91D:
 * missing requirements, active academic deficiency, schedule available,
 * COR available, and grades released.
 */
class StudentHubPriorityResolver
{
    public function __construct(
        private readonly HoldEvaluationService $holds,
        private readonly FinanceEvidenceService $finance,
        private readonly EnrollmentGateReviewSummary $gateReviewSummary,
    ) {}

    /**
     * @return array{tier:string, student_reason:string, required_action:?string, office_to_contact:?string}|null
     */
    public function resolve(StudentProfile $studentProfile): ?array
    {
        $user = $studentProfile->user;
        $currentEnrollment = $this->currentEnrollment($studentProfile);

        $unreadNotification = $user !== null ? $this->latestNotification($user, unreadOnly: true) : null;

        if ($unreadNotification instanceof DatabaseNotification) {
            return $this->securityNoticeTier($unreadNotification);
        }

        $enrollmentBlockingHold = $this->holds->mostRestrictiveActiveHold(
            $studentProfile,
            [Hold::BlockingEnrollment],
            $currentEnrollment,
        );

        if ($enrollmentBlockingHold instanceof Hold) {
            return $this->holdTier('Enrollment Blocked', $enrollmentBlockingHold);
        }

        $paymentTier = $this->paymentTier($studentProfile);

        if ($paymentTier !== null) {
            return $paymentTier;
        }

        $capacityPendingTier = $this->capacityPendingTier($currentEnrollment);

        if ($capacityPendingTier !== null) {
            return $capacityPendingTier;
        }

        $pendingReviewTier = $this->pendingReviewTier($currentEnrollment);

        if ($pendingReviewTier !== null) {
            return $pendingReviewTier;
        }

        $corBlockingHold = $this->holds->mostRestrictiveActiveHold(
            $studentProfile,
            [Hold::BlockingCorPrint],
            $currentEnrollment,
        );

        if ($corBlockingHold instanceof Hold) {
            return $this->holdTier('COR Blocked', $corBlockingHold);
        }

        $readNotification = $user !== null ? $this->latestNotification($user, unreadOnly: false) : null;

        if ($readNotification instanceof DatabaseNotification) {
            return $this->informationalNoticeTier($readNotification);
        }

        return null;
    }

    private function currentEnrollment(StudentProfile $studentProfile): ?Enrollment
    {
        return Enrollment::query()
            ->where('student_profile_id', $studentProfile->id)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{tier:string, student_reason:string, required_action:?string, office_to_contact:?string}
     */
    private function securityNoticeTier(DatabaseNotification $notification): array
    {
        $data = $notification->getAttribute('data');

        return [
            'tier' => 'Security / Account Notice',
            'student_reason' => data_get($data, 'body') ?? data_get($data, 'title') ?? 'You have an unread account notice.',
            'required_action' => 'Review the notice for the required next step.',
            'office_to_contact' => null,
        ];
    }

    /**
     * @return array{tier:string, student_reason:string, required_action:?string, office_to_contact:?string}
     */
    private function informationalNoticeTier(DatabaseNotification $notification): array
    {
        $data = $notification->getAttribute('data');

        return [
            'tier' => 'Informational Notice',
            'student_reason' => data_get($data, 'body') ?? data_get($data, 'title') ?? 'You have a recent account notice.',
            'required_action' => null,
            'office_to_contact' => null,
        ];
    }

    /**
     * @return array{tier:string, student_reason:string, required_action:?string, office_to_contact:string}
     */
    private function holdTier(string $tier, Hold $hold): array
    {
        return [
            'tier' => $tier,
            'student_reason' => $hold->studentFacingMessage() ?? 'An active hold is affecting your account.',
            'required_action' => $hold->resolution_requirement,
            'office_to_contact' => $hold->studentFacingOfficeLabel(),
        ];
    }

    /**
     * @return array{tier:string, student_reason:string, required_action:?string, office_to_contact:?string}|null
     */
    private function paymentTier(StudentProfile $studentProfile): ?array
    {
        if ($studentProfile->user === null) {
            return null;
        }

        $finance = $this->finance->studentFinance($studentProfile->user);

        if (($finance['available'] ?? false) !== true) {
            return null;
        }

        $paymentStatus = $finance['summary']['payment_status'] ?? null;

        if (! in_array($paymentStatus, [
            PaymentStatusResolver::StatusPaymentPending,
            PaymentStatusResolver::StatusPaymentUnderReview,
            PaymentStatusResolver::StatusPaymentRejected,
        ], true)) {
            return null;
        }

        return [
            'tier' => 'Payment Pending or Rejected',
            'student_reason' => match ($paymentStatus) {
                PaymentStatusResolver::StatusPaymentRejected => 'Your last payment evidence was rejected.',
                PaymentStatusResolver::StatusPaymentUnderReview => 'Your payment evidence is under review.',
                default => 'Your payment checkout is pending.',
            },
            'required_action' => match ($paymentStatus) {
                PaymentStatusResolver::StatusPaymentRejected => 'Submit new payment evidence for review.',
                PaymentStatusResolver::StatusPaymentUnderReview => 'No action needed while the Accounting Office reviews your evidence.',
                default => 'Complete your pending payment checkout.',
            },
            'office_to_contact' => 'Accounting Office',
        ];
    }

    /**
     * Capacity Pending tier (PRD `12_student_hub.md` §12.2 tier 4, rule 7).
     *
     * @return array{tier:string, student_reason:string, required_action:?string, office_to_contact:?string}|null
     */
    private function capacityPendingTier(?Enrollment $currentEnrollment): ?array
    {
        if (! $currentEnrollment instanceof Enrollment || $currentEnrollment->status !== 'capacity_pending') {
            return null;
        }

        return [
            'tier' => 'Capacity Pending',
            'student_reason' => 'Your section placement is awaiting Registrar action.',
            'required_action' => null,
            'office_to_contact' => 'Registrar Office',
        ];
    }

    /**
     * Pending-Review gate-reason tier (PRD `12_student_hub.md` §12.2 rule 8). Reuses
     * already-persisted {@see EnrollmentGateResult} rows via
     * {@see EnrollmentGateReviewSummary} rather than evaluating any new gate logic.
     *
     * @return array{tier:string, student_reason:string, required_action:?string, office_to_contact:?string}|null
     */
    private function pendingReviewTier(?Enrollment $currentEnrollment): ?array
    {
        if (! $currentEnrollment instanceof Enrollment || $currentEnrollment->status !== 'pending_review') {
            return null;
        }

        $reason = $this->gateReviewSummary->studentFacingReason($currentEnrollment);

        if ($reason === null) {
            return null;
        }

        return [
            'tier' => 'Pending Review',
            'student_reason' => $reason['reason'],
            'required_action' => null,
            'office_to_contact' => $reason['office'],
        ];
    }

    private function latestNotification(User $user, bool $unreadOnly): ?DatabaseNotification
    {
        $query = $user->notifications()->latest();

        if ($unreadOnly) {
            $query->whereNull('read_at');
        } else {
            $query->whereNotNull('read_at');
        }

        return $query->first();
    }
}
