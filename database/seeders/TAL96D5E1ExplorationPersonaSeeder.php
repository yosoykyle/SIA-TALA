<?php

namespace Database\Seeders;

use App\Actions\Applicants\AdmissionRequirementResolver;
use App\Actions\Finance\AccountingAdjustmentService;
use App\Actions\Finance\PaymentConfirmationService;
use App\Actions\Graduation\GraduationEligibilitySnapshotService;
use App\Actions\SystemAdministration\TAL96D5E1ExplorationPersonaCatalog;
use App\Models\AccountingAdjustment;
use App\Models\ApplicantIntake;
use App\Models\Assessment;
use App\Models\ChecklistItem;
use App\Models\CourseEnrollment;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\DocumentEvidence;
use App\Models\Enrollment;
use App\Models\FinancialAccommodation;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\GraduationReviewBatch;
use App\Models\GraduationReviewMember;
use App\Models\GraduationSnapshot;
use App\Models\Hold;
use App\Models\LedgerEntry;
use App\Models\OperationalEvent;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Adds deterministic presentation personas to the Canonical TALA Scheduling Dataset.
 *
 * This seeder has no application UI and never invokes CP-SAT or an external
 * provider. Its command guard limits execution to testing/MySQL/test_tala_db.
 */
final class TAL96D5E1ExplorationPersonaSeeder extends Seeder
{
    public function __construct(
        private readonly TAL96D5BAcceptanceStateSeeder $operationalStates,
        private readonly TAL96D5E1ExplorationPersonaCatalog $catalog,
        private readonly AdmissionRequirementResolver $requirements,
        private readonly PaymentConfirmationService $paymentConfirmation,
        private readonly AccountingAdjustmentService $accountingAdjustments,
    ) {}

    public function run(): void
    {
        $term = $this->presentationTerm();
        $this->ensureHistoricalCompletionPersonas();
        $this->operationalStates->run();

        $program = Program::query()->where('code', 'DBM')->sole();
        $registrar = User::query()->where('email', 'registrar.demo@example.test')->sole();

        $this->ensureStaffBoundaries();
        $this->ensureStudentVerificationBoundary();
        $this->ensurePriorTermStudentHistories($term, $registrar);
        $this->ensureApplicantPersonas($term, $program, $registrar);
        $this->ensureFinanceExplorationStates($term);
    }

    private function ensureHistoricalCompletionPersonas(): void
    {
        $definitions = [
            'student.completion.demo@example.test' => [
                'student_number' => 'DTHM-3A-001',
                'first_name' => 'Clarissa',
                'middle_name' => 'Mae',
                'last_name' => 'Dela Peña',
                'program' => 'DTHM',
                'standing' => StudentProfile::StandingCompletionCandidate,
                'birth_date' => '2003-04-18',
            ],
            'student.graduation.demo@example.test' => [
                'student_number' => 'DIT-3A-001',
                'first_name' => 'Roberto',
                'middle_name' => 'Luis',
                'last_name' => 'Magbanua',
                'program' => 'DIT',
                'standing' => StudentProfile::StandingGraduationCandidate,
                'birth_date' => '2003-09-07',
            ],
        ];

        foreach ($definitions as $email => $definition) {
            $program = Program::query()->where('code', $definition['program'])->sole();
            $curriculum = CurriculumVersion::query()
                ->whereBelongsTo($program)
                ->where('state', CurriculumVersion::StateActive)
                ->sole();
            $user = $this->ensureUser(
                email: $email,
                firstName: $definition['first_name'],
                lastName: $definition['last_name'],
                status: User::StatusActive,
                verified: true,
            );
            $user->forceFill(['middle_name' => $definition['middle_name']])->save();
            $user->syncRoles(['student']);

            StudentProfile::query()->updateOrCreate(
                ['student_number' => $definition['student_number']],
                [
                    'user_id' => $user->id,
                    'first_name' => $definition['first_name'],
                    'middle_name' => $definition['middle_name'],
                    'last_name' => $definition['last_name'],
                    'birth_date' => $definition['birth_date'],
                    'program_id' => $program->id,
                    'curriculum_version_id' => $curriculum->id,
                    'lifecycle_status' => StudentProfile::LifecycleInactive,
                    'academic_standing' => $definition['standing'],
                    'email' => $email,
                    'phone' => null,
                    'address' => 'San Pedro, Laguna',
                    'emergency_contact_name' => 'Designated family contact',
                    'emergency_contact_phone' => null,
                    'archived_at' => null,
                    'merged_into_id' => null,
                ],
            );
        }
    }

