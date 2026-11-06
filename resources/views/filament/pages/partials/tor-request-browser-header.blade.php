<div class="tala-record-browser-header">
    <div class="tala-record-browser-heading">
        <h2 class="tala-record-browser-title">TOR requests @if ($requests->total() > 0)<span>{{ $requests->total() }}</span>@endif</h2>
        <x-filament::actions :actions="$this->getCachedHeaderActions()" />
    </div>
    @if ($showFilters ?? true)
        <label class="tala-record-search"><span class="fi-sr-only">Search TOR requests</span><x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass"><x-filament::input type="search" wire:model.live.debounce.400ms="torSearch" placeholder="Student or request reference" /></x-filament::input.wrapper></label>
        <label><span class="fi-sr-only">Request status</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="torScope"><option value="">All requests</option><option value="Open">Unissued requests</option><option value="Issued">Has issuance history</option></x-filament::input.select></x-filament::input.wrapper></label>
    @endif
</div>
