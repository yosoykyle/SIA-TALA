<?php

namespace App\Actions\SystemAdministration;

use App\Actions\Integrations\SchedulingSolver\LocalHttpSchedulingSolverClient;
use App\Models\OperationalEvent;
use App\Models\ScheduleGenerationRun;
use App\Support\DisplayDateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

final class IntegrationHealthPresenter
{
    /**
     * @return list<array{
     *     name:string,
     *     driver:string,
     *     mode_label:string,
     *     configured:bool,
     *     configuration_label:string,
     *     configuration_color:string,
     *     evidence_label:string,
     *     evidence_color:string,
     *     owner:string,
     *     next_action:string,
     *     reference:array<string,string>
     * }>
     */
    public function integrations(): array
    {
        return [
            $this->emailStatus(),
            $this->paymentsStatus(),
            $this->schedulerStatus(),
        ];
    }

    /**
     * @return array{label:string,color:string,description:string}
     */
    public function summary(): array
    {
        $integrations = collect($this->integrations());
        $incomplete = $integrations
            ->filter(fn (array $integration): bool => ! $integration['configured'])
            ->pluck('name');

        if ($incomplete->isNotEmpty()) {
            return [
                'label' => 'Configuration incomplete',
                'color' => 'danger',
                'description' => 'Complete the local configuration for '.$incomplete->join(', ', ' and ').' before the demonstration.',
            ];
        }

        $attention = $integrations
            ->filter(fn (array $integration): bool => $integration['evidence_label'] === 'Attention required')
            ->pluck('name');

        if ($attention->isNotEmpty()) {
            return [
                'label' => 'Attention required',
                'color' => 'warning',
                'description' => 'Review unresolved operational evidence for '.$attention->join(', ', ' and ').'.',
            ];
        }

        $unverified = $integrations
            ->filter(fn (array $integration): bool => $integration['evidence_label'] === 'Not yet observed')
            ->pluck('name');

        if ($unverified->isNotEmpty()) {
            return [
                'label' => 'Ready for verification',
                'color' => 'primary',
                'description' => 'Local configuration is complete. Produce acceptance evidence for '.$unverified->join(', ', ' and ').'.',
            ];
        }

        return [
            'label' => 'Success observed',
            'color' => 'success',
            'description' => 'Local configuration and successful operational evidence are present. Recheck them before the demonstration.',
        ];
    }

    /**
     * @return array{
     *     name:string,
     *     driver:string,
     *     mode_label:string,
     *     configured:bool,
     *     configuration_label:string,
     *     configuration_color:string,
     *     evidence_label:string,
     *     evidence_color:string,
     *     owner:string,
     *     next_action:string,
     *     reference:array<string,string>
     * }
     */
    private function emailStatus(): array
    {
        $mailer = (string) config('mail.default');
        $usesExternalDelivery = ! in_array($mailer, ['log', 'array'], true);
        $configuredDriverKeys = match ($mailer) {
            'smtp' => [config('mail.mailers.smtp.host'), config('mail.mailers.smtp.username')],
            'postmark' => [config('services.postmark.token')],
            'ses', 'ses-v2' => [config('services.ses.key')],
            'resend' => [config('services.resend.key')],
            default => [],
        };
        $configured = ! $usesExternalDelivery
            || collect($configuredDriverKeys)->every(fn (mixed $value): bool => filled($value));
        $evidence = $this->observedEvidence(
            OperationalEvent::query()
                ->where('event_domain', OperationalEvent::DomainNotifications)
                ->where('integration', OperationalEvent::IntegrationMail),
        );

        return $this->status(
            name: 'Email',
            driver: $mailer,
            modeLabel: $usesExternalDelivery ? 'External delivery' : 'Local capture',
            configured: $configured,
            evidence: $evidence,
            owner: 'System Administrator',
            nextAction: match (true) {
                ! $configured => 'Complete the selected mail transport configuration, then send a test email.',
                $evidence['open_count'] > 0 => 'Review the failed mail event in Operational Events, correct the transport, and send another test email.',
                $evidence['last_success'] === null => 'Use Send test email to create delivery evidence for the signed-in System Administrator.',
                default => 'No immediate correction is required. Send another test email during presentation preflight.',
            },
            reference: [
                'From address' => (string) config('mail.from.address'),
                'From name' => (string) config('mail.from.name'),
                'Last successful event' => $evidence['last_success'] ?? 'None observed',
                'Last attention event' => $evidence['last_attention'] ?? 'None observed',
                'Open exceptions' => (string) $evidence['open_count'],
            ],
        );
    }

