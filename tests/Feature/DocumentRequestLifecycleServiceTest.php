<?php

namespace Tests\Feature;

use App\Actions\ServiceRequests\DocumentRequestLifecycleService;
use App\Models\DocumentRequest;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DocumentRequestLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_request_type_options_match_approved_spec_list(): void
    {
        $this->assertSame([
            DocumentRequest::TypeCertificateOfRegistration => 'Certificate of Registration',
            DocumentRequest::TypeCertificateOfEnrollment => 'Certificate of Enrollment',
            DocumentRequest::TypeGoodMoralCharacter => 'Certificate of Good Moral Character',
            DocumentRequest::TypeTranscriptOfRecords => 'Transcript of Records',
            DocumentRequest::TypeForm137 => 'Form 137',
            DocumentRequest::TypeForm138 => 'Form 138',
            DocumentRequest::TypeDiploma => 'Diploma',
            DocumentRequest::TypeOther => 'Other',
        ], DocumentRequest::documentTypeOptions());
    }

    public function test_courier_requests_require_consent_before_creation_or_shipping(): void
    {
        $source = $this->source(DocumentRequestLifecycleService::class);

        $this->assertStringContainsString('Courier delivery requires explicit data privacy consent.', $source);
        $this->assertStringContainsString('Courier request cannot ship without data privacy consent.', $source);
        $this->assertStringContainsString('DeliveryModeCourier', $source);
    }

    public function test_shipping_flow_moves_to_pending_shipping_payment_with_three_day_grace(): void
    {
        $source = $this->source(DocumentRequestLifecycleService::class);

        $this->assertStringContainsString('StatusPendingShippingPayment', $source);
        $this->assertStringContainsString("'shipping_grace_ends_at' => \$timestamp->addDays(3)", $source);
        $this->assertStringContainsString('Please pay the shipping fee within 3 calendar days to avoid debt posting.', $source);
        $this->assertStringContainsString('confirmShippingPayment', $source);
    }

    public function test_shipping_receipt_path_must_use_private_document_request_receipt_directory(): void
    {
        $source = $this->source(DocumentRequestLifecycleService::class);

        $this->assertSame('document-request-receipts', DocumentRequest::CourierReceiptDirectory);
        $this->assertStringContainsString('DocumentRequest::CourierReceiptDirectory', $source);
        $this->assertStringContainsString('str_starts_with($receiptPath, DocumentRequest::CourierReceiptDirectory.\'/\')', $source);
        $this->assertStringContainsString('private document request receipt field', $source);
    }

    public function test_manual_shipping_payment_uses_the_shared_payment_contract_and_posts_atomically(): void
    {
        Notification::fake();
        [$request, $studentProfile, $accounting] = $this->shippingPaymentContext();
        $confirmedAt = CarbonImmutable::now(config('app.timezone'))->subDay()->startOfMinute();

        $completedRequest = app(DocumentRequestLifecycleService::class)->confirmShippingPaymentManually(
            request: $request,
            cashier: $accounting,
            amount: '150.00',
            channel: 'bank_transfer',
            paymentReference: '  BANK-SHIPPING-1  ',
            confirmedAt: $confirmedAt,
        );

        $payment = Payment::query()->sole();
        $ledgerEntry = LedgerEntry::query()->where('entry_type', 'payment')->sole();

        $this->assertSame(DocumentRequest::StatusCompleted, $completedRequest->status);
        $this->assertSame('BANK-SHIPPING-1', $payment->payment_reference);
        $this->assertSame('bank_transfer', $payment->channel);
        $this->assertTrue($payment->confirmed_at->equalTo($confirmedAt));
        $this->assertSame($ledgerEntry->id, $payment->ledger_entry_id);
        $this->assertSame('-150.00', $ledgerEntry->amount);
        $this->assertSame('0.00', $ledgerEntry->running_balance);
        $this->assertSame($ledgerEntry->id, $completedRequest->shipping_fee_payment_transaction_id);
        $this->assertSame('0.00', $studentProfile->fresh()->current_balance);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => DocumentRequest::class,
            'subject_id' => $request->id,
            'event' => 'shipping_payment_confirmed',
        ]);
    }

    public function test_manual_shipping_payment_rejects_untrusted_confirmation_inputs_without_posting(): void
    {
        Notification::fake();
        [$request, $studentProfile, $accounting] = $this->shippingPaymentContext();
        $service = app(DocumentRequestLifecycleService::class);

        foreach ([
            ['channel' => 'cash', 'reference' => '   ', 'confirmed_at' => null, 'message' => 'Payment reference is required.'],
            ['channel' => 'paymongo_reconciled', 'reference' => 'PAYMONGO-SHIPPING', 'confirmed_at' => null, 'message' => 'Unsupported manual payment channel.'],
            ['channel' => 'cash', 'reference' => 'OR-FUTURE', 'confirmed_at' => CarbonImmutable::now(config('app.timezone'))->addMinute(), 'message' => 'Payment confirmation date cannot be in the future.'],
        ] as $case) {
            $caughtException = null;

            try {
                $service->confirmShippingPaymentManually(
                    request: $request,
                    cashier: $accounting,
                    amount: '150.00',
                    channel: $case['channel'],
                    paymentReference: $case['reference'],
                    confirmedAt: $case['confirmed_at'],
                );
            } catch (RuntimeException $exception) {
                $caughtException = $exception;
            }

            $this->assertInstanceOf(RuntimeException::class, $caughtException);
            $this->assertSame($case['message'], $caughtException->getMessage());
        }

        $this->assertDatabaseCount(Payment::class, 0);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'payment')->count());
        $this->assertSame(DocumentRequest::StatusPendingShippingPayment, $request->fresh()->status);
        $this->assertSame('150.00', $studentProfile->fresh()->current_balance);
    }

    private function source(string $class): string
    {
        $reflection = new \ReflectionClass($class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);

        return $source;
    }

    /**
     * @return array{DocumentRequest, StudentProfile, User}
     */
    private function shippingPaymentContext(): array
    {
        $term = Term::factory()->create();
        $studentProfile = StudentProfile::factory()->create([
            'current_balance' => '150.00',
        ]);
        $request = DocumentRequest::factory()->courier()->create([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $term->id,
            'status' => DocumentRequest::StatusPendingShippingPayment,
            'shipping_fee' => '150.00',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $accounting = User::factory()->create();
        $accounting->givePermissionTo(Permission::findOrCreate('process-payments'));

        return [$request, $studentProfile, $accounting];
    }
}
