@php
    /** @var \App\Models\Program $record */
    $record = $getRecord();
    /** @var \App\Filament\Pages\CatalogCurriculaWorkbench $livewire */
    $livewire = $getLivewire();
    $card = $livewire->cardDataFor($record);
@endphp

<article
    @class([
        'tala-task-surface',
        'tala-program-card',
        'tala-program-card--inactive' => ! $card['is_active'],
    ])
>
    <div class="tala-program-card__body">
        <div class="tala-program-card__head">
            <div class="tala-program-card__meta">
                <x-filament::badge color="gray" size="sm" class="font-mono">
                    {{ $card['code'] }}
                </x-filament::badge>
                <span class="tala-program-card__duration tabular-nums">
                    {{ $card['duration_years'] }} {{ \Illuminate\Support\Str::plural('year', $card['duration_years']) }}
                </span>
            </div>

            <x-filament::badge
                :color="$card['is_active'] ? 'success' : 'gray'"
                size="sm"
                class="shrink-0"
            >
                {{ $card['is_active'] ? 'Active' : 'Inactive' }}
            </x-filament::badge>
        </div>

        <h2 class="tala-program-card__title">
            <a href="{{ $card['details_url'] }}">
                {{ $card['name'] }}
            </a>
        </h2>

        <p class="tala-program-card__meta-line tabular-nums">
            <span>{{ $card['running_years_label'] }}</span>
            <span aria-hidden="true">·</span>
            <span>{{ number_format($card['enrolled_count']) }} enrolled</span>
        </p>

        @if ($card['availability']['available'])
            <p class="tala-program-card__status tala-program-card__status--available">
                <x-filament::icon icon="heroicon-m-check-circle" aria-hidden="true" />
                <span>
                    Available in Admissions
                    @if ($card['availability']['batch_label'])
                        · {{ $card['availability']['batch_label'] }}
                    @endif
                </span>
            </p>
        @elseif ($card['is_active'])
            <p class="tala-program-card__status tala-program-card__status--warning">
                <x-filament::icon icon="heroicon-m-exclamation-triangle" aria-hidden="true" />
                <span>{{ $card['availability']['reason'] }}</span>
            </p>
        @else
            <p class="tala-program-card__status tala-program-card__status--inactive">
                <x-filament::icon icon="heroicon-m-no-symbol" aria-hidden="true" />
                <span>
                    {{ $card['availability']['reason'] }}
                    @if ($card['inactive_note'])
                        · {{ $card['inactive_note'] }}
                    @endif
                </span>
            </p>
        @endif
    </div>

    <footer class="tala-program-card__footer">
        <a href="{{ $card['curricula_url'] }}" class="tala-program-card__curricula-link">
            <span class="tabular-nums">
                {{ $card['total_curricula_count'] }} {{ $card['total_curricula_count'] === 1 ? 'curriculum' : 'curricula' }}
            </span>
            @if ($card['draft_curricula_count'] > 0)
                <x-filament::badge color="warning" size="sm" class="tabular-nums">
                    {{ $card['draft_curricula_count'] }} draft
                </x-filament::badge>
            @endif
        </a>

        <x-filament::button
            tag="a"
            :href="$card['details_url']"
            color="gray"
            size="sm"
            icon="heroicon-o-arrow-right"
            icon-position="after"
        >
            Details
        </x-filament::button>
    </footer>
</article>
