@if (! $term)
    <x-servitech-term-gate
        :heading="$emptyHeading ?? 'Choose a Term'"
        :description="$terms->isEmpty() ? 'No Term is available. Contact the Registrar.' : ($emptyDescription ?? 'Select an Academic Year and Term to continue.')"
        heading-id="faculty-term-chooser-heading"
    >
        @if ($terms->isNotEmpty())
            <div class="tala-term-picker">@include('filament.components.faculty-term-select')</div>
        @endif
    </x-servitech-term-gate>
@else
    <x-record-scope-bar label="Selected Term" class="tala-faculty-scope-bar">
        <div class="tala-faculty-scope-bar__row">
            <div class="tala-term-picker">@include('filament.components.faculty-term-select')</div>
            @if (filled($scopePill ?? null))
                <div class="tala-faculty-scope-bar__pill" role="status">
                    <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4" aria-hidden="true" />
                    <span class="tabular-nums">{{ $scopePill }}</span>
                </div>
            @endif
        </div>
    </x-record-scope-bar>
@endif
