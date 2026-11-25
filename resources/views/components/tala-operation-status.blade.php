@props([
    'run',
    'inCanvas' => false,
])

@php
    $queued = $run->status === \App\Models\ScheduleGenerationRun::StatusQueued;
    $diagnostics = is_array($run->diagnostics) ? $run->diagnostics : [];
    $dispatch = is_array($diagnostics['solver_dispatch'] ?? null) ? $diagnostics['solver_dispatch'] : [];
    $dispatchFailure = is_array($dispatch['failure'] ?? null) ? $dispatch['failure'] : [];
    $hasRetryableFailure = $dispatchFailure !== [] && (($dispatchFailure['final'] ?? null) === false);
    $hasFinalFailure = $run->status === \App\Models\ScheduleGenerationRun::StatusFailed
        || ($dispatchFailure !== [] && (($dispatchFailure['final'] ?? null) === true));
    $isValidating = ! $queued && ! $hasFinalFailure && (
        isset($diagnostics['solver_result'])
        || isset($diagnostics['current_revalidation'])
        || in_array(strtolower((string) ($dispatch['stage'] ?? $dispatch['phase'] ?? '')), ['validating', 'ingesting'], true)
    );

    $startedAt = $run->created_at ?? now();
    $startedAtIso = $startedAt->toIso8601String();
    $initialElapsed = max(0, (int) $startedAt->diffInSeconds(now(), false));
    $initialMins = str_pad((string) intdiv($initialElapsed, 60), 2, '0', STR_PAD_LEFT);
    $initialSecs = str_pad((string) ($initialElapsed % 60), 2, '0', STR_PAD_LEFT);

    $isDelayed = $queued && ($hasRetryableFailure || $initialElapsed >= 60);

    $serverStage = match (true) {
        $isValidating => 'validating',
        ! $queued => 'optimizing',
        $isDelayed => 'queued',
        $initialElapsed < 4 => 'saved',
        default => 'queued',
    };

    $solverHealth = match (true) {
        $hasFinalFailure => 'error',
        $isDelayed => 'stalled',
        default => 'normal',
    };

    $solverPhase = in_array($serverStage, ['saved', 'queued'], true) ? 'queued' : 'solving';

    $kickerStatus = match (true) {
        $solverHealth === 'error' => 'Generation stopped',
        $solverHealth === 'stalled' && $hasRetryableFailure => 'Retrying connection',
        $solverHealth === 'stalled' => 'Delayed',
        $serverStage === 'saved' => 'Request saved',
        $serverStage === 'queued' => 'Waiting in line',
        $serverStage === 'validating' => 'Checking conflicts',
        default => 'Building schedule',
    };

    $headingText = match (true) {
        $solverHealth === 'error' => 'Timetable could not be generated',
        $solverHealth === 'stalled' => 'Taking longer than expected',
        $serverStage === 'validating' => 'Checking schedule for conflicts',
        in_array($serverStage, ['saved', 'queued'], true) => 'Preparing your timetable',
        default => 'Building your whole-term timetable',
    };

    $summaryText = match (true) {
        $solverHealth === 'error' => 'The scheduling service could not complete this request. Your term data is intact.',
        $solverHealth === 'stalled' && $hasRetryableFailure => 'The scheduling service was briefly unavailable and TALA is retrying automatically. You can wait or cancel anytime.',
        $solverHealth === 'stalled' => 'The scheduling service is busy. Your request is still saved in line and will start as soon as capacity opens.',
        $serverStage === 'validating' => 'Running final checks on rooms, teacher loads, and student section overlaps.',
        in_array($serverStage, ['saved', 'queued'], true) => 'Your request is saved and will start automatically as soon as the scheduling service is ready.',
        default => 'Placing classes into conflict-free times, qualified teachers, and valid rooms across the term.',
    };

    $initialMood = match (true) {
        $solverHealth === 'error' => 'Waiting for review before starting a new attempt.',
        $solverHealth === 'stalled' => 'Still waiting for the scheduling service. Retrying automatically…',
        $serverStage === 'saved' => 'Saving your term classes, rooms, and teacher rules…',
        $serverStage === 'queued' => 'Waiting for the scheduling service to begin…',
        $serverStage === 'validating' => 'Running final conflict and room-capacity checks…',
        default => 'Checking room capacities and lab types…',
    };

    $progressValueText = match (true) {
        $solverHealth === 'error' => 'Timetable generation stopped',
        $solverHealth === 'stalled' => 'Waiting in line, taking longer than expected',
        $serverStage === 'saved' => 'Request saved',
        $serverStage === 'queued' => 'Waiting in line',
        $serverStage === 'validating' => 'Checking schedule for conflicts',
        default => 'Building an optimized whole-term schedule',
    };

    $auroraModifier = match (true) {
        $solverHealth === 'error' => 'tala-aurora-canvas--error',
        $solverHealth === 'stalled' => 'tala-aurora-canvas--stalled',
        $serverStage === 'saved' => 'tala-aurora-canvas--saved',
        $serverStage === 'queued' => 'tala-aurora-canvas--queued',
        $serverStage === 'validating' => 'tala-aurora-canvas--validating',
        default => 'tala-aurora-canvas--optimizing',
    };

    $barFillState = match (true) {
        $solverHealth === 'error' => 'is-error',
        $solverHealth === 'stalled' => 'is-stalled',
        $serverStage === 'saved' => 'is-saved',
        $serverStage === 'queued' => 'is-queued',
        $serverStage === 'validating' => 'is-validating',
        default => 'is-solving',
    };

    $stageOrder = ['saved' => 1, 'queued' => 2, 'optimizing' => 3, 'validating' => 4];
    $currentStepIndex = $stageOrder[$serverStage] ?? 2;

    $solverJsPath = public_path('js/tala-solver-stage.js');
    $solverJsUrl = asset('js/tala-solver-stage.js').'?v='.(is_file($solverJsPath) ? filemtime($solverJsPath) : '1');
