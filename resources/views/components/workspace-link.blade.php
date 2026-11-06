@props(['href', 'icon', 'label', 'description' => null])

<a {{ \Filament\Support\generate_href_html($href) }} {{ $attributes->class(['registrar-work-row']) }}>
    <x-filament::icon :icon="$icon" class="registrar-work-icon" />
    <span>
        <strong>{{ $label }}</strong>
        @if ($description)<span class="registrar-work-description">{{ $description }}</span>@endif
        {{ $slot }}
    </span>
    @isset($status){{ $status }}@else<span></span>@endisset
    <x-filament::icon icon="heroicon-m-chevron-right" class="registrar-work-arrow" />
</a>
