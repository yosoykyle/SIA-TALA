<?php

namespace App\Actions\Integrations\Payments;

use App\Mail\PaymentPostedMail;
use App\Models\OperationalEvent;
use App\Models\Payment;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Support\DecimalMoney;
use Illuminate\Support\Facades\Mail;
use Throwable;

class PaymentPostedNotificationService
{
    public function __construct(private readonly DecimalMoney $money) {}

    public function record(Payment $payment): void
    {
        $payment->loadMissing(['studentProfile.user', 'termAccount.credentialUser', 'term']);
        $profile = $payment->studentProfile;
        $recipient = $profile instanceof StudentProfile ? $profile->user : $payment->termAccount?->credentialUser;

        if (! $recipient instanceof User) {
            return;
        }

        $deliveryEvent = OperationalEvent::query()->firstOrCreate(
            [
                'event_domain' => OperationalEvent::DomainNotifications,
                'external_id' => "payment-posted:payment:{$payment->id}:user:{$recipient->id}",
            ],
            [
                'integration' => OperationalEvent::IntegrationMail,
                'channel' => OperationalEvent::ChannelEmail,
                'direction' => OperationalEvent::DirectionOutbound,
                'event_type' => OperationalEvent::TypePaymentPostedEmail,
                'event_version' => '1',
                'user_id' => $recipient->id,
                'recipient_snapshot' => [
                    'user_id' => (int) $recipient->id,
                    'email' => (string) $recipient->email,
                ],
                'status' => OperationalEvent::StatusPending,
                'occurred_at' => now(),
                'processed_at' => null,
                'sent_at' => null,
                'failed_at' => null,
                'related_record_type' => Payment::class,
                'related_record_id' => (int) $payment->id,
                'diagnostics' => null,
                'payload' => [
                    'student_profile_id' => $payment->student_profile_id !== null ? (int) $payment->student_profile_id : null,
                    'term_id' => (int) $payment->term_id,
                    'amount' => $this->money->normalize((string) $payment->amount),
                    'currency' => (string) $payment->currency,
                ],
            ],
        );

        if (! $deliveryEvent->wasRecentlyCreated) {
            return;
        }

        $email = trim((string) $recipient->email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->markFailed($deliveryEvent, 'Recipient email is missing or invalid.');

            return;
        }

        try {
            Mail::to($recipient)->queue(new PaymentPostedMail(
                operationalEventId: (int) $deliveryEvent->id,
                recipientName: (string) $recipient->name,
                amount: 'PHP '.number_format((float) $this->money->normalize((string) $payment->amount), 2),
                termLabel: $payment->term instanceof Term ? (string) $payment->term->label : 'your current term',
                financeUrl: route('filament.student.pages.finance'),
            ));
        } catch (Throwable $exception) {
            $this->markFailed($deliveryEvent, 'Mail could not be queued.', $exception);
        }
    }

    private function markFailed(OperationalEvent $deliveryEvent, string $reason, ?Throwable $exception = null): void
    {
        $timestamp = now();
        $diagnostics = ['reason' => $reason];

        if ($exception instanceof Throwable) {
            $diagnostics['exception_type'] = class_basename($exception);
        }

        $deliveryEvent->forceFill([
            'status' => OperationalEvent::StatusFailed,
            'processed_at' => $timestamp,
            'sent_at' => null,
            'failed_at' => $timestamp,
            'diagnostics' => $diagnostics,
        ])->save();
    }
}
