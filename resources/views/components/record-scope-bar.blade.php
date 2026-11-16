@props([
    'label' => 'Active scope',
    'icon' => 'heroicon-o-funnel',
    'scopeTitle' => null,
    'scopeMeta' => null,
    'scopeSummary' => null,
    'urgentNotice' => null,
    'urgentHref' => null,
    'urgentActionLabel' => null,
])
@php
    $resolvedTitle = filled($scopeTitle) ? $scopeTitle : $scopeSummary;
    $hasControls = trim((string) $slot) !== '' || (isset($controls) && trim((string) $controls) !== '');
    $hasSummary = ! $hasControls && (filled($resolvedTitle) || filled($scopeMeta));
    $hasHeader = $hasSummary || (isset($actions) && trim((string) $actions) !== '');
@endphp
<section {{ $attributes->class(['tala-record-scope-bar', 'tala-record-scope-bar--with-header' => $hasHeader]) }} aria-label="{{ $label }}">
    @if ($hasHeader)
        <div class="tala-record-scope-bar__header">
            @if ($hasSummary)
                <div class="tala-record-scope-bar__summary">
                    <span class="tala-record-scope-bar__icon-wrap" aria-hidden="true">
                        <x-filament::icon :icon="$icon" class="tala-record-scope-bar__icon" />
                    </span>
                    <div class="tala-record-scope-bar__heading">
                        @if (filled($resolvedTitle))
                            <strong class="tala-record-scope-bar__title">{{ $resolvedTitle }}</strong>
                        @endif
                        @if (filled($scopeMeta))
                            <span class="tala-record-scope-bar__meta">{{ $scopeMeta }}</span>
                        @endif
                    </div>
                </div>
            @endif

            @if (isset($actions) && trim((string) $actions) !== '')
                <div class="tala-record-scope-bar__actions">
                    {{ $actions }}
                </div>
            @endif
        </div>
    @endif

    @if ($hasControls)
        <div class="tala-record-scope-bar__body">
            @if (isset($controls) && trim((string) $controls) !== '')
                <div class="tala-record-scope-bar__controls">
                    {{ $controls }}
                </div>
            @endif
            @if (trim((string) $slot) !== '')
                <div class="tala-record-scope-bar__controls">
                    {{ $slot }}
                </div>
            @endif
        </div>
    @endif

    @if ($hasControls && filled($scopeMeta))
        <p class="tala-record-scope-bar__context">{{ $scopeMeta }}</p>
    @endif

    @if (filled($urgentNotice) || (isset($notice) && trim((string) $notice) !== ''))
        <div class="tala-record-scope-bar__notice" role="status">
            @if (filled($urgentNotice))
                <span class="tala-record-scope-bar__notice-text">
                    <x-filament::icon icon="heroicon-o-exclamation-circle" aria-hidden="true" />
                    <span>{{ $urgentNotice }}</span>
                </span>
                @if (filled($urgentHref) && filled($urgentActionLabel))
                    <x-filament::link :href="$urgentHref">{{ $urgentActionLabel }}</x-filament::link>
                @endif
            @endif
            @if (isset($notice) && trim((string) $notice) !== '')
                <div class="tala-record-scope-bar__notice-actions">
                    {{ $notice }}
                </div>
            @endif
        </div>
    @endif
</section>
