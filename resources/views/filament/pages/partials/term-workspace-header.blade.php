@php
    $confirmedClasses = $contextClasses->whereNotNull('confirmed_at')->count();
    $classesUrl = \App\Filament\Pages\TermClasses::getUrl(['termId' => $term?->id]);
    $stages = [
        ['key' => 'plan', 'label' => 'Term summary', 'icon' => 'heroicon-o-home', 'url' => \App\Filament\Pages\TermOverview::getUrl(['termId' => $term?->id]), 'state' => 'Preparation & next work', 'ready' => false],
        ['key' => 'overview', 'label' => 'Calendar', 'icon' => 'heroicon-o-calendar-days', 'url' => \App\Filament\Pages\TermCalendar::getUrl(['termId' => $term?->id]), 'state' => $draftPackages->isNotEmpty() ? 'Draft to review' : ($activePackage ? 'Ready' : 'Not recorded'), 'ready' => $activePackage && $draftPackages->isEmpty()],
        ['key' => 'classes', 'label' => 'Classes & sections', 'icon' => 'heroicon-o-book-open', 'url' => $classesUrl, 'state' => $contextClasses->isEmpty() ? 'No classes yet' : $confirmedClasses.' of '.$contextClasses->count().' confirmed', 'ready' => false],
        ['key' => 'resources', 'label' => 'Faculty & rooms', 'icon' => 'heroicon-o-user-group', 'url' => \App\Filament\Pages\TermResources::getUrl(['termId' => $term?->id]), 'state' => $missingQualifications ? 'Teacher needed' : ($facultyDeadline ? 'Review inputs' : 'Deadline not set'), 'ready' => false],
        ['key' => 'generate', 'label' => 'Timetable', 'icon' => 'heroicon-o-table-cells', 'url' => \App\Filament\Pages\TermTimetable::getUrl(['termId' => $term?->id]), 'state' => $currentVersion ? 'Official v'.$currentVersion->version : ($activeRun ? (\App\Models\ScheduleGenerationRun::statusOptions()[$activeRun->status] ?? 'Draft recorded') : 'No draft'), 'ready' => (bool) $currentVersion],
    ];
    $taskTitle = match ($viewTab) {
        'overview' => $readOnly || $overviewPackage ? 'Teaching calendar' : 'Set the teaching week',
        'classes' => $this instanceof \App\Filament\Pages\TermClassProposal ? 'Prepare Term classes' : 'Classes & student sections',
        'resources' => 'Prepare teaching resources',
        'generate', 'published' => 'Review the timetable',
        default => 'Get this Term ready',
    };
    $isTermChooser = ! $term && $viewTab === 'plan';
@endphp
<header @class(['tala-guide-masthead', 'is-term-chooser' => $isTermChooser]) aria-label="Term context">
    <div class="tala-guide-school">
        <img src="{{ asset('images/brand/servitech-crest.webp') }}" alt="" width="40" height="40">
        <span><strong>Servitech Institute Asia</strong><small>Term planning</small></span>
    </div>
    @if ($isTermChooser)
        @if (! $readOnly)
            <x-filament::button tag="a" color="gray" icon="heroicon-o-plus" :href="\App\Filament\Resources\Terms\TermResource::getUrl('create', ['viewTab' => $viewTab])">Create Term</x-filament::button>
        @else
            <x-filament::button tag="a" color="gray" icon="heroicon-o-eye" :href="\App\Filament\Pages\AcademicApprovals::getUrl()">Academic Oversight</x-filament::button>
        @endif
    @else
        <div class="tala-term-picker">{{ $this->termSelection }}</div>
        <x-filament::dropdown placement="bottom-end">
            <x-slot name="trigger"><x-filament::button color="gray" icon="heroicon-o-chevron-down" icon-position="after">Term options</x-filament::button></x-slot>
            <x-filament::dropdown.list>
                @if ($term)<x-filament::dropdown.list.item tag="a" icon="heroicon-o-clock" :href="\App\Filament\Resources\Terms\TermResource::getUrl('view', ['record' => $term, 'termId' => $term->id, 'viewTab' => $viewTab])">Term details & history</x-filament::dropdown.list.item>@endif
                @if (! $readOnly)<x-filament::dropdown.list.item tag="a" icon="heroicon-o-plus" :href="\App\Filament\Resources\Terms\TermResource::getUrl('create', ['termId' => $term?->id, 'viewTab' => $viewTab])">Create Term</x-filament::dropdown.list.item>
                @else<x-filament::dropdown.list.item tag="a" icon="heroicon-o-eye" :href="\App\Filament\Pages\AcademicApprovals::getUrl(['termId' => $term?->id])">Academic Oversight</x-filament::dropdown.list.item>@endif
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    @endif
</header>
@if ($term)
    <x-tala-term-destinations :navigation="$this->getWorkspaceNavigation()" :states="collect($stages)->keyBy('label')->all()" />
@endif
<div class="tala-guide-title">
    <div><h1>{{ $isTermChooser ? 'Choose a Term to plan' : ($readOnly ? str_replace(['Get this Term ready', 'Prepare teaching resources'], ['Inspect this Term', 'Teaching resources'], $taskTitle) : $taskTitle) }}</h1>
    @if ($isTermChooser)<p>Open a Term to prepare its calendar, classes, teaching resources and timetable.</p>
    @elseif ($viewTab === 'plan')<p>You can prepare each area in any order. Check its recorded state, then open the work you need.</p>
    @elseif ($readOnly)<p>Read-only · Registrar owns these records.</p>@endif</div>
</div>
