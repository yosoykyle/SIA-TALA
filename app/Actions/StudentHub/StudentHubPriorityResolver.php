<?php

namespace App\Actions\StudentHub;

use App\Actions\Cor\BuildCorOutput;
use App\Actions\Enrollment\RegistrationReadinessQuery;
use App\Actions\Finance\FinanceEvidenceService;
use App\Actions\Finance\PaymentStatusResolver;
use App\Actions\StudentLifecycle\HoldEvaluationService;
use App\Filament\Student\Pages\GradesView;
use App\Models\ChecklistItem;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\GradeRosterRow;
use App\Models\Hold;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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
 * TAL-91C additionally implements the Capacity Pending and Pending Review
 * tiers. Slice 4 projects those tiers from the canonical Registration Case
 * readiness facts instead of the retired persisted gate engine.
 *
 * TAL-91E implements the remaining tiers 6-10: missing requirements
 * (reusing {@see ChecklistItem} and its `isResolved()` logic), active
 * academic deficiency (reusing {@see StudentProfile::$academic_standing}),
 * schedule available (reusing the exact published+active filter shape
 * from {@see StudentDashboardService::scheduleFor()}), COR available
 * (reusing {@see BuildCorOutput::forStudent()} directly rather than
 * re-deriving COR availability), and grades released (reusing the
 * released-grade existence filter shape from
 * {@see GradesView::releasedGradesQuery()}).
 * All eleven tiers of §12.2 are now implemented; none remain deferred.
 */
