@php
    $facultyOutput = $schedule['is_faculty_output'] ?? false;
    $timetableOutput = $schedule['is_timetable_output'] ?? false;
    $studentOutput = ! $facultyOutput && ! $timetableOutput;
    $timetableFormat = $timetableOutput && ($schedule['timetable_format'] ?? 'list') === 'week' ? 'week' : 'list';
    $isWeeklyTimetablePrint = $timetableOutput && $timetableFormat === 'week';
    $weeklyPresentation = $isWeeklyTimetablePrint ? ($schedule['weekly_presentation'] ?? null) : null;
    $weeklyMaxLanes = (int) ($schedule['weekly_max_lanes'] ?? 1);
    $singleSectionScope = (bool) ($schedule['single_section_scope'] ?? false);
    $useDenseWeeklyColumns = $isWeeklyTimetablePrint && ($weeklyMaxLanes > 4 || empty($weeklyPresentation['slots']) || empty($weeklyPresentation['days']));
    $externallyArranged = $schedule['externally_arranged'] ?? [];
    $studentTerms = collect($schedule['rows'])
        ->pluck('term')
        ->merge(collect($externallyArranged)->pluck('term'))
        ->filter()
        ->unique()
        ->implode('; ');
    $headerSubtitle = match (true) {
        $timetableOutput => collect([
            $schedule['term_label'] ?? null,
            filled($schedule['academic_year'] ?? null) ? 'AY '.$schedule['academic_year'] : null,
            ($schedule['version_state'] ?? null) === \App\Models\PublishedTimetableVersion::StateSuperseded ? 'Superseded' : null,
        ])->filter()->implode(' · '),
        $studentOutput => $studentTerms !== '' ? $studentTerms : ($schedule['version_label'] ?? ''),
        default => $schedule['version_label'].' · '.$schedule['version_state'],
    };
    $resolvedPageOrientation = match (true) {
        $isWeeklyTimetablePrint => 'landscape',
        $timetableOutput => 'portrait',
        default => 'landscape',
    };
    $resolvedPageMargin = $isWeeklyTimetablePrint ? '10mm' : ($schedule['page_margin'] ?? '12mm');
@endphp
<x-official-output-layout
    :title="$schedule['title']"
    :subtitle="$headerSubtitle"
    :generated-at="$schedule['generated_at']"
    :page-orientation="$resolvedPageOrientation"
    :page-margin="$resolvedPageMargin"
    :context="$studentOutput ? null : ($schedule['role_filter_context'] ?? $schedule['owner'])"
    :schedule-presentation="true"
    :back-url="$studentOutput ? ($schedule['back_url'] ?? \App\Filament\Student\Pages\ScheduleView::getUrl(panel: 'student')) : ($schedule['back_url'] ?? null)"
    :back-label="$studentOutput ? 'Back to class schedule' : ($schedule['back_label'] ?? 'Back')"
