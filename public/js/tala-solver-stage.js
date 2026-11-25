/*
 * Living Servitech Timetable Stage controller.
 *
 * Modeled directly on public/js/tala-idle.js (Session Timeout Countdown):
 * - ONE continuous ambient layer (2 blurred orbiting glows, 1 breathing inset edge
 *   vignette, and 16 drifting trinkets) shared across every timetable stage:
 *   calm (empty), saved, queued, optimizing, validating, stalled, and error.
 * - Never swaps backgrounds or removes trinkets between states; instead, CSS custom
 *   properties transition smoothly (1.2s ease) while WAAPI playbackRate and outward
 *   distance-lagged radial trinket beat() waves express each state's behavior.
 * - Ticks the tabular MM:SS elapsed timer and drives Mon-Sat matrix choreography
 *   on active generation runs.
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

    const BAR_MODIFIERS = ['is-saved', 'is-queued', 'is-solving', 'is-validating', 'is-stalled', 'is-error'];

    /* Pacing and radial beat profiles per mood (exact tala-idle playbackRate philosophy) */
    const MOOD_MOTION = {
        calm: { trinket: 0.72, glow: 0.75, edge: 0.65, beatIntervalMs: 0, beatPeak: 1.12 },
        saved: { trinket: 0.95, glow: 0.95, edge: 0.9, beatIntervalMs: 0, beatPeak: 1.18, beatOnEnter: true },
        queued: { trinket: 1.05, glow: 1.05, edge: 1.0, beatIntervalMs: 4600, beatPeak: 1.13 },
        optimizing: { trinket: 2.1, glow: 2.2, edge: 2.0, beatIntervalMs: 2100, beatPeak: 1.26, beatOnEnter: true },
        validating: { trinket: 1.5, glow: 1.45, edge: 1.6, beatIntervalMs: 1650, beatPeak: 1.2, beatOnEnter: true },
        stalled: { trinket: 0.52, glow: 0.58, edge: 1.45, beatIntervalMs: 3800, beatPeak: 1.14 },
        error: { trinket: 0.42, glow: 0.45, edge: 0.85, beatIntervalMs: 0, beatPeak: 1.12, beatOnEnter: true },
    };

    const CHECK_ICON_SVG =
        '<svg class="fi-icon h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/></svg>';

    const reducedMotionMedia = window.matchMedia('(prefers-reduced-motion: reduce)');
    const calmMotion = () => !reducedMotionMedia.matches;
    const mountedStages = new Set();
    const mountedAmbientStages = new Set();

    const trinketMotions = {
        bob: [{ translate: '0 0' }, { translate: '0 -0.6rem' }, { translate: '0 0' }],
        sway: [{ rotate: '-8deg' }, { rotate: '8deg' }, { rotate: '-8deg' }],
        twinkle: [
            { opacity: 0.35, scale: 0.85 },
            { opacity: 0.92, scale: 1.15 },
            { opacity: 0.35, scale: 0.85 },
        ],
        turn: [{ rotate: '0deg' }, { rotate: '360deg' }],
        glide: [
            { translate: '0 0', rotate: '-5deg' },
            { translate: '1.25rem -1rem', rotate: '7deg' },
            { translate: '0 0', rotate: '-5deg' },
        ],
    };

    function parseVar(styleText, name, unit, fallback) {
        const match = styleText.match(new RegExp(name + '\\s*:\\s*(-?[\\d.]+)' + unit));
        return match ? Number(match[1]) : fallback;
    }

    function formatClock(totalSeconds) {
        const safe = Math.max(0, Math.floor(totalSeconds));
        const mins = String(Math.floor(safe / 60)).padStart(2, '0');
        const secs = String(safe % 60).padStart(2, '0');
        return { mins, secs };
    }

    function moodProfile(mood) {
        return MOOD_MOTION[mood] || MOOD_MOTION.calm;
    }

    function resolveStageMood(stage, health) {
        if (health === 'error') {
            return 'error';
        }
        if (health === 'stalled') {
            return 'stalled';
        }
        return MOOD_MOTION[stage] ? stage : 'optimizing';
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

    /*
     * Unified Living Ambient Motion Engine (glow-a, glow-b, edge vignette, 16 trinkets + radial beat())
     * Runs identically on active solver stages AND empty/warning/error stages.
     */
    function startAmbientMotion(element, ambient, mood) {
        if (!calmMotion() || ambient.running) {
            return;
        }
        const backdrop = element.querySelector('.tala-ambient--stage');
        if (!backdrop) {
            return;
        }
        const glowA = backdrop.querySelector('.tala-ambient-glow--a');
        const glowB = backdrop.querySelector('.tala-ambient-glow--b');
        const edge = backdrop.querySelector('.tala-ambient-edge');
        const field = backdrop.querySelector('.tala-ambient-field');

        const profile = moodProfile(mood);
        element.dataset.solverWaa = 'active';
        ambient.running = true;

        if (glowA && typeof glowA.animate === 'function') {
            const animA = glowA.animate(
                [{ transform: 'translate(0, 0) scale(1)' }, { transform: 'translate(6.5rem, 4.75rem) scale(1.24)' }],
                { duration: 17000, iterations: Infinity, direction: 'alternate', easing: 'ease-in-out' }
            );
            animA.playbackRate = profile.glow;
            ambient.glows.push(animA);
        }

        if (glowB && typeof glowB.animate === 'function') {
            const animB = glowB.animate(
                [{ transform: 'translate(0, 0) scale(1)' }, { transform: 'translate(-6.5rem, -4.75rem) scale(1.24)' }],
                { duration: 23000, iterations: Infinity, direction: 'alternate', easing: 'ease-in-out', delay: -5500 }
            );
            animB.playbackRate = profile.glow;
            ambient.glows.push(animB);
        }

        if (edge && typeof edge.animate === 'function') {
            const animEdge = edge.animate([{ opacity: 0.34 }, { opacity: 1 }, { opacity: 0.34 }], {
                duration: 5200,
                iterations: Infinity,
                easing: 'ease-in-out',
            });
            animEdge.playbackRate = profile.edge;
            ambient.edge = animEdge;
        }

        if (field && field.children.length) {
            const holders = [...field.children];
            const distances = holders.map((holder) => {
                const styleText = holder.getAttribute('style') || '';
                const x = parseVar(styleText, '--x', '%', 50) / 100;
                const y = parseVar(styleText, '--y', '%', 50) / 100;
                return Math.hypot(x - 0.5, y - 0.5);
            });
            const furthest = Math.max(...distances, 0.01);

            ambient.holders = holders;
            ambient.lags = distances.map((d) => d / furthest);

            ambient.trinkets = holders
                .map((holder, index) => {
                    const target = holder.firstElementChild;
                    if (!target || typeof target.animate !== 'function') {
                        return null;
                    }
                    const motionKey = holder.dataset.motion || 'bob';
                    const keyframes = trinketMotions[motionKey] || trinketMotions.bob;
                    const inlineStyle = holder.getAttribute('style') || '';
                    const durationSec = parseVar(inlineStyle, '--trinket-duration', 's', 7.5 + (index % 5) * 1.3);
                    const delaySec = parseVar(inlineStyle, '--trinket-delay', 's', -index * 0.43);

                    const anim = target.animate(keyframes, {
                        duration: Math.max(2400, durationSec * 1000),
                        iterations: Infinity,
                        easing: motionKey === 'turn' ? 'linear' : 'ease-in-out',
                        delay: delaySec * 1000,
                    });
                    anim.playbackRate = profile.trinket;
                    return anim;
                })
                .filter(Boolean);
        }
    }

    function stopAmbientMotion(element, ambient) {
        if (ambient.beatTimer) {
            window.clearInterval(ambient.beatTimer);
            ambient.beatTimer = null;
        }
        [...(ambient.glows || []), ...(ambient.trinkets || []), ambient.edge].filter(Boolean).forEach((anim) => {
            try {
                anim.cancel();
            } catch (e) {
                /* ignore detached */
            }
        });
        ambient.glows = [];
        ambient.trinkets = [];
        ambient.edge = null;
        ambient.holders = [];
        ambient.lags = [];
        ambient.running = false;
        delete element.dataset.solverWaa;
    }

    function beatTrinkets(ambient, peakScale) {
        if (!calmMotion() || !ambient.running || !ambient.holders.length) {
            return;
        }
        const scale = peakScale || 1.2;
        ambient.holders.forEach((holder, index) => {
            if (!holder.isConnected || typeof holder.animate !== 'function') {
                return;
            }
            try {
                holder.animate([{ scale: 1 }, { scale, offset: 0.34 }, { scale: 1 }], {
                    duration: 580,
                    easing: 'ease-out',
                    delay: (ambient.lags[index] || 0) * 360,
                });
            } catch (e) {
                /* ignore detached */
            }
        });
    }

    function syncAmbientMood(element, ambient, mood, triggerEnterBeat = false) {
        element.dataset.stageMood = mood;
        const profile = moodProfile(mood);

        if (!calmMotion()) {
            stopAmbientMotion(element, ambient);
            return;
        }

        if (!ambient.running) {
            startAmbientMotion(element, ambient, mood);
        } else {
            ambient.trinkets.forEach((anim) => {
                try {
                    anim.playbackRate = profile.trinket;
                } catch (e) {
                    /* ignore detached */
                }
            });
            ambient.glows.forEach((anim) => {
                try {
                    anim.playbackRate = profile.glow;
                } catch (e) {
                    /* ignore detached */
                }
            });
            if (ambient.edge) {
                try {
                    ambient.edge.playbackRate = profile.edge;
                } catch (e) {
                    /* ignore detached */
                }
            }
        }

        if (ambient.beatTimer) {
            window.clearInterval(ambient.beatTimer);
            ambient.beatTimer = null;
        }

        if (triggerEnterBeat && profile.beatOnEnter) {
            beatTrinkets(ambient, profile.beatPeak);
        }

        if (profile.beatIntervalMs > 0) {
            ambient.beatTimer = window.setInterval(() => {
                if (!element.isConnected) {
                    stopAmbientMotion(element, ambient);
                    return;
                }
                beatTrinkets(ambient, profile.beatPeak);
            }, profile.beatIntervalMs);
        }
    }

    function createAmbientState() {
        return {
            running: false,
            glows: [],
            edge: null,
            trinkets: [],
            holders: [],
            lags: [],
            beatTimer: null,
        };
    }

    /* Active Solver Stage logic */
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
        const prevMood = state.mood;
        state.stage = computed.stage;
        state.health = computed.health;
        state.hasRetryableFailure = computed.hasRetryableFailure;
        state.phase = computed.stage === 'saved' || computed.stage === 'queued' ? 'queued' : 'solving';
        state.mood = resolveStageMood(state.stage, state.health);

        element.dataset.solverStage = state.stage;
        element.dataset.solverHealth = state.health;
        element.dataset.solverPhase = state.phase;

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

        syncAmbientMood(element, state.ambient, state.mood, force || prevMood !== state.mood);

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

    function unmountSolver(element) {
        const state = element.__talaSolverState;
        if (!state) {
            return;
        }
        window.clearInterval(state.clockTimer);
        window.clearInterval(state.moodTimer);
        window.clearInterval(state.matrixTimer);
        stopAmbientMotion(element, state.ambient);
        if (state.attrObserver) {
            state.attrObserver.disconnect();
        }
        mountedStages.delete(element);
        delete element.__talaSolverState;
    }

    function mountSolver(element) {
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
        const initialStage = element.dataset.solverStage || 'optimizing';
        const initialHealth = element.dataset.solverHealth || 'normal';
        const state = {
            runId,
            phase: element.dataset.solverPhase || 'solving',
            stage: initialStage,
            health: initialHealth,
            mood: resolveStageMood(initialStage, initialHealth),
            hasRetryableFailure: element.dataset.hasRetryableFailure === '1',
            startedAtMs: Number.isFinite(parsedStart) ? parsedStart : Date.now(),
            moodIndex: 0,
            matrixStep: 0,
            ambient: createAmbientState(),
            clockTimer: null,
            moodTimer: null,
            matrixTimer: null,
            attrObserver: null,
        };
        element.__talaSolverState = state;
        mountedStages.add(element);

        updateClock(element, state);
        syncVisualState(element, state, true);

        state.clockTimer = window.setInterval(() => {
            if (!element.isConnected) {
                unmountSolver(element);
                return;
            }
            updateClock(element, state);
            syncVisualState(element, state, false);
        }, 1000);

        state.moodTimer = window.setInterval(() => {
            if (!element.isConnected) {
                unmountSolver(element);
                return;
            }
            if (state.stage === 'optimizing' && state.health === 'normal') {
                state.moodIndex = (state.moodIndex + 1) % OPTIMIZING_MOODS.length;
                updateMood(element, state, false);
            }
        }, 3400);

        state.matrixTimer = window.setInterval(() => {
            if (!element.isConnected) {
                unmountSolver(element);
                return;
            }
            advanceMatrix(element, state);
        }, 780);

        state.attrObserver = new MutationObserver(() => {
            if (!element.isConnected) {
                unmountSolver(element);
                return;
            }
            syncVisualState(element, state, false);
        });
        state.attrObserver.observe(element, {
            attributes: true,
            attributeFilter: ['data-server-stage', 'data-server-health', 'data-has-retryable-failure', 'data-run-id'],
        });
    }

    /* Standalone Empty / Warning / Error Ambient Stage logic */
    function unmountAmbient(element) {
        const state = element.__talaAmbientState;
        if (!state) {
            return;
        }
        stopAmbientMotion(element, state.ambient);
        if (state.attrObserver) {
            state.attrObserver.disconnect();
        }
        mountedAmbientStages.delete(element);
        delete element.__talaAmbientState;
    }

    function mountAmbient(element) {
        if (!element || !element.isConnected || element.hasAttribute('data-tala-solver-stage')) {
            return;
        }

        const targetMood = element.dataset.stageMood || 'calm';
        const existing = element.__talaAmbientState;
        if (existing) {
            if (existing.mood !== targetMood || !existing.ambient.running) {
                const changed = existing.mood !== targetMood;
                existing.mood = targetMood;
                syncAmbientMood(element, existing.ambient, targetMood, changed);
            }
            return;
        }

        const state = {
            mood: targetMood,
            ambient: createAmbientState(),
            attrObserver: null,
        };
        element.__talaAmbientState = state;
        mountedAmbientStages.add(element);

        syncAmbientMood(element, state.ambient, targetMood, false);

        state.attrObserver = new MutationObserver(() => {
            if (!element.isConnected) {
                unmountAmbient(element);
                return;
            }
            const nextMood = element.dataset.stageMood || 'calm';
            if (nextMood !== state.mood) {
                state.mood = nextMood;
                syncAmbientMood(element, state.ambient, nextMood, true);
            }
        });
        state.attrObserver.observe(element, {
            attributes: true,
            attributeFilter: ['data-stage-mood'],
        });
    }

    function scan() {
        mountedStages.forEach((element) => {
            if (!element.isConnected) {
                unmountSolver(element);
            }
        });
        mountedAmbientStages.forEach((element) => {
            if (!element.isConnected) {
                unmountAmbient(element);
            }
        });
        document.querySelectorAll('[data-tala-solver-stage]').forEach(mountSolver);
        document.querySelectorAll('[data-tala-ambient-stage]').forEach(mountAmbient);
    }

    if (typeof reducedMotionMedia.addEventListener === 'function') {
        reducedMotionMedia.addEventListener('change', () => {
            mountedStages.forEach((element) => {
                const state = element.__talaSolverState;
                if (state) {
                    syncAmbientMood(element, state.ambient, state.mood, false);
                }
            });
            mountedAmbientStages.forEach((element) => {
                const state = element.__talaAmbientState;
                if (state) {
                    syncAmbientMood(element, state.ambient, state.mood, false);
                }
            });
        });
    }

    window.talaSolverStageMount = mountSolver;
    window.talaAmbientStageMount = mountAmbient;

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
