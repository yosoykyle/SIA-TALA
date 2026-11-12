@props(['heading' => 'History', 'paginator' => null, 'method' => 'gotoPage', 'hasEntries' => true, 'description' => null, 'headingTag' => 'h3', 'label' => 'entries', 'showCount' => false])
<section {{ $attributes->class(['tala-record-history-panel']) }} aria-label="{{ $heading }}">
    <header><x-filament::icon icon="heroicon-o-clock" aria-hidden="true" /><div><{{ $headingTag }}>{{ $heading }}</{{ $headingTag }}>@if ($description)<p>{{ $description }}</p>@endif</div><span>Latest first · Asia/Manila</span></header>
    @if ($hasEntries)
        <ol class="tala-record-timeline">{{ $slot }}</ol>
    @else
        <p class="tala-record-history-empty">{{ $empty ?? 'No history recorded yet.' }}</p>
    @endif
    @if ($paginator && ($paginator->hasPages() || $showCount))
        <x-record-collection-footer :paginator="$paginator" :method="$method" :label="$label" />
    @endif
</section>
