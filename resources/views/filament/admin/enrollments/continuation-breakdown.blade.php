@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
@php
    $statusFilter = $statusFilter ?? 'all';
    $allStudents = $summary['students'] ?? [];
    $filteredStudents = $statusFilter === 'all'
        ? $allStudents
        : array_values(array_filter(
            $allStudents,
            fn (array $student): bool => $student['status'] === $statusFilter,
        ));
    $enrolledCount = (int) ($summary['enrolled_count'] ?? 0);
    $didNotProceedCount = (int) ($summary['did_not_proceed_count'] ?? 0);
    $inProgressCount = (int) ($summary['in_progress_count'] ?? 0);
@endphp
<div class="registrar-continuation-breakdown">
    <dl class="tala-context-inset registrar-continuation-breakdown__metrics">
        <div>
            <dt>Enrolled this term</dt>
            <dd>{{ number_format($enrolledCount) }}</dd>
        </div>
        <div>
            <dt>Did not proceed</dt>
            <dd>{{ number_format($didNotProceedCount) }}</dd>
        </div>
        @if ($inProgressCount > 0)
            <div>
                <dt>Still in progress</dt>
                <dd>{{ number_format($inProgressCount) }}</dd>
            </div>
        @endif
    </dl>

    @if ($filteredStudents === [])
        <div class="registrar-continuation-breakdown__empty">
            <h3 id="registrar-continuation-breakdown-heading">No matching students</h3>
            <p>{{ $allStudents === [] ? ($summary['empty_note'] ?? 'No prior-term continuing students found.') : 'Try another status filter to see the rest of the continuing cohort.' }}</p>
        </div>
    @else
        <p id="registrar-continuation-breakdown-heading" class="registrar-continuation-breakdown__caption">
            Showing {{ count($filteredStudents) }} of {{ count($allStudents) }} continuing {{ \Illuminate\Support\Str::plural('student', count($allStudents)) }}
        </p>
        <div class="registrar-continuation-breakdown__table-wrap" tabindex="0" role="region" aria-labelledby="registrar-continuation-breakdown-heading">
            <table class="tala-data-table registrar-continuation-breakdown__table">
                <thead>
                    <tr>
                        <th scope="col">Student</th>
                        <th scope="col">Program</th>
                        <th scope="col">Prior year</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($filteredStudents as $student)
                        @php
                            $badgeColor = match ($student['status']) {
                                \App\Actions\Enrollment\EnrollmentContinuationSummary::StatusEnrolled => 'success',
                                \App\Actions\Enrollment\EnrollmentContinuationSummary::StatusInProgress => 'gray',
                                default => 'warning',
                            };
                        @endphp
                        <tr>
                            <th scope="row">
                                <span class="registrar-continuation-breakdown__name">{{ $student['student_name'] }}</span>
                                @if (filled($student['student_number']))
                                    <span class="tala-data-table-secondary">{{ $student['student_number'] }}</span>
                                @endif
                            </th>
                            <td>
                                <span>{{ $student['program_code'] }}</span>
                                @if ($student['program_name'] !== $student['program_code'] && $student['program_name'] !== 'Unassigned')
                                    <span class="tala-data-table-secondary">{{ $student['program_name'] }}</span>
                                @endif
                            </td>
                            <td>
                                <span>{{ $student['prior_year_label'] }}</span>
                                @if (filled($student['progression_label']))
                                    <span class="tala-data-table-secondary">{{ $student['progression_label'] }}</span>
                                @endif
                            </td>
                            <td>
                                <x-filament::badge :color="$badgeColor">{{ $student['status_label'] }}</x-filament::badge>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
