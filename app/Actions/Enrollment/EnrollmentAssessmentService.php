<?php

namespace App\Actions\Enrollment;

use App\Models\Assessment;
use App\Models\AssessmentLine;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\FeeRule;
use App\Models\LedgerEntry;
use App\Models\PaymentScheduleRow;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

class EnrollmentAssessmentService
{
    public function __construct(private readonly DecimalMoney $money) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function generateDraft(Enrollment $enrollment, User $actor, ?CarbonImmutable $asOf = null): Assessment
    {
        Gate::forUser($actor)->authorize('create', Assessment::class);

        $effectiveDate = ($asOf ?? CarbonImmutable::now(config('app.timezone')))->toDateString();

        return DB::transaction(function () use ($enrollment, $effectiveDate): Assessment {
            $lockedEnrollment = Enrollment::query()
                ->with(['studentProfile', 'term'])
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedEnrollment->status !== 'pending_payment') {
                throw ValidationException::withMessages([
                    'enrollment' => 'Assessment generation requires a Registrar-confirmed pending payment enrollment.',
                ]);
            }

            $courseEnrollments = $this->activeCourseEnrollments($lockedEnrollment);

            if ($courseEnrollments->isEmpty()) {
                throw ValidationException::withMessages([
                    'course_enrollments' => 'Assessment generation requires active TAL-67 course enrollments.',
                ]);
            }

            $rules = $this->applicableRules($lockedEnrollment, $effectiveDate);
            $downpaymentRule = $this->exactDownpaymentRule($lockedEnrollment, $effectiveDate);
            $chargeRules = $rules
                ->reject(fn (FeeRule $rule): bool => $rule->display_category === FeeRule::DisplayCategoryDownpayment)
                ->reject(fn (FeeRule $rule): bool => $rule->calculation_type === FeeRule::CalculationManual)
                ->values();

            $assessment = Assessment::query()
                ->where('enrollment_id', $lockedEnrollment->id)
                ->where('state', Assessment::StateDraft)
                ->lockForUpdate()
                ->first();

            if (! $assessment instanceof Assessment) {
                $assessment = Assessment::query()->create([
                    'enrollment_id' => $lockedEnrollment->id,
                    'version' => $this->nextVersion($lockedEnrollment),
                    'state' => Assessment::StateDraft,
                    'currency' => 'PHP',
                    'subtotal' => '0.00',
                    'discount_total' => '0.00',
                    'total' => '0.00',
                    'required_downpayment' => '0.00',
                ]);
            } else {
                $lineIds = $assessment->lines()->pluck('id');

                if ($lineIds->isNotEmpty() && LedgerEntry::query()
                    ->whereIn('source_id', $lineIds)
                    ->where('source_type', AssessmentLine::class)
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'assessment' => 'Draft assessment already has ledger postings and cannot be regenerated.',
                    ]);
                }

                $assessment->paymentScheduleRows()->delete();
                $assessment->lines()->delete();
            }

            $subtotal = '0.00';

            foreach ($chargeRules as $rule) {
                foreach ($this->linePayloads($assessment, $rule, $courseEnrollments) as $payload) {
                    AssessmentLine::query()->create($payload);
                    $subtotal = $this->money->add($subtotal, $payload['amount']);
                }
            }

            $requiredDownpayment = $downpaymentRule instanceof FeeRule && $downpaymentRule->amount !== null
                ? $this->money->normalize((string) $downpaymentRule->amount)
                : '0.00';

            $assessment->update([
                'subtotal' => $subtotal,
                'discount_total' => '0.00',
                'total' => $subtotal,
                'required_downpayment' => $requiredDownpayment,
            ]);

            if ($this->money->greaterThanZero($requiredDownpayment)) {
                $dueDate = $lockedEnrollment->term instanceof Term
                    ? CarbonImmutable::parse($lockedEnrollment->term->starts_on)->toDateString()
                    : $effectiveDate;

                PaymentScheduleRow::query()->create([
                    'assessment_id' => $assessment->id,
                    'financial_accommodation_id' => null,
                    'sequence' => 1,
                    'category' => PaymentScheduleRow::CategoryDownpayment,
                    'due_date' => $dueDate,
                    'amount' => $requiredDownpayment,
                    'state' => PaymentScheduleRow::StateDue,
                ]);
            }

