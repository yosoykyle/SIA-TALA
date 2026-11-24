<div class="tala-workspace-heading">
    <x-filament-panels::header :actions="$this->showOversightHeaderActions() ? $this->getCachedHeaderActions() : []" :heading="$this->getHeading()" :subheading="$this->getSubheading()" :breadcrumbs="$this->getBreadcrumbs()" />
    @if ($this->canMutateCompletion())
        <div class="tala-workspace-heading-controls">
            <x-tala-navigation label="Completion and TOR work">
                <x-filament::tabs.item tag="a" :href="\App\Filament\Pages\CompletionAndTor::getUrl(['work' => 'tor'])" :active="$this->completionView === 'tor'" icon="heroicon-o-document-text" wire:click.prevent="selectCompletionView('tor')" wire:loading.attr="disabled" wire:target="selectCompletionView">TOR requests</x-filament::tabs.item>
                <x-filament::tabs.item tag="a" :href="\App\Filament\Pages\CompletionAndTor::getUrl(['work' => 'graduation'])" :active="$this->completionView === 'graduation'" icon="heroicon-o-academic-cap" wire:click.prevent="selectCompletionView('graduation')" wire:loading.attr="disabled" wire:target="selectCompletionView">Graduation</x-filament::tabs.item>
            </x-tala-navigation>
        </div>
    @endif
</div>
