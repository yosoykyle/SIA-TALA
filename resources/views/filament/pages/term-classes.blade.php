<x-filament-panels::page class="tala-term-workspace">
    @include('filament.pages.partials.term-workspace-header')
    @if ($term)
        <section class="tala-task-work {{ $classesView === 'cohorts' ? 'tala-term-cohorts' : '' }}" aria-label="Classes and student sections">
        <div class="tala-task-heading">
            <p class="tala-work-summary">{{ $classesView === 'cohorts' ? 'Groups of learners who take classes together.' : 'Review the classes that will run and the meetings they need.' }}</p>
            <div class="tala-task-commands">
                @if ($classesView === 'cohorts' && ! $readOnly){{ $this->recordCohortAction }}@endif
                <x-filament::button tag="a" color="gray" :icon="$classesView === 'classes' ? 'heroicon-o-user-group' : 'heroicon-o-book-open'" :href="\App\Filament\Pages\TermClasses::getUrl(['termId' => $term->id, ...($classesView === 'classes' ? ['classesView' => 'cohorts'] : [])])">{{ $classesView === 'classes' ? 'View student sections' : 'View classes' }}</x-filament::button>
            </div>
        </div>
        @if ($classesView === 'classes')
            @if (! $activePackage)<p class="text-sm" role="status">Preparing and confirming classes requires an active teaching calendar. Student sections can be recorded now. <x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\TermCalendar::getUrl(['termId' => $term->id])">Calendar</x-filament::button></p>@endif
            {{ $this->table }}
        @else
            {{ $this->table }}
        @endif
        </section>
    @else<p>Select a Term to prepare its sections and classes.</p>@endif
    <x-filament-actions::modals />
</x-filament-panels::page>
