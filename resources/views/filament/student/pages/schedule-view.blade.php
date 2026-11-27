<x-filament-panels::page>
    @assets<link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">@endassets
    @php
        $hasWeeklyMeetings = $schedulePresentation && ($schedulePresentation['meetings'] ?? []) !== [];
        $hasExternallyArranged = ($externallyArrangedClasses ?? []) !== [];
        $currentTab = ($activeTab ?? 'schedule') === 'calendar' ? 'calendar' : 'schedule';
        $studentUser = auth()->user();
        $studentProfile = $studentUser?->studentProfile;
        $studentInitials = $studentProfile
            ? str($studentProfile->first_name)->substr(0, 1).str($studentProfile->last_name)->substr(0, 1)
            : collect(preg_split('/\s+/', trim((string) ($studentName ?? ''))))->filter()->take(2)->map(fn (string $part): string => str($part)->substr(0, 1)->upper()->toString())->implode('');
        $scheduleIdentityProgram = collect([$programName ?? null, $selectedTermFullLabel ?? null])->filter()->implode(' · ') ?: null;
    @endphp
    <div class="learner-enrollment student-schedule-reading space-y-4">
        @if (filled($studentNumber ?? null) || filled($studentName ?? null) || filled($programName ?? null) || filled($selectedTermFullLabel ?? null))
            <x-record-identity
                :name="$studentName ?: 'Student'"
                :number="$studentNumber"
                number-label="Student No."
                :show-number-label="true"
                :program="$scheduleIdentityProgram"
                :initials="$studentInitials ?: null"
                :photo-url="$studentUser?->avatar_path ? $studentUser->getFilamentAvatarUrl() : null"
                role="group"
                aria-label="Student schedule identity"
            >
                @if (filled($selectedTermLabel ?? null))
                    <x-slot:status>
                        <x-filament::badge color="success" size="sm">{{ $selectedTermLabel }}</x-filament::badge>
                    </x-slot:status>
                @endif
            </x-record-identity>
        @endif

        <div class="student-schedule-nav-bar">
            <x-tala-navigation label="Schedule sections" stretch>
                <x-filament::tabs.item
                    wire:key="student-schedule-tab-schedule"
                    :active="$currentTab === 'schedule'"
                    icon="heroicon-o-calendar-days"
                    wire:click="setActiveTab('schedule')"
                >
                    Class Schedule
                </x-filament::tabs.item>
                <x-filament::tabs.item
                    wire:key="student-schedule-tab-calendar"
                    :active="$currentTab === 'calendar'"
                    icon="heroicon-o-calendar"
                    wire:click="setActiveTab('calendar')"
                >
                    Academic Calendar
                </x-filament::tabs.item>
            </x-tala-navigation>

            @if (count($activeTermOptions ?? []) >= 2)
                <x-tala-navigation label="Active term schedule" compact>
                    @foreach ($activeTermOptions as $option)
                        <x-filament::tabs.item
                            wire:key="student-schedule-term-{{ $option['term_id'] }}"
                            :active="$option['is_selected']"
                            icon="heroicon-o-calendar-days"
                            wire:click="selectTerm({{ $option['term_id'] }})"
                        >
                            {{ $option['label'] }}
                        </x-filament::tabs.item>
                    @endforeach
                </x-tala-navigation>
            @endif
        </div>

        @if ($currentTab === 'schedule')
            @if (($recentScheduleRevisions ?? []) !== [])
                <section class="tala-warning-banner" role="status" aria-labelledby="student-schedule-changes-heading">
                    <div class="space-y-1">
                        <h2 id="student-schedule-changes-heading" class="flex items-center gap-2 text-sm font-semibold">
                            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5" aria-hidden="true" />
                            <span>Affected by recent change</span>
                        </h2>
                        <p class="text-sm">
                            {{ count($recentScheduleRevisions) === 1 ? '1 of your classes has a recent schedule update.' : count($recentScheduleRevisions).' of your classes have recent schedule updates.' }}
                        </p>
                    </div>
                    <ul class="tala-faculty-affected-list mt-1">
                        @foreach ($recentScheduleRevisions as $revision)
                            <li class="tala-faculty-affected-row">
                                <div class="tala-faculty-affected-row__main">
                                    <x-filament::badge :color="$revision['badge_color']" size="sm">
                                        {{ $revision['badge_label'] }}
                                    </x-filament::badge>
                                    <strong>{{ $revision['course_section'] }}</strong>
                                    <span class="tabular-nums">{{ $revision['schedule_line'] }}</span>
                                </div>
                                <span class="tala-faculty-affected-row__detail">{{ $revision['detail_line'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($hasOfficialEnrollment && ($termTeachingRange !== null || $examinationPeriod !== null || ($upcomingHolidays ?? []) !== []))
                <section class="student-schedule-highlights" aria-label="Term dates and exam period">
                    <div class="student-schedule-highlights__cards">
                        @if ($termTeachingRange !== null)
                            <div class="student-schedule-highlight-card student-schedule-highlight-card--classes">
                                <span class="student-schedule-highlight-card__icon" aria-hidden="true">
                                    <x-filament::icon icon="heroicon-o-calendar-days" />
                                </span>
                                <div class="student-schedule-highlight-card__body">
                                    <span class="student-schedule-highlight-card__label">Classes:</span>
                                    <strong class="student-schedule-highlight-card__value tabular-nums">{{ $termTeachingRange }}</strong>
                                </div>
                                @if ($selectedTermLabel)
                                    <x-filament::badge color="success" size="sm">{{ $selectedTermLabel }}</x-filament::badge>
                                @endif
                            </div>
                        @endif

                        <div class="student-schedule-highlight-card student-schedule-highlight-card--exam">
                            <span class="student-schedule-highlight-card__icon" aria-hidden="true">
                                <x-filament::icon icon="heroicon-o-academic-cap" />
                            </span>
                            <div class="student-schedule-highlight-card__body">
                                <span class="student-schedule-highlight-card__label">Exam period:</span>
                                <strong class="student-schedule-highlight-card__value tabular-nums">
                                    {{ $examinationPeriod ? $examinationPeriod->opens_on->format('M j').'–'.$examinationPeriod->closes_on->format('M j, Y') : 'Dates to be announced' }}
                                </strong>
                            </div>
                            <x-filament::badge :color="$examinationPeriod ? 'warning' : 'gray'" size="sm">
                                {{ $examinationPeriod ? 'Scheduled' : 'TBA' }}
                            </x-filament::badge>
                        </div>
                    </div>

                    @if (($upcomingHolidays ?? []) !== [])
                        <div class="student-schedule-highlights__holidays">
                            <span class="student-schedule-highlights__holidays-label">Upcoming no-class dates:</span>
                            <div class="flex flex-wrap items-center gap-1.5">
                                @foreach ($upcomingHolidays as $holiday)
                                    <x-filament::badge color="gray" size="sm" class="tabular-nums">
                                        {{ $holiday['date_label'] }} · {{ $holiday['label'] }}
                                    </x-filament::badge>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </section>
            @endif

            @if ($hasWeeklyMeetings)
                <x-enrollment-schedule
                    :presentation="$schedulePresentation"
                    source-key="student-schedule-{{ $termId ?? 'current' }}"
                    :show-mode-toggle="false"
                />
            @endif

            @if ($hasExternallyArranged)
                <section class="tala-task-surface p-5 space-y-3" aria-labelledby="student-externally-arranged-heading">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <div>
                            <h2 id="student-externally-arranged-heading" class="text-base font-semibold">Externally arranged classes</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Practicum, OJT, or externally arranged subjects with no fixed weekly classroom slot.
                            </p>
                        </div>
                        <x-filament::badge color="gray" size="sm">
                            {{ count($externallyArrangedClasses) }} {{ count($externallyArrangedClasses) === 1 ? 'class' : 'classes' }}
                        </x-filament::badge>
                    </div>

                    <div class="tala-data-table-scroll student-academic-table-wrap" role="region" aria-labelledby="student-externally-arranged-heading" tabindex="0">
                        <table class="tala-data-table student-academic-table">
                            <thead>
                                <tr>
                                    <th scope="col">Subject</th>
                                    <th scope="col">Class</th>
                                    <th scope="col">Units</th>
                                    <th scope="col">Faculty / Adviser</th>
                                    <th scope="col">Arrangement</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($externallyArrangedClasses as $item)
                                    <tr wire:key="student-external-class-{{ $loop->index }}">
                                        <th scope="row">
                                            {{ $item['course_title'] }}
                                            <span class="student-academic-secondary">{{ $item['course_code'] }}</span>
                                        </th>
                                        <td>{{ $item['section_code'] }}</td>
                                        <td class="student-academic-number tabular-nums">{{ $item['units'] }}</td>
                                        <td>{{ $item['faculty'] }}</td>
                                        <td>{{ $item['note'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            @if (! $hasWeeklyMeetings && ! $hasExternallyArranged)
                <x-servitech-empty-state :illustrated="! $hasOfficialEnrollment" class="tala-task-surface student-empty-card"
                    :heading="$hasOfficialEnrollment ? 'Class meetings are not available yet' : 'Your class schedule starts with official enrollment'"
                    :description="$hasOfficialEnrollment ? 'Ask the Registrar about the published meetings for your registered classes.' : 'Your weekly schedule appears here after your enrollment is official.'">
                    <x-filament::button tag="a" color="gray" outlined :href="\App\Filament\Student\Pages\Enrollment::getUrl(panel: 'student')">View enrollment</x-filament::button>
                </x-servitech-empty-state>
            @endif
        @else
            <div class="student-schedule-calendar space-y-4">
                @if (! $calendarPackage)
                    <section class="tala-task-surface" aria-label="Academic calendar status">
                        <x-servitech-empty-state
                            :illustrated="false"
                            icon="heroicon-o-calendar"
                            heading="Term calendar is not active yet"
                            description="Teaching dates, approved weekly hours, academic windows, and no-class dates appear here once the Registrar activates the term calendar."
                        />
                    </section>
                @else
                    <div class="tala-calendar-grid">
                        <section class="tala-task-surface tala-calendar-sheet" aria-labelledby="student-teaching-period-heading">
                            <header class="tala-calendar-sheet-heading">
                                <div class="tala-calendar-sheet-heading__title">
                                    <h2 id="student-teaching-period-heading">
                                        <x-filament::icon icon="heroicon-o-calendar" aria-hidden="true" />
                                        <span>Teaching period &amp; weekly hours</span>
                                    </h2>
                                    <x-filament::badge color="success" size="sm">Active</x-filament::badge>
                                </div>
                                @if ($teachingPeriod['updated_at_label'] ?? null)
                                    <span class="tala-calendar-sheet-heading__meta tabular-nums">
                                        Updated <time datetime="{{ $teachingPeriod['updated_at_iso'] }}">{{ $teachingPeriod['updated_at_label'] }}</time>
                                    </span>
                                @endif
                            </header>

                            <dl class="tala-calendar-facts">
                                <div class="tala-calendar-facts__item student-calendar-fact--classes">
                                    <dt>Classes run</dt>
                                    <dd class="tabular-nums">
                                        @if (($teachingPeriod['classes_start_iso'] ?? null) && ($teachingPeriod['classes_end_iso'] ?? null))
                                            <time datetime="{{ $teachingPeriod['classes_start_iso'] }}">{{ $teachingPeriod['classes_start_label'] }}</time>
                                            –
                                            <time datetime="{{ $teachingPeriod['classes_end_iso'] }}">{{ $teachingPeriod['classes_end_label'] }}</time>
                                        @else
                                            Dates not set
                                        @endif
                                    </dd>
                                    @if (($teachingPeriod['admin_start_iso'] ?? null) && ($teachingPeriod['admin_end_iso'] ?? null))
                                        <dd class="tala-calendar-facts__note tabular-nums">
                                            Term dates:
                                            <time datetime="{{ $teachingPeriod['admin_start_iso'] }}">{{ $teachingPeriod['admin_start_label'] }}</time>
                                            –
                                            <time datetime="{{ $teachingPeriod['admin_end_iso'] }}">{{ $teachingPeriod['admin_end_label'] }}</time>
                                        </dd>
                                    @endif
                                </div>

                                <div class="tala-calendar-facts__item">
                                    <dt>Approved teaching hours</dt>
                                    <dd class="tabular-nums">{{ $teachingPeriod['teaching_hours'] ?? 'Not configured' }}</dd>
                                    <dd class="tala-calendar-facts__note">{{ $teachingPeriod['teaching_hours_note'] ?? '' }}</dd>
                                </div>

                                <div class="tala-calendar-facts__item student-calendar-fact--exam">
                                    <dt>Exam period</dt>
                                    <dd class="tabular-nums">
                                        {{ $examinationPeriod ? $examinationPeriod->opens_on->format('M j').'–'.$examinationPeriod->closes_on->format('M j, Y') : 'Dates to be announced' }}
                                    </dd>
                                    @if ($teachingPeriod['special_term_basis'] ?? null)
                                        <dd class="tala-calendar-facts__note">{{ $teachingPeriod['special_term_basis'] }}</dd>
                                    @endif
                                </div>
                            </dl>

                            @include('filament.pages.partials.term-teaching-week', ['teachingWeekRows' => $teachingWeekRows])
                        </section>

                        <section class="tala-task-surface tala-calendar-activities" aria-labelledby="student-academic-windows-heading">
                            <header class="tala-calendar-sheet-heading">
                                <div class="tala-calendar-sheet-heading__title">
                                    <h2 id="student-academic-windows-heading">
                                        <x-filament::icon icon="heroicon-o-clock" aria-hidden="true" />
                                        <span>Academic windows</span>
                                    </h2>
                                </div>
                            </header>
                            <p class="tala-work-footnote">
                                Official enrollment, adjustment, examination, and grade release periods set by the Registrar.
                            </p>

                            <dl class="tala-calendar-activity-list">
                                @foreach ($academicWindows as $window)
                                    <div @class([
                                        'tala-calendar-activity',
                                        'is-recorded' => $window['opens_on_iso'] !== null,
                                        'is-unset' => $window['opens_on_iso'] === null,
                                        'is-highlighted' => $window['is_highlighted'] ?? false,
                                    ])>
                                        <dt>
                                            <x-filament::icon :icon="$window['icon']" aria-hidden="true" />
                                            <span class="tala-calendar-activity__label">
                                                <span>{{ $window['label'] }}</span>
                                                @if ($window['note'])
                                                    <small>{{ $window['note'] }}</small>
                                                @endif
                                            </span>
                                        </dt>
                                        <dd>
                                            @if ($window['opens_on_iso'] && $window['closes_on_iso'])
                                                <div class="tala-calendar-activity-period">
                                                    <strong class="tabular-nums">
                                                        <time datetime="{{ $window['opens_on_iso'] }}">{{ $window['opens_on_label'] }}</time>
                                                        –
                                                        <time datetime="{{ $window['closes_on_iso'] }}">{{ $window['closes_on_label'] }}</time>
                                                    </strong>
                                                    @if ($window['cutoff_label'])
                                                        <small class="tabular-nums">
                                                            <time datetime="{{ $window['closes_at_iso'] }}">{{ $window['cutoff_label'] }}</time>
                                                        </small>
                                                    @endif
                                                </div>
                                                <x-filament::badge :color="$window['status_color']" size="sm">{{ $window['status'] }}</x-filament::badge>
                                            @else
                                                <span class="tala-calendar-unset">Not set</span>
                                            @endif
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        </section>
                    </div>

                    <section class="tala-task-surface tala-calendar-activities" aria-labelledby="student-dated-exceptions-heading">
                        <header class="tala-calendar-sheet-heading">
                            <div class="tala-calendar-sheet-heading__title">
                                <h2 id="student-dated-exceptions-heading">
                                    <x-filament::icon icon="heroicon-o-calendar-days" aria-hidden="true" />
                                    <span>No-class holidays &amp; dated exceptions</span>
                                </h2>
                            </div>
                        </header>

                        @if ($datedExceptions['upcoming'] === [] && $datedExceptions['past'] === [])
                            <p class="tala-work-footnote">No holidays or dated calendar exceptions are recorded for this term.</p>
                        @else
                            @if ($datedExceptions['upcoming'] !== [])
                                <div class="tala-calendar-exceptions">
                                    <h3>Upcoming dates</h3>
                                    <ul>
                                        @foreach ($datedExceptions['upcoming'] as $exception)
                                            <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-2 border-b border-gray-200 dark:border-gray-800 last:border-b-0">
                                                <div class="min-w-0 space-y-0.5">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <strong class="font-medium">{{ $exception['label'] }}</strong>
                                                        @if ($exception['type_label'])
                                                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $exception['type_label'] }}</span>
                                                        @endif
                                                    </div>
                                                    <p class="text-sm text-gray-600 dark:text-gray-300 tabular-nums">
                                                        @if ($exception['is_same_day'] && $exception['starts_on_iso'])
                                                            <time datetime="{{ $exception['starts_on_iso'] }}">{{ $exception['starts_on_label'] }}</time>
                                                        @elseif ($exception['starts_on_iso'] && $exception['ends_on_iso'])
                                                            <time datetime="{{ $exception['starts_on_iso'] }}">{{ $exception['starts_on_label'] }}</time>
                                                            –
                                                            <time datetime="{{ $exception['ends_on_iso'] }}">{{ $exception['ends_on_label'] }}</time>
                                                        @endif
                                                    </p>
                                                </div>
                                                <div class="flex flex-wrap items-center gap-1.5">
                                                    @if ($exception['timing_label'])
                                                        <x-filament::badge :color="$exception['timing_color']" size="sm">
                                                            {{ $exception['timing_label'] }}
                                                        </x-filament::badge>
                                                    @endif
                                                    <x-filament::badge :color="$exception['blocks_teaching'] ? 'warning' : 'gray'" size="sm">
                                                        {{ $exception['blocks_teaching'] ? 'No classes' : 'Classes continue' }}
                                                    </x-filament::badge>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if ($datedExceptions['past'] !== [])
                                <div class="tala-calendar-exceptions">
                                    <h3>{{ $datedExceptions['upcoming'] !== [] ? 'Earlier this term' : 'Recorded dates' }}</h3>
                                    <ul>
                                        @foreach ($datedExceptions['past'] as $exception)
                                            <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-2 border-b border-gray-200 dark:border-gray-800 last:border-b-0">
                                                <div class="min-w-0 space-y-0.5">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <strong class="font-medium">{{ $exception['label'] }}</strong>
                                                        @if ($exception['type_label'])
                                                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $exception['type_label'] }}</span>
                                                        @endif
                                                    </div>
                                                    <p class="text-sm text-gray-600 dark:text-gray-300 tabular-nums">
                                                        @if ($exception['is_same_day'] && $exception['starts_on_iso'])
                                                            <time datetime="{{ $exception['starts_on_iso'] }}">{{ $exception['starts_on_label'] }}</time>
                                                        @elseif ($exception['starts_on_iso'] && $exception['ends_on_iso'])
                                                            <time datetime="{{ $exception['starts_on_iso'] }}">{{ $exception['starts_on_label'] }}</time>
                                                            –
                                                            <time datetime="{{ $exception['ends_on_iso'] }}">{{ $exception['ends_on_label'] }}</time>
                                                        @endif
                                                    </p>
                                                </div>
                                                <x-filament::badge :color="$exception['blocks_teaching'] ? 'warning' : 'gray'" size="sm">
                                                    {{ $exception['blocks_teaching'] ? 'No classes' : 'Classes continue' }}
                                                </x-filament::badge>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        @endif
                    </section>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
