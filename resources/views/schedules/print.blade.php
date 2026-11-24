@php
    $facultyOutput = $schedule['is_faculty_output'] ?? false;
    $timetableOutput = $schedule['is_timetable_output'] ?? false;
    $studentOutput = ! $facultyOutput && ! $timetableOutput;
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
@endphp
<x-official-output-layout
    :title="$schedule['title']"
    :subtitle="$headerSubtitle"
    :generated-at="$schedule['generated_at'].($facultyOutput ? ' (Asia/Manila)' : '')"
    page-orientation="landscape"
    :page-margin="$schedule['page_margin'] ?? '14mm'"
    :context="$studentOutput ? null : ($schedule['role_filter_context'] ?? $schedule['owner'])"
    :schedule-presentation="true"
    :back-url="$studentOutput ? ($schedule['back_url'] ?? \App\Filament\Student\Pages\ScheduleView::getUrl(panel: 'student')) : ($schedule['back_url'] ?? null)"
    :back-label="$studentOutput ? 'Back to class schedule' : ($schedule['back_label'] ?? 'Back')"
>
    @if ($timetableOutput)
        <style>
            @media print {
                .tt-print-wrap {
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
                .tt-table thead {
                    display: table-header-group;
                }
                .tt-table tr {
                    break-inside: avoid;
                    page-break-inside: avoid;
                }
            }
            .official-output-header {
                border-bottom: 2px solid #1f5f2c;
                margin-bottom: 16px;
                padding-bottom: 14px;
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
                grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
                gap: 8px 14px;
                background: #f4f4f5;
                border: 1px solid #d4d4d8;
                border-radius: 4px;
                padding: 8px 12px;
                margin-bottom: 14px;
            }
            .tt-meta-item {
                min-width: 0;
            }
            .tt-meta-label {
                display: block;
                font-size: 9px;
                font-weight: 600;
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
                word-break: break-word;
            }
            .tt-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 11px;
                line-height: 1.35;
            }
            .tt-table caption {
                caption-side: top;
                text-align: start;
                font-weight: 600;
                font-size: 11.5px;
                color: #18181b;
                padding: 6px 0 8px 0;
                border-bottom: 1.5px solid #1f5f2c;
            }
            .tt-table thead th {
                text-align: left;
                font-size: 9.5px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: #3f3f46;
                background: #f4f4f5;
                padding: 8px 10px;
                border-bottom: 1px solid #d4d4d8;
                white-space: nowrap;
            }
            .tt-table tbody td {
                padding: 8px 10px;
                border-bottom: 1px solid #e4e4e7;
                vertical-align: top;
                color: #18181b;
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
                font-size: 10px;
                color: #52525b;
                margin-top: 1px;
            }
            .tt-tabular {
                font-variant-numeric: tabular-nums;
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
    @if ($schedule['filter_description'] ?? null)
        <p><strong>Filtered schedule — this is a subset of your official assignments.</strong><br>{{ $schedule['filter_description'] }}</p>
    @endif

    @if ($schedule['rows'] === [] && $externallyArranged === [])
        <div class="schedule-empty">No schedule is available for this exact timetable version.</div>
    @elseif ($studentOutput)
        <p><strong>{{ $schedule['owner'] }}</strong> · Times in Asia/Manila</p>
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
            @if ($timetableOutput)
                <div class="official-output-table tt-print-wrap">
                    <table class="tt-table">
                        <caption>{{ $schedule['identity_line'] ?? $schedule['version_label'] }}</caption>
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
                                <tr>
                                    <td>
                                        <span class="tt-cell-primary">{{ $row['course'] }}</span>
                                        <span class="tt-cell-sub">{{ $row['description'] }}</span>
                                    </td>
                                    <td>{{ $row['section'] }}</td>
                                    <td>{{ $row['component'] }}</td>
                                    <td>
                                        <span class="tt-cell-primary">{{ $row['day'] }}</span>
                                        <span class="tt-cell-sub tt-tabular">{{ $row['time'] }}</span>
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