class StudentHubPriorityResolver
{
    public function __construct(
        private readonly HoldEvaluationService $holds,
        private readonly FinanceEvidenceService $finance,
        private readonly RegistrationReadinessQuery $registrationReadiness,
        private readonly BuildCorOutput $corOutput,
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

        $registrationReadiness = $currentEnrollment instanceof Enrollment
            && $currentEnrollment->canonical_outcome === Enrollment::OutcomeInProgress
            && ! in_array($currentEnrollment->status, ['completed', 'cancelled', 'dropped', 'withdrawn'], true)
                ? $this->registrationReadiness->for($currentEnrollment)
                : null;
        $capacityPendingTier = $this->capacityPendingTier($currentEnrollment, $registrationReadiness);

        if ($capacityPendingTier !== null) {
            return $capacityPendingTier;
        }

        $pendingReviewTier = $this->pendingReviewTier($currentEnrollment, $registrationReadiness);

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

        $missingRequirementsTier = $this->missingRequirementsTier($studentProfile);

        if ($missingRequirementsTier !== null) {
            return $missingRequirementsTier;
        }

        $academicDeficiencyTier = $this->academicDeficiencyTier($studentProfile);

        if ($academicDeficiencyTier !== null) {
            return $academicDeficiencyTier;
        }

        $scheduleAvailableTier = $this->scheduleAvailableTier($currentEnrollment);

        if ($scheduleAvailableTier !== null) {
            return $scheduleAvailableTier;
        }

        $corAvailableTier = $user !== null ? $this->corAvailableTier($user) : null;

        if ($corAvailableTier !== null) {
            return $corAvailableTier;
        }

        $gradesReleasedTier = $this->gradesReleasedTier($studentProfile);

        if ($gradesReleasedTier !== null) {
            return $gradesReleasedTier;
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
     * @param  array{ready:bool,proposal:bool,confirmation:bool,placement:bool,finance:bool,identity:bool,blockers:list<string>}|null  $readiness
     * @return array{tier:string, student_reason:string, required_action:?string, office_to_contact:?string}|null
     */
    private function capacityPendingTier(?Enrollment $currentEnrollment, ?array $readiness): ?array
    {
        if (! $currentEnrollment instanceof Enrollment
            || $readiness === null
            || $readiness['proposal'] !== true
            || $readiness['placement'] === true) {
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
     * Pending-Review tier (PRD `12_student_hub.md` §12.2 rule 8), projected from
     * the canonical Registration Case readiness facts.
     *
     * @param  array{ready:bool,proposal:bool,confirmation:bool,placement:bool,finance:bool,identity:bool,blockers:list<string>}|null  $readiness
     * @return array{tier:string, student_reason:string, required_action:?string, office_to_contact:?string}|null
     */
    private function pendingReviewTier(?Enrollment $currentEnrollment, ?array $readiness): ?array
    {
        if (! $currentEnrollment instanceof Enrollment || $readiness === null || $readiness['ready'] === true) {
            return null;
        }

        $blocker = $readiness['blockers'][0] ?? 'Registration review';
        $accountingBlocker = $blocker === 'Accounting clearance';

        return [
            'tier' => 'Pending Review',
            'student_reason' => match ($blocker) {
                'Confirmed Registration Proposal' => 'Your Registration Proposal is waiting for confirmation.',
                'Current Published Timetable source' => 'Your Registration Proposal must be reconciled with the current Published Timetable.',
                'Complete current placement' => 'Your confirmed Registration Proposal still needs complete section placement.',
                'Accounting clearance' => 'Your exact-Term assessment or payment requirement is not yet cleared.',
                'Authoritative learner identity' => 'Your Registration Case needs an authoritative learner identity review.',
                default => 'Your Registration Case requires office review.',
            },
            'required_action' => null,
            'office_to_contact' => $accountingBlocker ? 'Accounting Office' : 'Registrar Office',
        ];
    }

    /**
     * Missing Requirements tier (PRD `12_student_hub.md` §12.2 tier 6). Reuses
     * {@see ChecklistItem::isResolved()} rather than duplicating its logic; the
     * `requirement_type` value is a plain student-safe label already displayed
     * verbatim elsewhere (e.g. `ChecklistItemsRelationManager`,
     * `EnrollmentGateEvaluator::documentGate()`), so no additional formatting
     * is needed here.
     *
     * @return array{tier:string, student_reason:string, required_action:?string, office_to_contact:?string}|null
     */
    private function missingRequirementsTier(StudentProfile $studentProfile): ?array
    {
        $unresolvedItem = ChecklistItem::query()
            ->where('owner_type', ChecklistItem::OwnerStudent)
            ->where('student_profile_id', $studentProfile->id)
            ->oldest('deadline')
            ->oldest('id')
            ->get()
            ->first(fn (ChecklistItem $item): bool => ! $item->isResolved());

        if (! $unresolvedItem instanceof ChecklistItem) {
            return null;
        }

        return [
            'tier' => 'Missing Requirements',
            'student_reason' => 'You have an outstanding requirement: '.$unresolvedItem->requirement_type.'.',
            'required_action' => 'Submit or complete the outstanding requirement.',
            'office_to_contact' => 'Registrar Office',
        ];
    }

    /**
     * Active Academic Deficiency tier (PRD `12_student_hub.md` §12.2 tier 7). Reuses
     * {@see StudentProfile::$academic_standing} rather than deriving a new signal.
     * `Irregular` alone is not treated as a deficiency: the PRD distinguishes "not
     * following the standard curriculum sequence" (Irregular) from an actual academic
     * problem. Only the standings that represent a genuine problem trigger this tier.
     *
     * @return array{tier:string, student_reason:string, required_action:?string, office_to_contact:?string}|null
     */
    private function academicDeficiencyTier(StudentProfile $studentProfile): ?array
    {
        $deficientStandings = [
            StudentProfile::StandingDeficient,
            StudentProfile::StandingProbationary,
            StudentProfile::StandingBlockedByPrerequisite,
            StudentProfile::StandingMustRepeatYear,
        ];

        if (! in_array($studentProfile->academic_standing, $deficientStandings, true)) {
            return null;
        }

        return [
            'tier' => 'Active Academic Deficiency',
            'student_reason' => 'Your academic standing is currently: '.$studentProfile->academic_standing.'.',
            'required_action' => 'Contact the Academic Head Office to review your academic standing.',
            'office_to_contact' => 'Academic Head Office',
        ];
    }

    /**
     * Schedule Available tier (PRD `12_student_hub.md` §12.2 tier 8). Reuses the exact
     * published+active filter shape from
     * {@see StudentDashboardService::scheduleFor()} (published `scheduleRun.status`
     * plus active `sectionMeeting.state`) rather than re-deriving a separate filter,
     * to avoid the class of divergence bug fixed in TAL-91C.
     *
     * @return array{tier:string, student_reason:string, required_action:?string, office_to_contact:?string}|null
     */
    private function scheduleAvailableTier(?Enrollment $currentEnrollment): ?array
    {
        if (! $currentEnrollment instanceof Enrollment) {
            return null;
        }

        $currentVersionId = PublishedTimetableVersion::query()
            ->where('term_id', $currentEnrollment->term_id)
            ->where('state', PublishedTimetableVersion::StatePublished)
            ->latest('version')
            ->value('id');
        $sectionIds = CourseEnrollment::query()
            ->where('enrollment_id', $currentEnrollment->id)
            ->where('status', CourseEnrollment::StatusActive)
            ->where('is_current', true)
            ->whereNotNull('section_id')
            ->pluck('section_id');
        $hasPublishedActiveSchedule = $currentVersionId !== null
            && $sectionIds->isNotEmpty()
            && PublishedTimetableMeeting::query()
                ->where('published_timetable_version_id', $currentVersionId)
                ->whereIn('section_id', $sectionIds)
                ->exists();

        if (! $hasPublishedActiveSchedule) {
            return null;
        }

        return [
            'tier' => 'Schedule Available',
            'student_reason' => 'Your class schedule has been published and is available to view.',
            'required_action' => null,
            'office_to_contact' => null,
        ];
    }

    /**
     * COR Available tier (PRD `12_student_hub.md` §12.2 tier 9). Reuses
     * {@see BuildCorOutput::forStudent()} directly rather than re-implementing COR
     * availability logic, to avoid the exact class of divergence bug found and fixed
     * in TAL-91C.
     *
     * @return array{tier:string, student_reason:string, required_action:?string, office_to_contact:?string}|null
     */
    private function corAvailableTier(User $user): ?array
    {
        $corOutput = $this->corOutput->forStudent($user);

        if (($corOutput['available'] ?? false) !== true) {
            return null;
        }

        return [
            'tier' => 'COR Available',
            'student_reason' => 'Your Certificate of Registration is available to view and print.',
            'required_action' => null,
            'office_to_contact' => null,
        ];
    }

    /**
     * Grades Released tier (PRD `12_student_hub.md` §12.2 tier 10). Reuses the
     * `whereNotNull('released_at')` plus own-enrollment filter shape from
     * {@see GradesView::releasedGradesQuery()} as an
     * existence check, rather than re-deriving a separate filter.
     *
     * @return array{tier:string, student_reason:string, required_action:?string, office_to_contact:?string}|null
     */
    private function gradesReleasedTier(StudentProfile $studentProfile): ?array
    {
        $hasReleasedGrade = GradeRosterRow::query()
            ->whereNotNull('released_at')
            ->whereHas('courseEnrollment.enrollment', fn (Builder $query) => $query->where('student_profile_id', $studentProfile->id))
            ->exists();

        if (! $hasReleasedGrade) {
            return null;
        }

        return [
            'tier' => 'Grades Released',
            'student_reason' => 'New grades have been released and are available to view.',
            'required_action' => null,
            'office_to_contact' => null,
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
