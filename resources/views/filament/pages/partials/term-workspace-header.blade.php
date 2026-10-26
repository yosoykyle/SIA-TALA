@php
    $isTermChooser = ! $term;
    $isProposal = $this instanceof \App\Filament\Pages\TermClassProposal;
    $taskTitle = match ($viewTab) {
        'overview' => $readOnly || $overviewPackage ? 'Teaching calendar' : 'Set the teaching week',
        'classes' => $isProposal ? 'Prepare classes' : ($classesView === 'cohorts' ? 'Student sections' : 'Classes & student sections'),
        'resources' => $resourceView === 'rooms' ? 'Rooms' : 'Faculty & rooms',
        'generate', 'published' => 'Timetable',
        default => 'Term planning',
    };
    $termContext = $term ? collect([$term->typeLabel(), $term->academicYear?->label])->filter()->unique()->implode(' · ') : null;
    $taskBreadcrumbs = $term ? [
        \App\Filament\Pages\TermOverview::getUrl(['chooseTerm' => true]) => 'Term planning',
        ...($viewTab !== 'plan' ? [\App\Filament\Pages\TermOverview::getUrl(['termId' => $term->id]) => $term->typeLabel()] : []),
        ...($isProposal ? [\App\Filament\Pages\TermClasses::getUrl(['termId' => $term->id]) => 'Classes'] : []),
        $viewTab === 'plan' ? 'Term overview' : $taskTitle,
    ] : [];
    if ($readOnly) {
        $taskBreadcrumbs = [\App\Filament\Pages\AcademicApprovals::getUrl(['termId' => $term?->id]) => 'Academic oversight', ...($taskBreadcrumbs ?: ['Term planning'])];
    }
@endphp
<div class="tala-planning-heading">
    <x-filament-panels::header
        :heading="$isTermChooser ? 'Term planning' : ($viewTab === 'plan' ? $term->typeLabel() : $taskTitle)"
        :subheading="$isTermChooser ? ($terms->isEmpty() ? null : 'Choose a Term to plan.') : ($viewTab === 'plan' ? $term->academicYear?->label : $termContext)"
        :breadcrumbs="$taskBreadcrumbs"
        :actions="$term ? [...($isProposal ? [$this->addCoursesAction] : []), \Filament\Actions\Action::make('changeTerm')->label('Change Term')->color('gray')->icon('heroicon-o-arrows-right-left')->url(\App\Filament\Pages\TermOverview::getUrl(['chooseTerm' => true]))] : ($terms->isNotEmpty() && ! $readOnly ? [\Filament\Actions\Action::make('addTerm')->label('Add Term')->icon('heroicon-o-plus')->url(\App\Filament\Resources\Terms\TermResource::getUrl('create', ['viewTab' => 'plan']))] : [])"
    />
    @if ($term)
        <div class="tala-planning-context-note">
            <span><x-filament::icon icon="heroicon-o-globe-alt" />School-wide planning · All programs and year levels</span>
            <details class="tala-planning-identity">
                <summary>Term details<x-filament::icon icon="heroicon-m-chevron-down" /></summary>
                <div class="tala-planning-reveal">
                    <p><strong>Term dates</strong><time datetime="{{ $term->starts_on->toDateString() }}">{{ $term->starts_on->format('M j, Y') }}</time> – <time datetime="{{ $term->ends_on->toDateString() }}">{{ $term->ends_on->format('M j, Y') }}</time></p>
                    <p><strong>Record status</strong>{{ \App\Models\Term::stateOptions()[$term->state] ?? $term->state }}</p>
                    @if ($term->label !== $term->typeLabel())<p><strong>Recorded name</strong>{{ $term->label }}</p>@endif
                    <p>A Term is the shared academic period; its recorded classes determine the timetable.</p>
                </div>
            </details>
        </div>
        @if ($this->termAutoSelected)<p class="tala-work-footnote">Opened automatically: the only Term with an active teaching calendar.</p>@endif
    @endif
    @if ($readOnly && $viewTab !== 'plan')
        <p class="tala-work-footnote">Read-only · Registrar maintains these records.</p>
    @endif
</div>
