<x-filament-panels::page>
    @assets<link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">@endassets
    @if ($termId === null)
        <section class="oversight-welcome" aria-labelledby="oversight-welcome-title">
            <div class="oversight-welcome-main">
                <x-heroicon-o-calendar-days aria-hidden="true" />
                <h2 id="oversight-welcome-title">Choose an academic term</h2>
                <p>{{ $terms->isEmpty() ? 'No academic terms are recorded. The Registrar records term setup.' : 'Select an academic year and term to view its calendar, enrollment and class rosters.' }}</p>
                <div class="oversight-welcome-picker">{{ $this->termSelection }}</div>
            </div>
            <div class="oversight-welcome-records">
                <h3>Institution-wide records</h3>
                <nav aria-label="Institution-wide academic records">
                    @if (\App\Filament\Pages\CatalogCurriculaWorkbench::canAccess())
                        <x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\CatalogCurriculaWorkbench::getUrl()">Catalog & Curricula</x-filament::button>
                    @endif
                    @foreach (collect($this->approvalAreas())->whereIn('title', ['Grades & Progress', 'Lifecycle & Completion']) as $area)
                        <x-filament::button tag="a" color="gray" :href="$area['url']">{{ $area['action'] }}</x-filament::button>
                    @endforeach
                    @if (\App\Filament\Resources\StudentProfiles\StudentProfileResource::canAccess())
                        <x-filament::button tag="a" color="gray" :href="\App\Filament\Resources\StudentProfiles\StudentProfileResource::getUrl('index')">Student academic records</x-filament::button>
                    @endif
                </nav>
            </div>
        </section>
    @else
    <div class="oversight-board">
        <section class="oversight-panel oversight-calendar tala-task-surface" aria-labelledby="oversight-calendar-title">
            <div class="oversight-panel-heading">
                <h2 id="oversight-calendar-title">This term</h2>
                @if ($term)
                    <div class="oversight-calendar-state">
                        <span class="oversight-period-state{{ $calendarTimeline['enrollment_status'] === 'Enrollment period open' ? ' is-open' : '' }}">{{ $calendarTimeline['enrollment_status'] }}</span>
                        @if ($timetable)<a href="{{ \App\Filament\Pages\TermTimetable::getUrl(['termId' => $termId, 'selectedVersionId' => $timetable->id]) }}">Official timetable v{{ $timetable->version }} · {{ $timetable->meetings_count }} {{ str('meeting')->plural($timetable->meetings_count) }} <span aria-hidden="true">→</span></a>@else<span>No published timetable</span>@endif
                    </div>
                @endif
            </div>
            @if ($term)
                @if ($calendarTimeline['months'])
                    <div class="oversight-timeline" aria-label="Recorded term dates">
                        <div class="oversight-timeline-grid" aria-hidden="true">
                            @foreach ($calendarTimeline['months'] as $month)<span class="oversight-month" style="left:{{ $month['left_percent'] }}%"><span>{{ $month['label'] }}</span></span>@endforeach
                            @if ($calendarTimeline['today_percent'] !== null)<span class="oversight-today" style="left:{{ $calendarTimeline['today_percent'] }}%"><span>Today<small>{{ $checkedAt->format('j M') }}</small></span></span>@endif
                        </div>
                        <dl>
                            @foreach ($calendarTimeline['rows'] as $range)
                                <div class="oversight-timeline-row">
                                    <dt>{{ $range['label'] }}</dt>
                                    <dd>
                                        @if ($range['available'])
                                            <span class="oversight-date-bar oversight-date-bar-{{ $range['key'] }}" aria-hidden="true" style="left:{{ $range['left_percent'] }}%;width:{{ $range['width_percent'] }}%"></span>
                                            <span class="oversight-date-label">{{ $range['opens_on']->format('j M Y') }} – {{ $range['closes_on']->format('j M Y') }}</span>
                                        @else
                                            <span class="oversight-date-missing" aria-hidden="true"></span><span class="oversight-date-label">Dates unavailable</span>
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @else
                    <div class="oversight-calendar-empty"><x-heroicon-o-calendar-days aria-hidden="true" /><h3>Calendar dates unavailable</h3><p>The Registrar records the approved calendar for this term.</p></div>
                @endif
                <div class="oversight-planning-entry">
                    <div><h3>Term preparation</h3><p>Inspect classes, availability and timetable attempts.</p></div>
                    <x-filament::button tag="a" color="gray" icon="heroicon-o-eye" :href="\App\Filament\Pages\TermOverview::getUrl(['termId' => $termId])">Inspect Term planning</x-filament::button>
                </div>
                <details class="oversight-source-details">
                    <summary>Calendar source details</summary>
