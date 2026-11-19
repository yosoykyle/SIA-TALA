<x-filament-panels::page class="tala-term-workspace">
    @php
        $extraHeaderActions = $term ? array_values(array_filter([
            $classesView === 'classes' && ! $activePackage
                ? \Filament\Actions\Action::make('openCalendar')->label('Calendar')->color('gray')->icon('heroicon-o-calendar-days')->url(\App\Filament\Pages\TermCalendar::getUrl(['termId' => $term->id]))
                : null,
            \Filament\Actions\Action::make('toggleClassesView')
                ->label($classesView === 'classes' ? 'View student sections' : 'View classes')
                ->color('gray')
                ->icon($classesView === 'classes' ? 'heroicon-o-user-group' : 'heroicon-o-book-open')
                ->url(\App\Filament\Pages\TermClasses::getUrl(['termId' => $term->id, ...($classesView === 'classes' ? ['classesView' => 'cohorts'] : [])])),
        ])) : [];
    @endphp
    @include('filament.pages.partials.term-workspace-header', ['extraHeaderActions' => $extraHeaderActions])
    @if ($term)
        <section class="tala-task-work {{ $classesView === 'cohorts' ? 'tala-term-cohorts' : '' }}" aria-label="Classes and student sections">
            <p class="tala-work-summary">{{ $classesView === 'cohorts' ? 'Groups of learners who take classes together.' : 'Review the classes that will run and the meetings they need.' }}</p>
            @if ($classesView === 'classes' && ! $activePackage)
                <p class="tala-work-footnote" role="status">Preparing and confirming classes requires an active teaching calendar. Student sections can be recorded now.</p>
            @endif
            {{ $this->table }}
        </section>
    @else
        <x-servitech-empty-state illustrated heading="Select a Term" description="Select a Term to prepare its sections and classes." />
    @endif
    <x-filament-actions::modals />
</x-filament-panels::page>