    private function ensureFinanceExplorationStates(Term $term): void
    {
        $accounting = User::query()->where('email', 'accounting.demo@example.test')->sole();
        $dueAssessment = $this->activeAssessmentFor('DIT-1A-001');
        $partialAssessment = $this->activeAssessmentFor('DIT-1A-002');
        $clearedAssessment = $this->activeAssessmentFor('DIT-2A-001');

        $expiredAttempt = $this->ensurePaymentAttempt(
            assessment: $dueAssessment,
            reference: 'CHECKOUT-EXPIRED-001',
            status: 'expired',
            timestamp: CarbonImmutable::parse($term->starts_on)->addDays(3),
        );
        $reviewAttempt = $this->ensurePaymentAttempt(
            assessment: $clearedAssessment,
            reference: 'CHECKOUT-REVIEW-001',
            status: 'under_review',
            timestamp: CarbonImmutable::parse($term->starts_on)->addDays(4),
        );

        $this->ensurePendingOfficialReceiptPayment($partialAssessment, $accounting);
        $this->ensureAccountingCorrections($dueAssessment, $accounting);
        $this->ensureFinancialAccommodations($dueAssessment, $partialAssessment, $accounting, $term);
        $this->ensurePayMongoEvidenceStates($expiredAttempt, $reviewAttempt, $term);
    }

