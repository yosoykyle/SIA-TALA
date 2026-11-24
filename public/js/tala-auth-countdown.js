/*
 * Monotonic inline rate-limit countdown for public authentication pages.
 *
 * Uses server-computed remaining seconds (data-remaining-seconds + data-total-seconds)
 * and elapsed client wall time (Date.now() - startedAt) so client clock skew never distorts
 * the timer. When the countdown reaches 0, calls Livewire $wire.clearAuthRateLimit() so
 * the form fields reappear automatically in place.
 */
(() => {
    const calmMotion = () => !matchMedia('(prefers-reduced-motion: reduce)').matches;

    function resolveWire(element) {
        if (element.__talaWire) {
            return element.__talaWire;
        }
        const wireHost = element.closest('[wire\\:id]');
        const wireId = wireHost ? wireHost.getAttribute('wire:id') : null;
        if (wireId && window.Livewire && typeof window.Livewire.find === 'function') {
            return window.Livewire.find(wireId);
        }
        return null;
    }

    function clearRateLimitOnWire(element) {
        const wire = resolveWire(element);
        if (!wire) {
            return;
        }
        if (typeof wire.clearAuthRateLimit === 'function') {
            wire.clearAuthRateLimit();
        } else if (typeof wire.call === 'function') {
            wire.call('clearAuthRateLimit');
        }
    }

    function resolveAmbient(element) {
        return element.closest('.tala-auth-main')?.querySelector('.tala-ambient--auth')
            || document.querySelector('.tala-ambient--auth');
    }

    function computeAmbientLags(element, ambient) {
        const dial = element.querySelector('.tala-idle-dial');
        const field = ambient?.querySelector('.tala-ambient-field');
        if (!dial || !field) {
            return [];
        }
        const dialBox = dial.getBoundingClientRect();
        if (dialBox.width <= 0 || dialBox.height <= 0) {
            return [];
        }
        const centre = { x: dialBox.x + dialBox.width / 2, y: dialBox.y + dialBox.height / 2 };
        const reach = Math.max(
            1,
            Math.hypot(Math.max(centre.x, innerWidth - centre.x), Math.max(centre.y, innerHeight - centre.y)),
        );
        return [...field.children].map((holder) => {
            const box = holder.getBoundingClientRect();
            return Math.hypot(box.x + box.width / 2 - centre.x, box.y + box.height / 2 - centre.y) / reach;
        });
    }

    function syncAmbientPhase(element, phase) {
        const ambient = resolveAmbient(element);
        if (!ambient) {
            return null;
        }
        ambient.dataset.authState = 'rate-limited';
        ambient.dataset.rateLimitPhase = phase;
        return ambient;
    }

    function beatAmbientTrinkets(element, ambient, phase) {
        if (!calmMotion() || !ambient) {
            return;
        }
        const field = ambient.querySelector('.tala-ambient-field');
        if (!field || !field.children.length) {
            return;
        }
        if (!element.__talaAmbientLags || element.__talaAmbientLags.length !== field.children.length) {
            element.__talaAmbientLags = computeAmbientLags(element, ambient);
        }
        const peak = phase === 'warm' ? 1.18 : 1.12;
        [...field.children].forEach((holder, index) => {
            if (typeof holder.animate === 'function') {
                holder.animate(
                    [{ scale: 1 }, { scale: peak, offset: 0.4 }, { scale: 1 }],
                    { duration: 760, delay: (element.__talaAmbientLags[index] ?? 0) * 420, easing: 'ease-in-out' },
                );
            }
        });
    }

    function resetAmbientIfInactive() {
        if (document.querySelector('[data-tala-auth-countdown]')) {
            return;
        }
        document.querySelectorAll('.tala-ambient--auth[data-auth-state="rate-limited"]').forEach((ambient) => {
            delete ambient.dataset.authState;
            delete ambient.dataset.rateLimitPhase;
        });
    }

    function paintElement(element) {
        if (!element.__talaStartedAt) {
            mountElement(element);
            return;
        }

        const elapsedSeconds = Math.floor((Date.now() - element.__talaStartedAt) / 1000);
        const left = Math.max(0, element.__talaInitialSeconds - elapsedSeconds);

        if (left !== element.__talaShownSecond) {
            const previousSecond = element.__talaShownSecond;
            element.__talaShownSecond = left;

            const digits = element.querySelectorAll('.tala-idle-numeral span');
            const text = String(left);
            const nextFirst = text.length > 1 ? text.slice(0, -1) : text;
            const nextSecond = text.length > 1 ? text.slice(-1) : '';
            const firstChanged = digits[0] && digits[0].textContent !== nextFirst;
            const secondChanged = digits[1] && digits[1].textContent !== nextSecond;

            if (digits[0]) {
                digits[0].textContent = nextFirst;
            }
            if (digits[1]) {
                digits[1].textContent = nextSecond;
            }

            if (previousSecond !== null && calmMotion()) {
                if (firstChanged && digits[0]) {
                    digits[0].classList.remove('tala-digit-pop');
                    void digits[0].offsetWidth;
                    digits[0].classList.add('tala-digit-pop');
                }
                if (secondChanged && digits[1]) {
                    digits[1].classList.remove('tala-digit-pop');
                    void digits[1].offsetWidth;
                    digits[1].classList.add('tala-digit-pop');
                }
            }

            const progressFill = element.querySelector('.tala-auth-countdown-progress-fill');
            if (progressFill) {
                const total = Math.max(1, element.__talaTotalSeconds || 60);
                const ratio = Math.min(1, Math.max(0, Math.max(0, left - 1) / total));
                progressFill.style.transform = `scaleX(${ratio})`;
            }

            const phase = left <= 10 ? 'calm' : 'warm';
            element.dataset.phase = phase;
            const ambient = syncAmbientPhase(element, phase);

            if (previousSecond !== null && calmMotion()) {
                const numeral = element.querySelector('.tala-idle-numeral');
                if (numeral) {
                    numeral.classList.remove('tala-idle-tick');
                    void numeral.offsetWidth;
                    numeral.classList.add('tala-idle-tick');
                }
            }

            beatAmbientTrinkets(element, ambient, phase);

            const live = element.querySelector('[data-auth-countdown-live]');
            if (live && [60, 30, 20, 10, 5, 0].includes(left)) {
                live.textContent = left > 0
                    ? `Please wait ${left} seconds before trying again.`
                    : 'You may try again now.';
            }
        }

        if (left <= 0 && !element.__talaCleared) {
            element.__talaCleared = true;
            clearRateLimitOnWire(element);
        }
    }

    function mountElement(element, wire) {
        if (!element) {
            return;
        }
        if (wire) {
            element.__talaWire = wire;
        }

        const attrRemaining = Math.max(0, Number(element.dataset.remainingSeconds || 0));
        const attrTotal = Math.max(1, attrRemaining, Number(element.dataset.totalSeconds || 60));

        if (!element.__talaStartedAt || element.__talaAttrRemaining !== attrRemaining) {
            element.__talaStartedAt = Date.now();
            element.__talaInitialSeconds = attrRemaining;
            element.__talaAttrRemaining = attrRemaining;
            element.__talaTotalSeconds = attrTotal;
            element.__talaShownSecond = null;
            element.__talaCleared = false;
            element.__talaAmbientLags = null;
        }

        paintElement(element);
    }

    function tickAll() {
        const countdowns = document.querySelectorAll('[data-tala-auth-countdown]');
        if (!countdowns.length) {
            resetAmbientIfInactive();
            return;
        }
        countdowns.forEach((element) => {
            paintElement(element);
        });
    }

    window.talaAuthCountdownMount = mountElement;

    if (!window.__talaAuthCountdownInterval) {
        window.__talaAuthCountdownInterval = setInterval(tickAll, 250);
    }

    tickAll();
})();
