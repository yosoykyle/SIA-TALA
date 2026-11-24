@php
    /** @var \App\Models\ScheduleGenerationRun $record */
    $record = isset($run) && $run instanceof \App\Models\ScheduleGenerationRun
        ? $run
        : $this->getRecord();
    $contextTermId = property_exists($this, 'termId') ? $this->termId : $record->term_id;
    $report = \App\Filament\Resources\ScheduleGenerationRuns\Schemas\ScheduleGenerationRunInfolist::reportData($record, $contextTermId);
@endphp

@once
    <style>
        .tala-solver-report {
            display: grid;
            gap: var(--tala-section-gap, 1rem);
            min-inline-size: 0;
        }
        .tala-solver-metrics {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0;
            margin: 0;
            overflow: hidden;
            background: var(--tala-surface);
        }
        .tala-solver-metric {
            display: grid;
            align-content: start;
            gap: 0.25rem;
            min-inline-size: 0;
            padding: 0.9375rem var(--tala-card-pad, 1rem);
        }
        .tala-solver-metric + .tala-solver-metric {
            border-inline-start: 1px solid var(--tala-edge);
        }
        .tala-solver-metric.is-success {
            background: color-mix(in srgb, var(--tala-brand-tint) 34%, var(--tala-surface));
        }
        .tala-solver-metric.is-danger {
            background: color-mix(in srgb, var(--tala-status-danger-bg) 42%, var(--tala-surface));
        }
        .tala-solver-metric.is-warning {
            background: color-mix(in srgb, var(--tala-status-warning-bg) 38%, var(--tala-surface));
        }
        .tala-solver-metric__label {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            margin: 0;
            font-size: 0.75rem;
            font-weight: 600;
            line-height: 1.35;
            color: var(--tala-muted);
        }
        .tala-solver-metric__label .fi-icon {
            inline-size: 0.95rem;
            block-size: 0.95rem;
            flex: none;
            color: var(--tala-control-icon);
        }
        .tala-solver-metric.is-success .tala-solver-metric__label .fi-icon {
            color: var(--tala-status-success-fg);
        }
        .tala-solver-metric.is-danger .tala-solver-metric__label .fi-icon {
            color: var(--tala-status-danger-fg);
        }
        .tala-solver-metric__value {
            margin: 0;
            font-size: 1.375rem;
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: -0.02em;
            color: var(--tala-text);
            font-variant-numeric: tabular-nums;
            overflow-wrap: break-word;
        }
        .tala-solver-metric.is-success .tala-solver-metric__value {
            color: var(--tala-on-brand-tint);
        }
        .tala-solver-metric.is-danger .tala-solver-metric__value {
            color: var(--tala-status-danger-fg);
        }
        .tala-solver-metric__sub {
            margin: 0;
            font-size: 0.75rem;
            font-weight: 500;
            line-height: 1.4;
            color: var(--tala-muted);
        }
        .tala-solver-card {
            overflow: hidden;
        }
        .tala-solver-card__header {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            justify-content: space-between;
            gap: 0.25rem 1rem;
            padding: 0.75rem var(--tala-card-pad, 1rem);
            border-block-end: 1px solid var(--tala-edge);
            background: var(--tala-surface-sunken);
        }
        .tala-solver-card__header h2 {
            margin: 0;
            font-size: 0.9375rem;
            font-weight: 600;
            line-height: 1.35;
            color: var(--tala-text);
        }
        .tala-solver-card__caption {
            margin: 0;
            font-size: 0.8125rem;
            line-height: 1.45;
            color: var(--tala-muted);
            max-inline-size: 80ch;
        }
        .tala-solver-card__body {
            padding: var(--tala-card-pad, 1rem);
        }
        .tala-solver-kv-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem 1.5rem;
            margin: 0;
        }
        .tala-solver-kv-item {
            display: grid;
            align-content: start;
            gap: 0.25rem;
            min-inline-size: 0;
        }
        .tala-solver-kv-item--full {
            grid-column: 1 / -1;
            padding-block-start: 0.75rem;
            border-block-start: 1px solid var(--tala-edge);
        }
        .tala-solver-kv-item dt {
            font-size: 0.75rem;
            font-weight: 600;
            line-height: 1.35;
            color: var(--tala-muted);
        }
        .tala-solver-kv-item dd {
            margin: 0;
            display: grid;
            gap: 0.25rem;
            font-size: 0.875rem;
            line-height: 1.5;
            color: var(--tala-text);
            overflow-wrap: anywhere;
        }
        .tala-solver-kv-inline {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.45rem;
        }
        .tala-solver-kv-primary {
            font-weight: 600;
            color: var(--tala-text);
        }
        .tala-solver-kv-secondary {
            font-size: 0.8125rem;
            color: var(--tala-muted);
            line-height: 1.45;
        }
        .tala-solver-table-wrap {
            overflow-x: auto;
            min-inline-size: 0;
        }
        .tala-solver-table {
            inline-size: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
            text-align: start;
            font-variant-numeric: tabular-nums;
        }
        .tala-solver-table thead th {
            padding: 0.625rem var(--tala-card-pad, 1rem);
            border-block-end: 1px solid var(--tala-edge);
            background: color-mix(in srgb, var(--tala-surface-sunken) 55%, var(--tala-surface));
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--tala-muted);
            white-space: nowrap;
        }
        .tala-solver-table tbody td {
            padding: 0.6875rem var(--tala-card-pad, 1rem);
            border-block-end: 1px solid var(--tala-edge);
            vertical-align: middle;
            line-height: 1.45;
            color: var(--tala-text);
        }
        .tala-solver-table tbody tr:last-child td {
            border-block-end: 0;
        }
        .tala-solver-table__rule {
            font-weight: 600;
            color: var(--tala-text);
        }
        .tala-solver-table__notes {
            color: var(--tala-muted);
            font-size: 0.8125rem;
        }
        .tala-solver-table__value {
            text-align: end;
            font-weight: 600;
            white-space: nowrap;
        }
        .tala-solver-priority-pill {
            display: inline-grid;
            place-items: center;
            inline-size: 1.35rem;
            block-size: 1.35rem;
            margin-inline-end: 0.45rem;
            border-radius: 999px;
            border: 1px solid var(--tala-edge-strong);
            background: var(--tala-surface-sunken);
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--tala-muted);
            font-variant-numeric: tabular-nums;
        }
        .tala-solver-ref-footer {
            display: grid;
            gap: 0.625rem;
            padding: 0.75rem var(--tala-card-pad, 1rem);
            border: 1px solid var(--tala-edge);
            border-radius: var(--tala-card-radius);
            background: var(--tala-surface-sunken);
        }
        .tala-solver-ref-row {
            display: grid;
            grid-template-columns: minmax(0, 1.5fr) repeat(3, minmax(0, 1fr));
            gap: 0.625rem 1.25rem;
            margin: 0;
            align-items: center;
        }
        .tala-solver-ref-item {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 0.25rem 0.45rem;
            min-inline-size: 0;
            font-size: 0.8125rem;
            line-height: 1.4;
        }
        .tala-solver-ref-item dt {
            color: var(--tala-muted);
            font-weight: 500;
        }
        .tala-solver-ref-item dd {
            margin: 0;
            color: var(--tala-text);
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            overflow-wrap: anywhere;
        }
        .tala-solver-ref-admin {
            display: flex;
            flex-wrap: wrap;
            gap: 0.375rem 1.25rem;
            padding-block-start: 0.5rem;
            border-block-start: 1px solid var(--tala-edge);
            font-size: 0.75rem;
            color: var(--tala-muted);
            font-variant-numeric: tabular-nums;
        }
        .tala-solver-rm-table .fi-ta-header-toolbar > div:last-child {
            margin-inline-start: auto;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
        }
        @media (max-width: 56rem) {
            .tala-solver-metrics {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .tala-solver-metric:nth-child(3) {
                border-inline-start: 0;
            }
            .tala-solver-metric:nth-child(n + 3) {
                border-block-start: 1px solid var(--tala-edge);
            }
            .tala-solver-ref-row {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 38rem) {
            .tala-solver-metrics,
            .tala-solver-kv-grid,
            .tala-solver-ref-row {
                grid-template-columns: minmax(0, 1fr);
            }
            .tala-solver-metric + .tala-solver-metric {
                border-inline-start: 0;
                border-block-start: 1px solid var(--tala-edge);
            }
            .tala-solver-table__value {
                text-align: start;
                white-space: normal;
            }
        }
        @media (forced-colors: active) {
            .tala-solver-metrics,
            .tala-solver-metric,
            .tala-solver-card__header,
            .tala-solver-ref-footer {
                background: Canvas;
                color: CanvasText;
                border-color: CanvasText;
            }
        }
    </style>
@endonce

<div class="tala-solver-report" aria-label="Solver report summary and checks"
    @if ($report['isActiveRun']) wire:poll.5s.visible="refreshScheduleRun" @endif>

    @if ($report['isActiveRun'])
        <x-tala-operation-status :run="$report['run']" />
    @else
        {{-- 1. Top 4-Metric Summary Strip --}}
        <section aria-label="Solver summary metrics" class="tala-task-surface tala-solver-metrics">
            <div @class([
                'tala-solver-metric',
                'is-success' => $report['statusMetric']['tone'] === 'success',
                'is-danger' => $report['statusMetric']['tone'] === 'danger',
                'is-warning' => $report['statusMetric']['tone'] === 'warning',
            ])>
                <p class="tala-solver-metric__label">
                    <x-filament::icon :icon="$report['statusMetric']['tone'] === 'success' ? 'heroicon-o-check-badge' : ($report['statusMetric']['tone'] === 'danger' ? 'heroicon-o-exclamation-circle' : 'heroicon-o-document-check')" aria-hidden="true" />
                    <span>Status</span>
                </p>
                <p class="tala-solver-metric__value">{{ $report['statusMetric']['label'] }}</p>
                <p class="tala-solver-metric__sub">{{ $report['statusMetric']['subnote'] }}</p>
            </div>

            <div class="tala-solver-metric">
                <p class="tala-solver-metric__label">
                    <x-filament::icon icon="heroicon-o-calendar-days" aria-hidden="true" />
                    <span>Meetings placed</span>
                </p>
                <p class="tala-solver-metric__value">{{ $report['meetingsMetric'] }}</p>
                <p class="tala-solver-metric__sub">{{ $report['meetingsSubnote'] }}</p>
            </div>

            <div @class([
                'tala-solver-metric',
                'is-success' => $report['hardConflictsTone'] === 'success',
                'is-danger' => $report['hardConflictsTone'] === 'danger',
            ])>
                <p class="tala-solver-metric__label">
                    <x-filament::icon :icon="$report['hardConflicts'] > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-shield-check'" aria-hidden="true" />
                    <span>Hard conflicts</span>
                </p>
                <p class="tala-solver-metric__value">{{ $report['hardConflicts'] }}</p>
                <p class="tala-solver-metric__sub">{{ $report['hardConflictsSubnote'] }}</p>
            </div>

            <div class="tala-solver-metric">
                <p class="tala-solver-metric__label">
                    <x-filament::icon icon="heroicon-o-bolt" aria-hidden="true" />
                    <span>Solver runtime</span>
                </p>
                <p class="tala-solver-metric__value">{{ $report['runtimeShort'] }}</p>
                <p class="tala-solver-metric__sub">{{ $report['runtimeSubnote'] }}</p>
            </div>
        </section>

        @if ($report['blockerMessage'])
            <x-tala-access-alert variant="warning" title="Schedule blocked by validation">
                <p>{{ $report['blockerMessage'] }}</p>
            </x-tala-access-alert>
        @endif

        @if ($report['showFailureDiagnostics'])
            <section class="tala-task-surface tala-solver-card" aria-labelledby="solver-failure-heading">
                <header class="tala-solver-card__header">
                    <div>
                        <h2 id="solver-failure-heading">Technical diagnostics</h2>
                        <p class="tala-solver-card__caption">{{ $report['failureInfo']['guidance'] }}</p>
                    </div>
                </header>
                <div class="tala-solver-card__body">
                    <dl class="tala-solver-kv-grid">
                        <div class="tala-solver-kv-item">
                            <dt>Failure classification</dt>
                            <dd><span class="tala-solver-kv-primary">{{ $report['failureInfo']['classification'] }}</span></dd>
                        </div>
                        <div class="tala-solver-kv-item">
                            <dt>Retryable</dt>
                            <dd>
                                <div class="tala-solver-kv-inline">
                                    <x-filament::badge :color="$report['failureInfo']['retryable'] === 'Yes' ? 'warning' : 'gray'" size="sm">
                                        {{ $report['failureInfo']['retryable'] }}
                                    </x-filament::badge>
                                    <span class="tala-solver-kv-secondary">Cycle {{ $report['failureInfo']['dispatchCycle'] }} · {{ $report['failureInfo']['attemptCount'] }} {{ \Illuminate\Support\Str::plural('dispatch', $report['failureInfo']['attemptCount']) }}</span>
                                </div>
                            </dd>
                        </div>
                        <div class="tala-solver-kv-item tala-solver-kv-item--full">
                            <dt>Recorded failure detail</dt>
                            <dd>{{ $report['failureInfo']['message'] }}</dd>
                        </div>
                    </dl>
                </div>
            </section>
        @endif

        {{-- 2. Publication & Verification Card --}}
        <section class="tala-task-surface tala-solver-card" aria-labelledby="solver-pub-verification-heading">
            <header class="tala-solver-card__header">
                <h2 id="solver-pub-verification-heading">Publication &amp; verification</h2>
                <p class="tala-solver-card__caption">
                    {{ $report['wasPublished'] ? 'Official publication record and independent verification summary.' : 'A generated schedule remains a proposal until the Registrar publishes it in Timetable.' }}
                </p>
            </header>
            <div class="tala-solver-card__body">
                <dl class="tala-solver-kv-grid">
                    @if ($report['wasPublished'])
                        <div class="tala-solver-kv-item">
                            <dt>Published by</dt>
                            <dd>
                                <div class="tala-solver-kv-inline">
                                    <span class="tala-solver-kv-primary">{{ $report['publisherName'] }}</span>
                                    @if ($report['publishedVersionNumber'])
                                        <x-filament::badge color="primary" size="sm">Version {{ $report['publishedVersionNumber'] }}</x-filament::badge>
                                    @endif
                                </div>
                            </dd>
                        </div>
                        <div class="tala-solver-kv-item">
                            <dt>Published date (Asia/Manila)</dt>
                            <dd><span class="tala-solver-kv-primary tabular-nums">{{ $report['publishedAtFormatted'] }}</span></dd>
                        </div>
                    @else
                        <div class="tala-solver-kv-item">
                            <dt>Publication state</dt>
                            <dd>
                                <div class="tala-solver-kv-inline">
                                    <x-filament::badge :color="$report['statusMetric']['tone'] === 'success' ? 'success' : ($report['statusMetric']['tone'] === 'danger' ? 'danger' : 'gray')" size="sm">
                                        {{ $report['statusMetric']['label'] }}
                                    </x-filament::badge>
                                    <span class="tala-solver-kv-secondary">{{ $report['statusMetric']['subnote'] }}</span>
                                </div>
                            </dd>
                        </div>
                        <div class="tala-solver-kv-item">
                            <dt>Last checked (Asia/Manila)</dt>
                            <dd><span class="tala-solver-kv-primary tabular-nums">{{ $report['validatedAtFormatted'] }}</span></dd>
                        </div>
                    @endif

                    <div class="tala-solver-kv-item">
                        <dt>Validation status</dt>
                        <dd>
                            <div class="tala-solver-kv-inline">
                                <x-filament::badge :color="$report['validationColor']" size="sm">{{ $report['validationBadge'] }}</x-filament::badge>
                            </div>
                            <span class="tala-solver-kv-secondary">{{ $report['validationCoverage'] }}</span>
                        </dd>
                    </div>

                    <div class="tala-solver-kv-item">
                        <dt>Solver outcome</dt>
                        <dd>
                            <div class="tala-solver-kv-inline">
                                <x-filament::badge :color="$report['outcomeColor']" size="sm">{{ $report['outcomeBadge'] }}</x-filament::badge>
                                @if ($report['relativeGap'] !== null)
                                    <span class="tala-solver-kv-secondary tabular-nums">Optimality gap: {{ $report['relativeGap'] }}</span>
                                @endif
                            </div>
                            <span class="tala-solver-kv-secondary">{{ $report['outcomeMeaning'] }}</span>
                        </dd>
                    </div>

                    @if ($report['publicationNote'])
                        <div class="tala-solver-kv-item tala-solver-kv-item--full">
                            <dt>Publication note</dt>
                            <dd>{{ $report['publicationNote'] }}</dd>
                        </div>
                    @endif

                    @if ($report['recovery'])
                        <div class="tala-solver-kv-item tala-solver-kv-item--full">
                            <dt>Service recovery</dt>
                            <dd>
                                <div class="tala-solver-kv-inline">
                                    <a class="fi-link" href="{{ $report['recovery']['source_url'] }}">From attempt #{{ $report['recovery']['source_run_id'] }}</a>
                                    <span class="tala-solver-kv-secondary">· Requested by {{ $report['recovery']['actor'] }}@if ($report['recovery']['requested_at']) · {{ $report['recovery']['requested_at'] }}@endif</span>
                                </div>
                                @if ($report['recovery']['explanation'] !== '')
                                    <span class="tala-solver-kv-secondary">{{ $report['recovery']['explanation'] }}</span>
                                @endif
                            </dd>
                        </div>
                    @endif
                </dl>
            </div>
        </section>

        {{-- Conditional Validation Findings (only when findings exist) --}}
        @if ($report['findingRows'] !== [])
            <section class="tala-task-surface tala-solver-card" aria-labelledby="solver-findings-heading">
                <header class="tala-solver-card__header">
                    <h2 id="solver-findings-heading">Validation findings</h2>
                    <p class="tala-solver-card__caption">Recorded issues requiring review or source updates.</p>
                </header>
                <div class="tala-solver-table-wrap">
                    <table class="tala-solver-table">
                        <thead>
                            <tr>
                                <th scope="col">Severity</th>
                                <th scope="col">Rule</th>
                                <th scope="col">Finding</th>
                                <th scope="col">Source</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report['findingRows'] as $finding)
                                <tr>
                                    <td>
                                        <x-filament::badge :color="($finding['severity'] ?? '') === 'blocking' ? 'danger' : 'warning'" size="sm">
                                            {{ \Illuminate\Support\Str::headline((string) ($finding['severity'] ?? 'finding')) }}
                                        </x-filament::badge>
                                    </td>
                                    <td class="tala-solver-table__rule">{{ $finding['constraint_label'] }}</td>
                                    <td>{{ $finding['message'] ?? '—' }}</td>
                                    <td>
                                        @if (! empty($finding['source_url']))
                                            <a class="fi-link" href="{{ $finding['source_url'] }}">{{ $finding['source_label'] }}</a>
                                        @else
                                            <span class="tala-solver-table__notes">{{ $finding['source_label'] }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        {{-- 3. Hard Constraint Checklist --}}
        @if ($report['hardConstraintRows'] !== [])
            <section class="tala-task-surface tala-solver-card" aria-labelledby="solver-hard-constraints-heading">
                <header class="tala-solver-card__header">
                    <h2 id="solver-hard-constraints-heading">Hard constraint checklist</h2>
                    <p class="tala-solver-card__caption">Mandatory scheduling rules checked independently across the entire timetable.</p>
                </header>
                <div class="tala-solver-table-wrap">
                    <table class="tala-solver-table">
                        <thead>
                            <tr>
                                <th scope="col">Rule</th>
                                <th scope="col">Status</th>
                                <th scope="col">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report['hardConstraintRows'] as $row)
                                <tr>
                                    <td class="tala-solver-table__rule">{{ $row['label'] }}</td>
                                    <td>
                                        <x-filament::badge :color="$row['color']" size="sm">{{ $row['status'] }}</x-filament::badge>
                                    </td>
                                    <td class="tala-solver-table__notes">{{ $row['notes'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        {{-- 4. Schedule Quality Measures --}}
        @if ($report['hasQualitySection'])
            <section class="tala-task-surface tala-solver-card" aria-labelledby="solver-quality-heading">
                <header class="tala-solver-card__header">
                    <h2 id="solver-quality-heading">Schedule quality</h2>
                    <p class="tala-solver-card__caption">
                        Measured optimization-quality measures ranked in priority order — not predictive accuracy or an accuracy score. Lower values are preferred at each step after earlier priorities tie (higher for Stability preference).
                    </p>
                </header>
                @if ($report['qualityRows'] !== [])
                    <div class="tala-solver-table-wrap">
                        <table class="tala-solver-table">
                            <thead>
                                <tr>
                                    <th scope="col">Measure</th>
                                    <th scope="col" style="text-align: end;">Recorded value</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($report['qualityRows'] as $row)
                                    <tr>
                                        <td class="tala-solver-table__rule">
                                            @if ($row['priority'] !== null)
                                                <span class="tala-solver-priority-pill">{{ $row['priority'] }}</span>
                                            @endif
                                            <span>{{ $row['label'] }}</span>
                                        </td>
                                        <td class="tala-solver-table__value">{{ $row['value'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif
    @endif

    {{-- 5. Flat, Non-Collapsible 1-Row Run Reference Footer --}}
    <footer class="tala-solver-ref-footer" aria-label="Run reference">
        <dl class="tala-solver-ref-row">
            <div class="tala-solver-ref-item">
                <dt>Term</dt>
                <dd>{{ $report['runReference']['term'] }}</dd>
            </div>
            <div class="tala-solver-ref-item">
                <dt>Requested by</dt>
                <dd>{{ $report['runReference']['requestedBy'] }}</dd>
            </div>
            <div class="tala-solver-ref-item">
                <dt>Attempt #</dt>
                <dd>{{ $report['runReference']['attempt'] }}</dd>
            </div>
            <div class="tala-solver-ref-item">
                <dt>Runtime</dt>
                <dd>{{ $report['runReference']['runtime'] }}</dd>
            </div>
        </dl>

        @if ($report['isSystemAdmin'] && $report['adminMetadata'])
            <div class="tala-solver-ref-admin">
                <span>Solver: {{ $report['adminMetadata']['solverVersion'] }}</span>
                <span>Model: {{ $report['adminMetadata']['modelVersion'] }}</span>
                <span>Candidate key: {{ $report['adminMetadata']['candidateKey'] }}</span>
            </div>
        @endif
    </footer>
</div>
