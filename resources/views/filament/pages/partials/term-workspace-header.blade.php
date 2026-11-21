@php
    $isTermChooser = ! $term;
    $isProposal = $this instanceof \App\Filament\Pages\TermClassProposal;
    $taskTitle = match ($viewTab) {
        'overview' => $readOnly || $overviewPackage ? 'Teaching calendar' : 'Set the teaching week',
        'classes' => $isProposal ? 'Prepare classes' : ($classesView === 'cohorts' ? 'Student sections' : 'Classes & sections'),
        'resources' => $resourceView === 'rooms' ? 'Rooms' : 'Faculty & rooms',
        'generate', 'published' => 'Timetable',
        default => 'Term planning',
    };
    $taskBreadcrumbs = $term ? [
        \App\Filament\Pages\TermOverview::getUrl(['chooseTerm' => true]) => 'Term planning',
        ...($viewTab !== 'plan' ? [\App\Filament\Pages\TermOverview::getUrl(['termId' => $term->id]) => $term->typeLabel()] : []),
        ...($isProposal ? [\App\Filament\Pages\TermClasses::getUrl(['termId' => $term->id]) => 'Classes'] : []),
        $viewTab === 'plan' ? 'Term overview' : $taskTitle,
    ] : [];
    if ($readOnly) {
        $taskBreadcrumbs = [\App\Filament\Pages\AcademicApprovals::getUrl(['termId' => $term?->id]) => 'Academic oversight', ...($taskBreadcrumbs ?: ['Term planning'])];
    }
    $termStateColor = $term ? match ($term->state) {
        \App\Models\Term::StateActive => 'success',
        \App\Models\Term::StateDraft => 'info',
        default => 'gray',
    } : 'gray';
    $termStateLabel = $term ? (\App\Models\Term::stateOptions()[$term->state] ?? $term->state) : null;
    $termStartFormatted = $term ? ($term->starts_on->year === $term->ends_on->year ? $term->starts_on->format('M j') : $term->starts_on->format('M j, Y')) : null;
@endphp
<div class="tala-planning-heading">
    <x-filament-panels::header
        :heading="$isTermChooser ? 'Term planning' : ($viewTab === 'plan' ? $term->typeLabel() : $taskTitle)"
        :subheading="$isTermChooser ? ($terms->isEmpty() ? null : 'Choose a Term to plan.') : null"
        :breadcrumbs="$taskBreadcrumbs"
        :actions="$term ? [...($isProposal ? [$this->addCoursesAction] : []), ...($extraHeaderActions ?? []), \Filament\Actions\Action::make('changeTerm')->label('Change Term')->color('gray')->icon('heroicon-o-arrows-right-left')->url(\App\Filament\Pages\TermOverview::getUrl(['chooseTerm' => true]))] : ($terms->isNotEmpty() && ! $readOnly ? [\Filament\Actions\Action::make('addTerm')->label('Add Term')->icon('heroicon-o-plus')->url(\App\Filament\Resources\Terms\TermResource::getUrl('create', ['viewTab' => 'plan']))] : [])"
    />
    @if ($term)
        <div class="tala-term-context-strip">
            <div class="tala-term-context-strip__meta">
                @if ($viewTab !== 'plan')
                    <span>{{ $term->typeLabel() }}</span>
                    <span class="tala-term-context-strip__sep" aria-hidden="true">·</span>
                @endif
                @if ($term->label !== $term->typeLabel())
                    <span>{{ $term->label }}</span>
                    <span class="tala-term-context-strip__sep" aria-hidden="true">·</span>
                @endif
                @if ($term->academicYear?->label)
                    <span>{{ $term->academicYear->label }}</span>
                    <span class="tala-term-context-strip__sep" aria-hidden="true">·</span>
                @endif
                <span class="tala-term-context-strip__dates">
                    <x-filament::icon icon="heroicon-o-calendar-days" />
                    <time datetime="{{ $term->starts_on->toDateString() }}">{{ $termStartFormatted }}</time>
                    <span aria-hidden="true">–</span>
                    <time datetime="{{ $term->ends_on->toDateString() }}">{{ $term->ends_on->format('M j, Y') }}</time>
                </span>
            </div>
            <x-filament::badge :color="$termStateColor" size="sm">{{ $termStateLabel }}</x-filament::badge>
        </div>
        @if ($this->termAutoSelected)
            <p class="tala-work-footnote">Opened automatically: the only Term with an active teaching calendar.</p>
        @endif
        @if ($readOnly)
            <p class="tala-work-footnote">{{ $viewTab === 'plan' ? 'Read-only · The Registrar maintains these records.' : 'Read-only · Registrar maintains these records.' }}</p>
        @endif
        @include('filament.pages.partials.term-planning-stepper')
    @endif
</div>
