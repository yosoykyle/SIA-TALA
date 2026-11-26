<?php

namespace App\Actions\SystemAdministration;

use App\Actions\Integrations\SchedulingSolver\LocalHttpSchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverTransportException;
use App\Models\OperationalEvent;
use App\Models\ScheduleGenerationRun;
use App\Support\DisplayDateTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class SystemHealthPresenter
{
    public const Available = 'Available';

    public const NeedsAttention = 'Needs attention';

    public const Unavailable = 'Unavailable';

    public const NotRecentlyChecked = 'Not recently checked';

    public const Attention = self::NeedsAttention;

    public const Unknown = self::NotRecentlyChecked;

    /**
     * @return array{
     *     captured_at: string,
     *     captured_at_display: string,
     *     rows: array<string, array<string, mixed>>,
     *     local_rows: array<string, array<string, mixed>>,
     *     external_rows: array<string, array<string, mixed>>
     * }
     */
    public function capture(): array
    {
        $capturedAt = now();

        $localRows = collect([
            $this->appDatabaseStatus($capturedAt),
            $this->queueStatus($capturedAt),
            $this->mailStatus(),
            $this->solverStatus(),
        ])->keyBy('key')->all();

        return [
            'captured_at' => $capturedAt->toIso8601String(),
            'captured_at_display' => DisplayDateTime::format($capturedAt, 'M j, Y g:i A', 'Not recorded yet'),
            'rows' => $localRows,
            'local_rows' => $localRows,
            'external_rows' => [],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $capture
     * @return array{
     *     label: string,
     *     headline: string,
     *     breakdown: string,
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
    public function summary(?array $capture = null): array
    {
        if ($capture === null) {
            try {
                $capture = $this->capture();
            } catch (Throwable) {
                return $this->unavailableSummary();
            }
        }

        $localRows = array_key_exists('local_rows', $capture)
            ? collect($capture['local_rows'])
            : collect($capture['rows'] ?? []);

        if ($localRows->isEmpty()) {
            return $this->unavailableSummary();
        }

        $availableCount = $localRows->where('status', self::Available)->count();
        $attentionCount = $localRows->where('status', self::NeedsAttention)->count();
        $unavailableCount = $localRows->where('status', self::Unavailable)->count();
        $notCheckedCount = $localRows->where('status', self::NotRecentlyChecked)->count();
        $issueCount = $attentionCount + $unavailableCount;
        $total = $localRows->count();

        $capturedAtDisplay = is_string($capture['captured_at_display'] ?? null) && $capture['captured_at_display'] !== ''
            ? $capture['captured_at_display']
            : $this->formatCapturedAtFallback($capture['captured_at'] ?? null);

        $breakdownParts = [];

        if ($availableCount > 0) {
            $breakdownParts[] = "{$availableCount} operational";
        }

        if ($unavailableCount > 0) {
            $breakdownParts[] = "{$unavailableCount} unreachable";
        }

        if ($attentionCount > 0) {
            $breakdownParts[] = $attentionCount === 1 ? '1 needs attention' : "{$attentionCount} need attention";
        }

        if ($notCheckedCount > 0) {
            $breakdownParts[] = "{$notCheckedCount} not checked yet";
        }

        $breakdown = $breakdownParts !== [] ? implode(' · ', $breakdownParts) : 'No services reported';

        if ($issueCount > 0) {
            $label = "{$issueCount} of {$total} need attention";
            $headline = $issueCount === 1
                ? "1 of {$total} services needs attention"
                : "{$issueCount} of {$total} services need attention";
            $color = $unavailableCount > 0 ? 'danger' : 'warning';
            $description = 'Review the flagged service card and run its safe recovery action.';
        } elseif ($notCheckedCount > 0) {
            $label = "{$availableCount} of {$total} operational";
            $headline = "{$availableCount} of {$total} services operational";
            $color = 'gray';
            $description = 'Run the inline check actions on unprobed cards to verify live reachability.';
        } else {
            $label = 'All live checks available';
            $headline = "All {$total} services operational";
            $color = 'success';
            $description = 'Application runtime, queue, mail transport, and scheduling solver report healthy local status.';
        }

        return [
            'label' => $label,
            'headline' => $headline,
            'breakdown' => $breakdown,
            'color' => $color,
            'description' => $description,
            'available_count' => $availableCount,
            'attention_count' => $attentionCount,
            'unavailable_count' => $unavailableCount,
            'not_checked_count' => $notCheckedCount,
            'local_total' => $total,
            'external_count' => 0,
            'captured_at_display' => $capturedAtDisplay,
        ];
    }

    /**
     * Read failed_jobs rows safely without exposing serialized payloads or PII.
     *
     * @return list<array{id: int, uuid: string, job_name: string, queue: string, failed_at_display: string, exception_summary: string}>
     */
    public function failedJobs(int $limit = 25): array
    {
        try {
            $rows = DB::table('failed_jobs')
                ->select(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at'])
                ->orderByDesc('id')
                ->limit($limit)
                ->get();
        } catch (Throwable) {
            return [];
        }

        return $rows->map(function (object $row): array {
            $jobName = $this->extractSafeJobName(is_string($row->payload ?? null) ? $row->payload : '');
            $failedAtDisplay = $this->formatCapturedAtFallback($row->failed_at ?? null);

            return [
                'id' => (int) ($row->id ?? 0),
                'uuid' => (string) ($row->uuid ?? ''),
                'job_name' => $jobName,
                'queue' => filled($row->queue ?? null) ? (string) $row->queue : 'default',
                'failed_at_display' => $failedAtDisplay,
                'exception_summary' => self::sanitizeExceptionSummary((string) ($row->exception ?? '')),
            ];
        })->values()->all();
    }

    public static function sanitizeExceptionSummary(string $exception): string
    {
        $firstLine = trim((string) strtok($exception, "\r\n"));

        if ($firstLine === '') {
            return 'No exception summary recorded.';
        }

        // Strip trailing file paths from PHP exception first lines (e.g. " in /var/www/...:123").
        $withoutPath = preg_replace('/\s+in\s+(?:[A-Za-z]:\\\\|\/)\S+(?::\d+)?\s*$/', '', $firstLine);
        $clean = is_string($withoutPath) ? $withoutPath : $firstLine;

        // Redact any email addresses or long opaque tokens.
        $clean = (string) preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted]', $clean);
        $clean = (string) preg_replace('/(?:password|secret|token|key)=[^\s,;]+/i', '[redacted]', $clean);
        $clean = trim($clean);

        if ($clean === '') {
            return 'Error details redacted.';
        }

        return Str::limit($clean, 160);
    }

    /**
     * Read recent timetable solver runs safely without exposing input snapshots or PII.
     *
     * @return list<array{
     *     id: int,
     *     term: string,
     *     requested_by: string,
     *     requested_at_display: string,
     *     status_label: string,
     *     status_color: string,
     *     outcome: string,
     *     candidate_rows_count: int,
     *     runtime_display: string,
     *     attempts: int,
     *     solver_version: string,
     *     failure_classification: string
     * }>
     */
    public function recentSolverRuns(int $limit = 10): array
    {
        try {
            $runIds = ScheduleGenerationRun::query()
                ->latest('created_at')
                ->latest('id')
                ->limit($limit)
                ->pluck('id');

            $runs = $runIds->isEmpty()
                ? collect()
                : ScheduleGenerationRun::query()
                    ->whereKey($runIds)
                    ->with(['term', 'requester'])
                    ->withCount(['candidateRows', 'solverAttemptEvents'])
                    ->get()
                    ->sortByDesc(fn (ScheduleGenerationRun $run): array => [$run->created_at?->getTimestamp() ?? 0, (int) $run->id])
                    ->values();
        } catch (Throwable) {
            return [];
        }

        return $runs->map(function (ScheduleGenerationRun $run): array {
            $evidence = $run->reviewEvidence();
            $failure = $run->finalSolverFailure();
            $classification = is_string($failure['classification'] ?? null) && $failure['classification'] !== ''
                ? Str::headline((string) $failure['classification'])
                : null;
            $statusCode = is_numeric($failure['status_code'] ?? null) ? (int) $failure['status_code'] : null;

            $failureSummary = match (true) {
                $classification !== null && $statusCode !== null => "{$classification} (HTTP {$statusCode})",
                $classification !== null => $classification,
                $run->status === ScheduleGenerationRun::StatusFailed => 'Technical failure',
                $run->status === ScheduleGenerationRun::StatusBlocked => 'Blocked',
                default => 'None',
            };

            $runtimeDisplay = is_numeric($run->runtime_ms)
                ? ((int) $run->runtime_ms >= 1000
                    ? number_format(((int) $run->runtime_ms) / 1000, 2).' s'
                    : ((int) $run->runtime_ms).' ms')
                : 'Not recorded';

            return [
                'id' => (int) $run->id,
                'term' => filled($run->term?->label) ? (string) $run->term->label : 'Term #'.$run->term_id,
                'requested_by' => filled($run->requester?->name) ? (string) $run->requester->name : 'System',
                'requested_at_display' => DisplayDateTime::format($run->created_at, 'M j, Y g:i A', 'Not recorded'),
                'status_label' => ScheduleGenerationRun::statusOptions()[$run->status] ?? Str::headline((string) $run->status),
                'status_color' => ScheduleGenerationRun::statusColors()[$run->status] ?? 'gray',
                'outcome' => (string) ($evidence['outcome'] ?? 'Not recorded'),
                'candidate_rows_count' => (int) ($run->candidate_rows_count ?? 0),
                'runtime_display' => $runtimeDisplay,
                'attempts' => (int) ($run->solver_attempt_events_count ?? 0),
                'solver_version' => filled($run->solver_version) ? (string) $run->solver_version : 'Not recorded',
                'failure_classification' => $failureSummary,
            ];
        })->values()->all();
    }

    public static function classifyMailException(Throwable $exception): string
    {
        $text = strtolower($exception->getMessage().' '.class_basename($exception));

        return self::mapErrorCategory($text) ?? 'Transport error';
    }

    /**
     * @return array{
     *     label: string,
     *     headline: string,
     *     breakdown: string,
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
    private function unavailableSummary(): array
    {
        return [
            'label' => 'Evidence unavailable',
            'headline' => 'Health evidence unavailable',
            'breakdown' => 'No local checks captured',
            'color' => 'danger',
            'description' => 'Click Refresh status to retry capturing local system health checks.',
            'available_count' => 0,
            'attention_count' => 0,
            'unavailable_count' => 0,
            'not_checked_count' => 0,
            'local_total' => 0,
            'external_count' => 0,
            'captured_at_display' => 'Not recorded yet',
        ];
    }

    private function formatCapturedAtFallback(mixed $capturedAt): string
    {
        if ($capturedAt instanceof CarbonInterface) {
            return DisplayDateTime::format($capturedAt, 'M j, Y g:i A', 'Not recorded yet');
        }

        if (! is_string($capturedAt) || trim($capturedAt) === '') {
            return 'Not recorded yet';
        }

        try {
            return DisplayDateTime::format(CarbonImmutable::parse($capturedAt), 'M j, Y g:i A', 'Not recorded yet');
        } catch (Throwable) {
            return 'Not recorded yet';
        }
    }

    /** @return array<string, mixed> */
    private function appDatabaseStatus(CarbonInterface $capturedAt): array
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $databaseName = (string) $connection->getDatabaseName();

        $dbAvailable = false;
        try {
            DB::selectOne('SELECT 1 AS health_check');
            $dbAvailable = true;
        } catch (Throwable) {
            $dbAvailable = false;
        }

        $storageRoot = 'Not configured';
        $storageAvailable = false;
        try {
            $storageRoot = Storage::disk('local')->path('');
            $storageAvailable = is_dir($storageRoot) && is_readable($storageRoot) && is_writable($storageRoot);
        } catch (Throwable) {
            $storageAvailable = false;
        }

        $allAvailable = $dbAvailable && $storageAvailable;
        $status = $allAvailable ? self::Available : self::Unavailable;
        $displayStatus = $allAvailable ? 'Available' : 'Unavailable';
        $badgeColor = $allAvailable ? 'success' : 'danger';

        $evidence = match (true) {
            $allAvailable => 'Application runtime, database connection, and private local storage are operational.',
            ! $dbAvailable && ! $storageAvailable => 'Database connection and private local storage checks failed.',
            ! $dbAvailable => 'Database read-only check failed.',
            default => 'Private local storage directory is not readable or writable.',
        };

        $nextAction = $allAvailable
            ? 'No action required.'
            : 'Restore database connectivity and local storage permissions on the server.';

        $errorSummary = null;
        $troubleshootingSteps = [];

        if (! $allAvailable) {
            $errorSummary = match (true) {
                ! $dbAvailable && ! $storageAvailable => 'Both the database connection check and private local storage check failed.',
                ! $dbAvailable => 'Could not execute a read-only check against the configured database.',
                default => 'Private local storage is missing or lacks read/write permissions.',
            };

            if (! $dbAvailable) {
                $troubleshootingSteps[] = 'Verify the database server is running and DB_HOST, DB_PORT, DB_DATABASE, and credentials are valid.';
            }

            if (! $storageAvailable) {
                $troubleshootingSteps[] = 'Confirm storage/app/private exists and is readable and writable by the PHP process.';
            }

            $troubleshootingSteps[] = 'Click Refresh status after resolving host connectivity or permissions.';
        }

        $technicalDetails = implode(', ', [
            'PHP: '.PHP_VERSION,
            'Laravel: '.app()->version(),
            'Environment: '.app()->environment(),
            "DB driver: {$driver}",
            "Database: {$databaseName}",
            'Private storage root: '.$storageRoot,
            'Private storage status: '.($storageAvailable ? 'Writable' : 'Unavailable'),
        ]);

        return [
            ...$this->row(
                'app_database',
                'App & Database',
                $status,
                $evidence,
                'PDO connection check & Storage::disk(local)',
                'Runtime, DB & storage',
                $capturedAt,
                'System Administrator',
                $nextAction,
                $technicalDetails,
            ),
            'display_status' => $displayStatus,
            'badge_color' => $badgeColor,
            'db_available' => $dbAvailable,
            'storage_available' => $storageAvailable,
            'error_summary' => $errorSummary,
            'troubleshooting_steps' => $troubleshootingSteps,
            'facts' => [
                ['label' => 'Runtime', 'value' => 'PHP '.PHP_VERSION.' · Laravel '.app()->version()],
                ['label' => 'Environment', 'value' => ucfirst((string) app()->environment())],
                ['label' => 'Database', 'value' => $dbAvailable ? "Available ({$driver})" : 'Unavailable'],
                ['label' => 'Private storage', 'value' => $storageAvailable ? 'Writable' : 'Unavailable'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function queueStatus(CarbonInterface $capturedAt): array
    {
        $driver = (string) config('queue.default');
        $evidenceSource = 'jobs and failed_jobs tables';
        $evidenceSourceLabel = 'Job queue';
        $responsibleOwner = 'System Administrator';

        if ($driver === 'sync') {
            return [
                ...$this->row(
                    'queue',
                    'Job Queue',
                    self::Available,
                    'Synchronous local queue execution is active; background jobs run immediately.',
                    $evidenceSource,
                    $evidenceSourceLabel,
                    $capturedAt,
                    $responsibleOwner,
                    'No action required for synchronous mode.',
                    'Driver: sync, Pending: 0, Failed: 0',
                ),
                'display_status' => 'Available (sync)',
                'badge_color' => 'success',
                'pending_count' => 0,
                'failed_count' => 0,
                'facts' => [
                    ['label' => 'Pending jobs', 'value' => '0'],
                    ['label' => 'Failed jobs', 'value' => '0'],
                    ['label' => 'Queue driver', 'value' => 'sync'],
                    ['label' => 'Queue state', 'value' => 'Synchronous mode'],
                ],
            ];
        }

        if (! in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            return [
                ...$this->row(
                    'queue',
                    'Job Queue',
                    self::Unavailable,
                    'The selected queue driver is not supported by this health check.',
                    $evidenceSource,
                    $evidenceSourceLabel,
                    null,
                    $responsibleOwner,
                    'Correct the local queue configuration.',
                    "Driver: {$driver}",
                ),
                'display_status' => 'Unavailable',
                'badge_color' => 'danger',
                'pending_count' => 0,
                'failed_count' => 0,
                'error_summary' => "Queue driver '{$driver}' is not supported by TALA health checks.",
                'troubleshooting_steps' => [
                    'Set QUEUE_CONNECTION to database, redis, sqs, beanstalkd, or sync.',
                    'Click Refresh status after updating queue configuration.',
                ],
                'facts' => [
                    ['label' => 'Pending jobs', 'value' => 'Not available'],
                    ['label' => 'Failed jobs', 'value' => 'Not available'],
                    ['label' => 'Queue driver', 'value' => $driver],
                    ['label' => 'Queue state', 'value' => 'Unsupported driver'],
                ],
            ];
        }

        try {
            $pending = DB::table('jobs')->count();
            $failed = DB::table('failed_jobs')->count();
        } catch (Throwable) {
            return [
                ...$this->row(
                    'queue',
                    'Job Queue',
                    self::Unavailable,
                    'Unable to read queue counts from the database.',
                    $evidenceSource,
                    $evidenceSourceLabel,
                    null,
                    $responsibleOwner,
                    'Check database connectivity and queue tables.',
                    "Driver: {$driver}",
                ),
                'display_status' => 'Unavailable',
                'badge_color' => 'danger',
                'pending_count' => 0,
                'failed_count' => 0,
                'error_summary' => 'Queue tables (jobs / failed_jobs) could not be queried.',
                'troubleshooting_steps' => [
                    'Verify database connectivity in the App & Database card.',
                    'Confirm queue migrations have been run and click Refresh status.',
                ],
                'facts' => [
                    ['label' => 'Pending jobs', 'value' => 'Unavailable'],
                    ['label' => 'Failed jobs', 'value' => 'Unavailable'],
                    ['label' => 'Queue driver', 'value' => $driver],
                    ['label' => 'Queue state', 'value' => 'Unavailable'],
                ],
            ];
        }

        $technicalDetails = "Driver: {$driver}, Pending: {$pending}, Failed: {$failed}";
        $errorSummary = null;
        $troubleshootingSteps = [];

        if ($failed > 0) {
            $status = self::NeedsAttention;
            $displayStatus = 'Needs attention';
            $badgeColor = 'warning';
            $evidence = "{$failed} failed and {$pending} pending queued job(s) require attention.";
            $nextAction = 'Inspect failed jobs and retry once the underlying cause is resolved.';
            $errorSummary = "{$failed} background job(s) failed and stopped processing.";
            $troubleshootingSteps = [
                'Click Inspect failed jobs to view the job class and sanitized error summary.',
                'Resolve the underlying dependency issue (such as mail or solver connectivity) first.',
                'Click Retry failed jobs to re-queue failed work.',
            ];
        } elseif ($pending > 0) {
            $status = self::Available;
            $displayStatus = 'Queued (waiting for worker)';
            $badgeColor = 'gray';
            $evidence = "{$pending} pending job(s) queued with 0 failed jobs.";
            $nextAction = 'Ensure a queue worker process is running to drain pending jobs.';
        } else {
            $status = self::Available;
            $displayStatus = 'Ready';
            $badgeColor = 'success';
            $evidence = 'No failed or pending jobs are currently recorded.';
            $nextAction = 'No action required.';
        }

        return [
            ...$this->row(
                'queue',
                'Job Queue',
                $status,
                $evidence,
                $evidenceSource,
                $evidenceSourceLabel,
                $capturedAt,
                $responsibleOwner,
                $nextAction,
                $technicalDetails,
            ),
            'display_status' => $displayStatus,
            'badge_color' => $badgeColor,
            'pending_count' => $pending,
            'failed_count' => $failed,
            'error_summary' => $errorSummary,
            'troubleshooting_steps' => $troubleshootingSteps,
            'facts' => [
                ['label' => 'Pending jobs', 'value' => number_format($pending)],
                ['label' => 'Failed jobs', 'value' => number_format($failed)],
                ['label' => 'Queue driver', 'value' => $driver],
                ['label' => 'Queue state', 'value' => $displayStatus],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function mailStatus(): array
    {
        $mailer = (string) config('mail.default');
        $mailerLabel = match ($mailer) {
            'smtp' => 'SMTP',
            'array' => 'Array (in-memory)',
            'log' => 'Log (local file)',
            default => strtoupper($mailer),
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
        $evidenceSourceLabel = 'Mail configuration & logs';
        $responsibleOwner = 'System Administrator';
        $technicalDetails = "Mailer: {$mailer}, Host: ".($mailer === 'smtp' ? (string) config('mail.mailers.smtp.host') : 'local');

        if (! $valid) {
            return [
                ...$this->row('mail', 'Mail / SMTP', self::Unavailable, 'Required local mail transport settings are incomplete.', $evidenceSource, $evidenceSourceLabel, null, $responsibleOwner, 'Complete mail host and sender settings.', $technicalDetails),
                'display_status' => 'Unavailable',
                'badge_color' => 'danger',
                'error_category' => 'Configuration incomplete',
                'error_summary' => 'Required mail host or sender address settings are missing.',
                'troubleshooting_steps' => [
                    'Set MAIL_MAILER, MAIL_HOST, MAIL_PORT, and MAIL_FROM_ADDRESS in the server environment.',
                    'Click Refresh status and Send test email to verify.',
                ],
                'facts' => [
                    ['label' => 'Transport', 'value' => $mailerLabel],
                    ['label' => 'Delivery state', 'value' => 'Not configured'],
                    ['label' => 'Error category', 'value' => 'Configuration incomplete'],
                    ['label' => 'Last mail event', 'value' => 'Not recorded yet'],
                ],
            ];
        }

        if ($latest instanceof OperationalEvent && in_array($latest->status, [
            OperationalEvent::StatusFailed,
            OperationalEvent::StatusReviewRequired,
        ], true)) {
            $category = $this->classifyMailFailure($latest);
            $mailTroubleshooting = $this->mailTroubleshooting($category);

            return [
                ...$this->row('mail', 'Mail / SMTP', self::NeedsAttention, "Latest mail delivery attempt failed ({$category}).", $evidenceSource, $evidenceSourceLabel, $latest->occurred_at, $responsibleOwner, 'Verify mail transport credentials or connectivity, then send a test email.', $technicalDetails),
                'display_status' => 'Delivery failed',
                'badge_color' => 'danger',
                'error_category' => $category,
                'error_summary' => $mailTroubleshooting['error_summary'],
                'troubleshooting_steps' => $mailTroubleshooting['troubleshooting_steps'],
                'facts' => [
                    ['label' => 'Transport', 'value' => $mailerLabel],
                    ['label' => 'Delivery state', 'value' => 'Delivery failed'],
                    ['label' => 'Error category', 'value' => $category],
                    ['label' => 'Last mail event', 'value' => DisplayDateTime::format($latest->occurred_at, 'M j, Y g:i A', 'Not recorded yet')],
                ],
            ];
        }

        if ($latest instanceof OperationalEvent && $latest->status === OperationalEvent::StatusPending) {
            return [
                ...$this->row('mail', 'Mail / SMTP', self::Available, 'The latest outbound mail event is queued and waiting for a worker.', $evidenceSource, $evidenceSourceLabel, $latest->occurred_at, $responsibleOwner, 'Confirm the queue worker is running or send a test email.', $technicalDetails),
                'display_status' => 'Queued (waiting for worker)',
                'badge_color' => 'gray',
                'error_category' => 'None',
                'facts' => [
                    ['label' => 'Transport', 'value' => $mailerLabel],
                    ['label' => 'Delivery state', 'value' => 'Queued (waiting for worker)'],
                    ['label' => 'Error category', 'value' => 'None'],
                    ['label' => 'Last mail event', 'value' => DisplayDateTime::format($latest->occurred_at, 'M j, Y g:i A', 'Not recorded yet')],
                ],
            ];
        }

        if ($latest?->status === OperationalEvent::StatusProcessed) {
            return [
                ...$this->row('mail', 'Mail / SMTP', self::Available, 'The latest local mail delivery event was accepted by the transport.', $evidenceSource, $evidenceSourceLabel, $latest->occurred_at, $responsibleOwner, 'No action required; send a test email anytime to recheck.', $technicalDetails),
                'display_status' => 'Ready',
                'badge_color' => 'success',
                'error_category' => 'None',
                'facts' => [
                    ['label' => 'Transport', 'value' => $mailerLabel],
                    ['label' => 'Delivery state', 'value' => 'Ready'],
                    ['label' => 'Error category', 'value' => 'None'],
                    ['label' => 'Last mail event', 'value' => DisplayDateTime::format($latest->occurred_at, 'M j, Y g:i A', 'Not recorded yet')],
                ],
            ];
        }

        return [
            ...$this->row('mail', 'Mail / SMTP', self::NotRecentlyChecked, 'Mail transport is configured, but no mail event has been recorded yet.', $evidenceSource, $evidenceSourceLabel, $latest?->occurred_at, $responsibleOwner, 'Click Send test email to verify delivery to your administrator address.', $technicalDetails),
            'display_status' => 'Not recently checked',
            'badge_color' => 'gray',
            'error_category' => 'None',
            'facts' => [
                ['label' => 'Transport', 'value' => $mailerLabel],
                ['label' => 'Delivery state', 'value' => 'Not recently checked'],
                ['label' => 'Error category', 'value' => 'None'],
                ['label' => 'Last mail event', 'value' => 'Not recorded yet'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function solverStatus(): array
    {
        $driver = (string) config('tala_integrations.scheduling_solver.driver');
        $driverLabel = match ($driver) {
            'local_stub' => 'Local stub',
            'local_http' => 'Local HTTP',
            'cloud_run' => 'Cloud Run',
            default => $driver !== '' ? $driver : 'Unconfigured',
        };
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

        $latestRunId = ScheduleGenerationRun::query()
            ->latest('updated_at')
            ->latest('id')
            ->value('id');
        $latestRun = $latestRunId !== null
            ? ScheduleGenerationRun::query()->find($latestRunId)
            : null;
        $latestProbe = OperationalEvent::query()
            ->where('event_domain', OperationalEvent::DomainIntegration)
            ->where('integration', OperationalEvent::IntegrationSchedulingSolver)
            ->where('event_type', 'solver_health_probe')
            ->latest('occurred_at')
            ->latest('id')
            ->first();

        $evidenceSource = 'schedule_generation_runs, operational_events, and config(tala_integrations)';
        $evidenceSourceLabel = 'Scheduling solver';
        $responsibleOwner = 'System Administrator / Registrar';
        $technicalDetails = "Driver: {$driver}, URL: ".(is_string($url) ? $url : 'none');

        $latestRunLabel = match ($latestRun?->status) {
            ScheduleGenerationRun::StatusPublished => 'Published',
            ScheduleGenerationRun::StatusUnderReview => 'Under review',
            ScheduleGenerationRun::StatusSuperseded => 'Superseded',
            ScheduleGenerationRun::StatusQueued => 'Queued',
            ScheduleGenerationRun::StatusDispatching => 'Dispatching',
            ScheduleGenerationRun::StatusFailed => 'Failed',
            ScheduleGenerationRun::StatusBlocked => 'Blocked',
            default => 'None recorded',
        };
        $lastRunFact = $latestRun instanceof ScheduleGenerationRun
            ? $latestRunLabel.' · '.DisplayDateTime::format($latestRun->created_at, 'M j, Y g:i A', 'Not recorded')
            : 'None recorded';

        if (! $configured) {
            $diagnosis = $this->solverTroubleshooting(
                SchedulingSolverTransportException::ClassificationConfiguration,
                null,
            );

            return [
                ...$this->row('solver', 'Scheduling Solver', self::Unavailable, 'The selected scheduling solver configuration is incomplete or invalid.', $evidenceSource, $evidenceSourceLabel, null, $responsibleOwner, 'Correct the solver driver and endpoint configuration.', $technicalDetails),
                'display_status' => 'Unavailable',
                'badge_color' => 'danger',
                'reachability' => 'Unreachable',
                'error_summary' => $diagnosis['error_summary'],
                'troubleshooting_steps' => $diagnosis['troubleshooting_steps'],
                'facts' => [
                    ['label' => 'Driver / mode', 'value' => $driverLabel],
                    ['label' => 'Reachability', 'value' => 'Unreachable'],
                    ['label' => 'Last run', 'value' => $lastRunFact],
                    ['label' => 'Last check', 'value' => 'Not recorded yet'],
                ],
            ];
        }

        $reachability = match (true) {
            $latestProbe?->status === OperationalEvent::StatusProcessed => 'Online',
            $latestProbe?->status === OperationalEvent::StatusFailed => 'Unreachable',
            $driver === 'local_stub' => 'Online (local stub)',
            default => 'Not checked yet',
        };

        $asOf = $this->latestTimestamp($latestProbe?->occurred_at, $latestRun?->updated_at);
        $asOfDisplay = DisplayDateTime::format($asOf, 'M j, Y g:i A', 'Not recorded yet');
        $errorSummary = null;
        $troubleshootingSteps = [];

        $probeIsNewerOrSoleFailure = $latestProbe?->status === OperationalEvent::StatusFailed
            && (! $latestRun instanceof ScheduleGenerationRun || $latestProbe->occurred_at->greaterThanOrEqualTo($latestRun->updated_at));

        if (in_array($latestRun?->status, [ScheduleGenerationRun::StatusQueued, ScheduleGenerationRun::StatusDispatching], true)) {
            $status = self::NeedsAttention;
            $displayStatus = 'Needs attention';
            $badgeColor = 'warning';
            $evidence = 'A schedule generation run is queued or dispatching; solver output is not yet accepted.';
            $nextAction = 'Monitor the active run in Term Planning until completion.';
            $errorSummary = 'A timetable generation run is currently queued or dispatching.';
            $troubleshootingSteps = [
                'Verify the scheduling queue worker is running if the run stays queued.',
                'Click Solver runs & reports to inspect runtime and attempt counts.',
            ];
        } elseif ($probeIsNewerOrSoleFailure) {
            $status = self::NeedsAttention;
            $displayStatus = 'Unreachable';
            $badgeColor = 'danger';
            $classification = is_string(data_get($latestProbe?->diagnostics, 'classification'))
                ? (string) data_get($latestProbe?->diagnostics, 'classification')
                : null;
            $statusCode = is_numeric(data_get($latestProbe?->diagnostics, 'status_code'))
                ? (int) data_get($latestProbe?->diagnostics, 'status_code')
                : null;
            $diagnosis = $this->solverTroubleshooting($classification, $statusCode);
            $errorSummary = $diagnosis['error_summary'];
            $troubleshootingSteps = $diagnosis['troubleshooting_steps'];
            $evidence = $errorSummary;
            $nextAction = $troubleshootingSteps[0] ?? 'Click Check solver again after inspecting the solver service.';
        } elseif (in_array($latestRun?->status, [ScheduleGenerationRun::StatusFailed, ScheduleGenerationRun::StatusBlocked], true)) {
            $status = self::NeedsAttention;
            $displayStatus = 'Needs attention';
            $badgeColor = 'warning';
            $evidence = 'The latest schedule generation run failed or requires Registrar review.';
            $nextAction = 'Open Term Planning to review the run or probe solver health.';

            if ($latestRun->status === ScheduleGenerationRun::StatusBlocked
                && data_get($latestRun->diagnostics, 'solver_result.solver_status') === 'infeasible') {
                $errorSummary = 'Latest timetable run found no schedule satisfying all hard constraints.';
                $troubleshootingSteps = [
                    'Click Solver runs & reports to review the run outcome and metadata.',
                    'Ask the Registrar to resolve conflicting room, faculty, or class constraints in Term Planning.',
                ];
            } else {
                $failure = $latestRun->finalSolverFailure();
                $diagnosis = $this->solverTroubleshooting(
                    is_string($failure['classification'] ?? null) ? (string) $failure['classification'] : null,
                    is_numeric($failure['status_code'] ?? null) ? (int) $failure['status_code'] : null,
                );
                $errorSummary = $diagnosis['error_summary'];
                $troubleshootingSteps = $diagnosis['troubleshooting_steps'];
            }
        } elseif ($latestProbe?->status === OperationalEvent::StatusFailed) {
            $status = self::NeedsAttention;
            $displayStatus = 'Unreachable';
            $badgeColor = 'danger';
            $diagnosis = $this->solverTroubleshooting(
                is_string(data_get($latestProbe->diagnostics, 'classification'))
                    ? (string) data_get($latestProbe->diagnostics, 'classification')
                    : null,
                is_numeric(data_get($latestProbe->diagnostics, 'status_code'))
                    ? (int) data_get($latestProbe->diagnostics, 'status_code')
                    : null,
            );
            $errorSummary = $diagnosis['error_summary'];
            $troubleshootingSteps = $diagnosis['troubleshooting_steps'];
            $evidence = $errorSummary;
            $nextAction = $troubleshootingSteps[0] ?? 'Click Check solver again after inspecting the solver service.';
        } elseif ($latestProbe?->status === OperationalEvent::StatusProcessed) {
            $status = self::Available;
            $displayStatus = 'Online';
            $badgeColor = 'success';
            $evidence = 'The scheduling solver health endpoint responded normally.';
            $nextAction = 'No action required.';
        } elseif (in_array($latestRun?->status, [ScheduleGenerationRun::StatusUnderReview, ScheduleGenerationRun::StatusPublished, ScheduleGenerationRun::StatusSuperseded], true)) {
            $status = self::Available;
            $displayStatus = 'Available';
            $badgeColor = 'success';
            $evidence = 'The latest accepted schedule generation run succeeded.';
            $nextAction = 'Click Check solver to probe live /health reachability.';
        } else {
            $status = self::NotRecentlyChecked;
            $displayStatus = 'Not checked yet';
            $badgeColor = 'gray';
            $evidence = 'Solver configuration is valid; no live health probe or timetable run is recorded yet.';
            $nextAction = 'Click Check solver to verify /health reachability.';
        }

        return [
            ...$this->row('solver', 'Scheduling Solver', $status, $evidence, $evidenceSource, $evidenceSourceLabel, $asOf, $responsibleOwner, $nextAction, $technicalDetails),
            'display_status' => $displayStatus,
            'badge_color' => $badgeColor,
            'reachability' => $reachability,
            'error_summary' => $errorSummary,
            'troubleshooting_steps' => $troubleshootingSteps,
            'facts' => [
                ['label' => 'Driver / mode', 'value' => $driverLabel],
                ['label' => 'Reachability', 'value' => $reachability],
                ['label' => 'Last run', 'value' => $lastRunFact],
                ['label' => 'Last check', 'value' => $asOfDisplay],
            ],
        ];
    }

    private function latestTimestamp(?CarbonInterface $first, ?CarbonInterface $second): ?CarbonInterface
    {
        if ($first === null) {
            return $second;
        }

        if ($second === null) {
            return $first;
        }

        return $first->greaterThan($second) ? $first : $second;
    }

    private function classifyMailFailure(OperationalEvent $latest): string
    {
        $cachedCategory = Cache::get('tala:system-health:mail-failure-category:'.$latest->getKey());
        if (is_string($cachedCategory) && $cachedCategory !== '') {
            return $cachedCategory;
        }

        $diagnosticsText = strtolower(json_encode($latest->diagnostics ?? [], JSON_UNESCAPED_SLASHES) ?: '');

        if ($diagnosticsText !== '' && $diagnosticsText !== '[]') {
            $mapped = self::mapErrorCategory($diagnosticsText);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        try {
            $failedMailExceptions = DB::table('failed_jobs')
                ->select(['payload', 'exception'])
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->filter(function (object $row): bool {
                    $jobName = strtolower($this->extractSafeJobName((string) ($row->payload ?? '')));

                    return str_contains($jobName, 'mail') || str_contains($jobName, 'notification');
                })
                ->map(fn (object $row): string => strtolower((string) strtok((string) ($row->exception ?? ''), "\r\n")));

            foreach ($failedMailExceptions as $exceptionLine) {
                $mapped = self::mapErrorCategory($exceptionLine);
                if ($mapped !== null) {
                    return $mapped;
                }
            }
        } catch (Throwable) {
            // Fall through to safe default classification.
        }

        return 'Transport error';
    }

    /**
     * @return array{error_summary: string, troubleshooting_steps: list<string>}
     */
    private function mailTroubleshooting(string $category): array
    {
        return match ($category) {
            'Authentication failed' => [
                'error_summary' => 'Mail server rejected SMTP authentication credentials.',
                'troubleshooting_steps' => [
                    'Verify MAIL_USERNAME and MAIL_PASSWORD (or provider app password) on the host.',
                    'Confirm MAIL_FROM_ADDRESS is authorized by your mail provider.',
                    'Click Send test email to verify delivery.',
                ],
            ],
            'Connection refused' => [
                'error_summary' => 'Could not connect to the configured mail host and port.',
                'troubleshooting_steps' => [
                    'Verify MAIL_HOST, MAIL_PORT, and outbound firewall rules.',
                    'Confirm the SMTP relay or local mail service is running.',
                    'Click Send test email again once connectivity is restored.',
                ],
            ],
            'Connection timed out' => [
                'error_summary' => 'Mail server connection timed out before completing handshake.',
                'troubleshooting_steps' => [
                    'Check whether outbound SMTP ports (587 or 465) are allowed by the host firewall.',
                    'Verify MAIL_HOST reachability and retry Send test email.',
                ],
            ],
            'TLS / certificate error' => [
                'error_summary' => 'TLS or certificate validation failed with the mail server.',
                'troubleshooting_steps' => [
                    'Verify MAIL_SCHEME and encryption match MAIL_PORT (587 STARTTLS or 465 SMTPS).',
                    'Confirm the mail server TLS certificate matches MAIL_HOST.',
                    'Click Send test email after correcting mail settings.',
                ],
            ],
            default => [
                'error_summary' => 'Mail transport failed to accept the outbound message.',
                'troubleshooting_steps' => [
                    'Verify MAIL_MAILER, MAIL_HOST, MAIL_PORT, and MAIL_FROM_ADDRESS.',
                    'Inspect failed queue jobs (if mail is queued) or server mail logs.',
                    'Click Send test email to retest local transport.',
                ],
            ],
        };
    }

    /**
     * @return array{error_summary: string, troubleshooting_steps: list<string>}
     */
    private function solverTroubleshooting(?string $classification, ?int $statusCode): array
    {
        return match ($classification) {
            SchedulingSolverTransportException::ClassificationConnection => [
                'error_summary' => 'Could not connect to the configured solver endpoint.',
                'troubleshooting_steps' => [
                    'Verify TALA_SCHEDULING_SOLVER_URL and outbound network connectivity from this host.',
                    'Confirm the Cloud Run or local solver service is running.',
                    'Click Check solver again to verify recovery.',
                ],
            ],
            SchedulingSolverTransportException::ClassificationCredential => [
                'error_summary' => 'Solver authentication failed while preparing credentials or identity token.',
                'troubleshooting_steps' => [
                    'Verify TALA_SCHEDULING_SOLVER_CREDENTIALS points to a readable service-account JSON file.',
                    'Confirm TALA_SCHEDULING_SOLVER_AUDIENCE matches the Cloud Run service URL.',
                    'Click Check solver again once credentials are verified.',
                ],
            ],
            SchedulingSolverTransportException::ClassificationClientError => match (true) {
                in_array($statusCode, [401, 403], true) => [
                    'error_summary' => "Solver endpoint rejected authentication or access (HTTP {$statusCode}).",
                    'troubleshooting_steps' => [
                        'Verify the Cloud Run IAM invoker role and service-account credentials.',
                        'Confirm the Google Cloud project billing and Cloud Run service are active in Google Cloud Console.',
                        'Click Check solver again to confirm access.',
                    ],
                ],
                default => [
                    'error_summary' => 'Solver endpoint returned a client error'.($statusCode !== null ? " (HTTP {$statusCode})" : '').'.',
                    'troubleshooting_steps' => [
                        'Verify TALA_SCHEDULING_SOLVER_URL points to the root solver service URL.',
                        'Check solver service logs for request or routing mismatches.',
                        'Click Check solver again after correcting the endpoint.',
                    ],
                ],
            },
            SchedulingSolverTransportException::ClassificationRateLimited,
            SchedulingSolverTransportException::ClassificationRequestBudget => [
                'error_summary' => 'Solver service hit a quota, concurrency, or request-budget limit.',
                'troubleshooting_steps' => [
                    'Check Cloud Run concurrency and quota limits in Google Cloud Console.',
                    'Wait briefly for active timetable runs to drain, then click Check solver again.',
                ],
            ],
            SchedulingSolverTransportException::ClassificationTimeout => [
                'error_summary' => 'Solver health probe timed out waiting for a response.',
                'troubleshooting_steps' => [
                    'Check solver container warmup, CPU allocation, or active load.',
                    'Verify TALA_SCHEDULING_SOLVER_CONNECT_TIMEOUT_SECONDS in environment settings.',
                    'Wait a moment and click Check solver again.',
                ],
            ],
            SchedulingSolverTransportException::ClassificationServerError => [
                'error_summary' => 'Solver service returned a server error'.($statusCode !== null ? " (HTTP {$statusCode})" : '').'.',
                'troubleshooting_steps' => [
                    'Inspect the solver container or Cloud Run logs for startup or runtime errors.',
                    'Confirm the active solver service revision is healthy and serving traffic.',
                    'Click Check solver again once the service recovers.',
                ],
            ],
            SchedulingSolverTransportException::ClassificationConfiguration => [
                'error_summary' => 'Solver configuration is incomplete or failed local validation.',
                'troubleshooting_steps' => [
                    'Verify TALA_SCHEDULING_SOLVER_DRIVER, URL, audience, and credentials path.',
                    'Click Refresh status and Check solver after updating configuration.',
                ],
            ],
            default => [
                'error_summary' => 'The scheduling solver health endpoint could not be reached.',
                'troubleshooting_steps' => [
                    'Verify TALA_SCHEDULING_SOLVER_URL and confirm the solver service is running.',
                    'Inspect server or Cloud Run logs if the service recently restarted.',
                    'Click Check solver again to retest reachability.',
                ],
            ],
        };
    }

    private static function mapErrorCategory(string $text): ?string
    {
        return match (true) {
            str_contains($text, 'authentication') || str_contains($text, '535') || str_contains($text, 'username and password') || str_contains($text, 'credentials') => 'Authentication failed',
            str_contains($text, 'timed out') || str_contains($text, 'timeout') => 'Connection timed out',
            str_contains($text, 'connection refused') || str_contains($text, 'could not be established') || str_contains($text, 'network is unreachable') || str_contains($text, 'name or service not known') => 'Connection refused',
            str_contains($text, 'tls') || str_contains($text, 'ssl') || str_contains($text, 'certificate') || str_contains($text, 'starttls') => 'TLS / certificate error',
            default => null,
        };
    }

    private function extractSafeJobName(string $payloadJson): string
    {
        if ($payloadJson === '') {
            return 'Queued job';
        }

        $decoded = json_decode($payloadJson, true);
        if (! is_array($decoded)) {
            return 'Queued job';
        }

        $candidate = $decoded['displayName'] ?? data_get($decoded, 'data.commandName') ?? null;

        if (! is_string($candidate) || trim($candidate) === '') {
            return 'Queued job';
        }

        $shortName = class_basename(trim($candidate));
        $sanitized = preg_replace('/[^A-Za-z0-9_\\\\:-]/', '', $shortName);

        return is_string($sanitized) && $sanitized !== '' ? Str::limit($sanitized, 80) : 'Queued job';
    }

    /** @return array{key:string,service:string,status:string,evidence:string,evidence_source:string,evidence_source_label:string,as_of:string,as_of_display:string,responsible_owner:string,next_action:string,technical_details:string,is_external:bool} */
    private function row(
        string $key,
        string $service,
        string $status,
        string $evidence,
        string $evidenceSource,
        string $evidenceSourceLabel,
        ?CarbonInterface $asOf,
        string $responsibleOwner,
        string $nextAction,
        string $technicalDetails = '',
        bool $isExternal = false,
    ): array {
        return [
            'key' => $key,
            'service' => $service,
            'status' => $status,
            'evidence' => $evidence,
            'evidence_source' => $evidenceSource,
            'evidence_source_label' => $evidenceSourceLabel,
            'as_of' => $asOf?->toIso8601String() ?? 'No accepted evidence',
            'as_of_display' => DisplayDateTime::format($asOf, 'M j, Y g:i A', 'Not recorded yet'),
            'responsible_owner' => $responsibleOwner,
            'next_action' => $nextAction,
            'technical_details' => $technicalDetails,
            'is_external' => $isExternal,
        ];
    }
}
