@props([
    'run',
    'inCanvas' => false,
])

@php
    $queued = $run->status === \App\Models\ScheduleGenerationRun::StatusQueued;
    $startedAt = $run->created_at ?? now();
    $startedAtIso = $startedAt->toIso8601String();
    $initialElapsed = max(0, (int) $startedAt->diffInSeconds(now(), false));
    $initialMins = str_pad((string) intdiv($initialElapsed, 60), 2, '0', STR_PAD_LEFT);
    $initialSecs = str_pad((string) ($initialElapsed % 60), 2, '0', STR_PAD_LEFT);
    $initialMood = $queued
        ? 'Queued for the constraint solver…'
        : 'Checking room capacities & lab types…';
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
    data-solver-phase="{{ $queued ? 'queued' : 'solving' }}"
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
    <div class="tala-aurora-canvas {{ $queued ? 'tala-aurora-canvas--calm' : 'tala-aurora-canvas--active' }}" aria-hidden="true" wire:ignore>
        <span class="tala-aurora-blob tala-aurora-blob--green"></span>
        <span class="tala-aurora-blob tala-aurora-blob--gold"></span>
        <span class="tala-aurora-blob tala-aurora-blob--sage"></span>
    </div>
    <x-tala-ambient-backdrop variant="stage" class="tala-ambient--trinkets-only" wire:ignore />

    <div class="tala-solver-stage">
        <div class="tala-solver-meta-row">
            <p class="tala-solver-kicker">
                <span class="tala-solver-pulse-dot" aria-hidden="true"></span>
                <span>Attempt #{{ $run->id }}</span>
                <span aria-hidden="true">·</span>
                <span>{{ $queued ? 'Queued in worker' : 'Constraint solver active' }}</span>
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
                <h2>{{ $queued ? 'Waiting for the timetable worker' : 'Building your whole-term timetable' }}</h2>
                <p class="tala-solver-summary">{{ $queued ? 'Your whole-term scheduling request is saved and waiting for an available solver worker.' : 'Placing classes into conflict-free times, qualified teachers, and valid rooms across the term.' }}</p>
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
                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-green is-locked" data-matrix-block="0"></span></span>
                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-gold" data-matrix-block="1"></span></span>
                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-green" data-matrix-block="2"></span></span>
                <span class="tala-solver-matrix__cell"></span>
                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-sage" data-matrix-block="3"></span></span>
                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-gold" data-matrix-block="4"></span></span>

                <span class="tala-solver-matrix__cell"></span>
                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-green is-locked" data-matrix-block="5"></span></span>
                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-sage" data-matrix-block="6"></span></span>
                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-gold" data-matrix-block="7"></span></span>
                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-green" data-matrix-block="8"></span></span>
                <span class="tala-solver-matrix__cell"></span>

                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-gold" data-matrix-block="9"></span></span>
                <span class="tala-solver-matrix__cell"></span>
                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-green is-locked" data-matrix-block="10"></span></span>
                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-sage" data-matrix-block="11"></span></span>
                <span class="tala-solver-matrix__cell"></span>
                <span class="tala-solver-matrix__cell"><span class="tala-solver-matrix__block is-green" data-matrix-block="12"></span></span>
            </div>
        </div>

        <div class="tala-solver-surface-card">
            <div
                class="tala-operation-status__bar"
                role="progressbar"
                aria-label="Timetable generation progress"
                aria-valuetext="{{ $queued ? 'Queued' : 'Building an optimized whole-term schedule' }}"
            >
                <div class="tala-operation-status__bar-fill {{ $queued ? 'is-queued' : 'is-solving' }}"></div>
            </div>

            <ol class="tala-operation-status__stages" aria-label="Generation stages">
                <li class="is-complete">
                    <span class="tala-operation-status__step-node" aria-hidden="true"><x-filament::icon icon="heroicon-m-check" class="h-3.5 w-3.5" /></span>
                    <div class="tala-operation-status__step-label">Request saved <span class="sr-only">(Completed)</span></div>
                </li>
                <li class="{{ $queued ? 'is-active' : 'is-complete' }}" @if ($queued) aria-current="step" @endif>
                    @if ($queued)
                        <span class="tala-operation-status__step-node" aria-hidden="true">2</span>
                        <div class="tala-operation-status__step-label">Queued <span class="sr-only">(In progress)</span></div>
                    @else
                        <span class="tala-operation-status__step-node" aria-hidden="true"><x-filament::icon icon="heroicon-m-check" class="h-3.5 w-3.5" /></span>
                        <div class="tala-operation-status__step-label">Queued <span class="sr-only">(Completed)</span></div>
                    @endif
                </li>
                <li class="{{ $queued ? '' : 'is-active' }}" @if (! $queued) aria-current="step" @endif>
                    <span class="tala-operation-status__step-node" aria-hidden="true">3</span>
                    <div class="tala-operation-status__step-label">Optimizing schedule @if (! $queued)<span class="sr-only">(In progress)</span>@endif</div>
                </li>
                <li>
                    <span class="tala-operation-status__step-node" aria-hidden="true">4</span>
                    <div class="tala-operation-status__step-label">Independent validation</div>
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
