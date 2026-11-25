@php
    $selectedYear = $selectedAcademicYear ?? null;
    $yearStateColor = $selectedYear ? match ($selectedYear->state) {
        \App\Models\AcademicYear::StateActive => 'success',
        default => 'gray',
    } : 'gray';
    $yearStateLabel = $selectedYear
        ? (\App\Models\AcademicYear::stateOptions()[$selectedYear->state] ?? $selectedYear->state)
        : null;
@endphp
<x-filament-panels::page class="tala-term-workspace">
    @include('filament.pages.partials.term-workspace-header')

    <section class="tala-task-work tala-workspace-overview" aria-label="{{ $selectedYear ? $selectedYear->label.' terms' : 'Academic years directory' }}">
        @if ($selectedYear)
            <div class="tala-ay-drilldown-bar" role="region" aria-label="Selected academic year">
                <div class="tala-ay-drilldown-bar__main">
                    <x-filament::button
                        tag="a"
                        :href="\App\Filament\Pages\TermOverview::getUrl()"
                        wire:click.prevent="backToAcademicYears"
                        color="gray"
                        size="sm"
                        icon="heroicon-o-arrow-left"
                    >
                        Academic years
                    </x-filament::button>
                    <div class="tala-ay-drilldown-bar__identity">
                        <div class="tala-ay-drilldown-bar__title-row">
                            <h2>{{ $selectedYear->label }}</h2>
                            <x-filament::badge :color="$yearStateColor" size="sm">{{ $yearStateLabel }}</x-filament::badge>
                        </div>
                        <p class="tala-ay-drilldown-bar__meta">
                            @if ($selectedYear->starts_on && $selectedYear->ends_on)
                                <time datetime="{{ $selectedYear->starts_on->toDateString() }}">{{ $selectedYear->starts_on->format('M j, Y') }}</time>
                                <span aria-hidden="true">–</span>
                                <time datetime="{{ $selectedYear->ends_on->toDateString() }}">{{ $selectedYear->ends_on->format('M j, Y') }}</time>
                                <span aria-hidden="true">·</span>
                            @endif
                            <span>{{ (int) ($selectedYear->terms_count ?? 0) }} {{ \Illuminate\Support\Str::plural('term', (int) ($selectedYear->terms_count ?? 0)) }}</span>
                        </p>
                    </div>
                </div>
                @if ($this->editSelectedAcademicYearAction->isVisible())
                    <div class="tala-ay-drilldown-bar__actions">
                        {{ $this->editSelectedAcademicYearAction }}
                    </div>
                @endif
            </div>
        @else
            <div class="tala-overview-note" role="note" aria-label="Academic years overview">
                <x-filament::icon icon="heroicon-o-information-circle" class="tala-overview-note__icon" aria-hidden="true" />
                <p>Select an academic year to manage its terms, calendars, classes, faculty &amp; rooms, and timetable. A draft academic year activates automatically when its first term activates.</p>
            </div>
        @endif

        {{ $this->table }}
    </section>

    <x-filament-actions::modals />
</x-filament-panels::page>
