@php
    /** @var \App\Models\ScheduleGenerationRun $record */
    $record = isset($run) && $run instanceof \App\Models\ScheduleGenerationRun
        ? $run
        : $this->getRecord();
    $contextTermId = property_exists($this, 'termId') ? $this->termId : $record->term_id;
    $report = \App\Filament\Resources\ScheduleGenerationRuns\Schemas\ScheduleGenerationRunInfolist::reportData($record, $contextTermId);
    $publishedVersionBadgeColor = ($record->isPublished() && $record->term?->state !== \App\Models\Term::StateClosed)
        ? 'success'
        : 'gray';
@endphp

<div class="tala-solver-report" aria-label="Solver report summary and checks"
    @if ($report['isActiveRun']) wire:poll.5s.visible="refreshScheduleRun" @endif>

    @if ($report['isActiveRun'])
        <x-tala-operation-status :run="$report['run']" />
        @if ($report['recovery'])
            <section class="tala-task-surface tala-solver-card" aria-labelledby="solver-active-recovery-heading">
                <header class="tala-solver-card__header">
                    <h2 id="solver-active-recovery-heading">Service recovery</h2>
                    <p class="tala-solver-card__caption">Recovery details recorded for this queued attempt.</p>
                </header>
                <div class="tala-solver-card__body">
                    <dl class="tala-solver-kv-grid">
                        <div class="tala-solver-kv-item tala-solver-kv-item--full tala-solver-kv-item--flush">
                            <dt>Source attempt</dt>
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
                    </dl>
                </div>
            </section>
        @endif
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
                                        <x-filament::badge :color="$publishedVersionBadgeColor" size="sm">Version {{ $report['publishedVersionNumber'] }}</x-filament::badge>
                                    @endif
                                </div>
                            </dd>
                        </div>
                        <div class="tala-solver-kv-item">
                            <dt>Published date</dt>
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
                            <dt>Last checked</dt>
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
                        Ranked optimization-quality measures, not predictive accuracy or an accuracy score. Lower values are preferred when earlier priorities tie (except stability preference, where higher is better).
                    </p>
                </header>
                @if ($report['qualityRows'] !== [])
                    <div class="tala-solver-table-wrap">
                        <table class="tala-solver-table">
                            <thead>
                                <tr>
                                    <th scope="col">Measure</th>
                                    <th scope="col" class="tala-solver-table__value-head">Recorded value</th>
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
