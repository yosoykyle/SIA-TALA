@php
    $modalReadiness = $generationReadiness ?? $readiness ?? (method_exists($this, 'generationReadiness') ? $this->generationReadiness() : []);
    $modalBlockers = is_array($modalReadiness['blockers'] ?? null) ? $modalReadiness['blockers'] : [];
@endphp

<x-filament::modal id="timetable-generation-failure" width="lg" icon="heroicon-o-exclamation-triangle" icon-color="danger" teleport="body" footer-actions-alignment="end">
    <x-slot name="heading">{{ $this->generationFailure['title'] ?? 'Timetable could not be generated' }}</x-slot>

    @if ($this->generationFailure)
        <p>{{ $this->generationFailure['explanation'] }}</p>
        <p class="font-medium">{{ $this->generationFailure['nextStep'] }}</p>

        @if ($modalBlockers !== [])
            <div class="tala-failure-recovery mt-4">
                <p class="text-sm font-semibold">Resolve blocking prerequisites:</p>
                <ul class="tala-guide-history mt-2" aria-label="Blocking prerequisites">
                    @foreach ($modalBlockers as $blocker)
                        @php
                            $blockerStepLabel = match ($blocker['label'] ?? '') {
                                'Term Calendar' => 'Calendar',
                                'Classes' => 'Classes & sections',
                                'Resources' => 'Faculty & rooms',
                                'Timetable' => 'Timetable',
                                default => (string) ($blocker['label'] ?? 'Review prerequisite'),
                            };
                        @endphp
                        <li>
                            <span>{{ $blocker['reason'] }}</span>
                            <x-filament::button
                                tag="a"
                                size="sm"
                                :color="$loop->first ? 'primary' : 'gray'"
                                :href="$blocker['url']"
                            >
                                {{ $blockerStepLabel }}
                            </x-filament::button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (! empty($this->generationFailure['technical']))
            <details class="tala-technical-details mt-4">
                <summary class="tala-technical-details__label cursor-pointer">Technical details</summary>
                <p class="tala-work-footnote font-mono text-xs mt-1">{{ $this->generationFailure['technical'] }}</p>
            </details>
        @endif
    @endif

    <x-slot name="footerActions">
        @if ($activeRun ?? null)
            <x-filament::button tag="a" color="gray" icon="heroicon-o-arrow-top-right-on-square" :href="\App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource::getUrl('view', ['record' => $activeRun, 'termId' => $term?->id])">Open solver report</x-filament::button>
        @endif
        <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'timetable-generation-failure' })">Close</x-filament::button>
    </x-slot>
</x-filament::modal>
