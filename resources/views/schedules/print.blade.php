<x-official-output-layout
    :title="$schedule['title']"
    :subtitle="$schedule['version_label'].' · '.$schedule['version_state']"
    :generated-at="$schedule['generated_at']"
    page-orientation="landscape"
>
    <p class="schedule-owner">
        This A4 landscape output is bound to <strong>{{ $schedule['version_label'] }}</strong>
        for <strong>{{ $schedule['owner'] }}</strong>.
        @if ($schedule['version_state'] === \App\Models\PublishedTimetableVersion::StateSuperseded)
            <strong>Superseded history — not the current official timetable.</strong>
        @else
            Use the Student Hub or Faculty Workspace to confirm later revisions.
        @endif
    </p>

    @if ($schedule['rows'] === [])
        <div class="schedule-empty">No schedule is available for this exact timetable version.</div>
    @else
        <div class="official-output-table">
            <table class="schedule-table">
                <thead>
                    <tr>
                        <th>Term</th>
                        <th>Course</th>
                        <th>Course Title</th>
                        <th>Section</th>
                        <th>Class Component</th>
                        <th>Day</th>
                        <th>Time</th>
                        <th>Room</th>
                        <th>Modality</th>
                        <th>Faculty</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($schedule['rows'] as $row)
                        <tr>
                            <td>{{ $row['term'] }}</td>
                            <td>{{ $row['course'] }}</td>
                            <td>{{ $row['description'] }}</td>
                            <td>{{ $row['section'] }}</td>
                            <td>{{ $row['component'] }}</td>
                            <td>{{ $row['day'] }}</td>
                            <td>{{ $row['time'] }}</td>
                            <td>{{ $row['room'] }}</td>
                            <td>{{ $row['modality'] }}</td>
                            <td>{{ $row['faculty'] ?: 'Not assigned' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-official-output-layout>
