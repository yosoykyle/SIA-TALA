@props([
    'variant' => 'warning',
    'title' => null,
    'icon' => null,
    'role' => 'status',
])

@php
    $resolvedIcon = $icon ?? match ($variant) {
        'danger' => 'heroicon-o-exclamation-circle',
        'info' => 'heroicon-o-information-circle',
        'success' => 'heroicon-o-check-circle',
        default => 'heroicon-o-exclamation-triangle',
    };
@endphp

<div {{ $attributes->class(['tala-access-alert', "tala-access-alert--{$variant}"]) }} role="{{ $role }}">
    <x-filament::icon :icon="$resolvedIcon" class="tala-access-alert__icon" aria-hidden="true" />
    <div class="tala-access-alert__content">
        @if (filled($title))
            <p class="tala-access-alert__title">{{ $title }}</p>
        @endif
        <div class="tala-access-alert__body">{{ $slot }}</div>
    </div>
</div>
