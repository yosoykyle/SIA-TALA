@props(['variant' => 'bar'])

@php($now = now()->timezone('Asia/Manila'))

<div
    {{ $attributes->class(['tala-clock', 'tala-clock--'.$variant]) }}
    x-data="{
        now: new Date(),
        date: { weekday: 'short', month: 'short', day: 'numeric', {{ $variant === 'compact' ? '' : "year: 'numeric'," }} timeZone: 'Asia/Manila' },
        time: { hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Manila' },
        format(options) { return new Intl.DateTimeFormat('en-US', options).format(this.now) },
        timer: null,
        init() { this.timer = setInterval(() => this.now = new Date(), 15000) },
        destroy() { clearInterval(this.timer) },
    }"
    data-tala-clock
>
    <span class="tala-clock__part">
        <x-filament::icon icon="heroicon-o-calendar-days" class="tala-clock__icon" />
        <time datetime="{{ $now->toDateString() }}" x-bind:datetime="now.toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' })" x-text="format(date)">{{ $now->format($variant === 'compact' ? 'D, M j' : 'D, M j, Y') }}</time>
    </span>
    <span class="tala-clock__part">
        <x-filament::icon icon="heroicon-o-clock" class="tala-clock__icon" />
        <time datetime="{{ $now->toIso8601String() }}" x-bind:datetime="now.toISOString()" x-text="format(time)">{{ $now->format('g:i A') }}</time>
        <span class="tala-clock__zone">Asia/Manila</span>
    </span>
</div>
