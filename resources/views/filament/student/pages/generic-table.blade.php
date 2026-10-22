<x-filament-panels::page>
    @assets<link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">@endassets
    @if ($this->getTableRecords()->isEmpty())
        <section class="student-read-empty">
            <h2>{{ $this->getTable()->getEmptyStateHeading() }}</h2>
            <p>{{ $this->getTable()->getEmptyStateDescription() }}</p>
            @if ($this->getTable()->getDescription())<p>{{ $this->getTable()->getDescription() }}</p>@endif
            <div class="student-finance-actions">@foreach ($this->getTable()->getEmptyStateActions() as $action){{ $action }}@endforeach</div>
        </section>
    @else
        {{ $this->table }}
    @endif
</x-filament-panels::page>
