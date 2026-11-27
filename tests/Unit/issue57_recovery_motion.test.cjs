const test = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { runInNewContext } = require('node:vm');

function recovery({ reduced = false, initialTime = 100, admission = true } = {}) {
    const listeners = new Map();
    const ready = [];
    const timers = [];
    const styles = new Map();
    let now = initialTime;
    let preferenceChange;
    let intersection;
    const document = {
        hidden: false,
        body: {
            dataset: {},
            classList: { contains: () => admission },
            style: { setProperty: (name, value) => styles.set(name, value) },
        },
        querySelector: selector => selector === '.error-card' ? {} : null,
        addEventListener(event, handler) {
            if (event === 'DOMContentLoaded') ready.push(handler);
            else listeners.set(event, handler);
        },
    };
    const preference = {
        matches: reduced,
        addEventListener(event, handler) { preferenceChange = handler; },
    };
    class Observer {
        constructor(handler) { intersection = handler; }
        observe() {}
    }
    runInNewContext(readFileSync(resolve(__dirname, '../../public/js/tala-error.js'), 'utf8'), {
        document,
        window: { matchMedia: () => preference, IntersectionObserver: Observer },
        performance: { now: () => now },
        setTimeout: (handler, delay) => timers.push({ handler, at: now + delay }),
        IntersectionObserver: Observer,
        HTMLButtonElement: class {},
        HTMLDialogElement: class {},
    });
    ready.forEach(handler => handler());
    return {
        document, styles,
        advance(time, deliverTimers = true) {
            now = time;
            if (deliverTimers) timers.filter(timer => timer.at <= now).forEach(timer => timer.handler());
        },
        visibility(hidden) { document.hidden = hidden; listeners.get('visibilitychange')(); },
        onscreen(value) { intersection([{ isIntersecting: value }]); },
        reduced(value) { preference.matches = value; preferenceChange(); },
    };
}

test('the background settles within five seconds of navigation and never restarts', () => {
    const page = recovery();
    assert.equal(page.document.body.dataset.motion, 'running');
    assert.equal(page.styles.get('--recovery-duration'), '4900ms');
    page.advance(4999);
    assert.equal(page.document.body.dataset.motion, 'running');
    page.advance(5000);
    assert.equal(page.document.body.dataset.motion, 'settled');
    page.visibility(true);
    page.visibility(false);
    assert.equal(page.document.body.dataset.motion, 'settled');
});

test('hidden pages pause and returning after the deadline settles even with delayed timers', () => {
    const page = recovery();
    page.visibility(true);
    assert.equal(page.document.body.dataset.motion, 'paused');
    page.advance(10000, false);
    page.visibility(false);
    assert.equal(page.document.body.dataset.motion, 'settled');
});

test('offscreen content pauses and can resume only before the original deadline', () => {
    const page = recovery();
    page.onscreen(false);
    assert.equal(page.document.body.dataset.motion, 'paused');
    page.advance(2000);
    page.onscreen(true);
    assert.equal(page.document.body.dataset.motion, 'running');
    page.onscreen(false);
    page.advance(6000, false);
    page.onscreen(true);
    assert.equal(page.document.body.dataset.motion, 'settled');
});

test('reduced motion remains static across later preference changes', () => {
    const page = recovery({ reduced: true });
    assert.equal(page.document.body.dataset.motion, 'settled');
    page.reduced(false);
    assert.equal(page.document.body.dataset.motion, 'settled');
    const running = recovery();
    running.reduced(true);
    running.reduced(false);
    assert.equal(running.document.body.dataset.motion, 'settled');
});

test('late initialization starts static and other recovery pages remain unaffected', () => {
    assert.equal(recovery({ initialTime: 6000 }).document.body.dataset.motion, 'settled');
    assert.equal(recovery({ admission: false }).document.body.dataset.motion, undefined);
});
