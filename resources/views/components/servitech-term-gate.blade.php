@props([
    'heading',
    'description' => null,
    'icon' => 'heroicon-o-calendar-days',
    'headingId' => 'servitech-term-gate-heading',
    'attention' => [],
    'attentionLabel' => 'Queues needing attention',
    'urgentLabel' => 'Items needing attention',
    'split' => false,
])
@php
    $attentionItems = collect($attention)
        ->filter(fn ($item): bool => is_array($item) && filled($item['label'] ?? null))
        ->values();
@endphp
<section {{ $attributes->class(['tala-record-picker', 'servitech-journey-scene', 'tala-term-gate']) }} aria-labelledby="{{ $headingId }}">
    <x-servitech-journey-scenery />
    <div class="servitech-picker-content">
        <header class="tala-record-picker-heading">
            <x-filament::icon :icon="$icon" class="tala-record-picker-icon" aria-hidden="true" />
            <h2 id="{{ $headingId }}">{{ $heading }}</h2>
            @if (filled($description))
                <p>{{ $description }}</p>
            @endif
        </header>

        @if ($attentionItems->isNotEmpty())
            <div class="tala-gate-attention" role="region" aria-label="{{ $attentionLabel }}">
                @foreach ($attentionItems as $item)
                    @php
                        $itemIcon = $item['icon'] ?? 'heroicon-o-exclamation-circle';
                        $itemHref = $item['href'] ?? null;
                        $itemWireClick = $item['wireClick'] ?? null;
                    @endphp
                    @if (filled($itemHref))
                        <a href="{{ $itemHref }}" class="tala-gate-attention__item">
                            <x-filament::icon :icon="$itemIcon" class="tala-gate-attention__icon" aria-hidden="true" />
                            <span class="tala-gate-attention__label">{{ $item['label'] }}</span>
                            @isset($item['count'])
                                <x-filament::badge color="warning" size="sm" class="tala-gate-attention__badge">{{ $item['count'] }}</x-filament::badge>
                            @endisset
                            <x-filament::icon icon="heroicon-m-chevron-right" class="tala-gate-attention__chevron" aria-hidden="true" />
                        </a>
                    @else
                        <button type="button" @if (filled($itemWireClick)) wire:click="{{ $itemWireClick }}" @endif class="tala-gate-attention__item">
                            <x-filament::icon :icon="$itemIcon" class="tala-gate-attention__icon" aria-hidden="true" />
                            <span class="tala-gate-attention__label">{{ $item['label'] }}</span>
                            @isset($item['count'])
                                <x-filament::badge color="warning" size="sm" class="tala-gate-attention__badge">{{ $item['count'] }}</x-filament::badge>
                            @endisset
                            <x-filament::icon icon="heroicon-m-chevron-right" class="tala-gate-attention__chevron" aria-hidden="true" />
                        </button>
                    @endif
                @endforeach
            </div>
        @endif

        @if (isset($urgent) && trim((string) $urgent) !== '')
            <div class="tala-term-gate__urgent" role="region" aria-label="{{ $urgentLabel }}">
                {{ $urgent }}
            </div>
        @endif

        @if (trim((string) $slot) !== '')
            <div @class(['tala-term-gate__controls', 'tala-term-gate__controls--split' => $split])>
                {{ $slot }}
            </div>
        @endif

        @if (isset($actions) && trim((string) $actions) !== '')
            <div class="tala-term-gate__actions">
                {{ $actions }}
            </div>
        @endif

        @if (isset($note) && trim((string) $note) !== '')
            <p class="tala-term-gate__note">{{ $note }}</p>
        @endif
    </div>
</section>
