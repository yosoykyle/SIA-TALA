@props(['heading' => 'History', 'paginator' => null, 'method' => 'gotoPage', 'hasEntries' => true])
<section {{ $attributes->class(['tala-record-history-panel']) }} aria-label="{{ $heading }}">
    <header><x-filament::icon icon="heroicon-o-clock" aria-hidden="true" /><h3>{{ $heading }}</h3><span>Latest first · Asia/Manila</span></header>
    @if ($hasEntries)
        <ol class="tala-record-timeline">{{ $slot }}</ol>
    @else
        <p class="tala-record-history-empty">{{ $empty ?? 'No history recorded yet.' }}</p>
    @endif
    @if ($paginator?->hasPages())
        <x-record-collection-footer :paginator="$paginator" :method="$method" label="entries" />
    @endif
</section>
