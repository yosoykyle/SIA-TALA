<?php

namespace Tests\Feature;

use App\Actions\Finance\MapOfficialReceiptToPayment;
use App\Actions\Finance\PaymentConfirmationService;
use App\Filament\Resources\Assessments\Pages\ViewAssessment;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL86BManualPaymentOrMappingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_test_codex', DB::connection()->getDatabaseName());

        foreach ([
            'student',
            User::StaffRoleAccounting,
            User::StaffRoleRegistrar,
            User::StaffRoleFaculty,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_accounting_records_manual_payment_from_active_assessment_and_posts_one_ledger_payment(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $fixture = $this->activeAssessmentFixture();

        Livewire::actingAs($accounting)
            ->test(ViewAssessment::class, ['record' => $fixture['assessment']->getRouteKey()])
            ->assertActionVisible('recordManualPayment')
            ->callAction('recordManualPayment', data: [
                'amount' => '500.00',
                'channel' => 'cash',
                'payment_reference' => 'MANUAL-CASH-86B-001',
                'or_number' => 'OR-86B-001',
                'paid_at' => '2026-06-12 10:30:00',
            ])
            ->assertNotified('Manual payment recorded');

        $payment = Payment::query()->sole();
        $ledgerEntry = LedgerEntry::query()
            ->where('direction', LedgerEntry::DirectionPayment)
            ->sole();

        $this->assertSame('verified', $payment->evidence_status);
        $this->assertSame('MANUAL-CASH-86B-001', $payment->provider_reference);
        $this->assertSame('OR-86B-001', $payment->or_number);
        $this->assertSame('cash', $payment->channel);
        $this->assertSame($accounting->id, $payment->verified_by);
        $this->assertSame($payment->id, $ledgerEntry->payment_id);
        $this->assertSame(Payment::class, $ledgerEntry->source_type);
        $this->assertSame($payment->id, $ledgerEntry->source_id);
        $this->assertSame('posted', $ledgerEntry->state);
        $this->assertSame('500.00', (string) $ledgerEntry->amount);
        $this->assertSame(1, LedgerEntry::query()->where('direction', LedgerEntry::DirectionPayment)->count());
    }

    public function test_duplicate_manual_payment_reference_and_or_number_are_rejected_without_reposting(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $fixture = $this->activeAssessmentFixture();
        $service = app(PaymentConfirmationService::class);

        $service->confirmManualPayment(
            enrollmentId: $fixture['enrollment']->id,
            amount: '500.00',
            channel: 'cash',
            paymentReference: 'MANUAL-CASH-86B-DUP',
            actor: $accounting,
            confirmedAt: CarbonImmutable::parse('2026-06-12 10:30:00'),
            orNumber: 'OR-86B-DUP',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Payment reference already exists.');

        try {
            $service->confirmManualPayment(
                enrollmentId: $fixture['enrollment']->id,
                amount: '250.00',
                channel: 'cash',
                paymentReference: 'MANUAL-CASH-86B-DUP',
                actor: $accounting,
                confirmedAt: CarbonImmutable::parse('2026-06-12 10:45:00'),
                orNumber: 'OR-86B-OTHER',
            );
        } finally {
            $this->assertSame(1, Payment::query()->count());
            $this->assertSame(1, LedgerEntry::query()->where('direction', LedgerEntry::DirectionPayment)->count());
        }
    }

    public function test_duplicate_manual_or_number_is_rejected_without_reposting(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $fixture = $this->activeAssessmentFixture();
        $service = app(PaymentConfirmationService::class);

        $service->confirmManualPayment(
            enrollmentId: $fixture['enrollment']->id,
            amount: '500.00',
            channel: 'cash',
            paymentReference: 'MANUAL-CASH-86B-OR-1',
            actor: $accounting,
            confirmedAt: CarbonImmutable::parse('2026-06-12 10:30:00'),
            orNumber: 'OR-86B-UNIQUE',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Official Receipt number already exists.');

        try {
            $service->confirmManualPayment(
                enrollmentId: $fixture['enrollment']->id,
                amount: '250.00',
                channel: 'cash',
                paymentReference: 'MANUAL-CASH-86B-OR-2',
                actor: $accounting,
                confirmedAt: CarbonImmutable::parse('2026-06-12 10:45:00'),
                orNumber: 'OR-86B-UNIQUE',
            );
        } finally {
            $this->assertSame(1, Payment::query()->count());
            $this->assertSame(1, LedgerEntry::query()->where('direction', LedgerEntry::DirectionPayment)->count());
        }
    }

    public function test_non_accounting_user_cannot_record_manual_payment_or_map_or(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $fixture = $this->activeAssessmentFixture();
        $payment = $this->verifiedPostedPayment($fixture);

        Livewire::actingAs($registrar)
            ->test(ViewAssessment::class, ['record' => $fixture['assessment']->getRouteKey()])
            ->assertActionHidden('recordManualPayment');

        $this->assertFalse(Gate::forUser($registrar)->allows('mapOfficialReceipt', $payment));

        try {
            app(PaymentConfirmationService::class)->confirmManualPayment(
                enrollmentId: $fixture['enrollment']->id,
                amount: '250.00',
                channel: 'cash',
                paymentReference: 'MANUAL-CASH-86B-DENIED',
                actor: $registrar,
                confirmedAt: CarbonImmutable::parse('2026-06-12 10:30:00'),
                orNumber: 'OR-86B-DENIED',
            );

            $this->fail('Non-Accounting manual payment recording should be rejected.');
        } catch (AuthorizationException) {
            $this->assertSame(1, Payment::query()->count());
        }

        try {
            app(MapOfficialReceiptToPayment::class)->execute($payment, 'OR-86B-DENIED', $registrar);

            $this->fail('Non-Accounting OR mapping should be rejected.');
        } catch (AuthorizationException) {
            $this->assertNull($payment->fresh()->or_number);
        }

        $this->assertTrue(Gate::forUser($accounting)->allows('mapOfficialReceipt', $payment));
    }

    public function test_under_review_payment_evidence_does_not_post_ledger_or_produce_acknowledgement(): void
    {
        $fixture = $this->activeAssessmentFixture();
        $underReview = Payment::factory()
            ->for($fixture['profile'])
            ->for($fixture['term'])
            ->create([
                'method' => 'paymongo',
                'channel' => 'paymongo',
                'amount' => '500.00',
                'evidence_status' => 'under_review',
                'paid_at' => now(),
                'verified_at' => null,
                'provider_reference' => 'paymongo:under-review-86b',
            ]);

        $this->assertSame(0, LedgerEntry::query()->where('direction', LedgerEntry::DirectionPayment)->count());

        $this->actingAs($fixture['student'])
            ->get(route('finance.payments.acknowledgement', $underReview))
            ->assertForbidden();

        $this->assertFalse(Gate::forUser($this->staff(User::StaffRoleAccounting))->allows('mapOfficialReceipt', $underReview));
    }

    public function test_or_mapping_updates_existing_verified_posted_payment_only_without_new_rows(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $fixture = $this->activeAssessmentFixture();
        $payment = $this->verifiedPostedPayment($fixture);
        $paymentCount = Payment::query()->count();
        $paymentLedgerCount = LedgerEntry::query()->where('direction', LedgerEntry::DirectionPayment)->count();

        Livewire::actingAs($accounting)
            ->test(ListPayments::class)
            ->assertTableActionVisible('mapOr', $payment)
            ->callTableAction('mapOr', $payment, data: [
                'or_number' => 'OR-86B-MAPPED',
            ])
            ->assertNotified('Official Receipt mapped successfully');

        $payment->refresh();

        $this->assertSame('OR-86B-MAPPED', $payment->or_number);
        $this->assertSame($accounting->id, $payment->or_mapped_by);
        $this->assertNotNull($payment->or_mapped_at);
        $this->assertSame($paymentCount, Payment::query()->count());
        $this->assertSame($paymentLedgerCount, LedgerEntry::query()->where('direction', LedgerEntry::DirectionPayment)->count());

        Livewire::actingAs($accounting)
            ->test(ListPayments::class)
            ->assertTableActionHidden('mapOr', $payment->fresh());
    }

    public function test_or_mapping_rejects_duplicate_or_number_and_unposted_payment_evidence(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $fixture = $this->activeAssessmentFixture();
        $mapped = $this->verifiedPostedPayment($fixture, [
            'provider_reference' => 'paymongo:mapped-86b',
            'or_number' => 'OR-86B-TAKEN',
        ]);
        $unposted = Payment::factory()
            ->for($fixture['profile'])
            ->for($fixture['term'])
            ->create([
                'method' => 'paymongo',
                'channel' => 'paymongo',
                'amount' => '500.00',
                'evidence_status' => 'verified',
                'paid_at' => now(),
                'verified_at' => now(),
                'provider_reference' => 'paymongo:unposted-86b',
                'or_number' => null,
            ]);
        $candidate = $this->verifiedPostedPayment($fixture, [
            'provider_reference' => 'paymongo:candidate-86b',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Official Receipt number already exists.');

        try {
            app(MapOfficialReceiptToPayment::class)->execute($candidate, (string) $mapped->or_number, $accounting);
        } finally {
            $this->assertNull($candidate->fresh()->or_number);
            $this->assertFalse(Gate::forUser($accounting)->allows('mapOfficialReceipt', $unposted));
            $this->assertSame(3, Payment::query()->count());
            $this->assertSame(2, LedgerEntry::query()->where('direction', LedgerEntry::DirectionPayment)->count());
        }
    }

    /**
     * @return array{student:User,profile:StudentProfile,term:Term,enrollment:Enrollment,assessment:Assessment}
     */
    private function activeAssessmentFixture(): array
    {
        $student = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $student->assignRole('student');

        $program = Program::factory()->create();
        $profile = StudentProfile::factory()
            ->for($student)
            ->for($program)
            ->create();
        $term = Term::factory()->create(['label' => 'First Semester 2026-2027']);
        $enrollment = Enrollment::factory()
            ->for($profile)
            ->for($term)
            ->create(['status' => 'pending_payment']);
        $assessment = Assessment::query()->create([
            'enrollment_id' => $enrollment->id,
            'version' => 1,
            'state' => Assessment::StateActive,
            'currency' => 'PHP',
            'subtotal' => '5800.00',
            'discount_total' => '0.00',
            'total' => '5800.00',
            'required_downpayment' => '1500.00',
            'activated_at' => now()->subDay(),
        ]);

        LedgerEntry::query()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'direction' => LedgerEntry::DirectionCharge,
            'category' => 'assessment',
            'amount' => '5800.00',
            'source_type' => Assessment::class,
            'source_id' => $assessment->id,
            'description' => 'Active assessment charge',
            'posted_at' => now()->subHours(2),
            'state' => 'posted',
        ]);

        return [
            'student' => $student,
            'profile' => $profile,
            'term' => $term,
            'enrollment' => $enrollment,
            'assessment' => $assessment,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array{profile:StudentProfile,term:Term,enrollment:Enrollment}  $fixture
     */
    private function verifiedPostedPayment(array $fixture, array $overrides = []): Payment
    {
        $payment = Payment::factory()
            ->for($fixture['profile'])
            ->for($fixture['term'])
            ->create([
                'method' => 'paymongo',
                'channel' => 'paymongo',
                'amount' => '500.00',
                'evidence_status' => 'verified',
                'paid_at' => now()->subHour(),
                'verified_at' => now()->subMinutes(50),
                'provider_reference' => 'paymongo:verified-'.fake()->unique()->uuid(),
                'or_number' => null,
                ...$overrides,
            ]);

        LedgerEntry::query()->create([
            'student_profile_id' => $fixture['profile']->id,
            'term_id' => $fixture['term']->id,
            'enrollment_id' => $fixture['enrollment']->id,
            'direction' => LedgerEntry::DirectionPayment,
            'category' => 'payment',
            'amount' => $payment->amount,
            'source_type' => Payment::class,
            'source_id' => $payment->id,
            'payment_id' => $payment->id,
            'description' => 'Verified posted payment',
            'posted_at' => now()->subMinutes(45),
            'state' => 'posted',
        ]);

        return $payment->refresh();
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
