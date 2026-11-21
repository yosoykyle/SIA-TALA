<x-filament-panels::page class="tala-term-workspace">
    @php
        $extraHeaderActions = $term ? array_values(array_filter([
            $classesView === 'classes' && ! $activePackage
                ? \Filament\Actions\Action::make('openCalendar')->label('Calendar')->color('gray')->icon('heroicon-o-calendar-days')->url(\App\Filament\Pages\TermCalendar::getUrl(['termId' => $term->id]))
                : null,
            $classesView === 'classes' && \App\Filament\Resources\SchedulingDemands\SchedulingDemandResource::canAccess()
                ? \Filament\Actions\Action::make('scheduleRequirements')
                    ->label('Schedule requirements')
                    ->color('gray')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->url(\App\Filament\Resources\SchedulingDemands\SchedulingDemandResource::getUrl('index', [
                        'termId' => $term->id,
                        'filters' => ['term_id' => ['value' => $term->id]],
                    ]))
                : null,
        ])) : [];
    @endphp
    @include('filament.pages.partials.term-workspace-header', ['extraHeaderActions' => $extraHeaderActions])
    @if ($term)
        <section class="tala-task-work {{ $classesView === 'cohorts' ? 'tala-term-cohorts' : '' }}" aria-label="Classes and student sections">
            <div class="tala-work-toolbar">
                <x-tala-navigation label="Classes view" compact>
                    <x-filament::tabs.item
                        tag="a"
                        :href="\App\Filament\Pages\TermClasses::getUrl(['termId' => $term->id])"
                        :active="$classesView === 'classes'"
                        icon="heroicon-o-book-open"
                        wire:click.prevent="chooseClassesView('classes')"
                        wire:loading.attr="disabled"
                        wire:target="chooseClassesView"
                    >
                        Classes
                    </x-filament::tabs.item>
                    <x-filament::tabs.item
                        tag="a"
                        :href="\App\Filament\Pages\TermClasses::getUrl(['termId' => $term->id, 'classesView' => 'cohorts'])"
                        :active="$classesView === 'cohorts'"
                        icon="heroicon-o-user-group"
                        wire:click.prevent="chooseClassesView('cohorts')"
                        wire:loading.attr="disabled"
                        wire:target="chooseClassesView"
                    >
                        Student sections
                    </x-filament::tabs.item>
                </x-tala-navigation>
            </div>
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
