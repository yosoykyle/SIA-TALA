@props([
    'currentOwner',
    'currentTask',
    'nextOwner',
    'nextTask',
    'currentIcon' => 'heroicon-o-clipboard-document-list',
    'nextIcon' => 'heroicon-o-arrow-right-circle',
])
<ol {{ $attributes->class(['tala-workflow-handoff']) }} aria-label="Current responsibility and next step">
    <li class="tala-handoff-current" aria-current="step">
        <x-filament::icon :icon="$currentIcon" class="tala-handoff-icon" aria-hidden="true" />
        <div><span class="tala-handoff-phase">Now</span><strong>{{ $currentOwner }}</strong><p>{{ $currentTask }}</p></div>
    </li>
    <li class="tala-handoff-next">
        <x-filament::icon icon="heroicon-m-arrow-right" class="tala-handoff-arrow" aria-hidden="true" />
        <x-filament::icon :icon="$nextIcon" class="tala-handoff-icon" aria-hidden="true" />
        <div><span class="tala-handoff-phase">Next</span><strong>{{ $nextOwner }}</strong><p>{{ $nextTask }}</p></div>
    </li>
</ol>