    /**
     * @return array{
     *     name:string,
     *     driver:string,
     *     mode_label:string,
     *     configured:bool,
     *     configuration_label:string,
     *     configuration_color:string,
     *     evidence_label:string,
     *     evidence_color:string,
     *     owner:string,
     *     next_action:string,
     *     reference:array<string,string>
     * }
     */
    private function paymentsStatus(): array
    {
        $driver = (string) config('tala_integrations.payments.driver');
        $isPayMongo = $driver === 'paymongo';
        $hasApiKeys = filled(config('tala_integrations.payments.paymongo.secret_key'))
            && filled(config('tala_integrations.payments.paymongo.public_key'));
        $hasWebhookSecret = filled(config('tala_integrations.payments.paymongo.webhook_signature'));
        $hasWebhookRoute = Route::has('webhooks.paymongo');
        $applicationUrl = rtrim((string) config('app.url'), '/');
        $webhookPath = $hasWebhookRoute
            ? route('webhooks.paymongo', [], false)
            : '/api/webhooks/paymongo';
        $webhookUrl = $applicationUrl.'/'.ltrim($webhookPath, '/');
        $hasPublicHttpsCallback = $this->isPublicHttpsUrl($webhookUrl);
        $configured = $isPayMongo
            ? $hasApiKeys && $hasWebhookSecret && $hasWebhookRoute && $hasPublicHttpsCallback
            : filled(config('tala_integrations.payments.mock.provider'));
        $webhookEvents = OperationalEvent::query()
            ->where('event_domain', OperationalEvent::DomainIntegration)
            ->where('integration', OperationalEvent::IntegrationPayMongo)
            ->where('channel', OperationalEvent::ChannelWebhook)
            ->where('direction', OperationalEvent::DirectionInbound);
        $openEvents = OperationalEvent::query()
            ->where('event_domain', OperationalEvent::DomainIntegration)
            ->where('integration', OperationalEvent::IntegrationPayMongo)
            ->whereIn('channel', [
                OperationalEvent::ChannelWebhook,
                OperationalEvent::ChannelProviderApi,
            ]);
        $evidence = $this->observedEvidence($webhookEvents, $openEvents);

        return $this->status(
            name: 'Payments (PayMongo)',
            driver: $driver,
            modeLabel: match (true) {
                $isPayMongo && (bool) config('tala_integrations.payments.paymongo.livemode') => 'Live mode',
                $isPayMongo => 'Test mode',
                $driver === 'mock' => 'Mock provider',
                default => 'Unsupported mode',
            },
            configured: $configured,
            evidence: $isPayMongo
                ? $evidence
                : $this->unobservedEvidence(),
            owner: 'System Administrator for configuration; Accounting for payment exceptions',
            nextAction: match (true) {
                ! $isPayMongo => 'Select PayMongo test mode before running provider acceptance.',
                ! $configured => 'Set APP_URL to the active public HTTPS origin, complete PayMongo test configuration, and confirm the callback in PayMongo.',
                $evidence['open_count'] > 0 => 'Review Operational Events, correct integration failures, and route payment-evidence exceptions to Accounting.',
                $evidence['last_success'] === null => 'Complete one PayMongo test checkout and confirm its signed webhook, payment, and ledger evidence.',
                default => 'No immediate correction is required. Reverify one signed test checkout before the demonstration.',
            },
            reference: $isPayMongo ? [
                'Application base URL' => $applicationUrl,
                'Public webhook URL' => $webhookUrl,
                'Public HTTPS callback' => $hasPublicHttpsCallback ? 'Ready' : 'Not ready',
                'PayMongo API base URL' => (string) config('tala_integrations.payments.paymongo.base_url'),
                'Payment method types' => implode(', ', (array) config('tala_integrations.payments.paymongo.payment_method_types')),
                'Test acceptance events' => 'payment.paid, checkout_session.payment.paid, payment.failed',
                'API key references' => $hasApiKeys ? 'Configured' : 'Missing',
                'Webhook signing secret' => $hasWebhookSecret ? 'Configured' : 'Missing',
                'Local webhook route' => $hasWebhookRoute ? 'Registered' : 'Missing',
                'Last verified webhook' => $evidence['last_success'] ?? 'None observed',
                'Last attention event' => $evidence['last_attention'] ?? 'None observed',
                'Open exceptions' => (string) $evidence['open_count'],
                'PayMongo dashboard registration' => 'Not checked by TALA',
            ] : [
                'Mock checkout URL' => (string) config('tala_integrations.payments.mock.checkout_base_url'),
            ],
        );
    }

