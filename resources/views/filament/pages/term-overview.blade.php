@php
    $selectedYear = $selectedAcademicYear ?? null;
    $yearStateColor = $selectedYear ? match ($selectedYear->state) {
        \App\Models\AcademicYear::StateActive => 'success',
        default => 'gray',
    } : 'gray';
    $yearStateLabel = $selectedYear
        ? (\App\Models\AcademicYear::stateOptions()[$selectedYear->state] ?? $selectedYear->state)
        : null;
    $missingBatchList = is_array($missingBatchPrograms ?? null) ? $missingBatchPrograms : [];
    $programsUrl = $programsCurriculaUrl ?? \App\Filament\Pages\CatalogCurriculaWorkbench::getUrl();
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
                    <span class="tala-ay-drilldown-bar__divider" aria-hidden="true"></span>
                    <div class="tala-ay-drilldown-bar__identity">
                        <div class="tala-ay-drilldown-bar__title-row">
                            <h2>{{ $selectedYear->label }}</h2>
                            <x-filament::badge :color="$yearStateColor" size="sm">{{ $yearStateLabel }}</x-filament::badge>
                        </div>
                        @if ($selectedYear->starts_on && $selectedYear->ends_on)
                            <p class="tala-ay-drilldown-bar__meta">
                                <time datetime="{{ $selectedYear->starts_on->toDateString() }}">{{ $selectedYear->starts_on->format('M j, Y') }}</time>
                                <span aria-hidden="true">–</span>
                                <time datetime="{{ $selectedYear->ends_on->toDateString() }}">{{ $selectedYear->ends_on->format('M j, Y') }}</time>
                            </p>
                        @endif
                    </div>
                </div>
                <div class="tala-ay-drilldown-bar__actions">
                    <span class="tala-ay-drilldown-bar__stat tabular-nums">
                        <strong>{{ (int) ($selectedYear->terms_count ?? 0) }}</strong>
                        <span>{{ \Illuminate\Support\Str::plural('term', (int) ($selectedYear->terms_count ?? 0)) }}</span>
                    </span>
                    @if ($this->editSelectedAcademicYearAction->isVisible())
                        {{ $this->editSelectedAcademicYearAction }}
                    @endif
                </div>
            </div>

            @if ($missingBatchList !== [])
                @php
                    $visibleMissingPrograms = array_slice($missingBatchList, 0, 5);
                    $remainingMissingCount = max(0, count($missingBatchList) - count($visibleMissingPrograms));
                @endphp
                <div class="tala-inline-prereq" aria-label="Setup readiness">
                    <div class="tala-inline-prereq__body">
                        <p class="tala-inline-prereq__title">
                            <x-filament::icon icon="heroicon-o-exclamation-triangle" aria-hidden="true" />
                            <span>Setup readiness: {{ count($missingBatchList) }} {{ \Illuminate\Support\Str::plural('active program', count($missingBatchList)) }} missing a curriculum batch for {{ $selectedYear->label }}</span>
                        </p>
                        <div class="tala-missing-batch-chips" aria-label="Programs missing curriculum batch">
                            @foreach ($visibleMissingPrograms as $missingProgram)
                                <span class="tala-missing-batch-chip">
                                    <strong>{{ $missingProgram['code'] }}</strong>
                                    <span>{{ ! $missingProgram['has_active_curriculum'] ? 'No active curriculum' : 'No batch assigned' }}</span>
                                </span>
                            @endforeach
                            @if ($remainingMissingCount > 0)
                                <span class="tala-missing-batch-chip is-more">+{{ $remainingMissingCount }} more</span>
                            @endif
                        </div>
                        <p class="tala-inline-prereq__text">
                            Assign a curriculum batch so incoming student sections and admissions are ready.
                        </p>
                    </div>
                    <div class="tala-inline-prereq__actions">
                        @if ($this->assignAcademicYearBatchAction->isVisible())
                            {{ $this->assignAcademicYearBatchAction }}
                        @endif
                        <x-filament::button
                            tag="a"
                            :href="$programsUrl"
                            color="gray"
                            size="sm"
                            icon="heroicon-o-academic-cap"
                        >
                            Programs &amp; Curricula
                        </x-filament::button>
                    </div>
                </div>
            @endif
        @else
            <div class="tala-overview-note" role="note" aria-label="Academic years overview">
                <x-filament::icon icon="heroicon-o-information-circle" class="tala-overview-note__icon" aria-hidden="true" />
                <p>Select an academic year to manage its terms, calendars, sections &amp; classes, faculty &amp; rooms, and timetable. Activating a draft year's first term activates that academic year.</p>
            </div>
        @endif

        {{ $this->table }}
    </section>

    <x-filament-actions::modals />
</x-filament-panels::page>
