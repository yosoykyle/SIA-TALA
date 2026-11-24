@if (! $readOnly)
@php
    $compact = (bool) ($compact ?? false);
    $lifecycleMode = isset($term) && $term instanceof \App\Models\Term
        ? $this->termLifecycleMode($term)
        : 'draft';
    $isLiveMode = $lifecycleMode === 'live';
    $isClosedMode = $lifecycleMode === 'closed';
    $isLiveOrClosed = $isLiveMode || $isClosedMode;

    $recordedCount = $sourceClasses->count();
    $confirmedCount = $contextClasses->whereNotNull('confirmed_at')->count();
    $cohortCount = $cohorts->count();
    $demandsCount = $demands->count();
    $unreadyClasses = $isLiveOrClosed ? 0 : max(0, $recordedCount - $confirmedCount);
    $calendarDone = $isLiveOrClosed || ($activePackage !== null && $draftPackages->isEmpty());
    $classesDone = $isLiveOrClosed || ($cohortCount > 0 && $recordedCount > 0 && $unreadyClasses === 0 && $demandsCount > 0);

    $resourceBlockers = $isLiveOrClosed
        ? collect()
        : collect($readiness['blockers'] ?? [])
            ->whereIn('code', [
                'faculty_qualifications_missing',
                'fixed_faculty_availability_missing',
                'fixed_faculty_cannot_teach',
                'fixed_faculty_no_usable_time',
                'faculty_demand_uncovered',
                'rooms_unavailable',
                'rooms_unsuitable',
            ])
            ->values();
    $hasRooms = $rooms->isNotEmpty();
    $totalFaculty = (int) ($availabilityProjection['total_faculty'] ?? 0);
    $repliedFaculty = (int) ($availabilityProjection['replied_count'] ?? 0);
    $lateImpactCount = (int) ($availabilityProjection['late_impact_count'] ?? 0);
    $effectiveMissingQualifications = $isLiveOrClosed ? 0 : (int) $missingQualifications;
    $resourcesDone = $isLiveOrClosed || (
        $calendarDone
        && $hasRooms
        && $effectiveMissingQualifications === 0
        && $resourceBlockers->isEmpty()
        && ($totalFaculty > 0 || $offerings->isNotEmpty())
    );
    $timetableDone = $currentVersion !== null || $isClosedMode;

    $calendarDatesFormatted = $activePackage && $activePackage->classes_start_on && $activePackage->classes_end_on
        ? ($activePackage->classes_start_on->year === $activePackage->classes_end_on->year
            ? $activePackage->classes_start_on->format('M j').'–'.$activePackage->classes_end_on->format('M j')
            : $activePackage->classes_start_on->format('M j, Y').'–'.$activePackage->classes_end_on->format('M j, Y'))
        : null;

    $standardWindowTypes = array_keys(\App\Models\TermCalendarWindow::typeOptions());
    $standardWindowCount = count($standardWindowTypes);
    if (isset($operationalDates)) {
        $configuredActivityCount = collect($operationalDates)
            ->filter(fn (array $item): bool => ! empty($item['window']))
            ->count();
    } elseif ($activePackage) {
        $activePackage->loadMissing('windows');
        $configuredActivityCount = $activePackage->windows
            ->whereIn('window_type', $standardWindowTypes)
            ->filter(fn ($window): bool => $window->opens_on !== null)
            ->pluck('window_type')
            ->unique()
            ->count();
    } else {
        $configuredActivityCount = 0;
    }
    $unsetActivityCount = max(0, $standardWindowCount - $configuredActivityCount);

    $calendarSummary = match (true) {
        $isClosedMode => 'Closed · Read-only'.($calendarDatesFormatted ? ' · '.$calendarDatesFormatted : ''),
        $isLiveMode => 'Active'.($calendarDatesFormatted ? ' · '.$calendarDatesFormatted : '').' · Teaching hours locked',
        ! $activePackage && $draftPackages->isNotEmpty() => 'Draft calendar needs activation',
        ! $activePackage => 'Set and activate dates and hours',
        $draftPackages->isNotEmpty() => 'Pending calendar update to activate',
        default => 'Active'
            .($calendarDatesFormatted ? ' · '.$calendarDatesFormatted : '')
            .($unsetActivityCount > 0
                ? ' · '.$unsetActivityCount.' optional '.\Illuminate\Support\Str::plural('date', $unsetActivityCount).' unset'
                : ' · All dates set'),
    };

    $classesSummary = match (true) {
        $isClosedMode => $confirmedCount.' '.\Illuminate\Support\Str::plural('class', $confirmedCount).' archived',
        $isLiveMode => $confirmedCount.' '.\Illuminate\Support\Str::plural('class', $confirmedCount).' published · Locked',
        $cohortCount === 0 => 'Add at least one student section',
        $recordedCount === 0 => 'Load subjects and add classes',
        $unreadyClasses > 0 => $unreadyClasses.' '.\Illuminate\Support\Str::plural('class', $unreadyClasses).' need confirmation',
        $demandsCount === 0 => 'Weekly meeting requirements pending',
        default => $confirmedCount.' '.\Illuminate\Support\Str::plural('class', $confirmedCount).' confirmed',
    };

    $resourcesSummary = match (true) {
        $isClosedMode => 'Closed · Read-only',
        $isLiveMode && $lateImpactCount > 0 => $lateImpactCount.' late availability '.\Illuminate\Support\Str::plural('update', $lateImpactCount).' to review',
        $isLiveMode && $totalFaculty > 0 => $repliedFaculty.' of '.$totalFaculty.' faculty replied · Live',
        $isLiveMode => 'Qualified faculty and rooms active',
        $effectiveMissingQualifications > 0 => $effectiveMissingQualifications.' '.\Illuminate\Support\Str::plural('subject', $effectiveMissingQualifications).' '.($effectiveMissingQualifications === 1 ? 'needs' : 'need').' qualified faculty',
        ! $calendarDone => 'Activate calendar to open availability',
        ! $hasRooms => 'Add at least one active room',
        $resourceBlockers->contains('code', 'rooms_unsuitable') || $resourceBlockers->contains('code', 'rooms_unavailable') => 'Matching active room needed',
        $resourceBlockers->isNotEmpty() && $totalFaculty > 0 => $repliedFaculty.' of '.$totalFaculty.' faculty replied · availability blocker',
        $resourceBlockers->isNotEmpty() => 'Faculty availability or qualification needed',
        $totalFaculty > 0 => $repliedFaculty.' of '.$totalFaculty.' faculty replied',
        default => 'Qualified faculty and rooms ready',
    };

    $timetableSummary = match (true) {
        $isClosedMode => $currentVersion !== null ? 'Official timetable archived' : 'Closed without published timetable',
        $currentVersion !== null => 'Official timetable published',
        $activeRun && $activeRun->status === \App\Models\ScheduleGenerationRun::StatusUnderReview => 'Draft ready to review and publish',
        $activeRun && in_array($activeRun->status, [\App\Models\ScheduleGenerationRun::StatusQueued, \App\Models\ScheduleGenerationRun::StatusDispatching], true) => 'Generation in progress',
        $activeRun !== null => 'Timetable attempt needs attention',
        (bool) ($readiness['ready'] ?? false) => 'Ready to generate timetable',
        default => 'Finish steps 1–3, then generate',
    };

    $steps = [
        [
            'number' => 1,
            'key' => 'calendar',
            'label' => 'Calendar',
            'url' => \App\Filament\Pages\TermCalendar::getUrl(['termId' => $term->id]),
            'done' => $calendarDone,
            'has_attention' => ! $isLiveOrClosed && ! $calendarDone && $draftPackages->isNotEmpty(),
            'summary' => $calendarSummary,
        ],
        [
            'number' => 2,
            'key' => 'classes',
            'label' => 'Classes & sections',
            'url' => \App\Filament\Pages\TermClasses::getUrl(['termId' => $term->id]),
            'done' => $classesDone,
            'has_attention' => ! $isLiveOrClosed && ! $classesDone && ($unreadyClasses > 0 || ($recordedCount > 0 && ($cohortCount === 0 || $demandsCount === 0))),
            'summary' => $classesSummary,
        ],
        [
            'number' => 3,
            'key' => 'resources',
            'label' => 'Faculty & rooms',
            'url' => \App\Filament\Pages\TermResources::getUrl(['termId' => $term->id]),
            'done' => $resourcesDone,
            'has_attention' => ! $isLiveOrClosed && ! $resourcesDone && ($effectiveMissingQualifications > 0 || ($calendarDone && (! $hasRooms || $resourceBlockers->isNotEmpty()))),
            'summary' => $resourcesSummary,
        ],
        [
            'number' => 4,
            'key' => 'timetable',
            'label' => 'Timetable',
            'url' => \App\Filament\Pages\TermTimetable::getUrl(['termId' => $term->id]),
            'done' => $timetableDone,
            'has_attention' => ! $isLiveOrClosed && ! $timetableDone && $activeRun !== null && ! in_array($activeRun->status, [\App\Models\ScheduleGenerationRun::StatusQueued, \App\Models\ScheduleGenerationRun::StatusDispatching], true),
            'summary' => $timetableSummary,
        ],
    ];

    $firstIncompleteIndex = collect($steps)->search(fn (array $step): bool => ! $step['done']);
    $activeStageKey = match ($viewTab ?? 'plan') {
        'overview' => 'calendar',
        'classes' => 'classes',
        'resources' => 'resources',
        'generate', 'published' => 'timetable',
        default => null,
    };
