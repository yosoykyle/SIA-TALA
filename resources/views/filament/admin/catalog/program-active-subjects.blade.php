@assets
    <link rel="stylesheet" href="{{ asset('css/tala-catalog.css').'?v='.filemtime(public_path('css/tala-catalog.css')) }}">
@endassets

@php
    /** @var \App\Models\Program $program */
    /** @var \App\Models\CurriculumVersion|null $activeCurriculum */
    /** @var \App\Models\CurriculumVersion|null $latestDraft */
    /** @var list<array<string, mixed>> $yearProspectus */
    $latestDraft = $latestDraft ?? null;
@endphp

<section
    class="tala-task-surface tala-prospectus-card"
    aria-labelledby="active-curriculum-subjects-heading"
    x-data="{ activeYearTab: 'all' }"
>
    <header class="tala-prospectus-card__header">
        <div class="tala-prospectus-card__identity">
            <div class="tala-prospectus-card__title-row">
                <h2 id="active-curriculum-subjects-heading">Active curriculum subjects</h2>
                @if ($activeCurriculum)
                    <x-filament::badge color="success" size="sm">Active</x-filament::badge>
                @endif
            </div>
            <p class="tala-prospectus-card__subtitle">
                @if ($activeCurriculum)
                    {{ $activeCurriculum->name }} · {{ $activeCurriculum->entries->count() }} {{ \Illuminate\Support\Str::plural('subject', $activeCurriculum->entries->count()) }}
                @elseif ($latestDraft)
                    Draft in progress: {{ $latestDraft->name }}. Open and activate it to see its prospectus subjects here.
                @else
                    Create and activate a curriculum to see its prospectus subjects here.
                @endif
            </p>
        </div>

        @if ($activeCurriculum)
            <x-filament::button
                tag="a"
                color="gray"
                size="sm"
                icon="heroicon-m-arrow-right"
                icon-position="after"
                :href="\App\Filament\Resources\CurriculumVersions\CurriculumVersionResource::getUrl('review', array_filter(['record' => $activeCurriculum->id, 'programId' => $program->id, 'oversightTermId' => $oversightTermId], fn ($v) => $v !== null))"
            >
                Open curriculum
            </x-filament::button>
        @endif
    </header>

    @if ($activeCurriculum && $activeCurriculum->entries->isEmpty())
        <p class="tala-prospectus-empty">No subjects attached to this active curriculum yet.</p>
    @elseif ($activeCurriculum)
        <x-tala-navigation
            stretch
            label="Filter prospectus by year level"
            role="tablist"
        >
            <x-filament::tabs.item
                role="tab"
                alpine-active="activeYearTab === 'all'"
                x-on:click="activeYearTab = 'all'"
                x-bind:aria-selected="activeYearTab === 'all' ? 'true' : 'false'"
            >
                All years
            </x-filament::tabs.item>

            @foreach ($yearProspectus as $yearGroup)
                <x-filament::tabs.item
                    role="tab"
                    alpine-active="activeYearTab === {{ $yearGroup['year'] }}"
                    x-on:click="activeYearTab = {{ $yearGroup['year'] }}"
                    x-bind:aria-selected="activeYearTab === {{ $yearGroup['year'] }} ? 'true' : 'false'"
                >
                    {{ $yearGroup['tab_label'] }}
                </x-filament::tabs.item>
            @endforeach
        </x-tala-navigation>

        <div class="tala-prospectus-years">
            @foreach ($yearProspectus as $yearGroup)
                <section
                    class="tala-prospectus-year"
                    aria-label="{{ $yearGroup['heading'] }}"
                    x-show="activeYearTab === 'all' || activeYearTab === {{ $yearGroup['year'] }}"
                >
                    <header class="tala-prospectus-year__header">
                        <h3 class="tala-prospectus-year__title">{{ $yearGroup['heading'] }}</h3>
                        <span class="tala-prospectus-year__summary">
                            {{ $yearGroup['subject_count'] }} {{ \Illuminate\Support\Str::plural('subject', $yearGroup['subject_count']) }} · {{ $yearGroup['total_units'] }} units
                        </span>
                    </header>

                    <div class="tala-prospectus-semesters">
                        @foreach ($yearGroup['semesters'] as $semester)
                            <div @class([
                                'tala-prospectus-semester',
                                'tala-prospectus-semester--full' => $loop->count > 2 && $loop->last && ($loop->count % 2 === 1),
                            ])>
                                <div class="tala-prospectus-semester__header">
                                    <h4 class="tala-prospectus-semester__title">{{ $semester['label'] }}</h4>
                                    <span class="tala-prospectus-semester__count">
                                        {{ $semester['subject_count'] }} {{ \Illuminate\Support\Str::plural('subject', $semester['subject_count']) }}
                                    </span>
                                </div>

                                @if ($semester['rows'] === [])
                                    <p class="tala-prospectus-semester__empty">
                                        No subjects scheduled in {{ $semester['label'] }}.
                                    </p>
                                @else
                                    <div class="tala-prospectus-table-wrap">
                                        <table class="tala-prospectus-table">
                                            <thead>
                                                <tr>
                                                    <th scope="col" class="tala-prospectus-table__col-code">Subject code</th>
                                                    <th scope="col">Subject</th>
                                                    <th scope="col" class="tala-prospectus-table__col-units">Units</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($semester['rows'] as $row)
                                                    <tr>
                                                        <td class="tala-prospectus-table__code">
                                                            @if ($row['url'])
                                                                <a href="{{ $row['url'] }}">{{ $row['code'] }}</a>
                                                            @else
                                                                {{ $row['code'] }}
                                                            @endif
                                                        </td>
                                                        <td class="tala-prospectus-table__subject">
                                                            <div class="tala-prospectus-table__title">{{ $row['title'] }}</div>
                                                            @if (filled($row['hours_summary']))
                                                                <div class="tala-prospectus-table__meta">{{ $row['hours_summary'] }}</div>
                                                            @endif
                                                            @if ($row['prerequisites'] !== 'None')
                                                                <div class="tala-prospectus-table__prereq">Prerequisites: {{ $row['prerequisites'] }}</div>
                                                            @endif
                                                        </td>
                                                        <td class="tala-prospectus-table__units">{{ $row['units'] }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                            <tfoot>
                                                <tr>
                                                    <td colspan="2">Total</td>
                                                    <td class="tala-prospectus-table__units">{{ $semester['total_units'] }} units</td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</section>
