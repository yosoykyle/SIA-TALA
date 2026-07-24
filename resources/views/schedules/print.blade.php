<x-official-output-layout
    :title="$schedule['title']"
    :subtitle="'Current published schedule for '.$schedule['owner']"
    :generated-at="$schedule['generated_at']"
>
    <p class="schedule-owner">
        This document shows the current published schedule available to <strong>{{ $schedule['owner'] }}</strong>.
        Use the Student Hub or Faculty Workspace to confirm later revisions.
    </p>

    @if ($schedule['rows'] === [])
        <div class="schedule-empty">No current published schedule is available for this account.</div>
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
