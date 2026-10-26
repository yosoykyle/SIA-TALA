@if ($term)
    <section class="tala-task-surface tala-term-edit-context" aria-labelledby="term-edit-context-heading">
        <header class="tala-calendar-sheet-heading">
            <h2 id="term-edit-context-heading"><x-filament::icon icon="heroicon-o-calendar-days" />{{ $term->typeLabel() }}</h2>
            <x-filament::badge :color="$term->state === \App\Models\Term::StateActive ? 'success' : 'gray'">{{ \App\Models\Term::stateOptions()[$term->state] ?? $term->state }}</x-filament::badge>
        </header>
        <p class="tala-term-edit-dates"><time datetime="{{ $term->starts_on?->toDateString() }}">{{ $term->starts_on?->format('M j, Y') ?? 'Not recorded' }}</time><x-filament::icon icon="heroicon-o-arrow-long-right" /><time datetime="{{ $term->ends_on?->toDateString() }}">{{ $term->ends_on?->format('M j, Y') ?? 'Not recorded' }}</time></p>
        <p class="tala-term-edit-protection"><x-filament::icon icon="heroicon-o-lock-closed" />Term identity and dates are protected after calendar activation.</p>
        <details class="tala-planning-program-source">
            <summary>Protected Term identity</summary>
            <dl class="tala-planning-reveal tala-term-history-facts">
                <div><dt>Term name</dt><dd>{{ $term->label }}</dd></div>
                <div><dt>Academic year</dt><dd>{{ $term->academicYear?->label ?? 'Not recorded' }}@if ($term->academicYear)<small>Academic-year status: {{ $term->academicYear->stateLabel() }}</small>@endif</dd></div>
            </dl>
        </details>
    </section>
@endif
