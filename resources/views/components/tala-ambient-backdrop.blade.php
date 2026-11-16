@php
    // Decorative ambient geometry aligned with the inactivity countdown and error recovery screens.
    $shapes = [
        'spark' => '<svg viewBox="0 0 24 24" focusable="false"><path d="M12 1 C 13 8, 16 11, 23 12 C 16 13, 13 16, 12 23 C 11 16, 8 13, 1 12 C 8 11, 11 8, 12 1 Z"/></svg>',
        'ring' => '<svg viewBox="0 0 24 24" focusable="false"><circle class="tala-ambient-line" cx="12" cy="12" r="9"/></svg>',
        'plus' => '<svg viewBox="0 0 24 24" focusable="false"><path class="tala-ambient-line" d="M12 3 V 21 M3 12 H 21"/></svg>',
        'squiggle' => '<svg viewBox="0 0 64 20" focusable="false"><path class="tala-ambient-line" d="M2 10 C 8 0, 14 20, 20 10 S 32 0, 38 10 S 50 20, 62 10"/></svg>',
        'plane' => '<svg viewBox="0 0 48 44" focusable="false"><path d="M2 16 L 46 2 L 22 40 L 15 27 Z"/></svg>',
        'dot' => '<span class="tala-ambient-dot"></span>',
    ];

    // [shape, left%, top%, widthRem, motion, tone, durationSec, delaySec, keepCompact]
    $trinkets = [
        ['spark', 7, 15, 1.4, 'twinkle', 'green', 6, -1.1, true],
        ['ring', 16, 36, 1.15, 'bob', 'blue', 8, -2.5, false],
        ['plus', 25, 18, 0.95, 'turn', 'green', 26, -4.0, false],
        ['squiggle', 6, 76, 3.1, 'sway', 'green', 9, -1.8, false],
        ['plane', 19, 85, 1.8, 'glide', 'blue', 14, -3.2, true],
        ['dot', 27, 62, 0.5, 'bob', 'green', 7, -0.9, false],
        ['spark', 11, 55, 0.9, 'twinkle', 'blue', 5, -2.0, false],
        ['dot', 22, 11, 0.4, 'twinkle', 'green', 6, -3.0, false],
        ['spark', 90, 20, 1.3, 'twinkle', 'green', 7, -0.8, true],
        ['ring', 80, 38, 1.45, 'bob', 'blue', 10, -4.2, false],
        ['plus', 73, 14, 1.05, 'turn', 'blue', 30, -6.5, false],
        ['squiggle', 84, 71, 2.95, 'sway', 'green', 11, -2.7, false],
        ['plane', 76, 84, 1.6, 'glide', 'green', 15, -5.0, true],
        ['dot', 70, 64, 0.5, 'bob', 'blue', 8, -1.5, false],
        ['spark', 92, 53, 0.85, 'twinkle', 'blue', 5, -3.3, false],
        ['dot', 85, 89, 0.4, 'twinkle', 'green', 6, -1.2, false],
    ];
@endphp

<div class="tala-ambient" aria-hidden="true">
    <div class="tala-ambient-glow tala-ambient-glow--a"></div>
    <div class="tala-ambient-glow tala-ambient-glow--b"></div>
    <div class="tala-ambient-edge"></div>
    <div class="tala-ambient-field">
        @foreach ($trinkets as [$shape, $x, $y, $size, $motion, $tone, $duration, $delay, $keepCompact])
            <span
                @class(['tala-ambient-trinket', 'tala-ambient-trinket--edge' => $keepCompact])
                data-motion="{{ $motion }}"
                data-tone="{{ $tone }}"
                style="--trinket-x: {{ $x }}%; --trinket-y: {{ $y }}%; --trinket-size: {{ $size }}rem; --trinket-duration: {{ $duration }}s; --trinket-delay: {{ $delay }}s;"
            >
                {!! $shapes[$shape] !!}
            </span>
        @endforeach
    </div>
</div>
