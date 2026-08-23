<?php

namespace Tests\Feature\Finance;

use App\Actions\Integrations\Payments\CreatePaymentCheckoutSession;
use App\Actions\Integrations\Payments\PaymentCheckoutRequest;
use App\Actions\Integrations\Payments\PaymentCheckoutSession;
use App\Actions\Integrations\Payments\PaymentGateway;
use App\Actions\Integrations\Payments\PayMongoCheckoutReadinessService;
use App\Models\Assessment;
use App\Models\AssessmentObligation;
use App\Models\Enrollment;
use App\Models\PaymentAttempt;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermAccount;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ExactDuePayMongoJourneyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        Role::query()->firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
    }

    public function test_checkout_commits_one_exact_due_snapshot_before_the_provider_call(): void
    {
        $fixture = $this->fixture();
        $gateway = new ExactDueRecordingGateway;
        $gateway->beforeCreate = function (PaymentCheckoutRequest $request, string $idempotencyKey) use ($fixture): void {
            $this->assertSame('2000.00', $request->amount);
            $this->assertSame($fixture['account']->id, $request->termAccountId);
            $this->assertSame($fixture['assessment']->version, $request->assessmentVersion);
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $request->snapshotChecksum);

            $attempt = PaymentAttempt::query()->with('obligations')->where('internal_reference', $idempotencyKey)->sole();
            $this->assertSame(PaymentAttempt::StatusPending, $attempt->status);
            $this->assertNull($attempt->provider_checkout_id);
            $this->assertSame(['800.00', '1200.00'], $attempt->obligations->pluck('amount')->all());
            $this->assertSame(
                $fixture['due']->pluck('id')->all(),
                $attempt->obligations->pluck('assessment_obligation_id')->all(),
            );
        };
        $this->app->instance(PaymentGateway::class, $gateway);

        $result = app(CreatePaymentCheckoutSession::class)->create(actor: $fixture['student']);
        $attempt = PaymentAttempt::query()->with('obligations')->findOrFail($result['payment_attempt_id']);

        $this->assertSame('2000.00', $result['amount']);
        $this->assertSame($fixture['account']->id, $attempt->term_account_id);
        $this->assertSame($fixture['assessment']->id, $attempt->assessment_id);
        $this->assertSame(2, $attempt->obligations->count());
        $this->assertSame(1, $gateway->createCalls);
    }

    public function test_one_term_account_cannot_hold_competing_active_attempts(): void
    {
        $fixture = $this->fixture();
        $this->app->instance(PaymentGateway::class, new ExactDueRecordingGateway);
        $created = app(CreatePaymentCheckoutSession::class)->create(actor: $fixture['student']);
        $attempt = PaymentAttempt::query()->findOrFail($created['payment_attempt_id']);

        $this->expectException(QueryException::class);

        PaymentAttempt::query()->create([
            'assessment_id' => $attempt->assessment_id,
            'term_account_id' => $attempt->term_account_id,
            'student_profile_id' => $attempt->student_profile_id,
            'assessment_version' => $attempt->assessment_version,
            'snapshot_created_at' => now(),
            'snapshot_checksum' => str_repeat('a', 64),
            'channel' => 'paymongo',
            'provider' => 'mock',
            'internal_reference' => 'TALA-PAY-COMPETING',
            'amount' => $attempt->amount,
            'currency' => 'PHP',
            'status' => PaymentAttempt::StatusReviewRequired,
        ]);
    }

    public function test_a_changed_snapshot_requires_provider_confirmed_retirement_before_a_successor(): void
    {
        $fixture = $this->fixture();
        $gateway = new ExactDueRecordingGateway;
        $this->app->instance(PaymentGateway::class, $gateway);
        $creator = app(CreatePaymentCheckoutSession::class);

        $first = $creator->create(actor: $fixture['student']);
        $fixture['due']->last()->update(['amount' => '1300.00']);
        $second = $creator->create(actor: $fixture['student']);

        $this->assertNotSame($first['payment_attempt_id'], $second['payment_attempt_id']);
        $this->assertSame('2100.00', $second['amount']);
        $this->assertSame(PaymentAttempt::StatusExpired, PaymentAttempt::query()->findOrFail($first['payment_attempt_id'])->status);
        $this->assertSame(PaymentAttempt::StatusPending, PaymentAttempt::query()->findOrFail($second['payment_attempt_id'])->status);
        $this->assertSame(1, $gateway->expireCalls);
    }

    public function test_readiness_is_fail_closed_for_configuration_alumni_and_active_attempts(): void
    {
        $fixture = $this->fixture();
        $readiness = app(PayMongoCheckoutReadinessService::class);
        config()->set('tala_integrations.payments.driver', 'mock');
        config()->set('tala_integrations.payments.paymongo.secret_key');
        config()->set('tala_integrations.payments.paymongo.webhook_signature');

        $unconfigured = $readiness->for($fixture['student'], $fixture['account']);

        $this->assertFalse($unconfigured['enabled']);
        $this->assertStringContainsString('Manual payment evidence remains available', $unconfigured['reason']);

        $this->configurePayMongoReadiness();
        $ready = $readiness->for($fixture['student'], $fixture['account']);

        $this->assertTrue($ready['enabled']);
        $this->assertSame('2000.00', $ready['amount']);

        $fixture['profile']->update(['lifecycle_status' => StudentProfile::LifecycleCompleted]);
        $completed = $readiness->for($fixture['student'], $fixture['account']->fresh());

        $this->assertFalse($completed['enabled']);
        $this->assertSame('Completed alumni accounts are read-only.', $completed['reason']);

        $fixture['profile']->update(['lifecycle_status' => StudentProfile::LifecycleActive]);
        $this->app->instance(PaymentGateway::class, new ExactDueRecordingGateway);
        app(CreatePaymentCheckoutSession::class)->create(actor: $fixture['student']);
        $pending = $readiness->for($fixture['student'], $fixture['account']->fresh());

        $this->assertFalse($pending['enabled']);
        $this->assertSame('Payment confirmation is pending. Do not start another checkout.', $pending['reason']);
    }

    public function test_readiness_rejects_zero_due_and_stale_assessment_authority(): void
    {
        $this->configurePayMongoReadiness();
        $readiness = app(PayMongoCheckoutReadinessService::class);
        $zeroDue = $this->fixture();
        $zeroDue['due']->each->update(['due_at' => now()->addWeek()]);

        $zero = $readiness->for($zeroDue['student'], $zeroDue['account']->fresh());

        $this->assertFalse($zero['enabled']);
        $this->assertSame('There is no positive current due to pay online.', $zero['reason']);

        $stale = $this->fixture();
        $stale['assessment']->update(['content_hash' => null]);
        $unready = $readiness->for($stale['student'], $stale['account']->fresh());

        $this->assertFalse($unready['enabled']);
        $this->assertSame('The current Assessment is not ready for online checkout.', $unready['reason']);
    }

    private function configurePayMongoReadiness(): void
    {
        config()->set('tala_integrations.payments.driver', 'paymongo');
        config()->set('tala_integrations.payments.paymongo.secret_key', 'sk_test_exact_due_not_real');
        config()->set('tala_integrations.payments.paymongo.webhook_signature', 'whsk_exact_due_not_real');
        config()->set('queue.default', 'database');
    }

    /**
     * @return array{student:User,profile:StudentProfile,term:Term,enrollment:Enrollment,account:TermAccount,assessment:Assessment,due:Collection<int, AssessmentObligation>}
     */
    private function fixture(): array
    {
        $student = User::factory()->create(['status' => User::StatusActive, 'email_verified_at' => now()]);
        $student->assignRole('student');
        $profile = StudentProfile::factory()->for($student)->for(Program::factory())->create();
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->for($profile)->for($term)->create([
            'credential_user_id' => $student->id,
            'registered_at' => now()->subDay(),
        ]);
        $account = TermAccount::factory()->for($enrollment)->create([
            'credential_user_id' => $student->id,
            'term_id' => $term->id,
        ]);
        $assessment = Assessment::factory()->for($enrollment)->for($account)->create([
            'version' => 3,
            'content_hash' => hash('sha256', 'exact-due-assessment-'.$enrollment->id),
            'total' => '2500.00',
            'required_downpayment' => '2000.00',
        ]);
        $due = collect([
            AssessmentObligation::factory()->for($assessment)->create([
                'sequence' => 1,
                'code' => 'TUITION-DUE',
                'label' => 'Tuition currently due',
                'amount' => '800.00',
                'due_at' => now()->subDay(),
            ]),
            AssessmentObligation::factory()->for($assessment)->create([
                'sequence' => 2,
                'code' => 'LAB-DUE',
                'label' => 'Laboratory fee currently due',
                'amount' => '1200.00',
                'due_at' => now()->subHour(),
            ]),
        ]);
        AssessmentObligation::factory()->for($assessment)->create([
            'sequence' => 3,
            'code' => 'FUTURE-DUE',
            'label' => 'Future obligation',
            'amount' => '500.00',
            'due_at' => now()->addWeek(),
        ]);

        return compact('student', 'profile', 'term', 'enrollment', 'account', 'assessment', 'due');
    }
}

