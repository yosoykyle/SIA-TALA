@php($subjectsHeading = $subjectsHeading ?? 'Proposed subjects')
@php($showsSubjectDetail = $isStudent ?? false)
<section aria-labelledby="proposed-subjects-heading">
    <div class="student-registration-subjects-heading">
        <h2 id="proposed-subjects-heading">{{ $subjectsHeading }}</h2>
        @if (($showSummary ?? true) || ! $showsSubjectDetail)<p>{{ $proposal->items->count() }} {{ $proposal->items->count() === 1 ? 'subject' : 'subjects' }} · {{ (float) $proposal->items->sum('units_snapshot') }} units</p>@endif
        @if ($preparing)<p>Subjects may change before your enrollment is ready to confirm.</p>@endif
    </div>
    {{-- #110 item 7 (owner decision Nov 27, 2026): Applicants keep the enrollment steps here; the subject breakdown appears in the Student account after enrollment. --}}
    @if (! $showsSubjectDetail)
        <p>Your subject and class list will appear in your Student account after you are officially enrolled.</p>
    @else
        @if (isset($enrollment) && $enrollment)
            <x-enrollment-proposal-explanation :enrollment="$enrollment" :proposal="$proposal" audience="student" />
        @endif
        @if ($proposal->items->isNotEmpty())
            <div class="tala-data-table-scroll student-academic-table-wrap" role="region" aria-label="{{ $subjectsHeading }} and classes" tabindex="0">
                <table class="tala-data-table student-academic-table">
                    <thead><tr><th scope="col">Subject</th><th scope="col">Class</th><th scope="col">Units</th></tr></thead>
                    <tbody>
                        @foreach ($proposal->items as $item)
                            <tr wire:key="proposed-subject-{{ $item->id }}">
                                <th scope="row">
                                    {{ $item->course_title_snapshot }}
                                    <span class="student-academic-secondary">
                                        {{ $item->course_code_snapshot }}
                                        @if ($item->scheduling_treatment_snapshot === \App\Models\CourseSpecification::SchedulingExternallyArranged)
                                            · Externally arranged; no recurring meeting
                                        @endif
                                    </span>
                                </th>
                                <td>{{ $item->section?->code ?? 'Not arranged' }}</td>
                                <td class="student-academic-number">{{ (float) $item->units_snapshot }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else<p>Your subjects are not available yet. Check again after the Registrar updates your enrollment.</p>@endif
    @endif
</section>
