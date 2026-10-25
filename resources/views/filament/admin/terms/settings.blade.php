<div class="tala-term-settings">
    <section class="tala-task-surface tala-term-settings-summary" aria-labelledby="term-identity-heading">
        <header class="tala-calendar-sheet-heading">
            <h2 id="term-identity-heading"><x-filament::icon icon="heroicon-o-calendar-days" />{{ $term->typeLabel() }}</h2>
            <x-filament::badge :color="$term->state === \App\Models\Term::StateActive ? 'success' : 'gray'"><span class="sr-only">Term status: </span>{{ \App\Models\Term::stateOptions()[$term->state] ?? $term->state }}</x-filament::badge>
        </header>
        <dl class="tala-term-settings-identity">
            <div><dt>Term name</dt><dd>{{ $term->label }}</dd></div>
            <div><dt>Academic year</dt><dd>{{ $term->academicYear?->label ?? 'Not recorded' }}@if ($term->academicYear)<small>Academic-year status: {{ $term->academicYear->stateLabel() }}</small>@endif</dd></div>
        </dl>
        <h3 class="tala-term-settings-period">Term dates</h3>
        <div class="tala-calendar-date-range tala-term-settings-dates">
            <dl><dt>Starts</dt><dd><time datetime="{{ $term->starts_on?->toDateString() }}">{{ $term->starts_on?->format('M j, Y') ?? 'Not recorded' }}</time></dd></dl>
            <span class="tala-calendar-date-connector"><span class="sr-only">to</span><x-filament::icon icon="heroicon-o-arrow-long-right" /></span>
            <dl><dt>Ends</dt><dd><time datetime="{{ $term->ends_on?->toDateString() }}">{{ $term->ends_on?->format('M j, Y') ?? 'Not recorded' }}</time></dd></dl>
        </div>
        <dl class="tala-term-settings-load"><dt><x-filament::icon icon="heroicon-o-academic-cap" />Faculty teaching-load default</dt><dd>{{ $term->default_max_units !== null ? number_format((float) $term->default_max_units, 2).' units' : 'Not recorded' }}</dd></dl>
    </section>
    <section class="tala-task-surface tala-calendar-sheet" aria-labelledby="term-active-calendar-heading">
        <header class="tala-calendar-sheet-heading">
            <h2 id="term-active-calendar-heading"><x-filament::icon icon="heroicon-o-calendar-days" />Active teaching calendar</h2>
            @if ($activeCalendar)<p>Version {{ $activeCalendar->version }}</p>@endif
        </header>
        @if ($activeCalendar)
            <div class="tala-term-settings-class-dates">
                <span>Class dates</span>
                <strong><time datetime="{{ $activeCalendar->classes_start_on?->toDateString() }}">{{ $activeCalendar->classes_start_on?->format('M j, Y') ?? 'Start date missing' }}</time> <x-filament::icon icon="heroicon-o-arrow-long-right" /><time datetime="{{ $activeCalendar->classes_end_on?->toDateString() }}">{{ $activeCalendar->classes_end_on?->format('M j, Y') ?? 'End date missing' }}</time></strong>
            </div>
            @include('filament.pages.partials.term-teaching-week')
            <p class="tala-work-footnote tala-term-settings-timezone">Times use Asia/Manila.</p>
        @else
            <p class="tala-term-settings-calendar-empty">Awaiting core calendar activation</p>
        @endif
    </section>
</div>
