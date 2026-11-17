@props(['label' => null, 'compact' => false, 'stretch' => false])

<x-filament::tabs :label="$label" {{ $attributes->class(['tala-navigation', 'tala-navigation-compact' => $compact, 'tala-navigation-stretch' => $stretch]) }}>
    {{ $slot }}
</x-filament::tabs>