final class ExactDueRecordingGateway implements PaymentGateway
{
    public int $createCalls = 0;

    public int $expireCalls = 0;

    public ?Closure $beforeCreate = null;

    /** @var array<string, PaymentCheckoutSession> */
    private array $sessions = [];

    public function provider(): string
    {
        return 'mock';
    }

    public function createCheckoutSession(PaymentCheckoutRequest $request, string $idempotencyKey = ''): PaymentCheckoutSession
    {
        $this->createCalls++;

        if ($this->beforeCreate instanceof Closure) {
            ($this->beforeCreate)($request, $idempotencyKey);
        }

        $session = new PaymentCheckoutSession(
            provider: 'mock',
            checkoutSessionId: 'exact_due_'.$this->createCalls,
            checkoutUrl: 'https://mock-payments.test/exact-due/'.$this->createCalls,
            status: 'active',
            metadata: ['driver' => 'mock'],
        );
        $this->sessions[$session->checkoutSessionId] = $session;

        return $session;
    }

    public function retrieveCheckoutSession(string $checkoutSessionId): PaymentCheckoutSession
    {
        return $this->sessions[$checkoutSessionId];
    }

    public function expireCheckoutSession(string $checkoutSessionId): PaymentCheckoutSession
    {
        $this->expireCalls++;
        $current = $this->sessions[$checkoutSessionId];
        $expired = new PaymentCheckoutSession(
            provider: $current->provider,
            checkoutSessionId: $current->checkoutSessionId,
            checkoutUrl: $current->checkoutUrl,
            status: 'expired',
            metadata: $current->metadata,
        );
        $this->sessions[$checkoutSessionId] = $expired;

        return $expired;
    }
}
