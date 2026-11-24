<x-filament-panels::page>
    @assets<link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">@endassets
    @php
        $hasWeeklyMeetings = $schedulePresentation && ($schedulePresentation['meetings'] ?? []) !== [];
        $hasExternallyArranged = ($externallyArrangedClasses ?? []) !== [];
    @endphp
    <div class="learner-enrollment student-schedule-reading space-y-4">
        @if (count($activeTermOptions ?? []) >= 2)
            <div class="flex flex-wrap items-center justify-between gap-3">
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
            </div>
        @endif

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
            <section class="tala-context-inset flex flex-wrap items-center justify-between gap-x-6 gap-y-2 text-sm" aria-label="Term dates and exam period">
                <div class="flex flex-wrap items-center gap-x-5 gap-y-1 tabular-nums">
                    @if ($selectedTermLabel)
                        <span class="font-semibold text-gray-950 dark:text-white">{{ $selectedTermLabel }}</span>
                    @endif
                    @if ($termTeachingRange !== null)
                        <span>
                            <strong class="font-medium text-gray-950 dark:text-white">Classes:</strong>
                            <span class="text-gray-600 dark:text-gray-300">{{ $termTeachingRange }}</span>
                        </span>
                    @endif
                    <span>
                        <strong class="font-medium text-gray-950 dark:text-white">Exam period:</strong>
                        <span class="text-gray-600 dark:text-gray-300">
                            {{ $examinationPeriod ? $examinationPeriod->opens_on->format('M j').'–'.$examinationPeriod->closes_on->format('M j, Y') : 'Dates to be announced' }}
                        </span>
                    </span>
                </div>
                @if (($upcomingHolidays ?? []) !== [])
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Upcoming no-class dates:</span>
                        @foreach ($upcomingHolidays as $holiday)
                            <x-filament::badge color="gray" size="sm" class="tabular-nums">
                                {{ $holiday['date_label'] }} · {{ $holiday['label'] }}
                            </x-filament::badge>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif

        @if ($hasWeeklyMeetings)
            <x-enrollment-schedule :presentation="$schedulePresentation" source-key="student-schedule-{{ $termId ?? 'current' }}" />
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
                <x-filament::button tag="a" color="gray" :href="\App\Filament\Student\Pages\Enrollment::getUrl(panel: 'student')">View enrollment</x-filament::button>
            </x-servitech-empty-state>
        @endif
    </div>
</x-filament-panels::page>
