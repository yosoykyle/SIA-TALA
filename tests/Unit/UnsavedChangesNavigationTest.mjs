import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../public/js/tala-unsaved-changes.js', import.meta.url), 'utf8');

function guard() {
    const document = new EventTarget();
    const window = new EventTarget();
    const cleanups = [];
    const navigations = [];
    const requests = [];
    window.jsMd5 = (value) => createHash('md5').update(value).digest('hex');
    const wire = { data: { reason: '' }, __instance: { effects: {}, addCleanup: (fn) => cleanups.push(fn) } };
    wire.savedDataHash = window.jsMd5(JSON.stringify(wire.data));
    const navigate = (url) => {
        const event = new CustomEvent('livewire:navigate', { cancelable: true, detail: { url: new URL(url) } });
        document.dispatchEvent(event);
        if (!event.defaultPrevented) navigations.push(url);
        return event;
    };
    window.Livewire = { navigate };
    window.addEventListener('tala-confirm-leave', (event) => requests.push(event.detail));
    runInNewContext(source, { window, document, CustomEvent });
    window.setUpSpaModeUnsavedDataChangesAlert({ resolveLivewireComponentUsing: () => wire, $wire: wire });
    return { window, wire, navigate, requests, navigations, cleanup: () => cleanups.forEach((fn) => fn()) };
}

test('dirty navigation waits for explicit leave and does not change entered work when cancelled', () => {
    const state = guard();
    state.wire.data.reason = 'Unsaved explanation';
    const destination = 'https://school.test/admin/grades';
    assert.equal(state.navigate(destination).defaultPrevented, true);
    assert.deepEqual(state.navigations, []);
    assert.equal(state.wire.data.reason, 'Unsaved explanation');
    // Dismissing the modal never invokes the request's leave callback.
    assert.equal(state.requests.length, 1);
    state.requests[0].leave();
    assert.deepEqual(state.navigations, [destination]);
    assert.equal(state.requests.length, 1);
    // Approval is consumed by that navigation, rather than disabling the guard.
    assert.equal(state.navigate(destination).defaultPrevented, true);
    assert.equal(state.requests.length, 2);
});

test('clean forms and successful server redirects leave without a confirmation', () => {
    const state = guard();
    assert.equal(state.navigate('https://school.test/admin/records').defaultPrevented, false);
    state.wire.data.reason = 'Saved by a successful action';
    state.wire.__instance.effects.redirect = '/admin/records';
    assert.equal(state.navigate('https://school.test/admin/records').defaultPrevented, false);
    assert.equal(state.requests.length, 0);
});

test('hard exits retain the browser safeguard and component cleanup removes stale guards', () => {
    const state = guard();
    state.wire.data.reason = 'Unsaved explanation';
    state.navigate('https://school.test/admin/records');
    const unload = new Event('beforeunload', { cancelable: true });
    state.window.dispatchEvent(unload);
    assert.equal(unload.defaultPrevented, true);
    state.cleanup();
    const afterCleanup = new Event('beforeunload', { cancelable: true });
    state.window.dispatchEvent(afterCleanup);
    assert.equal(afterCleanup.defaultPrevented, false);
    state.requests[0].leave();
    assert.deepEqual(state.navigations, []);
    assert.equal(state.navigate('https://school.test/admin/records').defaultPrevented, false);
});
