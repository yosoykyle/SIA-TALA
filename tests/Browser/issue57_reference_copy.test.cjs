const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

async function copyWith(clipboard) {
    let handler;
    const feedback = { textContent: '' };
    const button = {
        dataset: { copyReference: 'APP-2026-ABCD-EFGH-JK23' },
        closest: () => ({ querySelector: () => feedback }),
    };
    const context = {
        document: { addEventListener: (event, listener) => { handler = listener; } },
        navigator: { clipboard },
    };
    vm.runInNewContext(fs.readFileSync('public/js/tala-reference.js', 'utf8'), context);
    await handler({ target: { closest: () => button } });
    return feedback.textContent;
}

test('copies the full canonical reference and announces success', async () => {
    let copied;
    const feedback = await copyWith({ writeText: async (value) => { copied = value; } });
    assert.equal(copied, 'APP-2026-ABCD-EFGH-JK23');
    assert.equal(feedback, 'Reference copied.');
});

test('clipboard refusal explains manual recovery', async () => {
    const feedback = await copyWith({ writeText: async () => { throw new Error('Permission denied'); } });
    assert.equal(feedback, 'Copy unavailable. Select the reference and copy it.');
});

test('unavailable clipboard API keeps the selectable reference usable', async () => {
    assert.equal(await copyWith(undefined), 'Copy unavailable. Select the reference and copy it.');
});
