@php
    $requiredNumber = (int) ($step['required_step_number'] ?? 1);
    $requiredLabel = (string) ($step['required_step_label'] ?? '1. Calendar');
    $requiredUrl = (string) ($step['required_step_url'] ?? \App\Filament\Pages\TermCalendar::getUrl(['termId' => $term->id]));
    $lockedReason = (string) ($step['locked_reason'] ?? 'Complete the previous step first before continuing.');
    $currentStepNumber = (int) ($step['number'] ?? 0);
    $currentStepLabel = (string) ($step['label'] ?? 'This step');
@endphp

<section class="tala-task-surface tala-step-locked" aria-labelledby="step-locked-heading">
    <div class="tala-step-locked__icon" aria-hidden="true">
        <x-filament::icon icon="heroicon-o-lock-closed" />
    </div>
    <div class="tala-step-locked__body">
        @if ($currentStepNumber > 0)
            <p class="tala-step-locked__kicker">Step {{ $currentStepNumber }} locked · {{ $currentStepLabel }}</p>
        @endif
        <h2 id="step-locked-heading" class="tala-step-locked__heading">Complete Step {{ $requiredNumber }} first</h2>
        <p class="tala-step-locked__reason">{{ $lockedReason }}</p>
        <div class="tala-step-locked__actions">
            <x-filament::button
                tag="a"
                :href="$requiredUrl"
                color="primary"
                size="sm"
                icon="heroicon-m-arrow-right"
                icon-position="after"
            >
                Go to {{ $requiredLabel }}
            </x-filament::button>
        </div>
    </div>
</section>
