@php
    $modalReadiness = $generationReadiness ?? $readiness ?? (method_exists($this, 'generationReadiness') ? $this->generationReadiness() : []);
    $modalBlockers = is_array($modalReadiness['blockers'] ?? null) ? $modalReadiness['blockers'] : [];
    $blockingRunId = is_array($this->generationFailure ?? null) && isset($this->generationFailure['blockingRunId'])
        ? (int) $this->generationFailure['blockingRunId']
        : null;
    $showTechnicalRef = $blockingRunId === null && ! empty($this->generationFailure['technical']);
@endphp

<x-filament::modal id="timetable-generation-failure" width="lg" :icon="$blockingRunId !== null ? 'heroicon-o-document-duplicate' : 'heroicon-o-exclamation-triangle'" :icon-color="$blockingRunId !== null ? 'warning' : 'danger'" teleport="body" footer-actions-alignment="end">
    <x-slot name="heading">{{ $this->generationFailure['title'] ?? 'Timetable could not be generated' }}</x-slot>

    @if ($this->generationFailure)
        <div class="grid gap-3">
            <p class="text-sm leading-relaxed text-[var(--tala-text)]">{{ $this->generationFailure['explanation'] }}</p>

            @if ($blockingRunId !== null)
                <div class="tala-studio-alert-card is-warning flex items-start gap-3 p-3.5">
                    <span class="tala-studio-alert-card__icon shrink-0" aria-hidden="true">
                        <x-filament::icon icon="heroicon-o-document-duplicate" />
                    </span>
                    <div class="tala-studio-alert-card__copy min-w-0">
                        <p class="tala-studio-alert-card__title">Attempt #{{ $blockingRunId }} is currently open as your active draft</p>
                        <p class="tala-studio-alert-card__text mt-0.5">{{ $this->generationFailure['nextStep'] }}</p>
                    </div>
                </div>
            @else
                <p class="text-sm font-medium leading-relaxed text-[var(--tala-text)]">{{ $this->generationFailure['nextStep'] }}</p>
            @endif

            @if ($modalBlockers !== [])
                <div class="tala-failure-recovery">
                    <p class="text-sm font-semibold">Resolve blocking prerequisites:</p>
                    <ul class="tala-guide-history mt-2" aria-label="Blocking prerequisites">
                        @foreach ($modalBlockers as $blocker)
                            @php
                                $blockerStepLabel = match ($blocker['label'] ?? '') {
                                    'Term Calendar', 'Term calendar' => 'Calendar',
                                    'Classes', 'Classes & sections' => 'Classes & sections',
                                    'Resources', 'Faculty & rooms' => 'Faculty & rooms',
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

            @if ($showTechnicalRef)
                <p class="tala-error-stage__ref justify-start">
                    <span class="tala-error-stage__ref-label">Error reference</span>
                    <code class="tala-error-stage__ref-code">{{ $this->generationFailure['technical'] }}</code>
                </p>
            @endif
        </div>
    @endif

    <x-slot name="footerActions">
        @if ($blockingRunId !== null)
            <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'timetable-generation-failure' })">Close</x-filament::button>
            <x-filament::button
                color="danger"
                outlined
                icon="heroicon-o-arrow-path"
                wire:click="discardBlockingDraftAndGenerate({{ $blockingRunId }})"
                wire:loading.attr="disabled"
            >
                Discard &amp; generate new
            </x-filament::button>
            <x-filament::button
                color="primary"
                icon="heroicon-o-eye"
                wire:click="openBlockingDraft({{ $blockingRunId }})"
            >
                Open Attempt #{{ $blockingRunId }}
            </x-filament::button>
        @else
            @if ($activeRun ?? null)
                <x-filament::button tag="a" color="gray" icon="heroicon-o-arrow-top-right-on-square" :href="\App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource::getUrl('view', ['record' => $activeRun, 'termId' => $term?->id])">Open solver report</x-filament::button>
            @endif
            <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'timetable-generation-failure' })">Close</x-filament::button>
        @endif
    </x-slot>
</x-filament::modal>
