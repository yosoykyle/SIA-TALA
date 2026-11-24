@props([
    'variant' => 'default',
])

@php
    $isAuthVariant = $variant === 'auth';
    $isStageVariant = $variant === 'stage';

    // Decorative ambient geometry aligned with the inactivity countdown, sign-in canvas, and error recovery screens.
    $shapes = [
        'spark' => '<svg viewBox="0 0 24 24" focusable="false"><path d="M12 1 C 13 8, 16 11, 23 12 C 16 13, 13 16, 12 23 C 11 16, 8 13, 1 12 C 8 11, 11 8, 12 1 Z"/></svg>',
        'star' => '<svg viewBox="0 0 24 24" focusable="false"><path d="M12 2.5 L 14.1 9.9 L 21.5 12 L 14.1 14.1 L 12 21.5 L 9.9 14.1 L 2.5 12 L 9.9 9.9 Z"/></svg>',
        'ring' => '<svg viewBox="0 0 24 24" focusable="false"><circle class="tala-ambient-line" cx="12" cy="12" r="8.75"/></svg>',
        'plus' => '<svg viewBox="0 0 24 24" focusable="false"><path class="tala-ambient-line" d="M12 3.5 V 20.5 M3.5 12 H 20.5"/></svg>',
        'squiggle' => '<svg viewBox="0 0 64 20" focusable="false"><path d="M2 10 C 8 0, 14 20, 20 10 S 32 0, 38 10 S 50 20, 62 10"/></svg>',
        'arc' => '<svg viewBox="0 0 32 20" focusable="false"><path d="M3 16 C 9 4, 23 4, 29 16"/></svg>',
        'plane' => '<svg viewBox="0 0 48 44" focusable="false"><path d="M2 16 L 46 2 L 22 40 L 15 27 Z"/></svg>',
        'dot' => '<span class="tala-ambient-dot"></span>',
    ];

    // [shape, desktopX%, desktopY%, sizePx, motion, tone, opacity, durationSec, delaySec, band, mobileX%, mobileYRem, midX%, midY%]
    $trinkets = [
        // Left flank (asymmetric coordinates outside the centered auth column across desktop & mid-width viewports)
        ['spark', 6, 12, 22, 'twinkle', 'green', 0.44, 6.8, -1.3, 'top', 7, 0.75, 5, 9],
        ['dot', 18, 9, 10, 'bob', 'gold', 0.34, 7.4, -3.1, 'top', 27, 1.85, 13, 22],
        ['plus', 24, 21, 16, 'turn', 'green', 0.38, 16.4, -4.6, null, null, null, 7, 34],
        ['ring', 13, 34, 20, 'bob', 'gold', 0.32, 8.8, -2.5, null, null, null, 12, 49],
        ['arc', 7, 48, 25, 'sway', 'green', 0.36, 10.6, -5.8, null, null, null, 4, 62],
        ['star', 22, 59, 14, 'twinkle', 'gold', 0.35, 6.5, -2.1, null, null, null, 13, 74],
        ['squiggle', 5, 73, 34, 'sway', 'green', 0.4, 11.4, -3.7, null, null, null, 4, 83],
        ['plane', 17, 85, 28, 'glide', 'green', 0.46, 14.2, -6.2, 'bottom', 9, 1.9, 14, 91],

        // Right flank (asymmetric coordinates outside the centered auth column across desktop & mid-width viewports)
        ['ring', 76, 14, 19, 'bob', 'green', 0.42, 8.2, -1.9, 'top', 69, 1.55, 86, 11],
        ['star', 90, 19, 14, 'twinkle', 'gold', 0.36, 7.1, -4.2, 'top', 89, 0.65, 93, 25],
        ['plane', 82, 32, 27, 'glide', 'gold', 0.34, 15.6, -7.5, null, null, null, 85, 38],
        ['dot', 92, 46, 12, 'twinkle', 'green', 0.4, 6.9, -0.9, null, null, null, 94, 52],
        ['plus', 75, 56, 17, 'turn', 'gold', 0.33, 18.0, -8.4, 'bottom', 34, 0.85, 87, 64],
        ['spark', 88, 66, 24, 'twinkle', 'green', 0.45, 7.6, -2.8, null, null, null, 92, 76],
        ['arc', 74, 78, 24, 'sway', 'green', 0.38, 9.8, -5.1, 'bottom', 64, 2.05, 84, 86],
        ['squiggle', 84, 88, 32, 'sway', 'green', 0.42, 12.4, -3.4, 'bottom', 82, 0.95, 91, 92],
    ];
@endphp

<div
    {{ $attributes->class([
        'tala-ambient',
        'tala-ambient--auth' => $isAuthVariant,
        'tala-ambient--stage' => $isStageVariant,
    ]) }}
    data-variant="{{ $variant }}"
    aria-hidden="true"
>
    <div class="tala-ambient-wash"></div>
    <div class="tala-ambient-glow tala-ambient-glow--a"></div>
    <div class="tala-ambient-glow tala-ambient-glow--b"></div>
    <div class="tala-ambient-edge"></div>
    <div class="tala-ambient-field">
        @foreach ($trinkets as [$shape, $x, $y, $sizePx, $motion, $tone, $opacity, $duration, $delay, $band, $mx, $my, $tx, $ty])
            @php
                $isEdge = $band !== null;
                $styleTokens = [
                    "--x: {$x}%",
                    "--y: {$y}%",
                    "--tx: {$tx}%",
                    "--ty: {$ty}%",
                    "--size: {$sizePx}px",
                    "--opacity: {$opacity}",
                    "--trinket-x: {$x}%",
                    "--trinket-y: {$y}%",
                    "--trinket-size: {$sizePx}px",
                    "--trinket-opacity: {$opacity}",
                    "--trinket-duration: {$duration}s",
                    "--trinket-delay: {$delay}s",
                ];

                if ($isEdge) {
                    $styleTokens[] = "--mx: {$mx}%";
                    $styleTokens[] = "--my: {$my}rem";
                    $styleTokens[] = "--trinket-mx: {$mx}%";
                    $styleTokens[] = "--trinket-my: {$my}rem";
                }
            @endphp
            <span
                @class(['tala-ambient-trinket', 'tala-ambient-trinket--edge' => $isEdge])
                data-shape="{{ $shape }}"
                data-motion="{{ $motion }}"
                data-tone="{{ $tone }}"
                @if ($isEdge)
                    data-band="{{ $band }}"
                @endif
                style="{{ implode('; ', $styleTokens) }};"
            >
                {!! $shapes[$shape] !!}
            </span>
        @endforeach
    </div>
</div>
