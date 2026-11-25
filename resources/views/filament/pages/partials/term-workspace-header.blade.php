@php
    $taskTitle = match ($viewTab) {
        'overview' => 'Term calendar',
        'classes' => 'Classes & sections',
        'resources' => 'Faculty & rooms',
        'generate', 'published' => 'Timetable',
        default => 'Term Planning',
    };
    $taskBreadcrumbs = [];
    if ($term) {
        if ($readOnly) {
            $taskBreadcrumbs[\App\Filament\Pages\AcademicApprovals::getUrl(['termId' => $term->id])] = 'Academic Oversight';
        }
        $taskBreadcrumbs[\App\Filament\Pages\TermOverview::getUrl()] = 'Term Planning';
        if ($term->academic_year_id && $term->academicYear) {
            $taskBreadcrumbs[\App\Filament\Pages\TermOverview::getUrl(['academicYearId' => $term->academic_year_id])] = (string) $term->academicYear->label;
        }
        $taskBreadcrumbs[] = $taskTitle;
    } elseif ($readOnly) {
        $taskBreadcrumbs = [
            \App\Filament\Pages\AcademicApprovals::getUrl() => 'Academic Oversight',
            'Term Planning',
        ];
    }

    $lifecycleMode = $term ? $this->termLifecycleMode($term) : 'draft';
    $isPublishedLive = $term && $term->state === \App\Models\Term::StateActive && $lifecycleMode === 'live';
    $termStateColor = $term ? match (true) {
        $isPublishedLive => 'success',
        $term->state === \App\Models\Term::StateActive => 'success',
        $term->state === \App\Models\Term::StateDraft => 'gray',
        default => 'gray',
    } : 'gray';
    $termStateLabel = $term ? ($isPublishedLive ? 'Published' : (\App\Models\Term::stateOptions()[$term->state] ?? $term->state)) : null;
    $termStateTitle = $term ? match (true) {
        $isPublishedLive => 'Active term with a published official timetable',
        $term->state === \App\Models\Term::StateActive => 'Active term: timetable not yet published',
        $term->state === \App\Models\Term::StateDraft => 'Draft term: setup in progress',
        default => 'Closed term: read-only historical record',
    } : null;
    $termStartFormatted = $term ? ($term->starts_on->year === $term->ends_on->year ? $term->starts_on->format('M j') : $term->starts_on->format('M j, Y')) : null;
    $switcherOptions = $term ? $this->termSwitcherOptions() : [];
    $headerActions = $term
        ? [
            ...($extraHeaderActions ?? []),
            $this->termSettingsAction,
        ]
        : [];
    $showStageBar = $term && ! $readOnly && in_array($viewTab, ['overview', 'classes', 'resources', 'generate', 'published'], true);
@endphp
<div class="tala-planning-heading">
    <x-filament-panels::header
        :heading="$term ? $taskTitle : 'Term Planning'"
        :subheading="$term ? null : 'Plan each semester across all programs: calendar, classes, faculty & rooms, and the school-wide timetable.'"
        :breadcrumbs="$taskBreadcrumbs"
        :actions="$headerActions"
    />
    @if ($term)
        <div class="tala-term-context-strip">
            <div class="tala-term-context-strip__switcher">
                <x-filament::input.wrapper prefix-icon="heroicon-o-calendar-days" class="tala-term-switcher">
                    <x-filament::input.select
                        id="term-context-switcher"
                        aria-label="Switch term"
                        wire:change="selectTerm($event.target.value)"
                    >
                        @foreach ($switcherOptions as $optionTermId => $optionLabel)
                            <option value="{{ $optionTermId }}" @selected((int) $optionTermId === (int) $term->id)>{{ $optionLabel }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
            <div class="tala-term-context-strip__meta">
                <span class="tala-term-context-strip__dates">
                    <time datetime="{{ $term->starts_on->toDateString() }}">{{ $termStartFormatted }}</time>
                    <span aria-hidden="true">–</span>
                    <time datetime="{{ $term->ends_on->toDateString() }}">{{ $term->ends_on->format('M j, Y') }}</time>
                </span>
                <x-filament::badge :color="$termStateColor" size="sm" :title="$termStateTitle">{{ $termStateLabel }}</x-filament::badge>
            </div>
        </div>
        @if ($showStageBar)
            @include('filament.pages.partials.term-planning-stepper')
        @endif
        @if ($this->termAutoSelected && filled($this->termAutoSelectBasis))
            <p class="tala-work-footnote">Opened automatically: {{ $this->termAutoSelectBasis }}.</p>
        @endif
        @if ($readOnly)
            <p class="tala-work-footnote" data-readonly-notice="Read-only · Registrar maintains these records.">Read-only · The Registrar maintains these records.</p>
        @endif
    @endif
</div>