    /**
     * @return array{
     *     name:string,
     *     driver:string,
     *     mode_label:string,
     *     configured:bool,
     *     configuration_label:string,
     *     configuration_color:string,
     *     evidence_label:string,
     *     evidence_color:string,
     *     owner:string,
     *     next_action:string,
     *     reference:array<string,string>
     * }
     */
    private function schedulerStatus(): array
    {
        $driver = (string) config('tala_integrations.scheduling_solver.driver');
        $url = config('tala_integrations.scheduling_solver.url') !== null
            ? (string) config('tala_integrations.scheduling_solver.url')
            : null;
        $audience = config('tala_integrations.scheduling_solver.audience') !== null
            ? (string) config('tala_integrations.scheduling_solver.audience')
            : null;
        $credentialsPath = config('tala_integrations.scheduling_solver.credentials_path') !== null
            ? (string) config('tala_integrations.scheduling_solver.credentials_path')
            : null;
        $configured = match ($driver) {
            'local_stub' => true,
            'local_http' => LocalHttpSchedulingSolverClient::supportsEnvironment(app()->environment())
                && LocalHttpSchedulingSolverClient::supportsBaseUrl($url),
            'cloud_run' => $this->isHttpsBaseUrl($url)
                && $this->isHttpsBaseUrl($audience)
                && filled($credentialsPath)
                && is_readable((string) $credentialsPath),
            default => false,
        };
        $evidence = $this->observedEvidence(
            OperationalEvent::query()
                ->where('event_domain', OperationalEvent::DomainIntegration)
                ->where('integration', OperationalEvent::IntegrationSchedulingSolver),
        );
        $lastObservedSolverIdentity = ScheduleGenerationRun::query()
            ->whereNotNull('solver_version')
            ->latest('updated_at')
            ->value('solver_version');
        $reference = match ($driver) {
            'local_stub' => [
                'Transport' => 'In-process deterministic test double',
                'Timeout (seconds)' => (string) config('tala_integrations.scheduling_solver.timeout_seconds'),
            ],
            'local_http' => [
                'URL' => (string) $url,
                'Health endpoint' => rtrim((string) $url, '/').'/health',
                'Timeout (seconds)' => (string) config('tala_integrations.scheduling_solver.timeout_seconds'),
            ],
            'cloud_run' => [
                'URL' => (string) $url,
                'Audience' => (string) $audience,
                'Timeout (seconds)' => (string) config('tala_integrations.scheduling_solver.timeout_seconds'),
            ],
            default => [
                'Transport' => 'Unsupported driver configuration',
            ],
        };

        return $this->status(
            name: 'Scheduler (CP-SAT solver)',
            driver: $driver,
            modeLabel: match ($driver) {
                'local_stub' => 'Deterministic stub',
                'local_http' => 'Local CP-SAT',
                'cloud_run' => 'Private Cloud Run',
                default => 'Unsupported mode',
            },
            configured: $configured,
            evidence: $evidence,
            owner: 'System Administrator for connectivity; Registrar for timetable runs and publication',
            nextAction: match (true) {
                ! $configured => 'Complete the selected solver transport configuration before the Registrar requests a timetable.',
                $evidence['open_count'] > 0 => 'Review the failed solver events in Operational Events; the Registrar should retry only after connectivity is healthy.',
                $evidence['last_success'] === null => 'Have the Registrar run one guarded timetable generation to produce solver evidence.',
                default => 'The Registrar can review the accepted candidate and publish only after validation passes.',
            },
            reference: [
                ...$reference,
                'Expected source identity' => ScheduleGenerationRun::SolverVersion,
                'Last observed candidate identity' => is_string($lastObservedSolverIdentity)
                    ? $lastObservedSolverIdentity
                    : 'None observed',
                'Active deployment identity' => 'Not verified; requires separate deployment evidence',
                'Last successful event' => $evidence['last_success'] ?? 'None observed',
                'Last attention event' => $evidence['last_attention'] ?? 'None observed',
                'Open exceptions' => (string) $evidence['open_count'],
            ],
        );
    }

