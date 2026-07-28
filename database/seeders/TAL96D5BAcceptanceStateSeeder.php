<?php

namespace Database\Seeders;

use App\Actions\Enrollment\EnrollmentAssessmentService;
use App\Actions\Finance\PaymentConfirmationService;
use App\Models\AdmissionRequirementPolicy;
use App\Models\ApplicantIntake;
use App\Models\Assessment;
use App\Models\ChecklistItem;
use App\Models\CourseEnrollment;
use App\Models\DocumentEvidence;
use App\Models\Enrollment;
use App\Models\FeeRule;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Program;
use App\Models\SchedulingDemand;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Adds deterministic TAL-96D5B operational states to the existing MIDDLE fixture.
 *
 * This opt-in test-only overlay is never called by DatabaseSeeder. It does not
 * create, replace, or resize MIN, MIDDLE, or MAX, and it never invokes CP-SAT.
 */
final class TAL96D5BAcceptanceStateSeeder extends Seeder
{
    public const ChargeRuleCode = 'TAL96D5B-OPERATING-TUITION';

    public function __construct(
        private readonly TAL96D4BAcceptanceStateSeeder $gradeAndLifecycleStates,
        private readonly EnrollmentAssessmentService $assessmentService,
        private readonly PaymentConfirmationService $paymentConfirmationService,
    ) {}

    public function run(): void
    {
        $term = $this->middleTerm();
        $this->assertMiddleSchedulingBaseline($term);

        $this->gradeAndLifecycleStates->run();

        $this->ensureApplicantAcceptanceStates($term);
        $this->ensureIrregularAwaitingPublication($term);
        $due = $this->ensureFinanceEnrollment('DIT-1A-001', $term);
        $partial = $this->ensureFinanceEnrollment('DIT-1A-002', $term);
        $cleared = $this->ensureFinanceEnrollment('DIT-2A-001', $term);
        $this->ensureCancelledEnrollment($term);

        $dueAssessment = $this->ensureActiveAssessment($due, $term);
        $partialAssessment = $this->ensureActiveAssessment($partial, $term);
        $clearedAssessment = $this->ensureActiveAssessment($cleared, $term);

        $this->ensureSyntheticPaymentAttempt(
            assessment: $dueAssessment,
            internalReference: 'TAL96D5B-SYNTHETIC-FAILED',
            status: 'failed',
        );
        $this->ensureSyntheticPaymentAttempt(
            assessment: $partialAssessment,
            internalReference: 'TAL96D5B-SYNTHETIC-PENDING',
            status: 'pending',
        );

        $this->ensureManualPayment(
            assessment: $partialAssessment,
            amount: '1000.00',
            reference: 'TAL96D5B-MANUAL-PARTIAL',
        );
        $this->ensureManualPayment(
            assessment: $clearedAssessment,
            amount: '2000.00',
            reference: 'TAL96D5B-MANUAL-CLEARED',
        );
    }

