@props(['href', 'icon', 'label', 'description', 'note' => null, 'compact' => false, 'featured' => false, 'action' => 'Open', 'actionColor' => 'primary', 'figure' => null, 'figureLabel' => null, 'headingLevel' => 2])

@if ($compact)
    <a {{ \Filament\Support\generate_href_html($href) }} {{ $attributes->class(['tala-workspace-card', 'tala-workspace-card--compact']) }}>
        <span class="tala-workspace-card__icon"><x-filament::icon :icon="$icon" /></span>
        <span class="tala-workspace-card__content">
            <span class="tala-workspace-card__title">{{ $label }}</span>
            <span class="tala-workspace-card__description">{{ $description }}</span>
            @if ($note)<span class="tala-workspace-card__note">{{ $note }}</span>@endif
        </span>
        <x-filament::icon icon="heroicon-m-chevron-right" class="tala-workspace-card__arrow" />
    </a>
@else
    <section {{ $attributes->class(['tala-task-surface', 'tala-workspace-feature', 'tala-workspace-feature--neutral' => ! $featured]) }}>
        <div class="tala-workspace-feature__body">
            <span class="tala-workspace-card__icon"><x-filament::icon :icon="$icon" /></span>
            @if ($headingLevel === 3)<h3>{{ $label }}</h3>@else<h2>{{ $label }}</h2>@endif
            @if ($figure !== null)<x-workspace-figure :value="$figure" :label="$figureLabel" />@endif
            <p>{{ $description }}</p>
            @if ($note)<p class="tala-workspace-card__note">{{ $note }}</p>@endif
            {{ $slot }}
        </div>
        <div class="tala-workspace-feature__action">
            <x-filament::button tag="a" :href="$href" :color="$actionColor" icon="heroicon-m-arrow-right" icon-position="after">{{ $action }}</x-filament::button>
        </div>
    </section>
@endif
