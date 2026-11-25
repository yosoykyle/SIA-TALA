/*
 * Living Servitech Timetable Generation Stage controller.
 *
 * - Ticks the tabular MM:SS elapsed timer cleanly every second.
 * - Transitions stage (saved -> queued -> optimizing -> validating) and health
 *   (normal -> stalled -> error) seamlessly without page reloads.
 * - Cycles plain-English scheduling status phrases during optimization.
 * - Drives distinct Mon-Sat matrix choreography per stage and health state.
 * - Tunes flanking ambient trinket pacing via Web Animations API.
 * - Respects prefers-reduced-motion: reduce and survives Livewire wire:poll.5s morphs.
 */
(() => {
    const STAGE_ORDER = {
        saved: 1,
        queued: 2,
        optimizing: 3,
        validating: 4,
    };

    const OPTIMIZING_MOODS = [
        'Checking room capacities and lab types…',
        'Balancing teacher workloads and hours…',
        'Minimizing student idle gaps…',
        'Verifying zero teacher and room overlaps…',
    ];

    const STAGE_MOODS = {
        saved: 'Saving your term classes, rooms, and teacher rules…',
        queued: 'Waiting for the scheduling service to begin…',
        stalled: 'Still waiting for the scheduling service. Retrying automatically…',
        validating: 'Running final conflict and room-capacity checks…',
        error: 'Waiting for review before starting a new attempt.',
    };

    const AURORA_MODIFIERS = [
        'tala-aurora-canvas--calm',
        'tala-aurora-canvas--active',
        'tala-aurora-canvas--saved',
        'tala-aurora-canvas--queued',
        'tala-aurora-canvas--optimizing',
        'tala-aurora-canvas--validating',
        'tala-aurora-canvas--stalled',
        'tala-aurora-canvas--error',
    ];

    const BAR_MODIFIERS = ['is-saved', 'is-queued', 'is-solving', 'is-validating', 'is-stalled', 'is-error'];

    const CHECK_ICON_SVG =
        '<svg class="fi-icon h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/></svg>';

    const reducedMotionMedia = window.matchMedia('(prefers-reduced-motion: reduce)');
    const calmMotion = () => !reducedMotionMedia.matches;
    const mountedStages = new Set();

    const trinketMotions = {
        bob: [{ translate: '0 0' }, { translate: '0 -0.55rem' }, { translate: '0 0' }],
        sway: [{ rotate: '-7deg' }, { rotate: '7deg' }, { rotate: '-7deg' }],
        twinkle: [
            { opacity: 0.35, scale: 0.86 },
            { opacity: 0.9, scale: 1.12 },
            { opacity: 0.35, scale: 0.86 },
        ],
        turn: [{ rotate: '0deg' }, { rotate: '360deg' }],
        glide: [
            { translate: '0 0', rotate: '-5deg' },
            { translate: '1.2rem -0.95rem', rotate: '7deg' },
            { translate: '0 0', rotate: '-5deg' },
        ],
    };

    function parseSecondsVar(styleText, name, fallback) {
        const match = styleText.match(new RegExp(name + '\\s*:\\s*(-?[\\d.]+)s'));
        return match ? Number(match[1]) : fallback;
    }

    function formatClock(totalSeconds) {
        const safe = Math.max(0, Math.floor(totalSeconds));
        const mins = String(Math.floor(safe / 60)).padStart(2, '0');
        const secs = String(safe % 60).padStart(2, '0');
        return { mins, secs };
    }

    function computeStageState(element, state) {
        const elapsedSec = Math.max(0, (Date.now() - state.startedAtMs) / 1000);
        const serverStage = element.dataset.serverStage || 'optimizing';
        const serverHealth = element.dataset.serverHealth || 'normal';
        const hasRetryableFailure = element.dataset.hasRetryableFailure === '1';

        if (serverHealth === 'error') {
            return { stage: serverStage, health: 'error', hasRetryableFailure, elapsedSec };
        }

        if (serverStage === 'validating') {
            return { stage: 'validating', health: 'normal', hasRetryableFailure, elapsedSec };
        }

        if (serverStage === 'optimizing') {
            return { stage: 'optimizing', health: 'normal', hasRetryableFailure, elapsedSec };
        }

        if (serverHealth === 'stalled' || hasRetryableFailure || elapsedSec >= 60) {
            return { stage: 'queued', health: 'stalled', hasRetryableFailure, elapsedSec };
        }

        if (elapsedSec < 4) {
            return { stage: 'saved', health: 'normal', hasRetryableFailure, elapsedSec };
        }

        return { stage: 'queued', health: 'normal', hasRetryableFailure, elapsedSec };
    }

    function trinketRateFor(stage, health) {
        if (health === 'error') {
            return 0;
        }
        if (health === 'stalled') {
            return 0.3;
        }
        switch (stage) {
            case 'saved':
                return 0.6;
            case 'queued':
                return 0.55;
            case 'validating':
                return 0.85;
            default:
                return 1.25;
        }
    }

    function updateClock(element, state) {
        const minsEl = element.querySelector('[data-solver-mins]');
        const secsEl = element.querySelector('[data-solver-secs]');
        if (!minsEl || !secsEl) {
            return;
        }
        const elapsedSec = (Date.now() - state.startedAtMs) / 1000;
        const { mins, secs } = formatClock(elapsedSec);
        if (minsEl.textContent !== mins) {
            minsEl.textContent = mins;
        }
        if (secsEl.textContent !== secs) {
            secsEl.textContent = secs;
        }
    }

    function applyTrinketPacing(element, state) {
        const rate = trinketRateFor(state.stage, state.health);
        if (rate === 0) {
            stopTrinketMotion(element, state);
            return;
        }
        if (!state.trinketAnimations.length) {
            startTrinketMotion(element, state);
            return;
        }
        state.trinketAnimations.forEach((anim) => {
            try {
                anim.playbackRate = rate;
            } catch (e) {
                /* ignore detached */
            }
        });
    }

    function startTrinketMotion(element, state) {
        if (!calmMotion() || state.trinketAnimations.length || state.health === 'error') {
            return;
        }
        const field = element.querySelector('.tala-ambient-field');
        if (!field || !field.children.length) {
            return;
        }
        element.dataset.solverWaa = 'active';
        const speedMultiplier = trinketRateFor(state.stage, state.health) || 0.55;

        state.trinketAnimations = [...field.children]
            .map((holder, index) => {
                const target = holder.firstElementChild;
                if (!target || typeof target.animate !== 'function') {
                    return null;
                }
                const motionKey = holder.dataset.motion || 'bob';
                const keyframes = trinketMotions[motionKey] || trinketMotions.bob;
                const inlineStyle = holder.getAttribute('style') || '';
                const durationSec = parseSecondsVar(inlineStyle, '--trinket-duration', 7.5 + (index % 5) * 1.3);
                const delaySec = parseSecondsVar(inlineStyle, '--trinket-delay', -index * 0.43);

                const anim = target.animate(keyframes, {
                    duration: Math.max(2200, durationSec * 1000),
                    iterations: Infinity,
                    easing: motionKey === 'turn' ? 'linear' : 'ease-in-out',
                    delay: delaySec * 1000,
                });
                anim.playbackRate = speedMultiplier;
                return anim;
            })
            .filter(Boolean);
    }

    function stopTrinketMotion(element, state) {
        (state.trinketAnimations || []).forEach((anim) => {
            try {
                anim.cancel();
            } catch (e) {
                /* ignore detached */
            }
        });
        state.trinketAnimations = [];
        delete element.dataset.solverWaa;
    }

    function syncVisualState(element, state, force = false) {
        const computed = computeStageState(element, state);
        const changed =
            force ||
            computed.stage !== state.stage ||
            computed.health !== state.health ||
            computed.hasRetryableFailure !== state.hasRetryableFailure;

        if (!changed) {
            return;
        }

        const prevStage = state.stage;
        const prevHealth = state.health;
        state.stage = computed.stage;
        state.health = computed.health;
        state.hasRetryableFailure = computed.hasRetryableFailure;
        state.phase = computed.stage === 'saved' || computed.stage === 'queued' ? 'queued' : 'solving';

        element.dataset.solverStage = state.stage;
        element.dataset.solverHealth = state.health;
        element.dataset.solverPhase = state.phase;

        const aurora = element.querySelector('[data-solver-aurora]');
        if (aurora) {
            aurora.classList.remove(...AURORA_MODIFIERS);
            if (state.health === 'error') {
                aurora.classList.add('tala-aurora-canvas--error');
            } else if (state.health === 'stalled') {
                aurora.classList.add('tala-aurora-canvas--stalled');
            } else {
                aurora.classList.add(`tala-aurora-canvas--${state.stage}`);
            }
        }

        const barFill = element.querySelector('[data-solver-bar-fill]');
        if (barFill) {
            barFill.classList.remove(...BAR_MODIFIERS);
            const barClass =
                state.health === 'error'
                    ? 'is-error'
                    : state.health === 'stalled'
                      ? 'is-stalled'
                      : state.stage === 'optimizing'
                        ? 'is-solving'
                        : `is-${state.stage}`;
            barFill.classList.add(barClass);
        }

        const progressbar = element.querySelector('[data-solver-progressbar]');
        if (progressbar) {
            const valueText =
                state.health === 'error'
                    ? 'Timetable generation stopped'
                    : state.health === 'stalled'
                      ? 'Waiting in line, taking longer than expected'
                      : state.stage === 'saved'
                        ? 'Request saved'
                        : state.stage === 'queued'
                          ? 'Waiting in line'
                          : state.stage === 'validating'
                            ? 'Checking schedule for conflicts'
                            : 'Building an optimized whole-term schedule';
            progressbar.setAttribute('aria-valuetext', valueText);
        }

        const kickerLabel = element.querySelector('[data-solver-kicker-label]');
        if (kickerLabel) {
            kickerLabel.textContent =
                state.health === 'error'
                    ? 'Generation stopped'
                    : state.health === 'stalled'
                      ? state.hasRetryableFailure
                          ? 'Retrying connection'
                          : 'Delayed'
                      : state.stage === 'saved'
                        ? 'Request saved'
                        : state.stage === 'queued'
                          ? 'Waiting in line'
                          : state.stage === 'validating'
                            ? 'Checking conflicts'
                            : 'Building schedule';
        }

        const headingEl = element.querySelector('[data-solver-heading]');
        const summaryEl = element.querySelector('[data-solver-summary]');
        if (headingEl && summaryEl) {
            if (state.health === 'error') {
                headingEl.textContent = 'Timetable could not be generated';
                summaryEl.textContent =
                    'The scheduling service could not complete this request. Your term data is intact.';
            } else if (state.health === 'stalled') {
                headingEl.textContent = 'Taking longer than expected';
                summaryEl.textContent = state.hasRetryableFailure
                    ? 'The scheduling service was briefly unavailable and TALA is retrying automatically. You can wait or cancel anytime.'
                    : 'The scheduling service is busy. Your request is still saved in line and will start as soon as capacity opens.';
            } else if (state.stage === 'validating') {
                headingEl.textContent = 'Checking schedule for conflicts';
                summaryEl.textContent =
                    'Running final checks on rooms, teacher loads, and student section overlaps.';
            } else if (state.stage === 'optimizing') {
                headingEl.textContent = 'Building your whole-term timetable';
                summaryEl.textContent =
                    'Placing classes into conflict-free times, qualified teachers, and valid rooms across the term.';
            } else {
                headingEl.textContent = 'Preparing your timetable';
                summaryEl.textContent =
                    'Your request is saved and will start automatically as soon as the scheduling service is ready.';
            }
        }

        const currentIndex = STAGE_ORDER[state.stage] || 2;
        element.querySelectorAll('[data-stage-step]').forEach((item) => {
            const stepKey = item.dataset.stageStep || '';
            const stepIndex = STAGE_ORDER[stepKey] || 0;
            const isComplete = stepIndex > 0 && stepIndex < currentIndex;
            const isActive = stepIndex === currentIndex;
            const isStalled = isActive && state.health === 'stalled';

            item.classList.toggle('is-complete', isComplete);
            item.classList.toggle('is-active', isActive);
            item.classList.toggle('is-stalled', isStalled);

            if (isActive) {
                item.setAttribute('aria-current', 'step');
            } else {
                item.removeAttribute('aria-current');
            }

            const node = item.querySelector('.tala-operation-status__step-node');
            if (node) {
                if (isComplete) {
                    if (!node.querySelector('svg')) {
                        node.innerHTML = CHECK_ICON_SVG;
                    }
                } else if (node.textContent.trim() !== String(stepIndex)) {
                    node.textContent = String(stepIndex);
                }
            }
        });

        applyTrinketPacing(element, state);

        if (prevStage !== state.stage || prevHealth !== state.health || force) {
            state.matrixStep = 0;
            updateMood(element, state, force);
            advanceMatrix(element, state);
        }
    }

    function advanceMatrix(element, state) {
        const blocks = [...element.querySelectorAll('[data-matrix-block]')];
        if (!blocks.length) {
            return;
        }

        if (!calmMotion() || state.health === 'error' || state.health === 'stalled') {
            blocks.forEach((block) => {
                block.classList.remove('is-evaluating', 'is-verified');
            });
            return;
        }

        if (state.stage === 'saved' || state.stage === 'queued') {
            blocks.forEach((block) => {
                block.classList.remove('is-evaluating', 'is-verified');
            });
            return;
        }

        if (state.stage === 'validating') {
            const scanCol = state.matrixStep % 6;
            state.matrixStep += 1;
            blocks.forEach((block) => {
                const col = Number(block.dataset.matrixCol ?? 0);
                block.classList.toggle('is-evaluating', col === scanCol);
                block.classList.toggle('is-verified', col <= scanCol);
                block.classList.toggle('is-locked', col <= scanCol);
            });
            return;
        }

        // Optimizing stage: energetic dual-slot evaluation & locking
        const primaryIndex = state.matrixStep % blocks.length;
        const secondaryIndex = (state.matrixStep + 5) % blocks.length;
        const settledIndex = (state.matrixStep + 8) % blocks.length;
        state.matrixStep += 1;

        blocks.forEach((block, index) => {
            block.classList.remove('is-verified');
            block.classList.toggle('is-evaluating', index === primaryIndex || index === secondaryIndex);
            if (index === settledIndex) {
                block.classList.toggle('is-locked');
            }
        });
    }

    function currentMoodText(state) {
        if (state.health === 'error') {
            return STAGE_MOODS.error;
        }
        if (state.health === 'stalled') {
            return STAGE_MOODS.stalled;
        }
        if (state.stage === 'optimizing') {
            return OPTIMIZING_MOODS[state.moodIndex % OPTIMIZING_MOODS.length];
        }
        return STAGE_MOODS[state.stage] || STAGE_MOODS.queued;
    }

    function updateMood(element, state, immediate = false) {
        const moodEl = element.querySelector('[data-solver-mood]');
        if (!moodEl) {
            return;
        }
        const targetText = currentMoodText(state);

        if (moodEl.textContent === targetText) {
            return;
        }

        if (immediate || !calmMotion()) {
            moodEl.classList.remove('is-fading');
            moodEl.textContent = targetText;
            return;
        }

        moodEl.classList.add('is-fading');
        window.setTimeout(() => {
            if (!element.isConnected) {
                return;
            }
            moodEl.textContent = targetText;
            moodEl.classList.remove('is-fading');
        }, 220);
    }

    function unmount(element) {
        const state = element.__talaSolverState;
        if (!state) {
            return;
        }
        window.clearInterval(state.clockTimer);
        window.clearInterval(state.moodTimer);
        window.clearInterval(state.matrixTimer);
        stopTrinketMotion(element, state);
        if (state.attrObserver) {
            state.attrObserver.disconnect();
        }
        mountedStages.delete(element);
        delete element.__talaSolverState;
    }

    function mount(element) {
        if (!element || !element.isConnected) {
            return;
        }

        const existing = element.__talaSolverState;
        const runId = element.dataset.runId || '';

        if (existing) {
            if (existing.runId !== runId) {
                const parsed = Date.parse(element.dataset.startedAt || '');
                existing.runId = runId;
                existing.startedAtMs = Number.isFinite(parsed) ? parsed : Date.now();
                existing.moodIndex = 0;
                existing.matrixStep = 0;
            }
            updateClock(element, existing);
            syncVisualState(element, existing, false);
            return;
        }

        const parsedStart = Date.parse(element.dataset.startedAt || '');
        const state = {
            runId,
            phase: element.dataset.solverPhase || 'solving',
            stage: element.dataset.solverStage || 'optimizing',
            health: element.dataset.solverHealth || 'normal',
            hasRetryableFailure: element.dataset.hasRetryableFailure === '1',
            startedAtMs: Number.isFinite(parsedStart) ? parsedStart : Date.now(),
            moodIndex: 0,
            matrixStep: 0,
            trinketAnimations: [],
            clockTimer: null,
            moodTimer: null,
            matrixTimer: null,
            attrObserver: null,
        };
        element.__talaSolverState = state;
        mountedStages.add(element);

        updateClock(element, state);
        syncVisualState(element, state, true);
        startTrinketMotion(element, state);

        state.clockTimer = window.setInterval(() => {
            if (!element.isConnected) {
                unmount(element);
                return;
            }
            updateClock(element, state);
            syncVisualState(element, state, false);
        }, 1000);

        state.moodTimer = window.setInterval(() => {
            if (!element.isConnected) {
                unmount(element);
                return;
            }
            if (state.stage === 'optimizing' && state.health === 'normal') {
                state.moodIndex = (state.moodIndex + 1) % OPTIMIZING_MOODS.length;
                updateMood(element, state, false);
            }
        }, 3400);

        state.matrixTimer = window.setInterval(() => {
            if (!element.isConnected) {
                unmount(element);
                return;
            }
            advanceMatrix(element, state);
        }, 780);

        state.attrObserver = new MutationObserver(() => {
            if (!element.isConnected) {
                unmount(element);
                return;
            }
            syncVisualState(element, state, false);
        });
        state.attrObserver.observe(element, {
            attributes: true,
            attributeFilter: ['data-server-stage', 'data-server-health', 'data-has-retryable-failure', 'data-run-id'],
        });
    }

    function scan() {
        mountedStages.forEach((element) => {
            if (!element.isConnected) {
                unmount(element);
            }
        });
        document.querySelectorAll('[data-tala-solver-stage]').forEach(mount);
    }

    window.talaSolverStageMount = mount;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scan, { once: true });
    } else {
        scan();
    }

    document.addEventListener('livewire:navigated', scan);
    document.addEventListener('livewire:init', () => {
        if (window.Livewire && typeof window.Livewire.hook === 'function') {
            window.Livewire.hook('morph.updated', () => {
                window.requestAnimationFrame(scan);
            });
        }
    });
})();