            return $assessment->refresh();
        }, attempts: 3);
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function activate(Assessment $assessment, User $actor, ?CarbonImmutable $postedAt = null): Assessment
    {
        Gate::forUser($actor)->authorize('activate', $assessment);

        $timestamp = $postedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($assessment, $actor, $timestamp): Assessment {
            $lockedAssessment = Assessment::query()
                ->with(['enrollment.studentProfile', 'lines.feeRule'])
                ->whereKey($assessment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAssessment->state === Assessment::StateActive) {
                return $lockedAssessment;
            }

            if ($lockedAssessment->state !== Assessment::StateDraft) {
                throw ValidationException::withMessages([
                    'assessment' => 'Only draft assessments can be activated in TAL-68.',
                ]);
            }

            $enrollment = $lockedAssessment->enrollment;
            $studentProfile = $enrollment?->studentProfile;

            if (! $enrollment instanceof Enrollment || ! $studentProfile instanceof StudentProfile) {
                throw ValidationException::withMessages([
                    'assessment' => 'Assessment is missing its enrollment or student profile source.',
                ]);
            }

            $downpaymentRule = $this->exactDownpaymentRule($enrollment, $timestamp->toDateString());

            if (! $downpaymentRule instanceof FeeRule
                || ! $this->money->greaterThanZero((string) $lockedAssessment->required_downpayment)) {
                throw ValidationException::withMessages([
                    'downpayment' => 'An active downpayment rule for this enrollment program and term is required before activation.',
                ]);
            }

            foreach ($lockedAssessment->lines as $line) {
                LedgerEntry::query()->firstOrCreate(
                    [
                        'source_type' => AssessmentLine::class,
                        'source_id' => $line->id,
                        'direction' => LedgerEntry::DirectionCharge,
                    ],
                    [
                        'student_profile_id' => $studentProfile->id,
                        'term_id' => $enrollment->term_id,
                        'enrollment_id' => $enrollment->id,
                        'category' => $line->feeRule->ledger_category ?? FeeRule::LedgerCategoryCharge,
                        'amount' => $line->amount,
                        'payment_id' => null,
                        'payment_allocation_id' => null,
                        'reverses_entry_id' => null,
                        'adjusts_entry_id' => null,
                        'description' => $line->description_snapshot,
                        'posted_by' => $actor->id,
                        'posted_at' => $timestamp,
                        'state' => 'posted',
                    ],
                );
            }

            $lockedAssessment->update([
                'state' => Assessment::StateActive,
                'activated_by' => $actor->id,
                'activated_at' => $timestamp,
            ]);

            return $lockedAssessment->refresh();
        }, attempts: 3);
    }

    public function ledgerBalanceFor(StudentProfile $studentProfile, ?Term $term = null): string
    {
        $entries = LedgerEntry::query()
            ->where('student_profile_id', $studentProfile->id)
            ->where('state', 'posted')
            ->when($term instanceof Term, fn (Builder $query) => $query->where('term_id', $term->id))
            ->get(['direction', 'amount']);

        $balance = '0.00';

        foreach ($entries as $entry) {
            $amount = (string) $entry->amount;
            $balance = match ($entry->direction) {
                LedgerEntry::DirectionPayment,
                LedgerEntry::DirectionDiscount,
                LedgerEntry::DirectionScholarship,
                LedgerEntry::DirectionWaiver,
                LedgerEntry::DirectionReversal => $this->money->subtract($balance, $amount),
                default => $this->money->add($balance, $amount),
            };
        }

        return $balance;
    }

    /**
     * @return Collection<int, CourseEnrollment>
     */
    private function activeCourseEnrollments(Enrollment $enrollment): Collection
    {
        return CourseEnrollment::query()
            ->with(['termOffering.curriculumEntry.courseSpecification.course'])
            ->where('enrollment_id', $enrollment->id)
            ->where('status', CourseEnrollment::StatusActive)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @return Collection<int, FeeRule>
     */
    private function applicableRules(Enrollment $enrollment, string $effectiveDate): Collection
    {
        $studentProfile = $enrollment->studentProfile;

        if (! $studentProfile instanceof StudentProfile) {
            throw ValidationException::withMessages([
                'program' => 'Assessment generation requires a student program scope.',
            ]);
        }

        $programId = (int) $studentProfile->program_id;
        $termId = (int) $enrollment->term_id;

        return FeeRule::query()
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $effectiveDate)
            ->where(function (Builder $query) use ($effectiveDate): void {
                $query->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $effectiveDate);
            })
            ->where(function (Builder $query) use ($programId): void {
                $query->whereNull('program_id')
                    ->orWhere('program_id', $programId);
            })
            ->where(function (Builder $query) use ($termId): void {
                $query->whereNull('term_id')
                    ->orWhere('term_id', $termId);
            })
            ->get()
            ->groupBy('code')
            ->map(fn (Collection $rules): FeeRule => $rules
                ->sort(fn (FeeRule $left, FeeRule $right): int => $this->compareRulePriority(
                    $left,
                    $right,
                    $programId,
                    $termId,
                ))
                ->first())
            ->values();
    }

    private function exactDownpaymentRule(Enrollment $enrollment, string $effectiveDate): ?FeeRule
    {
        $studentProfile = $enrollment->studentProfile;

        if (! $studentProfile instanceof StudentProfile) {
            throw ValidationException::withMessages([
                'program' => 'Assessment generation requires a student program scope.',
            ]);
        }

        return FeeRule::query()
            ->where('is_active', true)
            ->where('display_category', FeeRule::DisplayCategoryDownpayment)
            ->where('program_id', $studentProfile->program_id)
            ->where('term_id', $enrollment->term_id)
            ->whereNotNull('amount')
            ->whereDate('effective_from', '<=', $effectiveDate)
            ->where(function (Builder $query) use ($effectiveDate): void {
                $query->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $effectiveDate);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    private function compareRulePriority(
        FeeRule $left,
        FeeRule $right,
        int $programId,
        int $termId,
    ): int {
        $scopeComparison = $this->scopeScore($right, $programId, $termId)
            <=> $this->scopeScore($left, $programId, $termId);

        if ($scopeComparison !== 0) {
            return $scopeComparison;
        }

        $leftEffectiveDate = $left->getRawOriginal('effective_from');
        $rightEffectiveDate = $right->getRawOriginal('effective_from');

        if (! is_string($leftEffectiveDate) || ! is_string($rightEffectiveDate)) {
            throw new LogicException('Fee-rule effective dates must be stored as canonical date strings.');
        }

        $effectiveDateComparison = $rightEffectiveDate <=> $leftEffectiveDate;

        if ($effectiveDateComparison !== 0) {
            return $effectiveDateComparison;
        }

        return $right->id <=> $left->id;
    }

    private function scopeScore(FeeRule $rule, int $programId, int $termId): int
    {
        $ruleProgramId = $rule->getAttribute('program_id');
        $ruleTermId = $rule->getAttribute('term_id');
        $matchesProgram = $ruleProgramId !== null && (int) $ruleProgramId === $programId;
        $matchesTerm = $ruleTermId !== null && (int) $ruleTermId === $termId;
        $globalProgram = $ruleProgramId === null;
        $globalTerm = $ruleTermId === null;

        return match (true) {
            $matchesProgram && $matchesTerm => 4,
            $globalProgram && $matchesTerm => 3,
            $matchesProgram && $globalTerm => 2,
            default => 1,
        };
    }

    /**
     * @param  Collection<int, CourseEnrollment>  $courseEnrollments
     * @return list<array<string, mixed>>
     */
    private function linePayloads(Assessment $assessment, FeeRule $rule, Collection $courseEnrollments): array
    {
        if ($rule->calculation_type === FeeRule::CalculationPerUnit) {
            return $courseEnrollments
                ->map(function (CourseEnrollment $courseEnrollment) use ($assessment, $rule): array {
                    $quantity = $this->unitsFor($courseEnrollment);
                    $rate = $this->money->normalize((string) $rule->rate);

                    return [
                        'assessment_id' => $assessment->id,
                        'fee_rule_id' => $rule->id,
                        'course_enrollment_id' => $courseEnrollment->id,
                        'source_line_key' => "{$rule->code}:course:{$courseEnrollment->id}",
                        'description_snapshot' => $this->courseLineDescription($rule, $courseEnrollment),
                        'quantity' => $quantity,
                        'rate' => $rate,
                        'amount' => $this->multiplyQuantity($quantity, $rate),
                        'line_type' => $rule->display_category,
                    ];
                })
                ->all();
        }

        $amount = $this->money->normalize((string) $rule->amount);

        return [[
            'assessment_id' => $assessment->id,
            'fee_rule_id' => $rule->id,
            'course_enrollment_id' => null,
            'source_line_key' => "fee:{$rule->code}",
            'description_snapshot' => $rule->name,
            'quantity' => '1.0000',
            'rate' => $amount,
            'amount' => $amount,
            'line_type' => $rule->display_category,
        ]];
    }

    private function unitsFor(CourseEnrollment $courseEnrollment): string
    {
        $units = $courseEnrollment->units_snapshot
            ?? $courseEnrollment->termOffering?->curriculumEntry?->courseSpecification?->credit_units;

        if ($units === null) {
            throw ValidationException::withMessages([
                'units' => 'Per-unit assessment requires authoritative enrolled course units.',
            ]);
        }

        return number_format((float) $units, 4, '.', '');
    }

    private function multiplyQuantity(string $quantity, string $rate): string
    {
        $cents = (int) round(((float) $quantity) * $this->money->toCents($rate));

        return $this->money->fromCents($cents);
    }

    private function courseLineDescription(FeeRule $rule, CourseEnrollment $courseEnrollment): string
    {
        $offering = $courseEnrollment->termOffering;
        $specification = $offering?->curriculumEntry?->courseSpecification;
        $course = $specification?->course;

        return collect([
            $rule->name,
            $course?->code,
            $specification?->title,
        ])->filter()->implode(' - ');
    }

    private function nextVersion(Enrollment $enrollment): int
    {
        return ((int) Assessment::query()
            ->where('enrollment_id', $enrollment->id)
            ->max('version')) + 1;
    }
}