>
    @if ($timetableOutput)
        <style>
            .official-output-header {
                border-bottom: 2px solid #1f5f2c;
                margin-bottom: 12px;
                padding-bottom: 10px;
            }
            .tt-print-wrap {
                color: #18181b;
                font-variant-numeric: tabular-nums;
            }
            .tt-doc-ref {
                font-size: 9.5px;
                line-height: 1.45;
                color: #52525b;
                margin: 12px 0 0 0;
                padding-top: 8px;
                border-top: 1px solid #e4e4e7;
                font-variant-numeric: tabular-nums;
            }
            .tt-meta-band {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(115px, 1fr));
                gap: 8px 14px;
                background: #f4f4f5;
                border: 1px solid #d4d4d8;
                border-radius: 4px;
                padding: 8px 12px;
                margin-bottom: 12px;
            }
            .tt-meta-item {
                min-width: 0;
            }
            .tt-meta-label {
                display: block;
                font-size: 9px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.06em;
                color: #52525b;
                margin-bottom: 2px;
            }
            .tt-meta-value {
                display: block;
                font-size: 11px;
                font-weight: 600;
                color: #18181b;
                font-variant-numeric: tabular-nums;
                overflow-wrap: break-word;
            }
            .tt-scope-banner {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: space-between;
                gap: 6px 12px;
                margin: 0 0 12px 0;
                padding: 6px 10px;
                border-radius: 4px;
                border: 1px solid #bbf7d0;
                background: #f0fdf4;
                color: #14532d;
                font-size: 11px;
                line-height: 1.4;
            }

            /* === List View Table === */
            .tt-table {
                width: 100%;
                border-collapse: collapse;
                table-layout: fixed;
                font-size: 10.5px;
                line-height: 1.35;
            }
            .tt-table caption {
                caption-side: top;
                text-align: start;
                font-weight: 700;
                font-size: 11px;
                color: #18181b;
                padding: 4px 0 7px 0;
                border-bottom: 1.5px solid #1f5f2c;
            }
            .tt-table thead th {
                text-align: left;
                font-size: 9px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: #27272a;
                background: #f4f4f5;
                padding: 7px 8px;
                border: 0;
                border-bottom: 1.5px solid #1f5f2c;
                white-space: nowrap;
            }
            .tt-table tbody td {
                padding: 7px 8px;
                border: 0;
                border-bottom: 1px solid #e4e4e7;
                vertical-align: top;
                color: #18181b;
                overflow-wrap: break-word;
                word-break: normal;
            }
            .tt-table tbody tr:nth-child(even) {
                background: #fafafa;
            }
            .tt-table tbody tr:last-child td {
                border-bottom: 1px solid #d4d4d8;
            }
            .tt-cell-primary {
                display: block;
                font-weight: 600;
                color: #18181b;
            }
            .tt-cell-sub {
                display: block;
                font-size: 9.5px;
                color: #52525b;
                margin-top: 1px;
                line-height: 1.3;
            }
            .tt-nowrap {
                white-space: nowrap;
            }
            .tt-type-pill {
                display: inline-block;
                padding: 1px 6px;
                border-radius: 3px;
                font-size: 9px;
                font-weight: 700;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                white-space: nowrap;
                border: 1px solid #d4d4d8;
                background: #f4f4f5;
                color: #27272a;
            }
            .tt-type-pill.is-lab {
                border-color: #d97706;
                background: #fffbeb;
                color: #92400e;
            }
            .tt-type-pill.is-lec {
                border-color: #15803d;
                background: #f0fdf4;
                color: #166534;
            }
            .tt-tabular {
                font-variant-numeric: tabular-nums;
            }

            /* === Weekly Grid (Time-axis landscape) === */
            .tt-week-caption {
                display: flex;
                align-items: baseline;
                justify-content: space-between;
                gap: 8px;
                font-size: 11px;
                font-weight: 700;
                color: #18181b;
                padding: 0 0 6px 0;
                margin: 0 0 8px 0;
                border-bottom: 1.5px solid #1f5f2c;
            }
            .tt-week-caption span {
                font-size: 9.5px;
                font-weight: 500;
                color: #52525b;
            }
            .tt-week-grid {
                display: grid;
                grid-template-columns: 52px repeat(var(--tt-days, 6), minmax(0, 1fr));
                border: 1px solid #d4d4d8;
                border-radius: 4px;
                background: #ffffff;
                overflow: hidden;
            }
            .tt-week-axis {
                border-right: 1px solid #d4d4d8;
                background: #fafafa;
            }
            .tt-week-day {
                min-width: 0;
                border-left: 1px solid #e4e4e7;
            }
            .tt-week-day-head {
                font-size: 10px;
                font-weight: 700;
                text-align: center;
                padding: 6px 4px;
                background: #f4f4f5;
                color: #18181b;
                border-bottom: 1px solid #d4d4d8;
            }
            .tt-week-axis-body,
            .tt-week-day-body {
                position: relative;
                height: 510px;
            }
            .tt-week-axis-label {
                position: absolute;
                right: 5px;
                transform: translateY(-50%);
                font-size: 8.5px;
                color: #52525b;
                white-space: nowrap;
            }
            .tt-week-axis-label.is-hour {
                font-weight: 700;
                color: #18181b;
            }
            .tt-week-axis-label.is-first {
                transform: translateY(0);
            }
            .tt-week-axis-label.is-last {
                transform: translateY(-100%);
            }
            .tt-week-rule {
                position: absolute;
                left: 0;
                right: 0;
                border-top: 1px dashed #e4e4e7;
            }
            .tt-week-rule.is-hour {
                border-top: 1px solid #d4d4d8;
            }
            .tt-week-break {
                position: absolute;
                left: 0;
                right: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                background: #f4f4f5;
                border-top: 1px dashed #a1a1aa;
                border-bottom: 1px dashed #a1a1aa;
                color: #52525b;
                font-size: 8.5px;
                font-weight: 600;
                text-align: center;
                padding: 0 4px;
            }
            .tt-week-card {
                position: absolute;
                box-sizing: border-box;
                padding: 3px 4px;
                overflow: hidden;
                border: 1px solid #d4d4d8;
                border-left: 3px solid #15803d;
                border-radius: 3px;
                background: #f0fdf4;
                color: #18181b;
                display: flex;
                flex-direction: column;
                gap: 1px;
                font-size: 8.5px;
                line-height: 1.2;
            }
            .tt-week-card.is-lab {
                border-left-color: #b45309;
                background: #fffbeb;
            }
            .tt-week-card[data-course-color="2"]:not(.is-lab) {
                border-left-color: #b45309;
                background: #fff7ed;
            }
            .tt-week-card[data-course-color="3"]:not(.is-lab) {
                border-left-color: #9f1239;
                background: #fff1f2;
            }
            .tt-week-card[data-course-color="4"]:not(.is-lab) {
                border-left-color: #3f3f46;
                background: #f4f4f5;
            }
            .tt-week-card-head {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 3px;
                font-weight: 700;
                font-size: 8.5px;
            }
            .tt-week-card-code {
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .tt-week-card-badge {
                flex: none;
                font-size: 7.5px;
                font-weight: 700;
                padding: 0 3px;
                border-radius: 2px;
                border: 1px solid rgba(24, 24, 27, 0.2);
                background: rgba(255, 255, 255, 0.75);
            }
            .tt-week-card-title {
                font-weight: 600;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
            }
            .tt-week-card-meta {
                color: #3f3f46;
                font-size: 8px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            /* === Dense Whole-Term Weekly Grid (6-Column Chronological Cards, zero squished lanes) === */
            .tt-week-columns {
                display: grid;
                grid-template-columns: repeat(var(--tt-days, 6), minmax(0, 1fr));
                gap: 6px;
                align-items: start;
            }
            .tt-week-col {
                border: 1px solid #d4d4d8;
                border-radius: 4px;
                background: #ffffff;
                min-width: 0;
            }
            .tt-week-col-head {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 4px;
                padding: 5px 7px;
                background: #f4f4f5;
                border-bottom: 1.5px solid #1f5f2c;
                font-size: 10px;
                font-weight: 700;
                color: #18181b;
            }
            .tt-week-col-head span {
                font-size: 8.5px;
                font-weight: 600;
                color: #52525b;
            }
            .tt-week-col-list {
                display: flex;
                flex-direction: column;
                gap: 4px;
                padding: 5px;
            }
            .tt-week-col-item {
                break-inside: avoid;
                page-break-inside: avoid;
                border: 1px solid #e4e4e7;
                border-left: 3px solid #15803d;
                border-radius: 3px;
                padding: 4px 5px;
                background: #fafafa;
                font-size: 8.5px;
                line-height: 1.25;
            }
            .tt-week-col-item.is-lab {
                border-left-color: #b45309;
                background: #fffbeb;
            }
            .tt-week-col-time {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 4px;
                font-weight: 700;
                color: #18181b;
                font-size: 8px;
                margin-bottom: 1px;
            }
            .tt-week-col-course {
                font-weight: 700;
                color: #18181b;
            }
            .tt-week-col-meta {
                color: #3f3f46;
                font-size: 8px;
            }
            .tt-week-col-empty {
                padding: 8px;
                text-align: center;
                color: #71717a;
                font-size: 9px;
            }

            @media print {
                .tt-print-wrap {
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
                .official-output .tt-table {
                    font-size: 9.5px;
                }
                .official-output .tt-table thead {
                    display: table-header-group;
                }
                .official-output .tt-table thead th {
                    padding: 6px 6px !important;
                    border: 0 !important;
                    border-top: 1.5px solid #1f5f2c !important;
                    border-bottom: 1.5px solid #1f5f2c !important;
                    background: #f4f4f5 !important;
                    color: #18181b !important;
                }
                .official-output .tt-table tbody td {
                    padding: 5.5px 6px !important;
                    border: 0 !important;
                    border-bottom: 1px solid #d4d4d8 !important;
                    overflow-wrap: break-word !important;
                    word-break: normal !important;
                    color: #18181b !important;
                }
                .official-output .tt-table tbody td.tt-nowrap,
                .official-output .tt-table tbody td .tt-nowrap {
                    overflow-wrap: normal !important;
                    white-space: nowrap !important;
                }
                .official-output .tt-table tbody tr:nth-child(even) td {
                    background: #fafafa !important;
                }
                .official-output .tt-table tr {
                    break-inside: avoid;
                    page-break-inside: avoid;
                }
                .tt-week-grid {
                    break-inside: avoid;
                    page-break-inside: avoid;
                }
            }
        </style>
        <div class="tt-print-wrap">
            <div class="tt-meta-band">
                <div class="tt-meta-item">
                    <span class="tt-meta-label">Academic Year</span>
                    <span class="tt-meta-value">{{ $schedule['academic_year'] }}</span>
                </div>
                <div class="tt-meta-item">
                    <span class="tt-meta-label">Term</span>
                    <span class="tt-meta-value">{{ $schedule['term_label'] }}</span>
                </div>
                <div class="tt-meta-item">
                    <span class="tt-meta-label">Layout</span>
                    <span class="tt-meta-value">{{ $isWeeklyTimetablePrint ? 'Weekly grid (Landscape)' : 'List view (Portrait)' }}</span>
                </div>
                <div class="tt-meta-item">
                    <span class="tt-meta-label">Issued</span>
                    <span class="tt-meta-value">{{ $schedule['published_at'] }}</span>
                </div>
                <div class="tt-meta-item">
                    <span class="tt-meta-label">Copy</span>
                    <span class="tt-meta-value">{{ $schedule['owner'] }}</span>
                </div>
                @if (filled($schedule['authority_reference'] ?? null))
                    <div class="tt-meta-item">
                        <span class="tt-meta-label">Approval reference</span>
                        <span class="tt-meta-value">{{ $schedule['authority_reference'] }}</span>
                    </div>
                @endif
            </div>
            @if (filled($schedule['filter_description'] ?? null))
                <p class="tt-scope-banner">
                    <span><strong>Filtered scope:</strong> {{ $schedule['filter_description'] }}</span>
                    <span>{{ count($schedule['rows'] ?? []) }} {{ \Illuminate\Support\Str::plural('meeting', count($schedule['rows'] ?? [])) }}</span>
                </p>
            @endif
        </div>
    @endif

    @if ($facultyOutput)
        <p class="schedule-owner">
            Official teaching schedule for <strong>{{ $schedule['owner'] }}</strong> ({{ $schedule['version_label'] }}).
            @if ($schedule['version_state'] === \App\Models\PublishedTimetableVersion::StateSuperseded)
                <strong>Superseded version — not your current official schedule.</strong>
            @else
                Check My schedule in TALA for any later updates.
            @endif
        </p>
    @elseif ($timetableOutput && $schedule['version_state'] === \App\Models\PublishedTimetableVersion::StateSuperseded)
        <p class="schedule-owner">
            <strong>Superseded historical schedule — not the current official timetable.</strong>
        </p>
    @elseif (! $studentOutput && ! $timetableOutput)
        <p class="schedule-owner">
            This A4 landscape output is bound to <strong>{{ $schedule['version_label'] }}</strong>
            for <strong>{{ $schedule['owner'] }}</strong>.
            Use the Student Hub or Faculty Workspace to confirm later revisions.
        </p>
    @endif
    @if (! $timetableOutput && ($schedule['filter_description'] ?? null))
        <p><strong>Filtered schedule — this is a subset of your official assignments.</strong><br>{{ $schedule['filter_description'] }}</p>
    @endif

    @if ($schedule['rows'] === [] && $externallyArranged === [])
        <div class="schedule-empty">No schedule is available for this exact timetable version.</div>
    @elseif ($studentOutput)
        <p><strong>{{ $schedule['owner'] }}</strong></p>
        @if ($schedule['rows'] !== [])
            <div class="official-output-table finance-responsive-table">
                <table class="schedule-table">
                    <thead><tr><th scope="col">Subject</th><th scope="col">Class</th><th scope="col">Day and time</th><th scope="col">Room</th><th scope="col">Faculty</th></tr></thead>
                    <tbody>
                        @foreach ($schedule['rows'] as $row)
                            <tr>
                                <td data-label="Subject"><div><strong>{{ $row['description'] }}</strong><br>{{ $row['course'] }}</div></td>
                                <td data-label="Class"><div>{{ $row['section'] }}<br>{{ $row['modality'] }}</div></td>
                                <td data-label="Day and time"><div>{{ $row['day'] }}<br>{{ $row['time'] }}</div></td>
                                <td data-label="Room"><div>{{ $row['room'] }}</div></td>
                                <td data-label="Faculty"><div>{{ $row['faculty'] ?: 'Not assigned' }}</div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        @if ($externallyArranged !== [])
            <div class="official-output-table finance-responsive-table" style="margin-top: 1rem;">
                <table class="schedule-table">
                    <caption style="caption-side: top; text-align: start; font-weight: 600; padding: 8px 0; border-bottom: 1px solid #d4d4d8;">Externally arranged classes</caption>
                    <thead><tr><th scope="col">Subject</th><th scope="col">Class</th><th scope="col">Units</th><th scope="col">Faculty / Adviser</th><th scope="col">Arrangement</th></tr></thead>
                    <tbody>
                        @foreach ($externallyArranged as $item)
                            <tr>
                                <td data-label="Subject"><div><strong>{{ $item['description'] }}</strong><br>{{ $item['course'] }}</div></td>
                                <td data-label="Class"><div>{{ $item['section'] }}</div></td>
                                <td data-label="Units"><div>{{ $item['units'] }}</div></td>
                                <td data-label="Faculty / Adviser"><div>{{ $item['faculty'] ?: 'Not assigned' }}</div></td>
                                <td data-label="Arrangement"><div>{{ $item['note'] }}</div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <p>Check Class schedule in the Student Hub for later changes.</p>
        <p class="official-output-meta">Schedule source: {{ $schedule['version_label'] }} · {{ $schedule['version_state'] }}</p>
    @else
        @if ($schedule['rows'] !== [])
            @php
                $showRevisionColumn = collect($schedule['rows'])->contains(
                    fn (array $row): bool => filled($row['revision_marker'] ?? null) && ! in_array($row['revision_marker'], ['Current', 'Initial publication'], true),
                );
            @endphp
            @if ($isWeeklyTimetablePrint && is_array($weeklyPresentation))
                @php
                    $weekDays = $weeklyPresentation['days'] ?? [];
                    $weekMeetings = $weeklyPresentation['meetings'] ?? [];
                    $weekTicks = $weeklyPresentation['ticks'] ?? [];
                    $weekBreaks = $weeklyPresentation['blockedPeriods'] ?? [];
                @endphp
                <div class="tt-print-wrap">
                    <div class="tt-week-caption">
                        <strong>{{ $schedule['identity_line'] ?? $schedule['version_label'] }}</strong>
                        <span>{{ count($weekMeetings) }} {{ \Illuminate\Support\Str::plural('meeting', count($weekMeetings)) }}</span>
                    </div>

                    @if ($useDenseWeeklyColumns)
                        <div class="tt-week-columns" style="--tt-days: {{ max(1, count($weekDays)) }};">
                            @foreach ($weekDays as $day)
                                @php
                                    $dayMeetings = collect($weekMeetings)
                                        ->where('day', $day['number'])
                                        ->sortBy([['start', 'asc'], ['courseCode', 'asc']])
                                        ->values();
                                @endphp
                                <section class="tt-week-col">
                                    <header class="tt-week-col-head">
                                        <strong>{{ $day['label'] }}</strong>
                                        <span>{{ $dayMeetings->count() }}</span>
                                    </header>
                                    <div class="tt-week-col-list">
                                        @forelse ($dayMeetings as $meeting)
                                            @php
                                                $badge = $meeting['componentBadge'] ?? (($meeting['component'] ?? '') === 'Laboratory' ? 'LAB' : 'LEC');
                                                $isLab = $badge === 'LAB';
                                                $secLabel = trim((string) ($meeting['sectionDisplay'] ?? $meeting['class'] ?? ''));
                                            @endphp
                                            <article class="tt-week-col-item {{ $isLab ? 'is-lab' : '' }}">
                                                <div class="tt-week-col-time">
                                                    <span>{{ $meeting['time'] }}</span>
                                                    <span class="tt-week-card-badge">{{ $badge }}</span>
                                                </div>
                                                <div class="tt-week-col-course">{{ $meeting['courseCode'] }}@if (filled($meeting['courseTitle'] ?? null)) · {{ $meeting['courseTitle'] }}@endif</div>
                                                @if (! $singleSectionScope && $secLabel !== '')
                                                    <div class="tt-week-col-meta">Sec: {{ $secLabel }}</div>
                                                @endif
                                                <div class="tt-week-col-meta">{{ $meeting['room'] }} · {{ $meeting['faculty'] ?: 'Not assigned' }}</div>
                                            </article>
                                        @empty
                                            <div class="tt-week-col-empty">No meetings</div>
                                        @endforelse
                                    </div>
                                </section>
                            @endforeach
                        </div>
                    @else
                        <div class="tt-week-grid" style="--tt-days: {{ max(1, count($weekDays)) }};">
                            <div class="tt-week-axis" aria-hidden="true">
                                <div class="tt-week-day-head">Time</div>
                                <div class="tt-week-axis-body">
                                    @foreach ($weekTicks as $tick)
                                        @if (str_contains($tick['label'], ':00 ') || $loop->first || $loop->last)
                                            <span @class([
                                                'tt-week-axis-label',
                                                'is-hour' => str_contains($tick['label'], ':00 '),
                                                'is-first' => $loop->first,
                                                'is-last' => $loop->last,
                                            ]) style="top: {{ $tick['offset'] }}%;">{{ $tick['label'] }}</span>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                            @foreach ($weekDays as $day)
                                <section class="tt-week-day" aria-label="{{ $day['label'] }}">
                                    <div class="tt-week-day-head">{{ $day['label'] }}</div>
                                    <div class="tt-week-day-body">
                                        @foreach ($weekTicks as $tick)
                                            <span @class(['tt-week-rule', 'is-hour' => str_contains($tick['label'], ':00 ')]) style="top: {{ $tick['offset'] }}%;"></span>
                                        @endforeach
                                        @foreach ($weekBreaks as $period)
                                            @if (($period['day'] ?? null) === $day['number'])
                                                <div class="tt-week-break" style="top: {{ $period['top'] }}%; height: {{ $period['height'] }}%;">
                                                    {{ ($period['label'] ?? '') === 'Approved break' ? 'Lunch break' : ($period['label'] ?? 'Break') }}
                                                </div>
                                            @endif
                                        @endforeach
                                        @foreach ($weekMeetings as $meeting)
                                            @if (($meeting['plottable'] ?? false) && ($meeting['day'] ?? null) === $day['number'])
                                                @php
                                                    $badge = $meeting['componentBadge'] ?? (($meeting['component'] ?? '') === 'Laboratory' ? 'LAB' : 'LEC');
                                                    $isLab = $badge === 'LAB';
                                                    $lanes = max(1, (int) ($meeting['lanes'] ?? 1));
                                                    $lane = (int) ($meeting['lane'] ?? 0);
                                                    $secLabel = trim((string) ($meeting['sectionDisplay'] ?? $meeting['class'] ?? ''));
                                                    $colorSlot = $weeklyPresentation['courseColors'][$meeting['courseCode'] ?? ''] ?? ((crc32((string) ($meeting['courseCode'] ?? '')) % 4) + 1);
                                                @endphp
                                                <div
                                                    class="tt-week-card {{ $isLab ? 'is-lab' : '' }}"
                                                    data-course-color="{{ $colorSlot }}"
                                                    style="top: {{ $meeting['top'] }}%; height: {{ $meeting['height'] }}%; left: {{ $lane / $lanes * 100 }}%; width: {{ 100 / $lanes }}%;"
                                                >
                                                    <div class="tt-week-card-head">
                                                        <span class="tt-week-card-code">{{ $meeting['courseCode'] }}</span>
                                                        <span class="tt-week-card-badge">{{ $badge }}</span>
                                                    </div>
                                                    @if (filled($meeting['courseTitle'] ?? null))
                                                        <div class="tt-week-card-title">{{ $meeting['courseTitle'] }}</div>
                                                    @endif
                                                    @if (! $singleSectionScope && $secLabel !== '')
                                                        <div class="tt-week-card-meta">{{ $secLabel }}</div>
                                                    @endif
                                                    <div class="tt-week-card-meta">{{ $meeting['time'] }} · {{ $meeting['room'] }}</div>
                                                    <div class="tt-week-card-meta">{{ $meeting['faculty'] ?: 'Not assigned' }}</div>
                                                </div>
                                            @endif
                                        @endforeach
                                    </div>
                                </section>
                            @endforeach
                        </div>
                    @endif
                </div>
            @elseif ($timetableOutput)
                <div class="official-output-table tt-print-wrap">
                    <table class="tt-table">
                        <caption>{{ $schedule['identity_line'] ?? $schedule['version_label'] }}</caption>
                        <colgroup>
                            @if ($showRevisionColumn)
                                <col style="width: 24%;">
                                <col style="width: 13%;">
                                <col style="width: 7%;">
                                <col style="width: 17%;">
                                <col style="width: 14%;">
                                <col style="width: 16%;">
                                <col style="width: 9%;">
                            @else
                                <col style="width: 27%;">
                                <col style="width: 14%;">
                                <col style="width: 7%;">
                                <col style="width: 18%;">
                                <col style="width: 15%;">
                                <col style="width: 19%;">
                            @endif
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col">Course</th>
                                <th scope="col">Section</th>
                                <th scope="col">Type</th>
                                <th scope="col">Schedule</th>
                                <th scope="col">Room &amp; Mode</th>
                                <th scope="col">Faculty</th>
                                @if ($showRevisionColumn)<th scope="col">Notes</th>@endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($schedule['rows'] as $row)
                                @php
                                    $isLabRow = str_contains(strtolower((string) ($row['component'] ?? '')), 'lab');
                                @endphp
                                <tr>
                                    <td>
                                        <span class="tt-cell-primary">{{ $row['course'] }}</span>
                                        <span class="tt-cell-sub">{{ $row['description'] }}</span>
                                    </td>
                                    <td><span class="tt-cell-primary">{{ $row['section'] }}</span></td>
                                    <td class="tt-nowrap">
                                        <span class="tt-type-pill {{ $isLabRow ? 'is-lab' : 'is-lec' }}">{{ $row['component'] }}</span>
                                    </td>
                                    <td>
                                        <span class="tt-cell-primary tt-nowrap">{{ $row['day'] }}</span>
                                        <span class="tt-cell-sub tt-tabular tt-nowrap">{{ $row['time'] }}</span>
                                    </td>
                                    <td>
                                        <span class="tt-cell-primary">{{ $row['room'] }}</span>
                                        <span class="tt-cell-sub">{{ $row['modality'] }}</span>
                                    </td>
                                    <td>{{ $row['faculty'] ?: 'Not assigned' }}</td>
                                    @if ($showRevisionColumn)<td>{{ filled($row['revision_marker'] ?? null) ? $row['revision_marker'] : '—' }}</td>@endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="official-output-table">
                    <table class="schedule-table">
                        <caption style="caption-side: top; text-align: start; font-weight: 600; padding: 8px 0; border-bottom: 1px solid #d4d4d8;">{{ $schedule['identity_line'] ?? $schedule['version_label'] }}</caption>
                        <thead>
                            <tr>
                                @unless ($facultyOutput)<th scope="col">Term</th>@endunless
                                <th scope="col">Subject</th>
                                @unless ($facultyOutput)<th scope="col">Code</th>@endunless
                                <th scope="col">Class</th>
                                <th scope="col">Component</th>
                                <th scope="col">Day</th>
                                <th scope="col">Time</th>
                                <th scope="col">Room</th>
                                <th scope="col">Modality</th>
                                @unless ($facultyOutput)<th scope="col">Faculty</th>@endunless
                                @if ($showRevisionColumn)<th scope="col">Notes</th>@endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($schedule['rows'] as $row)
                                <tr>
                                    @unless ($facultyOutput)<td>{{ $row['term'] }}</td>@endunless
                                    <td><span class="schedule-course-title">{{ $row['description'] }}</span>@if ($facultyOutput)<br><span class="schedule-source-code">{{ $row['course'] }}</span>@endif</td>
                                    @unless ($facultyOutput)<td class="schedule-source-code">{{ $row['course'] }}</td>@endunless
                                    <td>{{ $row['section'] }}</td>
                                    <td>{{ $row['component'] }}</td>
                                    <td>{{ $row['day'] }}</td>
                                    <td class="schedule-meeting-time">{{ $row['time'] }}</td>
                                    <td>{{ $row['room'] }}</td>
                                    <td>{{ $row['modality'] }}</td>
                                    @unless ($facultyOutput)<td>{{ $row['faculty'] ?: 'Not assigned' }}</td>@endunless
                                    @if ($showRevisionColumn)<td>{{ filled($row['revision_marker'] ?? null) ? $row['revision_marker'] : '—' }}</td>@endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
        @if ($facultyOutput && $externallyArranged !== [])
            <div class="official-output-table" style="margin-top: 1rem;">
                <table class="schedule-table">
                    <caption style="caption-side: top; text-align: start; font-weight: 600; padding: 8px 0; border-bottom: 1px solid #d4d4d8;">Externally arranged classes</caption>
                    <thead>
                        <tr>
                            <th scope="col">Subject</th>
                            <th scope="col">Class</th>
                            <th scope="col">Role</th>
                            <th scope="col">Units</th>
                            <th scope="col">Arrangement</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($externallyArranged as $item)
                            <tr>
                                <td><span class="schedule-course-title">{{ $item['description'] }}</span><br><span class="schedule-source-code">{{ $item['course'] }}</span></td>
                                <td>{{ $item['section'] }}</td>
                                <td>{{ $item['role'] }}</td>
                                <td>{{ $item['units'] }}</td>
                                <td>{{ $item['note'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        @if ($timetableOutput)
            <p class="official-output-meta tt-doc-ref">
                Document ref: {{ $schedule['reference'] }} · {{ $schedule['version_label'] }} ({{ $schedule['version_state'] }}) · Issued {{ $schedule['published_at'] }}@if (filled($schedule['solver_generated_at'] ?? null)) · Schedule built {{ $schedule['solver_generated_at'] }}@endif · Use Term Planning to confirm the current official version and later revisions · Generated through TALA
            </p>
        @endif
    @endif
</x-official-output-layout>
