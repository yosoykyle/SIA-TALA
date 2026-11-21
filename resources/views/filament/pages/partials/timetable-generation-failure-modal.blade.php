<x-filament::modal id="timetable-generation-failure" width="lg" icon="heroicon-o-exclamation-triangle" icon-color="danger">
    <x-slot name="heading">{{ $this->generationFailure['title'] ?? 'Timetable could not be generated' }}</x-slot>

    @if ($this->generationFailure)
        <p>{{ $this->generationFailure['explanation'] }}</p>
        <p class="font-medium">{{ $this->generationFailure['nextStep'] }}</p>

        @if (! empty($this->generationFailure['technical']))
            <details class="tala-planning-coverage mt-4">
                <summary>Technical details</summary>
                <div class="tala-planning-reveal"><p class="tala-work-footnote">{{ $this->generationFailure['technical'] }}</p></div>
            </details>
        @endif
    @endif

    <x-slot name="footerActions">
        @if ($activeRun ?? null)
            <x-filament::button tag="a" color="gray" icon="heroicon-o-clipboard-document-check" :href="\App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource::getUrl('view', ['record' => $activeRun, 'termId' => $term?->id])">Evidence &amp; history</x-filament::button>
        @endif
        <x-filament::button color="primary" x-on:click="$dispatch('close-modal', { id: 'timetable-generation-failure' })">Close</x-filament::button>
    </x-slot>
</x-filament::modal>
