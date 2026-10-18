<x-filament-panels::page class="tala-term-workspace">
    @include('filament.pages.partials.term-workspace-header')
    @if ($term)
        <section class="tala-task-work {{ $classesView === 'cohorts' ? 'tala-term-cohorts' : '' }}" aria-label="Classes and student sections">
        <div class="tala-work-toolbar"><x-tala-navigation label="Class planning records"><x-filament::tabs.item type="button" wire:click="chooseClassesView('classes')" :active="$classesView === 'classes'" :aria-pressed="$classesView === 'classes'">Classes</x-filament::tabs.item><x-filament::tabs.item type="button" wire:click="chooseClassesView('cohorts')" :active="$classesView === 'cohorts'" :aria-pressed="$classesView === 'cohorts'">Student sections</x-filament::tabs.item></x-tala-navigation>@if ($classesView === 'cohorts' && ! $readOnly){{ $this->recordCohortAction }}@endif</div>
        @if ($classesView === 'classes')
            @if (! $activePackage)<p class="text-sm" role="status">Preparing and confirming classes requires an active teaching calendar. Student sections can be recorded now. <x-filament::link :href="\App\Filament\Pages\TermCalendar::getUrl(['termId' => $term->id])">Calendar</x-filament::link></p>@endif
            {{ $this->table }}
        @else
            <p class="tala-work-footnote">Student sections group learners who take classes together.</p>
            {{ $this->table }}
        @endif
        </section>
    @else<p>Select a Term to prepare its sections and classes.</p>@endif
    <x-filament-actions::modals />
</x-filament-panels::page>