    private function ensureApplicantAcceptanceStates(Term $term): void
    {
        $program = Program::query()->where('code', 'DBM')->sole();
        $applicant = User::query()->where('email', 'applicant.demo@example.test')->sole();
        $historyTerm = Term::query()->firstOrCreate(
            [
                'academic_year_id' => $term->academic_year_id,
                'type' => Term::TypeFirstSemester,
                'label' => 'TAL-96D5B Admissions History',
            ],
            [
                'starts_on' => '2025-06-02',
                'ends_on' => '2025-10-31',
                'state' => Term::StateClosed,
                'scheduling_slot_minutes' => 30,
                'scheduling_days' => ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY'],
                'scheduling_day_starts_at' => '07:00:00',
                'scheduling_day_ends_at' => '21:00:00',
            ],
        );

        ApplicantIntake::query()->firstOrCreate(
            ['user_id' => $applicant->id, 'term_id' => $historyTerm->id],
            $this->applicantIntakeAttributes(
                applicant: $applicant,
                program: $program,
                status: ApplicantIntake::StatusWithdrawn,
                submittedAt: CarbonImmutable::parse('2025-06-03 09:00:00', config('app.timezone')),
                archivedAt: CarbonImmutable::parse('2025-06-04 10:00:00', config('app.timezone')),
            ),
        );
        ApplicantIntake::query()->firstOrCreate(
            ['user_id' => $applicant->id, 'term_id' => $term->id],
            $this->applicantIntakeAttributes(
                applicant: $applicant,
                program: $program,
                status: ApplicantIntake::StatusDraft,
            ),
        );

        $reviewApplicant = User::query()->firstOrCreate(
            ['email' => 'applicant.review.demo@example.test'],
            [
                'first_name' => 'Review',
                'middle_name' => null,
                'last_name' => 'Applicant',
                'username' => 'applicant-review-demo',
                'password' => 'password',
                'status' => User::StatusApplicantPending,
                'email_verified_at' => CarbonImmutable::parse('2025-12-01 08:00:00', config('app.timezone')),
            ],
        );
        $reviewApplicant->syncRoles(['applicant']);

        $reviewIntake = ApplicantIntake::query()->firstOrCreate(
            ['user_id' => $reviewApplicant->id, 'term_id' => $term->id],
            $this->applicantIntakeAttributes(
                applicant: $reviewApplicant,
                program: $program,
                status: ApplicantIntake::StatusPending,
                submittedAt: CarbonImmutable::parse('2026-01-06 09:00:00', config('app.timezone')),
            ),
        );
        $digitalPolicy = AdmissionRequirementPolicy::query()
            ->where('admission_category', ApplicantIntake::AdmissionCategoryFirstTimeCollege)
            ->where('credential_basis', ApplicantIntake::CredentialBasisSeniorHighSchool)
            ->where('requirement_type', 'IDENTITY_DOCUMENT')
            ->sole();
        $physicalPolicy = AdmissionRequirementPolicy::query()
            ->where('admission_category', ApplicantIntake::AdmissionCategoryFirstTimeCollege)
            ->where('credential_basis', ApplicantIntake::CredentialBasisSeniorHighSchool)
            ->where('requirement_type', 'FORM_137')
            ->sole();

        $digitalItem = ChecklistItem::query()->updateOrCreate(
            [
                'applicant_intake_id' => $reviewIntake->id,
                'requirement_type' => 'IDENTITY_DOCUMENT',
            ],
            [
                'owner_type' => ChecklistItem::OwnerApplicant,
                'student_profile_id' => null,
                'source_policy_id' => $digitalPolicy->id,
                'status' => ChecklistItem::StatusReceivedDigital,
                'blocking_level' => ChecklistItem::BlockingHandover,
                'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
                'verification_status' => ChecklistItem::VerificationNotReviewed,
            ],
        );
        ChecklistItem::query()->updateOrCreate(
            [
                'applicant_intake_id' => $reviewIntake->id,
                'requirement_type' => 'FORM_137',
            ],
            [
                'owner_type' => ChecklistItem::OwnerApplicant,
                'student_profile_id' => null,
                'source_policy_id' => $physicalPolicy->id,
                'status' => ChecklistItem::StatusPending,
                'blocking_level' => ChecklistItem::BlockingEnrollment,
                'evidence_method' => ChecklistItem::EvidenceMethodPhysicalCopy,
                'verification_status' => ChecklistItem::VerificationNotReviewed,
            ],
        );

        $path = 'tal96d5b-acceptance/applicant-review-identity.pdf';
        $contents = "%PDF-1.4\n% TALA synthetic acceptance evidence\n%%EOF\n";
        Storage::disk('local')->put($path, $contents);
        DocumentEvidence::query()->updateOrCreate(
            ['checklist_item_id' => $digitalItem->id, 'path' => $path],
            [
                'disk' => 'local',
                'checksum' => hash('sha256', $contents),
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($contents),
                'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
                'status' => DocumentEvidence::StatusSubmitted,
                'uploaded_by' => $reviewApplicant->id,
                'uploaded_at' => CarbonImmutable::parse('2026-01-06 09:00:00', config('app.timezone')),
                'reviewed_by' => null,
                'reviewed_at' => null,
                'replaces_document_evidence_id' => null,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function applicantIntakeAttributes(
        User $applicant,
        Program $program,
        string $status,
        ?CarbonImmutable $submittedAt = null,
        ?CarbonImmutable $archivedAt = null,
    ): array {
        return [
            'program_id' => $program->id,
            'admission_category' => ApplicantIntake::AdmissionCategoryFirstTimeCollege,
            'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
            'first_name' => $applicant->first_name,
            'middle_name' => $applicant->middle_name,
            'last_name' => $applicant->last_name,
            'birth_date' => '2007-01-15',
            'gender' => 'FEMALE',
            'civil_status' => 'SINGLE',
            'birth_place' => 'Synthetic City, Laguna',
            'email' => $applicant->email,
            'phone' => '09171234567',
            'address_barangay' => 'Synthetic Barangay',
            'address_street' => '100 Acceptance Street',
            'address_city' => 'Synthetic City',
            'address_province' => 'Laguna',
            'prior_school' => 'Synthetic Senior High School',
            'guardian_name' => 'Synthetic Guardian',
            'guardian_phone' => '09179876543',
            'guardian_address' => '100 Acceptance Street, Synthetic Barangay, Synthetic City, Laguna',
            'status' => $status,
            'submitted_at' => $submittedAt,
            'archived_at' => $archivedAt,
        ];
    }

    private function middleTerm(): Term
    {
        return Term::query()
            ->where('label', 'Second Semester')
            ->whereHas('academicYear', fn ($query) => $query->where('label', 'AY 2025-2026'))
            ->sole();
    }

    private function assertMiddleSchedulingBaseline(Term $term): void
    {
        $facultyCount = User::role(User::StaffRoleFaculty)->count();
        $demandCount = SchedulingDemand::query()
            ->whereHas('termOffering', fn ($query) => $query->whereBelongsTo($term))
            ->count();

        if (StudentProfile::query()->count() !== 270
            || TermOffering::query()->whereBelongsTo($term)->count() !== 80
            || $demandCount !== 80
            || $facultyCount !== 14) {
            throw new RuntimeException(
                'TAL-96D5B operational states require the verified MIDDLE fixture: 270 students, 80 offerings, 80 scheduling demands, and 14 faculty.',
            );
        }
    }

    private function ensureIrregularAwaitingPublication(Term $term): void
    {
        $profile = $this->profile('DBM-2A-001');

        Enrollment::query()->updateOrCreate(
            [
                'student_profile_id' => $profile->id,
                'term_id' => $term->id,
            ],
            [
                'status' => 'pending',
                'student_type' => 'irregular',
                'registered_at' => null,
                'officially_enrolled_at' => null,
                'cancelled_at' => null,
                'dropped_at' => null,
                'withdrawn_at' => null,
                'status_reason' => 'Awaiting compatible published sections for the irregular proposal.',
            ],
        );
    }

    private function ensureFinanceEnrollment(string $studentNumber, Term $term): Enrollment
    {
        $profile = $this->profile($studentNumber);
        $enrollment = Enrollment::query()->firstOrCreate(
            [
                'student_profile_id' => $profile->id,
                'term_id' => $term->id,
            ],
            [
                'status' => 'pending_payment',
                'student_type' => 'regular',
                'registered_at' => CarbonImmutable::parse($term->starts_on)->startOfDay(),
                'status_reason' => 'TAL-96D5B deterministic finance acceptance state.',
            ],
        );

        if (! in_array($enrollment->status, ['pending_payment', 'pre_enrolled'], true)) {
            $enrollment->forceFill([
                'status' => 'pending_payment',
                'student_type' => 'regular',
                'officially_enrolled_at' => null,
                'cancelled_at' => null,
                'dropped_at' => null,
                'withdrawn_at' => null,
                'status_reason' => 'TAL-96D5B deterministic finance acceptance state.',
            ])->save();
        }

        $offering = TermOffering::query()
            ->with('curriculumEntry.courseSpecification')
            ->whereBelongsTo($term)
            ->whereHas(
                'curriculumEntry',
                fn ($query) => $query->where('curriculum_version_id', $profile->curriculum_version_id),
            )
            ->oldest('id')
            ->firstOrFail();
        $specification = $offering->curriculumEntry?->courseSpecification;

        if ($specification === null) {
            throw new RuntimeException("{$studentNumber} has no matching MIDDLE course specification.");
        }

        CourseEnrollment::query()->updateOrCreate(
            [
                'enrollment_id' => $enrollment->id,
                'term_offering_id' => $offering->id,
            ],
            [
                'proposed_section_id' => null,
                'proposed_at' => null,
                'status' => CourseEnrollment::StatusActive,
                'units_snapshot' => $specification->credit_units,
                'added_at' => CarbonImmutable::parse($term->starts_on)->startOfDay(),
                'dropped_at' => null,
                'withdrawn_at' => null,
                'status_reason' => 'TAL-96D5B deterministic finance acceptance state.',
            ],
        );

        return $enrollment->refresh();
    }

    private function ensureCancelledEnrollment(Term $term): void
    {
        $profile = $this->profile('DTHM-1A-001');
        $cancelledAt = CarbonImmutable::parse($term->starts_on)->addDay();

        Enrollment::query()->updateOrCreate(
            [
                'student_profile_id' => $profile->id,
                'term_id' => $term->id,
            ],
            [
                'status' => 'cancelled',
                'student_type' => 'regular',
                'registered_at' => null,
                'officially_enrolled_at' => null,
                'cancelled_at' => $cancelledAt,
                'dropped_at' => null,
                'withdrawn_at' => null,
                'status_reason' => 'Synthetic terminal enrollment used to verify restart guidance.',
            ],
        );
    }

    private function ensureActiveAssessment(Enrollment $enrollment, Term $term): Assessment
    {
        $active = Assessment::query()
            ->whereBelongsTo($enrollment)
            ->where('state', Assessment::StateActive)
            ->first();

        if ($active instanceof Assessment) {
            return $active;
        }

        $program = $enrollment->studentProfile()->with('program')->firstOrFail()->program;

        FeeRule::query()->updateOrCreate(
            [
                'code' => self::ChargeRuleCode.'-'.$program->code,
                'term_id' => $term->id,
            ],
            [
                'name' => 'TAL-96D5B '.$program->code.' Operating Tuition',
                'ledger_category' => FeeRule::LedgerCategoryCharge,
                'display_category' => FeeRule::DisplayCategoryTuition,
                'program_id' => $program->id,
                'calculation_type' => FeeRule::CalculationFixed,
                'amount' => '3000.00',
                'rate' => null,
                'effective_from' => $term->starts_on,
                'effective_until' => $term->ends_on,
                'is_active' => true,
                'authority' => 'TAL-96D5B synthetic operational-state overlay.',
            ],
        );

        $accounting = User::query()
            ->where('email', 'accounting.demo@example.test')
            ->sole();
        $effectiveAt = CarbonImmutable::parse($term->starts_on)->startOfDay();
        $assessment = $this->assessmentService->generateDraft($enrollment->refresh(), $accounting, $effectiveAt);

        return $this->assessmentService->activate($assessment, $accounting, $effectiveAt);
    }

    private function ensureSyntheticPaymentAttempt(
        Assessment $assessment,
        string $internalReference,
        string $status,
    ): void {
        PaymentAttempt::query()->updateOrCreate(
            ['internal_reference' => $internalReference],
            [
                'assessment_id' => $assessment->id,
                'student_profile_id' => $assessment->enrollment->student_profile_id,
                'channel' => 'synthetic_acceptance',
                'provider' => 'synthetic_acceptance',
                'provider_checkout_id' => null,
                'provider_intent_id' => null,
                'amount' => $assessment->required_downpayment,
                'currency' => 'PHP',
                'status' => $status,
                'expires_at' => null,
                'paid_at' => null,
                'metadata' => [
                    'purpose' => 'TAL-96D5B local state projection only',
                    'provider_evidence' => 'not evaluated',
                ],
            ],
        );
    }

    private function ensureManualPayment(Assessment $assessment, string $amount, string $reference): void
    {
        if (Payment::query()->where('provider_reference', $reference)->exists()) {
            return;
        }

        $accounting = User::query()
            ->where('email', 'accounting.demo@example.test')
            ->sole();

        $this->paymentConfirmationService->confirmManualPayment(
            enrollmentId: $assessment->enrollment_id,
            amount: $amount,
            channel: 'cash',
            paymentReference: $reference,
            actor: $accounting,
            confirmedAt: CarbonImmutable::parse($assessment->enrollment->term->starts_on)->addDays(2),
        );
    }

    private function profile(string $studentNumber): StudentProfile
    {
        return StudentProfile::query()
            ->where('student_number', $studentNumber)
            ->sole();
    }
}
