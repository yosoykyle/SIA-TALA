<x-filament-panels::page>
    @assets
        <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
    @endassets
    @if (! $student)
        <section class="student-record-empty tala-task-surface">
            <x-servitech-empty-state
                illustrated
                heading="Student record unavailable"
                description="Contact the Registrar to link your student record."
            />
        </section>
    @else
        <div @class(['student-academics student-academic-reading', 'student-academic-record' => $academicView === 'record'])>
            <x-tala-navigation label="Grades sections" stretch class="student-workspace-nav">
                <x-filament::tabs.item
                    tag="a"
                    wire:navigate
                    :href="\App\Filament\Student\Pages\Academics::getUrl(['view' => 'record'], panel: 'student')"
                    :active="$academicView === 'record'"
                    icon="heroicon-o-academic-cap"
                >
                    Grades
                </x-filament::tabs.item>
                <x-filament::tabs.item
                    tag="a"
                    wire:navigate
                    :href="\App\Filament\Student\Pages\Academics::getUrl(['view' => 'plan'], panel: 'student')"
                    :active="$academicView === 'plan'"
                    icon="heroicon-o-map"
                >
                    Study plan
                </x-filament::tabs.item>
            </x-tala-navigation>
            @if (! in_array($academicView, ['plan', 'record'], true) || ($academicView === 'plan' && $curriculum['required'] === []))
                <div class="student-record-context">
                    <strong>{{ $studyContext['program_name'] ?? 'Program unavailable' }}</strong>
                    @if ($studyContext['is_official'])
                        <span>{{ $studyContext['study_level_label'] }}</span>
                        <span>{{ collect([$studyContext['semester'], $studyContext['academic_year']])->filter()->implode(' · ') }}</span>
                    @elseif ($studyContext['study_level_label'] !== 'Unavailable')
                        <span>{{ $studyContext['study_level_label'] }} (provisional)</span>
                    @endif
                </div>
            @endif
            <div @class(['student-academic-workspace', 'tala-task-surface' => ! in_array($academicView, ['plan', 'record'], true), 'student-plan-workspace' => $academicView === 'plan', 'student-record-workspace' => $academicView === 'record'])>
                <div class="student-academic-body">
            @if ($academicView === 'plan')
                @if ($curriculum['required'] !== [])
                    @php
                        $planRows = collect($curriculum['required']);
                        $planTotalUnits = (float) $planRows->sum(fn (array $row): float => (float) $row['curriculum_entry']->courseSpecification?->credit_units);
                        $planCompletedUnits = (float) $curriculum['completed_units'];
                        $planPercent = $planTotalUnits > 0 ? (int) round($planCompletedUnits / $planTotalUnits * 100) : 0;
                        $planHasProgress = $planCompletedUnits > 0 || $planRows->contains('status', 'In progress');
                        $planFollowUps = $planRows->where('status', 'Deficient')->values();
                        $planNeedsAdvising = $effect['needs_attention'] || $planFollowUps->isNotEmpty();
                        $planFollowUpHeading = match (true) {
                            in_array($effect['effect'], ['BLOCKED', 'PENDING_DECISION'], true), $effect['context_state'] === 'ambiguous' => $effect['label'],
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
                    @php
                        $returnEnrollmentTerm = $this->enrollmentTerm ?: (request()->integer('enrollmentTerm') ?: null);
                        $planEnrollmentUrl = $returnEnrollmentTerm
                            ? \App\Filament\Student\Pages\Enrollment::getUrl(['term' => $returnEnrollmentTerm], panel: 'student')
                            : $enrollmentUrl;
                    @endphp
                    @if ($planNeedsAdvising)
                        @include('filament.student.pages.academic-guidance', [
                            'guidanceClass' => 'student-plan-advising',
                            'guidanceHeading' => $planFollowUpHeading,
                            'guidanceReason' => $effect['needs_attention'] ? $effect['student_reason'] : 'Ask the Registrar about completing or retaking the subject before choosing your next subjects.',
                            'enrollmentUrl' => null,
                            'actionUrl' => null,
                            'actionLabel' => null,
                            'guidanceActionLabel' => null,
                        ])
                    @endif
                    <section class="tala-task-surface student-card--two-tone student-plan-overview" aria-label="Program and study plan progress">
                        <div class="student-card__body">
                            <div class="student-academic-reading-grid" style="align-items: center;">
                                <div class="student-plan-overview-program">
                                    <h2>{{ $studyContext['program_name'] ?? 'Program unavailable' }}</h2>
                                    @if ($studyContext['is_official'])
                                        <ul aria-label="Your current standing">
                                            @foreach (collect([$studyContext['study_level_label'], $studyContext['semester'], $studyContext['academic_year']])->filter() as $contextFact)
                                                <li>{{ $contextFact }}</li>
                                            @endforeach
                                        </ul>
                                    @elseif ($studyContext['study_level_label'] !== 'Unavailable')
                                        <ul aria-label="Your provisional standing">
                                            <li>{{ $studyContext['study_level_label'] }} (provisional)</li>
                                        </ul>
                                    @endif
                                </div>
                                <div class="student-plan-overview-progress">
                                    <p><strong>{{ $planCompletedUnits }}</strong>@if ($planTotalUnits > 0) of <strong>{{ $planTotalUnits }}</strong>@endif units completed</p>
                                    @if ($planTotalUnits > 0)<div class="student-tile-bar" role="img" aria-label="{{ $planPercent }} percent of curriculum units completed"><span style="inline-size: {{ $planPercent }}%"></span></div>@endif
                                </div>
                            </div>
                        </div>
                        <div class="student-card__footer" style="justify-content: flex-end;">
                            <x-filament::button tag="a" size="sm" color="gray" outlined :href="$planEnrollmentUrl" :icon="$returnEnrollmentTerm ? 'heroicon-o-arrow-left' : 'heroicon-o-clipboard-document-check'">
                                {{ $returnEnrollmentTerm ? 'Return to enrollment' : 'View enrollment' }}
                            </x-filament::button>
                        </div>
                    </section>
                    @if ($totals = $curriculum['checklist_totals'] ?? null)
                        @php
                            $completedCount = (int) $totals['passed'] + (int) ($totals['pre_tala'] ?? 0) + (int) ($totals['credited'] ?? 0);
                            $followUpCount = (int) $totals['failed'] + (int) $totals['dropped'];
                            $completedMeta = ($totals['pre_tala'] ?? 0) > 0
                                ? 'Passed at SIA: '.$totals['passed'].' · Pre-TALA: '.$totals['pre_tala'].' · Transfer / credited: '.($totals['credited'] ?? 0)
                                : 'Passed at SIA: '.$totals['passed'].' · Transfer / credited: '.($totals['credited'] ?? 0);
                            $remainingMeta = ($totals['in_progress'] ?? 0) > 0
                                ? 'In progress: '.$totals['in_progress'].' · Not yet taken: '.$totals['not_taken']
                                : 'Not yet taken: '.$totals['not_taken'];
                            $planKpis = [
                                [
                                    'label' => 'Total subjects',
                                    'value' => $totals['total'],
                                    'meta' => 'Full curriculum checklist',
                                    'icon' => 'heroicon-o-book-open',
                                    'tone' => 'default',
                                ],
                                [
                                    'label' => 'Completed',
                                    'value' => $completedCount,
                                    'meta' => $completedMeta,
                                    'icon' => 'heroicon-o-check-circle',
                                    'tone' => $completedCount > 0 ? 'success' : 'default',
                                ],
                                [
                                    'label' => 'Remaining',
                                    'value' => $totals['remaining'],
                                    'meta' => $remainingMeta,
                                    'icon' => 'heroicon-o-clock',
                                    'tone' => 'default',
                                ],
                                [
                                    'label' => 'Needs follow-up',
                                    'value' => $followUpCount,
                                    'meta' => 'Failed – retake: '.$totals['failed'].' · Dropped or INC: '.$totals['dropped'],
                                    'icon' => 'heroicon-o-exclamation-triangle',
                                    'tone' => $followUpCount > 0 ? ($totals['failed'] > 0 ? 'danger' : 'warning') : 'default',
                                ],
                            ];
                        @endphp
                        <x-tala-kpi-strip :items="$planKpis" aria-label="Subject checklist summary" />
                    @endif
                    <div class="student-plan-years" style="display: grid; gap: var(--tala-card-gap, 12px); min-inline-size: 0;" x-data="{ planYear: {{ $planOpenIndex }} }">
                        @if (! $planHasProgress)
                            <p class="student-plan-lead">
                                <x-filament::icon icon="heroicon-o-map" aria-hidden="true" />
                                <span><strong>{{ $studyContext['is_official'] ? 'No completed units recorded.' : 'These are your planned subjects.' }}</strong> {{ $studyContext['is_official'] ? 'Released passing results and approved credits will update this plan.' : 'Check Enrollment for the subjects being prepared. They become active subjects after official enrollment.' }}</span>
                            </p>
                        @endif
                        @if ($planYears->count() > 1)
                            <ol class="student-plan-year-tabs" aria-label="Curriculum years">
                                @foreach ($planYears as $planIndex => $planYear)
                                    <li>
                                        <button
                                            type="button"
                                            @class(['student-plan-year-tab', 'is-active' => $planIndex === $planOpenIndex])
                                            x-on:click="planYear = {{ $planIndex }}; $el.scrollIntoView({ block: 'nearest', inline: 'nearest' })"
                                            x-bind:class="{ 'is-active': planYear === {{ $planIndex }} }"
                                            x-bind:aria-current="planYear === {{ $planIndex }} ? 'step' : false"
                                            @if ($planIndex === $planOpenIndex) aria-current="step" @endif
                                        >
                                            <span style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; inline-size: 100%;">
                                                <span style="font-size: 0.9375rem; font-weight: 700; line-height: 1.3;">{{ $planYear['label'] }}</span>
                                                @if ($planYear['current'])
                                                    <x-filament::badge color="success" size="sm">{{ $studyContext['is_official'] ? 'Current' : 'Provisional' }}</x-filament::badge>
                                                @endif
                                            </span>
                                            <span style="font-size: 0.75rem; line-height: 1.4; color: var(--tala-muted); font-variant-numeric: tabular-nums;">{{ $planYear['done'] }} of {{ $planYear['total'] }} completed</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                        @foreach ($curriculumGroups as $year => $termGroups)
                            @php
                                $planYear = $planYears[$loop->index];
                            @endphp
                            <section class="student-plan-year" aria-labelledby="student-plan-year-{{ $loop->index }}" x-show="planYear === {{ $loop->index }}" @if ($loop->index !== $planOpenIndex) x-cloak @endif>
                                <header class="student-record-year-heading">
                                    <div class="student-record-year-title-group">
                                        <span class="student-record-year-kicker">Curriculum year</span>
                                        <h2 id="student-plan-year-{{ $loop->index }}" class="student-record-year-label">{{ $planYear['label'] }}</h2>
                                    </div>
                                    <p style="margin: 0; font-size: 0.875rem; color: var(--tala-muted); font-variant-numeric: tabular-nums;">
                                        {{ $planYear['done'] }} of {{ $planYear['total'] }} {{ $planYear['total'] === 1 ? 'subject' : 'subjects' }} completed{{ $planYear['current'] ? ($studyContext['is_official'] ? ' · Your current year' : ' · Provisional standing') : '' }}
                                    </p>
                                </header>
                                <div style="display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--tala-card-gap, 12px);">
                                    @foreach ($termGroups as $termLabel => $rows)
                                        @php
                                            $termUnits = (float) $rows->sum(fn (array $row): float => (float) $row['curriculum_entry']->courseSpecification?->credit_units);
                                        @endphp
                                        <section class="tala-task-surface student-card--two-tone student-plan-term-card">
                                            <div class="student-card__body" style="padding-block: 0.875rem;">
                                                <div style="display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 0.375rem 0.75rem;">
                                                    <h3 style="margin: 0; font-size: 1.0625rem; font-weight: 700; line-height: 1.3; color: var(--tala-text);">{{ $termLabel ?: 'Term not recorded' }}</h3>
                                                    <span style="font-size: 0.8125rem; color: var(--tala-muted); font-variant-numeric: tabular-nums;">
                                                        {{ $rows->count() }} {{ $rows->count() === 1 ? 'subject' : 'subjects' }} · {{ $termUnits }} {{ $termUnits == 1.0 ? 'unit' : 'units' }}
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="student-card__table-flush tala-data-table-scroll student-academic-table-wrap" role="region" aria-label="{{ $termLabel ?: 'Term not recorded' }} subjects" tabindex="0">
                                                <table class="tala-data-table student-academic-table student-plan-table">
                                                    <thead>
                                                        <tr>
                                                            <th scope="col">Subject</th>
                                                            <th scope="col" class="tala-data-table-number" style="inline-size: 6.5rem;">Units</th>
                                                            <th scope="col" style="inline-size: 17rem;">Status</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach ($rows as $row)
                                                            @php
                                                                $checklistState = $row['checklist_state'] ?? 'not_taken';
                                                                $badgeColor = match ($checklistState) {
                                                                    'passed', 'pre_tala' => 'success',
                                                                    'credited', 'incomplete', 'dropped' => 'warning',
                                                                    'in_progress' => 'primary',
                                                                    'failed' => 'danger',
                                                                    default => 'gray',
                                                                };
                                                                $badgeIcon = match ($checklistState) {
                                                                    'passed' => 'heroicon-o-check-circle',
                                                                    'pre_tala' => 'heroicon-o-archive-box',
                                                                    'credited' => 'heroicon-o-document-check',
                                                                    'in_progress' => 'heroicon-o-clock',
                                                                    'failed' => 'heroicon-o-x-circle',
                                                                    'incomplete', 'dropped' => 'heroicon-o-exclamation-circle',
                                                                    'locked' => 'heroicon-o-lock-closed',
                                                                    default => 'heroicon-o-minus-circle',
                                                                };
                                                                $badgeLabel = match ($checklistState) {
                                                                    'passed' => 'Passed at SIA (TALA)',
                                                                    'pre_tala' => ($row['credit']['label'] ?? 'Pre-TALA Servitech record'),
                                                                    'credited' => ($row['credit']['label'] ?? 'Transfer credit (External)'),
                                                                    'in_progress' => 'In progress',
                                                                    'failed' => 'Failed – need to retake',
                                                                    'incomplete' => 'INC – complete or retake',
                                                                    'dropped' => 'Dropped – need to take',
                                                                    'locked' => 'Locked – prerequisite not passed'.(($row['locked_by'] ?? []) !== [] ? ' ('.implode(', ', $row['locked_by']).')' : ''),
                                                                    default => 'Not yet taken',
                                                                };
                                                            @endphp
                                                            <tr>
                                                                <th scope="row">
                                                                    <span>{{ $row['curriculum_entry']->courseSpecification?->title ?? 'Subject unavailable' }}</span>
                                                                    @if (filled($row['curriculum_entry']->courseSpecification?->course?->code))
                                                                        <span class="student-academic-secondary">{{ $row['curriculum_entry']->courseSpecification?->course?->code }}</span>
                                                                    @endif
                                                                    @if (filled($row['credit']['detail'] ?? null))
                                                                        <span @class(['student-academic-secondary', 'student-plan-credit-source' => $checklistState === 'credited', 'student-plan-pre-tala-source' => $checklistState === 'pre_tala'])>{{ $row['credit']['detail'] }}</span>
                                                                    @endif
                                                                    @if (! in_array($row['status'], ['Completed', 'Approved credit'], true))
                                                                        @foreach ($planRequirements->get($row['curriculum_entry']->id) ?? [] as $requirement)
                                                                            <span class="student-academic-secondary">{{ \App\Models\CourseRequirement::typeOptions()[$requirement->rule_type] }}: {{ $requirement->relatedCourse?->code ?? 'Subject not recorded' }}</span>
                                                                        @endforeach
                                                                        @if ($row['status'] === 'Deficient')
                                                                            <span class="student-academic-secondary">Ask the Registrar about completing or retaking this subject.</span>
                                                                        @endif
                                                                    @endif
                                                                </th>
                                                                <td class="tala-data-table-number">{{ (float) $row['curriculum_entry']->courseSpecification?->credit_units }} units</td>
                                                                <td data-checklist-state="{{ $checklistState }}">
                                                                    <x-filament::badge :color="$badgeColor" :icon="$badgeIcon" size="sm" style="max-inline-size: 100%; white-space: normal; text-align: start;">
                                                                        {{ $badgeLabel }}
                                                                    </x-filament::badge>
                                                                </td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </section>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                        <footer class="student-plan-note" style="border-block-start: 0; padding-block-start: 0.25rem;">
                            <p>Completed units include released passing results and approved credits.</p>
                            <p>The Registrar maintains your curriculum evaluation and confirms your next subject selection.</p>
                        </footer>
                    </div>
                @else
                    @if ($effect['needs_attention'])
                        @include('filament.student.pages.academic-guidance', [
                            'enrollmentUrl' => null,
                            'actionUrl' => null,
                            'actionLabel' => null,
                            'guidanceActionLabel' => null,
                        ])
                    @endif
                    <section class="student-record-empty tala-task-surface">
                        <x-servitech-empty-state
                            illustrated
                            heading="Your curriculum is unavailable"
                            description="Your curriculum is unavailable. Contact the Registrar."
                        />
                    </section>
                @endif
                @if ($competencyRequirements->isNotEmpty())
                    <section class="tala-task-surface student-card--two-tone" aria-labelledby="student-external-qualifications">
                        <div class="student-card__body" style="padding-block: 0.875rem;">
                            <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem;">
                                <div>
                                    <h2 id="student-external-qualifications">External qualifications</h2>
                                    <p class="student-academic-secondary" style="margin-block-start: 0.125rem;">Qualifications tracked in your curriculum and their current verified status.</p>
                                </div>
                                @if ($competencyRequirements->contains(fn ($requirement) => $requirement->currentResult !== null))
                                    <x-filament::button tag="a" size="sm" color="gray" outlined :href="static::getUrl(panel: 'student').'#external-assessment-history'" icon="heroicon-o-clock">View assessment history</x-filament::button>
                                @endif
                            </div>
                        </div>
                        <div class="student-card__table-flush tala-data-table-scroll student-academic-table-wrap" role="region" aria-label="External qualifications" tabindex="0">
                            <table class="tala-data-table student-academic-table student-plan-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Qualification</th>
                                        <th scope="col">Requirement</th>
                                        <th scope="col" style="inline-size: 17rem;">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($competencyRequirements as $requirement)
                                        @php
                                            $result = $requirement->currentResult;
                                            $competent = $result?->outcome === \App\Models\ExternalCompetencyResult::OutcomeCompetent;
                                            $notYetCompetent = $result?->outcome === \App\Models\ExternalCompetencyResult::OutcomeNotYetCompetent;
                                            $requiredForCompletion = $requirement->treatment === \App\Models\ExternalCompetencyRequirement::TreatmentCompletionRequired;
                                            $extBadgeColor = match (true) {
                                                $competent => 'success',
                                                $notYetCompetent || ($requiredForCompletion && ! $competent) => 'warning',
                                                default => 'gray',
                                            };
                                            $extBadgeIcon = match ($result?->outcome) {
                                                \App\Models\ExternalCompetencyResult::OutcomeCompetent => 'heroicon-o-check-circle',
                                                \App\Models\ExternalCompetencyResult::OutcomeNotYetCompetent => 'heroicon-o-exclamation-circle',
                                                default => 'heroicon-o-minus-circle',
                                            };
                                        @endphp
                                        <tr>
                                            <th scope="row">
                                                <span>{{ $requirement->qualification_label }}</span>
                                                @if ($requirement->qualification_level)
                                                    <span class="student-academic-secondary">{{ $requirement->qualification_level }}</span>
                                                @endif
                                                @if ($requiredForCompletion && ! $competent)
                                                    <span class="student-academic-secondary">Ask the Registrar how to get your verified result recorded.</span>
                                                @endif
                                            </th>
                                            <td>{{ $requiredForCompletion ? 'Required for graduation' : 'Tracked only' }}</td>
                                            <td>
                                                <x-filament::badge :color="$extBadgeColor" :icon="$extBadgeIcon" size="sm" style="max-inline-size: 100%; white-space: normal; text-align: start;">
                                                    {{ \App\Models\ExternalCompetencyResult::outcomeLabel($result?->outcome) }}
                                                </x-filament::badge>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($competencyRequirements->contains('treatment', \App\Models\ExternalCompetencyRequirement::TreatmentTrackedOnly))
                            <div class="student-card__footer">
                                <p class="student-academic-note" style="padding-block-start: 0;">Qualifications marked “Tracked only” are for reference and do not affect enrollment or graduation.</p>
                            </div>
                        @endif
                    </section>
                @endif
            @elseif ($academicView === 'record')
                @if ($effect['needs_attention'])
                    @include('filament.student.pages.academic-guidance', [
                        'enrollmentUrl' => null,
                        'actionUrl' => null,
                        'actionLabel' => null,
                        'guidanceActionLabel' => null,
                    ])
                @endif
                @if ($results->whereNotNull('outcome_source')->isEmpty() && $historicalResults->isEmpty())
                    <div class="student-record-context"><strong>{{ $studyContext['program_name'] ?? 'Program not recorded' }}</strong><span>{{ trim($student->first_name.' '.$student->last_name) }}</span></div>
                    <section class="student-record-empty tala-task-surface">
                        <x-servitech-empty-state illustrated heading="No released results yet" description="Results appear after the Registrar releases them.">
                            <x-filament::button tag="a" :href="static::getUrl(['view' => 'plan'], panel: 'student')" icon="heroicon-o-map">View study plan</x-filament::button>
                        </x-servitech-empty-state>
                    </section>
                @else
                    @php
                        $recordIncomplete = $results->where('result', 'INC');
                    @endphp
                    <div class="student-record-summary-row">
                    <section class="student-plan-overview student-record-masthead" aria-label="Grades summary">
                        <div class="student-plan-overview-program">
                            <h2>{{ $studyContext['program_name'] ?? 'Program unavailable' }}</h2>
                            @if ($studyContext['is_official'])
                                <ul aria-label="Your current standing">
                                    @foreach (collect([$studyContext['study_level_label'], $studyContext['semester'], $studyContext['academic_year']])->filter() as $contextFact)
                                        <li>{{ $contextFact }}</li>
                                    @endforeach
                                </ul>
                            @elseif ($studyContext['study_level_label'] !== 'Unavailable')
                                <ul aria-label="Your provisional standing">
                                    <li>{{ $studyContext['study_level_label'] }} (provisional)</li>
                                </ul>
                            @endif
                        </div>
                        <div class="student-record-gwa">
                            <dl class="tala-profile-average-compact">
                                <dt>{{ $cumulative['label'] }}</dt>
                                <dd>
                                    @if ($cumulative['value'] !== null)
                                        <strong>{{ $cumulative['value'] }}</strong>
                                        @if (filled($cumulative['through']))
                                            <span class="tala-profile-muted">Through {{ $cumulative['through'] }}</span>
                                        @endif
                                    @elseif ($cumulative['state'] === \App\Actions\Academics\AcademicAverageReadiness::NotApplicable)
                                        <strong>Not applicable</strong>
                                        <span class="tala-profile-muted">No eligible graded subjects</span>
                                    @else
                                        <strong>GWA: Pending</strong>
                                        <span class="tala-profile-muted">{{ $cumulative['state'] === \App\Actions\Academics\AcademicAverageReadiness::IncompleteResultPending ? 'Unresolved INC' : 'Results incomplete' }}</span>
                                    @endif
                                </dd>
                            </dl>
                        </div>
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
                                            <span class="student-record-inc-deadline"><x-filament::icon icon="heroicon-o-calendar-days" /><time datetime="{{ $recordIncomplete->first()['inc_deadline']->toDateString() }}">{{ $recordIncomplete->first()['inc_deadline']->format('M j, Y') }}</time></span>
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
                    <div class="student-record-years">
                        @foreach ($recordAcademicYears as $yearGroup)
                            @php
                                $yearGwa = $yearGroup['gwa'];
                            @endphp
                            <section class="student-record-year" aria-label="{{ $yearGroup['label'] }}">
                                <header class="student-record-year-heading">
                                    <div class="student-record-year-title-group">
                                        <span class="student-record-year-kicker">Academic year</span>
                                        <p class="student-record-year-label">{{ $yearGroup['label'] }}</p>
                                    </div>
                                    <dl class="student-record-year-gwa">
                                        <dt>Academic Year GWA</dt>
                                        <dd>
                                            @if ($yearGwa['value'] !== null)
                                                <strong>{{ $yearGwa['value'] }}</strong>
                                            @elseif ($yearGwa['state'] === \App\Actions\Academics\AcademicAverageReadiness::NotApplicable)
                                                <strong>Not applicable</strong>
                                            @else
                                                <strong>GWA: Pending</strong>
                                            @endif
                                        </dd>
                                    </dl>
                                </header>
                                <div class="student-record-terms">@foreach ($yearGroup['periods'] as $period)
                                    @php
                                        $average = $period['average'];
                                    @endphp
                                    <section class="student-record-term">
                                        <header class="student-record-term-heading">
                                            <div><h2>{{ $period['label'] }}</h2>@if (filled($period['subtitle']))<p>{{ $period['subtitle'] }}</p>@endif</div>
                                            <div class="student-record-term-average"><x-academic-average :$average compact /></div>
                                        </header>
                                        <div class="tala-data-table-scroll student-academic-table-wrap" role="region" aria-label="{{ $period['label'] }} grades" tabindex="0"><table class="tala-data-table student-academic-table"><thead><tr><th scope="col">Subject</th><th scope="col">Units</th><th scope="col">Grades</th></tr></thead><tbody>
                                            @foreach ($period['rows'] as $result)
                                                <tr>
                                                    <th scope="row">{{ $result['course_specification']?->title }}<span class="student-academic-secondary">{{ $result['course_specification']?->course?->code }}</span></th>
                                                    <td>{{ (float) $result['units'] }}</td>
                                                    <td @class(['student-record-grade' => is_numeric($result['result'])])>
                                                        @if (($result['course_enrollment'] ?? null) instanceof \App\Models\CourseEnrollment)
                                                            <x-student-academic-result :$result :show-details="false" />
                                                            @if (($hasPriorCredits ?? false) && $result['outcome_source'] !== null)
                                                                <span class="student-academic-secondary student-record-source-label">
                                                                    {{ \App\Actions\Enrollment\RegistrationAcademicEligibilityQuery::isPassingResult($result['result'] ?? null) ? 'Passed at SIA (TALA)' : 'SIA (TALA) record' }}
                                                                </span>
                                                            @endif
                                                        @else
                                                            @php
                                                                $priorCredit = $result['prior_credit'] ?? null;
                                                                $isExternalTransfer = $priorCredit instanceof \App\Models\PriorAcademicCredit
                                                                    && \App\Actions\Academics\CurriculumEvaluation::isExternalTransferPriorCredit($priorCredit);
                                                                $priorSourceLabel = $isExternalTransfer
                                                                    ? 'Transfer credit (External)'.(filled($priorCredit->source_school) ? ' · '.$priorCredit->source_school : '')
                                                                    : 'Pre-TALA Servitech record';
                                                            @endphp
                                                            @if ($result['result'] === 'INC')
                                                                <x-filament::badge color="warning" id="prior-credit-result-{{ $priorCredit?->id ?? $loop->index }}">INC</x-filament::badge>
                                                            @else
                                                                <span id="prior-credit-result-{{ $priorCredit?->id ?? $loop->index }}">{{ $result['result'] ?? 'Not released' }}</span>
                                                            @endif
                                                            <span class="student-academic-secondary student-record-source-label">{{ $priorSourceLabel }}</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody></table></div>
                                        @if ($average['value'] === null)<p class="student-record-term-note">{{ $average['reason'] }}</p>@endif
                                    </section>
                                @endforeach</div>
                            </section>
                        @endforeach
                    </div>
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
