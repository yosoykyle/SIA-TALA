<?php

namespace App\Support;

use App\Actions\Finance\IndividualAssessmentSources;
use App\Actions\Finance\TermAccountProjection;
use App\Actions\Grades\GradeWindowService;
use App\Actions\StudentLifecycle\LifecycleAccountingReview;
use App\Filament\Resources\Enrollments\Tables\EnrollmentsTable;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Models\AdmissionApplication;
use App\Models\Assessment;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\CourseDropRecord;
use App\Models\Enrollment;
use App\Models\GradeRoster;
use App\Models\IncCompletionSubmission;
use App\Models\LateGradeAuthorization;
use App\Models\PaymentEvidenceVersion;
use App\Models\RegistrationProposalVersion;
use App\Models\TermAccount;
use App\Models\TranscriptRequest;
use App\Models\User;
use App\Queries\Admissions\AdmissionDeadlineStatus;
use App\Queries\Admissions\AdmissionQueueCounts;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Request-instance memoized sidebar badge counts across all panels and roles.
 *
 * Designed to avoid full-model hydration on unrelated pages: queries select
 * minimal columns or aggregate directly with SQL COUNT/GROUP BY.
 */
class NavigationBadgeCounts
{
    /** @var array{assessment: int, proof: int, student_changes: int, unique_total: int}|null */
    private ?array $accountingMemo = null;

    /** @var array{needs_review: int, waiting_for_applicant: int, official_credentials: int, ready_for_enrollment: int, action: int}|null */
    private ?array $admissionsMemo = null;

    /** @var array{prepare: int, issues: int, ready: int, total: int}|null */
    private ?array $registrarEnrollmentMemo = null;

    /** @var array{grade_review: int, inc_corrections: int, completion_tor: int, total: int}|null */
    private ?array $registrarGradesMemo = null;

    /** @var array<int, int> */
    private array $facultyRosterMemo = [];

    /** @var array<int, int> */
    private array $studentEnrollmentMemo = [];

    /** @var array<int, int> */
    private array $studentFinanceMemo = [];

    /** @var array<int, int> */
    private array $applicantActionMemo = [];

