<div class="tala-workspace-heading">
    <x-filament-panels::header :heading="$this->getHeading()" :subheading="$this->getSubheading()" :breadcrumbs="$this->getBreadcrumbs()" />
    @if (auth()->user()?->hasRole(\App\Models\User::StaffRoleRegistrar))
        <div class="tala-workspace-heading-controls">
            <x-tala-navigation label="Completion and TOR work">
                <x-filament::tabs.item :active="$this->completionView === 'tor'" icon="heroicon-o-document-text" wire:click="selectCompletionView('tor')" wire:loading.attr="disabled" wire:target="selectCompletionView">TOR requests</x-filament::tabs.item>
                <x-filament::tabs.item :active="$this->completionView === 'graduation'" icon="heroicon-o-academic-cap" wire:click="selectCompletionView('graduation')" wire:loading.attr="disabled" wire:target="selectCompletionView">Graduation</x-filament::tabs.item>
            </x-tala-navigation>
        </div>
    @endif
</div>
