@props(['value', 'label'])

<span {{ $attributes->class(['tala-workspace-figure']) }}>
    <strong>{{ $value }}</strong>
    <span>{{ $label }}</span>
</span>
