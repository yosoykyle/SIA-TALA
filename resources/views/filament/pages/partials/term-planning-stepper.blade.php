@php
    $recordedCount = $sourceClasses->count();
    $confirmedCount = $contextClasses->whereNotNull('confirmed_at')->count();
    $cohortCount = $cohorts->count();
    $demandsCount = $demands->count();
    $unreadyClasses = max(0, $recordedCount - $confirmedCount);
    $calendarDone = $activePackage !== null && $draftPackages->isEmpty();
    $classesDone = $cohortCount > 0 && $recordedCount > 0 && $unreadyClasses === 0 && $demandsCount > 0;

    $resourceBlockers = collect($readiness['blockers'] ?? [])
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
    $needsRoom = $sourceClasses->contains(fn ($class): bool => ($class->delivery_mode ?? $class->termOffering?->modality) === \App\Models\TermOffering::ModalityFaceToFace);
    $hasRooms = $rooms->isNotEmpty();
    $resourcesDone = $missingQualifications === 0 && (! $needsRoom || $hasRooms) && $resourceBlockers->isEmpty();
    $timetableDone = $currentVersion !== null;

    $calendarSummary = match (true) {
        ! $activePackage && $draftPackages->isNotEmpty() => 'Draft calendar needs activation',
        ! $activePackage => 'Set & activate teaching dates and hours',
        $draftPackages->isNotEmpty() => 'Pending calendar update to activate',
        default => 'Active · '.$activePackage->classes_start_on?->format('M j').'–'.$activePackage->classes_end_on?->format('M j'),
    };

    $classesSummary = match (true) {
        $cohortCount === 0 => 'Add at least one student section',
        $recordedCount === 0 => 'Choose courses & create classes',
        $unreadyClasses > 0 => $unreadyClasses.' '.\Illuminate\Support\Str::plural('class', $unreadyClasses).' need confirmation',
        $demandsCount === 0 => 'Prepare weekly meeting requirements',
        default => $confirmedCount.' '.\Illuminate\Support\Str::plural('class', $confirmedCount).' confirmed',
    };

    $totalFaculty = (int) ($availabilityProjection['total_faculty'] ?? 0);
    $repliedFaculty = (int) ($availabilityProjection['replied_count'] ?? 0);

    $resourcesSummary = match (true) {
        $missingQualifications > 0 => $missingQualifications.' '.\Illuminate\Support\Str::plural('course', $missingQualifications).' need qualified faculty',
        $needsRoom && ! $hasRooms => 'Add at least one active room',
        $resourceBlockers->contains('code', 'rooms_unsuitable') || $resourceBlockers->contains('code', 'rooms_unavailable') => 'Matching active room needed',
        $resourceBlockers->isNotEmpty() && $totalFaculty > 0 => $repliedFaculty.' of '.$totalFaculty.' faculty replied · availability blocker',
        $resourceBlockers->isNotEmpty() => 'Faculty availability or qualification needed',
        $totalFaculty > 0 => $repliedFaculty.' of '.$totalFaculty.' faculty replied',
        default => 'Qualified faculty & rooms ready',
    };

    $timetableSummary = match (true) {
        $currentVersion !== null => 'Official timetable published',
        $activeRun && $activeRun->status === \App\Models\ScheduleGenerationRun::StatusUnderReview => 'Draft candidate ready to review & publish',
        $activeRun && in_array($activeRun->status, [\App\Models\ScheduleGenerationRun::StatusQueued, \App\Models\ScheduleGenerationRun::StatusDispatching], true) => 'Generation in progress',
        $activeRun !== null => 'Timetable attempt needs attention',
        (bool) ($readiness['ready'] ?? false) => 'Ready to generate timetable',
        default => 'Finish steps 1–3, then generate',
    };

    $steps = [
        [
            'number' => 1,
            'key' => 'calendar',
            'label' => 'Teaching calendar & load',
            'url' => \App\Filament\Pages\TermCalendar::getUrl(['termId' => $term->id]),
            'is_viewing' => $viewTab === 'overview',
            'done' => $calendarDone,
            'summary' => $calendarSummary,
        ],
        [
            'number' => 2,
            'key' => 'classes',
            'label' => 'Student sections & classes',
            'url' => \App\Filament\Pages\TermClasses::getUrl(['termId' => $term->id]),
            'is_viewing' => $viewTab === 'classes',
            'done' => $classesDone,
            'summary' => $classesSummary,
        ],
        [
            'number' => 3,
            'key' => 'resources',
            'label' => 'Faculty & rooms',
            'url' => \App\Filament\Pages\TermResources::getUrl(['termId' => $term->id]),
            'is_viewing' => $viewTab === 'resources',
            'done' => $resourcesDone,
            'summary' => $resourcesSummary,
        ],
        [
            'number' => 4,
            'key' => 'timetable',
            'label' => 'Generate & publish timetable',
            'url' => \App\Filament\Pages\TermTimetable::getUrl(['termId' => $term->id]),
            'is_viewing' => in_array($viewTab, ['generate', 'published'], true),
            'done' => $timetableDone,
            'summary' => $timetableSummary,
        ],
    ];

    $firstIncompleteIndex = collect($steps)->search(fn (array $step): bool => ! $step['done']);
@endphp

<nav class="tala-planning-stepper" aria-label="Term planning steps">
    <ol class="tala-planning-stepper__list">
        @foreach ($steps as $index => $step)
            @php
                $isCurrentStep = $firstIncompleteIndex !== false && $index === $firstIncompleteIndex;
                $statusLabel = $step['done'] ? 'Done' : ($isCurrentStep ? 'Current' : 'Needs attention');
                $statusColor = $step['done'] ? 'success' : ($isCurrentStep ? 'info' : 'warning');
            @endphp
            <li @class([
                'tala-planning-stepper__item',
                'is-done' => $step['done'],
                'is-current' => $isCurrentStep,
                'is-attention' => ! $step['done'] && ! $isCurrentStep,
                'is-viewing' => $step['is_viewing'],
            ])>
                <a class="tala-planning-stepper__link"
                   href="{{ $step['url'] }}"
                   @if ($step['is_viewing']) aria-current="step" @endif>
                    <span class="tala-planning-stepper__top">
                        <span class="tala-planning-stepper__title">{{ $step['number'] }}. {{ $step['label'] }}</span>
                        <x-filament::badge :color="$statusColor" size="sm">{{ $statusLabel }}</x-filament::badge>
                    </span>
                    <span class="tala-planning-stepper__summary">{{ $step['summary'] }}</span>
                </a>
            </li>
        @endforeach
    </ol>
</nav>