    /**
     * @param  array{label:string,color:string,last_success:?string,last_attention:?string,open_count:int}  $evidence
     * @param  array<string,string>  $reference
     * @return array{
     *     name:string,
     *     driver:string,
     *     mode_label:string,
     *     configured:bool,
     *     configuration_label:string,
     *     configuration_color:string,
     *     evidence_label:string,
     *     evidence_color:string,
     *     owner:string,
     *     next_action:string,
     *     reference:array<string,string>
     * }
     */
    private function status(
        string $name,
        string $driver,
        string $modeLabel,
        bool $configured,
        array $evidence,
        string $owner,
        string $nextAction,
        array $reference,
    ): array {
        return [
            'name' => $name,
            'driver' => $driver,
            'mode_label' => $modeLabel,
            'configured' => $configured,
            'configuration_label' => $configured ? 'Local configuration complete' : 'Local configuration incomplete',
            'configuration_color' => $configured ? 'success' : 'danger',
            'evidence_label' => $evidence['label'],
            'evidence_color' => $evidence['color'],
            'owner' => $owner,
            'next_action' => $nextAction,
            'reference' => $reference,
        ];
    }

    /**
     * @param  Builder<OperationalEvent>  $events
     * @param  Builder<OperationalEvent>|null  $openEvents
     * @return array{label:string,color:string,last_success:?string,last_attention:?string,open_count:int}
     */
    private function observedEvidence(Builder $events, ?Builder $openEvents = null): array
    {
        $lastProcessed = (clone $events)
            ->where('status', OperationalEvent::StatusProcessed)
            ->latest('processed_at')
            ->first(['processed_at', 'occurred_at']);
        $attentionEvents = $openEvents ?? clone $events;
        $lastAttention = (clone $attentionEvents)
            ->whereIn('status', [
                OperationalEvent::StatusFailed,
                OperationalEvent::StatusReviewRequired,
            ])
            ->latest('occurred_at')
            ->first(['occurred_at', 'failed_at']);
        $openCount = (clone $attentionEvents)
            ->whereIn('status', [
                OperationalEvent::StatusFailed,
                OperationalEvent::StatusReviewRequired,
            ])
            ->count();

        return [
            'label' => match (true) {
                $openCount > 0 => 'Attention required',
                $lastProcessed instanceof OperationalEvent => 'Success observed',
                default => 'Not yet observed',
            },
            'color' => match (true) {
                $openCount > 0 => 'warning',
                $lastProcessed instanceof OperationalEvent => 'success',
                default => 'gray',
            },
            'last_success' => $this->eventTimestamp(
                $lastProcessed instanceof OperationalEvent
                    ? ($lastProcessed->processed_at ?? $lastProcessed->occurred_at)
                    : null,
            ),
            'last_attention' => $this->eventTimestamp(
                $lastAttention instanceof OperationalEvent
                    ? ($lastAttention->failed_at ?? $lastAttention->occurred_at)
                    : null,
            ),
            'open_count' => $openCount,
        ];
    }

    /**
     * @return array{label:string,color:string,last_success:null,last_attention:null,open_count:int}
     */
    private function unobservedEvidence(): array
    {
        return [
            'label' => 'Not yet observed',
            'color' => 'gray',
            'last_success' => null,
            'last_attention' => null,
            'open_count' => 0,
        ];
    }

    private function eventTimestamp(mixed $timestamp): ?string
    {
        return $timestamp === null
            ? null
            : DisplayDateTime::format($timestamp, 'Y-m-d H:i');
    }

    private function isHttpsBaseUrl(?string $url): bool
    {
        $parts = parse_url(trim((string) $url));
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && filled($parts['host'] ?? null)
            && ! array_key_exists('user', $parts)
            && ! array_key_exists('pass', $parts)
            && ! array_key_exists('query', $parts)
            && ! array_key_exists('fragment', $parts)
            && in_array($path, ['', '/'], true);
    }

    private function isPublicHttpsUrl(string $url): bool
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! filled($parts['host'] ?? null)
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)) {
            return false;
        }

        $host = Str::lower((string) $parts['host']);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        return ! in_array($host, ['localhost'], true)
            && ! Str::endsWith($host, ['.local', '.localhost', '.test']);
    }
}
