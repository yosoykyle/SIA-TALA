<?php

namespace App\Filament\Pages;

use App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverTransportException;
use App\Actions\SystemAdministration\SystemHealthPresenter;
use App\Mail\TestConnectionMail;
use App\Models\OperationalEvent;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;
use UnitEnum;

class SystemHealth extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'System Administration';

    protected static ?string $navigationLabel = 'System Health';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'system-health';

    protected static ?string $title = 'System Health';

    public function getSubheading(): ?string
    {
        return 'Monitor live application, queue, mail transport, and scheduling solver status.';
    }

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected string $view = 'filament.pages.system-health';

    /** @var array{captured_at:string,captured_at_display:string,rows:array<string,array<string,mixed>>,local_rows:array<string,array<string,mixed>>,external_rows:array<string,array<string,mixed>>} */
    public array $capture = [
        'captured_at' => '',
        'captured_at_display' => 'Not recorded yet',
        'rows' => [],
        'local_rows' => [],
        'external_rows' => [],
    ];

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

    /**
     * @return array{
     *     label: string,
     *     color: string,
     *     description: string,
     *     available_count: int,
     *     attention_count: int,
     *     unavailable_count: int,
     *     not_checked_count: int,
     *     local_total: int,
     *     external_count: int,
     *     captured_at_display: string
     * }
     */
    public function getHealthSummaryProperty(): array
    {
        return $this->health()->summary($this->capture);
    }

    /** @return list<array<string, mixed>> */
    public function getHealthCardsProperty(): array
    {
        return array_values($this->capture['local_rows'] ?? $this->capture['rows'] ?? []);
    }

    public function viewTechnicalDetailsAction(): Action
    {
        return Action::make('viewTechnicalDetails')
            ->label('Technical details')
            ->icon(Heroicon::OutlinedCommandLine)
            ->color('gray')
            ->size(Size::Small)
            ->slideOver()
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalHeading('App & Database: Technical details')
            ->modalDescription('Bounded local runtime and configuration metadata captured from this server.')
            ->infolist(function (): array {
                $record = $this->capture['local_rows']['app_database'] ?? [];

                return [
                    TextEntry::make('service')
                        ->label('Service')
                        ->state((string) ($record['service'] ?? 'App & Database')),
                    TextEntry::make('status')
                        ->label('Current status')
                        ->state((string) ($record['status'] ?? SystemHealthPresenter::NotRecentlyChecked))
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            SystemHealthPresenter::Available => 'success',
                            SystemHealthPresenter::NeedsAttention => 'warning',
                            SystemHealthPresenter::Unavailable => 'danger',
                            default => 'gray',
                        }),
                    TextEntry::make('technical_details')
                        ->label('Runtime & configuration metadata')
                        ->state(filled($record['technical_details'] ?? null) ? (string) $record['technical_details'] : null)
                        ->placeholder('No additional metadata recorded.'),
                    TextEntry::make('evidence_source')
                        ->label('Evidence source')
                        ->state((string) ($record['evidence_source'] ?? 'Not recorded')),
                    TextEntry::make('as_of_display')
                        ->label('Recorded at (Asia/Manila)')
                        ->state((string) ($record['as_of_display'] ?? 'Not recorded yet')),
                    TextEntry::make('next_action')
                        ->label('Recommended next action')
                        ->state((string) ($record['next_action'] ?? 'None')),
                ];
            });
    }

    public function inspectFailedJobsAction(): Action
    {
        return Action::make('inspectFailedJobs')
            ->label('Inspect failed jobs')
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->color('gray')
            ->size(Size::Small)
            ->slideOver()
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalHeading('Failed queue jobs')
            ->modalDescription('Sanitized failed job summaries from the failed_jobs table. Serialized payloads and personal data are excluded.')
            ->modalContent(fn (): View => view('filament.admin.system-health.failed-jobs-modal', [
                'jobs' => $this->health()->failedJobs(),
            ]));
    }

    public function retryFailedJobsAction(): Action
    {
        return Action::make('retryFailedJobs')
            ->label('Retry failed jobs')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->size(Size::Small)
            ->requiresConfirmation()
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalHeading('Retry all failed jobs')
            ->modalDescription('Re-queues all currently failed jobs on their configured queues. Job payloads are not exposed or modified.')
            ->modalSubmitActionLabel('Retry failed jobs')
            ->visible(fn (): bool => $this->failedJobsCount() > 0)
            ->action(fn () => $this->retryFailedJobs());
    }

    public function sendTestEmailAction(): Action
    {
        return Action::make('sendTestEmail')
            ->label('Send test email')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->size(Size::Small)
            ->requiresConfirmation()
            ->modalHeading('Send test email to your account')
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription('Sends one diagnostic test email to your own signed-in administrator address only (rate-limited to once per minute). This checks local mail transport configuration and does not certify external provider delivery.')
            ->modalSubmitActionLabel('Send test email')
            ->action(fn () => $this->sendTestEmail());
    }

    public function checkSolverAction(): Action
    {
        return Action::make('checkSolver')
            ->label('Check solver')
            ->icon(Heroicon::OutlinedSignal)
            ->color('gray')
            ->size(Size::Small)
            ->action(fn () => $this->checkSolver());
    }

    public function solverRunsReportsAction(): Action
    {
        return Action::make('solverRunsReports')
            ->label('Solver runs & reports')
            ->icon(Heroicon::OutlinedDocumentChartBar)
            ->color('gray')
            ->size(Size::Small)
            ->slideOver()
            ->modalWidth(Width::FourExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalHeading('Recent solver runs & reports')
            ->modalDescription('Read-only operational history of recent timetable generation runs across terms. Schedule inputs and personal data are excluded.')
            ->modalContent(fn (): View => view('filament.admin.system-health.solver-runs-modal', [
                'runs' => $this->health()->recentSolverRuns(),
            ]));
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshLocalEvidence')
                ->label('Refresh status')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => $this->refreshLocalEvidence()),
        ];
    }

    private function failedJobsCount(): int
    {
        return (int) ($this->capture['local_rows']['queue']['failed_count'] ?? 0);
    }

    private function refreshLocalEvidence(): void
    {
        abort_unless(static::canAccess(), 403);

        try {
            $this->capture = $this->health()->capture();
            $this->captureStale = false;
            $this->captureNotice = null;

            Notification::make()
                ->title('System status refreshed')
                ->body('Local health evidence is up to date.')
                ->success()
                ->send();
        } catch (Throwable) {
            $this->captureStale = true;
            $this->captureNotice = 'Refresh failed. The preceding capture was retained and is now marked stale.';
        }
    }

    private function retryFailedJobs(): void
    {
        abort_unless(static::canAccess(), 403);

        $actor = $this->actor();
        $count = DB::table('failed_jobs')->count();

        if ($count === 0) {
            Notification::make()
                ->title('No failed jobs to retry')
                ->body('The failed jobs table is currently empty.')
                ->info()
                ->send();

            $this->refreshLocalEvidence();

            return;
        }

        try {
            $exitCode = Artisan::call('queue:retry', ['id' => ['all']]);

            if ($exitCode !== 0) {
                Notification::make()
                    ->title('Failed jobs could not be retried')
                    ->body('The queue retry command did not complete cleanly.')
                    ->danger()
                    ->send();

                return;
            }

            activity()
                ->causedBy($actor)
                ->event('failed_jobs_retried')
                ->withProperties(['retried_count' => $count])
                ->log('Failed queue jobs retried');

            Notification::make()
                ->title('Failed jobs queued for retry')
                ->body("Re-queued {$count} failed job(s) back onto their configured queues.")
                ->success()
                ->send();
        } catch (Throwable) {
            Notification::make()
                ->title('Failed jobs could not be retried')
                ->body('Queue retry failed safely without altering application records.')
                ->danger()
                ->send();
        }

        $this->refreshLocalEvidence();
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
        } catch (Throwable $exception) {
            $event = $this->recordMailSelfTest($actor, OperationalEvent::StatusFailed);
            Cache::put(
                'tala:system-health:mail-failure-category:'.$event->getKey(),
                SystemHealthPresenter::classifyMailException($exception),
                now()->addDays(7),
            );

            Notification::make()
                ->title('Mail self-test failed')
                ->body('The configured transport did not accept the message. No raw provider detail was stored.')
                ->danger()
                ->send();
        }

        $this->refreshLocalEvidence();
    }

    private function checkSolver(): void
    {
        abort_unless(static::canAccess(), 403);

        $actor = $this->actor();
        $key = 'tala:system-health:solver-probe:'.$actor->getKey();

        if (RateLimiter::tooManyAttempts($key, 1)) {
            Notification::make()
                ->title('Solver check is throttled')
                ->body('Try again in '.RateLimiter::availableIn($key).' seconds.')
                ->warning()
                ->send();

            return;
        }

        RateLimiter::hit($key, 30);

        $driver = (string) config('tala_integrations.scheduling_solver.driver');

        try {
            $probe = app(SchedulingSolverClient::class)->probe();
            $statusCode = (int) ($probe['status'] ?? 0);
            $ok = $statusCode >= 200 && $statusCode < 300;

            $diagnostics = $ok ? null : [
                'classification' => match (true) {
                    $statusCode === 429 => SchedulingSolverTransportException::ClassificationRateLimited,
                    $statusCode === 408 => SchedulingSolverTransportException::ClassificationTimeout,
                    $statusCode >= 500 => SchedulingSolverTransportException::ClassificationServerError,
                    default => SchedulingSolverTransportException::ClassificationClientError,
                },
                'status_code' => $statusCode > 0 ? $statusCode : null,
                'driver' => $driver,
            ];

            $this->recordSolverProbe(
                $actor,
                $ok ? OperationalEvent::StatusProcessed : OperationalEvent::StatusFailed,
                $diagnostics,
            );

            if ($ok) {
                Notification::make()
                    ->title('Scheduling solver is online')
                    ->body('The solver health endpoint responded normally (HTTP '.$statusCode.').')
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Scheduling solver is unreachable')
                    ->body('The solver health endpoint returned HTTP '.$statusCode.'. Review troubleshooting steps on the Solver card.')
                    ->danger()
                    ->send();
            }
        } catch (SchedulingSolverTransportException $exception) {
            $this->recordSolverProbe($actor, OperationalEvent::StatusFailed, [
                'classification' => $exception->classification(),
                'status_code' => $exception->statusCode(),
                'driver' => $driver,
            ]);

            Notification::make()
                ->title('Scheduling solver is unreachable')
                ->body('Health probe failed ('.$exception->classification().'). Review troubleshooting steps on the Solver card.')
                ->danger()
                ->send();
        } catch (Throwable) {
            $this->recordSolverProbe($actor, OperationalEvent::StatusFailed, [
                'classification' => SchedulingSolverTransportException::ClassificationUnexpected,
                'status_code' => null,
                'driver' => $driver,
            ]);

            Notification::make()
                ->title('Scheduling solver is unreachable')
                ->body('The solver health endpoint could not be reached. No timetable generation was started.')
                ->danger()
                ->send();
        }

        $this->refreshLocalEvidence();
    }

    private function recordMailSelfTest(User $actor, string $status): OperationalEvent
    {
        return OperationalEvent::query()->create([
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

    /** @param array<string, mixed>|null $diagnostics */
    private function recordSolverProbe(User $actor, string $status, ?array $diagnostics = null): void
    {
        OperationalEvent::query()->create([
            'event_domain' => OperationalEvent::DomainIntegration,
            'integration' => OperationalEvent::IntegrationSchedulingSolver,
            'channel' => 'http',
            'direction' => OperationalEvent::DirectionOutbound,
            'event_type' => 'solver_health_probe',
            'user_id' => $actor->getKey(),
            'status' => $status,
            'occurred_at' => now(),
            'processed_at' => $status === OperationalEvent::StatusProcessed ? now() : null,
            'failed_at' => $status === OperationalEvent::StatusFailed ? now() : null,
            'diagnostics' => $diagnostics,
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
