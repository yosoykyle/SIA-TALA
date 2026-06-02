<?php

namespace App\Actions\Enrollment;

use App\Models\Enrollment;
use App\Models\FeeTemplate;
use App\Models\LedgerEntry;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EnrollmentAssessmentService
{
    public function __construct(private readonly DecimalMoney $money) {}

    /**
     * @return array{enrollment_id:int, fee_template_id:int|null, gross_assessment:string, discount_amount:string, net_assessment:string, current_balance:string, already_assessed:bool}
     *
     * @throws AuthorizationException
     */
    public function assess(int $enrollmentId, User $actor, ?CarbonImmutable $postedAt = null): array
    {
        if (! $actor->can('create-assessments')) {
            throw new AuthorizationException('Only Accounting/Cashier can create enrollment assessments.');
        }

        $timestamp = $postedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($enrollmentId, $actor, $timestamp): array {
            $enrollment = Enrollment::query()
                ->with(['studentProfile'])
                ->lockForUpdate()
                ->findOrFail($enrollmentId);

            $studentProfile = StudentProfile::query()
                ->lockForUpdate()
                ->findOrFail($enrollment->student_profile_id);

            if ($this->hasAssessmentEntries($enrollment)) {
                return $this->summaryForExistingAssessment($enrollment, $studentProfile, true);
            }

            $feeTemplate = $this->resolveFeeTemplate($enrollment, $studentProfile);

            if (! $feeTemplate instanceof FeeTemplate) {
                throw new RuntimeException('No active fee template matches this enrollment scope.');
            }

            $currentBalance = $this->money->normalize((string) $studentProfile->current_balance);
            $grossAssessment = '0.00';
            $discountAmount = '0.00';

            foreach ($this->assessmentLines($feeTemplate) as $line) {
                if (! $this->money->greaterThanZero($line['amount'])) {
                    continue;
                }

                $grossAssessment = $this->money->add($grossAssessment, $line['amount']);
                $currentBalance = $this->postLedgerEntry(
                    enrollment: $enrollment,
                    studentProfile: $studentProfile,
                    entryType: 'assessment',
                    description: $line['description'],
                    amount: $line['amount'],
                    runningBalance: $currentBalance,
                    referenceType: 'fee_template',
                    referenceId: $feeTemplate->id,
                    actor: $actor,
                    postedAt: $timestamp,
                );
            }

            if ($enrollment->isFreshmenDiscountEligible() && $this->money->greaterThanZero((string) $feeTemplate->tuition_fee)) {
                $discountAmount = $this->money->multiplyPercent((string) $feeTemplate->tuition_fee, '50.00');
                $negativeDiscount = $this->money->subtract('0.00', $discountAmount);

                $currentBalance = $this->postLedgerEntry(
                    enrollment: $enrollment,
                    studentProfile: $studentProfile,
                    entryType: 'discount',
                    description: 'Automated Freshmen Discount - 50% Tuition Fee',
                    amount: $negativeDiscount,
                    runningBalance: $currentBalance,
                    referenceType: 'fee_template',
                    referenceId: $feeTemplate->id,
                    actor: $actor,
                    postedAt: $timestamp,
                );
            }

            $enrollment->forceFill([
                'enrolled_at' => $enrollment->enrolled_at ?? $timestamp,
            ])->save();

            $studentProfile->forceFill([
                'current_balance' => $currentBalance,
            ])->save();

            $this->recordAssessmentAudit($enrollment, $feeTemplate, $grossAssessment, $discountAmount, $actor, $timestamp);

            return [
                'enrollment_id' => $enrollment->id,
                'fee_template_id' => $feeTemplate->id,
                'gross_assessment' => $grossAssessment,
                'discount_amount' => $discountAmount,
                'net_assessment' => $this->money->subtract($grossAssessment, $discountAmount),
                'current_balance' => $currentBalance,
                'already_assessed' => false,
            ];
        });
    }

    private function hasAssessmentEntries(Enrollment $enrollment): bool
    {
        return LedgerEntry::query()
            ->where('enrollment_id', $enrollment->id)
            ->whereIn('entry_type', ['assessment', 'discount'])
            ->exists();
    }

    /**
     * @return array{enrollment_id:int, fee_template_id:int|null, gross_assessment:string, discount_amount:string, net_assessment:string, current_balance:string, already_assessed:bool}
     */
    private function summaryForExistingAssessment(Enrollment $enrollment, StudentProfile $studentProfile, bool $alreadyAssessed): array
    {
        $grossAssessment = $this->money->normalize((string) LedgerEntry::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('entry_type', 'assessment')
            ->sum('amount'));

        $discountAmount = $this->money->normalize((string) abs((float) LedgerEntry::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('entry_type', 'discount')
            ->sum('amount')));

        $feeTemplateId = LedgerEntry::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('reference_type', 'fee_template')
            ->value('reference_id');

        return [
            'enrollment_id' => $enrollment->id,
            'fee_template_id' => $feeTemplateId !== null ? (int) $feeTemplateId : null,
            'gross_assessment' => $grossAssessment,
            'discount_amount' => $discountAmount,
            'net_assessment' => $this->money->subtract($grossAssessment, $discountAmount),
            'current_balance' => $this->money->normalize((string) $studentProfile->current_balance),
            'already_assessed' => $alreadyAssessed,
        ];
    }

    private function resolveFeeTemplate(Enrollment $enrollment, StudentProfile $studentProfile): ?FeeTemplate
    {
        return FeeTemplate::query()
            ->where('is_active', true)
            ->where('education_level', $studentProfile->education_level)
            ->where(function ($query) use ($studentProfile): void {
                $query->whereNull('program_id');

                if ($studentProfile->program_id !== null) {
                    $query->orWhere('program_id', $studentProfile->program_id);
                }
            })
            ->where(function ($query) use ($enrollment): void {
                $query->whereNull('year_level');

                if ($enrollment->year_level !== null) {
                    $query->orWhere('year_level', $enrollment->year_level);
                }
            })
            ->orderByRaw('CASE WHEN program_id IS NULL THEN 0 ELSE 1 END DESC')
            ->orderByRaw('CASE WHEN year_level IS NULL THEN 0 ELSE 1 END DESC')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<int, array{description:string, amount:string}>
     */
    private function assessmentLines(FeeTemplate $feeTemplate): array
    {
        return [
            ['description' => 'Tuition Fee', 'amount' => $this->money->normalize((string) $feeTemplate->tuition_fee)],
            ['description' => 'Laboratory Fee', 'amount' => $this->money->normalize((string) $feeTemplate->laboratory_fee)],
            ['description' => 'Misc. Fee', 'amount' => $this->money->normalize((string) $feeTemplate->misc_fee)],
            ['description' => 'Other Fee', 'amount' => $this->money->normalize((string) $feeTemplate->other_fee)],
        ];
    }

    private function postLedgerEntry(
        Enrollment $enrollment,
        StudentProfile $studentProfile,
        string $entryType,
        string $description,
        string $amount,
        string $runningBalance,
        string $referenceType,
        int $referenceId,
        User $actor,
        CarbonImmutable $postedAt,
    ): string {
        $newBalance = $this->money->add($runningBalance, $amount);

        LedgerEntry::query()->create([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $enrollment->term_id,
            'enrollment_id' => $enrollment->id,
            'entry_type' => $entryType,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'description' => $description,
            'amount' => $amount,
            'running_balance' => $newBalance,
            'posted_at' => $postedAt,
            'posted_by' => $actor->id,
        ]);

        return $newBalance;
    }

    private function recordAssessmentAudit(
        Enrollment $enrollment,
        FeeTemplate $feeTemplate,
        string $grossAssessment,
        string $discountAmount,
        User $actor,
        CarbonImmutable $recordedAt,
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'enrollment_assessment',
            'description' => 'Enrollment assessment posted.',
            'subject_type' => Enrollment::class,
            'subject_id' => $enrollment->id,
            'event' => 'enrollment_assessed',
            'causer_type' => User::class,
            'causer_id' => $actor->id,
            'properties' => json_encode([
                'fee_template_id' => $feeTemplate->id,
                'gross_assessment' => $grossAssessment,
                'discount_amount' => $discountAmount,
                'net_assessment' => $this->money->subtract($grossAssessment, $discountAmount),
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $recordedAt->toDateTimeString(),
            'updated_at' => $recordedAt->toDateTimeString(),
        ]);
    }
}
