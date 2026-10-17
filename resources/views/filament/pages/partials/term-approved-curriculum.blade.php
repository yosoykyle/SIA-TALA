@php
    $matchingEntries = collect($catalog)->where('term_type', $termType);
    $additionalEntries = collect($catalog)->where('term_type', '!=', $termType);
@endphp

<div class="tala-approved-curriculum-dialog">
    <p>{{ $matchingEntries->count() + $additionalEntries->count() }} approved entries · {{ $matchingEntries->count() }} match this Term type · {{ $additionalEntries->count() }} are additional-only. Entries from other Term types require an additional approval reference when prepared.</p>

    @foreach ([
        ['heading' => 'For this Term type', 'entries' => $matchingEntries],
        ['heading' => 'Other Term types · Additional approval required', 'entries' => $additionalEntries],
    ] as $group)
        <section class="tala-approved-curriculum-group" aria-label="{{ $group['heading'] }}">
            <h3>{{ $group['heading'] }} <span>({{ $group['entries']->count() }})</span></h3>

            @if ($group['entries']->isEmpty())
                <p>No entries in this group.</p>
            @else
                <div class="tala-approved-curriculum-table-wrap">
                    <table class="tala-approved-curriculum-table">
                        <caption class="sr-only">{{ $group['heading'] }} approved courses</caption>
                        <thead>
                            <tr>
                                <th scope="col">Course</th>
                                <th scope="col">Program and curriculum</th>
                                <th scope="col">Weekly meetings</th>
                                <th scope="col">Existing classes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($group['entries'] as $course)
                                <tr>
                                    <th scope="row"><strong>{{ $course['course_title'] }}</strong><span class="tala-approved-curriculum-code">{{ $course['course_code'] }}</span></th>
                                    <td>{{ $course['program'] }} · {{ $course['year_level'] }} · {{ $course['curriculum_version'] }}</td>
                                    <td>{{ $course['meetings'] ?: 'Meeting definition needs attention' }}</td>
                                    <td>{{ $course['existing'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endforeach
</div>
