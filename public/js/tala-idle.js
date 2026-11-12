/*
 * Inactivity countdown shared by every signed-in panel.
 *
 * The server ends an idle session on its own (EnforceCanonicalSessionPolicy). This script only
 * mirrors that clock: it warns during the last minute, lets any movement, tap or key press keep
 * the session, and signs out through the normal server route when the time runs out.
 */
(() => {
    if (window.talaIdleCountdown) return;
    window.talaIdleCountdown = true;

    const touchKey = () => overlay()?.dataset.activityKey;
    const calmMotion = () => !matchMedia('(prefers-reduced-motion: reduce)').matches;
    const overlay = () => document.getElementById('tala-idle');
    let serverTouchedAt = Date.now();
    let personActiveAt = Date.now();
    let shownAt = 0;
    let shownSecond = null;
    let renewing = false;
    let leaving = false;
    let animations = [];
    let lags = [];
    let returnFocus = null;
    let retryAfter = 0;

    const shapes = {
        spark: '<svg viewBox="0 0 24 24"><path d="M12 1 C 13 8, 16 11, 23 12 C 16 13, 13 16, 12 23 C 11 16, 8 13, 1 12 C 8 11, 11 8, 12 1 Z"/></svg>',
        ring: '<svg viewBox="0 0 24 24"><circle class="tala-idle-line" cx="12" cy="12" r="9"/></svg>',
        plus: '<svg viewBox="0 0 24 24"><path class="tala-idle-line" d="M12 3 V 21 M3 12 H 21"/></svg>',
        squiggle: '<svg viewBox="0 0 64 20"><path class="tala-idle-line" d="M2 10 C 8 0, 14 20, 20 10 S 32 0, 38 10 S 50 20, 62 10"/></svg>',
        plane: '<svg viewBox="0 0 48 44"><path d="M2 16 L 46 2 L 22 40 L 15 27 Z"/></svg>',
        dot: '<span class="tala-idle-dot"></span>',
    };
    // [shape, left %, top %, size rem, motion, seconds per loop]
    const scatter = [
        ['spark', 8, 14, 1.4, 'twinkle', 6], ['ring', 17, 62, 1.1, 'bob', 8], ['plus', 26, 28, 1, 'turn', 25], ['squiggle', 6, 82, 3.2, 'sway', 9],
        ['plane', 21, 88, 1.8, 'glide', 13], ['dot', 31, 74, .5, 'bob', 7], ['spark', 13, 40, .9, 'twinkle', 5], ['dot', 24, 10, .4, 'twinkle', 6],
        ['spark', 90, 20, 1.2, 'twinkle', 7], ['ring', 80, 36, 1.5, 'bob', 10], ['plus', 71, 14, 1.1, 'turn', 30], ['squiggle', 84, 70, 3, 'sway', 11],
        ['plane', 74, 84, 1.6, 'glide', 15], ['dot', 67, 66, .5, 'bob', 8], ['spark', 93, 52, .8, 'twinkle', 5], ['dot', 77, 54, .4, 'twinkle', 6],
        ['ring', 43, 92, .9, 'bob', 9], ['plus', 58, 6, .8, 'turn', 22],
    ];
    const motions = {
        bob: [{ translate: '0 0' }, { translate: '0 -.5rem' }, { translate: '0 0' }],
        sway: [{ rotate: '-6deg' }, { rotate: '6deg' }, { rotate: '-6deg' }],
        twinkle: [{ opacity: .3 }, { opacity: .7 }, { opacity: .3 }],
        turn: [{ rotate: '0deg' }, { rotate: '360deg' }],
        glide: [{ translate: '0 0', rotate: '-4deg' }, { translate: '1.4rem -1.1rem', rotate: '6deg' }, { translate: '0 0', rotate: '-4deg' }],
    };

    function touchServer() {
        serverTouchedAt = Date.now();
        try { if (touchKey()) localStorage.setItem(touchKey(), String(serverTouchedAt)); } catch (error) { /* this tab still works without storage */ }
    }

    function fillField(element) {
        const field = element.querySelector('.tala-idle-field');
        if (field.childElementCount) return field;
        scatter.forEach(([shape, x, y, size]) => {
            const holder = document.createElement('span');
            holder.innerHTML = shapes[shape];
            Object.assign(holder.style, { left: x + '%', top: y + '%', width: size + 'rem' });
            if (shape === 'dot') holder.firstChild.style.height = size + 'rem';
            field.append(holder);
        });
        return field;
    }

    function startMotion(element) {
        if (!calmMotion()) return;
        const field = fillField(element);
        const dial = element.querySelector('.tala-idle-dial').getBoundingClientRect();
        const centre = { x: dial.x + dial.width / 2, y: dial.y + dial.height / 2 };
        const reach = Math.hypot(Math.max(centre.x, innerWidth - centre.x), Math.max(centre.y, innerHeight - centre.y));
        lags = [...field.children].map(holder => {
            const box = holder.getBoundingClientRect();
            return Math.hypot(box.x + box.width / 2 - centre.x, box.y + box.height / 2 - centre.y) / reach;
        });
        const drift = [...field.children].map((holder, index) => holder.firstChild.animate(motions[scatter[index][4]], {
            duration: scatter[index][5] * 1000, iterations: Infinity, easing: scatter[index][4] === 'turn' ? 'linear' : 'ease-in-out', delay: -index * 430,
        }));
        const glows = [...element.querySelectorAll('.tala-idle-glow')].map((glow, index) => glow.animate(
            [{ transform: 'translate(0,0) scale(1)' }, { transform: `translate(${index ? -9 : 9}rem, ${index ? -6 : 7}rem) scale(1.25)` }, { transform: 'translate(0,0) scale(1)' }],
            { duration: index ? 23000 : 17000, iterations: Infinity, easing: 'ease-in-out' }));
        const breath = element.querySelector('.tala-idle-edge').animate(
            [{ opacity: .35 }, { opacity: 1 }, { opacity: .35 }],
            { duration: 5200, iterations: Infinity, easing: 'ease-in-out' });
        animations = [...drift, breath, ...glows];
        animations.paced = [breath, ...glows];
    }

    // Each second of the clock sends one soft beat through the trinkets: nearest first, a little bigger near the end.
    function beat(element, phase) {
        if (!calmMotion()) return;
        const peak = phase === 'hot' ? 1.4 : phase === 'warm' ? 1.28 : 1.18;
        [...element.querySelector('.tala-idle-field').children].forEach((holder, index) => holder.animate(
            [{ scale: 1 }, { scale: peak, offset: .35 }, { scale: 1 }],
            { duration: 620, delay: (lags[index] ?? 0) * 380, easing: 'ease-in-out' }));
    }

    function paint(element, left) {
        if (left === shownSecond) return;
        shownSecond = left;
        const digits = element.querySelectorAll('.tala-idle-numeral span');
        const text = String(left);
        digits[0].textContent = text.length > 1 ? text[0] : text;
        digits[1].textContent = text.length > 1 ? text[1] : '';
        const warning = Number(element.dataset.warningSeconds);
        element.querySelector('.tala-idle-ring-left').style.strokeDashoffset = 100 - Math.max(0, left - 1) / warning * 100;
        const phase = left <= 10 ? 'hot' : left <= 20 ? 'warm' : 'calm';
        element.dataset.phase = phase;
        element.querySelector('.tala-idle-mood').textContent = phase === 'hot' ? 'Almost time.' : phase === 'warm' ? 'Not long now.' : 'Still there?';
        (animations.paced ?? []).forEach(animation => {
            animation.playbackRate = phase === 'hot' ? 1 + (11 - left) * .9 : phase === 'warm' ? 2.2 : 1;
        });
        if (calmMotion() && phase !== 'calm') {
            const numeral = element.querySelector('.tala-idle-numeral');
            numeral.classList.remove('tala-idle-tick');
            void numeral.offsetWidth;
            numeral.classList.add('tala-idle-tick');
        }
        beat(element, phase);
        if ([60, 30, 20, 10, 5].includes(left)) element.querySelector('[data-idle-live]').textContent = `You will be signed out in ${left} seconds.`;
    }

    function show(element, left) {
        returnFocus = document.activeElement;
        shownAt = Date.now();
        shownSecond = null;
        const ring = element.querySelector('.tala-idle-ring-left');
        ring.style.transition = 'none';
        ring.style.strokeDashoffset = 0;
        element.hidden = false;
        element.dispatchEvent(new CustomEvent('tala-idle-open'));
        void ring.getBoundingClientRect();
        ring.style.transition = '';
        startMotion(element);
        paint(element, left);
        element.querySelector('[data-idle-stay]')?.focus({ preventScroll: true });
    }

    function hide(element) {
        animations.forEach(animation => animation.cancel());
        animations = [];
        element.hidden = true;
        element.dispatchEvent(new CustomEvent('tala-idle-close'));
        const previous = returnFocus;
        returnFocus = null;
        queueMicrotask(() => { if (previous?.isConnected) previous.focus({ preventScroll: true }); });
    }

    function feedback(element, message = '') {
        const target = element.querySelector('[data-idle-feedback]');
        target.hidden = !message;
        target.textContent = message;
    }

    function signIn(element) {
        window.location.assign(element.dataset.loginUrl);
    }

    function request(element, url, body) {
        return fetch(url, {
            method: 'POST', credentials: 'same-origin', redirect: 'manual',
            signal: AbortSignal.timeout(8000),
            headers: { 'X-CSRF-TOKEN': element.querySelector('input[name="_token"]').value, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            body,
        });
    }

    async function leave(element) {
        if (leaving) return;
        leaving = true;
        feedback(element, 'Signing you out…');
        try {
            const form = element.querySelector('[data-idle-expire]');
            const response = await request(element, form.action, new FormData(form));
            // A redirect is opaque to fetch with redirect:manual. Never post a stale token again.
            if (response.type === 'opaqueredirect' || response.ok || [401, 419].includes(response.status)) {
                signIn(element);
                return;
            }
            throw new Error('Sign-out unavailable');
        } catch (error) {
            leaving = false;
            retryAfter = Date.now() + 5000;
            feedback(element, 'Unable to confirm sign-out. Check your connection and try Sign out now again.');
        }
    }

    async function renew(element, announce) {
        if (renewing || leaving) return;
        renewing = true;
        feedback(element, 'Keeping you signed in…');
        try {
            const response = await request(element, element.dataset.continueUrl);
            if (leaving) return;
            if (response.type === 'opaqueredirect' || [401, 419].includes(response.status)) {
                leaving = true;
                signIn(element);
                return;
            }
            if (response.status !== 204) throw new Error('Renewal unavailable');
            touchServer();
            feedback(element);
            if (!element.hidden) hide(element);
            if (announce && window.FilamentNotification) {
                new window.FilamentNotification().title('Welcome back').body('You are still signed in.').success().send();
            }
        } catch (error) {
            retryAfter = Date.now() + 5000;
            feedback(element, 'Unable to renew your session. Check your connection and try Stay signed in again.');
        } finally {
            renewing = false;
        }
    }

    function tick() {
        const element = overlay();
        if (!element || leaving) return;
        const left = Math.ceil(Number(element.dataset.idleSeconds) - (Date.now() - serverTouchedAt) / 1000);
        const warning = Number(element.dataset.warningSeconds);
        if (left > warning) { if (!element.hidden) hide(element); return; }
        if (left <= 0) {
            if (element.hidden) show(element, 0);
            paint(element, 0);
            if (Date.now() >= retryAfter) leave(element);
            return;
        }
        if (element.hidden) {
            // Someone working without sending a request (typing a long answer, reading and scrolling) is not idle.
            if (!renewing && Date.now() >= retryAfter && Date.now() - personActiveAt < 5000) { renew(element, false); return; }
            show(element, left);
            return;
        }
        paint(element, left);
    }

    ['pointermove', 'pointerdown', 'keydown', 'touchstart', 'wheel'].forEach(type => addEventListener(type, event => {
        personActiveAt = Date.now();
        const element = overlay();
        if (!element || element.hidden || Date.now() - shownAt < 900 || Date.now() < retryAfter) return;
        if (event.target instanceof Element && event.target.closest('[data-idle-expire], [data-idle-stay]')) return;
        if (type === 'keydown' && (event.key === 'Tab' || event.key === 'Shift')) return;
        renew(element, true);
    }, { passive: true, capture: true }));

    document.addEventListener('click', event => {
        const element = overlay();
        if (element && event.target instanceof Element && event.target.closest('[data-idle-stay]')) renew(element, true);
    });
    document.addEventListener('submit', event => {
        const element = overlay();
        if (element && event.target.matches('[data-idle-expire]')) {
            event.preventDefault();
            leave(element);
        }
    });
    addEventListener('storage', event => {
        const timestamp = Number(event.newValue);
        if (event.key === touchKey() && timestamp > serverTouchedAt && timestamp <= Date.now()) serverTouchedAt = timestamp;
    });
    document.addEventListener('livewire:navigated', touchServer);
    document.addEventListener('livewire:init', () => {
        window.Livewire?.hook('commit', ({ succeed }) => succeed(touchServer));
    });

    serverTouchedAt = Date.now() - Number(overlay()?.dataset.idleAge ?? 0) * 1000;
    setInterval(tick, 500);
})();
