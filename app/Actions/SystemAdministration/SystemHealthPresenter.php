<?php

namespace App\Actions\SystemAdministration;

use App\Actions\Integrations\SchedulingSolver\LocalHttpSchedulingSolverClient;
use App\Models\OperationalEvent;
use App\Models\PaymentAttempt;
use App\Models\ScheduleGenerationRun;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SystemHealthPresenter
{
    public const Available = 'Available';

    public const Attention = 'Attention';

    public const Unavailable = 'Unavailable';

    public const Unknown = 'Unknown';

    /** @return array{captured_at: string, rows: array<string, array{service:string,status:string,evidence:string,as_of:string,next_action:string}>} */
    public function capture(): array
    {
        $capturedAt = now();

        return [
            'captured_at' => $capturedAt->toIso8601String(),
            'rows' => collect([
                $this->mailStatus(),
                $this->payMongoStatus(),
                $this->solverStatus(),
                $this->queueStatus(),
                $this->applicationStatus($capturedAt),
                $this->databaseStatus($capturedAt),
                $this->privateStorageStatus($capturedAt),
                $this->operationalEvidenceStatus(OperationalEvidenceRecorder::TypeBackup),
                $this->operationalEvidenceStatus(OperationalEvidenceRecorder::TypeRestore),
                $this->externalUnknown('primary-host-backups', 'Primary-host backups'),
                $this->externalUnknown('provider-dashboards', 'Provider dashboards'),
                $this->externalUnknown('independent-provider', 'Independent backup provider'),
                $this->externalUnknown('physical-custody', 'Physical recovery custody'),
            ])->keyBy('key')->all(),
        ];
    }

    /** @return array{label:string,color:string,description:string} */
    public function summary(): array
    {
        try {
            $rows = collect($this->capture()['rows']);
        } catch (Throwable) {
            return [
                'label' => 'Evidence unavailable',
                'color' => 'danger',
                'description' => 'Open System Health to retry the bounded local evidence capture.',
            ];
        }

        $attentionCount = $rows->whereIn('status', [self::Attention, self::Unavailable])->count();
        $unknownCount = $rows->where('status', self::Unknown)->count();

        if ($attentionCount > 0) {
            return [
                'label' => "{$attentionCount} need attention",
                'color' => 'warning',
                'description' => 'Review current local evidence and its safe recovery action.',
            ];
        }

        return [
            'label' => $unknownCount > 0 ? "{$unknownCount} unknown" : 'Local evidence available',
            'color' => $unknownCount > 0 ? 'gray' : 'success',
            'description' => $unknownCount > 0
                ? 'External or missing evidence remains explicitly unknown.'
                : 'All represented local checks currently have accepted evidence.',
        ];
    }

    /** @return array{key:string,service:string,status:string,evidence:string,as_of:string,next_action:string} */
    private function mailStatus(): array
    {
        $mailer = (string) config('mail.default');
        $valid = match ($mailer) {
            'smtp' => filled(config('mail.mailers.smtp.host')) && filled(config('mail.from.address')),
            'array', 'log' => true,
            default => filled(config("mail.mailers.{$mailer}")),
        };
        $latest = OperationalEvent::query()
            ->where('event_domain', OperationalEvent::DomainNotifications)
            ->where('integration', OperationalEvent::IntegrationMail)
            ->latest('occurred_at')
            ->latest('id')
            ->first();

        if (! $valid) {
            return $this->row('mail', 'Mail transport (SMTP)', self::Unavailable, 'Required local mail configuration is incomplete.', null, 'Correct the selected mail transport configuration.');
        }

        if ($latest instanceof OperationalEvent && in_array($latest->status, [
            OperationalEvent::StatusPending,
            OperationalEvent::StatusFailed,
            OperationalEvent::StatusReviewRequired,
        ], true)) {
            return $this->row('mail', 'Mail transport (SMTP)', self::Attention, 'The latest local mail attempt is pending, failed, or requires review; unsafe provider detail is withheld.', $latest->occurred_at, 'Review queued mail or correct the transport, then send one bounded self-test.');
        }

        if ($latest?->status === OperationalEvent::StatusProcessed) {
            return $this->row('mail', 'Mail transport (SMTP)', self::Available, 'The latest accepted local mail event succeeded.', $latest->occurred_at, 'No action is required; recheck after configuration changes.');
        }

        return $this->row('mail', 'Mail transport (SMTP)', self::Unknown, 'Local configuration exists, but no accepted successful mail event is recorded.', $latest?->occurred_at, 'Send one bounded self-test to the signed-in administrator.');
    }

    /** @return array{key:string,service:string,status:string,evidence:string,as_of:string,next_action:string} */
    private function payMongoStatus(): array
    {
        $driver = (string) config('tala_integrations.payments.driver');
        $configured = $driver === 'paymongo'
            && filled(config('tala_integrations.payments.paymongo.public_key'))
            && filled(config('tala_integrations.payments.paymongo.secret_key'))
            && filled(config('tala_integrations.payments.paymongo.webhook_signature'))
            && Route::has('webhooks.paymongo');
        $latest = OperationalEvent::query()
            ->where('event_domain', OperationalEvent::DomainIntegration)
            ->where('integration', OperationalEvent::IntegrationPayMongo)
            ->latest('occurred_at')
            ->latest('id')
            ->first();
        $activeAttempts = PaymentAttempt::query()->whereIn('status', PaymentAttempt::ActiveStatuses)->count();

        if ($driver !== 'paymongo') {
            return $this->row('paymongo', 'PayMongo', self::Unknown, 'PayMongo is not the selected local payment driver.', null, 'Select and configure PayMongo only during an authorized provider activation.');
        }

        if (! $configured) {
            return $this->row('paymongo', 'PayMongo', self::Unavailable, 'Required local PayMongo configuration or webhook route is incomplete.', null, 'Correct local test-mode configuration before provider acceptance.');
        }

        if ($activeAttempts > 0 || in_array($latest?->status, [OperationalEvent::StatusFailed, OperationalEvent::StatusReviewRequired], true)) {
            return $this->row('paymongo', 'PayMongo', self::Attention, "{$activeAttempts} payment attempt(s) require posting or recovery review.", $latest?->occurred_at, 'Open Student Accounts and resolve the recorded payment exception.');
        }

        if ($latest instanceof OperationalEvent && $latest->status === OperationalEvent::StatusProcessed) {
            return $this->row('paymongo', 'PayMongo', self::Available, 'A signed local webhook event was accepted and no active exception remains.', $latest->occurred_at, 'No action is required; provider-dashboard state remains separately unknown.');
        }

        return $this->row('paymongo', 'PayMongo', self::Unknown, 'Local configuration exists, but no accepted signed webhook evidence is recorded.', null, 'Run test-mode provider acceptance only under separate authorization.');
    }

    /** @return array{key:string,service:string,status:string,evidence:string,as_of:string,next_action:string} */
    private function solverStatus(): array
    {
        $driver = (string) config('tala_integrations.scheduling_solver.driver');
        $url = config('tala_integrations.scheduling_solver.url');
        $configured = match ($driver) {
            'local_stub' => true,
            'local_http' => LocalHttpSchedulingSolverClient::supportsEnvironment(app()->environment())
                && LocalHttpSchedulingSolverClient::supportsBaseUrl(is_string($url) ? $url : null),
            'cloud_run' => is_string($url) && str_starts_with($url, 'https://')
                && filled(config('tala_integrations.scheduling_solver.audience'))
                && filled(config('tala_integrations.scheduling_solver.credentials_path')),
            default => false,
        };
        $latest = ScheduleGenerationRun::query()->latest('updated_at')->latest('id')->first();

        if (! $configured) {
            return $this->row('solver', 'Scheduling solver', self::Unavailable, 'The selected solver transport is not locally valid.', null, 'Correct the solver transport configuration before generation.');
        }

        if (in_array($latest?->status, [ScheduleGenerationRun::StatusFailed, ScheduleGenerationRun::StatusBlocked], true)) {
            return $this->row('solver', 'Scheduling solver', self::Attention, 'The latest schedule run failed or requires Registrar review.', $latest->updated_at, 'Open Term Planning and follow the recorded recovery path.');
        }

        if ($latest instanceof ScheduleGenerationRun) {
            return $this->row('solver', 'Scheduling solver', self::Available, 'The latest Laravel-accepted solver result is recorded.', $latest->updated_at, 'No action is required; Cloud provider status remains separately unknown.');
        }

        return $this->row('solver', 'Scheduling solver', self::Unknown, 'Local solver configuration exists, but no accepted run is recorded.', null, 'Generate a timetable only through the authorized Registrar journey.');
    }

    /** @return array{key:string,service:string,status:string,evidence:string,as_of:string,next_action:string} */
    private function queueStatus(): array
    {
        $driver = (string) config('queue.default');

        if ($driver === 'sync') {
            return $this->row('queue', 'Queue', self::Available, 'Synchronous local execution is configured; no worker-liveness claim is made.', now(), 'No action is required for synchronous execution.');
        }

        if (! in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            return $this->row('queue', 'Queue', self::Unavailable, 'The selected queue driver is not recognized by this health projection.', null, 'Correct the local queue configuration.');
        }

        $pending = DB::table('jobs')->count();
        $failed = DB::table('failed_jobs')->count();

        if ($failed > 0 || $pending > 0) {
            return $this->row('queue', 'Queue', self::Attention, "{$pending} pending and {$failed} failed queued job(s) are recorded.", now(), 'Review failed jobs and worker operation outside this read-only page.');
        }

        return $this->row('queue', 'Queue', self::Unknown, 'No pending or failed job is recorded; zero counts do not prove worker liveness.', now(), 'Confirm worker supervision through authorized deployment evidence.');
    }

    /** @return array{key:string,service:string,status:string,evidence:string,as_of:string,next_action:string} */
    private function applicationStatus(CarbonInterface $capturedAt): array
    {
        return $this->row('application', 'Application', self::Available, 'The current Laravel application process completed this bounded health capture.', $capturedAt, 'No action is required.');
    }

    /** @return array{key:string,service:string,status:string,evidence:string,as_of:string,next_action:string} */
    private function databaseStatus(CarbonInterface $capturedAt): array
    {
        try {
            DB::selectOne('SELECT 1 AS health_check');

            return $this->row('database', 'Database', self::Available, 'A bounded read-only query succeeded.', $capturedAt, 'No action is required.');
        } catch (Throwable) {
            return $this->row('database', 'Database', self::Unavailable, 'The bounded read-only query could not run.', $capturedAt, 'Restore local database connectivity outside this page.');
        }
    }

    /** @return array{key:string,service:string,status:string,evidence:string,as_of:string,next_action:string} */
    private function privateStorageStatus(CarbonInterface $capturedAt): array
    {
        try {
            $root = Storage::disk('local')->path('');
            $available = is_dir($root) && is_readable($root) && is_writable($root);
        } catch (Throwable) {
            $available = false;
        }

        return $available
            ? $this->row('private-storage', 'Private storage', self::Available, 'The configured private-storage root is locally readable and writable.', $capturedAt, 'No action is required.')
            : $this->row('private-storage', 'Private storage', self::Unavailable, 'The configured private-storage root is not locally usable.', $capturedAt, 'Correct local private-storage access before file workflows continue.');
    }

    /** @return array{key:string,service:string,status:string,evidence:string,as_of:string,next_action:string} */
    private function operationalEvidenceStatus(string $type): array
    {
        $integration = $type === OperationalEvidenceRecorder::TypeBackup
            ? OperationalEvent::IntegrationBackup
            : OperationalEvent::IntegrationRestore;
        $key = $type;
        $service = $type === OperationalEvidenceRecorder::TypeBackup ? 'Backup evidence' : 'Restore evidence';
        $current = $this->latestUnsupersededEvidence($integration)->first();
        $latestSuccess = $this->latestUnsupersededEvidence($integration)
            ->where('status', OperationalEvent::StatusProcessed)
            ->first();

        if (! $current instanceof OperationalEvent) {
            return $this->row($key, $service, self::Unknown, 'No validated local evidence has been ingested.', null, "Run an approved external {$type} process, then ingest its safe evidence.");
        }

        $overdueValue = $type === OperationalEvidenceRecorder::TypeBackup
            ? config('tala_operations.backup_overdue_after_hours')
            : config('tala_operations.restore_overdue_after_days');
        $isOverdue = is_numeric($overdueValue) && (float) $overdueValue > 0
            && $current->occurred_at->lt($type === OperationalEvidenceRecorder::TypeBackup
                ? now()->subHours((int) $overdueValue)
                : now()->subDays((int) $overdueValue));

        if ($current->status !== OperationalEvent::StatusProcessed || $isOverdue) {
            $previous = $latestSuccess instanceof OperationalEvent
                ? ' The preceding successful generation remains recorded.'
                : '';

            return $this->row($key, $service, self::Attention, $isOverdue
                ? 'The latest validated evidence is older than the configured local threshold.'.$previous
                : 'The latest validated evidence failed or requires reconciliation.'.$previous, $current->occurred_at, "Review the approved external {$type} process and append corrected evidence.");
        }

        return $this->row($key, $service, self::Available, 'The latest validated local evidence succeeded; this is not production certification.', $current->occurred_at, 'No local action is required; continue scheduled external verification.');
    }

    /** @return Builder<OperationalEvent> */
    private function latestUnsupersededEvidence(string $integration): Builder
    {
        return OperationalEvent::query()
            ->where('event_domain', OperationalEvent::DomainOperations)
            ->where('integration', $integration)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('operational_events as corrections')
                    ->whereColumn('corrections.related_record_id', 'operational_events.id')
                    ->where('corrections.related_record_type', OperationalEvent::class);
            })
            ->latest('occurred_at')
            ->latest('id');
    }

    /** @return array{key:string,service:string,status:string,evidence:string,as_of:string,next_action:string} */
    private function externalUnknown(string $key, string $service): array
    {
        return $this->row($key, $service, self::Unknown, 'Not checked by TALA.', null, 'Verify through the accountable external owner or provider under separate authorization.');
    }

    /** @return array{key:string,service:string,status:string,evidence:string,as_of:string,next_action:string} */
    private function row(string $key, string $service, string $status, string $evidence, ?CarbonInterface $asOf, string $nextAction): array
    {
        return [
            'key' => $key,
            'service' => $service,
            'status' => $status,
            'evidence' => $evidence,
            'as_of' => $asOf?->toIso8601String() ?? 'No accepted evidence',
            'next_action' => $nextAction,
        ];
    }
}
