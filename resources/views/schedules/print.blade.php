@php($facultyOutput = $schedule['is_faculty_output'] ?? false)
<x-official-output-layout
    :title="$schedule['title']"
    :subtitle="$schedule['version_label'].' · '.$schedule['version_state']"
    :generated-at="$schedule['generated_at'].($facultyOutput ? ' (Asia/Manila)' : '')"
    page-orientation="landscape"
    :page-margin="$schedule['page_margin'] ?? '14mm'"
    :context="$schedule['role_filter_context'] ?? $schedule['owner']"
    :tactile-toolbar="true"
    :schedule-presentation="true"
    :back-url="$schedule['back_url'] ?? null"
    :back-label="$schedule['back_label'] ?? 'Back'"
>
    @if ($schedule['is_timetable_output'] ?? false)
        <div class="finance-grid">
            <div><strong>Academic Year</strong><br>{{ $schedule['academic_year'] }}</div>
            <div><strong>Exact Term</strong><br>{{ $schedule['term_label'] }}</div>
            <div><strong>Reference</strong><br>{{ $schedule['reference'] }}</div>
            <div><strong>Approval reference</strong><br>{{ $schedule['authority_reference'] ?: 'Not recorded' }}</div>
            <div><strong>Published</strong><br>{{ $schedule['published_at'] }}</div>
            <div><strong>Solver generated</strong><br>{{ $schedule['solver_generated_at'] }}</div>
        </div>
    @endif

    <p class="schedule-owner">
        This A4 landscape output is bound to <strong>{{ $schedule['version_label'] }}</strong>
        for <strong>{{ $schedule['owner'] }}</strong>.
        @if ($schedule['version_state'] === \App\Models\PublishedTimetableVersion::StateSuperseded)
            <strong>Superseded history — not the current official timetable.</strong>
        @elseif ($schedule['is_timetable_output'] ?? false)
            Use Term Planning to confirm the current official version and later revisions.
        @else
            Use the Student Hub or Faculty Workspace to confirm later revisions.
        @endif
    </p>
    @if ($schedule['filter_description'] ?? null)
        <p><strong>Filtered schedule — this is a subset of your official assignments.</strong><br>{{ $schedule['filter_description'] }}</p>
    @endif

    @if ($schedule['rows'] === [])
        <div class="schedule-empty">No schedule is available for this exact timetable version.</div>
    @else
        <div class="official-output-table">
            <table class="schedule-table">
                <thead>
                    <tr>
                        <th colspan="{{ $facultyOutput ? 8 : 11 }}">{{ $schedule['identity_line'] ?? $schedule['version_label'] }}</th>
                    </tr>
                    <tr>
                        @unless ($facultyOutput)<th>Term</th>@endunless
                        <th>Course</th>
                        @unless ($facultyOutput)<th>Course code</th>@endunless
                        <th>Class</th>
                        <th>Component</th>
                        <th>Day</th>
                        <th>Time</th>
                        <th>Room</th>
                        <th>Modality</th>
                        @unless ($facultyOutput)<th>Faculty</th>@endunless
                        <th>Revision</th>
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
                            <td>{{ $row['revision_marker'] ?? 'Current' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-official-output-layout>
