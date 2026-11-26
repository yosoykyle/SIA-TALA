<x-filament-panels::page class="tala-term-workspace tala-resource-workspace">
    @include('filament.pages.partials.term-workspace-header')
    @if ($term)
        @php
            $resolvedStepState = $stepState ?? (class_exists(\App\Support\TermPlanningStepState::class)
                ? \App\Support\TermPlanningStepState::evaluate($term, (bool) ($readOnly ?? false))
                : null);
            $currentStepState = $resolvedStepState['steps']['resources'] ?? null;
            $isStepLocked = (bool) ($currentStepState['locked'] ?? false);
            $timetableUnlocked = (bool) ($resolvedStepState['steps']['timetable']['unlocked'] ?? false);
            $isLiveOrClosed = $this->isTermClosed() || $this->hasPublishedTimetable();
            $canEditResources = ! $readOnly && ! $this->isTermClosed();
            $formattedDefaultMaxUnits = ($term->default_max_units === null
                ? '24'
                : rtrim(rtrim(number_format((float) $term->default_max_units, 2, '.', ''), '0'), '.')).' units/teacher';
            $activeRoomCount = count($rooms);
            $schoolFacultyUrl = \App\Filament\Pages\SchoolResources::getUrl(['tab' => 'faculty', 'termId' => $term->id]);
            $schoolRoomsUrl = \App\Filament\Pages\SchoolResources::getUrl(['tab' => 'rooms', 'termId' => $term->id]);
        @endphp

        @if ($isStepLocked)
            @include('filament.pages.partials.term-step-locked', ['step' => $currentStepState, 'term' => $term])
        @else
            <section class="tala-task-work" aria-label="Faculty and rooms">
                <div class="tala-work-tabs tala-work-tabs--full">
                    <x-tala-navigation label="Resources view" compact stretch class="tala-work-tabs__nav">
                        <x-filament::tabs.item
                            tag="a"
                            :href="\App\Filament\Pages\TermResources::getUrl(['termId' => $term->id, 'resourceView' => 'faculty'])"
                            :active="$resourceView === 'faculty'"
                            icon="heroicon-o-user"
                            wire:click.prevent="chooseResourceView('faculty')"
                            wire:loading.attr="aria-disabled"
                            wire:target="chooseResourceView"
                        >
                            Faculty
                        </x-filament::tabs.item>
                        <x-filament::tabs.item
                            tag="a"
                            :href="\App\Filament\Pages\TermResources::getUrl(['termId' => $term->id, 'resourceView' => 'rooms'])"
                            :active="$resourceView === 'rooms'"
                            icon="heroicon-o-building-office-2"
                            wire:click.prevent="chooseResourceView('rooms')"
                            wire:loading.attr="aria-disabled"
                            wire:target="chooseResourceView"
                        >
                            Rooms
                        </x-filament::tabs.item>
                    </x-tala-navigation>
                </div>

                @if ($this->isTermClosed())
                    <x-tala-access-alert variant="neutral" icon="heroicon-o-lock-closed" title="Closed term · Read-only">
                        <p>This term is closed. Per-term faculty availability, load overrides, and room closures are preserved for historical reference and cannot be edited.</p>
                    </x-tala-access-alert>
                @endif

                @if ($resourceView === 'faculty')
                    @php
                        $replyPercent = $termFacultyCount > 0 ? (int) round(($facultyResponseCount / $termFacultyCount) * 100) : 0;
                        $replyBreakdown = collect([
                            $facultyResponseCount.' replied',
                            $noResponseCount.' no response',
                            $cannotTeachCount > 0 ? $cannotTeachCount.' cannot teach' : null,
                            $needsGridReviewCount > 0 ? $needsGridReviewCount.' review needed' : null,
                        ])->filter()->implode(' · ');
                        $uncoveredSubjectsList = $uncoveredSubjects ?? [];
                        $facultyBlockersList = $facultyReadinessBlockers ?? [];
                        $roomBlockersList = $roomReadinessBlockers ?? [];
                        $hasInlineNotices = ! $activePackage
                            || ! empty($unresolvedLateImpacts)
                            || $needsGridReviewCount > 0
                            || (! $isLiveOrClosed && ! empty($roomBlockersList));
                        $facultyTriageCounts = $this->facultyTriageCounts();
                        $activeFacultyStatusFilter = (string) ($this->getTableFilterState('availability_status')['value'] ?? 'all');
                    @endphp

                    <section class="tala-task-surface tala-resource-readiness-bar" aria-labelledby="faculty-replies-summary-heading">
                        <div class="tala-resource-readiness-bar__row">
                            <div class="tala-resource-readiness-bar__main">
                                <div class="tala-resource-readiness-bar__headline">
                                    <h2 id="faculty-replies-summary-heading" class="tala-resource-readiness-bar__heading tabular-nums">
                                        <strong class="tala-resource-readiness-bar__count">{{ $facultyResponseCount }} of {{ $termFacultyCount }}</strong>
                                        <span>faculty replied</span>
                                    </h2>
                                    <span class="tala-resource-readiness-bar__breakdown tabular-nums">{{ $replyBreakdown }}</span>
                                </div>
                                @if ($termFacultyCount > 0)
                                    <div class="tala-availability-meter">
                                        <progress
                                            class="tala-readiness-progress"
                                            value="{{ $facultyResponseCount }}"
                                            max="{{ $termFacultyCount }}"
                                            aria-label="Faculty reply progress: {{ $facultyResponseCount }} of {{ $termFacultyCount }} replied"
                                        >{{ $replyPercent }}%</progress>
                                        <span class="tala-resource-readiness-bar__breakdown tala-availability-meter__value tabular-nums whitespace-nowrap">{{ $replyPercent }}%</span>
                                    </div>
                                @endif
                                <p class="tala-resource-readiness-bar__meta tabular-nums">
                                    @if ($facultyDeadline)
                                        Reply by: <strong>{{ $facultyDeadline->copy()->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</strong>
                                    @else
                                        Reply by: <strong>No due date set</strong>
                                    @endif
                                    · Load limit: <strong>{{ $formattedDefaultMaxUnits }}</strong>
                                </p>
                                <p class="tala-resource-readiness-bar__meta">
                                    <strong>This term:</strong> faculty availability, load limits, and room closures.
                                    <strong>Shared across terms:</strong> <em>Assign subjects</em> and <em>Subjects</em> update the same catalog as
                                    <a href="{{ $schoolFacultyUrl }}" class="underline">School Resources</a>.
                                </p>
                            </div>
                            @if (! $readOnly && ! $isLiveOrClosed)
                                <div class="tala-resource-readiness-bar__actions">
                                    {{ $this->sendAvailabilityReminderAction }}
                                </div>
                            @endif
                        </div>

                        @if ($hasInlineNotices)
                            <div class="tala-resource-readiness-bar__notices">
                                @if (! $activePackage)
                                    <div class="tala-resource-readiness-bar__notice tala-resource-readiness-bar__notice--warning">
                                        <div class="tala-resource-readiness-bar__notice-copy">
                                            <x-filament::icon icon="heroicon-o-calendar" class="tala-resource-readiness-bar__notice-icon" aria-hidden="true" />
                                            <p>
                                                <strong>Teaching calendar not active.</strong>
                                                {{ $readOnly ? 'The Registrar must activate the teaching calendar before faculty can submit availability.' : 'Activate the teaching calendar so faculty can submit availability.' }}
                                            </p>
                                        </div>
                                        <x-filament::button tag="a" color="gray" size="sm" icon="heroicon-o-calendar" :href="\App\Filament\Pages\TermCalendar::getUrl(['termId' => $term->id])">Review calendar</x-filament::button>
                                    </div>
                                @endif

                                @if (! empty($unresolvedLateImpacts))
                                    <div class="tala-resource-readiness-bar__notice tala-resource-readiness-bar__notice--danger tala-late-impact-notice">
                                        <div class="tala-resource-readiness-bar__notice-copy">
                                            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="tala-resource-readiness-bar__notice-icon" aria-hidden="true" />
                                            <div class="tala-late-impact-list">
                                                <p>
                                                    <strong>{{ count($unresolvedLateImpacts) }} late availability {{ \Illuminate\Support\Str::plural('change', count($unresolvedLateImpacts)) }} {{ count($unresolvedLateImpacts) === 1 ? 'affects' : 'affect' }} the official timetable</strong>
                                                </p>
                                                @foreach ($unresolvedLateImpacts as $impact)
                                                    @php
                                                        $impactRevisionUrl = $this->officialRevisionUrlForMeetings(
                                                            $impact['affected_meeting_ids'] ?? [],
                                                            $impact['faculty_name'] ?? null,
                                                        ) ?? \App\Filament\Pages\TermTimetable::getUrl([
                                                            'termId' => $term->id,
                                                            'timetableState' => 'official',
                                                            ...($currentVersion ? ['selectedVersionId' => $currentVersion->id] : []),
                                                        ]);
                                                        $impactModeLabel = $this->formatAvailabilityModeLabel((string) ($impact['mode_label'] ?? ''));
                                                    @endphp
                                                    <div class="tala-late-impact-item">
                                                        <p class="tala-resource-readiness-bar__notice-detail">
                                                            <strong>{{ $impact['faculty_name'] }}</strong> ({{ $impactModeLabel }})
                                                            @if (! empty($impact['reason'])) · Reason: “{{ $impact['reason'] }}” @endif
                                                            @if (! empty($impact['affected_meetings']))
                                                                : {{ collect($impact['affected_meetings'])->map(fn (array $m): string => trim(($m['course_code'] ?? '').' '.($m['section_code'] ?? '').' ('.($m['day_label'] ?? '').' '.($m['time_label'] ?? '').')'))->implode('; ') }}
                                                            @endif
                                                        </p>
                                                        <x-filament::button
                                                            tag="a"
                                                            size="sm"
                                                            :color="$readOnly ? 'gray' : 'primary'"
                                                            icon="heroicon-m-arrow-right"
                                                            icon-position="after"
                                                            :href="$impactRevisionUrl"
                                                        >
                                                            {{ $readOnly ? 'Inspect official timetable' : 'Start timetable revision' }}
                                                        </x-filament::button>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                @if ($needsGridReviewCount > 0)
                                    <div class="tala-resource-readiness-bar__notice tala-resource-readiness-bar__notice--warning">
                                        <div class="tala-resource-readiness-bar__notice-copy">
                                            <x-filament::icon icon="heroicon-o-clock" class="tala-resource-readiness-bar__notice-icon" aria-hidden="true" />
                                            <p>
                                                <strong>Review needed: {{ $needsGridReviewCount }} {{ \Illuminate\Support\Str::plural('teacher', $needsGridReviewCount) }} chose times outside the updated teaching hours.</strong>
                                                Outside hours remain excluded from scheduling. Affected teachers can adjust their teaching times on My Availability.
                                            </p>
                                        </div>
                                    </div>
                                @endif

                                @if (! $isLiveOrClosed && ! empty($roomBlockersList))
                                    <div class="tala-resource-readiness-bar__notice tala-resource-readiness-bar__notice--warning">
                                        <div class="tala-resource-readiness-bar__notice-copy">
                                            <x-filament::icon icon="heroicon-o-building-office-2" class="tala-resource-readiness-bar__notice-icon" aria-hidden="true" />
                                            <div>
                                                <p><strong>Room readiness needs attention before scheduling.</strong></p>
                                                @foreach ($roomBlockersList as $roomBlocker)
                                                    <p class="tala-resource-readiness-bar__notice-detail">{{ $roomBlocker['reason'] }}</p>
                                                @endforeach
                                            </div>
                                        </div>
                                        <x-filament::button
                                            type="button"
                                            size="sm"
                                            color="gray"
                                            icon="heroicon-o-building-office-2"
                                            wire:click.prevent="chooseResourceView('rooms')"
                                        >
                                            Review rooms
                                        </x-filament::button>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </section>

                    @if (! $isLiveOrClosed && ($missingQualifications > 0 || ! empty($uncoveredSubjectsList)))
                        @php
                            $uncoveredCount = count($uncoveredSubjectsList) > 0 ? count($uncoveredSubjectsList) : $missingQualifications;
                            $visibleUncoveredSubjects = array_slice($uncoveredSubjectsList, 0, 6);
                            $remainingUncoveredCount = max(0, count($uncoveredSubjectsList) - count($visibleUncoveredSubjects));
                            $showTopSubjectPicker = empty($visibleUncoveredSubjects) || $remainingUncoveredCount > 0;
                        @endphp
                        <div class="tala-inline-prereq" role="region" aria-label="Subjects missing a qualified teacher">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="space-y-1">
                                    <p class="font-semibold">
                                        {{ $uncoveredCount }} offered {{ \Illuminate\Support\Str::plural('subject', $uncoveredCount) }} {{ $uncoveredCount === 1 ? 'needs' : 'need' }} a qualified teacher
                                    </p>
                                    <p class="text-sm">
                                        Assign at least one active teacher to each subject below so the timetable generator can place its classes.
                                    </p>
                                </div>
                                @if ($canEditResources && ($showTopSubjectPicker || $noResponseCount > 0))
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if ($showTopSubjectPicker)
                                            <x-filament::button
                                                type="button"
                                                size="sm"
                                                color="primary"
                                                icon="heroicon-o-user-plus"
                                                wire:click="mountAction('assignTeacherForSubject')"
                                            >
                                                {{ $remainingUncoveredCount > 0 ? 'Choose another subject' : 'Assign teacher' }}
                                            </x-filament::button>
                                        @endif
                                        @if ($noResponseCount > 0)
                                            <x-filament::button
                                                type="button"
                                                size="sm"
                                                color="gray"
                                                icon="heroicon-o-envelope"
                                                wire:click="mountAction('sendAvailabilityReminder')"
                                            >
                                                Remind
                                            </x-filament::button>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            @if (! empty($visibleUncoveredSubjects))
                                <ul class="mt-3 space-y-2">
                                    @foreach ($visibleUncoveredSubjects as $subject)
                                        <li class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                            <span>
                                                <strong>{{ $subject['code'] }}</strong>@if ($subject['title'] !== '') · {{ $subject['title'] }}@endif
                                            </span>
                                            @if ($canEditResources)
                                                <x-filament::button
                                                    type="button"
                                                    size="sm"
                                                    color="gray"
                                                    icon="heroicon-o-user-plus"
                                                    wire:click="mountAction('assignTeacherForSubject', { courseId: {{ $subject['course_id'] }} })"
                                                >
                                                    Assign teacher
                                                </x-filament::button>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                                @if ($remainingUncoveredCount > 0)
                                    <p class="mt-2 text-xs">
                                        +{{ $remainingUncoveredCount }} more uncovered {{ \Illuminate\Support\Str::plural('subject', $remainingUncoveredCount) }}. Use Choose another subject above to pick any subject.
                                    </p>
                                @endif
                            @endif
                        </div>
                    @endif

                    @if (! $isLiveOrClosed && ! empty($facultyBlockersList))
                        @php
                            $visibleFacultyBlockers = array_slice($facultyBlockersList, 0, 4);
                            $remainingFacultyBlockers = max(0, count($facultyBlockersList) - count($visibleFacultyBlockers));
                        @endphp
                        <div class="tala-inline-prereq" role="region" aria-label="Faculty availability blockers">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="space-y-1">
                                    <p class="font-semibold">
                                        {{ count($facultyBlockersList) }} {{ \Illuminate\Support\Str::plural('class', count($facultyBlockersList)) }} waiting on teacher availability
                                    </p>
                                    <ul class="space-y-1 text-sm">
                                        @foreach ($visibleFacultyBlockers as $blocker)
                                            <li>{{ $blocker['reason'] }}</li>
                                        @endforeach
                                    </ul>
                                    @if ($remainingFacultyBlockers > 0)
                                        <p class="text-xs">+{{ $remainingFacultyBlockers }} more availability {{ \Illuminate\Support\Str::plural('item', $remainingFacultyBlockers) }}.</p>
                                    @endif
                                </div>
                                @if ($canEditResources)
                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-filament::button
                                            type="button"
                                            size="sm"
                                            color="gray"
                                            icon="heroicon-o-envelope"
                                            wire:click="mountAction('sendAvailabilityReminder')"
                                        >
                                            Remind
                                        </x-filament::button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif

                    @if (($facultyScopeAutoFallback ?? false) && (($this->getTableFilterState('faculty_scope')['value'] ?? 'term') === 'term'))
                        <p class="tala-work-footnote">
                            Showing all {{ $activeSchoolFacultyCount }} active {{ \Illuminate\Support\Str::plural('faculty member', $activeSchoolFacultyCount) }} because no teachers are linked to this term's offered subjects yet. Assign subjects to connect teachers to this term.
                        </p>
                    @endif

                    @php
                        $facultyChips = array_values(array_filter([
                            ['key' => 'all', 'label' => 'All', 'count' => $facultyTriageCounts['all'], 'tone' => 'neutral'],
                            $lateImpactCount > 0 ? ['key' => 'official_impact', 'label' => 'Affects live schedule', 'count' => $facultyTriageCounts['official_impact'], 'tone' => 'danger'] : null,
                            ['key' => 'no_response', 'label' => 'No response', 'count' => $facultyTriageCounts['no_response'], 'tone' => 'neutral'],
                            ['key' => 'needs_review', 'label' => 'Needs review', 'count' => $facultyTriageCounts['needs_review'], 'tone' => $facultyTriageCounts['needs_review'] > 0 ? 'warning' : 'neutral'],
                            ['key' => 'cannot_teach', 'label' => 'Cannot teach', 'count' => $facultyTriageCounts['cannot_teach'], 'tone' => 'neutral'],
                            ['key' => 'replied', 'label' => 'Replied', 'count' => $facultyTriageCounts['replied'], 'tone' => 'neutral'],
                        ]));
                    @endphp
                    <div class="tala-triage-track-wrap">
                        <div class="tala-triage-chips" role="toolbar" aria-label="Filter faculty by status">
                            @foreach ($facultyChips as $chip)
                                @php $isChipActive = $activeFacultyStatusFilter === $chip['key']; @endphp
                                <button
                                    type="button"
                                    wire:click="applyFacultyTriageFilter('{{ $chip['key'] }}')"
                                    @class([
                                        'tala-triage-chip',
                                        'is-active' => $isChipActive,
                                        'tala-triage-chip--danger' => $chip['tone'] === 'danger',
                                        'tala-triage-chip--warning' => $chip['tone'] === 'warning',
                                    ])
                                    aria-pressed="{{ $isChipActive ? 'true' : 'false' }}"
                                >
                                    <span>{{ $chip['label'] }}</span>
                                    <span class="tala-triage-chip__count">{{ $chip['count'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @else
                    @php
                        $roomBlockersList = $roomReadinessBlockers ?? [];
                        $resolvedRoomTriageCounts = $roomTriageCounts ?? [
                            'active' => $activeRoomCount,
                            'has_closures' => 0,
                            'lecture' => 0,
                            'laboratory' => 0,
                            'inactive' => $inactiveRoomCount ?? 0,
                        ];
                        $activeRoomPreset = $this->activeRoomTriagePreset();
                    @endphp
                    <section class="tala-task-surface tala-resource-readiness-bar" aria-labelledby="rooms-summary-heading">
                        <div class="tala-resource-readiness-bar__row">
                            <div class="tala-resource-readiness-bar__main">
                                <div class="tala-resource-readiness-bar__headline">
                                    <h2 id="rooms-summary-heading" class="tala-resource-readiness-bar__heading tabular-nums">
                                        <strong class="tala-resource-readiness-bar__count">{{ $activeRoomCount }}</strong>
                                        <span>active campus {{ \Illuminate\Support\Str::plural('room', $activeRoomCount) }}</span>
                                    </h2>
                                    @if (($activeRoomClosureCount ?? 0) > 0)
                                        <span class="tala-resource-readiness-bar__breakdown tabular-nums">· {{ $activeRoomClosureCount }} active {{ \Illuminate\Support\Str::plural('closure', $activeRoomClosureCount) }} this term</span>
                                    @endif
                                    @if (($inactiveRoomCount ?? 0) > 0)
                                        <span class="tala-resource-readiness-bar__breakdown tabular-nums">· {{ $inactiveRoomCount }} inactive in catalog</span>
                                    @endif
                                </div>
                                <p class="tala-resource-readiness-bar__meta">
                                    <strong>This term:</strong> weekly maintenance closures.
                                    <strong>Shared across terms:</strong> <em>Add room</em>, <em>Edit</em>, and <em>Activate</em> update the same room catalog as
                                    <a href="{{ $schoolRoomsUrl }}" class="underline">School Resources</a>.
                                </p>
                            </div>
                        </div>
                    </section>

                    @if (! $isLiveOrClosed && ! empty($roomBlockersList))
                        <div class="tala-inline-prereq" role="region" aria-label="Room readiness blockers">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="space-y-1">
                                    <p class="font-semibold">Room requirements not yet met for this term</p>
                                    <ul class="space-y-1 text-sm">
                                        @foreach ($roomBlockersList as $roomBlocker)
                                            <li>{{ $roomBlocker['reason'] }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                                @if ($canEditResources && \App\Filament\Resources\Rooms\RoomResource::canCreate())
                                    <div class="flex flex-wrap items-center gap-2">
                                        {{ $this->addRoomAction }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif

                    @if ($activeRoomCount === 0 && ($inactiveRoomCount ?? 0) > 0)
                        <p class="tala-work-footnote">
                            No rooms are active yet. Showing all catalog rooms below so you can activate an existing room or add a new one.
                        </p>
                    @endif

                    @php
                        $roomChips = [
                            ['key' => 'active', 'label' => 'All active', 'count' => $resolvedRoomTriageCounts['active'], 'tone' => 'neutral'],
                            ['key' => 'has_closures', 'label' => 'Has closures', 'count' => $resolvedRoomTriageCounts['has_closures'], 'tone' => $resolvedRoomTriageCounts['has_closures'] > 0 ? 'warning' : 'neutral'],
                            ['key' => 'lecture', 'label' => 'Lecture', 'count' => $resolvedRoomTriageCounts['lecture'], 'tone' => 'neutral'],
                            ['key' => 'laboratory', 'label' => 'Laboratory', 'count' => $resolvedRoomTriageCounts['laboratory'], 'tone' => 'neutral'],
                            ['key' => 'inactive', 'label' => 'Inactive', 'count' => $resolvedRoomTriageCounts['inactive'], 'tone' => 'neutral'],
                        ];
                    @endphp
                    <div class="tala-triage-track-wrap">
                        <div class="tala-triage-chips" role="toolbar" aria-label="Filter rooms by status or type">
                            @foreach ($roomChips as $chip)
                                @php $isChipActive = $activeRoomPreset === $chip['key']; @endphp
                                <button
                                    type="button"
                                    wire:click="applyRoomTriageFilter('{{ $chip['key'] }}')"
                                    @class([
                                        'tala-triage-chip',
                                        'is-active' => $isChipActive,
                                        'tala-triage-chip--warning' => $chip['tone'] === 'warning',
                                    ])
                                    aria-pressed="{{ $isChipActive ? 'true' : 'false' }}"
                                >
                                    <span>{{ $chip['label'] }}</span>
                                    <span class="tala-triage-chip__count">{{ $chip['count'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{ $this->table }}

                @if (! $readOnly && ! $isLiveOrClosed && $timetableUnlocked)
                    <div class="tala-stage-next">
                        <div class="tala-stage-next__copy">
                            <strong>Faculty and rooms are ready for scheduling.</strong>
                            <span>Continue to Timetable to generate and review the schedule draft.</span>
                        </div>
                        <x-filament::button
                            tag="a"
                            size="sm"
                            color="primary"
                            icon="heroicon-m-arrow-right"
                            icon-position="after"
                            :href="\App\Filament\Pages\TermTimetable::getUrl(['termId' => $term->id])"
                        >
                            Next: 4. Timetable
                        </x-filament::button>
                    </div>
                @endif
            </section>
        @endif
    @else
        <section class="tala-task-surface" aria-label="Faculty and rooms">
            <x-servitech-empty-state
                :illustrated="false"
                icon="heroicon-o-users"
                heading="Choose a term"
                description="Open Term Planning to select an academic year and term before reviewing faculty availability and rooms."
            >
                <x-filament::button
                    tag="a"
                    color="primary"
                    size="sm"
                    icon="heroicon-m-arrow-right"
                    icon-position="after"
                    :href="\App\Filament\Pages\TermOverview::getUrl()"
                >
                    Open Term Planning
                </x-filament::button>
            </x-servitech-empty-state>
        </section>
    @endif
    <x-filament-actions::modals />
</x-filament-panels::page>
