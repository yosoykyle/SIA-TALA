<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $schedule['title'] }}</title>
    <style>
        body { color: #111827; font-family: Arial, sans-serif; margin: 2rem; }
        h1 { margin-bottom: .25rem; }
        .meta { color: #4b5563; margin-bottom: 1.5rem; }
        table { border-collapse: collapse; font-size: .78rem; width: 100%; }
        th, td { border: 1px solid #d1d5db; padding: .45rem; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        .empty { border: 1px solid #d1d5db; padding: 1rem; }
        .print-button { margin-bottom: 1rem; padding: .6rem 1rem; }
        @media print {
            body { margin: 0; }
            .print-button { display: none; }
        }
    </style>
</head>
<body>
    <button class="print-button" type="button" onclick="window.print()">Print / Save as PDF</button>
    <h1>{{ $schedule['title'] }}</h1>
    <div class="meta">
        <strong>{{ $schedule['owner'] }}</strong><br>
        Generated {{ $schedule['generated_at'] }} from current published schedule records.
    </div>

    @if ($schedule['rows'] === [])
        <div class="empty">No current published schedule is available.</div>
    @else
        <table>
            <thead>
                <tr>
                    <th>Term</th>
                    <th>Course</th>
                    <th>Description</th>
                    <th>Section</th>
                    <th>Component</th>
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
                        <td>{{ $row['faculty'] ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