    /**
     * @return array{assessment: int, proof: int, student_changes: int, unique_total: int}
     */
    public function accounting(): array
    {
        if ($this->accountingMemo !== null) {
            return $this->accountingMemo;
        }

        $proofIds = PaymentEvidenceVersion::query()
            ->join('term_accounts', 'term_accounts.id', '=', 'payment_evidence_versions.term_account_id')
            ->where('payment_evidence_versions.state', PaymentEvidenceVersion::StateSubmitted)
            ->distinct()
            ->pluck('term_accounts.enrollment_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $studentChangeIds = app(LifecycleAccountingReview::class)->pendingEnrollmentIds();
        $assessmentIds = $this->accountingAssessmentIds();

        $uniqueTotal = collect([...$assessmentIds, ...$proofIds, ...$studentChangeIds])
            ->unique()
            ->count();

        return $this->accountingMemo = [
            'assessment' => count($assessmentIds),
            'proof' => count($proofIds),
            'student_changes' => count($studentChangeIds),
            'unique_total' => $uniqueTotal,
        ];
    }

    public function accountingTooltip(): ?string
    {
        $counts = $this->accounting();
        $parts = array_values(array_filter([
            $counts['assessment'] > 0 ? $counts['assessment'].' '.Str::plural('assessment review', $counts['assessment']) : null,
            $counts['proof'] > 0 ? $counts['proof'].' '.Str::plural('receipt', $counts['proof']) : null,
            $counts['student_changes'] > 0 ? $counts['student_changes'].' '.Str::plural('status change', $counts['student_changes']) : null,
        ]));

        return $parts !== [] ? implode(', ', $parts) : null;
    }

    /**
     * @return array{needs_review: int, waiting_for_applicant: int, official_credentials: int, ready_for_enrollment: int, expired: int, action: int}
     */
    public function admissions(): array
    {
        if ($this->admissionsMemo !== null) {
            return $this->admissionsMemo;
        }

        $deadlines = app(AdmissionDeadlineStatus::class);
        $closedIds = $deadlines->idsWith(AdmissionDeadlineStatus::Closed);
        $expiredIds = $deadlines->idsWith(AdmissionDeadlineStatus::Expired);

        $stateCounts = AdmissionApplication::query()
            ->canonical()
            ->whereIn('application_state', [
                AdmissionApplication::StateSubmitted,
                AdmissionApplication::StateActionNeeded,
            ])
            ->when($closedIds !== [], fn (Builder $query): Builder => $query->whereNotIn('id', $closedIds))
            ->selectRaw('application_state, COUNT(*) as aggregate')
            ->groupBy('application_state')
            ->pluck('aggregate', 'application_state');

        $readyIds = app(ReadyApplicantProjectionQuery::class)->readyApplicationIds()->all();
        $notActive = [...$closedIds, ...$expiredIds];

        $clearanceCount = AdmissionApplication::query()
            ->canonical()
            ->where('application_state', AdmissionApplication::StateAdmitted)
            ->when([...$readyIds, ...$notActive] !== [], fn (Builder $query): Builder => $query->whereNotIn('id', [...$readyIds, ...$notActive]))
            ->count();

        $readyCount = $readyIds === []
            ? 0
            : AdmissionApplication::query()
                ->canonical()
                ->whereIn('id', $readyIds)
                ->when($notActive !== [], fn (Builder $query): Builder => $query->whereNotIn('id', $notActive))
                ->count();

        $expiredCount = $expiredIds === [] ? 0 : AdmissionApplication::query()->canonical()->whereIn('id', $expiredIds)->count();
        $needsReview = (int) ($stateCounts[AdmissionApplication::StateSubmitted] ?? 0);
        $waiting = (int) ($stateCounts[AdmissionApplication::StateActionNeeded] ?? 0);

        return $this->admissionsMemo = [
            AdmissionQueueCounts::NeedsReview => $needsReview,
            AdmissionQueueCounts::WaitingForApplicant => $waiting,
            AdmissionQueueCounts::RegistrarClearance => $clearanceCount,
            AdmissionQueueCounts::ReadyForEnrollment => $readyCount,
            AdmissionQueueCounts::Expired => $expiredCount,
            'action' => $needsReview + $clearanceCount + $expiredCount,
        ];
    }

    /**
     * @return array{prepare: int, issues: int, ready: int, total: int}
     */
    public function registrarEnrollment(): array
    {
        if ($this->registrarEnrollmentMemo !== null) {
            return $this->registrarEnrollmentMemo;
        }

        $counts = ['prepare' => 0, 'issues' => 0, 'ready' => 0];
        $cases = Enrollment::query()
            ->where('canonical_outcome', Enrollment::OutcomeInProgress)
            ->with([
                'credentialUser',
                'admissionApplication',
                'studentProfile',
                'currentProposalVersion.confirmation',
                'currentProposalVersion.curriculumVersion.program',
                'currentProposalVersion.items.reservation',
            ])
            ->get();

        foreach ($cases as $case) {
            $bucket = match (EnrollmentsTable::stage($case)) {
                'Prepare subjects' => 'prepare',
                'Ready to finalize' => 'ready',
                'Waiting for learner', 'Payment or fee review' => null,
                default => 'issues',
            };

            if ($bucket !== null) {
                $counts[$bucket]++;
            }
        }

        return $this->registrarEnrollmentMemo = [
            ...$counts,
            'total' => $counts['prepare'] + $counts['issues'] + $counts['ready'],
        ];
    }

    public function registrarEnrollmentTooltip(): ?string
    {
        $counts = $this->registrarEnrollment();
        $parts = array_values(array_filter([
            $counts['prepare'] > 0 ? $counts['prepare'].' to prepare' : null,
            $counts['issues'] > 0 ? $counts['issues'].' '.Str::plural('issue', $counts['issues']) : null,
            $counts['ready'] > 0 ? $counts['ready'].' ready to finalize' : null,
        ]));

        return $parts !== [] ? implode(', ', $parts) : null;
    }

    /**
     * @return array{grade_review: int, inc_corrections: int, completion_tor: int, total: int}
     */
    public function registrarGrades(): array
    {
        if ($this->registrarGradesMemo !== null) {
            return $this->registrarGradesMemo;
        }

        $gradeReview = GradeRosterResource::getEloquentQuery()
            ->where('state', GradeRoster::StateSubmitted)
            ->count();

        $incCorrections = IncCompletionSubmission::query()
            ->where('state', IncCompletionSubmission::StateSubmitted)
            ->count();

        $completionTor = TranscriptRequest::query()
            ->where('state', TranscriptRequest::StateOpen)
            ->count();

        return $this->registrarGradesMemo = [
            'grade_review' => $gradeReview,
            'inc_corrections' => $incCorrections,
            'completion_tor' => $completionTor,
            'total' => $gradeReview + $incCorrections + $completionTor,
        ];
    }

    public function registrarGradesTooltip(): ?string
    {
        $counts = $this->registrarGrades();
        $parts = array_values(array_filter([
            $counts['grade_review'] > 0 ? $counts['grade_review'].' class '.Str::plural('roster', $counts['grade_review']) : null,
            $counts['inc_corrections'] > 0 ? $counts['inc_corrections'].' INC '.Str::plural('submission', $counts['inc_corrections']) : null,
            $counts['completion_tor'] > 0 ? $counts['completion_tor'].' open TOR '.Str::plural('request', $counts['completion_tor']) : null,
        ]));

        return $parts !== [] ? implode(', ', $parts) : null;
    }

    public function facultyGradeRosterActionCount(?User $user): int
    {
        if (! $user instanceof User) {
            return 0;
        }

        if (array_key_exists($user->id, $this->facultyRosterMemo)) {
            return $this->facultyRosterMemo[$user->id];
        }

        $rosters = GradeRoster::query()
            ->whereIn('section_id', ClassOfferingTeachingAssignment::query()
                ->select('section_id')
                ->where('faculty_user_id', $user->id)
                ->where('state', ClassOfferingTeachingAssignment::StateActive))
            ->whereIn('state', [
                GradeRoster::StateReturned,
                GradeRoster::StateDraft,
                GradeRoster::StateLateNotSubmitted,
            ])
            ->with('termOffering:id,term_id')
            ->get(['id', 'term_offering_id', 'section_id', 'state']);

        if ($rosters->isEmpty()) {
            return $this->facultyRosterMemo[$user->id] = 0;
        }

        $gradeWindow = app(GradeWindowService::class);
        $count = 0;

        foreach ($rosters as $roster) {
            if ($roster->state === GradeRoster::StateReturned || $gradeWindow->isOpen($roster, LateGradeAuthorization::PeriodFinal)) {
                $count++;
            }
        }

        return $this->facultyRosterMemo[$user->id] = $count;
    }

    public function studentEnrollmentActionCount(?User $user): int
    {
        if (! $user instanceof User) {
            return 0;
        }

        if (array_key_exists($user->id, $this->studentEnrollmentMemo)) {
            return $this->studentEnrollmentMemo[$user->id];
        }

        return $this->studentEnrollmentMemo[$user->id] = Enrollment::query()
            ->where('credential_user_id', $user->id)
            ->where('canonical_outcome', Enrollment::OutcomeInProgress)
            ->whereHas('currentProposalVersion', fn (Builder $query): Builder => $query
                ->where('state', RegistrationProposalVersion::StateIssued)
                ->whereDoesntHave('confirmation'))
            ->count();
    }

    public function studentFinanceActionCount(?User $user): int
    {
        if (! $user instanceof User) {
            return 0;
        }

        if (array_key_exists($user->id, $this->studentFinanceMemo)) {
            return $this->studentFinanceMemo[$user->id];
        }

        $account = TermAccount::query()
            ->with([
                'enrollment',
                'assessments' => fn ($query) => $query->where('state', Assessment::StateActive)->latest('version'),
                'assessments.obligations.paymentAllocations.payment',
                'assessments.obligations.coverages',
                'latestPaymentEvidenceVersion',
            ])
            ->where('credential_user_id', $user->id)
            ->latest('id')
            ->first();

        if (! $account instanceof TermAccount) {
            return $this->studentFinanceMemo[$user->id] = 0;
        }

        if ($account->latestPaymentEvidenceVersion?->state === PaymentEvidenceVersion::StateRejected) {
            return $this->studentFinanceMemo[$user->id] = 1;
        }

        $position = app(TermAccountProjection::class)->forAccount($account, useLoadedRelations: true);

        return $this->studentFinanceMemo[$user->id] = ($position['state'] === 'ActionNeeded' ? 1 : 0);
    }

    public function applicantActionCount(?User $user): int
    {
        if (! $user instanceof User) {
            return 0;
        }

        if (array_key_exists($user->id, $this->applicantActionMemo)) {
            return $this->applicantActionMemo[$user->id];
        }

        return $this->applicantActionMemo[$user->id] = AdmissionApplication::query()
            ->canonical()
            ->where('user_id', $user->id)
            ->where('application_state', AdmissionApplication::StateActionNeeded)
            ->count();
    }

    /**
     * @return list<int>
     */
    private function accountingAssessmentIds(): array
    {
        $ids = [];

        // 1. In-progress enrollments missing an active assessment for their current proposal version.
        $inProgressCases = Enrollment::query()
            ->where('canonical_outcome', Enrollment::OutcomeInProgress)
            ->with(['termAccount.assessments' => fn ($query) => $query
                ->where('state', Assessment::StateActive)
                ->orderByDesc('version')
                ->select(['id', 'term_account_id', 'version', 'source_proposal_version_id', 'state'])])
            ->get(['id', 'canonical_outcome', 'current_proposal_version_id']);

        foreach ($inProgressCases as $case) {
            $assessment = $case->termAccount?->assessments->first();

            if ($assessment === null || (int) $assessment->source_proposal_version_id !== (int) $case->current_proposal_version_id) {
                $ids[$case->id] = (int) $case->id;
            }
        }

        // 2. Official enrollments with confirmed adjustment proposals or recorded course drops pending reassessment.
        $officialCandidates = Enrollment::query()
            ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled)
            ->where(function (Builder $query): void {
                $query->whereHas('currentProposalVersion', fn (Builder $proposal): Builder => $proposal
                    ->where('purpose', RegistrationProposalVersion::PurposeAdjustment)
                    ->where('state', RegistrationProposalVersion::StateConfirmed)
                    ->whereHas('confirmation'))
                    ->orWhereIn('id', CourseDropRecord::query()
                        ->whereNotNull('recorded_at')
                        ->select('enrollment_id'));
            })
            ->with([
                'term',
                'currentProposalVersion.confirmation',
                'currentCorVersion',
                'termAccount.assessments' => fn ($query) => $query
                    ->where('state', Assessment::StateActive)
                    ->orderByDesc('version')
                    ->select(['id', 'term_account_id', 'version', 'source_proposal_version_id', 'state']),
            ])
            ->get();

        if ($officialCandidates->isNotEmpty()) {
            $sourcesResolver = app(IndividualAssessmentSources::class);

            foreach ($officialCandidates as $case) {
                $assessment = $case->termAccount?->assessments->first();
                $sources = $sourcesResolver->forEnrollment($case, useLoadedRelations: true);

                if ($sources['course_drop'] !== null
                    || ($sources['adjustment'] && (int) $assessment?->source_proposal_version_id !== (int) $case->current_proposal_version_id)) {
                    $ids[$case->id] = (int) $case->id;
                }
            }
        }

        // 3. Re-assessed accounts (active assessment version > 1) that require payment/coverage reconciliation.
        $reassessedAccounts = TermAccount::query()
            ->whereHas('assessments', fn (Builder $query): Builder => $query
                ->where('state', Assessment::StateActive)
                ->where('version', '>', 1))
            ->when($ids !== [], fn (Builder $query): Builder => $query->whereNotIn('enrollment_id', array_values($ids)))
            ->with([
                'enrollment',
                'assessments.obligations.paymentAllocations.payment',
                'assessments.obligations.coverages',
            ])
            ->get();

        if ($reassessedAccounts->isNotEmpty()) {
            $projection = app(TermAccountProjection::class);

            foreach ($reassessedAccounts as $account) {
                $assessment = $account->assessments->where('state', Assessment::StateActive)->sortByDesc('version')->first();

                if ($assessment === null || (int) $assessment->version <= 1) {
                    continue;
                }

                $position = $projection->forAccount($account, useLoadedRelations: true);

                if ($position['reconciliation_required'] ?? false) {
                    $ids[(int) $account->enrollment_id] = (int) $account->enrollment_id;
                }
            }
        }

        return array_values($ids);
    }
}
