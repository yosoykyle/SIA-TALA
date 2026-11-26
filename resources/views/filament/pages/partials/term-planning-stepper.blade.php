@if (! $readOnly)
@php
    $compact = (bool) ($compact ?? false);
    $resolvedStepState = (isset($stepState) && is_array($stepState))
        ? $stepState
        : ($term instanceof \App\Models\Term
            ? \App\Support\TermPlanningStepState::evaluate($term, (bool) ($readOnly ?? false))
            : null);

    $lifecycleMode = (string) ($resolvedStepState['lifecycle_mode'] ?? 'draft');
    $isLiveMode = $lifecycleMode === 'live';
    $isClosedMode = $lifecycleMode === 'closed';
    $isLiveOrClosed = (bool) ($resolvedStepState['is_live_or_closed'] ?? ($isLiveMode || $isClosedMode));

    $recordedCount = $sourceClasses->count();
    $confirmedCount = $contextClasses->whereNotNull('confirmed_at')->count();
    $cohortCount = $cohorts->count();
    $demandsCount = $demands->count();
    $unreadyClasses = $isLiveOrClosed ? 0 : max(0, $recordedCount - $confirmedCount);

    $calendarDone = (bool) ($resolvedStepState['calendar_done'] ?? false);
    $classesDone = (bool) ($resolvedStepState['classes_done'] ?? false);
    $resourcesDone = (bool) ($resolvedStepState['resources_done'] ?? false);
    $timetableDone = (bool) ($resolvedStepState['timetable_done'] ?? false);

    $resourceBlockers = $isLiveOrClosed
        ? collect()
        : collect($readiness['blockers'] ?? [])
            ->whereIn('code', \App\Support\TermPlanningStepState::RESOURCE_BLOCKER_CODES)
            ->values();
    $hasRooms = $rooms->isNotEmpty();
    $totalFaculty = (int) ($availabilityProjection['total_faculty'] ?? 0);
    $repliedFaculty = (int) ($availabilityProjection['replied_count'] ?? 0);
    $lateImpactCount = (int) ($availabilityProjection['late_impact_count'] ?? 0);
    $effectiveMissingQualifications = $isLiveOrClosed ? 0 : (int) $missingQualifications;

    $calendarSummary = match (true) {
        $isClosedMode => 'Closed · Read-only',
        $isLiveMode => 'Active · Dates & hours set',
        ! $activePackage && $draftPackages->isNotEmpty() => 'Draft needs activation',
        ! $activePackage => 'Not set',
        $draftPackages->isNotEmpty() => 'Draft needs activation',
        default => 'Active · Dates & hours set',
    };

    $classesSummary = match (true) {
        $isClosedMode => $confirmedCount.' '.\Illuminate\Support\Str::plural('class', $confirmedCount).' archived',
        $isLiveMode => $confirmedCount.' '.\Illuminate\Support\Str::plural('class', $confirmedCount).' published',
        $cohortCount === 0 => 'Add student sections',
        $recordedCount === 0 => 'Add classes',
        $unreadyClasses > 0 => $unreadyClasses.' to confirm',
        $demandsCount === 0 => 'Requirements pending',
        default => $confirmedCount.' confirmed',
    };

    $resourcesSummary = match (true) {
        $isClosedMode => 'Closed · Read-only',
        $isLiveMode && $lateImpactCount > 0 => $lateImpactCount.' late '.\Illuminate\Support\Str::plural('update', $lateImpactCount),
        $isLiveMode && $totalFaculty > 0 => $repliedFaculty.'/'.$totalFaculty.' faculty replied',
        $isLiveMode => 'Faculty & rooms active',
        ! $calendarDone => 'Complete Step 1 first',
        ! $classesDone && ! ($resolvedStepState['has_confirmed_class'] ?? false) => 'Complete Step 2 first',
        $effectiveMissingQualifications > 0 => $effectiveMissingQualifications.' '.\Illuminate\Support\Str::plural('subject', $effectiveMissingQualifications).' '.($effectiveMissingQualifications === 1 ? 'needs' : 'need').' faculty',
        ! $hasRooms => 'Add active room',
        $resourceBlockers->contains('code', 'rooms_unsuitable') || $resourceBlockers->contains('code', 'rooms_unavailable') => 'Add matching room',
        $resourceBlockers->isNotEmpty() && $totalFaculty > 0 => $repliedFaculty.'/'.$totalFaculty.' faculty replied',
        $resourceBlockers->isNotEmpty() => 'Availability needed',
        ! $classesDone => 'Confirm remaining classes',
        $totalFaculty > 0 => $repliedFaculty.'/'.$totalFaculty.' faculty replied',
        default => 'Faculty & rooms ready',
    };

    $timetableSummary = match (true) {
        $isClosedMode => $currentVersion !== null ? 'Archived' : 'Closed',
        $currentVersion !== null => 'Published',
        $activeRun && $activeRun->status === \App\Models\ScheduleGenerationRun::StatusUnderReview => 'Draft ready for review',
        $activeRun && in_array($activeRun->status, [\App\Models\ScheduleGenerationRun::StatusQueued, \App\Models\ScheduleGenerationRun::StatusDispatching], true) => 'Generating...',
        $activeRun !== null => 'Needs attention',
        (bool) ($readiness['ready'] ?? false) => 'Ready to generate',
        default => 'Complete steps 1-3',
    };

    $evaluatedSteps = $resolvedStepState['steps'] ?? [];

    $steps = [
        [
            'number' => 1,
            'key' => 'calendar',
            'label' => (string) ($evaluatedSteps['calendar']['label'] ?? 'Calendar'),
            'url' => \App\Filament\Pages\TermCalendar::getUrl(['termId' => $term->id]),
            'done' => $calendarDone,
            'locked' => (bool) ($evaluatedSteps['calendar']['locked'] ?? false),
            'required_step_number' => $evaluatedSteps['calendar']['required_step_number'] ?? null,
            'has_attention' => ! $isLiveOrClosed && ! $calendarDone && $draftPackages->isNotEmpty(),
            'summary' => $calendarSummary,
        ],
        [
            'number' => 2,
            'key' => 'classes',
            'label' => (string) ($evaluatedSteps['classes']['label'] ?? 'Sections & classes'),
            'url' => \App\Filament\Pages\TermClasses::getUrl(['termId' => $term->id]),
            'done' => $classesDone,
            'locked' => (bool) ($evaluatedSteps['classes']['locked'] ?? false),
            'required_step_number' => $evaluatedSteps['classes']['required_step_number'] ?? null,
            'has_attention' => ! $isLiveOrClosed && ! $classesDone && ($unreadyClasses > 0 || ($recordedCount > 0 && ($cohortCount === 0 || $demandsCount === 0))),
            'summary' => $classesSummary,
        ],
        [
            'number' => 3,
            'key' => 'resources',
            'label' => (string) ($evaluatedSteps['resources']['label'] ?? 'Faculty & rooms'),
            'url' => \App\Filament\Pages\TermResources::getUrl(['termId' => $term->id]),
            'done' => $resourcesDone,
            'locked' => (bool) ($evaluatedSteps['resources']['locked'] ?? false),
            'required_step_number' => $evaluatedSteps['resources']['required_step_number'] ?? null,
            'has_attention' => ! $isLiveOrClosed && ! $resourcesDone && ! (bool) ($evaluatedSteps['resources']['locked'] ?? false) && ($effectiveMissingQualifications > 0 || ($calendarDone && (! $hasRooms || $resourceBlockers->isNotEmpty()))),
            'summary' => $resourcesSummary,
        ],
        [
            'number' => 4,
            'key' => 'timetable',
            'label' => (string) ($evaluatedSteps['timetable']['label'] ?? 'Timetable'),
            'url' => \App\Filament\Pages\TermTimetable::getUrl(['termId' => $term->id]),
            'done' => $timetableDone,
            'locked' => (bool) ($evaluatedSteps['timetable']['locked'] ?? false),
            'required_step_number' => $evaluatedSteps['timetable']['required_step_number'] ?? null,
            'has_attention' => ! $isLiveOrClosed && ! $timetableDone && ! (bool) ($evaluatedSteps['timetable']['locked'] ?? false) && $activeRun !== null && ! in_array($activeRun->status, [\App\Models\ScheduleGenerationRun::StatusQueued, \App\Models\ScheduleGenerationRun::StatusDispatching], true),
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
     data-step-states="Done,Next step,Needs attention,Locked,Pending">
    <ol class="tala-stage-bar__list">
        @foreach ($steps as $index => $step)
            @php
                $isViewingStep = $activeStageKey !== null && $activeStageKey === $step['key'];
                $isLockedStep = ! $isLiveOrClosed && (bool) ($step['locked'] ?? false);
                $renderAsLocked = $isLockedStep && ! $isViewingStep;
                $isCurrentStep = ! $isLiveOrClosed && ! $isLockedStep && $firstIncompleteIndex !== false && $index === $firstIncompleteIndex;
                $isLiveResourceReview = $isLiveMode && $step['key'] === 'resources' && $lateImpactCount > 0;
                $hasAttention = (! $isLiveOrClosed && ! $isLockedStep && (bool) ($step['has_attention'] ?? false))
                    || $isLiveResourceReview;
                $lockedSummary = 'Complete Step '.((int) ($step['required_step_number'] ?? 1)).' first';
                $statusLabel = match (true) {
                    $isClosedMode => 'Closed',
                    $isLiveResourceReview => 'Needs review',
                    $isLiveMode && $step['key'] === 'timetable' => 'Published',
                    $isLiveMode => 'Live',
                    $isLockedStep => 'Locked',
                    $step['done'] => 'Done',
                    $isCurrentStep && $hasAttention => 'Needs attention',
                    $isCurrentStep => 'Next step',
                    $hasAttention => 'Needs attention',
                    default => 'Pending',
                };
                $statusColor = match (true) {
                    $isClosedMode => 'gray',
                    $isLiveResourceReview => 'warning',
                    $isLiveMode => 'success',
                    $isLockedStep => 'gray',
                    $step['done'] => 'success',
                    $hasAttention => 'warning',
                    $isCurrentStep => 'primary',
                    default => 'gray',
                };
            @endphp
            <li class="tala-stage-bar__item">
                @if ($renderAsLocked)
                    <span class="tala-stage-bar__step is-locked" aria-disabled="true">
                        <span class="tala-stage-bar__top">
                            <span class="tala-stage-bar__title">
                                <x-filament::icon icon="heroicon-o-lock-closed" class="tala-stage-bar__lock-icon" aria-hidden="true" />
                                <span>{{ $step['number'] }}. {{ $step['label'] }}</span>
                            </span>
                            <x-filament::badge color="gray" size="sm">Locked</x-filament::badge>
                        </span>
                        <span class="tala-stage-bar__summary">{{ $lockedSummary }}</span>
                    </span>
                @else
                    <a @class([
                        'tala-stage-bar__step',
                        'is-active' => $isViewingStep,
                        'is-viewing' => $isViewingStep,
                        'is-locked' => $isLockedStep,
                        'is-done' => $step['done'],
                        'is-current' => $isCurrentStep,
                        'is-attention' => $hasAttention,
                        'is-pending' => ! $step['done'] && ! $isCurrentStep && ! $hasAttention && ! $isLiveOrClosed && ! $isLockedStep,
                        'is-live' => $isLiveMode,
                        'is-closed' => $isClosedMode,
                    ])
                       href="{{ $step['url'] }}"
                       @if ($isViewingStep) aria-current="step" @endif>
                        <span class="tala-stage-bar__top">
                            <span class="tala-stage-bar__title">
                                @if ($isLockedStep)
                                    <x-filament::icon icon="heroicon-o-lock-closed" class="tala-stage-bar__lock-icon" aria-hidden="true" />
                                @endif
                                <span>{{ $step['number'] }}. {{ $step['label'] }}</span>
                            </span>
                            <x-filament::badge :color="$statusColor" size="sm">{{ $statusLabel }}</x-filament::badge>
                        </span>
                        <span class="tala-stage-bar__summary">{{ $isLockedStep ? $lockedSummary : $step['summary'] }}</span>
                    </a>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
@endif
