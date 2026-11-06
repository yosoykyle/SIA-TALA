@props(['label' => 'Loading content'])
<div {{ $attributes->class(['tala-content-progress'])->merge(['style' => 'display:none']) }} role="status">
    <span class="fi-sr-only">{{ $label }}</span>
</div>