<div class="tala-planning-reveal">
                    <dl class="oversight-source-grid">
                        <div><dt>Teaching calendar</dt><dd>{{ $calendar ? 'Active version '.$calendar->version : 'No active calendar' }}</dd></div>
                        <div><dt>Examination Period</dt><dd>{{ $examination['status'] === 'Available' ? $examination['opens_on']->format('M j, Y').' – '.$examination['closes_on']->format('M j, Y') : 'Examination Period unavailable' }} · Informational</dd></div>
                        <div><dt>Calendar authority</dt><dd>{{ $examination['authority_reference'] ?: 'Not recorded' }}</dd></div>
                        <div><dt>Source checked</dt><dd>{{ $examination['as_of']->format('j M Y, g:i A') }} · Asia/Manila</dd></div>
                    </dl>
                    <p>{{ $examination['message'] }}</p>
                    <x-filament::button tag="a" color="gray" icon="heroicon-o-calendar-days" :href="$calendarTimeline['url']">Inspect calendar</x-filament::button>

</div>
</details>
            @else
                <div class="oversight-calendar-empty"><x-heroicon-o-calendar-days aria-hidden="true" /><h3>{{ $terms->isEmpty() ? 'No Terms recorded' : 'No exact Term is selected' }}</h3><p>{{ $terms->isEmpty() ? 'The Registrar records term setup.' : 'Choose a term above to see its calendar, enrollment and class rosters.' }}</p></div>
            @endif
        </section>

        <section class="oversight-panel oversight-enrollment tala-task-surface" aria-labelledby="oversight-enrollment-title">
            <h2 id="oversight-enrollment-title">Enrollment</h2>
            @if ($enrollmentSummary['total'] !== null)
                <p class="oversight-figure"><strong>{{ number_format($enrollmentSummary['total']) }}</strong><span>{{ str('case')->plural($enrollmentSummary['total']) }}</span></p>
                @if ($enrollmentSummary['total'] > 0)
                    <div class="oversight-distribution" aria-hidden="true">
                        @foreach (['official', 'in_progress', 'closed', 'unknown'] as $state)@if ($enrollmentSummary[$state] > 0)<span class="oversight-segment-{{ $state }}" style="flex-grow:{{ $enrollmentSummary[$state] }}"></span>@endif @endforeach
                    </div>
                    <dl class="oversight-legend">
                        <div><dt><i class="oversight-segment-official" aria-hidden="true"></i>Official</dt><dd>{{ $enrollmentSummary['official'] }}</dd></div>
                        <div><dt><i class="oversight-segment-in_progress" aria-hidden="true"></i>In progress</dt><dd>{{ $enrollmentSummary['in_progress'] }}</dd></div>
                        @if ($enrollmentSummary['closed'] > 0)<div><dt><i class="oversight-segment-closed" aria-hidden="true"></i>Closed / not enrolled</dt><dd>{{ $enrollmentSummary['closed'] }}</dd></div>@endif
                        @if ($enrollmentSummary['unknown'] > 0)<div><dt><i class="oversight-segment-unknown" aria-hidden="true"></i>Unclassified</dt><dd>{{ $enrollmentSummary['unknown'] }}</dd></div>@endif
                    </dl>
                @else<p class="oversight-summary-note">No enrollment cases are recorded for this term.</p>@endif
                <x-filament::button tag="a" color="gray" :href="$enrollmentSummary['url']" icon="heroicon-m-arrow-right" icon-position="after">View enrollment cases</x-filament::button>
            @else<p class="oversight-unavailable"><strong aria-hidden="true">—</strong><span>Choose a term to see its enrollment cases.</span></p>@endif
        </section>

        <section class="oversight-panel oversight-grades tala-task-surface" aria-labelledby="oversight-grades-title">
            <h2 id="oversight-grades-title">Grades &amp; Progress</h2>
            @if ($gradeSummary['total'] !== null)
                <p class="oversight-figure"><strong>{{ number_format($gradeSummary['released']) }}</strong><span>of {{ number_format($gradeSummary['total']) }} {{ str('roster')->plural($gradeSummary['total']) }} released</span></p>
                @if ($gradeSummary['total'] > 0)
                    <div class="oversight-distribution" aria-hidden="true">
                        @foreach (['released', 'submitted', 'in_progress', 'unknown'] as $state)@if ($gradeSummary[$state] > 0)<span class="oversight-grade-{{ $state }}" style="flex-grow:{{ $gradeSummary[$state] }}"></span>@endif @endforeach
                    </div>
                    <dl class="oversight-legend">
                        <div><dt><i class="oversight-grade-released" aria-hidden="true"></i>Released</dt><dd>{{ $gradeSummary['released'] }}</dd></div>
                        @if ($gradeSummary['submitted'] > 0)<div><dt><i class="oversight-grade-submitted" aria-hidden="true"></i>Submitted</dt><dd>{{ $gradeSummary['submitted'] }}</dd></div>@endif
                        <div><dt><i class="oversight-grade-in_progress" aria-hidden="true"></i>In progress</dt><dd>{{ $gradeSummary['in_progress'] }}</dd></div>
                        @if ($gradeSummary['unknown'] > 0)<div><dt><i class="oversight-grade-unknown" aria-hidden="true"></i>Unclassified</dt><dd>{{ $gradeSummary['unknown'] }}</dd></div>@endif
                    </dl>
                @else<p class="oversight-summary-note">No class rosters are recorded for this term.</p>@endif
                <x-filament::button tag="a" color="gray" :href="$gradeSummary['url']" icon="heroicon-m-arrow-right" icon-position="after">Open class rosters</x-filament::button>
            @else<p class="oversight-unavailable"><strong aria-hidden="true">—</strong><span>Choose a term to see its class rosters.</span></p>@endif
        </section>

        <section class="oversight-panel oversight-attention tala-task-surface" aria-labelledby="oversight-attention-title">
            <div class="oversight-panel-heading"><h2 id="oversight-attention-title">Attention</h2><span class="oversight-count">{{ count($attention) }}</span></div>
            @if ($attention)
                <ul>
                    @foreach ($attention as $finding)
                        <li>
                            <span class="oversight-scope {{ $finding['scope'] === 'Institution-wide' ? 'oversight-scope-institution' : '' }}">{{ $finding['scope'] }}</span>
                            <h3><a href="{{ $finding['url'] }}" aria-label="Inspect source: {{ $finding['title'] }}">{{ $finding['title'] }} <span aria-hidden="true">→</span></a></h3>
                            <p>{{ $finding['detail'] }}</p>
                            <p class="oversight-finding-owner">Responsible: {{ $finding['owner'] }}</p>
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="oversight-no-findings"><x-heroicon-o-document-check aria-hidden="true" /><h3>No findings recorded</h3><p>Open a source below to inspect its current records.</p></div>
            @endif
        </section>

        <section class="oversight-panel oversight-authority tala-task-surface" aria-labelledby="oversight-authority-title">
            <div class="oversight-panel-heading"><div class="oversight-heading-scope"><h2 id="oversight-authority-title">Academic Authority</h2><span class="oversight-scope oversight-scope-institution">Institution-wide</span></div><a href="{{ \App\Filament\Pages\CatalogCurriculaWorkbench::getUrl(['oversightTermId' => $termId]) }}">Catalog & Curricula <span aria-hidden="true">→</span></a></div>
            @if ($curriculumSummary['total'] > 0)
                <div class="oversight-program-summary"><p><strong>{{ $curriculumSummary['ready'] }}</strong> of {{ $curriculumSummary['total'] }} {{ str('program')->plural($curriculumSummary['total']) }} ready for offerings</p><div class="oversight-distribution oversight-program-distribution" aria-hidden="true">@if ($curriculumSummary['ready'] > 0)<span class="oversight-segment-official" style="flex-grow:{{ $curriculumSummary['ready'] }}"></span>@endif @if ($curriculumSummary['total'] > $curriculumSummary['ready'])<span class="oversight-segment-closed" style="flex-grow:{{ $curriculumSummary['total'] - $curriculumSummary['ready'] }}"></span>@endif</div></div>
                <ul class="oversight-programs">
                    @foreach ($authorityRows as $row)<li><div><a href="{{ $row['url'] }}">{{ $row['program']->name }}</a><small>{{ $row['program']->code }} · {{ $row['curriculum']?->version_code ?? 'No curriculum recorded' }}</small></div><x-filament::badge :color="$row['readiness']['color']">{{ $row['readiness']['status'] }}</x-filament::badge></li>@endforeach
                </ul>
            @else<p class="oversight-summary-note">No Programs recorded. Academic authority evidence is unavailable.</p>@endif
        </section>

        <section class="oversight-panel oversight-lifecycle tala-task-surface" aria-labelledby="oversight-lifecycle-title">
            <div><h2 id="oversight-lifecycle-title">Lifecycle &amp; Completion</h2><p>Read-only records · these views use their own filters.</p></div>
            <nav aria-label="Lifecycle and academic record sources">
                @foreach (collect($this->approvalAreas())->where('title', 'Lifecycle & Completion') as $area)<x-filament::button tag="a" color="gray" :href="$area['url']" icon="heroicon-o-document-text">{{ $area['action'] }}</x-filament::button>@endforeach
                @if (\App\Filament\Resources\StudentProfiles\StudentProfileResource::canAccess())<x-filament::button tag="a" color="gray" :href="\App\Filament\Resources\StudentProfiles\StudentProfileResource::getUrl('index', ['oversightTermId' => $termId])" icon="heroicon-o-user-group">Student academic records</x-filament::button>@endif
            </nav>
        </section>
    </div>
    <p class="oversight-checked">Checked {{ $checkedAt->format('j M Y, g:i A') }} · Asia/Manila · Read-only source records</p>
    @endif
</x-filament-panels::page>
