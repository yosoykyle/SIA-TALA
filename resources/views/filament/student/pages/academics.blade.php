<x-filament-panels::page>
    @assets
        <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
    @endassets
    @if (! $student)
        <x-filament::section heading="Student record unavailable"><p>Contact the Registrar to link your student record.</p></x-filament::section>
    @else
        <div @class(['student-academics student-academic-reading', 'student-academic-current' => $academicView === 'current', 'student-academic-record' => $academicView === 'record'])>
            @if (! in_array($academicView, ['plan', 'record'], true) || ($academicView === 'plan' && $curriculum['required'] === []))
                <p class="student-academic-context">
                    <span>{{ $studyContext['program_name'] ?? 'Program unavailable' }}</span>
                    @if ($studyContext['is_official'])
                        <span>{{ $studyContext['study_level_label'] }}</span>
                        <span>{{ collect([$studyContext['semester'], $studyContext['academic_year']])->filter()->implode(' · ') }}</span>
                    @endif
                </p>
            @endif
            <div @class(['student-academic-workspace', 'tala-task-surface' => ! in_array($academicView, ['plan', 'record'], true), 'student-plan-workspace' => $academicView === 'plan', 'student-record-workspace' => $academicView === 'record'])>
                <div class="student-academic-body">
            @if ($academicView === 'current')
                @php
                    $firstInc = $currentResults->firstWhere('result', 'INC');
                @endphp
                <section aria-labelledby="current-subjects-heading" x-data="{ openSubject: @js($firstInc ? $firstInc['course_enrollment']->id : null) }">
                    <header class="student-academic-heading">
                        <div><h2 id="current-subjects-heading">Current subjects</h2><p class="student-academic-secondary">Your official subjects and released results.</p></div>
                        <x-filament::button tag="a" color="gray" :href="$enrollmentUrl" icon="heroicon-o-arrow-top-right-on-square">View enrollment</x-filament::button>
                    </header>
                    @if ($currentResults->isNotEmpty())
                        @php
                            $releasedCount = $currentResults->whereNotNull('outcome_source')->count();
                        @endphp
                        <div class="student-academic-tiles">
                            <section class="student-tile" aria-label="Results released">
                                <p class="student-tile-kicker"><x-filament::icon icon="heroicon-o-academic-cap" />Results released</p>
                                <div class="student-tile-split">
                                    <div class="student-tile-ring" style="--tile-progress: {{ (int) round($releasedCount / $currentResults->count() * 100) }}" aria-hidden="true">{{ $releasedCount }}/{{ $currentResults->count() }}</div>
                                    <p class="student-tile-figure">{{ $releasedCount }} of {{ $currentResults->count() }}</p>
                                </div>
                            </section>
                            <section class="student-tile" aria-label="Registered units">
                                <p class="student-tile-kicker"><x-filament::icon icon="heroicon-o-book-open" />Registered units</p>
                                <p class="student-tile-figure">{{ (float) $currentUnits }}</p>
                                <p class="student-tile-meta">{{ $currentResults->count() }} {{ $currentResults->count() === 1 ? 'subject' : 'subjects' }} this term</p>
                            </section>
                            @if ($currentAverage)
                                <section class="student-tile" aria-label="{{ $currentAverage['label'] }}">
                                    <p class="student-tile-kicker"><x-filament::icon icon="heroicon-o-chart-bar" />{{ $currentAverage['label'] }}</p>
                                    <p @class(['student-tile-figure' => $currentAverage['value'] !== null, 'font-semibold' => $currentAverage['value'] === null])>{{ $currentAverage['value'] ?? 'Grades not complete' }}</p>
                                    @if ($currentAverage['value'] === null)<p class="student-tile-meta">{{ $currentAverage['reason'] }}</p>@endif
                                </section>
                            @endif
                        </div>
                        <div class="student-academic-table-wrap" role="region" aria-label="Current subjects and released results" tabindex="0">
                            <table class="student-academic-table student-academic-ledger">
                                <thead><tr><th scope="col">Subject</th><th scope="col">Class</th><th scope="col">Units</th><th scope="col">Released result</th></tr></thead>
                                <tbody>
                                    @foreach ($currentResults as $result)
                                        @php
                                            $courseId = $result['course_enrollment']->id;
                                        @endphp
                                        <tr @if ($result['result'] === 'INC') x-bind:class="{ 'student-subject-selected': openSubject === {{ $courseId }} }" @endif>
                                            <th scope="row">
                                                @if ($result['result'] === 'INC')
                                                    <button type="button" class="student-subject-toggle" x-on:click="openSubject = openSubject === {{ $courseId }} ? null : {{ $courseId }}" x-bind:aria-expanded="openSubject === {{ $courseId }}" aria-controls="student-inc-{{ $courseId }}">
                                                        <span>{{ $result['course_specification']?->title ?? 'Subject unavailable' }}<span class="student-academic-secondary">{{ $result['course_specification']?->course?->code }}</span></span>
                                                        <span class="student-subject-toggle-hint"><span x-text="openSubject === {{ $courseId }} ? 'Hide what to finish' : 'Show what to finish'">Show what to finish</span><x-filament::icon icon="heroicon-o-chevron-down" x-bind:class="{ 'student-subject-chevron-open': openSubject === {{ $courseId }} }" /></span>
                                                    </button>
                                                @else
                                                    {{ $result['course_specification']?->title ?? 'Subject unavailable' }}
                                                    <span class="student-academic-secondary">{{ $result['course_specification']?->course?->code }}</span>
                                                @endif
                                                <span class="student-mobile-subject-meta">{{ $result['course_enrollment']->section?->code ?? 'Class not recorded' }} · {{ (float) $result['units'] }} units</span>
                                            </th>
                                            <td>{{ $result['course_enrollment']->section?->code ?? 'Not recorded' }}</td>
                                            <td class="student-academic-number">{{ (float) $result['units'] }}</td>
                                            <td @class(['student-record-grade' => is_numeric($result['result'])])><x-student-academic-result :$result :show-details="false" /></td>
                                        </tr>
                                        @if ($result['result'] === 'INC')
                                            <tr class="student-subject-detail" x-show="openSubject === {{ $courseId }}" x-cloak>
                                                <td colspan="4">
                                                    <section id="student-inc-{{ $courseId }}" aria-label="{{ $result['course_specification']?->title }} completion guidance">
                                                        <header class="student-academic-heading"><h3>Work to complete</h3><button type="button" class="student-subject-hide" x-on:click="openSubject = null; $el.closest('tbody').querySelector('[aria-controls=&quot;student-inc-{{ $courseId }}&quot;]').focus()">Hide details</button></header>
                                                        <x-student-inc-guidance :$result :show-average-note="false" />
                                                    </section>
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="student-academic-empty-state">
                            <x-filament::icon icon="heroicon-o-book-open" />
                            <div><h3>{{ $studyContext['is_official'] ? 'No active subjects recorded' : 'Current subjects unavailable' }}</h3>
                            <p>{{ $studyContext['is_official'] ? 'Contact the Registrar if you expected subjects in this registration.' : 'Current subjects cannot be shown here. View your enrollment record, or contact the Registrar.' }}</p></div>
                        </div>
                    @endif
                </section>
                @if ($effect['effect'] !== 'ALLOWED')
                    <section class="student-academic-advising" aria-label="Enrollment guidance">
                        <x-filament::icon icon="heroicon-o-information-circle" />
                        <div><h2>Next enrollment guidance</h2><p>{{ $effect['student_reason'] ?? $effect['reason'] }} Contact the Registrar about your next enrollment.</p></div>
                    </section>
                @endif
                @if ($examinationPeriod['status'] === 'Available')
                    <section class="student-academic-calendar">
                        <x-filament::icon icon="heroicon-o-calendar-days" />
                        <div><h2>Exam dates</h2><p>{{ $examinationPeriod['opens_on']->format('M j, Y') }}–{{ $examinationPeriod['closes_on']->format('M j, Y') }}</p>
                        <x-supporting-details summary="Calendar details" inset><p>{{ $examinationPeriod['authority_reference'] ?: 'Authority not recorded' }} · {{ $examinationPeriod['owner'] }}</p><p>{{ $examinationPeriod['message'] }}</p></x-supporting-details></div>
                    </section>
                @endif
            @elseif ($academicView === 'plan')
                @if ($curriculum['required'] !== [])
                    @php
                        $planRows = collect($curriculum['required']);
                        $planTotalUnits = (float) $planRows->sum(fn (array $row): float => (float) $row['curriculum_entry']->courseSpecification?->credit_units);
                        $planCompletedUnits = (float) $curriculum['completed_units'];
                        $planPercent = $planTotalUnits > 0 ? (int) round($planCompletedUnits / $planTotalUnits * 100) : 0;
                        $planHasProgress = $planCompletedUnits > 0 || $planRows->contains('status', 'In progress');
                        $planFollowUps = $planRows->where('status', 'Deficient')->values();
                        $planNeedsAdvising = $effect['effect'] !== 'ALLOWED' || $planFollowUps->isNotEmpty();
                        $planFollowUpHeading = match (true) {
                            $planFollowUps->count() === 1 => ($planFollowUps->first()['curriculum_entry']->courseSpecification?->title ?? 'One subject').' needs follow-up',
                            $planFollowUps->count() > 1 => $planFollowUps->count().' subjects need follow-up',
                            default => 'Your next enrollment needs follow-up',
                        };
                        $planYears = $curriculumGroups->map(function ($termGroups, $year) use ($studyContext): array {
                            $yearRows = collect($termGroups)->flatten(1);
                            $yearDone = $yearRows->whereIn('status', ['Completed', 'Approved credit'])->count();

                            return [
                                'label' => ctype_digit((string) $year) ? 'Year '.$year : $year,
                                'total' => $yearRows->count(),
                                'done' => $yearDone,
                                'current' => $yearRows->contains(fn (array $row): bool => in_array((string) $row['curriculum_entry']->year_level, $studyContext['curriculum_levels'], true)),
                                'active' => $yearRows->whereIn('status', ['In progress', 'Deficient'])->isNotEmpty(),
                                'complete' => $yearRows->isNotEmpty() && $yearDone === $yearRows->count(),
                            ];
                        })->values();
                        $planCurrentIndex = $planYears->search(fn (array $planYear): bool => $planYear['current']);
                        $planOpenIndex = $planCurrentIndex;
                        $planOpenIndex = $planOpenIndex === false ? $planYears->search(fn (array $planYear): bool => $planYear['active']) : $planOpenIndex;
                        $planOpenIndex = $planOpenIndex === false ? $planYears->search(fn (array $planYear): bool => ! $planYear['complete']) : $planOpenIndex;
                        $planOpenIndex = $planOpenIndex === false ? 0 : $planOpenIndex;
                        $planCurrentLabels = $planYears->where('current', true)->pluck('label');
                    @endphp
                    <section class="student-plan-overview" aria-label="Program and study plan progress">
                        <div class="student-plan-overview-program">
                            <h2>{{ $studyContext['program_name'] ?? 'Program unavailable' }}</h2>
                            @if ($studyContext['is_official'])
                                <ul aria-label="Your current standing">
                                    @foreach (collect([$studyContext['study_level_label'], $studyContext['semester'], $studyContext['academic_year']])->filter() as $contextFact)
                                        <li>{{ $contextFact }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                        <div class="student-plan-overview-progress">
                            <p><strong>{{ $planCompletedUnits }}</strong>@if ($planTotalUnits > 0) of <strong>{{ $planTotalUnits }}</strong>@endif units completed</p>
                            @if ($planTotalUnits > 0)<div class="student-tile-bar" role="img" aria-label="{{ $planPercent }} percent of curriculum units completed"><span style="inline-size: {{ $planPercent }}%"></span></div>@endif
                        </div>
                    </section>
                    @if (! $planHasProgress)
                        <section class="student-plan-start"><h2>{{ $studyContext['is_official'] ? 'No completed units recorded' : 'These are your planned subjects' }}</h2><p>{{ $studyContext['is_official'] ? 'Released passing results and approved credits will update this plan.' : 'Check Enrollment for the subjects being prepared. They become current subjects after official enrollment.' }}</p><x-filament::link :href="$enrollmentUrl">View enrollment</x-filament::link></section>
                    @endif
                    @if ($planNeedsAdvising)
                        <section class="student-academic-advising student-plan-advising" aria-label="Study plan guidance">
                            <x-filament::icon icon="heroicon-o-exclamation-circle" />
                            <div>
                                <h2>{{ $planFollowUpHeading }}</h2>
                                <p>{{ $effect['effect'] !== 'ALLOWED' ? ($effect['student_reason'] ?? $effect['reason']) : 'Ask the Registrar about completing or retaking the subject before choosing your next subjects.' }}</p>
                            </div>
                            <x-filament::button tag="a" color="gray" :href="$enrollmentUrl" icon="heroicon-m-arrow-right" icon-position="after">Review enrollment</x-filament::button>
                        </section>
                    @endif
                    <div class="student-plan-map" x-data="{ planYear: {{ $planOpenIndex }} }">
                        @if ($planYears->count() > 1)
                            <ol class="student-plan-timeline" aria-label="Curriculum years">
                                @foreach ($planYears as $planIndex => $planYear)
                                    <li @class(['student-plan-timeline-complete' => $planYear['complete'], 'student-plan-timeline-active' => $planYear['current'] || ($planCurrentIndex === false && $planYear['active'])])>
                                        <button type="button" x-on:click="planYear = {{ $planIndex }}; $el.scrollIntoView({ block: 'nearest', inline: 'nearest' })" x-bind:aria-current="planYear === {{ $planIndex }} ? 'step' : false" @if ($planIndex === $planOpenIndex) aria-current="step" @endif>
                                            <span class="student-plan-timeline-dot" aria-hidden="true"></span>
                                            <span class="student-plan-timeline-copy">
                                                <span class="student-plan-timeline-label">{{ $planYear['label'] }}</span>
                                                <span class="student-plan-timeline-meta">{{ $planYear['done'] }} of {{ $planYear['total'] }} completed</span>
                                                @if ($planYear['current'])<span class="student-plan-timeline-current">Current year</span>@endif
                                            </span>
                                        </button>
                                    </li>
                                @endforeach
                            </ol>
                            @if ($planYears->count() > 2)
                                <p class="student-plan-scroll-hint">Scroll across to browse all curriculum years.</p>
                            @endif
                        @endif
                        @foreach ($curriculumGroups as $year => $termGroups)
                            @php
                                $planYear = $planYears[$loop->index];
                            @endphp
                            <section class="student-plan-year" aria-labelledby="student-plan-year-{{ $loop->index }}" x-show="planYear === {{ $loop->index }}" @if ($loop->index !== $planOpenIndex) x-cloak @endif>
                                <header class="student-plan-year-heading">
                                    <h2 id="student-plan-year-{{ $loop->index }}">{{ $planYear['label'] }}</h2>
                                    <p>{{ $planYear['done'] }} of {{ $planYear['total'] }} {{ $planYear['total'] === 1 ? 'subject' : 'subjects' }} completed{{ $planYear['current'] ? ' · Your current year' : '' }}</p>
                                </header>
                                <div>@foreach ($termGroups as $termLabel => $rows)
                                    <section class="student-plan-term"><h3>{{ $termLabel ?: 'Term not recorded' }}</h3>
                                        <ul class="student-plan-subjects">@foreach ($rows as $row)
                                            <li><div><h4>{{ $row['curriculum_entry']->courseSpecification?->title ?? 'Subject unavailable' }}</h4><p class="student-academic-secondary">{{ $row['curriculum_entry']->courseSpecification?->course?->code }}</p>
                                                @if (! in_array($row['status'], ['Completed', 'Approved credit'], true))
                                                    @foreach ($planRequirements->get($row['curriculum_entry']->id) ?? [] as $requirement)<p class="student-academic-secondary">{{ \App\Models\CourseRequirement::typeOptions()[$requirement->rule_type] }}: {{ $requirement->relatedCourse?->code ?? 'Subject not recorded' }}@if ($requirement->minimum_grade !== null) · Minimum grade {{ (float) $requirement->minimum_grade }}@endif</p>@endforeach
                                                    @if ($row['status'] === 'Deficient')<p class="student-academic-secondary">Ask the Registrar about completing or retaking this subject.</p>@endif
                                                @endif
                                            </div><p>{{ (float) $row['curriculum_entry']->courseSpecification?->credit_units }} units</p><p @class(['student-plan-status', 'student-plan-status-future' => $row['status'] === 'Not taken', 'student-plan-status-warning' => $row['status'] === 'Deficient'])><x-filament::icon :icon="match ($row['status']) { 'Completed', 'Approved credit' => 'heroicon-o-check-circle', 'In progress' => 'heroicon-o-clock', 'Deficient' => 'heroicon-o-exclamation-circle', default => 'heroicon-o-minus-circle' }" />{{ match ($row['status']) { 'Not taken' => 'Not yet enrolled', 'Deficient' => 'Needs follow-up', default => $row['status'] } }}</p></li>
                                        @endforeach</ul>
                                    </section>
                                @endforeach</div>
                            </section>
                        @endforeach
                        <footer class="student-plan-note">
                            <p>Completed units include released passing results and approved credits.</p>
                            <p>The Registrar maintains your curriculum evaluation and confirms your next subject selection.</p>
                        </footer>
                    </div>
                @else<p class="student-academic-empty">Your curriculum is unavailable. Contact the Registrar.</p>@endif
                @if ($competencyRequirements->isNotEmpty())
                    <section class="student-record-panel student-academic-section" aria-labelledby="student-external-qualifications">
                        <header class="student-academic-heading">
                            <div>
                                <h2 id="student-external-qualifications">External qualifications</h2>
                                <p class="student-academic-secondary">Qualifications tracked in your curriculum and their current verified status.</p>
                            </div>
                            @if ($competencyRequirements->contains(fn ($requirement) => $requirement->currentResult !== null))
                                <x-filament::link :href="static::getUrl(['view' => 'record'], panel: 'student').'#external-assessment-history'">View assessment history</x-filament::link>
                            @endif
                        </header>
                        <ul class="student-plan-subjects">
                            @foreach ($competencyRequirements as $requirement)
                                @php
                                    $result = $requirement->currentResult;
                                    $competent = $result?->outcome === \App\Models\ExternalCompetencyResult::OutcomeCompetent;
                                    $notYetCompetent = $result?->outcome === \App\Models\ExternalCompetencyResult::OutcomeNotYetCompetent;
                                    $requiredForCompletion = $requirement->treatment === \App\Models\ExternalCompetencyRequirement::TreatmentCompletionRequired;
                                @endphp
                                <li>
                                    <div>
                                        <h3 class="font-semibold">{{ $requirement->qualification_label }}</h3>
                                        @if ($requirement->qualification_level)
                                            <p class="student-academic-secondary">{{ $requirement->qualification_level }}</p>
                                        @endif
                                        @if ($requiredForCompletion && ! $competent)
                                            <p class="student-academic-secondary">Ask the Registrar how to get your verified result recorded.</p>
                                        @endif
                                    </div>
                                    <p>{{ $requiredForCompletion ? 'Required for graduation' : 'Tracked only' }}</p>
                                    <p @class(['student-plan-status', 'student-plan-status-future' => $result === null, 'student-plan-status-warning' => $notYetCompetent || ($requiredForCompletion && ! $competent)])>
                                        <x-filament::icon :icon="match ($result?->outcome) {
                                            \App\Models\ExternalCompetencyResult::OutcomeCompetent => 'heroicon-o-check-circle',
                                            \App\Models\ExternalCompetencyResult::OutcomeNotYetCompetent => 'heroicon-o-exclamation-circle',
                                            default => 'heroicon-o-minus-circle',
                                        }" />
                                        {{ \App\Models\ExternalCompetencyResult::outcomeLabel($result?->outcome) }}
                                    </p>
                                </li>
                            @endforeach
                        </ul>
                        @if ($competencyRequirements->contains('treatment', \App\Models\ExternalCompetencyRequirement::TreatmentTrackedOnly))
                            <p class="student-academic-note">Qualifications marked “Tracked only” are for reference and do not affect enrollment or graduation.</p>
                        @endif
                    </section>
                @endif
            @elseif ($academicView === 'record')
                @if ($results->whereNotNull('outcome_source')->isEmpty() && $historicalResults->isEmpty())
                    <section class="student-record-empty tala-task-surface"><x-filament::icon icon="heroicon-o-document-text" /><h2>Your results will appear here after release.</h2><p>You can check enrolled subjects in Academics.</p><x-filament::button tag="a" color="gray" :href="static::getUrl(panel: 'student')" icon="heroicon-m-arrow-right" icon-position="after">View current subjects</x-filament::button></section>
                    <p class="student-academic-note">An unofficial record becomes available after results are released.</p>
                @else
                    @php
                        $recordReleased = $results->whereNotNull('outcome_source')->count();
                        $recordIncomplete = $results->where('result', 'INC');
                    @endphp
                    <div class="student-record-summary-row">
                    <section class="student-plan-overview student-record-masthead" aria-label="Academic record summary">
                        <div class="student-plan-overview-program">
                            <h2>{{ $studyContext['program_name'] ?? 'Program unavailable' }}</h2>
                            @if ($studyContext['is_official'])
                                <ul aria-label="Your current standing">
                                    @foreach (collect([$studyContext['study_level_label'], $studyContext['semester'], $studyContext['academic_year']])->filter() as $contextFact)
                                        <li>{{ $contextFact }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                        <div class="student-record-gwa">
                            <x-academic-average :average="$cumulative" compact />

                        </div>
                        @if ($results->isNotEmpty())
                            <div class="student-record-release" aria-label="Results on record">
                                <p>Released results</p>
                                <p><strong>{{ $recordReleased }} of {{ $results->count() }}</strong> released</p>
                                <div class="student-tile-bar" role="img" aria-label="{{ (int) round($recordReleased / $results->count() * 100) }} percent of results released"><span style="inline-size: {{ (int) round($recordReleased / $results->count() * 100) }}%"></span></div>
                                <p>Across {{ $recordTerms->count() }} {{ $recordTerms->count() === 1 ? 'term' : 'terms' }}</p>
                            </div>
                        @endif
                            <x-supporting-details summary="Average sources" class="student-record-sources">
                                <p>{{ $cumulative['reason'] }}</p>
                                <p>As of {{ \Illuminate\Support\Carbon::parse($cumulative['as_of'])->timezone('Asia/Manila')->format('M j, Y g:i A') }} · Asia/Manila</p>
                                @foreach ($cumulative['exclusions'] as $exclusion)<p>{{ $exclusion }}</p>@endforeach
                            </x-supporting-details>
                    </section>

                    @if ($recordIncomplete->isNotEmpty())
                        <section class="student-record-inc" aria-label="Incomplete results and next steps">
                            <x-filament::icon icon="heroicon-o-exclamation-triangle" />
                            <div>
                                <h2>{{ $recordIncomplete->count() === 1 ? ($recordIncomplete->first()['course_specification']?->title ?? 'Subject') : $recordIncomplete->count().' incomplete subjects' }}</h2>
                                <p class="student-record-inc-status">INC completion {{ ($recordIncomplete->first()['inc_state'] ?? null) === \App\Actions\Grades\IncDeadlineService::StateCompletionOverdue ? 'overdue' : 'pending' }}</p>

                            </div>
                            @if ($recordIncomplete->count() === 1 && $recordIncomplete->first()['event']?->inc_completion_note)
                                <div class="student-record-inc-work"><span>Remaining work</span><p>{{ $recordIncomplete->first()['event']->inc_completion_note }}</p></div>
                            @endif
                                <x-supporting-details class="student-record-inc-details">
                                    <x-slot:summary>
                                        @if ($recordIncomplete->count() === 1 && ($recordIncomplete->first()['inc_deadline'] ?? null))
                                            <span class="student-record-inc-deadline"><x-filament::icon icon="heroicon-o-calendar-days" /><time datetime="{{ $recordIncomplete->first()['inc_deadline']->toDateString() }}">{{ $recordIncomplete->first()['inc_deadline']->format('M j, Y') }}</time><span>Asia/Manila</span></span>
                                        @endif
                                        <span class="student-record-inc-toggle">View INC details<x-filament::icon icon="heroicon-m-chevron-down" /></span>
                                    </x-slot:summary>
                                    @foreach ($recordIncomplete as $incomplete)
                                        @if ($recordIncomplete->count() > 1)<h3>{{ $incomplete['course_specification']?->title }}</h3>@endif
                                        <x-student-inc-guidance :result="$incomplete" />
                                    @endforeach
                                </x-supporting-details>
                        </section>
                    @endif
                    </div>
                    <div class="student-record-terms">@foreach ($recordTerms as $term)
                        @php
                            $average = $termAverages->get($term->id);
                        @endphp
                        <section class="student-record-term">
                            <header class="student-record-term-heading">
                                <div><h2>{{ $term->label }}</h2><p>{{ collect([$termYearLevels->get($term->id), $term->academicYear?->label])->filter()->implode(' · ') }}</p></div>
                                <div class="student-record-term-average"><x-academic-average :$average compact /></div>
                            </header>
                            <div class="student-academic-table-wrap" role="region" aria-label="{{ $term->label }} results" tabindex="0"><table class="student-academic-table"><thead><tr><th scope="col">Subject</th><th scope="col">Units</th><th scope="col">Result</th></tr></thead><tbody>
                                @foreach ($results->where('term.id', $term->id) as $result)<tr><th scope="row">{{ $result['course_specification']?->title }}<span class="student-academic-secondary">{{ $result['course_specification']?->course?->code }}</span></th><td>{{ (float) $result['units'] }}</td><td @class(['student-record-grade' => is_numeric($result['result'])])><x-student-academic-result :$result :show-details="false" /></td></tr>@endforeach
                            </tbody></table></div>
                            @if ($average['value'] === null)<p class="student-record-term-note">{{ $average['reason'] }}</p>@endif
                        </section>
                    @endforeach</div>
                @endif
                @if ($competencyResults->isNotEmpty())
                    <x-record-history id="external-assessment-history" heading="External assessment history">
                            @foreach ($competencyResults as $result)
                                <x-record-history-item :heading="$result->requirement?->qualification_label ?? 'External assessment'" :date="$result->recorded_at">
                                    <div>
                                        <p>{{ \App\Models\ExternalCompetencyResult::outcomeLabel($result->outcome) }} · {{ $result->is_current ? 'Latest attempt' : 'Earlier attempt' }}</p>
                                    </div>
                                    @if ($result->assessment_date)
                                        <time datetime="{{ $result->assessment_date->toDateString() }}">Assessed {{ $result->assessment_date->format('M j, Y') }}</time>
                                    @endif
                                </x-record-history-item>
                            @endforeach
                    </x-record-history>
                @endif
                @if ($lifecycleChanges->isNotEmpty())<section class="student-record-section"><h2>Student record changes</h2>@foreach ($lifecycleChanges as $change)<p>{{ str($change->type)->replace('_', ' ')->headline() }} · {{ str($change->state)->replace('_', ' ')->headline() }} · {{ $change->effective_on?->format('M j, Y') }}</p>@endforeach</section>@endif
            @else
                @php
                    $earlierConferrals = $degreeConferrals->reject(fn (\App\Models\DegreeConferral $conferral): bool => $conferral->is($completion['conferral']));
                @endphp
                <div class="student-graduation-records">
                @if ($completion['conferral'])
                    <section class="student-degree-summary"><h2>{{ $completion['conferral']->degree_name }}</h2><p>{{ $completion['conferral']->conferred_on ? 'Conferred '.$completion['conferral']->conferred_on->format('M j, Y') : 'External graduation date not recorded' }}</p><p class="student-academic-note">Recorded by the Registrar {{ $completion['conferral']->recorded_at?->format('M j, Y') }}.</p></section>
                @endif
                @if ($earlierConferrals->isNotEmpty())
                    <details class="learner-disclosure"><summary>Earlier degree records</summary><div><ol class="student-record-timeline">@foreach ($earlierConferrals as $conferral)<li><strong>{{ $conferral->degree_name }}</strong>@if($conferral->conferred_on)<time datetime="{{ $conferral->conferred_on->toDateString() }}">{{ $conferral->conferred_on->format('M j, Y') }}</time>@else<span>External graduation date not recorded</span>@endif</li>@endforeach</ol></div></details>
                @endif
                @if ($graduationApplications->isNotEmpty())
                    <section class="student-record-section"><h2>Application history</h2><ol class="student-record-timeline">@foreach ($graduationApplications as $application)<li><time datetime="{{ $application->applied_at->toDateString() }}">{{ $application->applied_at->format('M j, Y') }}</time><strong>{{ match ($application->state) { \App\Models\GraduationApplication::StateActive => 'On file', \App\Models\GraduationApplication::StateCorrected => 'Corrected record', default => str($application->state)->replace('_', ' ')->headline() } }}</strong></li>@endforeach</ol></section>
                @endif
                <p class="student-academic-note">These records are maintained by the Registrar. Contact the Registrar about corrections.</p>
                @if ($this->resendCompletionUpdateAction->isVisible()){{ $this->resendCompletionUpdateAction }}@endif
                </div>
            @endif
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
