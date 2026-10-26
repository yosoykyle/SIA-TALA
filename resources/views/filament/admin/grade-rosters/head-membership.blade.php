@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
<div class="academic-head-membership">
    <div class="faculty-membership-context">
        @if ($isCurrent)<strong>{{ number_format($count) }} {{ str('enrolled student')->plural($count) }}</strong>@else<strong>Membership needs an update</strong>@endif
        <span>{{ $roster->teachingAssignment?->faculty?->getFilamentName() ?? $roster->faculty?->getFilamentName() ?? 'Faculty not recorded' }} · Designated faculty</span>
        <span class="academic-roster-state">{{ match ($roster->state) { \App\Models\GradeRoster::StateDraft => 'Draft', \App\Models\GradeRoster::StateSubmitted => 'Submitted', \App\Models\GradeRoster::StateReturned => 'Returned', \App\Models\GradeRoster::StateReleased => 'Posted and released', default => 'Late / not submitted' } }}</span>
    </div>
    <section class="faculty-membership-list tala-task-surface" aria-labelledby="head-membership-heading">
        <h2 id="head-membership-heading">Current class members</h2>
        @if (! $isCurrent)
            <div role="status"><h3>Class membership needs an update</h3><p>Ask the Registrar to refresh this class roster, then reload. The saved roster no longer matches current official enrollment.</p></div>
        @elseif ($count === 0)
            <p>No officially enrolled students are currently assigned to this class.</p>
        @else
            <table class="faculty-membership-table">
                <thead><tr><th scope="col">Student number</th><th scope="col">Legal name</th><th scope="col">Program</th></tr></thead>
                <tbody>
                    @foreach ($roster->rows as $row)
                        @php($student = $row->courseEnrollment?->enrollment?->studentProfile)
                        <tr>
                            <td data-label="Student number">{{ $student?->student_number }}</td>
                            <th scope="row" data-label="Legal name">{{ collect([$student?->last_name, collect([$student?->first_name, $student?->middle_name])->filter()->implode(' ')])->filter()->implode(', ') }}</th>
                            <td data-label="Program">{{ $student?->program?->code ?? 'Not recorded' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="faculty-membership-note">Current official class membership. @if ($roster->state === \App\Models\GradeRoster::StateReleased)Released results are available below.@else Draft grades are not shown here.@endif</p>
        @endif
    </section>
    <details class="faculty-membership-source">
        <summary>Roster source details</summary>
        <dl>
            <div><dt>Assignment approval</dt><dd>{{ $roster->teachingAssignment?->authority_reference ?? 'Not recorded' }}</dd></div>
            <div><dt>Roster version</dt><dd>{{ $roster->current_version_number }}</dd></div>
            <div><dt>Membership reference</dt><dd>{{ $roster->membership_signature ?? 'Not recorded' }}</dd></div>
            @if ($roster->submitted_at)<div><dt>Submitted</dt><dd>{{ \App\Support\DisplayDateTime::format($roster->submitted_at) }}</dd></div>@endif
            @if ($roster->released_at)<div><dt>Released</dt><dd>{{ \App\Support\DisplayDateTime::format($roster->released_at) }}</dd></div>@endif
            @if (filled($roster->return_reason))<div><dt>Return reason</dt><dd>{{ $roster->return_reason }}</dd></div>@endif
        </dl>
    </details>
</div>
