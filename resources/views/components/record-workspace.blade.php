@props(['collectionLabel' => 'students', 'selectionParameter' => 'result', 'selectionActions' => 'selectResult,selectIncResult,selectStudent', 'loadingLabel' => 'Loading selected record'])
<div {{ $attributes->class(['tala-record-workspace', 'tala-record-workspace--framed']) }} wire:loading.attr="aria-busy"
    x-data="{ detailOpen: new URL(window.location.href).searchParams.has(@js($selectionParameter)) }"
    x-init="$nextTick(() => { const selected = $refs.recordBrowser.querySelector('.tala-record-option[aria-pressed=true]'); const list = selected?.closest('.tala-record-list'); if (list) list.scrollTop += selected.getBoundingClientRect().top - list.getBoundingClientRect().top })"
    x-bind:data-record-view="detailOpen ? 'detail' : 'list'"
    x-on:roster-student-selected.window="detailOpen = true; $nextTick(() => { $refs.recordInspector.scrollTop = 0; $refs.recordInspector.focus() })"
    x-on:record-selected.window="detailOpen = true; $nextTick(() => { $refs.recordInspector.scrollTop = 0; if (window.matchMedia('(max-width: 47.99rem)').matches) $refs.recordInspector.focus() })"
    x-on:click="if ($event.target.closest('.tala-record-option, .tala-record-selection-navigation button')) { detailOpen = true; $nextTick(() => { $refs.recordInspector.scrollTop = 0; if (window.matchMedia('(max-width: 47.99rem)').matches) $refs.recordInspector.focus() }) }">
    @isset($toolbar)<header {{ $toolbar->attributes->class(['tala-record-toolbar']) }}>{{ $toolbar }}</header>@endisset
    <section {{ $browser->attributes->class(['tala-record-browser']) }} x-ref="recordBrowser" tabindex="-1">
        {{ $browser }}
        @isset($collectionFooter)<div class="tala-record-collection-footer">{{ $collectionFooter }}</div>@endisset
    </section>
    <div class="tala-record-detail">
    <section {{ $inspector->attributes->class(['tala-record-inspector']) }} x-ref="recordInspector" tabindex="0">
        <div class="tala-record-mobile-back"><x-filament::button type="button" color="gray" icon="heroicon-m-arrow-left" x-on:click="detailOpen = false; $nextTick(() => $refs.recordBrowser.focus())">Back to {{ $collectionLabel }}</x-filament::button></div>
        <div class="tala-content-region" wire:loading.attr="aria-busy" wire:target="{{ $selectionActions }}">
            <x-content-progress :label="$loadingLabel" wire:loading.delay.short.block wire:target="{{ $selectionActions }}" />
            {{ $inspector }}
        </div>
    </section>
    @isset($footer)
        <footer {{ $footer->attributes->class(['tala-record-footer']) }}>{{ $footer }}</footer>
    @endisset
    </div>
</div>