@endphp

<nav @class([
    'tala-stage-bar',
    'tala-stage-bar--compact' => $compact,
    'is-live-mode' => $isLiveMode,
    'is-closed-mode' => $isClosedMode,
])
     aria-label="Term planning progress"
     data-lifecycle-mode="{{ $lifecycleMode }}"
     data-step-states="Done,Next step,Needs attention,Pending">
    <ol class="tala-stage-bar__list">
        @foreach ($steps as $index => $step)
            @php
                $isViewingStep = $activeStageKey !== null && $activeStageKey === $step['key'];
                $isCurrentStep = ! $isLiveOrClosed && $firstIncompleteIndex !== false && $index === $firstIncompleteIndex;
                $hasAttention = ! $isLiveOrClosed && (bool) ($step['has_attention'] ?? false);
                $statusLabel = match (true) {
                    $isClosedMode => 'Closed',
                    $isLiveMode && $step['key'] === 'timetable' => 'Published',
                    $isLiveMode => 'Live',
                    $step['done'] => 'Done',
                    $isCurrentStep && $hasAttention => 'Needs attention',
                    $isCurrentStep => 'Next step',
                    $hasAttention => 'Needs attention',
                    default => 'Pending',
                };
                $statusColor = match (true) {
                    $isClosedMode => 'gray',
                    $isLiveMode => 'success',
                    $step['done'] => 'success',
                    $hasAttention => 'warning',
                    $isCurrentStep => 'primary',
                    default => 'gray',
                };
            @endphp
            <li class="tala-stage-bar__item">
                <a @class([
                    'tala-stage-bar__step',
                    'is-active' => $isViewingStep,
                    'is-viewing' => $isViewingStep,
                    'is-done' => $step['done'],
                    'is-current' => $isCurrentStep,
                    'is-attention' => $hasAttention,
                    'is-pending' => ! $step['done'] && ! $isCurrentStep && ! $hasAttention && ! $isLiveOrClosed,
                    'is-live' => $isLiveMode,
                    'is-closed' => $isClosedMode,
                ])
                   href="{{ $step['url'] }}"
                   @if ($isViewingStep) aria-current="step" @endif>
                    <span class="tala-stage-bar__top">
                        <span class="tala-stage-bar__title">{{ $step['number'] }}. {{ $step['label'] }}</span>
                        <x-filament::badge :color="$statusColor" size="sm">{{ $statusLabel }}</x-filament::badge>
                    </span>
                    <span class="tala-stage-bar__summary">{{ $step['summary'] }}</span>
                </a>
            </li>
        @endforeach
    </ol>
</nav>
@endif
