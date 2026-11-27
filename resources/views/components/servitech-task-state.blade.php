@props([
    'heading',
    'description',
    'scope' => null,
    'office' => null,
    'icon' => 'heroicon-o-information-circle',
    'attention' => false,
    'blocked' => false,
    'eyebrow' => null,
])
@php
    $statusEyebrow = $eyebrow ?? match (true) {
        $blocked => 'Action blocked',
        $attention => 'Needs attention',
        default => null,
    };
@endphp
<section {{ $attributes->class([
    'servitech-task-state',
    'servitech-task-state--attention' => $attention && ! $blocked,
    'servitech-task-state--blocked' => $blocked,
]) }}>
    <span class="servitech-task-state__icon"><x-filament::icon :icon="$icon" aria-hidden="true" /></span>
    <div class="servitech-task-state__body">
        <div class="servitech-task-state__header">
            @if (filled($statusEyebrow))
                <span class="servitech-task-state__pill">{{ $statusEyebrow }}</span>
            @endif
            <h2>{{ $heading }}</h2>
        </div>
        @if ($scope)<p class="servitech-task-state__scope">{{ $scope }}</p>@endif
        <p>{{ $description }}</p>
        @if ($office)<p class="servitech-task-state__office">Responsible office: <strong>{{ $office }}</strong></p>@endif
        @if ($slot->isNotEmpty())<div class="servitech-task-state__actions">{{ $slot }}</div>@endif
    </div>
</section>
