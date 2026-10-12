@props(['label' => null, 'compact' => false])

<x-filament::tabs :label="$label" {{ $attributes->class(['tala-navigation', 'tala-navigation-compact' => $compact]) }}>
    {{ $slot }}
</x-filament::tabs>
