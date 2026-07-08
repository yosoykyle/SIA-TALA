<?php

namespace App\Filament\Pages;

use App\Mail\TestConnectionMail;
use App\Models\OperationalEvent;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Mail;
use Throwable;
use UnitEnum;

/**
 * TAL-92D: read-only integration status view.
 *
 * Owning contract: PRD `13_system_admin_reports_audit.md` §13.5 (Integration
 * Settings and Operational Monitoring) and §13.8 ("Integration settings" row:
 * "Restricted Record Form; secrets are write-only or stored by secure
 * reference"). Every integration (email, PayMongo, CP-SAT scheduler) is
 * driver-switchable via environment variables, with secrets living only in
 * the environment, never in the database. This page never renders a secret
 * value; it only reports whether one is configured.
 *
 * OCR is intentionally excluded: `.env.example` declares `TALA_OCR_*`
 * variables, but no `config/*.php` file, config key, or application code
 * wires them up anywhere in this codebase (confirmed by full-repo search).
 * Reading `env()` directly here or inventing a new config file would not
 * reflect an existing, accepted integration boundary, so this status row is
 * deferred rather than fabricated. See handshake report for detail.
 */
class IntegrationStatus extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'System Administration';

    protected static ?string $navigationLabel = 'Integration Status';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Integration Status';

    protected string $view = 'filament.pages.integration-status';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleSystemSuperAdmin) ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * @return list<array{name: string, driver: string, live_mode: bool, configured: bool, reference: array<string, string>}>
     */
    public function getIntegrationsProperty(): array
    {
        return [
            $this->emailStatus(),
            $this->paymentsStatus(),
            $this->schedulerStatus(),
        ];
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendTestEmail')
                ->label('Send test email')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Send test email')
                ->modalDescription(fn (): string => 'This sends a mail-connection test message to your own signed-in address ('.$this->actor()->email.') only. No other recipient can be targeted.')
                ->modalSubmitActionLabel('Send test email')
                ->action(fn () => $this->sendTestEmail()),
        ];
    }

    private function sendTestEmail(): void
    {
        $actor = $this->actor();

        try {
            Mail::to($actor->email)->send(new TestConnectionMail);

            OperationalEvent::query()->create([
                'event_domain' => 'notifications',
                'integration' => 'mail',
                'event_type' => 'test_email_sent',
                'user_id' => $actor->id,
                'status' => 'PROCESSED',
                'occurred_at' => now(),
                'sent_at' => now(),
            ]);

            Notification::make()
                ->title('Test email sent')
                ->body('A test email was sent to '.$actor->email.'.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            OperationalEvent::query()->create([
                'event_domain' => 'notifications',
                'integration' => 'mail',
                'event_type' => 'test_email_failed',
                'user_id' => $actor->id,
                'status' => 'FAILED',
                'occurred_at' => now(),
                'failed_at' => now(),
                'diagnostics' => ['reason' => $exception->getMessage()],
            ]);

            Notification::make()
                ->title('Test email failed')
                ->body('The mail transport could not deliver the test email. See Operational Events for diagnostics.')
                ->danger()
                ->send();
        }
    }

    /** @return array{name: string, driver: string, live_mode: bool, configured: bool, reference: array<string, string>} */
    private function emailStatus(): array
    {
        $mailer = (string) config('mail.default');
        $isLive = ! in_array($mailer, ['log', 'array'], true);
        $configuredDriverKeys = match ($mailer) {
            'smtp' => [config('mail.mailers.smtp.host'), config('mail.mailers.smtp.username')],
            'postmark' => [config('services.postmark.token')],
            'ses', 'ses-v2' => [config('services.ses.key')],
            'resend' => [config('services.resend.key')],
            default => [],
        };

        return [
            'name' => 'Email',
            'driver' => $mailer,
            'live_mode' => $isLive,
            'configured' => ! $isLive || collect($configuredDriverKeys)->every(fn (mixed $value): bool => filled($value)),
            'reference' => [
                'From address' => (string) config('mail.from.address'),
                'From name' => (string) config('mail.from.name'),
            ],
        ];
    }

    /** @return array{name: string, driver: string, live_mode: bool, configured: bool, reference: array<string, string>} */
    private function paymentsStatus(): array
    {
        $driver = (string) config('tala_integrations.payments.driver');
        $isPaymongo = $driver === 'paymongo';

        return [
            'name' => 'Payments (PayMongo)',
            'driver' => $driver,
            'live_mode' => $isPaymongo && (bool) config('tala_integrations.payments.paymongo.livemode'),
            'configured' => $isPaymongo
                ? filled(config('tala_integrations.payments.paymongo.secret_key')) && filled(config('tala_integrations.payments.paymongo.public_key'))
                : filled(config('tala_integrations.payments.mock.provider')),
            'reference' => $isPaymongo ? [
                'Base URL' => (string) config('tala_integrations.payments.paymongo.base_url'),
                'Payment method types' => implode(', ', (array) config('tala_integrations.payments.paymongo.payment_method_types')),
            ] : [
                'Mock checkout URL' => (string) config('tala_integrations.payments.mock.checkout_base_url'),
            ],
        ];
    }

    /** @return array{name: string, driver: string, live_mode: bool, configured: bool, reference: array<string, string>} */
    private function schedulerStatus(): array
    {
        $driver = (string) config('tala_integrations.scheduling_solver.driver');
        $isLive = $driver !== 'local_stub';

        return [
            'name' => 'Scheduler (CP-SAT solver)',
            'driver' => $driver,
            'live_mode' => $isLive,
            'configured' => ! $isLive || (filled(config('tala_integrations.scheduling_solver.url')) && filled(config('tala_integrations.scheduling_solver.audience'))),
            'reference' => [
                'URL' => (string) config('tala_integrations.scheduling_solver.url'),
                'Audience' => (string) config('tala_integrations.scheduling_solver.audience'),
                'Timeout (seconds)' => (string) config('tala_integrations.scheduling_solver.timeout_seconds'),
            ],
        ];
    }

    private function actor(): User
    {
        $actor = auth()->user();

        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