@endphp

<div
    {{ $attributes->class([
        'tala-operation-status',
        'is-in-canvas' => $inCanvas,
    ]) }}
    data-tala-solver-stage
    data-run-id="{{ $run->id }}"
    data-started-at="{{ $startedAtIso }}"
    data-solver-phase="{{ $solverPhase }}"
    data-solver-stage="{{ $serverStage }}"
    data-server-stage="{{ $serverStage }}"
    data-solver-health="{{ $solverHealth }}"
    data-server-health="{{ $solverHealth }}"
    data-has-retryable-failure="{{ $hasRetryableFailure ? '1' : '0' }}"
    data-script-url="{{ $solverJsUrl }}"
    x-data="{
        init() {
            if (window.talaSolverStageMount) {
                window.talaSolverStageMount(this.$el);
                return;
            }
            if (!document.querySelector('script[data-tala-solver-stage-loader]')) {
                const script = document.createElement('script');
                script.src = this.$el.dataset.scriptUrl;
                script.dataset.talaSolverStageLoader = 'true';
                script.onload = () => window.talaSolverStageMount && window.talaSolverStageMount(this.$el);
                document.head.appendChild(script);
            }
        }
    }"
>
    <div class="tala-aurora-canvas {{ $auroraModifier }}" data-solver-aurora aria-hidden="true" wire:ignore>
        <span class="tala-aurora-blob tala-aurora-blob--green"></span>
        <span class="tala-aurora-blob tala-aurora-blob--gold"></span>
        <span class="tala-aurora-blob tala-aurora-blob--sage"></span>
    </div>
    @if ($solverHealth !== 'error')
        <x-tala-ambient-backdrop variant="stage" class="tala-ambient--trinkets-only" wire:ignore />
    @endif

    <div class="tala-solver-stage">
        <div class="tala-solver-meta-row">
            <p class="tala-solver-kicker">
                <span class="tala-solver-pulse-dot" aria-hidden="true"></span>
                <span>Attempt #{{ $run->id }}</span>
                <span aria-hidden="true">·</span>
                <span data-solver-kicker-label>{{ $kickerStatus }}</span>
            </p>
            <div class="tala-solver-elapsed tala-operation-status__timer" aria-label="Elapsed time" wire:ignore>
                <span class="tala-solver-elapsed__label">Elapsed</span>
                <span class="tala-solver-elapsed__clock" data-solver-numeral>
                    <span data-solver-mins>{{ $initialMins }}</span>:<span data-solver-secs>{{ $initialSecs }}</span>
                </span>
            </div>
        </div>

        <div class="tala-solver-copy">
            <div class="tala-operation-status__lead" role="status" aria-live="polite">
                <h2 data-solver-heading>{{ $headingText }}</h2>
                <p class="tala-solver-summary" data-solver-summary>{{ $summaryText }}</p>
            </div>
            <p class="tala-solver-mood" data-solver-mood aria-hidden="true" wire:ignore>{{ $initialMood }}</p>
        </div>

        <div class="tala-solver-matrix" aria-hidden="true" wire:key="tala-solver-matrix-{{ $run->id }}" wire:ignore>
            <div class="tala-solver-matrix__days">
                <span>Mon</span>
                <span>Tue</span>
                <span>Wed</span>
                <span>Thu</span>
                <span>Fri</span>
                <span>Sat</span>
            </div>
            <div class="tala-solver-matrix__grid">
                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-green is-locked" data-matrix-block="0" data-matrix-col="0"></span></span>
                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-gold" data-matrix-block="1" data-matrix-col="1"></span></span>
                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-green" data-matrix-block="2" data-matrix-col="2"></span></span>
                <span class="tala-solver-matrix__cell"></span>
                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-sage" data-matrix-block="3" data-matrix-col="4"></span></span>
                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-gold" data-matrix-block="4" data-matrix-col="5"></span></span>

                <span class="tala-solver-matrix__cell"></span>
                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-green is-locked" data-matrix-block="5" data-matrix-col="1"></span></span>
                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-sage" data-matrix-block="6" data-matrix-col="2"></span></span>
                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-gold" data-matrix-block="7" data-matrix-col="3"></span></span>
                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-green" data-matrix-block="8" data-matrix-col="4"></span></span>
                <span class="tala-solver-matrix__cell"></span>

                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-gold" data-matrix-block="9" data-matrix-col="0"></span></span>
                <span class="tala-solver-matrix__cell"></span>
                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-green is-locked" data-matrix-block="10" data-matrix-col="2"></span></span>
                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-sage" data-matrix-block="11" data-matrix-col="3"></span></span>
                <span class="tala-solver-matrix__cell"></span>
                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-green" data-matrix-block="12" data-matrix-col="5"></span></span>
            </div>
        </div>

        <div class="tala-solver-surface-card">
            <div
                class="tala-operation-status__bar"
                role="progressbar"
                aria-label="Timetable generation progress"
                aria-valuetext="{{ $progressValueText }}"
                data-solver-progressbar
            >
                <div class="tala-operation-status__bar-fill {{ $barFillState }}" data-solver-bar-fill></div>
            </div>

            <ol class="tala-operation-status__stages" aria-label="Generation stages">
                <li
                    data-stage-step="saved"
                    @class([
                        'is-complete' => $currentStepIndex > 1,
                        'is-active' => $currentStepIndex === 1,
                    ])
                    @if ($currentStepIndex === 1) aria-current="step" @endif
                >
                    <span class="tala-operation-status__step-node" aria-hidden="true">
                        @if ($currentStepIndex > 1)
                            <x-filament::icon icon="heroicon-m-check" class="h-3.5 w-3.5" />
                        @else
                            1
                        @endif
                    </span>
                    <div class="tala-operation-status__step-label">Request saved <span class="sr-only">({{ $currentStepIndex > 1 ? 'Completed' : 'In progress' }})</span></div>
                </li>
                <li
                    data-stage-step="queued"
                    @class([
                        'is-complete' => $currentStepIndex > 2,
                        'is-active' => $currentStepIndex === 2,
                        'is-stalled' => $currentStepIndex === 2 && $solverHealth === 'stalled',
                    ])
                    @if ($currentStepIndex === 2) aria-current="step" @endif
                >
                    <span class="tala-operation-status__step-node" aria-hidden="true">
                        @if ($currentStepIndex > 2)
                            <x-filament::icon icon="heroicon-m-check" class="h-3.5 w-3.5" />
                        @else
                            2
                        @endif
                    </span>
                    <div class="tala-operation-status__step-label">Queued @if ($currentStepIndex === 2)<span class="sr-only">({{ $solverHealth === 'stalled' ? 'Delayed' : 'In progress' }})</span>@elseif ($currentStepIndex > 2)<span class="sr-only">(Completed)</span>@endif</div>
                </li>
                <li
                    data-stage-step="optimizing"
                    @class([
                        'is-complete' => $currentStepIndex > 3,
                        'is-active' => $currentStepIndex === 3,
                    ])
                    @if ($currentStepIndex === 3) aria-current="step" @endif
                >
                    <span class="tala-operation-status__step-node" aria-hidden="true">
                        @if ($currentStepIndex > 3)
                            <x-filament::icon icon="heroicon-m-check" class="h-3.5 w-3.5" />
                        @else
                            3
                        @endif
                    </span>
                    <div class="tala-operation-status__step-label">Optimizing schedule @if ($currentStepIndex === 3)<span class="sr-only">(In progress)</span>@elseif ($currentStepIndex > 3)<span class="sr-only">(Completed)</span>@endif</div>
                </li>
                <li
                    data-stage-step="validating"
                    @class([
                        'is-active' => $currentStepIndex === 4,
                    ])
                    @if ($currentStepIndex === 4) aria-current="step" @endif
                >
                    <span class="tala-operation-status__step-node" aria-hidden="true">4</span>
                    <div class="tala-operation-status__step-label">Independent validation @if ($currentStepIndex === 4)<span class="sr-only">(In progress)</span>@endif</div>
                </li>
            </ol>

            <div class="tala-operation-status__footer">
                <p class="tala-work-footnote">
                    Refreshes automatically every 5 seconds. You can leave this page and return; any current official timetable stays active.
                </p>
                @if (isset($actions) && trim((string) $actions) !== '')
                    <div class="tala-operation-status__actions">
                        {{ $actions }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
<script src="{{ $solverJsUrl }}" data-navigate-once></script>
