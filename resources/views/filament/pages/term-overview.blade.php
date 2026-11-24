<x-filament-panels::page class="tala-term-workspace">
    @include('filament.pages.partials.term-workspace-header')

    <section class="tala-task-work tala-workspace-overview" aria-label="Term Planning directory">
        <div class="tala-work-tabs tala-work-tabs--full">
            <x-tala-navigation label="Term Planning directory view" compact stretch class="tala-work-tabs__nav">
                <x-filament::tabs.item
                    tag="a"
                    :href="\App\Filament\Pages\TermOverview::getUrl()"
                    :active="$overviewTab === 'terms'"
                    icon="heroicon-o-calendar-days"
                    wire:click.prevent="chooseOverviewTab('terms')"
                    wire:loading.attr="disabled"
                    wire:target="chooseOverviewTab"
                >
                    Terms
                </x-filament::tabs.item>
                <x-filament::tabs.item
                    tag="a"
                    :href="\App\Filament\Pages\TermOverview::getUrl(['overviewTab' => 'academic-years'])"
                    :active="$overviewTab === 'academic-years'"
                    icon="heroicon-o-academic-cap"
                    wire:click.prevent="chooseOverviewTab('academic-years')"
                    wire:loading.attr="disabled"
                    wire:target="chooseOverviewTab"
                >
                    Academic years
                </x-filament::tabs.item>
            </x-tala-navigation>
        </div>

        @if ($overviewTab === 'academic-years')
            <div class="tala-resource-readiness-bar" role="note" aria-label="Academic years overview">
                <div class="tala-resource-readiness-bar__notice" style="background: var(--tala-surface-sunken);">
                    <div class="tala-resource-readiness-bar__notice-copy">
                        <x-filament::icon icon="heroicon-o-information-circle" class="tala-resource-readiness-bar__notice-icon" aria-hidden="true" />
                        <p>Academic years group semesters and anchor incoming curriculum batches. A draft year activates automatically when its first term activates.</p>
                    </div>
                </div>
            </div>
        @endif

        {{ $this->table }}
    </section>

    <x-filament-actions::modals />
</x-filament-panels::page>
