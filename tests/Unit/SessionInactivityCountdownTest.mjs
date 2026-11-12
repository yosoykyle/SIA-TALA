import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../public/js/tala-idle.js', import.meta.url), 'utf8');

function countdown() {
    let clock = 1_000_000;
    let tick;
    let respond;
    const requests = [];
    const destinations = [];
    const events = [];
    const window = new EventTarget();
    const document = new EventTarget();
    class Element extends EventTarget {
        hidden = false;
        textContent = '';
        style = {};
        classList = { add() {}, remove() {} };
        childElementCount = 0;
        children = [];
        isConnected = true;
        focus() { document.activeElement = this; }
        getBoundingClientRect() { return { x: 0, y: 0, width: 100, height: 100 }; }
        closest(selector) { return selector.includes(this.selector) ? this : null; }
        matches(selector) { return selector === this.selector; }
    }
    const elements = new Map();
    const find = (selector) => {
        if (!elements.has(selector)) {
            const element = new Element();
            element.selector = selector;
            elements.set(selector, element);
        }
        return elements.get(selector);
    };
    const overlay = new Element();
    overlay.hidden = true;
    overlay.dataset = { idleSeconds: '120', warningSeconds: '60', continueUrl: '/session/continue', loginUrl: '/student/login', activityKey: 'session-current', idleAge: '0' };
    overlay.querySelector = find;
    overlay.querySelectorAll = () => [new Element(), new Element()];
    overlay.addEventListener('tala-idle-open', () => events.push('open'));
    overlay.addEventListener('tala-idle-close', () => events.push('close'));
    find('[data-idle-expire]').action = '/session/expire';
    find('input[name="_token"]').value = 'test-csrf';
    document.getElementById = () => overlay;
    document.activeElement = new Element();
    window.location = { assign: (url) => destinations.push(url) };
    const signals = [];
    runInNewContext(source, {
        window, document, Element, CustomEvent, queueMicrotask, Date: { now: () => clock },
        matchMedia: () => ({ matches: true }), localStorage: { setItem() {} },
        addEventListener: window.addEventListener.bind(window),
        setInterval: (callback) => { tick = callback; },
        AbortSignal: { timeout: (milliseconds) => { signals.push(milliseconds); return {}; } },
        FormData: class {},
        fetch: (url, options) => {
            requests.push({ url, options });
            return new Promise((resolve, reject) => { respond = { resolve, reject }; });
        },
    });
    const dispatch = (type, target, detail = {}) => {
        const event = new Event(type, { cancelable: true });
        Object.defineProperty(event, 'target', { value: target });
        Object.assign(event, detail);
        (type === 'click' || type === 'submit' ? document : window).dispatchEvent(event);
        return event;
    };
    return {
        overlay, requests, destinations, events, signals, find, dispatch,
        advance: (milliseconds) => { clock += milliseconds; tick(); },
        settle: async (response) => { respond.resolve(response); await new Promise(setImmediate); },
        fail: async () => { respond.reject(new Error('Offline')); await new Promise(setImmediate); },
    };
}

test('Stay signed in works immediately and waits for server confirmation before closing', async () => {
    const state = countdown();
    state.advance(61_000);
    state.dispatch('click', state.find('[data-idle-stay]'));
    assert.equal(state.requests.length, 1);
    assert.equal(state.overlay.hidden, false);
    assert.equal(state.requests[0].url, '/session/continue');
    assert.equal(state.requests[0].options.headers['X-CSRF-TOKEN'], 'test-csrf');
    assert.deepEqual(state.signals, [8000]);
    await state.settle({ status: 204 });
    assert.equal(state.overlay.hidden, true);
    assert.deepEqual(state.events, ['open', 'close']);
});

test('failed renewal keeps the warning and lets the explicit button retry immediately', async () => {
    const state = countdown();
    state.advance(61_000);
    state.dispatch('click', state.find('[data-idle-stay]'));
    await state.fail();
    assert.equal(state.overlay.hidden, false);
    assert.match(state.find('[data-idle-feedback]').textContent, /Unable to renew/);
    state.dispatch('click', state.find('[data-idle-stay]'));
    assert.equal(state.requests.length, 2);
    await state.settle({ status: 204 });
    assert.equal(state.overlay.hidden, true);
});

for (const response of [{ status: 401 }, { status: 419 }, { status: 0, type: 'opaqueredirect' }]) {
    test(`expired renewal ${response.status} goes to sign-in without posting a stale token again`, async () => {
        const state = countdown();
        state.advance(61_000);
        state.dispatch('click', state.find('[data-idle-stay]'));
        await state.settle(response);
        assert.deepEqual(state.destinations, ['/student/login']);
        assert.equal(state.requests.length, 1);
    });
}

test('the countdown keeps running during a pending renewal and signs out at zero', async () => {
    const state = countdown();
    state.advance(61_000);
    state.dispatch('click', state.find('[data-idle-stay]'));
    state.advance(60_000);
    assert.equal(state.requests[1].url, '/session/expire');
    await state.settle({ status: 419 });
    assert.deepEqual(state.destinations, ['/student/login']);
});

test('Sign out now uses the protected form action and recovers when the token expired', async () => {
    const state = countdown();
    state.advance(61_000);
    const event = state.dispatch('submit', state.find('[data-idle-expire]'));
    assert.equal(event.defaultPrevented, true);
    assert.equal(state.requests[0].url, '/session/expire');
    await state.settle({ status: 419 });
    assert.deepEqual(state.destinations, ['/student/login']);
});

test('unrelated sessions cannot postpone the countdown through cross-tab storage', () => {
    const state = countdown();
    state.advance(59_000);
    state.dispatch('storage', null, { key: 'session-other', newValue: '1059000' });
    state.advance(2000);
    assert.equal(state.overlay.hidden, false);
});

test('renewal confirmed in another tab closes this session warning', () => {
    const state = countdown();
    state.advance(61_000);
    state.dispatch('storage', null, { key: 'session-current', newValue: '1061000' });
    state.advance(500);
    assert.equal(state.overlay.hidden, true);
});
