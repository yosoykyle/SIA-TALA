<?php

namespace App\Filament\Pages;

use App\Actions\SystemAdministration\SystemHealthPresenter;
use App\Mail\TestConnectionMail;
use App\Models\OperationalEvent;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;
use UnitEnum;

class SystemHealth extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'System Administration';

    protected static ?string $navigationLabel = 'System Health';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'system-health';

    protected static ?string $title = 'System Health';

    protected string $view = 'filament.pages.system-health';

    /** @var array{captured_at:string,rows:array<string,array{service:string,status:string,evidence:string,as_of:string,next_action:string}>} */
    public array $capture = ['captured_at' => '', 'rows' => []];

    public bool $captureStale = false;

    public ?string $captureNotice = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleSystemSuperAdmin) ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        try {
            $this->capture = $this->health()->capture();
        } catch (Throwable) {
            $this->captureStale = true;
            $this->captureNotice = 'Local evidence could not be captured. No prior evidence was overwritten.';
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->capture['rows'])
            ->columns([
                TextColumn::make('service')
                    ->label('Service')
                    ->weight('semibold')
                    ->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        SystemHealthPresenter::Available => 'success',
                        SystemHealthPresenter::NeedsAttention => 'warning',
                        SystemHealthPresenter::Unavailable => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('evidence')
                    ->label('Safe local evidence')
                    ->wrap(),
                TextColumn::make('evidence_source')
                    ->label('Evidence source')
                    ->wrap(),
                TextColumn::make('as_of')
                    ->label('As of')
                    ->wrap(),
                TextColumn::make('responsible_owner')
                    ->label('Responsible owner')
                    ->wrap(),
                TextColumn::make('next_action')
                    ->label('Safe next action')
                    ->wrap(),
            ])
            ->stackedOnMobile()
            ->paginated(false)
            ->emptyStateHeading('No local evidence capture is available')
            ->emptyStateDescription('Refresh the bounded local evidence. Provider and custody facts remain outside this page.');
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshLocalEvidence')
                ->label('Refresh local evidence')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => $this->refreshLocalEvidence()),
            Action::make('sendTestEmail')
                ->label('Send self-test email')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->requiresConfirmation()
                ->modalHeading('Send one mail self-test')
                ->modalDescription('This synchronously checks the configured transport using only your signed-in address. It does not verify provider delivery or an SLA.')
                ->modalSubmitActionLabel('Send self-test')
                ->action(fn () => $this->sendTestEmail()),
        ];
    }

    private function refreshLocalEvidence(): void
    {
        abort_unless(static::canAccess(), 403);

        try {
            $this->capture = $this->health()->capture();
            $this->captureStale = false;
            $this->captureNotice = 'Local evidence was refreshed. No provider or business record was changed.';
        } catch (Throwable) {
            $this->captureStale = true;
            $this->captureNotice = 'Refresh failed. The preceding capture was retained and is now marked stale.';
        }

        $this->resetTable();
    }

    private function sendTestEmail(): void
    {
        abort_unless(static::canAccess(), 403);

        $actor = $this->actor();
        $key = 'tala:system-health:mail-self-test:'.$actor->getKey();

        if (RateLimiter::tooManyAttempts($key, 1)) {
            Notification::make()
                ->title('Mail self-test is throttled')
                ->body('Try again in '.RateLimiter::availableIn($key).' seconds.')
                ->warning()
                ->send();

            return;
        }

        RateLimiter::hit($key, 60);

        try {
            Mail::to($actor->email)->send(new TestConnectionMail);
            $this->recordMailSelfTest($actor, OperationalEvent::StatusProcessed);

            Notification::make()
                ->title('Mail self-test accepted')
                ->body('The configured transport accepted the message for your signed-in address. Provider delivery is not certified.')
                ->success()
                ->send();
        } catch (Throwable) {
            $this->recordMailSelfTest($actor, OperationalEvent::StatusFailed);

            Notification::make()
                ->title('Mail self-test failed')
                ->body('The configured transport did not accept the message. No raw provider detail was stored.')
                ->danger()
                ->send();
        }

        $this->refreshLocalEvidence();
    }

    private function recordMailSelfTest(User $actor, string $status): void
    {
        OperationalEvent::query()->create([
            'event_domain' => OperationalEvent::DomainNotifications,
            'integration' => OperationalEvent::IntegrationMail,
            'channel' => OperationalEvent::ChannelEmail,
            'direction' => OperationalEvent::DirectionOutbound,
            'event_type' => $status === OperationalEvent::StatusProcessed
                ? 'mail_self_test_accepted'
                : 'mail_self_test_failed',
            'user_id' => $actor->getKey(),
            'status' => $status,
            'occurred_at' => now(),
            'processed_at' => $status === OperationalEvent::StatusProcessed ? now() : null,
            'sent_at' => $status === OperationalEvent::StatusProcessed ? now() : null,
            'failed_at' => $status === OperationalEvent::StatusFailed ? now() : null,
        ]);
    }

    private function health(): SystemHealthPresenter
    {
        return app(SystemHealthPresenter::class);
    }

    private function actor(): User
    {
        $actor = auth()->user();

        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
