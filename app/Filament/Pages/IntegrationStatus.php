<?php

namespace App\Filament\Pages;

use App\Actions\SystemAdministration\IntegrationHealthPresenter;
use App\Filament\Resources\SystemSettings\SystemSettingResource;
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

    /** @return list<array<string, mixed>> */
    public function getIntegrationsProperty(): array
    {
        return $this->health()->integrations();
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('systemSettings')
                ->label('System settings')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->color('gray')
                ->url(SystemSettingResource::getUrl('index'))
                ->visible(fn (): bool => SystemSettingResource::canAccess()),
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

    private function health(): IntegrationHealthPresenter
    {
        return app(IntegrationHealthPresenter::class);
    }

    private function actor(): User
    {
        $actor = auth()->user();

        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
