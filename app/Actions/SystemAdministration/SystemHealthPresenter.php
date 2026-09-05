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

    public const NeedsAttention = 'Needs attention';

    public const Unavailable = 'Unavailable';

    public const NotRecentlyChecked = 'Not recently checked';

    public const Attention = self::NeedsAttention;

    public const Unknown = self::NotRecentlyChecked;

    /** @return array{captured_at: string, rows: array<string, array{key:string,service:string,status:string,evidence:string,evidence_source:string,as_of:string,responsible_owner:string,next_action:string,technical_details:string}>} */
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

        $attentionCount = $rows->whereIn('status', [self::NeedsAttention, self::Unavailable])->count();
        $notCheckedCount = $rows->where('status', self::NotRecentlyChecked)->count();

        if ($attentionCount > 0) {
            return [
                'label' => "{$attentionCount} need attention",
                'color' => 'warning',
                'description' => 'Review current local evidence and its safe recovery action.',
            ];
        }

        return [
            'label' => $notCheckedCount > 0 ? "{$notCheckedCount} not recently checked" : 'Local evidence available',
            'color' => $notCheckedCount > 0 ? 'gray' : 'success',
            'description' => $notCheckedCount > 0
                ? 'External or unselected provider facts remain explicitly not recently checked.'
                : 'All represented local checks currently have accepted evidence.',
        ];
    }

    /** @return array{key:string,service:string,status:string,evidence:string,evidence_source:string,as_of:string,responsible_owner:string,next_action:string,technical_details:string} */
    private function mailStatus(): array
    {
        $mailer = (string) config('mail.default');
        $service = match ($mailer) {
            'smtp' => 'Mail transport (SMTP)',
            'array', 'log' => "Mail transport ({$mailer} local transport)",
            default => "Mail transport ({$mailer})",
        };

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

        $evidenceSource = 'config(mail) and operational_events';
        $responsibleOwner = 'System Administrator';
        $technicalDetails = "Mailer: {$mailer}, Host: ".($mailer === 'smtp' ? (string) config('mail.mailers.smtp.host') : 'local');

        if (! $valid) {
            return $this->row('mail', $service, self::Unavailable, 'Required local mail configuration is incomplete.', $evidenceSource, null, $responsibleOwner, 'Correct the selected mail transport configuration.', $technicalDetails);
        }

        if ($latest instanceof OperationalEvent && in_array($latest->status, [
            OperationalEvent::StatusPending,
            OperationalEvent::StatusFailed,
            OperationalEvent::StatusReviewRequired,
        ], true)) {
            return $this->row('mail', $service, self::NeedsAttention, 'The latest local mail attempt is pending, failed, or requires review; unsafe provider detail is withheld.', $evidenceSource, $latest->occurred_at, $responsibleOwner, 'Review queued mail or correct the transport, then send one bounded self-test.', $technicalDetails);
        }

        if ($latest?->status === OperationalEvent::StatusProcessed) {
            return $this->row('mail', $service, self::Available, 'The latest accepted local mail event succeeded.', $evidenceSource, $latest->occurred_at, $responsibleOwner, 'No action is required; recheck after configuration changes.', $technicalDetails);
        }

        return $this->row('mail', $service, self::NotRecentlyChecked, 'Local configuration exists, but no accepted successful mail event is recorded.', $evidenceSource, $latest?->occurred_at, $responsibleOwner, 'Send one bounded self-test to the signed-in administrator.', $technicalDetails);
    }

    /** @return array{key:string,service:string,status:string,evidence:string,evidence_source:string,as_of:string,responsible_owner:string,next_action:string,technical_details:string} */
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
            return $this->row('paymongo', 'PayMongo', self::NotRecentlyChecked, 'PayMongo is not the selected local payment driver.', 'config(tala_integrations.payments.driver)', null, 'Accounting / System Administrator', 'Select and configure PayMongo only during an authorized provider activation.', "Driver: {$driver}");
        }

        $evidenceSource = 'operational_events and payment_attempts';
        $responsibleOwner = 'Accounting';
        $technicalDetails = 'Driver: paymongo, Webhook: '.(Route::has('webhooks.paymongo') ? 'registered' : 'missing');

        if (! $configured) {
            return $this->row('paymongo', 'PayMongo', self::Unavailable, 'Required local PayMongo configuration or webhook route is incomplete.', $evidenceSource, null, $responsibleOwner, 'Correct local test-mode configuration before provider acceptance.', $technicalDetails);
        }

        if ($activeAttempts > 0 || in_array($latest?->status, [OperationalEvent::StatusFailed, OperationalEvent::StatusReviewRequired], true)) {
            return $this->row('paymongo', 'PayMongo', self::NeedsAttention, "{$activeAttempts} payment attempt(s) require posting or recovery review.", $evidenceSource, $latest?->occurred_at, $responsibleOwner, 'Open Student Accounts and resolve the recorded payment exception.', $technicalDetails);
        }

        if ($latest instanceof OperationalEvent && $latest->status === OperationalEvent::StatusProcessed) {
            return $this->row('paymongo', 'PayMongo', self::Available, 'A signed local webhook event was accepted and no active exception remains.', $evidenceSource, $latest->occurred_at, $responsibleOwner, 'No action is required; provider-dashboard state remains separately unknown.', $technicalDetails);
        }

        return $this->row('paymongo', 'PayMongo', self::NotRecentlyChecked, 'Local configuration exists, but no accepted signed webhook evidence is recorded.', $evidenceSource, null, $responsibleOwner, 'Run test-mode provider acceptance only under separate authorization.', $technicalDetails);
    }

    /** @return array{key:string,service:string,status:string,evidence:string,evidence_source:string,as_of:string,responsible_owner:string,next_action:string,technical_details:string} */
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

        $evidenceSource = 'schedule_generation_runs and config(scheduling_solver)';
        $responsibleOwner = 'Registrar / Academic Head';
        $technicalDetails = "Driver: {$driver}, URL: ".(is_string($url) ? $url : 'none');

        if (! $configured) {
            return $this->row('solver', 'Scheduling solver', self::Unavailable, 'The selected solver transport is not locally valid.', $evidenceSource, null, $responsibleOwner, 'Correct the solver transport configuration before generation.', $technicalDetails);
        }

        if (in_array($latest?->status, [ScheduleGenerationRun::StatusFailed, ScheduleGenerationRun::StatusBlocked], true)) {
            return $this->row('solver', 'Scheduling solver', self::NeedsAttention, 'The latest schedule run failed or requires Registrar review.', $evidenceSource, $latest->updated_at, $responsibleOwner, 'Open Term Planning and follow the recorded recovery path.', $technicalDetails);
        }

        if (in_array($latest?->status, [ScheduleGenerationRun::StatusQueued, ScheduleGenerationRun::StatusDispatching], true)) {
            return $this->row('solver', 'Scheduling solver', self::NeedsAttention, 'A schedule generation run is queued or dispatching; solver output is not yet accepted.', $evidenceSource, $latest->updated_at, $responsibleOwner, 'Monitor the schedule generation run in Term Planning until completion.', $technicalDetails);
        }

        if (in_array($latest?->status, [ScheduleGenerationRun::StatusUnderReview, ScheduleGenerationRun::StatusPublished, ScheduleGenerationRun::StatusSuperseded], true)) {
            return $this->row('solver', 'Scheduling solver', self::Available, 'The latest Laravel-accepted solver result is recorded.', $evidenceSource, $latest->updated_at, $responsibleOwner, 'No action is required; Cloud provider status remains separately unknown.', $technicalDetails);
        }

        return $this->row('solver', 'Scheduling solver', self::NotRecentlyChecked, 'Local solver configuration exists, but no accepted run is recorded.', $evidenceSource, null, $responsibleOwner, 'Generate a timetable only through the authorized Registrar journey.', $technicalDetails);
    }

    /** @return array{key:string,service:string,status:string,evidence:string,evidence_source:string,as_of:string,responsible_owner:string,next_action:string,technical_details:string} */
    private function queueStatus(): array
    {
        $driver = (string) config('queue.default');
        $evidenceSource = 'jobs and failed_jobs tables';
        $responsibleOwner = 'System Administrator';

        if ($driver === 'sync') {
            return $this->row('queue', 'Queue', self::Available, 'Synchronous local execution is configured; no worker-liveness claim is made.', $evidenceSource, now(), $responsibleOwner, 'No action is required for synchronous execution.', 'Driver: sync');
        }

        if (! in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            return $this->row('queue', 'Queue', self::Unavailable, 'The selected queue driver is not recognized by this health projection.', $evidenceSource, null, $responsibleOwner, 'Correct the local queue configuration.', "Driver: {$driver}");
        }

        $pending = DB::table('jobs')->count();
        $failed = DB::table('failed_jobs')->count();
        $technicalDetails = "Driver: {$driver}, Pending: {$pending}, Failed: {$failed}";

        if ($failed > 0 || $pending > 0) {
            return $this->row('queue', 'Queue', self::NeedsAttention, "{$pending} pending and {$failed} failed queued job(s) are recorded.", $evidenceSource, now(), $responsibleOwner, 'Review failed jobs and worker operation outside this read-only page.', $technicalDetails);
        }

        return $this->row('queue', 'Queue', self::NotRecentlyChecked, 'No pending or failed job is recorded; zero counts do not prove worker liveness.', $evidenceSource, now(), $responsibleOwner, 'Confirm worker supervision through authorized deployment evidence.', $technicalDetails);
    }

    /** @return array{key:string,service:string,status:string,evidence:string,evidence_source:string,as_of:string,responsible_owner:string,next_action:string,technical_details:string} */
    private function applicationStatus(CarbonInterface $capturedAt): array
    {
        $details = 'PHP: '.PHP_VERSION.', Laravel: '.app()->version().', Environment: '.app()->environment();

        return $this->row('application', 'Application', self::Available, 'The current Laravel application process completed this bounded health capture.', 'Laravel application runtime', $capturedAt, 'System Administrator', 'No action is required.', $details);
    }

    /** @return array{key:string,service:string,status:string,evidence:string,evidence_source:string,as_of:string,responsible_owner:string,next_action:string,technical_details:string} */
    private function databaseStatus(CarbonInterface $capturedAt): array
    {
        $connection = DB::connection();
        $details = 'Driver: '.$connection->getDriverName().', Database: '.$connection->getDatabaseName();

        try {
            DB::selectOne('SELECT 1 AS health_check');

            return $this->row('database', 'Database', self::Available, 'A bounded read-only query succeeded.', 'PDO connection check', $capturedAt, 'System Administrator', 'No action is required.', $details);
        } catch (Throwable) {
            return $this->row('database', 'Database', self::Unavailable, 'The bounded read-only query could not run.', 'PDO connection check', $capturedAt, 'System Administrator', 'Restore local database connectivity outside this page.', $details);
        }
    }

    /** @return array{key:string,service:string,status:string,evidence:string,evidence_source:string,as_of:string,responsible_owner:string,next_action:string,technical_details:string} */
    private function privateStorageStatus(CarbonInterface $capturedAt): array
    {
        try {
            $root = Storage::disk('local')->path('');
            $available = is_dir($root) && is_readable($root) && is_writable($root);
        } catch (Throwable) {
            $root = 'unknown';
            $available = false;
        }

        $details = "Root path: {$root}";

        return $available
            ? $this->row('private-storage', 'Private storage', self::Available, 'The configured private-storage root is locally readable and writable.', 'Storage::disk(local) permissions', $capturedAt, 'System Administrator', 'No action is required.', $details)
            : $this->row('private-storage', 'Private storage', self::Unavailable, 'The configured private-storage root is not locally usable.', 'Storage::disk(local) permissions', $capturedAt, 'System Administrator', 'Correct local private-storage access before file workflows continue.', $details);
    }

    /** @return array{key:string,service:string,status:string,evidence:string,evidence_source:string,as_of:string,responsible_owner:string,next_action:string,technical_details:string} */
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

        $evidenceSource = 'operational_events table';
        $responsibleOwner = 'System Administrator';

        if (! $current instanceof OperationalEvent) {
            return $this->row($key, $service, self::NotRecentlyChecked, 'No validated local evidence has been ingested.', $evidenceSource, null, $responsibleOwner, "Run an approved external {$type} process, then ingest its safe evidence.", "Type: {$type}, Status: none");
        }

        $technicalDetails = "Type: {$type}, Status: {$current->status}";

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

            return $this->row($key, $service, self::NeedsAttention, $isOverdue
                ? 'The latest validated evidence is older than the configured local threshold.'.$previous
                : 'The latest validated evidence failed or requires reconciliation.'.$previous, $evidenceSource, $current->occurred_at, $responsibleOwner, "Review the approved external {$type} process and append corrected evidence.", $technicalDetails);
        }

        return $this->row($key, $service, self::Available, 'The latest validated local evidence succeeded; this is not production certification.', $evidenceSource, $current->occurred_at, $responsibleOwner, 'No local action is required; continue scheduled external verification.', $technicalDetails);
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

    /** @return array{key:string,service:string,status:string,evidence:string,evidence_source:string,as_of:string,responsible_owner:string,next_action:string,technical_details:string} */
    private function externalUnknown(string $key, string $service): array
    {
        return $this->row($key, $service, self::NotRecentlyChecked, 'Not checked by TALA.', 'External provider / physical custody', null, 'External Custodian / Cloud Provider', 'Verify through the accountable external owner or provider under separate authorization.', 'External fact; not verifiable via local PHP process.');
    }

    /** @return array{key:string,service:string,status:string,evidence:string,evidence_source:string,as_of:string,responsible_owner:string,next_action:string,technical_details:string} */
    private function row(string $key, string $service, string $status, string $evidence, string $evidenceSource, ?CarbonInterface $asOf, string $responsibleOwner, string $nextAction, string $technicalDetails = ''): array
    {
        return [
            'key' => $key,
            'service' => $service,
            'status' => $status,
            'evidence' => $evidence,
            'evidence_source' => $evidenceSource,
            'as_of' => $asOf?->toIso8601String() ?? 'No accepted evidence',
            'responsible_owner' => $responsibleOwner,
            'next_action' => $nextAction,
            'technical_details' => $technicalDetails,
        ];
    }
}
