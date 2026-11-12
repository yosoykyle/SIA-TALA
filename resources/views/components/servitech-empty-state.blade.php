@props([
    'heading',
    'description' => null,
    'note' => null,
    'illustrated' => false,
    'icon' => 'heroicon-o-document-text',
    'contained' => false,
    'headingTag' => 'h2',
])

<div {{ $attributes->class(['servitech-empty-state', 'servitech-empty-state--illustrated' => $illustrated]) }}>
    <div class="servitech-empty-state__layout">
        <x-filament::empty-state :heading="$heading" :description="$description" :icon="$illustrated ? null : $icon" :compact="! $illustrated" :contained="$contained" :heading-tag="$headingTag">
            @if ($slot->isNotEmpty() || $note)
                <x-slot:footer><div class="servitech-empty-state__footer">@if ($slot->isNotEmpty())<div class="servitech-empty-state__actions">{{ $slot }}</div>@endif @if ($note)<p class="servitech-empty-state__note">{{ $note }}</p>@endif</div></x-slot:footer>
            @endif
        </x-filament::empty-state>
        @if ($illustrated)
            <img class="servitech-empty-state__art" src="{{ asset('images/servitech-empty-state.webp') }}" alt="" width="768" height="768" decoding="async" draggable="false">
        @endif
    </div>
</div>
