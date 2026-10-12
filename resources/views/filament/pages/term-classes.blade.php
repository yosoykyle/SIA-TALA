<x-filament-panels::page>
    @include('filament.pages.partials.term-workspace-header')
    @if ($term)
        <section class="tala-task-work" aria-label="Classes and student sections">
        <div class="tala-work-toolbar"><x-tala-navigation label="Class planning records"><x-filament::tabs.item type="button" wire:click="chooseClassesView('classes')" :active="$classesView === 'classes'">Classes</x-filament::tabs.item><x-filament::tabs.item type="button" wire:click="chooseClassesView('cohorts')" :active="$classesView === 'cohorts'">Student sections</x-filament::tabs.item></x-tala-navigation>@if ($classesView === 'cohorts' && ! $readOnly){{ $this->recordCohortAction }}@endif</div>
        @if ($classesView === 'classes')
            @if (! $activePackage)<p class="text-sm" role="status">Preparing and confirming classes requires an active teaching calendar. Student sections can be recorded now. <x-filament::link :href="\App\Filament\Pages\TermCalendar::getUrl(['termId' => $term->id])">Calendar</x-filament::link></p>@endif
            {{ $this->table }}
        @else
            <p class="text-sm">All student sections in this Term. Program and curriculum-year filters apply to the Classes view.</p>
            {{ $this->table }}
        @endif
        </section>
    @else<p>Select a Term to prepare its sections and classes.</p>@endif
    <x-filament-actions::modals />
</x-filament-panels::page>
