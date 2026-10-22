<?php

namespace Tests\Feature\Finance;

use App\Actions\Enrollment\PlaceRegistrationProposal;
use App\Actions\Enrollment\PrepareRegistrationProposal;
use App\Actions\Enrollment\RecordCourseDrop;
use App\Actions\Enrollment\RecordRegistrationCreditEvaluation;
use App\Actions\Enrollment\StartRegistrationCase;
use App\Actions\Finance\CreateAssessmentFromPublishedFeePlan;
use App\Actions\Finance\CreateContextualFinanceExport;
use App\Actions\Finance\CreateFeePlanDraft;
use App\Actions\Finance\CreateSuccessorFeePlan;
use App\Actions\Finance\IndividualAssessmentSources;
use App\Actions\Finance\PublishFeePlan;
use App\Actions\Finance\RecordApprovedCoverage;
use App\Actions\Finance\RecordAuthorizedIndividualAssessment;
use App\Actions\Finance\RevalidateAssessmentEffects;
use App\Actions\Finance\ReverseApprovedCoverage;
use App\Actions\Finance\ReversePaymentPosting;
use App\Actions\Finance\ReviewPaymentEvidence;
use App\Actions\Finance\SubmitPaymentEvidence;
use App\Actions\Finance\TermAccountProjection;
use App\Actions\Integrations\Payments\ExactDuePaymentSnapshotService;
use App\Actions\Integrations\Payments\PaymentGateway;
use App\Actions\Integrations\Payments\PaymentPostedNotificationService;
use App\Actions\Integrations\Payments\PayMongoCheckoutRecoveryService;
use App\Actions\Integrations\Payments\PayMongoReconciliationService;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Filament\Student\Pages\Dashboard as StudentDashboard;
use App\Filament\Student\Pages\Enrollment as StudentEnrollment;
use App\Filament\Student\Pages\Finance as StudentFinance;
use App\Jobs\ProcessPayMongoWebhookCall;
use App\Mail\PaymentPostedMail;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationSubmissionVersion;
use App\Models\ApprovedCoverage;
use App\Models\Assessment;
use App\Models\AssessmentEffectRevalidation;
use App\Models\AssessmentObligation;
use App\Models\CorVersion;
use App\Models\CourseDropRecord;
use App\Models\CourseEnrollment;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\FeePlan;
use App\Models\FinanceExport;
use App\Models\OfficialOutputPaymentClearance;
use App\Models\OperationalEvent;
use App\Models\OutputAccessLog;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use App\Models\PaymentEvidenceVersion;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrarEnrollmentClearance;
use App\Models\RegistrationProposalConfirmation;
use App\Models\RegistrationProposalItem;
use App\Models\RegistrationProposalVersion;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermAccount;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TermAccountToVerifiedManualPaymentJourneyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());
        $this->travelTo(CarbonImmutable::parse('2026-08-25 18:10:00', config('app.timezone')));

        Role::query()->firstOrCreate([
            'name' => User::StaffRoleAccounting,
            'guard_name' => 'web',
        ]);
    }

    public function test_fee_plan_uses_independent_reconciled_obligations_with_due_dates(): void
    {
        $accounting = $this->accounting();
        $program = Program::factory()->create();
        $term = Term::factory()->create();

        $draft = app(CreateFeePlanDraft::class)->execute(
            $program,
            $term,
            [
                ['code' => 'TUITION', 'label' => 'Tuition', 'category' => 'Tuition', 'amount' => '36000.00'],
                ['code' => 'MISC', 'label' => 'Miscellaneous fees', 'category' => 'InstitutionalFee', 'amount' => '12000.00'],
            ],
            $accounting,
            obligations: [
                ['code' => 'ENROLLMENT', 'label' => 'Enrollment obligation', 'purpose' => 'Enrollment', 'amount' => '12000.00', 'due_at' => '2026-08-25 17:00:00', 'required_for_enrollment' => true],
                ['code' => 'MIDTERM', 'label' => 'Midterm obligation', 'purpose' => 'TermPayment', 'amount' => '18000.00', 'due_at' => '2026-10-15 17:00:00'],
                ['code' => 'FINAL', 'label' => 'Final obligation', 'purpose' => 'TermPayment', 'amount' => '18000.00', 'due_at' => '2026-12-01 17:00:00'],
            ],
        );

        $published = app(PublishFeePlan::class)->execute(
            $draft,
            $accounting,
            'SYNTH-FEE-AUTHORITY-2026-01',
            CarbonImmutable::parse('2026-08-01', config('app.timezone')),
        );

        $this->assertSame(FeePlan::StatePublished, $published->state);
        $this->assertSame('48000.00', number_format((float) $published->obligations->sum('amount'), 2, '.', ''));
        $this->assertSame([1, 2, 3], $published->obligations->pluck('sequence')->all());
        $this->assertSame('2026-08-01', $published->authority_date->toDateString());
    }

    public function test_current_due_excludes_later_obligations_until_their_due_time(): void
    {
        $fixture = $this->accountFixture();
        $asOf = CarbonImmutable::parse('2026-08-25 18:00:00', config('app.timezone'));

        $position = app(TermAccountProjection::class)->forAccount($fixture['account'], $asOf);

        $this->assertSame('12000.00', $position['current_due']);
        $this->assertSame('48000.00', $position['remaining_balance']);
        $this->assertSame('ActionNeeded', $position['state']);
        $this->assertSame('ENROLLMENT', $position['next_obligation']['code']);
    }

    public function test_verified_actual_amount_is_applied_oldest_due_first_and_retry_is_idempotent(): void
    {
        Mail::fake();
        $fixture = $this->accountFixture();
        $evidence = $this->evidence($fixture, [
            'term_account_id' => $fixture['account']->id,
            'claimed_amount' => '12000.00',
            'channel' => 'gcash_manual',
            'paid_at' => CarbonImmutable::parse('2026-08-25 18:00:00', config('app.timezone')),
            'payment_reference' => 'GCASH-SYNTH-0001',
            'submitted_by' => $fixture['learner']->id,
            'submitted_at' => CarbonImmutable::parse('2026-08-25 18:05:00', config('app.timezone')),
        ]);

        $first = app(ReviewPaymentEvidence::class)->verify(
            $evidence,
            $fixture['accounting'],
            '7000.00',
            'SYNTH-BANK-CHECK-0001',
        );
        $retry = app(ReviewPaymentEvidence::class)->verify(
            $evidence->fresh(),
            $fixture['accounting'],
            '7000.00',
            'SYNTH-BANK-CHECK-0001',
        );

        $this->assertSame($first->id, $retry->id);
        $this->assertSame('7000.00', $first->amount);
        $this->assertSame(1, Payment::query()->where('payment_evidence_version_id', $evidence->id)->count());
        $this->assertSame('7000.00', PaymentAllocation::query()
            ->where('payment_id', $first->id)
            ->where('assessment_obligation_id', $fixture['obligations'][0]->id)
            ->sum('amount'));
    }

    public function test_payment_notification_is_after_commit_and_duplicate_safe_for_the_account_owner(): void
    {
        Mail::fake();
        $fixture = $this->accountFixture();
        $evidence = $this->evidence($fixture, [
            'term_account_id' => $fixture['account']->id,
            'claimed_amount' => '12000.00',
            'channel' => 'bank_transfer',
            'paid_at' => CarbonImmutable::parse('2026-08-25 18:00:00', config('app.timezone')),
            'payment_reference' => 'BANK-SYNTH-NOTIFICATION',
            'submitted_by' => $fixture['learner']->id,
        ]);
        $payment = app(ReviewPaymentEvidence::class)->verify(
            $evidence,
            $fixture['accounting'],
            '12000.00',
            'SYNTH-BANK-CHECK-NOTIFICATION',
        );

        $service = app(PaymentPostedNotificationService::class);
        $service->record($payment);
        $service->record($payment->fresh());

        $this->assertSame(1, OperationalEvent::query()
            ->where('event_type', OperationalEvent::TypePaymentPostedEmail)
            ->where('related_record_id', $payment->id)
            ->count());
        Mail::assertQueued(PaymentPostedMail::class, function (PaymentPostedMail $mail) use ($fixture): bool {
            $this->assertTrue($mail->afterCommit);
            $this->assertTrue($mail->hasTo($fixture['learner']->email));

            return true;
        });
        Mail::assertQueuedCount(1);
    }

    public function test_notification_queue_failure_does_not_roll_back_the_financial_posting(): void
    {
        $fixture = $this->accountFixture();
        $payment = Payment::factory()->create([
            'term_account_id' => $fixture['account']->id,
            'student_profile_id' => null,
            'term_id' => $fixture['account']->term_id,
            'state' => Payment::StatePosted,
            'amount' => '12000.00',
            'channel' => 'bank_transfer',
            'paid_at' => CarbonImmutable::parse('2026-08-25 18:00:00', config('app.timezone')),
            'provider_reference' => 'SYNTH-NOTIFICATION-FAILURE',
        ]);

        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new RuntimeException('Sensitive transport failure'));
        app(PaymentPostedNotificationService::class)->record($payment);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'state' => Payment::StatePosted,
            'amount' => '12000.00',
        ]);
        $event = OperationalEvent::query()
            ->where('event_type', OperationalEvent::TypePaymentPostedEmail)
            ->where('related_record_id', $payment->id)
            ->sole();
        $this->assertSame(OperationalEvent::StatusFailed, $event->status);
        $this->assertSame('Mail could not be queued.', data_get($event->diagnostics, 'reason'));
        $this->assertStringNotContainsString('Sensitive transport failure', json_encode($event->diagnostics, JSON_THROW_ON_ERROR));
    }

    public function test_excess_verified_amount_remains_an_exception_without_partial_posting(): void
    {
        $fixture = $this->accountFixture();
        $evidence = $this->evidence($fixture, [
            'term_account_id' => $fixture['account']->id,
            'claimed_amount' => '50000.00',
            'channel' => 'bank_transfer',
            'paid_at' => CarbonImmutable::parse('2026-08-25 18:00:00', config('app.timezone')),
            'payment_reference' => 'BANK-SYNTH-EXCESS',
            'submitted_by' => $fixture['learner']->id,
        ]);

        try {
            app(ReviewPaymentEvidence::class)->verify(
                $evidence,
                $fixture['accounting'],
                '50000.00',
                'SYNTH-BANK-CHECK-EXCESS',
            );
            $this->fail('Excess verified money must remain an exception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('actual_verified_amount', $exception->errors());
        }

        $this->assertFalse(Payment::query()->where('payment_evidence_version_id', $evidence->id)->exists());
        $this->assertSame(PaymentEvidenceVersion::StateSubmitted, $evidence->fresh()->state);
    }

    public function test_coverage_is_bounded_and_reversal_restores_the_exact_due(): void
    {
        $fixture = $this->accountFixture();
        $obligation = $fixture['obligations'][0];
        $coverage = app(RecordApprovedCoverage::class)->execute(
            $fixture['account'],
            $obligation,
            [
                'category' => ApprovedCoverage::CategoryGovernmentSubsidy,
                'safe_source_description' => 'Synthetic government subsidy result',
                'authority_reference' => 'SYNTH-COV-AUTH-0001',
                'authority_date' => '2026-08-20',
                'effective_date' => '2026-08-25',
                'amount' => '2000.00',
            ],
            $fixture['accounting'],
        );

        $covered = app(TermAccountProjection::class)->forAccount(
            $fixture['account'],
            CarbonImmutable::parse('2026-08-25 18:00:00', config('app.timezone')),
        );
        $this->assertSame('10000.00', $covered['current_due']);

        app(ReverseApprovedCoverage::class)->execute(
            $coverage,
            $fixture['accounting'],
            'SYNTH-COV-REVERSAL-0001',
            'External authority withdrew the result.',
        );

        $reversed = app(TermAccountProjection::class)->forAccount(
            $fixture['account'],
            CarbonImmutable::parse('2026-08-25 18:00:00', config('app.timezone')),
        );
        $this->assertSame('12000.00', $reversed['current_due']);
        $this->assertSame(ApprovedCoverage::StateReversed, $coverage->fresh()->state);
    }

    public function test_payment_reversal_is_append_only_and_restores_exact_obligation_effects(): void
    {
        $fixture = $this->accountFixture();
        $evidence = $this->evidence($fixture, [
            'term_account_id' => $fixture['account']->id,
            'claimed_amount' => '3000.00',
            'channel' => 'bank_transfer',
            'paid_at' => CarbonImmutable::parse('2026-08-25 18:00:00', config('app.timezone')),
            'payment_reference' => 'BANK-SYNTH-REVERSAL',
            'submitted_by' => $fixture['learner']->id,
        ]);
        $payment = app(ReviewPaymentEvidence::class)->verify(
            $evidence,
            $fixture['accounting'],
            '3000.00',
            'SYNTH-BANK-CHECK-REVERSAL',
        );

        $reversal = app(ReversePaymentPosting::class)->execute(
            $payment,
            $fixture['accounting'],
            'SYNTH-PAYMENT-REVERSAL-0001',
            'Verified external correction.',
            'SYNTH-EXTERNAL-REFUND-0001',
            '3000.00',
            now(),
        );

        $this->assertSame($payment->id, $reversal->reverses_payment_id);
        $this->assertSame('3000.00', $reversal->amount);
        $this->assertSame('3000.00', $payment->fresh()->amount);
        $this->assertSame(
            $payment->allocations()->pluck('assessment_obligation_id')->all(),
            $reversal->allocations()->pluck('assessment_obligation_id')->all(),
        );
        $this->actingAs($fixture['learner'])->get(route('finance.payments.acknowledgement', $payment))
            ->assertOk()->assertSee('Reversed')->assertSee('Recorded refunds')->assertSee('Verified external correction.')
            ->assertViewHas('acknowledgement', fn (array $acknowledgement): bool => $acknowledgement['payment']->amount === '3000.00'
                && $acknowledgement['payment']->id === $payment->id
                && $acknowledgement['allocations'] === [['target' => 'Enrollment obligation', 'amount' => '3000.00']]);
    }

    public function test_contextual_export_is_private_bounded_and_spreadsheet_safe(): void
    {
        Storage::fake('local');
        $fixture = $this->accountFixture();
        $export = app(CreateContextualFinanceExport::class)->createAccountStatus(
            $fixture['accounting'],
            'Reconcile the synthetic Term Account.',
            new Collection([$fixture['account']->enrollment]),
            ['term_account_id' => $fixture['account']->id],
            CarbonImmutable::parse('2026-08-25 18:00:00', config('app.timezone')),
        );

        Storage::disk('local')->assertExists($export->path);
        $contents = Storage::disk('local')->get($export->path);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contents);
        $this->assertSame(FinanceExport::TypeAccountStatus, $export->type);
        $this->actingAs($fixture['accounting'])->get(route('finance.exports.download', $export))->assertOk();
        $this->actingAs($fixture['learner'])->get(route('finance.exports.download', $export))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('finance.exports.download', $export))->assertForbidden();

        $this->expectException(ValidationException::class);
        app(CreateContextualFinanceExport::class)->createAccountStatus(
            $fixture['accounting'], 'Too broad',
            new Collection(array_fill(0, 10001, $fixture['account']->enrollment)),
            [], CarbonImmutable::now(config('app.timezone')),
        );
    }

    public function test_tor_payment_clearance_is_request_specific_versioned_and_not_global(): void
    {
        $fixture = $this->accountFixture();
        $first = OfficialOutputPaymentClearance::factory()->create([
            'term_account_id' => $fixture['account']->id,
            'output_request_reference' => 'TOR-SYNTH-0001',
            'version' => 1,
            'state' => OfficialOutputPaymentClearance::StateNotCleared,
            'authority_reference' => 'SYNTH-CLEARANCE-AUTH-1',
            'safe_reason' => 'Historical current due remained.',
            'decided_by' => $fixture['accounting']->id,
            'decided_at' => now(),
        ]);
        $second = OfficialOutputPaymentClearance::factory()->create([
            'term_account_id' => $fixture['account']->id,
            'output_request_reference' => 'TOR-SYNTH-0001',
            'version' => 2,
            'supersedes_clearance_id' => $first->id,
            'state' => OfficialOutputPaymentClearance::StateCleared,
            'authority_reference' => 'SYNTH-CLEARANCE-AUTH-2',
            'safe_reason' => 'Historical request-specific decision retained.',
            'decided_by' => $fixture['accounting']->id,
            'decided_at' => now(),
        ]);

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertSame($first->id, $second->supersedes_clearance_id);
        $this->assertSame(TermAccount::StateOpen, $fixture['account']->fresh()->state);
        $this->assertFalse(OfficialOutputPaymentClearance::query()->where('output_request_reference', 'TOR-SYNTH-OTHER')->exists());
    }

    public function test_private_payment_evidence_is_versioned_and_submission_never_posts_money(): void
    {
        Storage::fake('local');
        $fixture = $this->accountFixture();
        $first = app(SubmitPaymentEvidence::class)->execute(
            $fixture['account'], $fixture['learner'], UploadedFile::fake()->image('proof.png'),
            '2500.00', 'gcash_manual', now()->subMinute(), 'SYN-GCASH-PRIVATE-001',
        );
        $second = app(SubmitPaymentEvidence::class)->execute(
            $fixture['account'], $fixture['learner'], UploadedFile::fake()->image('replacement.png', 24, 24),
            '2500.00', 'gcash_manual', now()->subMinute(), 'SYN-GCASH-PRIVATE-002',
        );

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertSame($first->id, $second->supersedes_version_id);
        $this->assertFalse(Payment::query()->where('term_account_id', $fixture['account']->id)->exists());
        $this->actingAs($fixture['learner'])->get(route('finance.payment-evidence.download', $second))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('finance.payment-evidence.download', $second))->assertForbidden();
    }

    public function test_owned_payment_proof_previews_supported_files_privately_and_preserves_downloads(): void
    {
        Storage::fake('local');
        $fixture = $this->accountFixture();
        $positionBefore = app(TermAccountProjection::class)->forAccount($fixture['account']);

        foreach ([
            [UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"), 'application/pdf', 'pdf'],
            [UploadedFile::fake()->image('receipt.jpg'), 'image/jpeg', 'jpg'],
            [UploadedFile::fake()->image('receipt.png'), 'image/png', 'png'],
        ] as [$file, $mime, $extension]) {
            $proof = app(SubmitPaymentEvidence::class)->execute(
                $fixture['account'], $fixture['learner'], $file,
                '2500.00', 'bank_transfer', now()->subMinute(), null,
            );

            $this->actingAs($fixture['learner'])->get(route('finance.payment-evidence.view', $proof))
                ->assertOk()
                ->assertHeader('Content-Type', $mime)
                ->assertHeader('Content-Disposition', sprintf('inline; filename="payment-evidence-%d.%s"', $proof->id, $extension))
                ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertContent(Storage::disk('local')->get($proof->path));
            $this->get(route('finance.payment-evidence.download', $proof))
                ->assertOk()
                ->assertHeader('Content-Disposition', sprintf('attachment; filename="payment-evidence-%d.%s"', $proof->id, $extension));

            Storage::disk('local')->assertExists($proof->path);
            $this->assertSame(PaymentEvidenceVersion::StateSubmitted, $proof->fresh()->state);
            $this->assertSame(2, DB::table('activity_log')->where('event', 'payment_evidence_accessed')
                ->where('subject_type', PaymentEvidenceVersion::class)->where('subject_id', $proof->id)
                ->where('causer_id', $fixture['learner']->id)->count());
        }

        $this->assertFalse(Payment::query()->where('term_account_id', $fixture['account']->id)->exists());
        $this->assertSame($positionBefore, app(TermAccountProjection::class)->forAccount($fixture['account']));
    }

    public function test_payment_proof_preview_refuses_guest_foreign_and_unsupported_file_content(): void
    {
        Storage::fake('local');
        $fixture = $this->accountFixture();
        $proof = app(SubmitPaymentEvidence::class)->execute(
            $fixture['account'], $fixture['learner'], UploadedFile::fake()->image('private-receipt.png'),
            '2500.00', 'bank_transfer', now()->subMinute(), null,
        );
        $preview = route('finance.payment-evidence.view', $proof);

        $this->get($preview)->assertRedirect();
        $this->actingAs(User::factory()->create())->get($preview)->assertForbidden();

        Storage::disk('local')->put($proof->path, '<html><script>alert("unsafe")</script></html>');
        $this->actingAs($fixture['learner'])->get($preview)->assertStatus(415);

        $this->assertSame(0, DB::table('activity_log')->where('event', 'payment_evidence_accessed')
            ->where('subject_type', PaymentEvidenceVersion::class)->where('subject_id', $proof->id)->count());
        $this->assertSame(PaymentEvidenceVersion::StateSubmitted, $proof->fresh()->state);
        $this->assertFalse(Payment::query()->where('term_account_id', $fixture['account']->id)->exists());
    }

    public function test_soa_and_acknowledgement_use_canonical_state_and_record_access(): void
    {
        Mail::fake();
        $fixture = $this->accountFixture();
        $evidence = $this->evidence($fixture, [
            'term_account_id' => $fixture['account']->id, 'claimed_amount' => '3000.00',
            'channel' => 'bank_transfer', 'paid_at' => '2026-08-25 18:00:00',
            'payment_reference' => 'BANK-SYNTH-OUTPUT', 'submitted_by' => $fixture['learner']->id,
        ]);
        $payment = app(ReviewPaymentEvidence::class)->verify(
            $evidence, $fixture['accounting'], '3000.00', 'SYN-BANK-CHECK-OUTPUT',
        );
        $fixture['obligations'][1]->update(['due_at' => null]);
        $fixture['learner']->assignRole(Role::findOrCreate('student', 'web'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($fixture['learner'])
            ->get(route('finance.statement', $fixture['assessment']))
            ->assertOk()->assertSee('Amount due now')->assertDontSee('Ledger Entry')
            ->assertSee(route('filament.student.pages.finance'))
            ->assertSeeHtml('<td data-label="Due">Not recorded</td>')
            ->assertDontSeeHtml('<td data-label="Due">'.now(config('app.display_timezone'))->format('M d, Y h:i A').'</td>')
            ->assertSee('PHP 9,000.00')->assertSee('PHP 45,000.00')->assertSee('Verified payment')
            ->assertDontSee('SYN-BANK-CHECK-OUTPUT')
            ->assertViewHas('statement', fn (array $statement): bool => $statement['position']['coverage_applied'] === '0.00');
        $acknowledgement = $this->actingAs($fixture['learner'])
            ->get(route('finance.payments.acknowledgement', $payment))
            ->assertOk()->assertSee('Actual verified amount')->assertSee('Account effect')
            ->assertSee(route('filament.student.pages.finance'))
            ->assertSee('Independent Source Check')->assertDontSee('SYN-BANK-CHECK-OUTPUT');
        $this->assertSame(1, substr_count($acknowledgement->getContent(), $payment->provider_reference));

        $this->assertSame(1, OutputAccessLog::query()->where('output_type', 'SOA')->where('source_record_id', $fixture['assessment']->id)->count());
        $this->assertSame(1, OutputAccessLog::query()->where('output_type', 'PAYMENT_ACKNOWLEDGEMENT')->where('source_record_id', $payment->id)->count());
        $accountingBackUrl = route('filament.admin.resources.enrollments.view', $fixture['account']->enrollment_id);
        $this->actingAs($fixture['accounting'])->get(route('finance.statement', $fixture['assessment']))
            ->assertOk()->assertSee($accountingBackUrl);
        $this->actingAs($fixture['accounting'])->get(route('finance.payments.acknowledgement', $payment))
            ->assertOk()->assertSee($accountingBackUrl);
        app(RecordApprovedCoverage::class)->execute($fixture['account'], $fixture['obligations'][0], [
            'category' => ApprovedCoverage::CategoryScholarship, 'safe_source_description' => 'Approved study grant',
            'authority_reference' => 'COVERAGE-AUTHORITY', 'authority_date' => '2026-08-24',
            'effective_date' => '2026-08-24', 'amount' => '2000.00',
        ], $fixture['accounting']);
        $this->actingAs($fixture['learner'])->get(route('finance.statement', $fixture['assessment']))
            ->assertOk()->assertSee('PHP 7,000.00')->assertSee('PHP 43,000.00')
            ->assertSeeInOrder(['Approved coverage', 'Approved study grant', 'COVERAGE-AUTHORITY', 'Verified payment'])
            ->assertViewHas('statement', fn (array $statement): bool => $statement['position']['payment_applied'] === '3000.00'
                && $statement['position']['coverage_applied'] === '2000.00');
        $this->actingAs(User::factory()->create())->get(route('finance.statement', $fixture['assessment']))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('finance.payments.acknowledgement', $payment))->assertForbidden();
    }

    public function test_applicant_finance_outputs_return_to_applicant_enrollment(): void
    {
        Mail::fake();
        $fixture = $this->accountFixture();
        $payment = $this->verifiedPayment($fixture, '3000.00', 'APPLICANT-OUTPUT-RETURN');
        $this->assertTrue($fixture['learner']->hasRole('applicant'));
        $this->assertFalse($fixture['learner']->hasRole('student'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $backUrl = \App\Filament\Applicant\Pages\Enrollment::getUrl(panel: 'applicant');
        $staffUrl = route('filament.admin.resources.enrollments.view', $fixture['account']->enrollment_id);

        foreach ([route('finance.statement', $fixture['assessment']), route('finance.payments.acknowledgement', $payment)] as $url) {
            $this->actingAs($fixture['learner'])->get($url)
                ->assertOk()->assertSeeHtml('href="'.$backUrl.'"')->assertDontSeeHtml('href="'.$staffUrl.'"');
        }
    }

    public function test_historical_soa_url_uses_one_current_assessment_for_charges_sources_and_balances(): void
    {
        $fixture = $this->accountFixture();
        $fixture['assessment']->update(['state' => Assessment::StateSuperseded, 'authority_reference' => 'OLDER-AUTHORITY']);
        $successor = Assessment::factory()->create([
            'enrollment_id' => $fixture['account']->enrollment_id, 'term_account_id' => $fixture['account']->id,
            'version' => 2, 'state' => Assessment::StateActive, 'authority_reference' => 'CURRENT-AUTHORITY',
            'total' => '42000.00', 'subtotal' => '42000.00', 'source_proposal_version_id' => null,
        ]);
        $successor->lines()->create(['source_line_key' => 'CURRENT-CHARGE', 'description_snapshot' => 'Current approved charge <script>unsafe()</script>',
            'quantity' => 1, 'rate' => '42000.00', 'amount' => '42000.00', 'line_type' => 'Fixed']);
        AssessmentObligation::factory()->create(['assessment_id' => $successor->id, 'amount' => '42000.00',
            'due_at' => now()->subDay(), 'required_for_enrollment' => true]);

        foreach ([$fixture['learner'], $fixture['accounting']] as $actor) {
            $this->actingAs($actor)->get(route('finance.statement', $fixture['assessment']))
                ->assertOk()->assertSee('Earlier account · current position')->assertSee('Opened from an earlier assessment.')
                ->assertSee('Assessment 2')->assertSee('CURRENT-AUTHORITY')->assertDontSee('OLDER-AUTHORITY')
                ->assertSee('PHP 42,000.00')->assertSee('Current approved charge <script>unsafe()</script>')
                ->assertDontSeeHtml('<script>unsafe()</script>')
                ->assertViewHas('statement', fn (array $statement): bool => $statement['assessment']->id === $successor->id
                    && $statement['position']['assessment_id'] === $successor->id);
        }
    }

    public function test_soa_without_an_active_assessment_keeps_historical_facts_and_unavailable_balances(): void
    {
        $fixture = $this->accountFixture();
        $fixture['assessment']->update(['state' => Assessment::StateSuperseded, 'authority_reference' => 'RETAINED-AUTHORITY']);
        $fixture['assessment']->lines()->create(['source_line_key' => 'RETAINED-CHARGE', 'description_snapshot' => 'Retained approved charge',
            'quantity' => 1, 'rate' => '48000.00', 'amount' => '48000.00', 'line_type' => 'Fixed']);

        $this->actingAs($fixture['learner'])->get(route('finance.statement', $fixture['assessment']))
            ->assertOk()->assertSee('Earlier account · current position')->assertSee('Balance unavailable')
            ->assertSee('Retained historical charges')->assertSee('Retained approved charge')->assertSee('RETAINED-AUTHORITY')
            ->assertSee('PHP 48,000.00')->assertDontSee('PHP 0.00')->assertDontSee('Amount due now')
            ->assertViewHas('statement', fn (array $statement): bool => $statement['position']['current_due'] === null
                && $statement['position']['remaining_balance'] === null);
    }

    public function test_coverage_supersession_preserves_history_and_revalidates_the_bound(): void
    {
        $fixture = $this->accountFixture();
        $base = [
            'category' => ApprovedCoverage::CategoryScholarship,
            'safe_source_description' => 'Synthetic scholarship decision',
            'authority_reference' => 'SYN-COV-BASE', 'authority_date' => '2026-08-01',
            'effective_date' => '2026-08-25', 'amount' => '2000.00',
        ];
        $first = app(RecordApprovedCoverage::class)->execute($fixture['account'], $fixture['obligations'][0], $base, $fixture['accounting']);
        $second = app(RecordApprovedCoverage::class)->execute(
            $fixture['account'], $fixture['obligations'][0],
            [...$base, 'authority_reference' => 'SYN-COV-SUCCESSOR', 'amount' => '3000.00', 'supersedes_coverage_id' => $first->id],
            $fixture['accounting'],
        );

        $this->assertSame(ApprovedCoverage::StateSuperseded, $first->fresh()->state);
        $this->assertSame($first->id, $second->supersedes_coverage_id);
        $this->assertSame(2, ApprovedCoverage::query()->where('term_account_id', $fixture['account']->id)->count());

        $this->expectException(ValidationException::class);
        app(RecordApprovedCoverage::class)->execute(
            $fixture['account'], $fixture['obligations'][0], [...$base, 'amount' => '10000.01'], $fixture['accounting'],
        );
    }

    public function test_legacy_finance_routes_and_generic_reports_are_unreachable_while_manual_payment_remains_visible(): void
    {
        $fixture = $this->accountFixture();
        Role::query()->firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $fixture['learner']->assignRole('student');
        $profile = StudentProfile::factory()->create(['user_id' => $fixture['learner']->id]);
        $fixture['account']->enrollment->update(['student_profile_id' => $profile->id]);

        $this->assertFalse(Route::has('finance.billing-slip'));
        $this->assertFalse(Route::has('filament.admin.pages.paymongo-reconciliation'));
        $this->assertFileDoesNotExist(app_path('Actions/Reports/OperationalReportService.php'));

        $this->actingAs($fixture['learner'])
            ->get(route('filament.student.pages.finance'))
            ->assertOk()
            ->assertSee('Submit payment proof')
            ->assertDontSee('Billing Slip')
            ->assertDontSee('Financial Accommodation');
    }

    public function test_verification_keeps_captured_targets_and_rejects_a_balance_consumed_by_coverage(): void
    {
        $fixture = $this->accountFixture();
        $evidence = $this->evidence($fixture, ['term_account_id' => $fixture['account']->id, 'claimed_amount' => '3000.00', 'channel' => 'bank_transfer', 'paid_at' => now(), 'submitted_by' => $fixture['learner']->id]);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', config('app.timezone')));
        app(RecordApprovedCoverage::class)->execute($fixture['account'], $fixture['obligations'][0], [
            'category' => ApprovedCoverage::CategoryScholarship, 'safe_source_description' => 'Synthetic approved result',
            'authority_reference' => 'SYN-TARGET-COVERAGE', 'authority_date' => '2026-08-01', 'effective_date' => '2026-08-25', 'amount' => '10000.00',
        ], $fixture['accounting']);

        try {
            app(ReviewPaymentEvidence::class)->verify($evidence, $fixture['accounting'], '3000.00', 'SYN-TARGET-CHECK');
            $this->fail('Later unpaid obligations must not absorb money intended for the captured target.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('actual_verified_amount', $exception->errors());
        }
        $this->assertSame(0, Payment::query()->where('term_account_id', $fixture['account']->id)->count());
        $payment = app(ReviewPaymentEvidence::class)->verify($evidence->fresh(), $fixture['accounting'], '2000.00', 'SYN-TARGET-CHECK');
        $this->assertSame([$fixture['obligations'][0]->id], $payment->allocations->pluck('assessment_obligation_id')->all());
        $this->assertSame('18000.00', app(TermAccountProjection::class)->forAccount($fixture['account'])['current_due']);
    }

    public function test_duplicate_receipt_attempts_are_retained_and_only_one_can_post(): void
    {
        Storage::fake('local');
        $fixture = $this->accountFixture();
        $receipt = UploadedFile::fake()->image('same-receipt.png');
        $first = app(SubmitPaymentEvidence::class)->execute($fixture['account'], $fixture['learner'], $receipt, '1000.00', 'bank_transfer', now(), 'SYN-DUPLICATE');
        $second = app(SubmitPaymentEvidence::class)->execute($fixture['account'], $fixture['learner'], $receipt, '1000.00', 'bank_transfer', now(), 'SYN-DUPLICATE');
        $this->assertTrue($second->possible_duplicate);
        $this->assertSame($first->id, $second->supersedes_version_id);
        app(ReviewPaymentEvidence::class)->verify($first, $fixture['accounting'], '1000.00', 'SYN-DUPLICATE-CHECK');
        try {
            app(ReviewPaymentEvidence::class)->verify($second, $fixture['accounting'], '1000.00', 'SYN-SECOND-CHECK');
            $this->fail('The same external receipt cannot be posted twice.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evidence', $exception->errors());
        }
        $this->assertSame(2, $fixture['account']->paymentEvidenceVersions()->count());
        $this->assertSame(1, $fixture['account']->payments()->count());
    }

    public function test_same_receipt_across_accounts_retains_both_uploads_and_blocks_a_second_posting(): void
    {
        Storage::fake('local');
        $firstFixture = $this->accountFixture();
        $secondFixture = $this->accountFixture();
        $receipt = UploadedFile::fake()->image('shared-receipt.png');

        $first = app(SubmitPaymentEvidence::class)->execute($firstFixture['account'], $firstFixture['learner'], $receipt, '1000.00', 'bank_transfer', now(), 'SYN-FIRST-ACCOUNT');
        $second = app(SubmitPaymentEvidence::class)->execute($secondFixture['account'], $secondFixture['learner'], $receipt, '1000.00', 'bank_transfer', now(), 'SYN-SECOND-ACCOUNT');

        $this->assertSame($first->checksum, $second->checksum);
        $this->assertNotSame($first->path, $second->path);
        foreach ([$firstFixture, $secondFixture] as $fixture) {
            $this->assertSame(1, $fixture['account']->paymentEvidenceVersions()->count());
            $this->assertSame(0, $fixture['account']->payments()->count());
            $position = app(TermAccountProjection::class)->forAccount($fixture['account']);
            $this->assertSame('0.00', $position['payment_applied']);
            $this->assertSame('12000.00', $position['current_due']);
            $this->assertSame('48000.00', $position['remaining_balance']);
        }

        $payment = app(ReviewPaymentEvidence::class)->verify($first, $firstFixture['accounting'], '1000.00', 'SYN-FIRST-ACCOUNT-CHECK');

        $this->assertSame(Payment::StatePosted, $payment->state);
        $this->assertSame('1000.00', $payment->amount);
        $this->assertSame($firstFixture['obligations'][0]->id, $payment->allocations->sole()->assessment_obligation_id);
        $this->assertSame('1000.00', $payment->allocations->sole()->amount);

        try {
            app(ReviewPaymentEvidence::class)->verify($second, $secondFixture['accounting'], '1000.00', 'SYN-SECOND-ACCOUNT-CHECK');
            $this->fail('The same receipt must not move money into a second account.');
        } catch (ValidationException $exception) {
            $this->assertSame([
                'evidence' => ['This receipt or independent source result already has a posting. Resolve the duplicate without posting it again.'],
            ], $exception->errors());
        }

        $this->assertSame(1, $firstFixture['account']->payments()->count());
        $this->assertSame(0, $secondFixture['account']->payments()->count());
        $this->assertSame(1, $payment->allocations()->count());
        $this->assertDatabaseMissing('payment_allocations', ['assessment_obligation_id' => $secondFixture['obligations'][0]->id]);
        $this->assertSame(PaymentEvidenceVersion::StateVerified, $first->fresh()->state);
        $this->assertSame(PaymentEvidenceVersion::StateSubmitted, $second->fresh()->state);
        $this->assertNull($second->fresh()->reviewed_at);
        $this->assertSame(1, $firstFixture['account']->paymentEvidenceVersions()->count());
        $this->assertSame(1, $secondFixture['account']->paymentEvidenceVersions()->count());
        Storage::disk('local')->assertExists([$first->path, $second->path]);

        $firstPosition = app(TermAccountProjection::class)->forAccount($firstFixture['account']);
        $this->assertSame('1000.00', $firstPosition['payment_applied']);
        $this->assertSame('11000.00', $firstPosition['current_due']);
        $this->assertSame('47000.00', $firstPosition['remaining_balance']);
        $secondPosition = app(TermAccountProjection::class)->forAccount($secondFixture['account']);
        $this->assertSame('0.00', $secondPosition['payment_applied']);
        $this->assertSame('12000.00', $secondPosition['current_due']);
        $this->assertSame('48000.00', $secondPosition['remaining_balance']);
    }

    public function test_successor_assessment_requires_explicit_reconciliation_and_rejects_old_evidence(): void
    {
        $fixture = $this->accountFixture();
        $postedEvidence = $this->evidence($fixture, ['term_account_id' => $fixture['account']->id, 'claimed_amount' => '3000.00', 'channel' => 'bank_transfer', 'paid_at' => now(), 'submitted_by' => $fixture['learner']->id]);
        $payment = app(ReviewPaymentEvidence::class)->verify($postedEvidence, $fixture['accounting'], '3000.00', 'SYN-PREDECESSOR-PAYMENT');
        $pending = $this->evidence($fixture, ['term_account_id' => $fixture['account']->id, 'version' => 2, 'claimed_amount' => '1000.00', 'channel' => 'bank_transfer', 'paid_at' => now(), 'submitted_by' => $fixture['learner']->id]);
        $fixture['assessment']->update(['state' => Assessment::StateSuperseded]);
        $successor = Assessment::factory()->create(['enrollment_id' => $fixture['account']->enrollment_id, 'term_account_id' => $fixture['account']->id, 'version' => 2, 'total' => '48000.00', 'subtotal' => '48000.00']);
        $target = AssessmentObligation::factory()->create(['assessment_id' => $successor->id, 'code' => 'SUCCESSOR', 'amount' => '48000.00', 'due_at' => now()->subMinute(), 'required_for_enrollment' => true]);
        $position = app(TermAccountProjection::class)->forAccount($fixture['account']);
        $this->assertSame('Unavailable', $position['state']);
        $this->assertTrue($position['reconciliation_required']);
        $this->assertSame('3000.00', $position['retained_payment']);
        $record = app(RevalidateAssessmentEffects::class)->execute($successor, $fixture['accounting'], [$fixture['obligations'][0]->id => $target->id], 'SYN-RECONCILIATION');
        $again = app(RevalidateAssessmentEffects::class)->execute($successor, $fixture['accounting'], [$fixture['obligations'][0]->id => $target->id], 'SYN-RECONCILIATION');
        $this->assertSame($record->id, $again->id);
        $this->assertSame('45000.00', app(TermAccountProjection::class)->forAccount($fixture['account'])['current_due']);
        $this->assertSame($fixture['obligations'][0]->id, $payment->allocations()->sole()->assessment_obligation_id);
        try {
            app(ReviewPaymentEvidence::class)->verify($pending, $fixture['accounting'], '1000.00', 'SYN-STALE-EVIDENCE');
            $this->fail('Old submitted evidence must not silently change assessment targets.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('actual_verified_amount', $exception->errors());
        }
        $this->assertSame(1, $fixture['account']->payments()->count());
    }

    public function test_partial_refunds_require_actual_evidence_are_retry_safe_and_cannot_over_reverse(): void
    {
        $fixture = $this->accountFixture();
        $evidence = $this->evidence($fixture, ['term_account_id' => $fixture['account']->id, 'claimed_amount' => '3000.00', 'channel' => 'bank_transfer', 'paid_at' => now()->subMinute(), 'submitted_by' => $fixture['learner']->id]);
        $payment = app(ReviewPaymentEvidence::class)->verify($evidence, $fixture['accounting'], '3000.00', 'SYN-REFUND-PAYMENT');
        $reverser = app(ReversePaymentPosting::class);
        try {
            $reverser->execute($payment, $fixture['accounting'], 'SYN-REFUND-AUTH', 'External refund decision.', '', '1000.00', now());
            $this->fail('A decision without actual external refund evidence must not change money.');
        } catch (ValidationException) {
            $this->assertSame(0, $payment->reversals()->count());
        }
        $first = $reverser->execute($payment, $fixture['accounting'], 'SYN-REFUND-AUTH', 'Completed partial refund.', 'SYN-REFUND-1', '1000.00', now());
        $again = $reverser->execute($payment, $fixture['accounting'], 'SYN-REFUND-AUTH', 'Completed partial refund.', 'SYN-REFUND-1', '1000.00', now());
        $this->assertSame($first->id, $again->id);
        $reverser->execute($payment, $fixture['accounting'], 'SYN-REFUND-AUTH', 'Completed remaining refund.', 'SYN-REFUND-2', '2000.00', now());
        try {
            $reverser->execute($payment, $fixture['accounting'], 'SYN-REFUND-AUTH', 'Excess refund claim.', 'SYN-REFUND-3', '0.01', now());
            $this->fail('A correction cannot exceed the original net posted amount.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment', $exception->errors());
        }
        $this->assertSame(2, $payment->reversals()->count());
        $this->assertSame('3000.00', $payment->fresh()->amount);
        $this->assertSame('12000.00', app(TermAccountProjection::class)->forAccount($fixture['account'])['current_due']);
    }

    public function test_student_can_submit_a_later_due_obligation_after_the_enrollment_obligation_is_satisfied(): void
    {
        Storage::fake('local');
        $fixture = $this->accountFixture();
        Role::findOrCreate('student', 'web');
        $fixture['learner']->assignRole('student');
        $profile = StudentProfile::factory()->create(['user_id' => $fixture['learner']->id]);
        $fixture['account']->enrollment->update(['student_profile_id' => $profile->id]);
        app(RecordApprovedCoverage::class)->execute($fixture['account'], $fixture['obligations'][0], [
            'category' => ApprovedCoverage::CategoryScholarship,
            'safe_source_description' => 'Synthetic enrollment coverage',
            'authority_reference' => 'SYN-LATER-PAYMENT-COVERAGE',
            'authority_date' => '2026-08-25', 'effective_date' => '2026-08-25', 'amount' => '12000.00',
        ], $fixture['accounting']);
        $this->travelTo(CarbonImmutable::parse('2026-10-16 12:10:00', 'UTC'));
        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::actingAs($fixture['learner']->fresh())->test(StudentFinance::class)
            ->assertActionVisible('submitEvidence')
            ->callAction('submitEvidence', data: [
                'evidence' => UploadedFile::fake()->image('later-payment.jpg'),
                'claimed_amount' => '18000.00', 'channel' => 'bank_transfer',
                'paid_at' => '2026-10-16 20:09', 'payment_reference' => 'SYN-LATER-DUE',
            ])->assertHasNoActionErrors()->assertNotified('Evidence submitted for Accounting review');

        $evidence = $fixture['account']->paymentEvidenceVersions()->sole();
        $this->assertSame([$fixture['obligations'][1]->id], array_column($evidence->obligation_snapshot, 'id'));
        $this->assertSame('2026-10-16 12:09:00', $evidence->paid_at->utc()->toDateTimeString());
        $this->assertSame(0, $fixture['account']->payments()->count());
    }

    public function test_official_enrollment_cannot_bypass_the_exact_confirmed_adjustment_assessment_source(): void
    {
        foreach ([
            [RegistrationProposalVersion::PurposeInitial, RegistrationProposalVersion::StateConfirmed, true, false, Assessment::CategoryAuthorizedAdjustment],
            [RegistrationProposalVersion::PurposeAdjustment, RegistrationProposalVersion::StateDraft, false, false, Assessment::CategoryAuthorizedAdjustment],
            [RegistrationProposalVersion::PurposeAdjustment, RegistrationProposalVersion::StateConfirmed, false, false, Assessment::CategoryAuthorizedAdjustment],
            [RegistrationProposalVersion::PurposeAdjustment, RegistrationProposalVersion::StateConfirmed, true, true, Assessment::CategoryAuthorizedAdjustment],
            [RegistrationProposalVersion::PurposeAdjustment, RegistrationProposalVersion::StateConfirmed, true, false, Assessment::CategoryIndividuallyAdvised],
        ] as [$purpose, $state, $confirmed, $foreignCase, $category]) {
            $fixture = $this->accountFixture();
            $enrollment = $fixture['account']->enrollment;
            $proposal = RegistrationProposalVersion::factory()->create([
                'enrollment_id' => $foreignCase ? Enrollment::factory() : $enrollment->id,
                'purpose' => $purpose,
                'state' => $state,
            ]);
            if ($confirmed) {
                RegistrationProposalConfirmation::factory()->for($proposal, 'proposalVersion')->create();
            }
            $enrollment->update([
                'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
                'current_proposal_version_id' => $proposal->id,
            ]);
            $before = $fixture['assessment']->fresh()->getAttributes();

            try {
                app(RecordAuthorizedIndividualAssessment::class)->execute(
                    $enrollment, $fixture['accounting'], $category, 'SYN-UNSUPPORTED-OFFICIAL-ASSESSMENT', CarbonImmutable::now(),
                    [['code' => 'TOTAL', 'label' => 'Authorized total', 'amount' => '48000.00']],
                    [['code' => 'ENROLLMENT', 'label' => 'Enrollment obligation', 'purpose' => 'Enrollment', 'amount' => '48000.00', 'due_at' => now()->toDateTimeString(), 'required_for_enrollment' => true]],
                );
                $this->fail('Official enrollment alone cannot authorize an individual assessment.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('assessment', $exception->errors());
            }

            $this->assertSame($before, $fixture['assessment']->fresh()->getAttributes());
            $this->assertSame(1, $fixture['account']->assessments()->count());
        }
    }

    public function test_confirmed_adjustment_assessment_retains_exact_source_and_supersedes_idempotently(): void
    {
        $fixture = $this->accountFixture();
        $enrollment = $fixture['account']->enrollment;
        $proposal = RegistrationProposalVersion::factory()->for($enrollment)->create([
            'version' => 2,
            'purpose' => RegistrationProposalVersion::PurposeAdjustment,
            'state' => RegistrationProposalVersion::StateConfirmed,
        ]);
        RegistrationProposalConfirmation::factory()->for($proposal, 'proposalVersion')->create();
        $item = RegistrationProposalItem::factory()->for($proposal, 'proposalVersion')->create();
        $enrollment->update([
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'current_proposal_version_id' => $proposal->id,
        ]);
        $record = fn (int $expectedProposalVersionId): Assessment => app(RecordAuthorizedIndividualAssessment::class)->execute(
            $enrollment, $fixture['accounting'], Assessment::CategoryAuthorizedAdjustment, 'SYN-CONFIRMED-ADJUSTMENT-ASSESSMENT', CarbonImmutable::now(),
            [['code' => 'TOTAL', 'label' => 'Authorized changed total', 'amount' => '48000.00']],
            [['code' => 'ENROLLMENT', 'label' => 'Enrollment obligation', 'purpose' => 'Enrollment', 'amount' => '48000.00', 'due_at' => now()->toDateTimeString(), 'required_for_enrollment' => true]],
            expectedProposalVersionId: $expectedProposalVersionId,
        );

        $before = $fixture['assessment']->fresh()->getAttributes();
        $previous = RegistrationProposalVersion::factory()->for($enrollment)->recycle([$proposal->curriculumVersion, $proposal->timetableVersion])->create([
            'version' => 1, 'state' => RegistrationProposalVersion::StateSuperseded,
        ]);
        try {
            $record($previous->id);
            $this->fail('An open assessment form cannot silently assess a different current proposal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assessment', $exception->errors());
        }
        $this->assertSame($before, $fixture['assessment']->fresh()->getAttributes());
        $this->assertSame(1, $fixture['account']->assessments()->count());

        $assessment = $record($proposal->id);
        $again = $record($proposal->id);

        $this->assertSame($assessment->id, $again->id);
        $this->assertSame($proposal->id, $assessment->source_proposal_version_id);
        $this->assertSame($proposal->content_hash, $assessment->source_snapshot['proposal_content_hash']);
        $this->assertSame($item->course_code_snapshot, $assessment->source_snapshot['courses'][0]['course_code_snapshot']);
        $this->assertSame(Assessment::CategoryAuthorizedAdjustment, $assessment->reason_category);
        $this->assertSame(Assessment::StateSuperseded, $fixture['assessment']->fresh()->state);
        $this->assertSame($assessment->id, $fixture['assessment']->fresh()->superseded_by_assessment_id);
        $this->assertSame(2, $fixture['account']->assessments()->count());
    }

    public function test_accounting_account_keeps_reconciliation_unavailable_and_ended_states_contextual(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $reconciliation = $this->reconciliationFixture();
        $beforePayments = Payment::query()->count();
        $page = Livewire::actingAs($reconciliation['accounting'])->test(ViewEnrollment::class, ['record' => $reconciliation['account']->enrollment_id, 'enrollmentView' => 'account'])
            ->assertSee('Reconcile retained payment and coverage')->assertSee('Retained verified payment')
            ->assertDontSee('No payment due now')->assertDontSee('Remaining term fees')->assertDontSee('Five finalization checkpoints');
        $page->mountAction('reconcileAssessmentEffects')->assertMountedActionModalSee('Apply to');
        $page->unmountAction();

        $unavailable = $this->accountFixture();
        $unavailable['assessment']->update(['state' => Assessment::StateSuperseded]);
        Livewire::actingAs($unavailable['accounting'])->test(ViewEnrollment::class, ['record' => $unavailable['account']->enrollment_id, 'enrollmentView' => 'account'])
            ->assertSee('Account balance unavailable')->assertSee('Recorded charges')->assertSee('Account history')
            ->assertDontSee('No payment due now')->assertDontSee('Five finalization checkpoints');

        $ended = $this->accountFixture();
        $this->verifiedPayment($ended, '3000.00', 'SYN-ENDED-ACCOUNT-RETAINED');
        $ended['account']->enrollment->update(['canonical_outcome' => Enrollment::OutcomeCancelledByRegistrar]);
        Livewire::actingAs($ended['accounting'])->test(ViewEnrollment::class, ['record' => $ended['account']->enrollment_id, 'enrollmentView' => 'account'])
            ->assertSee('Registration ended')->assertSee('Accounting review required')->assertSee('Review financial history')
            ->assertSee('PHP 3,000.00 verified payment')
            ->assertDontSee('Enrollment payment satisfied')->assertDontSee('Enrollment payment pending')
            ->assertDontSee('Five finalization checkpoints');
        $this->assertSame($beforePayments + 1, Payment::query()->count());
        $ended['account']->enrollment->update(['canonical_outcome' => null]);
        Livewire::actingAs($ended['accounting'])->test(ViewEnrollment::class, ['record' => $ended['account']->enrollment_id, 'enrollmentView' => 'account'])
            ->assertSee('Registration outcome unavailable')->assertSee('Review financial history')
            ->assertDontSee('Registration ended')->assertDontSee('No payment due now');
    }

    public function test_accounting_account_leads_with_the_recorded_course_drop_financial_review(): void
    {
        $fixture = $this->recordedCourseDropFixture();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($fixture['accounting'])->test(ViewEnrollment::class, ['record' => $fixture['enrollment']->id, 'enrollmentView' => 'account'])
            ->assertSee('Review the Course Drop financial effect')->assertSee('Record individual assessment')
            ->assertSee('Recorded charges')->assertSee('Account history')->assertDontSee('Five finalization checkpoints');
        $this->assertSame(1, $fixture['enrollment']->corVersions()->count());
        $this->assertSame(1, $fixture['account']->assessments()->count());
    }

    public function test_accounting_pending_adjustment_waits_for_confirmation_then_offers_only_financial_work(): void
    {
        $fixture = $this->recordedCourseDropFixture();
        $case = $fixture['enrollment'];
        $current = $fixture['remainingCourse'];
        $prior = $case->currentProposalVersion;
        $replacement = Section::factory()->for($current->termOffering, 'termOffering')->create(['state' => Section::StateOpen]);
        $proposal = RegistrationProposalVersion::factory()->for($case)->recycle([$prior->curriculumVersion, $prior->timetableVersion])->create([
            'version' => 2, 'purpose' => RegistrationProposalVersion::PurposeAdjustment, 'state' => RegistrationProposalVersion::StateIssued,
        ]);
        RegistrationProposalItem::factory()->for($proposal, 'proposalVersion')->create(['section_id' => $replacement->id, 'term_offering_id' => $replacement->term_offering_id]);
        $case->update(['current_proposal_version_id' => $proposal->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($fixture['accounting'])->test(ViewEnrollment::class, ['record' => $case->id, 'enrollmentView' => 'account'])
            ->assertSee('Waiting for the confirmed registration change')->assertDontSee('Five finalization checkpoints')
            ->assertActionHidden('recordIndividualAssessment')->assertActionHidden('confirmNoAdditionalCost');
        $proposal->update(['state' => RegistrationProposalVersion::StateConfirmed]);
        RegistrationProposalConfirmation::factory()->for($proposal, 'proposalVersion')->create(['learner_user_id' => $fixture['learner']->id]);
        Livewire::actingAs($fixture['accounting'])->test(ViewEnrollment::class, ['record' => $case->id, 'enrollmentView' => 'account'])
            ->assertSee('Review the confirmed registration change')->assertSee($replacement->code)->assertSee('Record individual assessment')
            ->assertDontSee('Five finalization checkpoints')->assertActionVisible('recordIndividualAssessment');
        $this->assertSame(1, $case->corVersions()->count());
        $this->assertSame(1, $fixture['account']->assessments()->count());
    }

    public function test_ordinary_course_drop_without_a_routine_reference_remains_an_exact_accounting_assessment_source(): void
    {
        $fixture = $this->accountFixture();
        $enrollment = $fixture['account']->enrollment;
        $proposal = RegistrationProposalVersion::factory()->for($enrollment)->create(['state' => RegistrationProposalVersion::StateConfirmed]);
        $courses = collect([1, 2])->map(fn (): CourseEnrollment => CourseEnrollment::factory()->for($enrollment)->create());
        $cor = CorVersion::factory()->for($enrollment)->for($proposal, 'proposalVersion')->for($fixture['assessment'])->create([
            'snapshot' => ['program_id' => Program::factory()->create()->id,
                'courses' => $courses->map(fn (CourseEnrollment $course): array => ['course_enrollment_id' => $course->id, 'course_code' => 'SYN-'.$course->id, 'units' => '3.00'])->all()],
        ]);
        $enrollment->update(['canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled, 'current_proposal_version_id' => $proposal->id, 'current_cor_version_id' => $cor->id]);
        $package = TermCalendarPackage::factory()->for($enrollment->term)->create(['state' => TermCalendarPackage::StateActive, 'activated_at' => now()]);
        TermCalendarWindow::factory()->for($package, 'package')->create(['window_type' => TermCalendarWindow::TypeCourseDrop, 'opens_on' => now()->subDay()->toDateString(), 'closes_on' => now()->addDay()->toDateString(), 'cutoff_at' => '23:59:59']);
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(Role::findOrCreate(User::StaffRoleRegistrar, 'web'));
        $drop = app(RecordCourseDrop::class)->execute($enrollment, $courses->first(), $registrar, 'Approved ordinary Course Drop.');
        $this->assertNull($drop->authority_reference);
        $sources = app(IndividualAssessmentSources::class)->forEnrollment($enrollment->fresh());
        $this->assertSame($drop->id, $sources['course_drop']?->id);
        $assessment = app(RecordAuthorizedIndividualAssessment::class)->execute(
            $enrollment->fresh(), $fixture['accounting'], Assessment::CategoryAuthorizedAdjustment, 'GENUINE-ACCOUNTING-RESULT', CarbonImmutable::now(),
            [['code' => 'TOTAL', 'label' => 'Externally approved remaining charges', 'amount' => '42000.00']],
            [['code' => 'ENROLLMENT', 'label' => 'Authorized obligation', 'purpose' => 'Enrollment', 'amount' => '42000.00', 'due_at' => now()->toDateTimeString(), 'required_for_enrollment' => true]],
            courseDrop: $drop,
        );
        $this->assertSame('GENUINE-ACCOUNTING-RESULT', $assessment->authority_reference);
        $this->assertNull($assessment->source_snapshot['course_drop']['authority_reference']);
        $this->assertSame($drop->id, $assessment->source_snapshot['course_drop']['id']);
        $this->assertSame(2, $enrollment->corVersions()->count());
    }

    public function test_recorded_course_drop_assessment_freezes_the_exact_change_and_remaining_courses_without_rewriting_cor(): void
    {
        $fixture = $this->recordedCourseDropFixture();
        $beforeCor = $fixture['cor']->fresh()->getAttributes();
        $record = fn (string $authority = 'SYN-DROP-ACCOUNTING-RESULT'): Assessment => app(RecordAuthorizedIndividualAssessment::class)->execute(
            $fixture['enrollment'], $fixture['accounting'], Assessment::CategoryAuthorizedAdjustment, $authority, CarbonImmutable::now(),
            [['code' => 'TOTAL', 'label' => 'Externally determined remaining charges', 'amount' => '42000.00']],
            [['code' => 'ENROLLMENT', 'label' => 'Authorized obligation', 'purpose' => 'Enrollment', 'amount' => '42000.00', 'due_at' => now()->toDateTimeString(), 'required_for_enrollment' => true]],
            courseDrop: $fixture['drop'],
        );
        $sources = app(IndividualAssessmentSources::class)->forEnrollment($fixture['enrollment']);
        $this->assertSame($fixture['drop']->id, $sources['course_drop']?->id);
        $this->assertSame([Assessment::CategoryAuthorizedAdjustment], $sources['categories']);

        $assessment = $record();
        $again = $record();

        $this->assertSame($assessment->id, $again->id);
        $this->assertSame('SYN-DROP-ACCOUNTING-RESULT', $assessment->authority_reference);
        $this->assertSame($fixture['drop']->id, $assessment->source_snapshot['course_drop']['id']);
        $this->assertSame($fixture['drop']->authority_reference, $assessment->source_snapshot['course_drop']['authority_reference']);
        $this->assertSame($fixture['cor']->id, $assessment->source_snapshot['course_drop']['cor_version_id']);
        $this->assertSame($fixture['cor']->content_hash, $assessment->source_snapshot['course_drop']['cor_content_hash']);
        $this->assertSame([
            ['course_enrollment_id' => $fixture['remainingCourse']->id, 'course_code_snapshot' => 'SYN-REMAINING', 'units_snapshot' => '3.00'],
        ], $assessment->source_snapshot['courses']);
        $this->assertSame($fixture['enrollment']->term_id, $assessment->source_snapshot['term_id']);
        $this->assertSame('42000.00', $assessment->total);
        $this->assertSame(hash('sha256', json_encode($assessment->source_snapshot, JSON_THROW_ON_ERROR)), $assessment->content_hash);
        $this->assertSame('AssessmentRecorded', $fixture['drop']->fresh()->finance_state);
        $this->assertSame($beforeCor, $fixture['cor']->fresh()->getAttributes());
        $this->assertSame(Assessment::StateSuperseded, $fixture['assessment']->fresh()->state);
        $this->assertSame(2, $fixture['account']->assessments()->count());
        $this->assertNull(app(IndividualAssessmentSources::class)->forEnrollment($fixture['enrollment'])['course_drop']);
        $beforeAssessment = $assessment->fresh()->getAttributes();

        try {
            $record('SYN-ARBITRARY-SECOND-DROP-RESULT');
            $this->fail('A consumed recorded drop must not authorize another arbitrary successor.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assessment', $exception->errors());
        }

        $this->assertSame($beforeAssessment, $assessment->fresh()->getAttributes());
        $this->assertSame(2, $fixture['account']->assessments()->count());
    }

    public function test_course_drop_assessment_rejects_foreign_stale_and_pending_adjustment_sources(): void
    {
        foreach (['foreign_drop', 'stale_drop', 'pending_adjustment', 'active_course', 'wrong_category'] as $invalidSource) {
            $fixture = $this->recordedCourseDropFixture();
            $drop = $fixture['drop'];
            $category = Assessment::CategoryAuthorizedAdjustment;
            if ($invalidSource === 'foreign_drop') {
                $drop = $this->recordedCourseDropFixture()['drop'];
            } elseif ($invalidSource === 'stale_drop') {
                $drop = CourseDropRecord::factory()->create([
                    'enrollment_id' => $fixture['enrollment']->id,
                    'course_enrollment_id' => $fixture['droppedCourse']->id,
                ]);
            } elseif ($invalidSource === 'pending_adjustment') {
                $pending = RegistrationProposalVersion::factory()->for($fixture['enrollment'])->create([
                    'version' => 2, 'purpose' => RegistrationProposalVersion::PurposeAdjustment,
                ]);
                $fixture['enrollment']->update(['current_proposal_version_id' => $pending->id]);
            } elseif ($invalidSource === 'active_course') {
                $fixture['droppedCourse']->update(['is_current' => true, 'status' => CourseEnrollment::StatusActive]);
            } else {
                $category = Assessment::CategoryIndividuallyAdvised;
            }
            $before = $fixture['assessment']->fresh()->getAttributes();

            try {
                app(RecordAuthorizedIndividualAssessment::class)->execute(
                    $fixture['enrollment'], $fixture['accounting'], $category, 'SYN-INVALID-DROP-RESULT', CarbonImmutable::now(),
                    [['code' => 'TOTAL', 'label' => 'Externally determined remaining charges', 'amount' => '42000.00']],
                    [['code' => 'ENROLLMENT', 'label' => 'Authorized obligation', 'purpose' => 'Enrollment', 'amount' => '42000.00', 'due_at' => now()->toDateTimeString(), 'required_for_enrollment' => true]],
                    courseDrop: $drop,
                );
                $this->fail('An unsupported drop source must not create an assessment: '.$invalidSource);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('assessment', $exception->errors());
            }

            $this->assertSame($before, $fixture['assessment']->fresh()->getAttributes());
            $this->assertSame(1, $fixture['account']->assessments()->count());
            $this->assertSame('AccountingReviewPending', $fixture['drop']->fresh()->finance_state);
        }
    }

    public function test_individual_assessment_discovery_uses_current_confirmed_sources_and_excludes_terminal_or_unreserved_cases(): void
    {
        $fixture = $this->recordedCourseDropFixture();
        $query = app(IndividualAssessmentSources::class);
        $enrollment = $fixture['enrollment'];
        $pending = RegistrationProposalVersion::factory()->for($enrollment)->recycle([
            $enrollment->currentProposalVersion->curriculumVersion,
            $enrollment->currentProposalVersion->timetableVersion,
        ])->create(['version' => 2, 'purpose' => RegistrationProposalVersion::PurposeAdjustment]);
        $enrollment->update(['current_proposal_version_id' => $pending->id]);

        $sources = $query->forEnrollment($enrollment);

        $this->assertNull($sources['course_drop']);
        $this->assertFalse($sources['adjustment']);
        $this->assertSame([], $sources['categories']);
        $pending->update(['state' => RegistrationProposalVersion::StateIssued]);
        $pending->update(['state' => RegistrationProposalVersion::StateConfirmed]);
        $this->assertFalse($query->forEnrollment($enrollment)['adjustment']);
        RegistrationProposalConfirmation::factory()->for($pending, 'proposalVersion')->create();

        $sources = $query->forEnrollment($enrollment);

        $this->assertTrue($sources['adjustment']);
        $this->assertSame([Assessment::CategoryAuthorizedAdjustment], $sources['categories']);
        $enrollment->update(['canonical_outcome' => Enrollment::OutcomeCancelled]);
        $this->assertSame(['initial' => false, 'adjustment' => false, 'course_drop' => null, 'categories' => []], $query->forEnrollment($enrollment));
        $enrollment->update(['canonical_outcome' => Enrollment::OutcomeInProgress, 'selection_basis' => Enrollment::SelectionIndividuallyAdvised]);
        $this->assertFalse($query->forEnrollment($enrollment)['initial']);
    }

    public function test_individual_assessment_discovery_requires_valid_reserved_initial_proposal_and_eligible_case_basis(): void
    {
        Role::findOrCreate('student', 'web');
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(Role::findOrCreate(User::StaffRoleRegistrar, 'web'));
        $profile = StudentProfile::factory()->create();
        $profile->user->update(['status' => User::StatusActive]);
        $profile->user->assignRole('student');
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive, 'activated_at' => now(),
        ]);
        TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => now()->subDay()->toDateString(), 'closes_on' => now()->addWeek()->toDateString(), 'cutoff_at' => '23:59:59',
        ]);
        $entry = CurriculumEntry::factory()->for($profile->curriculumVersion)->create();
        $offering = TermOffering::factory()->for($term)->for($entry)->create(['state' => TermOffering::StateScheduled]);
        $section = Section::factory()->for($offering, 'termOffering')->create(['state' => Section::StateOpen]);
        $timetable = PublishedTimetableVersion::factory()->for($term)->create();
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $section->id]);
        $case = app(StartRegistrationCase::class)->forContinuingStudent($profile, $term, $profile->user);
        $case->update(['selection_basis' => Enrollment::SelectionIndividuallyAdvised]);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case->fresh(), $registrar, [$section->id], $case->fresh()->lock_version);
        app(PlaceRegistrationProposal::class)->execute($proposal, $registrar);
        $query = app(IndividualAssessmentSources::class);

        $sources = $query->forEnrollment($case);

        $this->assertTrue($sources['initial']);
        $this->assertSame([Assessment::CategoryIndividuallyAdvised, Assessment::CategoryReducedEnrollment], $sources['categories']);
        $record = fn (string $category): Assessment => app(RecordAuthorizedIndividualAssessment::class)->execute(
            $case, $this->accounting(), $category, 'SYN-INITIAL-CATEGORY-'.$category, CarbonImmutable::now(),
            [['code' => 'TOTAL', 'label' => 'External exact charges', 'amount' => '100.00']],
            [['code' => 'ENROLLMENT', 'label' => 'Enrollment obligation', 'purpose' => 'Enrollment', 'amount' => '100.00', 'due_at' => now()->toDateTimeString(), 'required_for_enrollment' => true]],
            expectedProposalVersionId: $proposal->id,
        );
        foreach ([Assessment::CategorySpecialTerm, Assessment::CategoryAuthorizedAdjustment] as $category) {
            try {
                $record($category);
                $this->fail('An initial advised proposal cannot use an unrelated assessment category.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('assessment', $exception->errors());
            }
        }
        $this->assertSame(0, Assessment::query()->where('enrollment_id', $case->id)->count());
        $this->assertSame(Assessment::CategoryIndividuallyAdvised, $record(Assessment::CategoryIndividuallyAdvised)->reason_category);
        $this->assertSame(Assessment::CategoryReducedEnrollment, $record(Assessment::CategoryReducedEnrollment)->reason_category);
        $case->update(['selection_basis' => Enrollment::SelectionStandardCurriculum]);
        $this->assertFalse($query->forEnrollment($case)['initial']);
        $term->update(['type' => Term::TypeSpecialTerm]);
        foreach ([Assessment::CategoryIndividuallyAdvised, Assessment::CategoryReducedEnrollment] as $category) {
            try {
                $record($category);
                $this->fail('Special Term alone does not establish an advised assessment category.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('assessment', $exception->errors());
            }
        }
        $case->update(['selection_basis' => Enrollment::SelectionIndividuallyAdvised]);
        $proposal->items()->sole()->reservation->update(['deadline' => now()->subMinute()]);
        $this->assertFalse($query->forEnrollment($case)['initial']);
    }

    public function test_drop_after_applied_adjustment_requires_the_explicit_drop_source_even_after_its_assessment_is_recorded(): void
    {
        $fixture = $this->recordedCourseDropFixture(RegistrationProposalVersion::PurposeAdjustment);
        $enrollment = $fixture['enrollment'];
        $proposal = $enrollment->currentProposalVersion;
        RegistrationProposalItem::factory()->for($proposal, 'proposalVersion')->create([
            'term_offering_id' => $fixture['droppedCourse']->term_offering_id,
            'section_id' => $fixture['droppedCourse']->section_id,
            'course_code_snapshot' => 'SYN-DROPPED',
        ]);
        $query = app(IndividualAssessmentSources::class);
        $record = fn (?CourseDropRecord $drop = null): Assessment => app(RecordAuthorizedIndividualAssessment::class)->execute(
            $enrollment, $fixture['accounting'], Assessment::CategoryAuthorizedAdjustment, 'SYN-APPLIED-ADJUSTMENT-THEN-DROP', CarbonImmutable::now(),
            [['code' => 'TOTAL', 'label' => 'Externally determined remaining charges', 'amount' => '42000.00']],
            [['code' => 'ENROLLMENT', 'label' => 'Authorized obligation', 'purpose' => 'Enrollment', 'amount' => '42000.00', 'due_at' => now()->toDateTimeString(), 'required_for_enrollment' => true]],
            courseDrop: $drop, expectedProposalVersionId: $proposal->id,
        );
        $this->assertFalse($query->forEnrollment($enrollment)['adjustment']);
        $this->assertSame($fixture['drop']->id, $query->forEnrollment($enrollment)['course_drop']?->id);

        foreach ([false, true] as $dropAlreadyAssessed) {
            if ($dropAlreadyAssessed) {
                $assessment = $record($fixture['drop']);
                $this->assertSame([$fixture['remainingCourse']->id], array_column($assessment->source_snapshot['courses'], 'course_enrollment_id'));
                $this->assertSame($assessment->id, $record($fixture['drop'])->id);
            }
            $before = $fixture['account']->assessments()->count();
            try {
                $record();
                $this->fail('The applied adjustment proposal must not bypass the exact later Course Drop source.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('assessment', $exception->errors());
            }
            $this->assertSame($before, $fixture['account']->assessments()->count());
            $this->assertFalse($query->forEnrollment($enrollment)['adjustment']);
        }
        $this->assertNull($query->forEnrollment($enrollment)['course_drop']);
        $this->assertSame([], $query->forEnrollment($enrollment)['categories']);

        $successor = RegistrationProposalVersion::factory()->for($enrollment)->recycle([$proposal->curriculumVersion, $proposal->timetableVersion])->create([
            'version' => 2, 'purpose' => RegistrationProposalVersion::PurposeAdjustment, 'state' => RegistrationProposalVersion::StateConfirmed,
        ]);
        RegistrationProposalConfirmation::factory()->for($successor, 'proposalVersion')->create();
        $enrollment->update(['current_proposal_version_id' => $successor->id]);
        $this->assertTrue($query->forEnrollment($enrollment)['adjustment']);
    }

    public function test_fixed_assessment_rejects_replaced_reviewed_proposal_and_published_plan_before_creating_account_effects(): void
    {
        [$case, $proposal, $accounting, $registrar, $section] = $this->unassessedAdvisedApplicantFixture();
        $plan = app(CreateFeePlanDraft::class)->execute(
            $case->admissionApplication->program, $case->term,
            [['code' => 'FIXED', 'label' => 'Published exact charges', 'amount' => '6000.00']], $accounting,
            [['code' => 'ENROLLMENT', 'label' => 'Enrollment obligation', 'purpose' => 'Enrollment', 'amount' => '6000.00', 'due_at' => now()->toDateTimeString(), 'required_for_enrollment' => true]],
        );
        $plan = app(PublishFeePlan::class)->execute($plan, $accounting, 'SYN-REVIEWED-FIXED-PLAN', CarbonImmutable::now());
        $replacement = Section::factory()->for($section->termOffering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 2]);
        PublishedTimetableMeeting::factory()->for($proposal->timetableVersion, 'timetableVersion')->create(['section_id' => $replacement->id]);
        $currentProposal = app(PrepareRegistrationProposal::class)->execute($case->fresh(), $registrar, [$replacement->id], $case->fresh()->lock_version);
        app(PlaceRegistrationProposal::class)->execute($currentProposal, $registrar);
        $this->assertNotSame($proposal->id, $currentProposal->id);

        try {
            app(CreateAssessmentFromPublishedFeePlan::class)->execute($case, $accounting, $proposal->id, $plan->id);
            $this->fail('Reviewed old subjects cannot be replaced by the current proposal during fixed assessment.');
        } catch (ValidationException $exception) {
            $this->assertSame(['assessment' => ['The proposal changed after this assessment was opened. Review the current subjects and Fee Plan before recording it.']], $exception->errors());
        }
        $this->assertSame(0, Assessment::query()->where('enrollment_id', $case->id)->count());
        $this->assertSame(0, TermAccount::query()->where('enrollment_id', $case->id)->count());

        $successor = app(CreateSuccessorFeePlan::class)->execute($plan, $accounting);
        $successor = app(PublishFeePlan::class)->execute($successor, $accounting, 'SYN-CURRENT-FIXED-PLAN', CarbonImmutable::now());
        try {
            app(CreateAssessmentFromPublishedFeePlan::class)->execute($case, $accounting, $currentProposal->id, $plan->id);
            $this->fail('A newer published Fee Plan cannot silently replace the reviewed fixed authority.');
        } catch (ValidationException $exception) {
            $this->assertSame(['assessment' => ['The published Fee Plan changed after this assessment was opened. Review its current charges and payment dates before recording it.']], $exception->errors());
        }
        $this->assertSame(0, Assessment::query()->where('enrollment_id', $case->id)->count());
        $this->assertSame(0, TermAccount::query()->where('enrollment_id', $case->id)->count());

        $assessment = app(CreateAssessmentFromPublishedFeePlan::class)->execute($case, $accounting, $currentProposal->id, $successor->id);
        $again = app(CreateAssessmentFromPublishedFeePlan::class)->execute($case, $accounting, $currentProposal->id, $successor->id);

        $this->assertSame($assessment->id, $again->id);
        $this->assertSame($currentProposal->id, $assessment->source_proposal_version_id);
        $this->assertSame($successor->id, $assessment->fee_plan_id);
        $this->assertSame('SYN-CURRENT-FIXED-PLAN', $assessment->authority_reference);
        $this->assertSame('6000.00', $assessment->total);
        $this->assertSame(1, TermAccount::query()->where('enrollment_id', $case->id)->count());
        $this->assertSame(1, Assessment::query()->where('enrollment_id', $case->id)->count());
        $this->assertNull($case->fresh()->student_profile_id);
    }

    public function test_mounted_fixed_plan_dialog_shows_reviewed_charges_and_dates_and_rejects_a_newer_publication_until_reviewed(): void
    {
        [$case, $proposal, $accounting] = $this->unassessedAdvisedApplicantFixture();
        $plan = app(CreateFeePlanDraft::class)->execute(
            $case->admissionApplication->program, $case->term,
            [['code' => 'FIXED', 'label' => 'Originally approved fixed charges', 'amount' => '6000.00']], $accounting,
            [['code' => 'ENROLLMENT', 'label' => 'Reviewed enrollment payment', 'purpose' => 'Enrollment', 'amount' => '6000.00', 'due_at' => '2026-09-15 09:00:00', 'required_for_enrollment' => true]],
        );
        $plan = app(PublishFeePlan::class)->execute($plan, $accounting, 'SYN-MOUNTED-PLAN-ORIGINAL', CarbonImmutable::parse('2026-08-20'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($accounting)->test(ViewEnrollment::class, ['record' => $case->id])
            ->mountAction('createAssessment')
            ->assertMountedActionModalSee('Originally approved fixed charges')->assertMountedActionModalSee('PHP 6,000.00')
            ->assertMountedActionModalSee('Reviewed enrollment payment')->assertMountedActionModalSee('Sep 15, 2026')
            ->assertMountedActionModalSee('SYN-MOUNTED-PLAN-ORIGINAL');
        $this->assertSame($plan->id, $component->get('mountedActions.0.data.reviewed_fee_plan_id'));
        $this->assertSame($proposal->id, $component->get('mountedActions.0.data.reviewed_proposal_id'));
        $successor = app(CreateSuccessorFeePlan::class)->execute($plan, $accounting);
        $successor = app(PublishFeePlan::class)->execute($successor, $accounting, 'SYN-MOUNTED-PLAN-SUCCESSOR', CarbonImmutable::parse('2026-08-25'));

        $component->callMountedAction()->assertHasNoActionErrors()->assertNotified('Assessment was not recorded');

        $this->assertSame(0, Assessment::query()->where('enrollment_id', $case->id)->count());
        $this->assertSame(0, TermAccount::query()->where('enrollment_id', $case->id)->count());
        Livewire::actingAs($accounting)->test(ViewEnrollment::class, ['record' => $case->id])
            ->mountAction('createAssessment')->assertMountedActionModalSee('SYN-MOUNTED-PLAN-SUCCESSOR')
            ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Assessment recorded');

        $assessment = Assessment::query()->where('enrollment_id', $case->id)->sole();
        $this->assertSame($successor->id, $assessment->fee_plan_id);
        $this->assertSame($proposal->id, $assessment->source_proposal_version_id);
        $this->assertSame('SYN-MOUNTED-PLAN-SUCCESSOR', $assessment->authority_reference);
        $this->assertSame('6000.00', $assessment->total);
        $this->assertNull($case->fresh()->student_profile_id);
    }

    public function test_accounting_individual_assessment_form_records_approved_charges_and_ordered_payments_before_student_activation(): void
    {
        [$case, $proposal, $accounting] = $this->unassessedAdvisedApplicantFixture();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($accounting)->test(ViewEnrollment::class, ['record' => $case->id])
            ->mountAction('recordIndividualAssessment');
        $chargeKey = array_key_first($component->get('mountedActions.0.data.charges'));

        $component->setActionData([
            'authority_reference' => 'SYN-APPROVED-6000', 'authority_date' => '2026-08-20',
            'charges' => [$chargeKey => ['label' => 'Approved fixed charges', 'amount' => '6000.00']],
            'enrollment_payment' => ['amount' => '1500.00', 'due_at' => '2026-08-25 17:00'],
            'later_payments' => [['amount' => '4500.00', 'due_at' => '2026-10-15 17:00']],
        ])->callMountedAction()->assertHasNoActionErrors()->assertNotified('Assessment recorded');

        $assessment = Assessment::query()->where('enrollment_id', $case->id)->sole();
        $this->assertSame('SYN-APPROVED-6000', $assessment->authority_reference);
        $this->assertSame('2026-08-20', $assessment->authority_date->toDateString());
        $this->assertSame('6000.00', $assessment->total);
        $this->assertSame('Approved fixed charges', $assessment->lines()->sole()->description_snapshot);
        $this->assertSame(['1500.00', '4500.00'], $assessment->obligations->pluck('amount')->all());
        $this->assertSame(['Enrollment', 'TermPayment'], $assessment->obligations->pluck('purpose')->all());
        $this->assertSame(['Enrollment payment', 'Later payment 1'], $assessment->obligations->pluck('label')->all());
        $this->assertSame([true, false], $assessment->obligations->pluck('required_for_enrollment')->all());
        $this->assertSame($proposal->id, $assessment->source_proposal_version_id);
        $this->assertSame(RegistrationProposalVersion::StateIssued, $proposal->fresh()->state);
        $this->assertNull($case->fresh()->student_profile_id);
        $this->assertSame(0, StudentProfile::query()->where('user_id', $case->credential_user_id)->count());
    }

    public function test_mounted_individual_assessment_form_preserves_reviewed_source_and_requires_explicit_zero_authority(): void
    {
        [$case, $proposal, $accounting, $registrar, $section] = $this->unassessedAdvisedApplicantFixture();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($accounting)->test(ViewEnrollment::class, ['record' => $case->id])
            ->mountAction('recordIndividualAssessment');
        $chargeKey = array_key_first($component->get('mountedActions.0.data.charges'));
        $component->setActionData([
            'authority_reference' => 'SYN-ZERO-FORM', 'authority_date' => '2026-08-20',
            'charges' => [$chargeKey => ['label' => 'Authorized zero charges', 'amount' => '0.00']],
            'enrollment_payment' => ['amount' => '0.00', 'due_at' => '2026-08-25 17:00'],
        ])->callMountedAction()->assertHasActionErrors(['no_payment_required_authority_reference' => 'required']);
        $this->assertSame(0, Assessment::query()->where('enrollment_id', $case->id)->count());

        $component = Livewire::actingAs($accounting)->test(ViewEnrollment::class, ['record' => $case->id])
            ->mountAction('recordIndividualAssessment');
        $chargeKey = array_key_first($component->get('mountedActions.0.data.charges'));
        $component->setActionData([
            'authority_reference' => 'SYN-STALE-FORM', 'authority_date' => '2026-08-20',
            'charges' => [$chargeKey => ['label' => 'Approved exact charges', 'amount' => '6000.00']],
            'enrollment_payment' => ['amount' => '6000.00', 'due_at' => '2026-08-25 17:00'],
        ]);
        $replacement = Section::factory()->for($section->termOffering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 2]);
        PublishedTimetableMeeting::factory()->for($proposal->timetableVersion, 'timetableVersion')->create(['section_id' => $replacement->id]);
        $successor = app(PrepareRegistrationProposal::class)->execute($case->fresh(), $registrar, [$replacement->id], $case->fresh()->lock_version);
        app(PlaceRegistrationProposal::class)->execute($successor, $registrar);
        $this->assertNotSame($proposal->id, $successor->id);
        $component->callMountedAction()->assertHasNoActionErrors()->assertNotified('Assessment was not recorded');

        $this->assertSame(0, Assessment::query()->where('enrollment_id', $case->id)->count());
        $this->assertSame($successor->id, $case->fresh()->current_proposal_version_id);
        $this->assertNull($case->fresh()->student_profile_id);
    }

    public function test_ordinary_official_case_has_no_individual_assessment_form_action(): void
    {
        $fixture = $this->recordedCourseDropFixture();
        $ordinaryCor = CorVersion::factory()->for($fixture['enrollment'])->for($fixture['enrollment']->currentProposalVersion, 'proposalVersion')
            ->for($fixture['enrollment']->currentProposalVersion->timetableVersion, 'timetableVersion')->for($fixture['assessment'])->create([
                'version' => 2, 'snapshot' => ['courses' => $fixture['cor']->snapshot['courses']],
            ]);
        $fixture['enrollment']->update(['current_cor_version_id' => $ordinaryCor->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($fixture['accounting'])->test(ViewEnrollment::class, ['record' => $fixture['enrollment']->id])
            ->assertActionHidden('recordIndividualAssessment');
    }

    /** @return array{Enrollment,RegistrationProposalVersion,User,User,Section} */
    private function unassessedAdvisedApplicantFixture(): array
    {
        Mail::fake();
        Role::findOrCreate('applicant', 'web');
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(Role::findOrCreate(User::StaffRoleRegistrar, 'web'));
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $package = TermCalendarPackage::factory()->for($term)->create(['state' => TermCalendarPackage::StateActive, 'activated_at' => now()]);
        TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeEnrollment, 'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addWeek()->toDateString(), 'cutoff_at' => '23:59:59',
        ]);
        $cycle = AdmissionCycle::factory()->for($term)->create();
        $application = AdmissionApplication::factory()->for($cycle, 'admissionCycle')->create([
            'application_state' => AdmissionApplication::StateAdmitted,
            'application_path' => AdmissionApplication::PathTransferee,
        ]);
        $application->user->assignRole('applicant');
        $requirements = AdmissionRequirementSet::factory()->published()->for($cycle)->create(['application_path' => $application->application_path]);
        $submission = ApplicationSubmissionVersion::factory()->for($application, 'application')->for($requirements, 'requirementSet')->create(['submitted_by' => $application->user_id]);
        $application->update(['current_submission_version_id' => $submission->id]);
        $decision = AdmissionDecision::factory()->admitted()->for($application, 'application')->create(['application_submission_version_id' => $submission->id]);
        RegistrarEnrollmentClearance::factory()->forAdmittedApplication($application)->create([
            'result' => RegistrarEnrollmentClearance::ResultCleared, 'external_checks_confirmed' => true, 'recorded_by' => $decision->decided_by,
        ]);
        $curriculum = CurriculumVersion::factory()->create(['program_id' => $application->program_id, 'effective_entry_term_id' => $term->id, 'state' => CurriculumVersion::StateActive]);
        $entry = CurriculumEntry::factory()->for($curriculum)->create();
        $offering = TermOffering::factory()->for($term)->for($entry)->create(['state' => TermOffering::StateScheduled]);
        $section = Section::factory()->for($offering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 2]);
        $timetable = PublishedTimetableVersion::factory()->for($term)->create();
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $section->id]);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application->fresh(), $term, $application->user);
        app(RecordRegistrationCreditEvaluation::class)->execute($case, $registrar, [], 'SYN-APPROVED-TRANSFER-EVALUATION', CarbonImmutable::today(), (int) $application->current_submission_version_id, $curriculum->id, $case->lock_version);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case->fresh(), $registrar, [$section->id], $case->fresh()->lock_version);
        app(PlaceRegistrationProposal::class)->execute($proposal, $registrar);

        return [$case->fresh(), $proposal->fresh(), $this->accounting(), $registrar, $section];
    }

    /** @return array<string, mixed> */
    private function recordedCourseDropFixture(string $proposalPurpose = RegistrationProposalVersion::PurposeInitial): array
    {
        $fixture = $this->accountFixture();
        $enrollment = $fixture['account']->enrollment;
        $proposal = RegistrationProposalVersion::factory()->for($enrollment)->recycle($enrollment->term)->create([
            'purpose' => $proposalPurpose,
            'state' => RegistrationProposalVersion::StateConfirmed,
        ]);
        RegistrationProposalConfirmation::factory()->for($proposal, 'proposalVersion')->create();
        $droppedCourse = CourseEnrollment::factory()->for($enrollment)->recycle([$proposal->curriculumVersion, $enrollment->term, $proposal->timetableVersion])->create([
            'is_current' => false, 'status' => CourseEnrollment::StatusDropped, 'dropped_at' => now(),
        ]);
        $remainingCourse = CourseEnrollment::factory()->for($enrollment)->recycle([$proposal->curriculumVersion, $enrollment->term, $proposal->timetableVersion])->create();
        $drop = CourseDropRecord::factory()->for($enrollment)->for($droppedCourse, 'courseEnrollment')->create();
        $cor = CorVersion::factory()->for($enrollment)->for($proposal, 'proposalVersion')->for($proposal->timetableVersion, 'timetableVersion')->for($fixture['assessment'])->create([
            'snapshot' => [
                'program_id' => $remainingCourse->termOffering->curriculumEntry->curriculumVersion->program_id,
                'courses' => [['course_enrollment_id' => $remainingCourse->id, 'course_code' => 'SYN-REMAINING', 'units' => '3.00']],
                'change' => ['type' => 'CourseDrop', 'record_id' => $drop->id, 'finance_state' => 'AccountingReviewPending'],
            ],
        ]);
        $enrollment->update([
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'current_proposal_version_id' => $proposal->id,
            'current_cor_version_id' => $cor->id,
        ]);

        return [...$fixture, 'enrollment' => $enrollment, 'drop' => $drop, 'cor' => $cor, 'droppedCourse' => $droppedCourse, 'remainingCourse' => $remainingCourse];
    }

    /** @return array{accounting:User,learner:User,account:TermAccount,assessment:Assessment,obligations:list<AssessmentObligation>} */
    public function test_student_finance_keeps_one_account_composition_through_proof_review_rejection_and_coverage(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00', 'Asia/Manila'));
        Storage::fake('local');
        $fixture = $this->accountFixture();
        $fixture['learner']->assignRole(Role::findOrCreate('student', 'web'));
        $profile = StudentProfile::factory()->create(['user_id' => $fixture['learner']->id]);
        $fixture['account']->enrollment->update(['student_profile_id' => $profile->id]);
        Filament::setCurrentPanel(Filament::getPanel('student'));
        $beforePayments = Payment::query()->count();

        $page = Livewire::actingAs($fixture['learner'])->test(StudentFinance::class)
            ->assertSee('Your next payment')->assertSee('Payment schedule')->assertSee('PHP 12,000.00')
            ->assertSee('PHP 36,000.00')->assertDontSee('Enrollment requirement remaining')
            ->assertActionVisible('submitEvidence');
        $page->mountAction('submitEvidence')->setActionData([
            'claimed_amount' => '0', 'channel' => 'bank_transfer', 'paid_at' => now()->subHour()->toDateTimeString(),
        ])->callMountedAction()->assertHasActionErrors(['evidence' => 'required', 'claimed_amount' => 'min'])
            ->assertMountedActionModalSee('Choose a PDF, JPG or PNG receipt.')
            ->assertMountedActionModalSee('Enter an amount greater than zero.')->call('unmountAction');
        $this->assertSame(0, $fixture['account']->paymentEvidenceVersions()->count());
        $proof = app(SubmitPaymentEvidence::class)->execute($fixture['account'], $fixture['learner'],
            UploadedFile::fake()->image('private-proof.png'), '12000.00', 'bank_transfer', now()->subHour(), 'SYN-FINANCE-PROOF');
        $page->call('refreshFinance')->assertSee('Payment proof awaiting review')->assertSee('PHP 48,000.00')
            ->assertSee(route('finance.payment-evidence.download', $proof));
        app(ReviewPaymentEvidence::class)->reject($proof, $fixture['accounting'], 'Replace the unreadable receipt <script>unsafe()</script>');
        $page->call('refreshFinance')->assertSee('Payment proof needs correction')->assertSee('Upload corrected proof')
            ->assertSee('Replace the unreadable receipt <script>unsafe()</script>')->assertDontSeeHtml('<script>unsafe()</script>');
        app(RecordApprovedCoverage::class)->execute($fixture['account'], $fixture['obligations'][0], [
            'category' => ApprovedCoverage::CategoryScholarship, 'safe_source_description' => 'Recorded study grant',
            'authority_reference' => 'SYN-FINANCE-COVERAGE', 'authority_date' => today()->toDateString(),
            'effective_date' => today()->toDateString(), 'amount' => '12000.00',
        ], $fixture['accounting']);
        $page->call('refreshFinance')->assertSee('No payment due today')->assertSee('Satisfied')
            ->assertDontSee('Payment proof needs correction')->assertDontSeeHtml('>Paid<')
            ->assertSee('Recorded study grant')->assertSee('Rejected')->assertSee('Submitted receipts')
            ->assertActionHidden('submitEvidence')->assertActionHidden('payExactCurrentDue');
        $this->assertSame($beforePayments, Payment::query()->count());
        $this->assertSame(PaymentEvidenceVersion::StateRejected, $proof->fresh()->state);
    }

    public function test_student_overview_distinguishes_submitted_and_rejected_proof_without_changing_money(): void
    {
        Storage::fake('local');
        $fixture = $this->accountFixture();
        $fixture['learner']->assignRole(Role::findOrCreate('student', 'web'));
        $profile = StudentProfile::factory()->create(['user_id' => $fixture['learner']->id]);
        $fixture['account']->enrollment->update(['student_profile_id' => $profile->id]);
        Filament::setCurrentPanel(Filament::getPanel('student'));
        $position = app(TermAccountProjection::class)->forAccount($fixture['account']);
        $proof = app(SubmitPaymentEvidence::class)->execute(
            $fixture['account'], $fixture['learner'], UploadedFile::fake()->image('overview-proof.png'),
            '12000.00', 'bank_transfer', now()->subMinute(), null,
        );

        Livewire::actingAs($fixture['learner'])->test(StudentDashboard::class)
            ->assertSee('Your payment proof is under review')->assertSee('PHP 12,000.00')
            ->assertSee('No action is needed while it is reviewed.')
            ->assertDontSee('Your last payment evidence was rejected.');

        app(ReviewPaymentEvidence::class)->reject($proof, $fixture['accounting'], 'Please send a readable receipt.');
        Livewire::test(StudentDashboard::class)
            ->assertSee('Your last payment evidence was rejected.')
            ->assertSee('Submit replacement payment evidence for review.')
            ->assertDontSee('No action is needed while it is reviewed.');

        $this->assertSame(PaymentEvidenceVersion::StateRejected, $proof->fresh()->state);
        $this->assertSame($position, app(TermAccountProjection::class)->forAccount($fixture['account']));
        $this->assertFalse(Payment::query()->where('term_account_id', $fixture['account']->id)->exists());
    }

    public function test_student_overview_never_promises_preparation_for_closed_or_unknown_enrollment(): void
    {
        $fixture = $this->accountFixture();
        $fixture['learner']->assignRole(Role::findOrCreate('student', 'web'));
        $profile = StudentProfile::factory()->create(['user_id' => $fixture['learner']->id]);
        $case = $fixture['account']->enrollment;
        $case->update(['student_profile_id' => $profile->id]);
        Filament::setCurrentPanel(Filament::getPanel('student'));
        $this->actingAs($fixture['learner']);

        foreach ([
            [Enrollment::OutcomeCancelledByRegistrar, 'This enrollment is closed'],
            [null, 'Ask the Registrar about this enrollment'],
        ] as [$outcome, $guidance]) {
            $case->update(['canonical_outcome' => $outcome]);
            Livewire::test(StudentDashboard::class)->assertSee($guidance)
                ->assertDontSee('The Registrar is preparing your enrollment');
            $this->assertSame($outcome, $case->fresh()->canonical_outcome);
        }
    }

    public function test_student_payment_modals_show_only_current_owned_checkout_targets_without_starting_payment(): void
    {
        Http::preventStrayRequests();
        config([
            'tala_integrations.payments.driver' => 'paymongo',
            'tala_integrations.payments.paymongo.secret_key' => 'synthetic-preview-key',
            'tala_integrations.payments.paymongo.webhook_signature' => 'synthetic-preview-signature',
            'queue.default' => 'database',
        ]);
        $fixture = $this->accountFixture();
        $fixture['learner']->assignRole(Role::findOrCreate('student', 'web'));
        $profile = StudentProfile::factory()->create(['user_id' => $fixture['learner']->id]);
        $case = $fixture['account']->enrollment;
        $proposal = RegistrationProposalVersion::factory()->create([
            'enrollment_id' => $case->id, 'state' => RegistrationProposalVersion::StateConfirmed,
        ]);
        RegistrationProposalConfirmation::factory()->create([
            'registration_proposal_version_id' => $proposal->id, 'learner_user_id' => $fixture['learner']->id,
        ]);
        $case->update(['student_profile_id' => $profile->id, 'current_proposal_version_id' => $proposal->id]);
        $fixture['assessment']->update(['source_proposal_version_id' => $proposal->id]);
        $beforeAttempts = PaymentAttempt::query()->count();
        $beforePayments = Payment::query()->count();
        Filament::setCurrentPanel(Filament::getPanel('student'));

        foreach ([[StudentEnrollment::class, 'submitPaymentEvidence'], [StudentFinance::class, 'submitEvidence']] as [$pageClass, $proofAction]) {
            $page = Livewire::actingAs($fixture['learner'])->test($pageClass)
                ->mountAction($proofAction)->assertActionMounted($proofAction)
                ->assertActionDataSet(['claimed_amount' => null, 'payment_reference' => null]);
            $page->unmountAction()->mountAction('payExactCurrentDue')->assertActionMounted('payExactCurrentDue')
                ->assertMountedActionModalSee('PHP 12,000.00')
                ->assertMountedActionModalSee('Enrollment obligation')
                ->assertMountedActionModalDontSee('Midterm obligation')
                ->assertMountedActionModalDontSee('Final obligation')
                ->assertMountedActionModalDontSee('PHP 48,000.00');
            $page->unmountAction();
        }

        $this->assertSame($beforeAttempts, PaymentAttempt::query()->count());
        $this->assertSame($beforePayments, Payment::query()->count());
        $this->assertSame(0, $fixture['account']->paymentEvidenceVersions()->count());
        Http::assertNothingSent();
    }

    public function test_student_finance_review_required_keeps_online_checkout_blocked_and_money_unchanged(): void
    {
        $fixture = $this->accountFixture();
        $fixture['learner']->assignRole(Role::findOrCreate('student', 'web'));
        $snapshot = app(ExactDuePaymentSnapshotService::class)->forAccount($fixture['account']);
        $attempt = PaymentAttempt::query()->create([
            'assessment_id' => $snapshot['assessment_id'], 'term_account_id' => $fixture['account']->id,
            'assessment_version' => $snapshot['assessment_version'], 'snapshot_created_at' => $snapshot['created_at'],
            'snapshot_checksum' => $snapshot['checksum'], 'internal_reference' => 'SYN-FINANCE-REVIEW-REQUIRED',
            'provider' => 'mock', 'channel' => 'paymongo', 'amount' => $snapshot['amount'], 'currency' => 'PHP',
            'status' => PaymentAttempt::StatusReviewRequired,
        ]);
        $before = app(TermAccountProjection::class)->forAccount($fixture['account']);
        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::actingAs($fixture['learner'])->test(StudentFinance::class)
            ->assertSee('Online payment needs Accounting review')->assertSee('Refresh status')
            ->assertSee('Wait for their review before paying again.')->assertDontSee('Payment Exception')
            ->assertActionDisabled('payExactCurrentDue')->assertActionVisible('submitEvidence');
        $this->assertSame($before, app(TermAccountProjection::class)->forAccount($fixture['account']));
        $this->assertSame(PaymentAttempt::StatusReviewRequired, $attempt->fresh()->status);
        $this->assertSame(0, $fixture['account']->payments()->count());
        $attempt->update(['status' => PaymentAttempt::StatusPending]);
        Livewire::actingAs($fixture['learner'])->test(StudentFinance::class)
            ->assertSee('Payment confirmation pending')->assertSee('Refresh the status after completing payment with the provider.')
            ->assertActionDisabled('payExactCurrentDue')->assertActionVisible('submitEvidence');
        $this->assertSame($before, app(TermAccountProjection::class)->forAccount($fixture['account']));
        $this->assertSame(0, $fixture['account']->payments()->count());

    }

    public function test_student_finance_unavailable_and_foreign_sources_never_become_zero_balance_or_owned_history(): void
    {
        $fixture = $this->accountFixture();
        $fixture['learner']->assignRole(Role::findOrCreate('student', 'web'));
        $other = $this->accountFixture();
        $foreign = PaymentEvidenceVersion::factory()->create(['term_account_id' => $other['account']->id,
            'original_name' => 'FOREIGN-PRIVATE-PROOF.pdf', 'submitted_by' => $other['learner']->id]);
        $fixture['assessment']->update(['state' => Assessment::StateSuperseded]);
        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::actingAs($fixture['learner'])->test(StudentFinance::class)
            ->assertSee('Account information unavailable')->assertDontSee('No payment due today')
            ->assertDontSee('Amount due now')->assertDontSee('FOREIGN-PRIVATE-PROOF.pdf')
            ->assertActionHidden('statement')->assertActionHidden('submitEvidence')->assertActionHidden('payExactCurrentDue');
        $this->actingAs($fixture['learner'])->get(route('finance.payment-evidence.download', $foreign))->assertForbidden();
    }

    public function test_past_finance_records_are_owned_reading_while_current_actions_keep_the_latest_account(): void
    {
        Storage::fake('local');
        $past = $this->accountFixture();
        $past['learner']->assignRole(Role::findOrCreate('student', 'web'));
        $past['account']->term->update(['label' => 'Past synthetic term']);
        $proof = app(SubmitPaymentEvidence::class)->execute($past['account'], $past['learner'],
            UploadedFile::fake()->image('past-private-proof.png'), '100.00', 'bank_transfer', now()->subHour(), 'PAST-PROOF');
        app(ReviewPaymentEvidence::class)->reject($proof, $past['accounting'], 'Past note <script>unsafe()</script>');
        $current = $this->accountFixture();
        $current['account']->update(['credential_user_id' => $past['learner']->id]);
        $current['account']->enrollment->update(['credential_user_id' => $past['learner']->id]);
        $current['account']->term->update(['label' => 'Current synthetic term']);
        Filament::setCurrentPanel(Filament::getPanel('student'));

        $page = Livewire::actingAs($past['learner'])->test(StudentFinance::class)
            ->assertSee('Past statements and payments')->assertSee('Current synthetic term')
            ->assertDontSee('past-private-proof.png')->assertActionVisible('submitEvidence');
        $this->assertSame($current['account']->id, $page->get('finance')['account']->id);
        $page->callAction('submitEvidence', data: ['evidence' => UploadedFile::fake()->image('current-private-proof.png'),
            'claimed_amount' => '100.00', 'channel' => 'bank_transfer', 'paid_at' => now()->subHour()->toDateTimeString()])
            ->assertHasNoActionErrors();
        $this->assertSame('current-private-proof.png', $current['account']->paymentEvidenceVersions()->sole()->original_name);
        $this->assertSame(1, $past['account']->paymentEvidenceVersions()->count());

        Livewire::withQueryParams(['view' => 'history'])->actingAs($past['learner'])->test(StudentFinance::class)
            ->assertSee('Past synthetic term')->assertDontSee('Current synthetic term')
            ->assertActionHidden('submitEvidence')->assertActionHidden('payExactCurrentDue');
        $history = Livewire::withQueryParams(['view' => 'history', 'account' => (string) $past['account']->id])
            ->actingAs($past['learner'])->test(StudentFinance::class)
            ->assertSee('past-private-proof.png')->assertSee('Past note <script>unsafe()</script>')
            ->assertDontSeeHtml('<script>unsafe()</script>')->assertDontSee('current-private-proof.png')
            ->assertSee(route('finance.statement', $past['assessment']))
            ->assertSee(route('finance.payment-evidence.download', $proof))
            ->assertActionHidden('statement')->assertActionHidden('submitEvidence')->assertActionHidden('payExactCurrentDue');
        $this->assertSame($current['account']->id, $history->get('finance')['account']->id);
        $profile = StudentProfile::factory()->create(['user_id' => $past['learner']->id, 'lifecycle_status' => StudentProfile::LifecycleCompleted]);
        $current['account']->enrollment->update(['student_profile_id' => $profile->id]);
        Livewire::withQueryParams([])->actingAs($past['learner'])->test(StudentFinance::class)
            ->assertActionHidden('submitEvidence')->assertActionDisabled('payExactCurrentDue')->assertActionVisible('statement');
        Livewire::withQueryParams(['view' => 'history', 'account' => (string) $past['account']->id])
            ->actingAs($past['learner'])->test(StudentFinance::class)->assertSee('past-private-proof.png')
            ->assertActionHidden('submitEvidence')->assertActionHidden('payExactCurrentDue');
    }

    public function test_past_finance_selection_rejects_foreign_missing_and_invalid_ids_without_disclosure(): void
    {
        $fixture = $this->accountFixture();
        $fixture['learner']->assignRole(Role::findOrCreate('student', 'web'));
        $foreign = $this->accountFixture();
        $foreign['account']->term->update(['label' => 'FOREIGN-PRIVATE-TERM']);
        Filament::setCurrentPanel(Filament::getPanel('student'));
        Livewire::actingAs($fixture['learner'])->test(StudentFinance::class)->assertDontSee('Past statements and payments');
        Livewire::withQueryParams(['view' => 'history'])->actingAs($fixture['learner'])->test(StudentFinance::class)
            ->assertSee('No earlier term accounts are recorded.')->assertDontSee('FOREIGN-PRIVATE-TERM');
        foreach ([(string) $foreign['account']->id, '999999999', '0', '-1', 'abc', ['1']] as $id) {
            Livewire::withQueryParams(['view' => 'history', 'account' => $id])->actingAs($fixture['learner'])
                ->test(StudentFinance::class)->assertStatus(404)->assertDontSee('FOREIGN-PRIVATE-TERM');
        }
    }

    public function test_accounting_rejection_form_refuses_a_proof_from_another_selected_account(): void
    {
        $selected = $this->accountFixture();
        $foreign = $this->accountFixture();
        $ownProof = $this->evidence($selected, ['term_account_id' => $selected['account']->id, 'channel' => 'bank_transfer',
            'paid_at' => now()->subMinute(), 'submitted_by' => $selected['learner']->id]);
        $foreignProof = $this->evidence($foreign, ['term_account_id' => $foreign['account']->id, 'channel' => 'bank_transfer',
            'paid_at' => now()->subMinute(), 'submitted_by' => $foreign['learner']->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($selected['accounting'])->test(ViewEnrollment::class, ['record' => $selected['account']->enrollment_id])
            ->callAction('rejectPaymentEvidence', ['evidence_id' => $foreignProof->id, 'safe_reason' => 'Wrong selected account.'])
            ->assertHasActionErrors(['evidence_id']);
        $this->assertSame(PaymentEvidenceVersion::StateSubmitted, $ownProof->fresh()->state);
        $this->assertSame(PaymentEvidenceVersion::StateSubmitted, $foreignProof->fresh()->state);
        $this->assertSame(0, Payment::query()->whereIn('term_account_id', [$selected['account']->id, $foreign['account']->id])->count());
    }

    public function test_selected_proof_review_preserves_choice_and_preview_matches_the_posted_allocation(): void
    {
        $fixture = $this->accountFixture();
        $fixture['obligations'][1]->update(['due_at' => now()->subMinute()]);
        $attributes = ['term_account_id' => $fixture['account']->id, 'channel' => 'bank_transfer',
            'paid_at' => now()->subMinute(), 'submitted_by' => $fixture['learner']->id];
        $first = $this->evidence($fixture, [...$attributes, 'original_name' => 'first-receipt.pdf']);
        $second = $this->evidence($fixture, [...$attributes, 'version' => 2, 'original_name' => 'second-receipt.pdf']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($fixture['accounting'])->test(ViewEnrollment::class, ['record' => $fixture['account']->enrollment_id])
            ->set('paymentProofId', $first->id)->assertSee('Review payment proof')->assertSee('first-receipt.pdf')
            ->assertSee('Submitted payment proofs')->assertSee('second-receipt.pdf')
            ->mountAction('verifyPaymentEvidence')->assertMountedActionModalSee('Record verified payment')
            ->setActionData(['actual_verified_amount' => '16000.25', 'external_check_reference' => 'BANK-CHECK-SELECTED']);
        $preview = $page->instance()->paymentProofViewData('16000.25');
        $this->assertSame([['label' => 'Enrollment obligation', 'applied' => '12000.00', 'remaining' => '0.00'],
            ['label' => 'Midterm obligation', 'applied' => '4000.25', 'remaining' => '13999.75']], $preview['allocation']);
        $this->assertSame(0, Payment::query()->where('term_account_id', $fixture['account']->id)->count());
        $page->callMountedAction()->assertHasNoActionErrors()->assertNotified('Payment evidence verified');
        $payment = Payment::query()->where('payment_evidence_version_id', $first->id)->firstOrFail();
        $this->assertSame('16000.25', $payment->amount);
        $this->assertSame(['12000.00', '4000.25'], $payment->allocations->sortBy('sequence')->pluck('amount')->all());
        $this->assertSame([$fixture['obligations'][0]->id, $fixture['obligations'][1]->id], $payment->allocations->sortBy('sequence')->pluck('assessment_obligation_id')->all());
        $this->assertSame(PaymentEvidenceVersion::StateSubmitted, $second->fresh()->state);
    }

    public function test_selected_proof_forms_reject_missing_result_excess_amount_and_stale_source_without_money_changes(): void
    {
        $fixture = $this->accountFixture();
        $proof = $this->evidence($fixture, ['term_account_id' => $fixture['account']->id, 'channel' => 'bank_transfer',
            'paid_at' => now()->subMinute(), 'submitted_by' => $fixture['learner']->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($fixture['accounting'])->test(ViewEnrollment::class, ['record' => $fixture['account']->enrollment_id])
            ->mountAction('verifyPaymentEvidence')
            ->setActionData(['actual_verified_amount' => '0', 'external_check_reference' => ''])
            ->callMountedAction()->assertHasActionErrors(['actual_verified_amount' => 'min', 'external_check_reference' => 'required']);
        $page->setActionData(['actual_verified_amount' => '12000.01', 'external_check_reference' => 'BANK-EXCESS'])
            ->callMountedAction()->assertNotified('Evidence was not verified');
        $this->assertNotNull($page->instance()->paymentProofViewData('12000.01')['allocationError']);
        $page->mountAction('verifyPaymentEvidence');
        $fixture['assessment']->update(['state' => Assessment::StateSuperseded]);
        $page->setActionData(['actual_verified_amount' => '100', 'external_check_reference' => 'BANK-STALE'])
            ->callMountedAction()->assertNotified('Evidence was not verified');
        $this->assertFalse($page->instance()->paymentProofViewData('100')['sourceCurrent']);
        $this->assertSame(PaymentEvidenceVersion::StateSubmitted, $proof->fresh()->state);
        $this->assertSame(0, Payment::query()->where('term_account_id', $fixture['account']->id)->count());
        $this->assertSame(0, PaymentAllocation::query()->whereHas('payment', fn ($query) => $query->where('term_account_id', $fixture['account']->id))->count());
    }

    public function test_selected_proof_rejection_requires_learner_reason_and_preserves_account_balance(): void
    {
        $fixture = $this->accountFixture();
        $proof = $this->evidence($fixture, ['term_account_id' => $fixture['account']->id, 'channel' => 'bank_transfer',
            'paid_at' => now()->subMinute(), 'submitted_by' => $fixture['learner']->id]);
        $before = app(TermAccountProjection::class)->forAccount($fixture['account']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($fixture['accounting'])->test(ViewEnrollment::class, ['record' => $fixture['account']->enrollment_id])
            ->mountAction('rejectPaymentEvidence')->callMountedAction()->assertHasActionErrors(['safe_reason' => 'required'])
            ->setActionData(['safe_reason' => 'The bank could not confirm this reference. Upload the corrected receipt.'])
            ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Payment evidence rejected');
        $this->assertSame(PaymentEvidenceVersion::StateRejected, $proof->fresh()->state);
        $this->assertSame('The bank could not confirm this reference. Upload the corrected receipt.', $proof->fresh()->review_note);
        $this->assertSame($before, app(TermAccountProjection::class)->forAccount($fixture['account']->fresh()));
        $this->assertSame(0, Payment::query()->where('term_account_id', $fixture['account']->id)->count());
    }

    public function test_review_form_cannot_switch_its_selected_proof_or_decide_a_proof_reviewed_elsewhere(): void
    {
        $fixture = $this->accountFixture();
        $attributes = ['term_account_id' => $fixture['account']->id, 'channel' => 'bank_transfer',
            'paid_at' => now()->subMinute(), 'submitted_by' => $fixture['learner']->id];
        $selected = $this->evidence($fixture, $attributes);
        $other = $this->evidence($fixture, [...$attributes, 'version' => 2]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($fixture['accounting'])->test(ViewEnrollment::class, ['record' => $fixture['account']->enrollment_id])
            ->set('paymentProofId', $selected->id)->mountAction('rejectPaymentEvidence')
            ->setActionData(['evidence_id' => $other->id, 'safe_reason' => 'This is a different receipt.'])
            ->callMountedAction()->assertHasActionErrors(['evidence_id']);
        $this->assertSame(PaymentEvidenceVersion::StateSubmitted, $selected->fresh()->state);
        $this->assertSame(PaymentEvidenceVersion::StateSubmitted, $other->fresh()->state);
        app(ReviewPaymentEvidence::class)->reject($selected, $fixture['accounting'], 'Already reviewed by Accounting.');
        $page->setActionData(['evidence_id' => $selected->id, 'safe_reason' => 'A stale replacement decision.'])
            ->callMountedAction()->assertHasActionErrors(['evidence_id']);
        $this->assertSame('Already reviewed by Accounting.', $selected->fresh()->review_note);
        $this->assertSame(0, Payment::query()->where('term_account_id', $fixture['account']->id)->count());
    }

    public function test_online_source_review_does_not_offer_recovery_without_provider_evidence(): void
    {
        $fixture = $this->accountFixture();
        $attempt = $this->onlineAttempt($fixture, ['provider' => 'mock', 'provider_checkout_id' => null, 'status' => PaymentAttempt::StatusReviewRequired]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($fixture['accounting'])->test(ViewEnrollment::class, ['record' => $fixture['account']->enrollment_id])
            ->assertSee('Online payment needs source review')->assertSee('Accounting owns the next step')
            ->assertActionHidden('retrievePayMongoCheckout')->assertActionHidden('confirmRecoveredPayMongo')->assertActionHidden('rejectRecoveredPayMongo');
        $this->assertSame(PaymentAttempt::StatusReviewRequired, $attempt->fresh()->status);
        $this->assertSame(0, Payment::query()->where('payment_attempt_id', $attempt->id)->count());
    }

    public function test_selected_online_retrieval_refuses_foreign_or_changed_checkout_before_calling_provider_recovery(): void
    {
        $fixture = $this->accountFixture();
        $foreign = $this->accountFixture();
        $attempt = $this->onlineAttempt($fixture);
        $other = $this->onlineAttempt($foreign);
        $gateway = $this->mock(PaymentGateway::class);
        $gateway->shouldNotReceive('retrieveCheckoutSession');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($fixture['accounting'])->test(ViewEnrollment::class, ['record' => $fixture['account']->enrollment_id]);
        $attempt->update(['provider_checkout_id' => 'cs_changed_after_dialog']);
        $page->callAction('retrievePayMongoCheckout');
        $this->assertSame(PaymentAttempt::StatusPending, $attempt->fresh()->status);
        $this->assertSame(PaymentAttempt::StatusPending, $other->fresh()->status);
        $this->assertSame(0, Payment::query()->whereIn('payment_attempt_id', [$attempt->id, $other->id])->count());
    }

    public function test_selected_recovered_confirmation_shows_exact_effect_and_posts_once_through_canonical_service(): void
    {
        Mail::fake();
        config()->set('tala_integrations.payments.paymongo.livemode', false);
        $fixture = $this->accountFixture();
        $attempt = $this->onlineAttempt($fixture, ['status' => PaymentAttempt::StatusReviewRequired]);
        $event = $this->recoveredOnlineEvent($attempt);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($fixture['accounting'])->test(ViewEnrollment::class, ['record' => $fixture['account']->enrollment_id])
            ->assertSee('Review recovered payment')->mountAction('confirmRecoveredPayMongo')
            ->assertMountedActionModalSee('PHP 12,000.00 applied')->assertMountedActionModalSee('PHP 0.00 remaining')
            ->assertDontSee('Accounting decision basis');
        $page->callMountedAction()->assertHasNoActionErrors()->assertNotified('Recovered PayMongo payment posted');
        $payment = Payment::query()->where('payment_attempt_id', $attempt->id)->sole();
        $this->assertSame('12000.00', $payment->amount);
        $this->assertSame('12000.00', $payment->allocations->sole()->amount);
        $this->assertSame(PaymentAttempt::StatusConfirmed, $attempt->fresh()->status);
        $retry = app(PayMongoCheckoutRecoveryService::class)->confirm($event->id, 'Repeat the same confirmed source.', $fixture['accounting']);
        $this->assertSame($payment->id, $retry['payment_id']);
        $this->assertSame('duplicate', $retry['status']);
        $this->assertNull($payment->ledgerEntry);
        $this->assertSame(1, Payment::query()->where('payment_attempt_id', $attempt->id)->count());
        $this->assertSame(1, $payment->allocations()->count());
        Mail::assertQueued(PaymentPostedMail::class, 1);
    }

    public function test_selected_recovered_decision_refuses_foreign_event_and_changed_result_without_money_or_attempt_changes(): void
    {
        $fixture = $this->accountFixture();
        $foreign = $this->accountFixture();
        $attempt = $this->onlineAttempt($fixture, ['status' => PaymentAttempt::StatusReviewRequired]);
        $other = $this->onlineAttempt($foreign, ['status' => PaymentAttempt::StatusReviewRequired]);
        $event = $this->recoveredOnlineEvent($attempt);
        $foreignEvent = $this->recoveredOnlineEvent($other);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($fixture['accounting'])->test(ViewEnrollment::class, ['record' => $fixture['account']->enrollment_id])
            ->mountAction('rejectRecoveredPayMongo')->setActionData(['event_id' => $foreignEvent->id, 'reason' => 'Foreign selected event must be refused.'])
            ->callMountedAction()->assertHasActionErrors(['event_id']);
        $payload = $event->payload;
        data_set($payload, 'payment.amount_centavos', 1);
        $event->update(['payload' => $payload]);
        $page->setActionData(['event_id' => $event->id, 'reason' => 'Reviewed external result cannot be used after its source changes.'])->callMountedAction()->assertNotified('Recovered evidence was not rejected');
        $this->assertSame(PaymentAttempt::StatusReviewRequired, $attempt->fresh()->status);
        $this->assertSame(PaymentAttempt::StatusReviewRequired, $other->fresh()->status);
        $this->assertSame(OperationalEvent::StatusReviewRequired, $event->fresh()->status);
        $this->assertSame(0, Payment::query()->whereIn('payment_attempt_id', [$attempt->id, $other->id])->count());
    }

    public function test_recovered_mismatch_or_stale_account_cannot_confirm_and_rejection_preserves_reason_without_payment(): void
    {
        $fixture = $this->accountFixture();
        $attempt = $this->onlineAttempt($fixture, ['status' => PaymentAttempt::StatusReviewRequired]);
        $event = $this->recoveredOnlineEvent($attempt);
        $payload = $event->payload;
        data_set($payload, 'payment.amount_centavos', 1);
        $event->update(['payload' => $payload]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($fixture['accounting'])->test(ViewEnrollment::class, ['record' => $fixture['account']->enrollment_id])
            ->assertActionDisabled('confirmRecoveredPayMongo')->assertSee('The provider result does not exactly match this checkout.');
        $fixture['assessment']->update(['state' => Assessment::StateSuperseded]);
        $page->refresh()->assertActionDisabled('confirmRecoveredPayMongo')->assertSee('The current assessment or unpaid targets changed.')
            ->mountAction('rejectRecoveredPayMongo')->setActionData(['reason' => 'The provider amount differs from the captured payment.'])
            ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Recovered evidence rejected');
        $this->assertSame(PaymentAttempt::StatusFailed, $attempt->fresh()->status);
        $this->assertSame(OperationalEvent::StatusProcessed, $event->fresh()->status);
        $this->assertSame(0, Payment::query()->where('payment_attempt_id', $attempt->id)->count());
        $page->set('enrollmentView', 'history')->assertSee('The provider amount differs from the captured payment.');
    }

    public function test_student_recovered_retry_returns_the_same_posting_and_keeps_legacy_ledger_and_email_once(): void
    {
        Mail::fake();
        config()->set('tala_integrations.payments.paymongo.livemode', false);
        $fixture = $this->accountFixture();
        $profile = StudentProfile::factory()->for($fixture['learner'])->create();
        $fixture['account']->enrollment->update(['student_profile_id' => $profile->id]);
        $attempt = $this->onlineAttempt($fixture, ['status' => PaymentAttempt::StatusReviewRequired]);
        $event = $this->recoveredOnlineEvent($attempt);
        $service = app(PayMongoCheckoutRecoveryService::class);
        $first = $service->confirm($event->id, 'Matched Student checkout and payment targets.', $fixture['accounting']);
        $second = $service->confirm($event->id, 'Retried the exact same Student confirmation.', $fixture['accounting']);
        $this->assertSame($first['payment_id'], $second['payment_id']);
        $this->assertSame('duplicate', $second['status']);
        $payment = Payment::query()->where('payment_attempt_id', $attempt->id)->sole();
        $this->assertNotNull($payment->ledgerEntry);
        $this->assertSame(1, $payment->allocations()->count());
        $this->assertSame('12000.00', $payment->amount);
        Mail::assertQueued(PaymentPostedMail::class, 1);
    }

    public function test_recovered_retry_refuses_changed_reference_targets_or_foreign_payment_without_new_effects(): void
    {
        Mail::fake();
        config()->set('tala_integrations.payments.paymongo.livemode', false);
        $fixture = $this->accountFixture();
        $foreign = $this->accountFixture();
        $attempt = $this->onlineAttempt($fixture, ['status' => PaymentAttempt::StatusReviewRequired]);
        $other = $this->onlineAttempt($foreign, ['status' => PaymentAttempt::StatusReviewRequired]);
        $event = $this->recoveredOnlineEvent($attempt);
        $foreignEvent = $this->recoveredOnlineEvent($other);
        $service = app(PayMongoCheckoutRecoveryService::class);
        $posted = $service->confirm($event->id, 'Confirmed the exact original source.', $fixture['accounting']);
        $otherPosted = $service->confirm($foreignEvent->id, 'Confirmed the separate owning account.', $foreign['accounting']);
        $originalReference = $attempt->internal_reference;
        $attempt->update(['internal_reference' => 'CHANGED-REFERENCE']);
        try {
            $service->confirm($event->id, 'Retry changed checkout reference.', $fixture['accounting']);
            $this->fail('Changed checkout reference must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('does not exactly match', $exception->getMessage());
        }
        $attempt->update(['internal_reference' => $originalReference]);
        $target = $attempt->obligations->sole();
        $target->update(['amount' => '1.00']);
        try {
            $service->confirm($event->id, 'Retry changed captured allocation.', $fixture['accounting']);
            $this->fail('Changed captured target must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('captured payment targets', $exception->getMessage());
        }
        $target->update(['amount' => $attempt->amount]);
        $event->refresh()->update(['related_record_id' => $otherPosted['payment_id']]);
        try {
            $service->confirm($event->id, 'Retry foreign linked posting.', $fixture['accounting']);
            $this->fail('Foreign linked payment must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('original payment', $exception->getMessage());
        }
        $this->assertSame(2, Payment::query()->whereIn('payment_attempt_id', [$attempt->id, $other->id])->count());
        $this->assertSame('12000.00', Payment::query()->findOrFail($posted['payment_id'])->amount);
        $this->assertSame('12000.00', Payment::query()->findOrFail($otherPosted['payment_id'])->amount);
        $this->assertSame(2, PaymentAllocation::query()->whereIn('payment_id', [$posted['payment_id'], $otherPosted['payment_id']])->count());
        Mail::assertQueued(PaymentPostedMail::class, 2);
    }

    /** @param array<string, mixed> $fixture @param array<string, mixed> $attributes */
    private function onlineAttempt(array $fixture, array $attributes = []): PaymentAttempt
    {
        $snapshot = app(ExactDuePaymentSnapshotService::class)->forAccount($fixture['account']);
        $attempt = PaymentAttempt::query()->create(['term_account_id' => $fixture['account']->id, 'assessment_id' => $fixture['assessment']->id,
            'student_profile_id' => $fixture['account']->enrollment->student_profile_id, 'assessment_version' => $fixture['assessment']->version, 'snapshot_created_at' => $snapshot['created_at'],
            'snapshot_checksum' => $snapshot['checksum'], 'channel' => 'paymongo', 'provider' => 'paymongo',
            'internal_reference' => 'SYN-ONLINE-TEST-'.$fixture['account']->id, 'provider_checkout_id' => 'cs_test_'.$fixture['account']->id,
            'amount' => $snapshot['amount'], 'currency' => 'PHP', 'status' => PaymentAttempt::StatusPending, ...$attributes]);
        foreach ($snapshot['obligations'] as $target) {
            $attempt->obligations()->create(['assessment_obligation_id' => $target['id'], 'sequence' => $target['sequence'], 'amount' => $target['amount']]);
        }

        return $attempt;
    }

    private function recoveredOnlineEvent(PaymentAttempt $attempt): OperationalEvent
    {
        return OperationalEvent::factory()->create(['event_domain' => OperationalEvent::DomainIntegration, 'integration' => OperationalEvent::IntegrationPayMongo,
            'channel' => OperationalEvent::ChannelProviderApi, 'event_type' => 'checkout_session.payment.recovered', 'status' => OperationalEvent::StatusReviewRequired,
            'related_record_type' => PaymentAttempt::class, 'related_record_id' => $attempt->id,
            'diagnostics' => ['reason' => 'recovered_paid_without_webhook'],
            'payload' => ['checkout_session_id' => $attempt->provider_checkout_id, 'reference_number' => $attempt->internal_reference, 'payment_count' => 1,
                'payment' => ['id' => 'pay_test_'.$attempt->id, 'status' => 'paid', 'amount_centavos' => (int) round((float) $attempt->amount * 100),
                    'currency' => 'PHP', 'livemode' => false, 'payment_intent_id' => 'pi_test_'.$attempt->id, 'disputed' => false, 'has_refunds' => false, 'paid_at' => now()->timestamp]]]);
    }

    public function test_registrar_cannot_mount_contextual_provider_actions_even_with_legacy_payment_permission(): void
    {
        $fixture = $this->accountFixture();
        $attempt = $this->onlineAttempt($fixture, ['status' => PaymentAttempt::StatusReviewRequired]);
        $event = $this->recoveredOnlineEvent($attempt);
        Role::query()->firstOrCreate(['name' => User::StaffRoleRegistrar, 'guard_name' => 'web']);
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $permission = Permission::query()->firstOrCreate(['name' => 'process-payments', 'guard_name' => 'web']);
        $registrar->givePermissionTo($permission);
        $this->assertTrue($registrar->canProcessPayments());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($registrar)->test(ViewEnrollment::class, ['record' => $fixture['account']->enrollment_id]);
        foreach (['retrievePayMongoCheckout', 'confirmRecoveredPayMongo', 'rejectRecoveredPayMongo', 'retryPayMongoEvent', 'linkUnknownPayMongoEvent', 'confirmPayMongoException', 'rejectPayMongoException'] as $action) {
            $this->assertFalse($page->instance()->getAction($action, isMounting: false)->isAuthorized());
            $page->mountAction($action)->assertActionNotMounted($action);
        }
        $this->assertSame(PaymentAttempt::StatusReviewRequired, $attempt->fresh()->status);
        $this->assertSame(OperationalEvent::StatusReviewRequired, $event->fresh()->status);
        $this->assertSame(0, Payment::query()->where('payment_attempt_id', $attempt->id)->count());
    }

    public function test_webhook_claim_is_not_verified_money_or_an_available_acknowledgement(): void
    {
        $fixture = $this->accountFixture();
        $attempt = $this->onlineAttempt($fixture, ['status' => PaymentAttempt::StatusReviewRequired]);
        $event = $this->webhookReviewEvent($attempt);
        $claim = Payment::findOrFail($event->related_record_id);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($fixture['accounting'])->test(ViewEnrollment::class, ['record' => $fixture['account']->enrollment_id])
            ->assertSee('Review online payment')->assertSee('Unverified claim')->assertActionVisible('confirmPayMongoException')
            ->set('enrollmentView', 'account')->assertDontSee('Open acknowledgment')->assertActionHidden('reversePaymentPosting')
            ->set('enrollmentView', 'history')->assertSee('Unverified claim')->assertDontSee('Open acknowledgment');
        $this->actingAs($fixture['accounting'])->get(route('finance.payments.acknowledgement', $claim))->assertForbidden();
        $this->assertSame('under_review', $claim->fresh()->evidence_status);
        $this->assertSame(0, $claim->allocations()->count());
        $this->assertSame('12000.00', app(TermAccountProjection::class)->forAccount($fixture['account'])['current_due']);
    }

    public function test_webhook_bound_decision_refuses_foreign_and_changed_source_without_changing_claims(): void
    {
        $fixture = $this->accountFixture();
        $foreign = $this->accountFixture();
        $attempt = $this->onlineAttempt($fixture, ['status' => PaymentAttempt::StatusReviewRequired]);
        $event = $this->webhookReviewEvent($attempt);
        $other = $this->webhookReviewEvent($this->onlineAttempt($foreign, ['status' => PaymentAttempt::StatusReviewRequired]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($fixture['accounting'])->test(ViewEnrollment::class, ['record' => $fixture['account']->enrollment_id]);
        $page->mountAction('confirmPayMongoException')->setActionData(['event_id' => $other->id, 'reason' => 'Checked the wrong account source.'])
            ->callMountedAction()->assertHasActionErrors(['event_id']);
        $page->unmountAction()->mountAction('confirmPayMongoException');
        $event->update(['diagnostics' => [...$event->diagnostics, 'review_note' => 'Source changed after opening.']]);
        $page->setActionData(['reason' => 'Checked the original account source.'])->callMountedAction()->assertHasActionErrors(['reviewed_source']);
        $this->assertSame('under_review', Payment::findOrFail($event->related_record_id)->evidence_status);
        $this->assertSame('under_review', Payment::findOrFail($other->related_record_id)->evidence_status);
        $this->assertSame(PaymentAttempt::StatusReviewRequired, $attempt->fresh()->status);
        $this->assertSame(0, PaymentAllocation::whereIn('payment_id', [$event->related_record_id, $other->related_record_id])->count());
    }

    public function test_webhook_amount_mismatch_can_only_be_rejected_with_retained_claim_history(): void
    {
        $fixture = $this->accountFixture();
        $attempt = $this->onlineAttempt($fixture, ['status' => PaymentAttempt::StatusReviewRequired]);
        $event = $this->webhookReviewEvent($attempt, 'amount_mismatch', 1199900);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($fixture['accounting'])->test(ViewEnrollment::class, ['record' => $fixture['account']->enrollment_id])
            ->assertActionHidden('confirmPayMongoException')->assertSee('11,999.00')->assertSee('12,000.00')
            ->mountAction('rejectPayMongoException')->setActionData(['reason' => 'Provider amount conflicts with the captured checkout.'])
            ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Payment result rejected');
        $this->assertSame('rejected', Payment::findOrFail($event->related_record_id)->evidence_status);
        $this->assertSame(PaymentAttempt::StatusFailed, $attempt->fresh()->status);
        $this->assertSame(OperationalEvent::StatusProcessed, $event->fresh()->status);
        $this->assertSame(0, PaymentAllocation::where('payment_id', $event->related_record_id)->count());
    }

    public function test_inline_webhook_retry_queues_the_original_failed_delivery_without_a_reason_field(): void
    {
        Queue::fake();
        $fixture = $this->accountFixture();
        $attempt = $this->onlineAttempt($fixture, ['status' => PaymentAttempt::StatusReviewRequired]);
        $event = $this->webhookReviewEvent($attempt, 'processing_failed');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($fixture['accounting'])->test(ViewEnrollment::class, ['record' => $fixture['account']->enrollment_id]);
        $page->assertDontSee('retry reason')->callAction('retryPayMongoEvent')->assertHasNoErrors();
        $this->assertSame(OperationalEvent::StatusPending, $event->fresh()->status);
        Queue::assertPushed(ProcessPayMongoWebhookCall::class, 1);
        $this->assertSame(0, Payment::where('payment_attempt_id', $attempt->id)->count());
        $this->assertSame(PaymentAttempt::StatusReviewRequired, $attempt->fresh()->status);
    }

    public function test_unknown_webhook_links_only_the_selected_account_checkout_and_queues_without_posting(): void
    {
        Queue::fake();
        $fixture = $this->accountFixture();
        $foreign = $this->accountFixture();
        $attempt = $this->onlineAttempt($fixture, ['provider_checkout_id' => null]);
        $other = $this->onlineAttempt($foreign);
        $event = $this->webhookReviewEvent($attempt, 'unknown_reference');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($fixture['accounting'])->test(ViewEnrollment::class, ['record' => $fixture['account']->enrollment_id]);
        $page->set('enrollmentView', 'webhook')->set('webhookEventId', $event->id)->set('onlineAttemptId', $other->id)->assertActionHidden('linkUnknownPayMongoEvent');
        $page->set('onlineAttemptId', $attempt->id)->mountAction('linkUnknownPayMongoEvent')
            ->setActionData(['reason' => 'Matched this checkout to the original account source.'])->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame(OperationalEvent::StatusPending, $event->fresh()->status);
        $this->assertSame($attempt->id, $event->fresh()->related_record_id);
        Queue::assertPushed(ProcessPayMongoWebhookCall::class, 1);
        $this->assertSame(0, Payment::whereIn('payment_attempt_id', [$attempt->id, $other->id])->count());
    }

    public function test_webhook_choices_keep_compatible_coexisting_sources_and_exclude_foreign_or_conflicting_unlinked_sources(): void
    {
        $fixture = $this->accountFixture();
        $foreign = $this->accountFixture();
        $attempt = $this->onlineAttempt($fixture, ['status' => PaymentAttempt::StatusReviewRequired]);
        $linked = $this->webhookReviewEvent($attempt);
        $compatible = $this->webhookReviewEvent($attempt, 'unknown_reference');
        $foreignEvent = $this->webhookReviewEvent($this->onlineAttempt($foreign, ['provider_checkout_id' => null]), 'unknown_reference');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($fixture['accounting'])->test(ViewEnrollment::class, ['record' => $fixture['account']->enrollment_id])
            ->set('enrollmentView', 'webhook')->set('webhookEventId', $linked->id);
        $choices = $page->instance()->webhookPaymentViewData()['webhookChoices']->pluck('id');
        $this->assertCount(2, $choices);
        $this->assertTrue($choices->contains($linked->id));
        $this->assertTrue($choices->contains($compatible->id));
        $this->assertFalse($choices->contains($foreignEvent->id));
        $page->set('webhookEventId', $compatible->id)->assertActionVisible('linkUnknownPayMongoEvent');
        $callId = data_get($compatible->diagnostics, 'webhook_call_id');
        $raw = json_decode(DB::table('webhook_calls')->where('id', $callId)->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        data_set($raw, 'data.attributes.data.id', 'cs_conflicts_with_account_checkout');
        DB::table('webhook_calls')->where('id', $callId)->update(['payload' => json_encode($raw, JSON_THROW_ON_ERROR)]);
        $page->refresh()->assertActionHidden('linkUnknownPayMongoEvent')->mountAction('linkUnknownPayMongoEvent')->assertActionNotMounted('linkUnknownPayMongoEvent');
        $this->assertSame([$linked->id], $page->instance()->webhookPaymentViewData()['webhookChoices']->pluck('id')->all());
        $this->assertSame(OperationalEvent::StatusReviewRequired, $compatible->fresh()->status);
        $this->assertSame(0, PaymentAllocation::where('payment_id', $linked->related_record_id)->count());
    }

    public function test_registrar_fallback_keeps_enrollment_consequence_without_private_payment_details(): void
    {
        $fixture = $this->accountFixture();
        $fixture['account']->enrollment->update(['canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled]);
        $this->evidence($fixture, ['term_account_id' => $fixture['account']->id, 'original_name' => 'PRIVATE-RECEIPT.pdf', 'payment_reference' => 'PRIVATE-BANK-REFERENCE', 'state' => PaymentEvidenceVersion::StateSubmitted]);
        Role::firstOrCreate(['name' => User::StaffRoleRegistrar, 'guard_name' => 'web']);
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)->test(ViewEnrollment::class, ['record' => $fixture['account']->enrollment_id])
            ->assertSee('Registration certificate source unavailable')->assertSee('Registrar and the system administrator must investigate')
            ->assertDontSee('PRIVATE-BANK-REFERENCE')->assertDontSee('Inspect private evidence')->assertDontSee('Payment evidence and review targets');
    }

    public function test_confirmed_webhook_retry_reuses_pre_student_canonical_posting_without_a_ledger(): void
    {
        Mail::fake();
        $fixture = $this->accountFixture();
        $attempt = $this->onlineAttempt($fixture, ['status' => PaymentAttempt::StatusReviewRequired]);
        $event = $this->webhookReviewEvent($attempt);
        $service = app(PayMongoReconciliationService::class);
        $first = $service->confirm($event->id, 'Matched the exact checkout and reference discrepancy.', $fixture['accounting']);
        $second = $service->confirm($event->id, 'Retried the same original payment decision.', $fixture['accounting']);
        $this->assertSame($first['payment_id'], $second['payment_id']);
        $this->assertSame('duplicate', $second['status']);
        $payment = Payment::findOrFail($first['payment_id']);
        $this->assertNull($payment->ledgerEntry);
        $this->assertSame(1, Payment::where('payment_attempt_id', $attempt->id)->count());
        $this->assertSame(1, $payment->allocations()->count());
        Mail::assertQueued(PaymentPostedMail::class, 1);
    }

    public function test_webhook_student_retry_and_changed_posted_source_keep_exact_original_effects(): void
    {
        Mail::fake();
        $fixture = $this->accountFixture();
        $profile = StudentProfile::factory()->for($fixture['learner'])->create();
        $fixture['account']->enrollment->update(['student_profile_id' => $profile->id]);
        $attempt = $this->onlineAttempt($fixture, ['status' => PaymentAttempt::StatusReviewRequired]);
        $event = $this->webhookReviewEvent($attempt);
        $service = app(PayMongoReconciliationService::class);
        $posted = $service->confirm($event->id, 'Confirmed the exact Student payment source.', $fixture['accounting']);
        $retry = $service->confirm($event->id, 'Retried the exact Student payment source.', $fixture['accounting']);
        $this->assertSame($posted['payment_id'], $retry['payment_id']);
        $payment = Payment::findOrFail($posted['payment_id']);
        $this->assertNotNull($payment->ledgerEntry);
        $reference = $attempt->internal_reference;
        $attempt->update(['internal_reference' => 'ALTERED-AFTER-POSTING']);
        try {
            $service->confirm($event->id, 'Retry after the captured reference changed.', $fixture['accounting']);
            $this->fail('Changed original reference must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('original payment', $exception->getMessage());
        }
        $attempt->update(['internal_reference' => $reference]);
        $target = $attempt->obligations->sole();
        $target->update(['amount' => '1.00']);
        try {
            $service->confirm($event->id, 'Retry after the captured target changed.', $fixture['accounting']);
            $this->fail('Changed original allocation must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('captured payment targets', $exception->getMessage());
        }
        $target->update(['amount' => $attempt->amount]);
        $callId = data_get($event->diagnostics, 'webhook_call_id');
        $raw = DB::table('webhook_calls')->where('id', $callId)->value('payload');
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        data_set($payload, 'data.attributes.data.id', 'cs_changed_after_posting');
        DB::table('webhook_calls')->where('id', $callId)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        try {
            $service->confirm($event->id, 'Retry changed original provider source.', $fixture['accounting']);
            $this->fail('Changed original checkout must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('checkout ownership conflicts', $exception->getMessage());
        }
        DB::table('webhook_calls')->where('id', $callId)->update(['payload' => $raw]);
        $foreign = $this->accountFixture();
        $foreignClaim = Payment::factory()->create(['term_account_id' => $foreign['account']->id, 'payment_attempt_id' => $this->onlineAttempt($foreign)->id]);
        $event->refresh()->update(['related_record_id' => $foreignClaim->id]);
        try {
            $service->confirm($event->id, 'Retry foreign event payment association.', $fixture['accounting']);
            $this->fail('Foreign payment association must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ownership conflicts', $exception->getMessage());
        }
        $this->assertSame('12000.00', $payment->fresh()->amount);
        $this->assertSame(1, Payment::where('payment_attempt_id', $attempt->id)->count());
        $this->assertSame(1, $payment->allocations()->count());
        $this->assertSame(1, $payment->ledgerEntry()->count());
        Mail::assertQueued(PaymentPostedMail::class, 1);
    }

    private function webhookReviewEvent(PaymentAttempt $attempt, string $reason = 'missing_tala_reference', int $amount = 1200000): OperationalEvent
    {
        config()->set('tala_integrations.payments.paymongo.livemode', false);
        $payload = ['data' => ['id' => 'evt_webhook_test_'.$attempt->id.'_'.$reason, 'type' => 'event', 'attributes' => ['type' => 'checkout_session.payment.paid', 'livemode' => false,
            'data' => ['id' => $attempt->provider_checkout_id ?? 'cs_unlinked_test_'.$attempt->id, 'type' => 'checkout_session',
                'attributes' => ['status' => 'paid', 'amount_paid' => $amount, 'currency' => 'PHP', 'metadata' => ['term_account_id' => (string) $attempt->term_account_id, 'assessment_version' => (string) $attempt->assessment_version, 'snapshot_checksum' => $attempt->snapshot_checksum]]]]]];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $callId = DB::table('webhook_calls')->insertGetId(['name' => 'paymongo', 'url' => 'https://tala.test/api/webhooks/paymongo', 'headers' => '{}', 'payload' => $raw, 'created_at' => now(), 'updated_at' => now()]);
        $claim = in_array($reason, ['missing_tala_reference', 'amount_mismatch'], true) ? Payment::factory()->create(['term_account_id' => $attempt->term_account_id, 'payment_attempt_id' => $attempt->id, 'student_profile_id' => null,
            'amount' => number_format($amount / 100, 2, '.', ''), 'provider_reference' => 'paymongo:'.data_get($payload, 'data.attributes.data.id'), 'state' => Payment::StatePosted, 'evidence_status' => 'under_review', 'verified_at' => null, 'verified_by' => null]) : null;

        return OperationalEvent::factory()->create(['event_domain' => OperationalEvent::DomainIntegration, 'integration' => OperationalEvent::IntegrationPayMongo, 'channel' => OperationalEvent::ChannelWebhook,
            'external_id' => data_get($payload, 'data.id'), 'event_type' => 'checkout_session.payment.paid', 'status' => $reason === 'processing_failed' ? OperationalEvent::StatusFailed : OperationalEvent::StatusReviewRequired,
            'related_record_type' => $reason === 'unknown_reference' ? null : ($claim ? Payment::class : PaymentAttempt::class), 'related_record_id' => $reason === 'unknown_reference' ? null : ($claim?->id ?? $attempt->id),
            'diagnostics' => ['reason' => $reason, 'payload_sha256' => hash('sha256', $raw), 'webhook_call_id' => $callId], 'payload' => ['livemode' => false]]);
    }

    public function test_coverage_reversal_form_binds_one_source_or_offers_a_choice_and_refuses_stale_or_foreign_sources(): void
    {
        $fixture = $this->accountFixture();
        $foreign = $this->accountFixture();
        $sole = $this->appliedCoverage($fixture, 0, '2000.00');
        $foreignCoverage = $this->appliedCoverage($foreign, 0, '500.00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($fixture['accounting'])->test(ViewEnrollment::class, ['record' => $fixture['account']->enrollment_id]);

        $page->mountAction('reverseApprovedCoverage')->assertMountedActionModalSee(['PHP 2,000.00', 'Enrollment obligation', 'Reverse coverage'])
            ->assertMountedActionModalDontSee('Coverage to reverse');
        $later = $this->appliedCoverage($fixture, 1, '1000.00');
        $sole->update(['state' => ApprovedCoverage::StateReversed]);
        $page->setActionData(['authority_reference' => 'SYN-REVERSE-AUTH', 'safe_reason' => 'Source withdrew the result.'])
            ->callMountedAction()->assertNotified('Coverage was not reversed');
        $this->assertSame(ApprovedCoverage::StateApplied, $later->fresh()->state);

        $second = $this->appliedCoverage($fixture, 0, '300.00');
        $page->call('unmountAction')->mountAction('reverseApprovedCoverage')
            ->setActionData(['coverage_id' => $foreignCoverage->id, 'authority_reference' => 'SYN-REVERSE-AUTH', 'safe_reason' => 'Wrong account.'])
            ->callMountedAction()->assertHasActionErrors(['coverage_id']);
        $page->setActionData(['coverage_id' => $second->id, 'authority_reference' => 'SYN-REVERSE-AUTH', 'safe_reason' => 'Source withdrew the result.'])
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame(ApprovedCoverage::StateReversed, $second->fresh()->state);
        $this->assertSame(ApprovedCoverage::StateApplied, $later->fresh()->state);
        $this->assertSame(ApprovedCoverage::StateApplied, $foreignCoverage->fresh()->state);
    }

    public function test_registrar_with_a_legacy_permission_cannot_open_the_three_accounting_correction_forms(): void
    {
        $fixture = $this->accountFixture();
        $this->appliedCoverage($fixture, 0, '2000.00');
        Role::query()->firstOrCreate(['name' => User::StaffRoleRegistrar, 'guard_name' => 'web']);
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $registrar->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'process-payments', 'guard_name' => 'web']));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($registrar)->test(ViewEnrollment::class, ['record' => $fixture['account']->enrollment_id]);
        foreach (['reverseApprovedCoverage', 'reversePaymentPosting', 'reconcileAssessmentEffects'] as $action) {
            $this->assertFalse($page->instance()->getAction($action, isMounting: false)->isAuthorized());
            $page->mountAction($action)->assertActionNotMounted($action);
        }
        $this->assertSame(1, ApprovedCoverage::query()->where('state', ApprovedCoverage::StateApplied)->where('term_account_id', $fixture['account']->id)->count());
    }

    public function test_refund_form_binds_the_exact_payment_and_provider_evidence_and_refuses_a_changed_payment(): void
    {
        $fixture = $this->accountFixture();
        $payment = $this->verifiedPayment($fixture, '3000.00', 'SYN-REFUND-FORM-1');
        $other = $this->verifiedPayment($fixture, '1000.00', 'SYN-REFUND-FORM-2', version: 2);
        $ownEvent = $this->refundEvent($payment);
        $otherEvent = $this->refundEvent($other);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($fixture['accounting'])->test(ViewEnrollment::class, ['record' => $fixture['account']->enrollment_id])
            ->mountAction('reversePaymentPosting')->assertMountedActionModalSee(['Record a completed refund', 'Keep payment unchanged']);
        $data = ['authority_reference' => 'SYN-REFUND-AUTH', 'safe_reason' => 'Completed external refund.', 'external_refund_reference' => 'SYN-EXT-REFUND-1',
            'refund_amount' => '1000.00', 'refunded_at' => now()->subMinute()->toDateTimeString()];
        $page->setActionData($data + ['payment_id' => $payment->id, 'paymongo_event_id' => $otherEvent->id])->callMountedAction()->assertHasActionErrors(['paymongo_event_id']);
        $this->assertSame(0, Payment::query()->where('state', Payment::StateReversal)->where('term_account_id', $fixture['account']->id)->count());
        $this->assertSame(OperationalEvent::StatusReviewRequired, $ownEvent->fresh()->status);

        app(ReversePaymentPosting::class)->execute($payment, $fixture['accounting'], 'SYN-CHANGED-AUTH', 'Refunded elsewhere first.', 'SYN-CHANGED-REFUND', '2000.00', now()->subMinute());
        $page->setActionData($data + ['payment_id' => $payment->id])->callMountedAction()->assertNotified('Payment was not reversed');
        $this->assertSame(1, $payment->reversals()->count());
        $this->assertSame(0, $other->reversals()->count());
    }

    public function test_reconciliation_form_binds_the_reviewed_assessment_and_refuses_stale_or_malformed_mappings(): void
    {
        $fixture = $this->reconciliationFixture();
        $source = $fixture['obligations'][0];
        $foreign = $this->accountFixture();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($fixture['accounting'])->test(ViewEnrollment::class, ['record' => $fixture['account']->enrollment_id])
            ->mountAction('reconcileAssessmentEffects')->assertMountedActionModalSee(['Apply earlier payments and coverage', 'Keep for review', 'Enrollment obligation']);
        $valid = fn (array $rows): array => ['mapping' => $rows];
        $row = fn (int $sourceId, int $targetId): array => ['source_obligation_id' => $sourceId, 'target_obligation_id' => $targetId];
        $open = fn () => $page->call('unmountAction')->mountAction('reconcileAssessmentEffects');

        $open()->setActionData($valid([]))->callMountedAction()->assertNotified('Retained effects were not reconciled');
        $open()->setActionData($valid([$row($source->id, $fixture['target']->id), $row($source->id, $fixture['target']->id)]))->callMountedAction()->assertNotified('Retained effects were not reconciled');
        $open()->setActionData($valid([$row($foreign['obligations'][0]->id, $fixture['target']->id)]))->callMountedAction()->assertNotified('Retained effects were not reconciled');
        $open()->setActionData($valid([$row($source->id, $foreign['obligations'][0]->id)]))->callMountedAction()->assertHasActionErrors();
        $this->assertSame(0, AssessmentEffectRevalidation::query()->where('term_account_id', $fixture['account']->id)->count());

        $open();
        $fixture['successor']->update(['state' => Assessment::StateSuperseded]);
        $revised = Assessment::factory()->create(['enrollment_id' => $fixture['account']->enrollment_id, 'term_account_id' => $fixture['account']->id, 'version' => 3, 'total' => '48000.00', 'subtotal' => '48000.00']);
        $newTarget = AssessmentObligation::factory()->create(['assessment_id' => $revised->id, 'code' => 'REVISED', 'amount' => '48000.00', 'due_at' => now()->subMinute(), 'required_for_enrollment' => true]);
        $page->setActionData($valid([$row($source->id, $fixture['target']->id)]))->callMountedAction()->assertNotified('Retained effects were not reconciled');
        $this->assertSame(0, AssessmentEffectRevalidation::query()->where('term_account_id', $fixture['account']->id)->count());

        $page->call('unmountAction')->mountAction('reconcileAssessmentEffects')
            ->setActionData($valid([$row($source->id, $newTarget->id)]))->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame($revised->id, AssessmentEffectRevalidation::query()->where('term_account_id', $fixture['account']->id)->sole()->assessment_id);
    }

    public function test_reconciliation_refuses_changed_target_or_assessment_facts_without_moving_retained_effects(): void
    {
        $fixture = $this->reconciliationFixture();
        $source = $fixture['obligations'][0];
        $payment = $fixture['account']->payments()->sole();
        $allocationBefore = $payment->allocations()->sole()->getAttributes();
        $data = ['mapping' => [['source_obligation_id' => $source->id, 'target_obligation_id' => $fixture['target']->id]]];
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($fixture['accounting'])->test(ViewEnrollment::class, ['record' => $fixture['account']->enrollment_id])->mountAction('reconcileAssessmentEffects');
        $fixture['target']->update(['amount' => '49000.00', 'label' => 'Changed target after review']);

        $page->setActionData($data)->callMountedAction()->assertNotified('Retained effects were not reconciled');
        $this->assertSame(0, AssessmentEffectRevalidation::query()->where('term_account_id', $fixture['account']->id)->count());
        $this->assertSame($allocationBefore, $payment->allocations()->sole()->getAttributes());

        $page->call('unmountAction')->mountAction('reconcileAssessmentEffects');
        $fixture['successor']->update(['content_hash' => hash('sha256', 'changed-assessment-source')]);
        $page->setActionData($data)->callMountedAction()->assertNotified('Retained effects were not reconciled');
        $this->assertSame(0, AssessmentEffectRevalidation::query()->where('term_account_id', $fixture['account']->id)->count());
        $this->assertSame($allocationBefore, $payment->allocations()->sole()->getAttributes());
    }

    /** @param array<string, mixed> $fixture */
    public function test_payment_acknowledgement_lists_every_partial_reversal_with_a_source_backed_remainder(): void
    {
        $fixture = $this->accountFixture();
        $payment = $this->verifiedPayment($fixture, '3000.00', 'SYN-ACK-PARTIAL');
        $reverser = app(ReversePaymentPosting::class);
        $reverser->execute($payment, $fixture['accounting'], 'SYN-ACK-AUTH', 'First completed refund.', 'SYN-ACK-REFUND-1', '1000.00', now()->subMinutes(10));
        $reverser->execute($payment, $fixture['accounting'], 'SYN-ACK-AUTH', 'Second completed refund.', 'SYN-ACK-REFUND-2', '500.00', now()->subMinutes(5));

        $this->actingAs($fixture['learner'])->get(route('finance.payments.acknowledgement', $payment))->assertOk()
            ->assertSee('Partly refunded')->assertSee('First completed refund.')->assertSee('Second completed refund.')
            ->assertViewHas('acknowledgement', fn (array $acknowledgement): bool => count($acknowledgement['corrections']) === 2
                && $acknowledgement['reversed_total'] === '1500.00' && $acknowledgement['net_amount'] === '1500.00'
                && $acknowledgement['posting_status'] === 'Partly refunded');

        $reverser->execute($payment, $fixture['accounting'], 'SYN-ACK-AUTH', 'Final completed refund.', 'SYN-ACK-REFUND-3', '1500.00', now()->subMinute());
        $this->actingAs($fixture['learner'])->get(route('finance.payments.acknowledgement', $payment))->assertOk()
            ->assertViewHas('acknowledgement', fn (array $acknowledgement): bool => count($acknowledgement['corrections']) === 3
                && $acknowledgement['net_amount'] === '0.00' && $acknowledgement['posting_status'] === 'Reversed');
        $this->assertSame('3000.00', $payment->fresh()->amount);
    }

    public function test_payment_acknowledgement_reversal_uses_exact_cent_totals_without_float_precision_loss(): void
    {
        $fixture = $this->accountFixture();
        $payment = $this->verifiedPayment($fixture, '0.80', 'SYN-FLOAT-TEST');
        $reverser = app(ReversePaymentPosting::class);
        $reverser->execute($payment, $fixture['accounting'], 'AUTH-1', 'First cent refund', 'REFUND-1', '0.10', now()->subMinutes(5));
        $reverser->execute($payment, $fixture['accounting'], 'AUTH-2', 'Second cent refund', 'REFUND-2', '0.70', now()->subMinute());

        $this->actingAs($fixture['learner'])->get(route('finance.payments.acknowledgement', $payment))->assertOk()
            ->assertViewHas('acknowledgement', fn (array $acknowledgement): bool => count($acknowledgement['corrections']) === 2
                && $acknowledgement['reversed_total'] === '0.80'
                && $acknowledgement['net_amount'] === '0.00'
                && $acknowledgement['posting_status'] === 'Reversed');
    }

    public function test_statement_with_an_unavailable_source_never_reads_as_a_zero_or_cleared_balance(): void
    {
        $fixture = $this->reconciliationFixture();

        $this->actingAs($fixture['learner'])->get(route('finance.statement', $fixture['successor']))->assertOk()
            ->assertSee('Balance unavailable')->assertSee('not a zero or cleared balance')
            ->assertDontSee('Amount due now')->assertDontSee('Remaining term balance');
    }

    private function appliedCoverage(array $fixture, int $obligationIndex, string $amount): ApprovedCoverage
    {
        return app(RecordApprovedCoverage::class)->execute($fixture['account'], $fixture['obligations'][$obligationIndex], [
            'category' => ApprovedCoverage::CategoryGovernmentSubsidy,
            'safe_source_description' => 'Synthetic coverage '.$amount,
            'authority_reference' => 'SYN-COV-'.$amount,
            'authority_date' => '2026-08-20',
            'effective_date' => '2026-08-25',
            'amount' => $amount,
        ], $fixture['accounting']);
    }

    /** @param array<string, mixed> $fixture */
    private function verifiedPayment(array $fixture, string $amount, string $reference, int $version = 1): Payment
    {
        $evidence = $this->evidence($fixture, ['term_account_id' => $fixture['account']->id, 'version' => $version, 'claimed_amount' => $amount, 'channel' => 'bank_transfer',
            'paid_at' => now()->subHour(), 'payment_reference' => $reference, 'checksum' => hash('sha256', $reference), 'submitted_by' => $fixture['learner']->id]);

        return app(ReviewPaymentEvidence::class)->verify($evidence, $fixture['accounting'], $amount, 'SYN-CHECK-'.$reference);
    }

    private function refundEvent(Payment $payment): OperationalEvent
    {
        return OperationalEvent::factory()->create(['event_domain' => OperationalEvent::DomainIntegration, 'integration' => OperationalEvent::IntegrationPayMongo,
            'channel' => OperationalEvent::ChannelWebhook, 'event_type' => 'payment.refunded', 'status' => OperationalEvent::StatusReviewRequired,
            'related_record_type' => Payment::class, 'related_record_id' => $payment->id, 'diagnostics' => ['reason' => 'refund_or_reversal']]);
    }

    /** @return array<string, mixed> */
    private function reconciliationFixture(): array
    {
        $fixture = $this->accountFixture();
        $this->verifiedPayment($fixture, '3000.00', 'SYN-RECONCILE-PAYMENT');
        $fixture['assessment']->update(['state' => Assessment::StateSuperseded]);
        $fixture['successor'] = Assessment::factory()->create(['enrollment_id' => $fixture['account']->enrollment_id, 'term_account_id' => $fixture['account']->id, 'version' => 2, 'total' => '48000.00', 'subtotal' => '48000.00']);
        $fixture['target'] = AssessmentObligation::factory()->create(['assessment_id' => $fixture['successor']->id, 'code' => 'SUCCESSOR', 'amount' => '48000.00', 'due_at' => now()->subMinute(), 'required_for_enrollment' => true]);

        return $fixture;
    }

    private function accountFixture(): array
    {
        $accounting = $this->accounting();
        $learner = User::factory()->create(['status' => User::StatusActive]);
        Role::query()->firstOrCreate(['name' => 'applicant', 'guard_name' => 'web']);
        $learner->assignRole('applicant');
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => null,
            'credential_user_id' => $learner->id,
            'term_id' => $term->id,
        ]);
        $account = TermAccount::factory()->create([
            'enrollment_id' => $enrollment->id,
            'credential_user_id' => $learner->id,
            'term_id' => $term->id,
        ]);
        $assessment = Assessment::factory()->create([
            'enrollment_id' => $enrollment->id,
            'term_account_id' => $account->id,
            'source_proposal_version_id' => null,
            'total' => '48000.00',
            'subtotal' => '48000.00',
        ]);
        $obligations = [
            AssessmentObligation::factory()->create([
                'assessment_id' => $assessment->id,
                'sequence' => 1,
                'code' => 'ENROLLMENT',
                'label' => 'Enrollment obligation',
                'purpose' => 'Enrollment',
                'amount' => '12000.00',
                'due_at' => '2026-08-25 17:00:00',
                'required_for_enrollment' => true,
            ]),
            AssessmentObligation::factory()->create([
                'assessment_id' => $assessment->id,
                'sequence' => 2,
                'code' => 'MIDTERM',
                'label' => 'Midterm obligation',
                'purpose' => 'TermPayment',
                'amount' => '18000.00',
                'due_at' => '2026-10-15 17:00:00',
                'required_for_enrollment' => false,
            ]),
            AssessmentObligation::factory()->create([
                'assessment_id' => $assessment->id,
                'sequence' => 3,
                'code' => 'FINAL',
                'label' => 'Final obligation',
                'purpose' => 'TermPayment',
                'amount' => '18000.00',
                'due_at' => '2026-12-01 17:00:00',
                'required_for_enrollment' => false,
            ]),
        ];

        return compact('accounting', 'learner', 'account', 'assessment', 'obligations');
    }

    private function accounting(): User
    {
        $accounting = User::factory()->create(['status' => User::StatusActive]);
        $accounting->assignRole(User::StaffRoleAccounting);

        return $accounting;
    }

    /** @param array<string, mixed> $fixture @param array<string, mixed> $attributes */
    private function evidence(array $fixture, array $attributes): PaymentEvidenceVersion
    {
        $position = app(TermAccountProjection::class)->forAccount($fixture['account']);

        return PaymentEvidenceVersion::factory()->create([
            'assessment_id' => $fixture['assessment']->id,
            'obligation_snapshot' => collect($position['obligations'])->where('is_due', true)->map(fn (array $row): array => ['id' => $row['id'], 'code' => $row['code'], 'label' => $row['label'], 'available_balance' => $row['available_balance']])->values()->all(),
            ...$attributes,
        ]);
    }
}
