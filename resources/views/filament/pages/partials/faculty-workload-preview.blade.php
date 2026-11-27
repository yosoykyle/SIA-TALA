@php
    use App\Actions\Scheduling\FacultyTermWorkloadProjection;
    use Illuminate\Support\Str;

    $exclusive = $preview['exclusive'];
    $shared = $preview['shared'];
    $hasWarning = (bool) ($preview['has_warning'] ?? false);

    $unitCapLabel = ! empty($preview['registrar_not_teaching'])
        ? '0 units (excluded)'
        : FacultyTermWorkloadProjection::formatNumber((float) $preview['unit_cap']).' units'.(! empty($preview['unit_cap_is_override']) ? ' (override)' : '');

    $availableMinutes = $preview['available_minutes'];
    $availableLabel = match ($preview['available_source'] ?? 'no_calendar') {
        'excluded' => '0 hrs/week (excluded)',
        'cannot_teach' => '0 hrs/week (unavailable)',
        'submitted_windows' => FacultyTermWorkloadProjection::formatHours((int) $availableMinutes).' hrs/week (submitted)',
        'submitted_any' => FacultyTermWorkloadProjection::formatHours((int) $availableMinutes).' hrs/week (all school hours)',
        'submitted_legacy' => FacultyTermWorkloadProjection::formatHours((int) $availableMinutes).' hrs/week (open)',
        'school_grid' => FacultyTermWorkloadProjection::formatHours((int) $availableMinutes).' hrs/week (school hours · no reply)',
        default => 'No active calendar',
    };

    $exclusiveSummary = sprintf(
        '%d %s · %s %s · %s hrs/week',
        $exclusive['classes'],
        Str::plural('class', $exclusive['classes']),
        FacultyTermWorkloadProjection::formatNumber((float) $exclusive['units']),
        (float) $exclusive['units'] === 1.0 ? 'unit' : 'units',
        FacultyTermWorkloadProjection::formatHours((int) $exclusive['weekly_minutes']),
    );

    $sharedSummary = sprintf(
        '%d %s · %s %s · %s hrs/week',
        $shared['classes'],
        Str::plural('class', $shared['classes']),
        FacultyTermWorkloadProjection::formatNumber((float) $shared['units']),
        (float) $shared['units'] === 1.0 ? 'unit' : 'units',
        FacultyTermWorkloadProjection::formatHours((int) $shared['weekly_minutes']),
    );
@endphp

<div @class(['tala-workload-preview', 'is-warning' => $hasWarning]) role="region" aria-label="Term workload preview">
    <div class="tala-workload-preview__header">
        <span class="tala-workload-preview__title">Term workload preview</span>
        <span class="tala-workload-preview__caps tabular-nums">
            Cap: <strong>{{ $unitCapLabel }}</strong>
            <span aria-hidden="true">·</span>
            Open: <strong>{{ $availableLabel }}</strong>
        </span>
    </div>

    <div class="tala-workload-preview__grid">
        <div @class(['tala-workload-preview__bucket', 'is-warning' => $hasWarning])>
            <div class="tala-workload-preview__bucket-label">Sole-teacher (exclusive)</div>
            <div class="tala-workload-preview__bucket-value tabular-nums">{{ $exclusiveSummary }}</div>
            <div class="tala-workload-preview__bucket-hint">
                @if ($exclusive['classes'] > 0 && $exclusive['longest_meeting_minutes'] > 0)
                    Only available teacher · longest class {{ FacultyTermWorkloadProjection::formatDuration((int) $exclusive['longest_meeting_minutes']) }}
                @else
                    Only available qualified teacher for this term
                @endif
            </div>
        </div>

        <div class="tala-workload-preview__bucket">
            <div class="tala-workload-preview__bucket-label">Shared-pool</div>
            <div class="tala-workload-preview__bucket-value tabular-nums">{{ $sharedSummary }}</div>
            <div class="tala-workload-preview__bucket-hint">
                Shared with other qualified teachers
            </div>
        </div>
    </div>

    @if ($hasWarning)
        <div class="tala-workload-preview__warning" role="status">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="tala-workload-preview__warning-icon" aria-hidden="true" />
            <p class="tala-workload-preview__warning-text">
                {{ implode(' ', $preview['warning_reasons']) }}
                You can still save and assign backup teachers to share the load.
            </p>
        </div>
    @endif
</div>
