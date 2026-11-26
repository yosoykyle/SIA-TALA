<x-filament-panels::page class="tala-term-workspace tala-guide-calendar">
    @include('filament.pages.partials.term-workspace-header')
    @if ($term)
        @php
            $scheduleSummary = $this->teachingScheduleSummary($overviewPackage, $term);
            $keyDatesLocked = ! $activePackage || $draftPackages->isNotEmpty();
            $hasAnyKeyDate = collect($operationalDates)->contains(fn (array $item): bool => $item['window'] !== null);
            $resolvedStepState = (isset($stepState) && is_array($stepState))
                ? $stepState
                : \App\Support\TermPlanningStepState::evaluate($term, (bool) ($readOnly ?? false));
            $isLiveOrClosed = (bool) ($resolvedStepState['is_live_or_closed'] ?? ($this->isTermClosed($term) || $this->hasPublishedTimetable($term)));
            $classesUnlocked = (bool) ($resolvedStepState['steps']['classes']['unlocked'] ?? ($activePackage !== null));
            $calendarScheduleLocked = $this->isCalendarScheduleLocked($term);
            $hasPublished = $this->hasPublishedTimetable($term);
            $enrolledStudentCount = $hasPublished ? $this->officialStudentEnrollmentCount($term) : 0;
            $hasOfficialEnrollments = $enrolledStudentCount > 0;
        @endphp
        <div class="tala-calendar-grid">
            <section class="tala-task-surface tala-calendar-sheet" aria-labelledby="teaching-calendar-heading">
                @if ($overviewPackage)
                    @php
                        $packageStateColor = match ($overviewPackage->state) {
                            \App\Models\TermCalendarPackage::StateActive => 'success',
                            \App\Models\TermCalendarPackage::StateDraft => 'warning',
                            default => 'gray',
                        };
                        $packageStateLabel = match ($overviewPackage->state) {
                            \App\Models\TermCalendarPackage::StateActive => 'Active',
                            \App\Models\TermCalendarPackage::StateDraft => 'Draft',
                            \App\Models\TermCalendarPackage::StateClosed => 'Closed',
                            default => (string) $overviewPackage->state,
                        };
                    @endphp
                    <header class="tala-calendar-sheet-heading">
                        <div class="tala-calendar-sheet-heading__title">
                            <h2 id="teaching-calendar-heading"><x-filament::icon icon="heroicon-o-calendar" aria-hidden="true" /><span>Teaching schedule &amp; dates</span></h2>
                            <x-filament::badge :color="$packageStateColor" size="sm">{{ $packageStateLabel }}</x-filament::badge>
                            @if ($overviewPackage->updated_at)
                                <span class="tala-calendar-sheet-heading__meta tabular-nums">Updated {{ $overviewPackage->updated_at->timezone(config('app.display_timezone'))->format('M j, Y') }}</span>
                            @endif
                        </div>
                        @if (! $readOnly && ! $calendarScheduleLocked)
                            <div class="tala-calendar-sheet-heading__action flex flex-wrap items-center gap-2">
                                @if ($draftPackages->isNotEmpty())
                                    {{ $this->discardDraftCalendarAction }}
                                @endif
                                {{ $this->saveAndActivateCalendarAction }}
                            </div>
                        @endif
                    </header>

                    @if ($this->isTermClosed($term))
                        <div class="tala-calendar-published-strip">
                            <div class="tala-calendar-published-strip__copy">
                                <x-filament::icon icon="heroicon-o-lock-closed" aria-hidden="true" />
                                <span>This term is closed and read-only.</span>
                            </div>
                        </div>
                    @elseif (! $readOnly && $hasPublished && ! $hasOfficialEnrollments)
                        <div class="tala-warning-banner mt-3 flex flex-wrap items-center justify-between gap-3" role="status">
                            <div class="tala-calendar-published-strip__copy">
                                <x-filament::icon icon="heroicon-o-information-circle" aria-hidden="true" />
                                <span>Published timetable has 0 active student enrollments. Editing teaching hours or daily breaks is allowed, and you will need to generate and publish a new draft in 4. Timetable for the changes to take effect.</span>
                            </div>
                            <x-filament::button
                                tag="a"
                                :href="\App\Filament\Pages\TermTimetable::getUrl(['termId' => $term->id, 'timetableState' => 'official'])"
                                color="gray"
                                size="sm"
                                icon="heroicon-m-arrow-right"
                                icon-position="after"
                            >
                                Open timetable
                            </x-filament::button>
                        </div>
                    @elseif (! $readOnly && $hasPublished && $hasOfficialEnrollments)
                        <div class="tala-calendar-published-strip">
                            <div class="tala-calendar-published-strip__copy">
                                <x-filament::icon icon="heroicon-o-lock-closed" aria-hidden="true" />
                                <span>Teaching hours and daily breaks are locked because {{ $enrolledStudentCount === 1 ? '1 student is' : $enrolledStudentCount.' students are' }} enrolled in this term's official timetable. Daily breaks can no longer be changed for this term; use Revise official schedule in 4. Timetable only to move individual classes.</span>
                            </div>
                            <x-filament::button
                                tag="a"
                                :href="\App\Filament\Pages\TermTimetable::getUrl(['termId' => $term->id, 'timetableState' => 'official'])"
                                color="gray"
                                size="sm"
                                icon="heroicon-m-arrow-right"
                                icon-position="after"
                            >
                                Open timetable
                            </x-filament::button>
                        </div>
                    @endif

                    <dl class="tala-calendar-facts">
                        <div class="tala-calendar-facts__item">
                            <dt>Classes run</dt>
                            <dd class="tabular-nums">{{ $scheduleSummary['classes_run'] }}</dd>
                            @if ($scheduleSummary['classes_run_note'])
                                <dd class="tala-calendar-facts__note">{{ $scheduleSummary['classes_run_note'] }}</dd>
                            @endif
                        </div>
                        <div class="tala-calendar-facts__item">
                            <dt>Teaching hours</dt>
                            <dd class="tabular-nums">{{ $scheduleSummary['teaching_hours'] }}</dd>
                            <dd class="tala-calendar-facts__note">{{ $scheduleSummary['teaching_hours_note'] }}</dd>
                        </div>
                        <div class="tala-calendar-facts__item">
                            <dt>Schedule increments</dt>
                            <dd class="tabular-nums">{{ $scheduleSummary['grid_slots'] }}</dd>
                            <dd class="tala-calendar-facts__note">{{ $scheduleSummary['grid_slots_note'] }}</dd>
                        </div>
                    </dl>

                    @include('filament.pages.partials.term-teaching-week', ['teachingWeekRows' => $this->teachingWeekRows($overviewPackage)])

                    @if (! $readOnly && ! $calendarScheduleLocked && ! $scheduleSummary['has_breaks'] && $overviewPackage->teachingGridRows->isNotEmpty())
                        <p class="tala-work-footnote">No daily breaks set yet. Add lunch or institutional breaks via Edit calendar before generating a timetable.</p>
                    @endif

                    @php
                        $calendarMeta = implode(' · ', array_filter([
                            $activePackage && $coreReadiness['ready'] && $draftPackages->isEmpty() ? 'Calendar ready for class planning' : null,
                            $term->type === \App\Models\Term::TypeSpecialTerm ? 'Special Term basis: '.($overviewPackage->special_term_schedule_basis ?: 'Not recorded') : null,
                        ]));
                    @endphp

                    @if (! $coreReadiness['ready'])
                        <div class="tala-task-finding">
                            <ul>
                                @foreach ($coreReadiness['blockers'] as $blocker)
                                    <li><strong>{{ $blocker['reason'] }}</strong> {{ $readOnly ? 'Responsible: '.$blocker['owner'] : $blocker['next_action'] }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @elseif ($draftPackages->isNotEmpty())
                        <p class="tala-work-footnote">Review and activate this draft when ready. The active calendar stays in use until then.</p>
                    @endif

                    @if ($overviewPackage->datedExceptions->isNotEmpty())
                        <div class="tala-calendar-exceptions">
                            <h3>School events &amp; no-class days</h3>
                            <ul>
                                @foreach ($overviewPackage->datedExceptions as $exception)
                                    @php
                                        $exceptionTypeLabel = $this->datedExceptionTypeLabel($exception->exception_type);
                                    @endphp
                                    <li><span class="tabular-nums">{{ $exception->starts_on->format('M j') }} – {{ $exception->ends_on->format('M j, Y') }}</span> · {{ $exception->label }} · {{ $exception->blocks_teaching ? ($exceptionTypeLabel && $exception->exception_type !== 'NoClasses' ? $exceptionTypeLabel.' · No classes' : 'No classes') : ($exceptionTypeLabel ?? 'Recorded') }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($calendarMeta !== '')
                        <p class="tala-work-footnote tala-calendar-footnote">{{ $calendarMeta }}</p>
                    @endif
                @else
                    <header class="tala-calendar-sheet-heading">
                        <div class="tala-calendar-sheet-heading__title">
                            <h2 id="teaching-calendar-heading"><x-filament::icon icon="heroicon-o-calendar" aria-hidden="true" /><span>Teaching schedule &amp; dates</span></h2>
                            <x-filament::badge color="gray" size="sm">Not set</x-filament::badge>
                        </div>
                        @if (! $readOnly && ! $calendarScheduleLocked)
                            <div class="tala-calendar-sheet-heading__action flex flex-wrap items-center gap-2">
                                @if ($draftPackages->isNotEmpty())
                                    {{ $this->discardDraftCalendarAction }}
                                @endif
                                {{ $this->saveAndActivateCalendarAction }}
                            </div>
                        @endif
                    </header>

                    @if ($this->isTermClosed($term))
                        <div class="tala-calendar-published-strip">
                            <div class="tala-calendar-published-strip__copy">
                                <x-filament::icon icon="heroicon-o-lock-closed" aria-hidden="true" />
                                <span>This term is closed and read-only.</span>
                            </div>
                        </div>
                    @elseif (! $readOnly && $hasPublished && ! $hasOfficialEnrollments)
                        <div class="tala-warning-banner mt-3 flex flex-wrap items-center justify-between gap-3" role="status">
                            <div class="tala-calendar-published-strip__copy">
                                <x-filament::icon icon="heroicon-o-information-circle" aria-hidden="true" />
                                <span>Published timetable has 0 active student enrollments. Editing teaching hours or daily breaks is allowed, and you will need to generate and publish a new draft in 4. Timetable for the changes to take effect.</span>
                            </div>
                            <x-filament::button
                                tag="a"
                                :href="\App\Filament\Pages\TermTimetable::getUrl(['termId' => $term->id, 'timetableState' => 'official'])"
                                color="gray"
                                size="sm"
                                icon="heroicon-m-arrow-right"
                                icon-position="after"
                            >
                                Open timetable
                            </x-filament::button>
                        </div>
                    @elseif (! $readOnly && $hasPublished && $hasOfficialEnrollments)
                        <div class="tala-calendar-published-strip">
                            <div class="tala-calendar-published-strip__copy">
                                <x-filament::icon icon="heroicon-o-lock-closed" aria-hidden="true" />
                                <span>Teaching hours and daily breaks are locked because {{ $enrolledStudentCount === 1 ? '1 student is' : $enrolledStudentCount.' students are' }} enrolled in this term's official timetable. Daily breaks can no longer be changed for this term; use Revise official schedule in 4. Timetable only to move individual classes.</span>
                            </div>
                            <x-filament::button
                                tag="a"
                                :href="\App\Filament\Pages\TermTimetable::getUrl(['termId' => $term->id, 'timetableState' => 'official'])"
                                color="gray"
                                size="sm"
                                icon="heroicon-m-arrow-right"
                                icon-position="after"
                            >
                                Open timetable
                            </x-filament::button>
                        </div>
                    @endif

                    <dl class="tala-calendar-facts">
                        <div class="tala-calendar-facts__item">
                            <dt>Classes run</dt>
                            <dd class="tabular-nums">{{ $scheduleSummary['classes_run'] }}</dd>
                            @if ($scheduleSummary['classes_run_note'])
                                <dd class="tala-calendar-facts__note">{{ $scheduleSummary['classes_run_note'] }}</dd>
                            @endif
                        </div>
                        <div class="tala-calendar-facts__item">
                            <dt>Teaching hours</dt>
                            <dd class="tabular-nums">{{ $scheduleSummary['teaching_hours'] }}</dd>
                            <dd class="tala-calendar-facts__note">{{ $scheduleSummary['teaching_hours_note'] }}</dd>
                        </div>
                        <div class="tala-calendar-facts__item">
                            <dt>Schedule increments</dt>
                            <dd class="tabular-nums">{{ $scheduleSummary['grid_slots'] }}</dd>
                            <dd class="tala-calendar-facts__note">{{ $scheduleSummary['grid_slots_note'] }}</dd>
                        </div>
                    </dl>

                    <p class="tala-work-footnote">Pre-filled with term dates and default Mon–Sat hours. Activate the calendar to unlock Step 2 (Sections &amp; classes).</p>
                @endif
            </section>

            <section class="tala-task-surface tala-calendar-activities" aria-labelledby="activity-dates-heading">
                <header class="tala-calendar-sheet-heading">
                    <div class="tala-calendar-sheet-heading__title">
                        <h2 id="activity-dates-heading"><x-filament::icon icon="heroicon-o-calendar" aria-hidden="true" /><span>Key term dates</span></h2>
                        <x-filament::badge color="gray" size="sm">Optional</x-filament::badge>
                    </div>
                    @if (! $readOnly && ! $this->isTermClosed($term) && ! $keyDatesLocked)
                        <div class="tala-calendar-sheet-heading__action">
                            {{ $this->addOperationalDatesAction->label($hasAnyKeyDate ? 'Edit dates' : 'Set dates')->color('gray')->icon('heroicon-o-pencil-square')->size('sm') }}
                        </div>
                    @endif
                </header>
                <p class="tala-work-footnote">Optional before timetable generation. Activating the teaching schedule unlocks Step 2; configure these windows later before opening student enrollment or faculty grading.</p>

                @if ($keyDatesLocked)
                    <p class="tala-calendar-lock-note">
                        <x-filament::icon icon="heroicon-o-lock-closed" aria-hidden="true" />
                        <span>Activate the teaching schedule above before setting key term dates.</span>
                    </p>
                @endif

                @php
                    $linkedAdmissionCycles = $this->linkedAdmissionCycles($term);
                    $admissionsIndexUrl = $this->admissionsIndexUrl();
                @endphp
                <section @class(['tala-calendar-admissions', 'has-cycles' => $linkedAdmissionCycles !== []]) aria-labelledby="linked-admissions-heading">
                    <div class="tala-calendar-admissions__lead">
                        <h3 id="linked-admissions-heading" class="tala-calendar-admissions__heading">
                            <x-filament::icon icon="heroicon-o-user-plus" class="h-4 w-4 shrink-0" aria-hidden="true" />
                            <span>Admissions</span>
                        </h3>
                        @if ($linkedAdmissionCycles === [])
                            <p class="tala-calendar-admissions__empty">No admission cycle linked for this term yet.</p>
                        @endif
                    </div>
                    @if (! $readOnly)
                        <div class="tala-calendar-admissions__cta">
                            <x-filament::button tag="a" :href="$admissionsIndexUrl" color="gray" size="sm" icon="heroicon-o-arrow-top-right-on-square" icon-position="after">
                                Manage in Admissions
                            </x-filament::button>
                        </div>
                    @endif

                    @if ($linkedAdmissionCycles !== [])
                        <ul class="tala-calendar-admissions__list">
                            @foreach ($linkedAdmissionCycles as $cycle)
                                <li class="tala-calendar-admissions__item">
                                    <div class="min-w-0">
                                        @if (! $readOnly)
                                            <a href="{{ $cycle['url'] }}" class="tala-calendar-admissions__title fi-link">{{ $cycle['label'] }}</a>
                                        @else
                                            <span class="tala-calendar-admissions__title">{{ $cycle['label'] }}</span>
                                        @endif
                                        <p class="tala-calendar-admissions__meta">
                                            {{ $cycle['date_range'] }}@if ($cycle['time_note']) · {{ $cycle['time_note'] }}@endif
                                        </p>
                                    </div>
                                    <x-filament::badge :color="$cycle['status_color']" size="sm">{{ $cycle['status'] }}</x-filament::badge>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <dl @class(['tala-calendar-activity-list', 'is-locked' => $keyDatesLocked])>
                    @foreach ($operationalDates as $item)
                        @php($activityIcon = match ($item['type']) {
                            \App\Models\TermCalendarWindow::TypeEnrollment => 'heroicon-o-clipboard-document-check',
                            \App\Models\TermCalendarWindow::TypeLateEnrollment => 'heroicon-o-clock',
                            \App\Models\TermCalendarWindow::TypeEnrollmentAdjustment => 'heroicon-o-arrows-right-left',
                            \App\Models\TermCalendarWindow::TypeCourseDrop => 'heroicon-o-minus-circle',
                            \App\Models\TermCalendarWindow::TypeExaminationPeriod => 'heroicon-o-academic-cap',
                            default => 'heroicon-o-pencil-square',
                        })
                        <div @class(['tala-calendar-activity', 'is-recorded' => (bool) $item['window'], 'is-unset' => ! $item['window'], 'is-locked' => $keyDatesLocked])>
                            <dt>
                                <x-filament::icon :icon="$activityIcon" aria-hidden="true" />
                                <span class="tala-calendar-activity__label">
                                    <span>{{ $item['label'] }}</span>
                                    @if ($item['type'] === \App\Models\TermCalendarWindow::TypeExaminationPeriod)
                                        <span class="tala-calendar-inline-tag">Calendar notice</span>
                                        <span class="tala-calendar-activity__hint">Shown on student &amp; faculty calendars · Does not pause classes or lock grades.</span>
                                    @elseif ($item['type'] === \App\Models\TermCalendarWindow::TypeEnrollment && ! $item['window'])
                                        <span class="tala-calendar-activity__hint">Required before opening student enrollment</span>
                                    @elseif ($item['type'] === \App\Models\TermCalendarWindow::TypeGradeEntry && ! $item['window'])
                                        <span class="tala-calendar-activity__hint">Required before faculty can submit grades</span>
                                    @endif
                                </span>
                            </dt>
                            <dd>
                                @if ($item['window'])
                                    <div class="tala-calendar-activity-period">
                                        <strong class="tabular-nums"><time datetime="{{ $item['window']->opens_on->toDateString() }}">{{ $item['window']->opens_on->format('M j') }}</time> – <time datetime="{{ $item['window']->closes_on->toDateString() }}">{{ $item['window']->closes_on->format('M j, Y') }}</time></strong>
                                        @if ($item['window']->cutoff_at)<small class="tabular-nums">{{ $item['type'] === \App\Models\TermCalendarWindow::TypeExaminationPeriod ? 'Ends' : 'Closes' }} {{ \Carbon\CarbonImmutable::parse($item['window']->cutoff_at)->format('g:i A') }} · Asia/Manila</small>@endif
                                    </div>
                                    <x-filament::badge :color="$item['status'] === 'Open' ? 'success' : 'gray'" size="sm">{{ $item['status'] }}</x-filament::badge>
                                @else
                                    <span class="tala-calendar-unset">Not set</span>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>

                <p class="tala-work-footnote tala-calendar-footnote">Times use Asia/Manila. Activity dates do not block class planning.</p>
            </section>
        </div>

        @if (! $readOnly && ! $isLiveOrClosed && $classesUnlocked)
            <div class="tala-stage-next">
                <p class="tala-stage-next__copy">Term calendar is active. Continue to set up student sections and classes.</p>
                <x-filament::button
                    tag="a"
                    :href="\App\Filament\Pages\TermClasses::getUrl(['termId' => $term->id])"
                    color="primary"
                    size="sm"
                    icon="heroicon-m-arrow-right"
                    icon-position="after"
                >
                    Next: 2. Sections &amp; classes
                </x-filament::button>
            </div>
        @endif
    @else
        <section class="tala-task-surface" aria-label="Term calendar">
            <x-servitech-empty-state
                :illustrated="false"
                icon="heroicon-o-calendar"
                heading="Choose a term"
                description="Open Term Planning to select a term and view its calendar."
            >
                <x-filament::button
                    tag="a"
                    :href="\App\Filament\Pages\TermOverview::getUrl()"
                    color="primary"
                    size="sm"
                    icon="heroicon-m-arrow-right"
                    icon-position="after"
                >
                    Open Term Planning
                </x-filament::button>
            </x-servitech-empty-state>
        </section>
    @endif
    <x-filament-actions::modals />
</x-filament-panels::page>
