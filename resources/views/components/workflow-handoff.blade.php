@props([
    'currentOwner',
    'currentTask',
    'nextOwner' => null,
    'nextTask' => null,
    'currentIcon' => 'heroicon-o-clipboard-document-list',
    'nextIcon' => 'heroicon-o-arrow-right-circle',
    'currentArea' => null,
    'nextArea' => null,
    'currentUrl' => null,
    'nextUrl' => null,
    'currentLinkLabel' => null,
    'nextLinkLabel' => null,
])
<ol {{ $attributes->class(['tala-workflow-handoff']) }} aria-label="Current responsibility and next step">
    <li class="tala-handoff-current" aria-current="step">
        <x-filament::icon :icon="$currentIcon" class="tala-handoff-icon" aria-hidden="true" />
        <div>
            <span class="tala-handoff-phase">{{ $currentArea ? 'Now · '.$currentOwner : 'Now' }}</span>
            <strong>{{ $currentArea ?? $currentOwner }}</strong>
            <p>{{ $currentTask }}</p>
            @if ($currentUrl && $currentLinkLabel)
                <x-filament::button color="gray" size="sm" tag="a" :href="$currentUrl">{{ $currentLinkLabel }}</x-filament::button>
            @endif
        </div>
    </li>
    @if ($nextOwner && $nextTask)
    <li class="tala-handoff-next">
        <x-filament::icon icon="heroicon-m-arrow-right" class="tala-handoff-arrow" aria-hidden="true" />
        <x-filament::icon :icon="$nextIcon" class="tala-handoff-icon" aria-hidden="true" />
        <div>
            <span class="tala-handoff-phase">{{ $nextArea ? 'Next · '.$nextOwner : 'Next' }}</span>
            <strong>{{ $nextArea ?? $nextOwner }}</strong>
            <p>{{ $nextTask }}</p>
            @if ($nextUrl && $nextLinkLabel)
                <x-filament::button color="gray" size="sm" tag="a" :href="$nextUrl">{{ $nextLinkLabel }}</x-filament::button>
            @endif
        </div>
    </li>
    @endif
</ol>
