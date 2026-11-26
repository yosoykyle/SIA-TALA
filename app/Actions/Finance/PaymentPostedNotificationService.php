<?php

namespace App\Actions\Finance;

use App\Mail\PaymentPostedMail;
use App\Models\Enrollment;
use App\Models\OperationalEvent;
use App\Models\Payment;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Support\DecimalMoney;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Throwable;

class PaymentPostedNotificationService
{
    public function __construct(private readonly DecimalMoney $money) {}

    public function record(Payment $payment, bool $suppressRegistrarAlert = false): void
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

        $this->sendDatabaseNotifications($payment, $recipient, $suppressRegistrarAlert);

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
                financeUrl: route($recipient->hasRole('student') ? 'filament.student.pages.finance' : 'filament.applicant.pages.dashboard'),
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

    private function sendDatabaseNotifications(Payment $payment, User $recipient, bool $suppressRegistrarAlert = false): void
    {
        $formattedAmount = 'PHP '.number_format((float) $this->money->normalize((string) $payment->amount), 2);
        $termLabel = $payment->term instanceof Term ? (string) $payment->term->label : 'your current term';

        if ($recipient->hasRole('student')) {
            $financeUrl = Route::has('filament.student.pages.finance')
                ? route('filament.student.pages.finance')
                : null;

            $studentNotification = Notification::make()
                ->title('Payment posted')
                ->body("{$formattedAmount} was verified and posted for {$termLabel}.")
                ->success();

            if ($financeUrl !== null) {
                $studentNotification->actions([
                    Action::make('viewFinance')
                        ->label('View finance')
                        ->url($financeUrl),
                ]);
            }

            $studentNotification->sendToDatabase($recipient);
        }

        if ($suppressRegistrarAlert) {
            return;
        }

        $enrollment = $payment->termAccount?->enrollment;
        if ($enrollment instanceof Enrollment && $enrollment->canonical_outcome === Enrollment::OutcomeInProgress) {
            $registrars = User::query()
                ->where('status', User::StatusActive)
                ->whereHas('roles', fn ($query) => $query->where('name', User::StaffRoleRegistrar))
                ->get();

            if ($registrars->isEmpty()) {
                return;
            }

            $enrollmentUrl = Route::has('filament.admin.resources.enrollments.view')
                ? route('filament.admin.resources.enrollments.view', ['record' => $enrollment->id])
                : null;

            $registrarNotification = Notification::make()
                ->title('Payment verified for pending enrollment')
                ->body("{$formattedAmount} was verified for {$recipient->name} ({$termLabel}).")
                ->success();

            if ($enrollmentUrl !== null) {
                $registrarNotification->actions([
                    Action::make('reviewEnrollment')
                        ->label('Review enrollment')
                        ->url($enrollmentUrl),
                ]);
            }

            $registrarNotification->sendToDatabase($registrars);
        }
    }
}
