<?php

namespace App\Actions\Integrations\Payments;

use App\Actions\Enrollment\EnrollmentAssessmentService;
use App\Actions\Finance\FinanceEvidenceService;
use App\Models\Assessment;
use App\Models\AssessmentLine;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\FeeRule;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentScheduleRow;
use App\Models\SchedulingDemand;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Database\Seeders\ClientAlignedAcceptanceBaselineSeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PreparePayMongoDemoFixture
{
    public const ChargeRuleCode = 'TAL96C-DEMO-CHARGE';

    public const StudentEmail = 'student.demo@example.test';

    public const AccountingEmail = 'accounting.demo@example.test';

    public function __construct(
        private readonly ClientAlignedAcceptanceBaselineSeeder $baselineSeeder,
        private readonly EnrollmentAssessmentService $assessmentService,
        private readonly FinanceEvidenceService $financeEvidence,
        private readonly DecimalMoney $money,
    ) {}

    /**
     * @return array{outcome:string,student:string,enrollment_id:int,assessment_id:int,course_enrollment_id:int,amount_due:string,readiness:string}
     */
    public function prepare(): array
    {
        return DB::transaction(function (): array {
            $existingFixture = $this->fixtureResult('already_present');

            if ($existingFixture !== null) {
                return $existingFixture;
            }

            if ($this->fixtureArtifactsExist()) {
                throw $this->conflictException();
            }

            $baselineState = $this->baselineSeeder->state();

            if ($baselineState === ClientAlignedAcceptanceBaselineSeeder::StateConflict) {
                throw $this->conflictException();
            }

            if ($baselineState === ClientAlignedAcceptanceBaselineSeeder::StateEmpty) {
                $this->baselineSeeder->run();
            }

            if ($this->baselineSeeder->state() !== ClientAlignedAcceptanceBaselineSeeder::StateComplete
                || ! $this->baselineSeeder->readinessPasses()) {
                throw new RuntimeException('The client acceptance baseline is not complete and scheduling-ready. No demo fixture was created.');
            }

            $this->createFixture();

            return $this->fixtureResult('created')
                ?? throw new RuntimeException('The PayMongo demo fixture did not satisfy its exact readiness contract.');
        }, attempts: 3);
    }

    private function createFixture(): void
    {
        $student = User::query()
            ->where('email', self::StudentEmail)
            ->sole();
        $studentProfile = StudentProfile::query()
            ->whereBelongsTo($student)
            ->sole();
        $accounting = User::query()
            ->where('email', self::AccountingEmail)
            ->sole();
        $term = Term::query()->sole();
        $offering = TermOffering::query()
            ->with('curriculumEntry.courseSpecification')
            ->whereBelongsTo($term)
            ->whereHas('curriculumEntry', fn ($query) => $query
                ->where('curriculum_version_id', $studentProfile->curriculum_version_id))
            ->oldest('id')
            ->firstOrFail();
        $courseSpecification = $offering->curriculumEntry?->courseSpecification;

        if (! $student->hasVerifiedEmail()
            || ! $student->hasRole('student')
            || ! $accounting->hasRole(User::StaffRoleAccounting)
            || $courseSpecification === null) {
            throw new RuntimeException('The representative student, Accounting actor, or matching client-baseline offering is unavailable.');
        }

        $effectiveAt = CarbonImmutable::parse((string) $term->starts_on, config('app.timezone'))->startOfDay();

        FeeRule::query()->create([
            'code' => self::ChargeRuleCode,
            'name' => 'TAL-96C PayMongo Demonstration Charge',
            'ledger_category' => FeeRule::LedgerCategoryCharge,
            'display_category' => FeeRule::DisplayCategoryTuition,
            'program_id' => $studentProfile->program_id,
            'term_id' => $term->id,
            'calculation_type' => FeeRule::CalculationFixed,
            'amount' => '2000.00',
            'rate' => null,
            'effective_from' => $term->starts_on,
            'effective_until' => $term->ends_on,
            'is_active' => true,
            'authority' => 'TAL-96C test-only PayMongo demonstration fixture.',
        ]);

        $enrollment = Enrollment::query()->create([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $term->id,
            'status' => 'pending_payment',
            'student_type' => 'regular',
            'registered_at' => $effectiveAt,
            'officially_enrolled_at' => null,
            'status_reason' => 'TAL-96C test-only PayMongo demonstration fixture.',
        ]);

        CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => $courseSpecification->credit_units,
            'added_at' => $effectiveAt,
            'status_reason' => 'TAL-96C test-only PayMongo demonstration fixture.',
        ]);

        $assessment = $this->assessmentService->generateDraft($enrollment, $accounting, $effectiveAt);
        $this->assessmentService->activate($assessment, $accounting, $effectiveAt);
    }

    /**
     * @return array{outcome:string,student:string,enrollment_id:int,assessment_id:int,course_enrollment_id:int,amount_due:string,readiness:string}|null
     */
    private function fixtureResult(string $outcome): ?array
    {
        if (! $this->fixtureCountsAreExact()) {
            return null;
        }

        $student = User::query()->where('email', self::StudentEmail)->first();
        $accounting = User::query()->where('email', self::AccountingEmail)->first();
        $profile = $student instanceof User
            ? StudentProfile::query()->whereBelongsTo($student)->first()
            : null;
        $term = Term::query()->first();
        $enrollment = $profile instanceof StudentProfile && $term instanceof Term
            ? Enrollment::query()
                ->whereBelongsTo($profile)
                ->whereBelongsTo($term)
                ->first()
            : null;
        $courseEnrollment = $enrollment instanceof Enrollment
            ? CourseEnrollment::query()->with('termOffering.curriculumEntry')->whereBelongsTo($enrollment)->first()
            : null;
        $chargeRule = FeeRule::query()->where('code', self::ChargeRuleCode)->first();
        $assessment = $enrollment instanceof Enrollment
            ? Assessment::query()->whereBelongsTo($enrollment)->first()
            : null;
        $line = $assessment instanceof Assessment
            ? AssessmentLine::query()->whereBelongsTo($assessment)->first()
            : null;
        $scheduleRow = $assessment instanceof Assessment
            ? PaymentScheduleRow::query()->whereBelongsTo($assessment)->first()
            : null;
        $chargeLedger = $line instanceof AssessmentLine
            ? LedgerEntry::query()
                ->where('source_type', AssessmentLine::class)
                ->where('source_id', $line->id)
                ->where('direction', LedgerEntry::DirectionCharge)
                ->first()
            : null;

        if (! $student instanceof User
            || ! $student->hasVerifiedEmail()
            || ! $student->hasRole('student')
            || ! $accounting instanceof User
            || ! $accounting->hasRole(User::StaffRoleAccounting)
            || ! $profile instanceof StudentProfile
            || ! $term instanceof Term
            || ! $enrollment instanceof Enrollment
            || $enrollment->status !== 'pending_payment'
            || $enrollment->student_type !== 'regular'
            || ! $courseEnrollment instanceof CourseEnrollment
            || $courseEnrollment->status !== CourseEnrollment::StatusActive
            || $courseEnrollment->termOffering->term_id !== $term->id
            || $courseEnrollment->termOffering->curriculumEntry?->curriculum_version_id !== $profile->curriculum_version_id
            || ! $chargeRule instanceof FeeRule
            || $chargeRule->name !== 'TAL-96C PayMongo Demonstration Charge'
            || $chargeRule->ledger_category !== FeeRule::LedgerCategoryCharge
            || $chargeRule->display_category !== FeeRule::DisplayCategoryTuition
            || $chargeRule->program_id !== $profile->program_id
            || $chargeRule->term_id !== $term->id
            || $chargeRule->calculation_type !== FeeRule::CalculationFixed
            || ! $this->amountMatches($chargeRule->amount, '2000.00')
            || $chargeRule->rate !== null
            || ! $chargeRule->is_active
            || $chargeRule->authority !== 'TAL-96C test-only PayMongo demonstration fixture.'
            || ! $assessment instanceof Assessment
            || $assessment->state !== Assessment::StateActive
            || ! $this->amountMatches($assessment->subtotal, '2000.00')
            || ! $this->amountMatches($assessment->total, '2000.00')
            || ! $this->amountMatches($assessment->required_downpayment, '2000.00')
            || $assessment->activated_by !== $accounting->id
            || ! $line instanceof AssessmentLine
            || $line->fee_rule_id !== $chargeRule->id
            || ! $this->amountMatches($line->amount, '2000.00')
            || ! $scheduleRow instanceof PaymentScheduleRow
            || $scheduleRow->state !== PaymentScheduleRow::StateDue
            || ! $this->amountMatches($scheduleRow->amount, '2000.00')
            || ! $chargeLedger instanceof LedgerEntry
            || $chargeLedger->student_profile_id !== $profile->id
            || $chargeLedger->term_id !== $term->id
            || $chargeLedger->enrollment_id !== $enrollment->id
            || ! $this->amountMatches($chargeLedger->amount, '2000.00')
            || $chargeLedger->posted_by !== $accounting->id
            || ! $this->baselineSeeder->readinessPasses()) {
            return null;
        }

        $finance = $this->financeEvidence->studentFinance($student);

        if (($finance['available'] ?? false) !== true
            || ($finance['current_due_amount'] ?? null) !== '2000.00') {
            return null;
        }

        return [
            'outcome' => $outcome,
            'student' => self::StudentEmail,
            'enrollment_id' => (int) $enrollment->id,
            'assessment_id' => (int) $assessment->id,
            'course_enrollment_id' => (int) $courseEnrollment->id,
            'amount_due' => '2000.00',
            'readiness' => 'PASS',
        ];
    }

    private function fixtureCountsAreExact(): bool
    {
        return User::query()->count() === 64
            && StudentProfile::query()->count() === 47
            && TermOffering::query()->count() === 54
            && SchedulingDemand::query()->count() === 54
            && FeeRule::query()->count() === 4
            && Enrollment::query()->count() === 1
            && CourseEnrollment::query()->count() === 1
            && Assessment::query()->count() === 1
            && AssessmentLine::query()->count() === 1
            && PaymentScheduleRow::query()->count() === 1
            && LedgerEntry::query()->count() === 1
            && PaymentAttempt::query()->doesntExist()
            && Payment::query()->doesntExist()
            && ! DB::table('webhook_calls')->exists();
    }

    private function fixtureArtifactsExist(): bool
    {
        return FeeRule::query()->where('code', self::ChargeRuleCode)->exists()
            || Enrollment::query()->exists()
            || CourseEnrollment::query()->exists()
            || Assessment::query()->exists()
            || AssessmentLine::query()->exists()
            || PaymentScheduleRow::query()->exists()
            || LedgerEntry::query()->exists()
            || PaymentAttempt::query()->exists()
            || Payment::query()->exists()
            || DB::table('webhook_calls')->exists();
    }

    private function amountMatches(string|int|float|null $actual, string $expected): bool
    {
        return $actual !== null && $this->money->normalize($actual) === $expected;
    }

    private function conflictException(): RuntimeException
    {
        return new RuntimeException(
            'The PayMongo demo fixture found partial, changed, paid, or conflicting operational data. No writes were made; rebuild only test_tala_db before preparing a fresh demo fixture.',
        );
    }
}
