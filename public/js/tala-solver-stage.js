/*
 * Living Servitech Solver Stage controller.
 *
 * - Ticks the tabular MM:SS elapsed timer cleanly every second.
 * - Cycles honest CP-SAT solver activity moods every ~3.4s with gentle crossfade.
 * - Drives lively glowing slot evaluation & locking across the Mon–Sat solving matrix.
 * - Gently animates the flanking ambient trinkets via Web Animations API.
 * - Respects prefers-reduced-motion: reduce and survives Livewire wire:poll.5s morphs.
 */
(() => {
    const SOLVING_MOODS = [
        'Checking room capacities & lab types…',
        'Balancing faculty teaching hours & load caps…',
        'Minimizing student idle gaps…',
        'Verifying zero teacher & room overlaps…',
    ];
    const QUEUED_MOOD = 'Queued for the constraint solver…';

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

    function startTrinketMotion(element, state) {
        if (!calmMotion() || state.trinketAnimations.length) {
            return;
        }
        const field = element.querySelector('.tala-ambient-field');
        if (!field || !field.children.length) {
            return;
        }
        element.dataset.solverWaa = 'active';
        const speedMultiplier = state.phase === 'queued' ? 0.75 : 1.15;

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

    function advanceMatrix(element, state) {
        if (!calmMotion()) {
            return;
        }
        const blocks = [...element.querySelectorAll('[data-matrix-block]')];
        if (!blocks.length) {
            return;
        }

        const primaryIndex = state.matrixStep % blocks.length;
        const secondaryIndex = (state.matrixStep + 5) % blocks.length;
        const settledIndex = (state.matrixStep + 8) % blocks.length;
        state.matrixStep += 1;

        const isSolving = state.phase !== 'queued';
        blocks.forEach((block, index) => {
            block.classList.toggle('is-evaluating', isSolving && (index === primaryIndex || index === secondaryIndex));
            if (index === settledIndex) {
                block.classList.toggle('is-locked');
            }
        });
    }

    function updateMood(element, state, immediate = false) {
        const moodEl = element.querySelector('[data-solver-mood]');
        if (!moodEl) {
            return;
        }
        const targetText =
            state.phase === 'queued'
                ? QUEUED_MOOD
                : SOLVING_MOODS[state.moodIndex % SOLVING_MOODS.length];

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
        const phase = element.dataset.solverPhase || 'solving';

        if (existing) {
            if (existing.runId !== runId) {
                const parsed = Date.parse(element.dataset.startedAt || '');
                existing.runId = runId;
                existing.startedAtMs = Number.isFinite(parsed) ? parsed : Date.now();
                existing.moodIndex = 0;
            }
            if (existing.phase !== phase) {
                existing.phase = phase;
                updateMood(element, existing, true);
                const rate = phase === 'queued' ? 0.75 : 1.15;
                existing.trinketAnimations.forEach((a) => {
                    a.playbackRate = rate;
                });
            }
            updateClock(element, existing);
            return;
        }

        const parsedStart = Date.parse(element.dataset.startedAt || '');
        const state = {
            runId,
            phase,
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
        updateMood(element, state, true);
        startTrinketMotion(element, state);
        advanceMatrix(element, state);

        state.clockTimer = window.setInterval(() => {
            if (!element.isConnected) {
                unmount(element);
                return;
            }
            updateClock(element, state);
        }, 1000);

        state.moodTimer = window.setInterval(() => {
            if (!element.isConnected) {
                unmount(element);
                return;
            }
            if (state.phase !== 'queued') {
                state.moodIndex = (state.moodIndex + 1) % SOLVING_MOODS.length;
                updateMood(element, state, false);
            }
        }, 3400);

        state.matrixTimer = window.setInterval(() => {
            if (!element.isConnected) {
                unmount(element);
                return;
            }
            advanceMatrix(element, state);
        }, 900);

        state.attrObserver = new MutationObserver(() => {
            if (!element.isConnected) {
                unmount(element);
                return;
            }
            const nextPhase = element.dataset.solverPhase || 'solving';
            if (nextPhase !== state.phase) {
                state.phase = nextPhase;
                updateMood(element, state, true);
            }
        });
        state.attrObserver.observe(element, {
            attributes: true,
            attributeFilter: ['data-solver-phase', 'data-run-id'],
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