    private function ensurePaymentAttempt(
        Assessment $assessment,
        string $reference,
        string $status,
        CarbonImmutable $timestamp,
    ): PaymentAttempt {
        return PaymentAttempt::query()->updateOrCreate(
            ['internal_reference' => $reference],
            [
                'assessment_id' => $assessment->id,
                'term_account_id' => $assessment->term_account_id,
                'student_profile_id' => $assessment->enrollment->student_profile_id,
                'assessment_version' => $assessment->version,
                'channel' => 'online_checkout',
                'provider' => 'paymongo',
                'provider_checkout_id' => null,
                'provider_intent_id' => null,
                'amount' => $assessment->required_downpayment,
                'currency' => 'PHP',
                'status' => $status,
                'expires_at' => $status === 'expired' ? $timestamp : $timestamp->addDay(),
                'paid_at' => null,
                'metadata' => [
                    'presentation_case' => 'payment_status_recovery',
                    'purpose' => 'Accounting and PayMongo first-time exploration',
                ],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        );
    }

    private function ensurePendingOfficialReceiptPayment(Assessment $assessment, User $accounting): void
    {
        if (Payment::query()->where('provider_reference', 'PAYMENT-OR-PENDING-001')->exists()) {
            return;
        }

        $this->paymentConfirmation->confirmManualPayment(
            enrollmentId: $assessment->enrollment_id,
            amount: '125.00',
            channel: 'bank_transfer',
            paymentReference: 'PAYMENT-OR-PENDING-001',
            actor: $accounting,
            confirmedAt: CarbonImmutable::parse($assessment->enrollment->term->starts_on)->addDays(5),
        );
    }

    private function ensureAccountingCorrections(Assessment $assessment, User $accounting): void
    {
        $charges = LedgerEntry::query()
            ->where('enrollment_id', $assessment->enrollment_id)
            ->where('direction', LedgerEntry::DirectionCharge)
            ->where('state', 'posted')
            ->oldest('id')
            ->get();
        $creditSource = $charges->first();
        $reversalSource = $charges->skip(1)->first() ?? $creditSource;

        if (! $creditSource instanceof LedgerEntry || ! $reversalSource instanceof LedgerEntry) {
            throw new RuntimeException('The presentation account requires a posted assessment charge.');
        }

        if (! AccountingAdjustment::query()->where('evidence_reference', 'ADJUSTMENT-CREDIT-001')->exists()) {
            $this->accountingAdjustments->post([
                'student_profile_id' => $assessment->enrollment->student_profile_id,
                'term_id' => $assessment->enrollment->term_id,
                'enrollment_id' => $assessment->enrollment_id,
                'source_ledger_entry_id' => $creditSource->id,
                'adjustment_type' => AccountingAdjustment::TypeStudentAccountCredit,
                'amount' => '25.00',
                'reason' => 'Approved credit applied after Accounting reviewed the supporting record.',
                'evidence_reference' => 'ADJUSTMENT-CREDIT-001',
            ], $accounting, CarbonImmutable::parse($assessment->enrollment->term->starts_on)->addDays(6));
        }

        if (! AccountingAdjustment::query()->where('evidence_reference', 'ADJUSTMENT-REVERSAL-001')->exists()) {
            $this->accountingAdjustments->post([
                'student_profile_id' => $assessment->enrollment->student_profile_id,
                'term_id' => $assessment->enrollment->term_id,
                'enrollment_id' => $assessment->enrollment_id,
                'source_ledger_entry_id' => $reversalSource->id,
                'adjustment_type' => AccountingAdjustment::TypeLedgerEntryReversal,
                'reason' => 'Approved reversal preserving the original account entry and correction history.',
                'evidence_reference' => 'ADJUSTMENT-REVERSAL-001',
            ], $accounting, CarbonImmutable::parse($assessment->enrollment->term->starts_on)->addDays(7));
        }
    }

    private function ensureFinancialAccommodations(
        Assessment $activeAssessment,
        Assessment $expiredAssessment,
        User $accounting,
        Term $term,
    ): void {
        FinancialAccommodation::query()->updateOrCreate(
            ['certification_reference' => 'ACCOMMODATION-ACTIVE-001'],
            [
                'student_profile_id' => $activeAssessment->enrollment->student_profile_id,
                'term_id' => $term->id,
                'balance_snapshot' => $activeAssessment->total,
                'covered_amount' => '500.00',
                'basis' => FinancialAccommodation::BasisInstitutionalAccommodation,
                'private_evidence_reference' => 'private://tal96d5e1c/active-accommodation',
                'promissory_required' => true,
                'promissory_maker' => 'Student account holder',
                'allows_finance_gate' => true,
                'allows_next_term_enrollment' => false,
                'allows_reactivation' => false,
                'allows_record_release' => false,
                'waives_downpayment' => false,
                'authority' => 'Accounting-approved payment accommodation',
                'recorded_by' => $accounting->id,
                'status' => FinancialAccommodation::StatusActive,
                'effective_from' => CarbonImmutable::parse($term->starts_on)->toDateString(),
                'expires_on' => CarbonImmutable::parse($term->starts_on)->addMonths(5)->toDateString(),
            ],
        );

        FinancialAccommodation::query()->updateOrCreate(
            ['certification_reference' => 'ACCOMMODATION-EXPIRED-001'],
            [
                'student_profile_id' => $expiredAssessment->enrollment->student_profile_id,
                'term_id' => $term->id,
                'balance_snapshot' => $expiredAssessment->total,
                'covered_amount' => '300.00',
                'basis' => FinancialAccommodation::BasisDswDLguCertification,
                'private_evidence_reference' => 'private://tal96d5e1c/expired-accommodation',
                'promissory_required' => false,
                'promissory_maker' => null,
                'allows_finance_gate' => true,
                'allows_next_term_enrollment' => false,
                'allows_reactivation' => false,
                'allows_record_release' => false,
                'waives_downpayment' => false,
                'authority' => 'Expired Accounting payment accommodation',
                'recorded_by' => $accounting->id,
                'status' => FinancialAccommodation::StatusExpired,
                'effective_from' => CarbonImmutable::parse($term->starts_on)->subYear()->toDateString(),
                'expires_on' => CarbonImmutable::parse($term->starts_on)->subMonth()->toDateString(),
            ],
        );
    }

    private function ensurePayMongoEvidenceStates(
        PaymentAttempt $expiredAttempt,
        PaymentAttempt $reviewAttempt,
        Term $term,
    ): void {
        $timestamp = CarbonImmutable::parse($term->starts_on)->addDays(8);

        OperationalEvent::query()->updateOrCreate(
            [
                'event_domain' => OperationalEvent::DomainIntegration,
                'external_id' => 'tal96d5e1c-open-exception',
            ],
            [
                'integration' => OperationalEvent::IntegrationPayMongo,
                'channel' => OperationalEvent::ChannelWebhook,
                'direction' => OperationalEvent::DirectionInbound,
                'event_type' => 'payment.paid',
                'event_version' => 'v1',
                'status' => OperationalEvent::StatusReviewRequired,
                'occurred_at' => $timestamp,
                'processed_at' => null,
                'failed_at' => null,
                'related_record_type' => PaymentAttempt::class,
                'related_record_id' => $reviewAttempt->id,
                'diagnostics' => [
                    'reason' => 'unknown_reference',
                    'presentation_case' => 'payment_status_recovery',
                ],
                'payload' => [
                    'livemode' => false,
                    'redacted' => true,
                ],
            ],
        );

        OperationalEvent::query()->updateOrCreate(
            [
                'event_domain' => OperationalEvent::DomainIntegration,
                'external_id' => 'tal96d5e1c-recovered-evidence',
            ],
            [
                'integration' => OperationalEvent::IntegrationPayMongo,
                'channel' => OperationalEvent::ChannelProviderApi,
                'direction' => OperationalEvent::DirectionInbound,
                'event_type' => 'checkout.recovered',
                'event_version' => 'v1',
                'status' => OperationalEvent::StatusProcessed,
                'occurred_at' => $timestamp->addMinute(),
                'processed_at' => $timestamp->addMinutes(2),
                'failed_at' => null,
                'related_record_type' => PaymentAttempt::class,
                'related_record_id' => $expiredAttempt->id,
                'diagnostics' => [
                    'reason' => 'recovered_paid_without_webhook',
                    'decision' => 'confirmed',
                    'presentation_case' => 'payment_status_recovery',
                ],
                'payload' => [
                    'livemode' => false,
                    'redacted' => true,
                ],
            ],
        );
    }

    private function activeAssessmentFor(string $studentNumber): Assessment
    {
        return Assessment::query()
            ->where('state', Assessment::StateActive)
            ->whereHas(
                'enrollment.studentProfile',
                fn ($query) => $query->where('student_number', $studentNumber),
            )
            ->with(['enrollment.studentProfile', 'enrollment.term'])
            ->sole();
    }

    private function ensureStaffBoundaries(): void
    {
        $unverified = $this->catalog->unverifiedStaff();
        $unverifiedUser = $this->ensureUser(
            email: $unverified['email'],
            firstName: 'Cecilia',
            lastName: 'Navarro',
            status: User::StatusActive,
            verified: false,
        );
        $unverifiedUser->syncRoles([$unverified['role']]);

        $denied = $this->catalog->deniedLoginPersona();
        $deniedUser = $this->ensureUser(
            email: $denied['email'],
            firstName: 'Arturo',
            lastName: 'Salcedo',
            status: $denied['status'],
            verified: true,
        );
        $deniedUser->syncRoles([$denied['role']]);
    }

    private function ensureStudentVerificationBoundary(): void
    {
        $boundary = $this->catalog->unverifiedStudent();
        $student = User::query()->where('email', $boundary['email'])->sole();

        $student->forceFill(['email_verified_at' => null])->save();
    }

    private function ensureApplicantPersonas(Term $term, Program $program, User $registrar): void
    {
        foreach ($this->catalog->applicants() as $email => $definition) {
            $applicant = User::query()->where('email', $email)->first();

            if (! $applicant instanceof User) {
                [$firstName, $lastName] = $this->applicantIdentity($email);
                $applicant = $this->ensureUser(
                    email: $email,
                    firstName: $firstName,
                    lastName: $lastName,
                    status: $definition['user_status'],
                    verified: true,
                );
            } else {
                $applicant->forceFill([
                    'status' => $definition['user_status'],
                    'email_verified_at' => '2025-12-01 08:00:00',
                ])->save();
            }

            $applicant->syncRoles(['applicant']);
            $intake = $this->ensureApplicantIntake(
                applicant: $applicant,
                term: $term,
                program: $program,
                definition: $definition,
                registrar: $registrar,
            );

            if (in_array($intake->status, [
                ApplicantIntake::StatusPending,
                ApplicantIntake::StatusActionRequired,
                ApplicantIntake::StatusForEvaluation,
                ApplicantIntake::StatusApproved,
            ], true)) {
                $this->ensureChecklistState($intake, $registrar);
            }

            if ($intake->status === ApplicantIntake::StatusWithdrawn) {
                $this->ensureWithdrawalAudit($intake, $applicant);
            }
        }
    }

    /**
     * @param  array{label:string,intake_status:string,admission_category:string,credential_basis:string,user_status:string}  $definition
     */
    private function ensureApplicantIntake(
        User $applicant,
        Term $term,
        Program $program,
        array $definition,
        User $registrar,
    ): ApplicantIntake {
        $submittedAt = in_array($definition['intake_status'], [
            ApplicantIntake::StatusPending,
            ApplicantIntake::StatusActionRequired,
            ApplicantIntake::StatusForEvaluation,
            ApplicantIntake::StatusApproved,
        ], true)
            ? CarbonImmutable::parse('2026-01-06 09:00:00', config('app.timezone'))
            : null;
        $reviewedAt = in_array($definition['intake_status'], [
            ApplicantIntake::StatusActionRequired,
            ApplicantIntake::StatusForEvaluation,
            ApplicantIntake::StatusApproved,
        ], true)
            ? CarbonImmutable::parse('2026-01-07 10:00:00', config('app.timezone'))
            : null;
        $approvedAt = $definition['intake_status'] === ApplicantIntake::StatusApproved
            ? CarbonImmutable::parse('2026-01-08 11:00:00', config('app.timezone'))
            : null;
        $archivedAt = $definition['intake_status'] === ApplicantIntake::StatusWithdrawn
            ? CarbonImmutable::parse('2026-01-07 10:00:00', config('app.timezone'))
            : null;
        $attributes = [
            'program_id' => $program->id,
            'admission_category' => $definition['admission_category'],
            'credential_basis' => $definition['credential_basis'],
            'modality_preference' => null,
            'first_name' => $applicant->first_name,
            'middle_name' => $applicant->middle_name,
            'last_name' => $applicant->last_name,
            'birth_date' => '2007-01-15',
            'gender' => 'FEMALE',
            'civil_status' => 'SINGLE',
            'birth_place' => 'San Pedro, Laguna',
            'email' => $applicant->email,
            'phone' => '09171234567',
            'address_barangay' => 'San Antonio',
            'address_street' => '100 Exploration Street',
            'address_city' => 'San Pedro',
            'address_province' => 'Laguna',
            'prior_school' => 'Laguna Community Senior High School',
            'guardian_name' => 'Ramon Mercado',
            'guardian_phone' => '09179876543',
            'guardian_address' => 'San Pedro, Laguna',
            'status' => $definition['intake_status'],
            'submitted_at' => $submittedAt,
            'reviewed_at' => $reviewedAt,
            'reviewed_by' => $reviewedAt === null ? null : $registrar->id,
            'approved_at' => $approvedAt,
            'approved_by' => $approvedAt === null ? null : $registrar->id,
            'archived_at' => $archivedAt,
        ];

        if ($definition['intake_status'] === ApplicantIntake::StatusWithdrawn) {
            return ApplicantIntake::query()->firstOrCreate(
                ['user_id' => $applicant->id, 'term_id' => $term->id],
                $attributes,
            );
        }

        return ApplicantIntake::query()->updateOrCreate(
            ['user_id' => $applicant->id, 'term_id' => $term->id],
            $attributes,
        );
    }

    private function ensureChecklistState(ApplicantIntake $intake, User $registrar): void
    {
        $applicant = $intake->user;

        if (! $applicant instanceof User) {
            throw new RuntimeException('A presentation applicant intake must belong to a user account.');
        }

        $rejectionAssigned = false;

        foreach ($this->requirements->resolve($intake) as $policy) {
            $isResolved = in_array($intake->status, [
                ApplicantIntake::StatusForEvaluation,
                ApplicantIntake::StatusApproved,
            ], true);
            $isRejected = $intake->status === ApplicantIntake::StatusActionRequired
                && ! $rejectionAssigned
                && $policy->evidence_method === ChecklistItem::EvidenceMethodDigitalUpload;

            if ($isRejected) {
                $rejectionAssigned = true;
            }

            $status = match (true) {
                $isRejected => ChecklistItem::StatusRejected,
                $isResolved => ChecklistItem::StatusAccepted,
                $policy->evidence_method === ChecklistItem::EvidenceMethodDigitalUpload => ChecklistItem::StatusReceivedDigital,
                default => ChecklistItem::StatusPending,
            };
            $verification = match (true) {
                $isRejected => ChecklistItem::VerificationRejected,
                $isResolved => ChecklistItem::VerificationVerified,
                default => ChecklistItem::VerificationNotReviewed,
            };
            $reviewed = $isRejected || $isResolved;
            $item = ChecklistItem::query()->updateOrCreate(
                [
                    'applicant_intake_id' => $intake->id,
                    'requirement_type' => $policy->requirement_type,
                ],
                [
                    'owner_type' => ChecklistItem::OwnerApplicant,
                    'student_profile_id' => null,
                    'source_policy_id' => $policy->id,
                    'status' => $status,
                    'blocking_level' => $policy->blocking_level,
                    'evidence_method' => $policy->evidence_method,
                    'verification_status' => $verification,
                    'reviewed_by' => $reviewed ? $registrar->id : null,
                    'reviewed_at' => $reviewed
                        ? CarbonImmutable::parse('2026-01-07 10:00:00', config('app.timezone'))
                        : null,
                    'waiver_reason' => $isRejected
                        ? 'Upload a clear and complete replacement file.'
                        : null,
                ],
            );

            if ($policy->evidence_method === ChecklistItem::EvidenceMethodDigitalUpload) {
                $this->ensureDigitalEvidence($item, $applicant, $registrar, $isRejected, $isResolved);
            }
        }
    }

    private function ensureDigitalEvidence(
        ChecklistItem $item,
        User $applicant,
        User $registrar,
        bool $isRejected,
        bool $isResolved,
    ): void {
        $evidence = $item->documentEvidence()->latest('uploaded_at')->latest('id')->first();

        if (! $evidence instanceof DocumentEvidence) {
            $path = 'tal96d5e1-acceptance/'.$applicant->username.'/'.strtolower($item->requirement_type).'.pdf';
            $contents = "%PDF-1.4\n% TALA applicant requirement evidence\n%%EOF\n";
            Storage::disk('local')->put($path, $contents);
            $evidence = DocumentEvidence::query()->create([
                'checklist_item_id' => $item->id,
                'disk' => 'local',
                'path' => $path,
                'checksum' => hash('sha256', $contents),
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($contents),
                'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
                'status' => DocumentEvidence::StatusSubmitted,
                'uploaded_by' => $applicant->id,
                'uploaded_at' => CarbonImmutable::parse('2026-01-06 09:00:00', config('app.timezone')),
                'reviewed_by' => null,
                'reviewed_at' => null,
                'replaces_document_evidence_id' => null,
            ]);
        }

        $reviewed = $isRejected || $isResolved;
        $evidence->forceFill([
            'status' => match (true) {
                $isRejected => DocumentEvidence::StatusRejected,
                $isResolved => DocumentEvidence::StatusAccepted,
                default => DocumentEvidence::StatusSubmitted,
            },
            'reviewed_by' => $reviewed ? $registrar->id : null,
            'reviewed_at' => $reviewed
                ? CarbonImmutable::parse('2026-01-07 10:00:00', config('app.timezone'))
                : null,
        ])->save();
    }

    private function ensureWithdrawalAudit(ApplicantIntake $intake, User $applicant): void
    {
        $timestamp = CarbonImmutable::parse('2026-01-07 10:00:00', config('app.timezone'));

        DB::table('activity_log')->updateOrInsert(
            [
                'log_name' => 'applicant_intake',
                'subject_type' => ApplicantIntake::class,
                'subject_id' => $intake->id,
                'event' => 'applicant_intake_withdrawn',
            ],
            [
                'description' => 'Applicant withdrew their intake before completed staff review.',
                'causer_type' => User::class,
                'causer_id' => $applicant->id,
                'properties' => json_encode([
                    'status_before' => ApplicantIntake::StatusDraft,
                    'status_after' => ApplicantIntake::StatusWithdrawn,
                    'reason' => 'Plans changed before submission.',
                ], JSON_UNESCAPED_SLASHES),
                'created_at' => $timestamp->toDateTimeString(),
                'updated_at' => $timestamp->toDateTimeString(),
            ],
        );
    }

    private function ensurePriorTermStudentHistories(Term $currentTerm, User $registrar): void
    {
        $priorTerm = Term::query()
            ->where('type', Term::TypeFirstSemester)
            ->where('label', 'First Semester')
            ->where('state', Term::StateClosed)
            ->whereHas('academicYear', fn ($query) => $query->where('label', 'AY 2025-2026'))
            ->sole();
        $faculty = User::role(User::StaffRoleFaculty)->orderBy('id')->firstOrFail();
        $timestamp = CarbonImmutable::parse('2025-10-31 17:00:00', config('app.timezone'));
        $histories = [
            'student.demo@example.test' => [
                'course_indexes' => [0],
                'student_type' => 'regular',
                'category' => GradeRosterRow::CategoryPassing,
            ],
            'student.dbm-2a.002@example.test' => [
                'course_indexes' => [0],
                'student_type' => 'regular',
                'category' => GradeRosterRow::CategoryPassing,
            ],
            'student.dit-2a.002@example.test' => [
                'course_indexes' => [0],
                'student_type' => 'regular',
                'category' => GradeRosterRow::CategoryPassing,
            ],
            'student.dbm-2a.001@example.test' => [
                'course_indexes' => [1],
                'student_type' => 'irregular',
                'category' => GradeRosterRow::CategoryFailed,
            ],
            'student.dit-1a.001@example.test' => [
                'course_indexes' => [0],
                'student_type' => 'regular',
                'category' => GradeRosterRow::CategoryFailed,
            ],
            'student.dit-1a.002@example.test' => [
                'course_indexes' => [1],
                'student_type' => 'irregular',
                'category' => GradeRosterRow::CategoryIncomplete,
            ],
            'student.dit-2a.001@example.test' => [
                'course_indexes' => [0],
                'student_type' => 'irregular',
                'category' => GradeRosterRow::CategoryFailed,
                'hold_type' => Hold::TypePrerequisite,
            ],
            'student.dthm-1a.001@example.test' => [
                'course_indexes' => [0, 1],
                'student_type' => 'irregular',
                'category' => GradeRosterRow::CategoryFailed,
                'hold_type' => Hold::TypeAcademicDeficit,
            ],
            'student.dthm-1a.002@example.test' => [
                'course_indexes' => [2],
                'student_type' => 'regular',
                'category' => GradeRosterRow::CategoryPassing,
            ],
            'student.dthm-2a.001@example.test' => [
                'course_indexes' => [0],
                'student_type' => 'regular',
                'category' => GradeRosterRow::CategoryPassing,
            ],
            'student.dthm-2a.002@example.test' => [
                'course_indexes' => [1],
                'student_type' => 'regular',
                'category' => GradeRosterRow::CategoryPending,
            ],
            'student.completion.demo@example.test' => [
                'course_indexes' => [0],
                'student_type' => 'regular',
                'category' => GradeRosterRow::CategoryPassing,
                'graduation_status' => GraduationEligibilitySnapshotService::ResultReadyForRegistrarReview,
            ],
            'student.graduation.demo@example.test' => [
                'course_indexes' => [0],
                'student_type' => 'regular',
                'category' => GradeRosterRow::CategoryPassing,
                'graduation_status' => GraduationEligibilitySnapshotService::ResultComplete,
            ],
        ];

        foreach ($histories as $email => $history) {
            $profile = User::query()
                ->where('email', $email)
                ->sole()
                ->studentProfile()
                ->sole();
            $yearLevel = $this->yearLevelFromStudentNumber($profile->student_number);
            $entries = CurriculumEntry::query()
                ->whereBelongsTo($profile->curriculumVersion)
                ->where('year_level', $yearLevel)
                ->where('term_type', Term::TypeFirstSemester)
                ->with('courseSpecification.course')
                ->orderBy('sequence')
                ->get();

            foreach ($history['course_indexes'] as $courseIndex) {
                $entry = $entries->get($courseIndex);

                if (! $entry instanceof CurriculumEntry) {
                    throw new RuntimeException(
                        "Missing prior-term curriculum evidence for [{$profile->student_number}] course index [{$courseIndex}].",
                    );
                }

                $this->ensurePriorTermCourseOutcome(
                    profile: $profile,
                    priorTerm: $priorTerm,
                    entry: $entry,
                    courseIndex: $courseIndex,
                    studentType: $history['student_type'],
                    category: $history['category'],
                    faculty: $faculty,
                    registrar: $registrar,
                    timestamp: $timestamp,
                );
            }

            if (isset($history['hold_type'])) {
                $this->ensureAcademicHold(
                    profile: $profile,
                    priorTerm: $priorTerm,
                    holdType: $history['hold_type'],
                    registrar: $registrar,
                    timestamp: $timestamp,
                );
            }

            if (isset($history['graduation_status'])) {
                $this->ensureGraduationProjection(
                    profile: $profile,
                    currentTerm: $currentTerm,
                    resultStatus: $history['graduation_status'],
                    registrar: $registrar,
                    timestamp: $timestamp,
                );
            }
        }
    }

    private function ensurePriorTermCourseOutcome(
        StudentProfile $profile,
        Term $priorTerm,
        CurriculumEntry $entry,
        int $courseIndex,
        string $studentType,
        string $category,
        User $faculty,
        User $registrar,
        CarbonImmutable $timestamp,
    ): void {
        $specification = $entry->courseSpecification;
        $course = $specification?->course;

        if ($specification === null || $course === null) {
            throw new RuntimeException("Prior-term curriculum entry [{$entry->id}] is missing its course specification.");
        }

        $isPending = $category === GradeRosterRow::CategoryPending;
        $isOnline = str_starts_with($course->code, 'GE')
            || str_starts_with($course->code, 'PE')
            || str_starts_with($course->code, 'NSTP')
            || str_starts_with($course->code, 'NIHONGO');
        $offering = TermOffering::query()->firstOrCreate(
            [
                'term_id' => $priorTerm->id,
                'curriculum_entry_id' => $entry->id,
                'delivery_variant' => TermOffering::ArrangementNormalClass,
            ],
            [
                'category' => TermOffering::CategoryRegular,
                'special_reason' => null,
                'modality' => $isOnline
                    ? TermOffering::ModalityOnline
                    : TermOffering::ModalityFaceToFace,
                'expected_count' => 1,
                'room_type_override' => null,
                'same_faculty_override' => true,
                'state' => TermOffering::StateScheduled,
            ],
        );
        $section = Section::query()->firstOrCreate(
            [
                'term_offering_id' => $offering->id,
                'code' => sprintf('HIST-%s-%02d', $profile->student_number, $courseIndex + 1),
            ],
            [
                'capacity' => 30,
                'state' => Section::StateClosed,
            ],
        );
        $enrollment = Enrollment::query()->updateOrCreate(
            [
                'student_profile_id' => $profile->id,
                'term_id' => $priorTerm->id,
            ],
            [
                'status' => 'officially_enrolled',
                'student_type' => $studentType,
                'registered_at' => $timestamp->subMonths(4),
                'officially_enrolled_at' => $timestamp->subMonths(4)->addDay(),
                'status_reason' => 'Recorded result from the previous academic term.',
            ],
        );
        $courseEnrollment = CourseEnrollment::query()->updateOrCreate(
            [
                'enrollment_id' => $enrollment->id,
                'term_offering_id' => $offering->id,
            ],
            [
                'status' => CourseEnrollment::StatusActive,
                'units_snapshot' => $specification->credit_units,
                'added_at' => $timestamp->subMonths(4)->addDay(),
                'status_reason' => 'Recorded result from the previous academic term.',
            ],
        );
        $roster = GradeRoster::query()->updateOrCreate(
            [
                'term_offering_id' => $offering->id,
                'section_id' => $section->id,
            ],
            [
                'faculty_user_id' => $faculty->id,
                'state' => $isPending ? GradeRoster::StateSubmitted : GradeRoster::StateReleased,
                'grading_profile_snapshot' => config('grades.servitech_v1'),
                'submitted_by' => $faculty->id,
                'submitted_at' => $timestamp->subDays(2),
                'reviewed_by' => $isPending ? null : $registrar->id,
                'reviewed_at' => $isPending ? null : $timestamp->subDay(),
                'released_by' => $isPending ? null : $registrar->id,
                'released_at' => $isPending ? null : $timestamp,
                'return_reason' => null,
            ],
        );
        [$equivalent, $outcomeCode] = match ($category) {
            GradeRosterRow::CategoryPassing => [90, '1.75'],
            GradeRosterRow::CategoryFailed => [68, '5.00'],
            GradeRosterRow::CategoryIncomplete => [null, 'INC'],
            default => [null, null],
        };
        $row = GradeRosterRow::query()->updateOrCreate(
            ['course_enrollment_id' => $courseEnrollment->id],
            [
                'grade_roster_id' => $roster->id,
                'prelim_equivalent' => $equivalent,
                'midterm_equivalent' => $equivalent,
                'final_equivalent' => $equivalent,
                'computed_average' => $equivalent,
                'current_outcome_code' => $outcomeCode,
                'current_outcome_category' => $category,
                'released_at' => $isPending ? null : $timestamp,
            ],
        );

        if (! $isPending) {
            GradeOutcomeEvent::query()->updateOrCreate(
                [
                    'grade_roster_row_id' => $row->id,
                    'event_type' => GradeOutcomeEvent::TypeInitialRelease,
                    'evidence_reference' => "ACADEMIC-HISTORY-{$profile->student_number}-{$course->code}",
                ],
                [
                    'previous_value' => null,
                    'new_value' => $equivalent,
                    'previous_category' => null,
                    'new_category' => $category,
                    'deadline' => $category === GradeRosterRow::CategoryIncomplete
                        ? $timestamp->addMonths(1)->toDateString()
                        : null,
                    'authority' => 'Registrar-confirmed release of Faculty-owned grade evidence.',
                    'reason' => 'Recorded previous-term academic outcome.',
                    'recorded_by' => $registrar->id,
                ],
            );
        }
    }

    private function ensureAcademicHold(
        StudentProfile $profile,
        Term $priorTerm,
        string $holdType,
        User $registrar,
        CarbonImmutable $timestamp,
    ): void {
        Hold::query()->updateOrCreate(
            [
                'student_profile_id' => $profile->id,
                'term_id' => $priorTerm->id,
                'hold_type' => $holdType,
                'reason' => 'Academic review requires Registrar action before enrollment.',
            ],
            [
                'enrollment_id' => Enrollment::query()
                    ->whereBelongsTo($profile)
                    ->whereBelongsTo($priorTerm)
                    ->value('id'),
                'blocking_level' => Hold::BlockingEnrollment,
                'status' => Hold::StatusActive,
                'staff_only_reason' => 'This hold is retained for Registrar review.',
                'student_message' => $holdType === Hold::TypePrerequisite
                    ? 'A prerequisite course must be completed before enrollment can continue.'
                    : 'Meet the Academic Head or Registrar to review the failed prior-term courses.',
                'created_by' => $registrar->id,
                'effective_at' => $timestamp,
                'resolution_requirement' => 'Complete the documented academic review.',
            ],
        );
    }

    private function ensureGraduationProjection(
        StudentProfile $profile,
        Term $currentTerm,
        string $resultStatus,
        User $registrar,
        CarbonImmutable $timestamp,
    ): void {
        $batch = GraduationReviewBatch::query()->firstOrCreate(
            [
                'term_id' => $currentTerm->id,
                'name' => 'Completion Eligibility Review',
            ],
            [
                'academic_year_id' => $currentTerm->academic_year_id,
                'state' => GraduationReviewBatch::StateOpen,
                'created_by' => $registrar->id,
                'filter_summary' => ['purpose' => 'Completion eligibility review'],
            ],
        );
        $member = GraduationReviewMember::query()->firstOrCreate(
            [
                'graduation_review_batch_id' => $batch->id,
                'student_profile_id' => $profile->id,
            ],
            [
                'added_by' => $registrar->id,
                'added_at' => $timestamp,
                'is_active' => true,
            ],
        );
        $remainingRequirements = $resultStatus === GraduationEligibilitySnapshotService::ResultComplete
            ? []
            : ['Complete the current-term curriculum requirements.'];

        GraduationSnapshot::query()->updateOrCreate(
            [
                'graduation_review_member_id' => $member->id,
                'version' => 1,
            ],
            [
                'result_status' => $resultStatus,
                'evaluation_snapshot' => [
                    'student_projection' => [
                        'result_status' => $resultStatus,
                        'remaining_requirements' => $remainingRequirements,
                        'pending_grade_blockers' => [],
                        'inc_blockers' => [],
                        'hold_or_clearance_labels' => [],
                        'required_action' => $remainingRequirements === []
                            ? 'Await the Registrar finalization.'
                            : 'Complete the listed requirements before final review.',
                        'office_to_contact' => 'Registrar Office',
                    ],
                    'review_authority' => 'Registrar completion review',
                ],
                'generated_by' => $registrar->id,
                'generated_at' => $timestamp,
                'made_visible_by' => $registrar->id,
                'made_visible_at' => $timestamp,
                'visibility_reason' => 'Result released for student review.',
            ],
        );
    }

    private function yearLevelFromStudentNumber(string $studentNumber): string
    {
        if (! preg_match('/^[A-Z]+-(\d)A-\d{3}$/', $studentNumber, $matches)) {
            throw new RuntimeException("Student number [{$studentNumber}] does not identify a supported year level.");
        }

        return match ((int) $matches[1]) {
            1 => 'First Year',
            2 => 'Second Year',
            3 => 'Third Year',
            default => throw new RuntimeException("Unsupported year level in student number [{$studentNumber}]."),
        };
    }

    private function ensureUser(
        string $email,
        string $firstName,
        string $lastName,
        string $status,
        bool $verified,
    ): User {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'first_name' => $firstName,
                'middle_name' => null,
                'last_name' => $lastName,
                'username' => str($email)->before('@')->replace('.', '-')->toString(),
                'password' => 'password',
                'status' => $status,
            ],
        );
        $user->forceFill([
            'status' => $status,
            'email_verified_at' => $verified ? '2025-12-01 08:00:00' : null,
        ])->save();

        return $user;
    }

    /**
     * @return array{string, string}
     */
    private function applicantIdentity(string $email): array
    {
        return match ($email) {
            'applicant.review.demo@example.test' => ['Bianca', 'Mendoza'],
            'applicant.action-required.demo@example.test' => ['Carlo', 'Reyes'],
            'applicant.evaluation.demo@example.test' => ['Denise', 'Garcia'],
            'applicant.approved.demo@example.test' => ['Enrique', 'Santos'],
            'applicant.withdrawn.demo@example.test' => ['Fiona', 'Bautista'],
            'applicant.transfer.demo@example.test' => ['Gabriel', 'Flores'],
            'applicant.returning.demo@example.test' => ['Hazel', 'Aquino'],
            default => ['Andrea', 'Marquez'],
        };
    }

    private function presentationTerm(): Term
    {
        return Term::query()
            ->where('label', 'Second Semester')
            ->whereHas('academicYear', fn ($query) => $query->where('label', 'AY 2025-2026'))
            ->sole();
    }
}
