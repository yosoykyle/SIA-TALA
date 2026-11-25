<x-filament-panels::page>
    @php
        $summary = $this->healthSummary;
        $cards = $this->healthCards;

        $cardIcons = [
            'app_database' => 'heroicon-o-server-stack',
            'queue' => 'heroicon-o-queue-list',
            'mail' => 'heroicon-o-envelope',
            'solver' => 'heroicon-o-cpu-chip',
        ];
    @endphp

    <div class="grid" style="gap: var(--tala-card-gap);">
        @if ($captureStale && $captureNotice !== null)
            <div
                class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm text-warning-900 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-100"
                role="status"
                aria-live="polite"
            >
                <span class="font-semibold">Stale capture.</span>
                {{ $captureNotice }}
            </div>
        @endif

        @php
            $summaryColor = (string) ($summary['color'] ?? 'gray');
            $summaryIcon = match ($summaryColor) {
                'success' => 'heroicon-o-check-circle',
                'warning', 'danger' => 'heroicon-o-exclamation-triangle',
                default => 'heroicon-o-signal',
            };
        @endphp

        <section
            class="tala-task-surface flex flex-col gap-4 rounded-xl p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5"
            aria-label="Local system health summary"
        >
            <div class="flex min-w-0 items-start gap-3.5">
                <div
                    @class([
                        'flex h-10 w-10 shrink-0 items-center justify-center rounded-full border',
                        'border-success-200 bg-success-50 text-success-700 dark:border-success-800/60 dark:bg-success-950/60 dark:text-success-400' => $summaryColor === 'success',
                        'border-warning-200 bg-warning-50 text-warning-700 dark:border-warning-800/60 dark:bg-warning-950/60 dark:text-warning-400' => $summaryColor === 'warning',
                        'border-danger-200 bg-danger-50 text-danger-700 dark:border-danger-800/60 dark:bg-danger-950/60 dark:text-danger-400' => $summaryColor === 'danger',
                        'border-gray-200 bg-gray-100 text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300' => ! in_array($summaryColor, ['success', 'warning', 'danger'], true),
                    ])
                >
                    <x-filament::icon :icon="$summaryIcon" class="h-5 w-5" />
                </div>

                <div class="min-w-0 space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-base font-semibold" style="color: var(--tala-text);">
                            {{ $summary['headline'] ?? $summary['label'] }}
                        </h2>
                        <x-filament::badge :color="$summaryColor" size="sm">
                            {{ $summary['breakdown'] ?? $summary['label'] }}
                        </x-filament::badge>
                        @if ($captureStale)
                            <x-filament::badge color="warning" size="sm">Stale</x-filament::badge>
                        @endif
                    </div>
                    <p class="text-sm" style="color: var(--tala-muted);">
                        {{ $summary['description'] }}
                    </p>
                </div>
            </div>

            <div
                class="shrink-0 rounded-lg border px-3 py-2 text-left sm:text-right"
                style="border-color: var(--tala-edge); background: var(--tala-surface-sunken);"
            >
                <p class="text-xs font-medium uppercase tracking-wide" style="color: var(--tala-muted);">
                    Last checked
                </p>
                <p class="mt-0.5 text-xs font-semibold tabular-nums" style="color: var(--tala-text);">
                    {{ $summary['captured_at_display'] }} · Asia/Manila
                </p>
            </div>
        </section>

        <div class="grid grid-cols-1 lg:grid-cols-2" style="gap: var(--tala-card-gap);">
            @foreach ($cards as $card)
                @php
                    $key = (string) ($card['key'] ?? '');
                    $icon = $cardIcons[$key] ?? 'heroicon-o-signal';
                    $facts = is_array($card['facts'] ?? null) ? $card['facts'] : [];
                @endphp

                <section
                    class="tala-task-surface flex h-full flex-col rounded-xl p-5"
                    aria-labelledby="health-card-{{ $key }}"
                >
                    <div class="flex-1">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <x-filament::icon
                                    :icon="$icon"
                                    class="h-5 w-5 shrink-0"
                                    style="color: var(--tala-muted);"
                                />
                                <h2
                                    id="health-card-{{ $key }}"
                                    class="text-base font-semibold"
                                    style="color: var(--tala-text);"
                                >
                                    {{ $card['service'] }}
                                </h2>
                            </div>

                            <x-filament::badge :color="$card['badge_color'] ?? 'gray'" size="sm">
                                {{ $card['display_status'] ?? $card['status'] }}
                            </x-filament::badge>
                        </div>

                        <p class="mt-2 text-sm" style="color: var(--tala-muted);">
                            {{ $card['evidence'] }}
                        </p>

                        @if ($facts !== [])
                            <dl class="tala-profile-inset mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                @foreach ($facts as $fact)
                                    <div class="min-w-0">
                                        <dt class="text-xs font-medium" style="color: var(--tala-muted);">
                                            {{ $fact['label'] }}
                                        </dt>
                                        <dd class="mt-0.5 text-sm font-semibold tabular-nums break-words" style="color: var(--tala-text);">
                                            {{ $fact['value'] }}
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif

                        @if (filled($card['error_summary'] ?? null) || ! empty($card['troubleshooting_steps'] ?? []))
                            @php
                                $isDangerAlert = ($card['badge_color'] ?? '') === 'danger';
                                $steps = is_array($card['troubleshooting_steps'] ?? null) ? $card['troubleshooting_steps'] : [];
                            @endphp
                            <div
                                @class([
                                    'mt-4 rounded-lg border p-3.5 text-xs space-y-2',
                                    'border-danger-300 bg-danger-50/70 text-danger-950 dark:border-danger-700/60 dark:bg-danger-950/40 dark:text-danger-100' => $isDangerAlert,
                                    'border-warning-300 bg-warning-50/70 text-warning-950 dark:border-warning-700/60 dark:bg-warning-950/40 dark:text-warning-100' => ! $isDangerAlert,
                                ])
                                role="region"
                                aria-label="Error diagnosis and troubleshooting steps"
                            >
                                @if (filled($card['error_summary'] ?? null))
                                    <p class="font-semibold">
                                        Error reason: {{ $card['error_summary'] }}
                                    </p>
                                @endif

                                @if ($steps !== [])
                                    <ol class="list-decimal space-y-1 pl-4">
                                        @foreach ($steps as $step)
                                            <li>{{ $step }}</li>
                                        @endforeach
                                    </ol>
                                @endif
                            </div>
                        @endif
                    </div>

                    <div
                        class="mt-auto flex flex-wrap items-center gap-2 border-t pt-3"
                        style="margin-block-start: 1rem; border-color: var(--tala-edge);"
                    >
                        @if ($key === 'app_database')
                            {{ $this->viewTechnicalDetailsAction }}
                        @elseif ($key === 'queue')
                            {{ $this->inspectFailedJobsAction }}
                            @if ($this->retryFailedJobsAction->isVisible())
                                {{ $this->retryFailedJobsAction }}
                            @endif
                        @elseif ($key === 'mail')
                            {{ $this->sendTestEmailAction }}
                        @elseif ($key === 'solver')
                            {{ $this->checkSolverAction }}
                            {{ $this->solverRunsReportsAction }}
                        @endif
                    </div>
                </section>
            @endforeach
        </div>

        <p class="px-1 text-xs" style="color: var(--tala-muted);">
            Automated database backups and disaster recovery are managed at the hosting infrastructure level.
        </p>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
