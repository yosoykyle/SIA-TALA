@props([
    'remainingSeconds' => null,
    'totalSeconds' => null,
    'context' => null,
    'showUseAnotherAccount' => false,
    'mood' => 'Hold on a moment',
    'title' => 'Too many attempts',
    'summary' => 'To protect accounts, please wait until the timer finishes before trying again.',
])

@php
    $livewire = isset($this) ? $this : null;
    $remaining = max(0, (int) ($remainingSeconds ?? $livewire?->rateLimitRemainingSeconds ?? 0));
    $total = max(1, $remaining, (int) ($totalSeconds ?? $livewire?->rateLimitTotalSeconds ?? 60));
    $resolvedContext = (string) ($context ?? $livewire?->rateLimitContext ?? 'login');
    $phase = $remaining > 0 && $remaining <= 10 ? 'calm' : 'warm';
    $remainingText = (string) $remaining;
    $firstDigit = strlen($remainingText) > 1 ? substr($remainingText, 0, -1) : $remainingText;
    $secondDigit = strlen($remainingText) > 1 ? substr($remainingText, -1) : '';
    $progressScale = round(max(0, min(1, $remaining / $total)), 4);
    $cssVersion = is_file(public_path('css/tala-auth-countdown.css')) ? filemtime(public_path('css/tala-auth-countdown.css')) : '1';
    $jsVersion = is_file(public_path('js/tala-auth-countdown.js')) ? filemtime(public_path('js/tala-auth-countdown.js')) : '1';
    $scriptUrl = asset('js/tala-auth-countdown.js').'?v='.$jsVersion;
@endphp

<link rel="stylesheet" href="{{ asset('css/tala-auth-countdown.css').'?v='.$cssVersion }}">
<section
    class="tala-auth-countdown tala-auth-verify-card"
    data-tala-auth-countdown
    data-context="{{ $resolvedContext }}"
    data-phase="{{ $phase }}"
    data-remaining-seconds="{{ $remaining }}"
    data-total-seconds="{{ $total }}"
    data-script-url="{{ $scriptUrl }}"
    role="region"
    aria-labelledby="tala-auth-countdown-title"
    aria-describedby="tala-auth-countdown-summary"
    x-data="{
        init() {
            this.$el.__talaWire = this.$wire;
            if (window.talaAuthCountdownMount) {
                window.talaAuthCountdownMount(this.$el, this.$wire);
                return;
            }
            if (!document.querySelector('script[data-tala-auth-countdown-loader]')) {
                const script = document.createElement('script');
                script.src = this.$el.dataset.scriptUrl;
                script.dataset.talaAuthCountdownLoader = 'true';
                document.head.appendChild(script);
            }
        }
    }"
>
    <div class="tala-auth-countdown__body">
        <p class="tala-idle-mood">{{ $mood }}</p>
        <div class="tala-idle-dial" aria-hidden="true">
            <span class="tala-countdown-charm tala-countdown-charm--spark">
                <svg viewBox="0 0 24 24" focusable="false"><path d="M12 1 C 13 8, 16 11, 23 12 C 16 13, 13 16, 12 23 C 11 16, 8 13, 1 12 C 8 11, 11 8, 12 1 Z"/></svg>
            </span>
            <span class="tala-countdown-charm tala-countdown-charm--ring">
                <svg viewBox="0 0 24 24" focusable="false"><circle cx="12" cy="12" r="8.5"/></svg>
            </span>
            <span class="tala-countdown-charm tala-countdown-charm--plus">
                <svg viewBox="0 0 24 24" focusable="false"><path d="M12 4 V 20 M4 12 H 20"/></svg>
            </span>
            <div class="tala-idle-count">
                <div class="tala-idle-numeral"><span>{{ $firstDigit }}</span><span>{{ $secondDigit }}</span></div>
                <span class="tala-idle-unit">seconds</span>
            </div>
            <svg class="tala-idle-plane" viewBox="0 0 220 120" focusable="false">
                <path class="tala-idle-plane-trail" d="M6 96 C 46 108, 74 70, 52 56 C 30 42, 22 78, 62 80 C 104 82, 132 46, 168 34" />
                <path d="M170 22 L 214 8 L 190 46 L 183 33 Z" />
                <path class="tala-idle-plane-fold" d="M183 33 L 214 8 L 176 30 Z" />
            </svg>
            <span class="tala-countdown-charm tala-countdown-charm--dot"></span>
        </div>
        <div class="tala-auth-countdown-progress" aria-hidden="true">
            <div class="tala-auth-countdown-progress-fill" style="transform: scaleX({{ $progressScale }})"></div>
        </div>
        <div class="tala-idle-copy">
            <h2 id="tala-auth-countdown-title">{{ $title }}</h2>
            <p class="tala-idle-summary" id="tala-auth-countdown-summary">{{ $summary }}</p>
            <p class="sr-only" aria-live="polite" data-auth-countdown-live>
                Please wait {{ $remaining }} seconds before trying again.
            </p>
        </div>
    </div>
    @if ($showUseAnotherAccount)
        <div class="tala-auth-verify-card__actions tala-idle-actions">
            <x-filament::button
                type="button"
                color="gray"
                wire:click="useAnotherAccount"
                data-rate-limit-use-another-account
            >
                Use another account
            </x-filament::button>
        </div>
    @endif
</section>
<script src="{{ $scriptUrl }}" data-navigate-once></script>
