@props([
    'items' => [],
    'ariaLabel' => 'Summary metrics',
])

@php
    $itemCount = count($items);
@endphp

<section aria-label="{{ $ariaLabel }}" {{ $attributes->class(['tala-kpi-strip']) }}>
    <div @class([
        'tala-kpi-strip__grid',
        'tala-kpi-strip__grid--5' => $itemCount === 5,
    ])>
        @foreach ($items as $item)
            @php
                $href = $item['href'] ?? null;
                $active = (bool) ($item['active'] ?? false);
                $tone = in_array(($item['tone'] ?? 'default'), ['default', 'success', 'warning', 'danger'], true)
                    ? (string) $item['tone']
                    : 'default';
                $icon = $item['icon'] ?? null;
                $meta = $item['meta'] ?? null;
                $tag = filled($href) ? 'a' : 'div';
            @endphp
            <{{ $tag }}
                @if (filled($href))
                    href="{{ $href }}"
                    @if ($active) aria-current="page" @endif
                @endif
                @class([
                    'tala-kpi-tile',
                    'tala-kpi-tile--interactive' => filled($href),
                    'tala-kpi-tile--'.$tone => ! $active,
                    'is-active' => $active,
                ])
            >
                <div class="tala-kpi-tile__top">
                    <span class="tala-kpi-tile__label">{{ $item['label'] }}</span>
                    @if (filled($icon))
                        <x-filament::icon
                            :icon="$icon"
                            @class([
                                'tala-kpi-tile__icon',
                                'tala-kpi-tile__icon--'.$tone => ! $active,
                            ])
                        />
                    @endif
                </div>
                <p class="tala-kpi-tile__value">{{ $item['value'] }}</p>
                @if (filled($meta))
                    <p class="tala-kpi-tile__meta">{{ $meta }}</p>
                @endif
            </{{ $tag }}>
        @endforeach
    </div>
</section>
