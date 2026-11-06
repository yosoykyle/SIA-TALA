@props(['beforeLabel' => 'Before', 'afterLabel' => 'After', 'highlightAfter' => true])
<div {{ $attributes->class(['tala-record-change-comparison']) }}>
    <div class="tala-record-change-before"><p class="tala-record-change-label">{{ $beforeLabel }}</p>{{ $before }}</div>
    <x-filament::icon icon="heroicon-m-arrow-right" aria-hidden="true" />
    <div @class(['tala-record-change-after', 'tala-record-change-after--emphasized' => $highlightAfter])><p class="tala-record-change-label">{{ $afterLabel }}</p>{{ $after }}</div>
</div>
