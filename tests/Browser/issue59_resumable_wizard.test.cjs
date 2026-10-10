const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const steps = ['choice', 'identity', 'education', 'requirements', 'review'];

function resumableWizard({ startIndex, reachedStepIndex, isSkippable = false }) {
    const context = { window: {} };
    vm.runInNewContext(fs.readFileSync('public/js/tala-wizard.js', 'utf8'), context);

    const calls = [];
    const watchers = [];
    const wizard = {
        step: steps[startIndex],
        scrolled: 0,
        getStepIndex: (step) => Math.max(0, steps.indexOf(step)),
        scroll() { this.scrolled++; },
        $wire: { callSchemaComponentMethod: (...args) => calls.push(args) },
    };
    const setStep = (stepKey) => {
        wizard.step = stepKey;
        watchers.forEach((watcher) => watcher());
    };

    context.window.talaResumableWizard(wizard, (property, callback) => watchers.push(callback), {
        key: 'data::wizard',
        isSkippable,
        reachedStepIndex,
    });

    const requestStep = (stepKey) => {
        const before = wizard.step;
        wizard.requestStep(stepKey);
        if (wizard.step !== before) {
            setStep(wizard.step);
        }
    };

    return { wizard, calls, setStep, requestStep };
}

test('completed steps keep their done state and stay reachable after going back', () => {
    const { wizard, requestStep } = resumableWizard({ startIndex: 2, reachedStepIndex: 2 });

    requestStep('choice');

    assert.equal(wizard.step, 'choice');
    assert.equal(wizard.isStepCompleted(1), true);
    assert.equal(wizard.isStepCompleted(0), true, 'the saved step being revisited keeps its done state');
    assert.equal(wizard.isStepAccessible('education'), true, 'the step being worked on stays reachable');
    assert.equal(wizard.isStepCompleted(2), false, 'the step being worked on is not yet done');
    assert.equal(wizard.isStepAccessible('requirements'), false, 'uncompleted later steps stay locked');
});

test('a forward header jump asks the server to validate and save the current step first', () => {
    const { wizard, calls, requestStep } = resumableWizard({ startIndex: 0, reachedStepIndex: 2 });

    requestStep('education');

    assert.equal(wizard.step, 'choice', 'the step changes only after the server confirms');
    assert.equal(JSON.stringify(calls), JSON.stringify([['data::wizard', 'jumpToStep', { currentStepIndex: 0, targetStepIndex: 2 }]]));

    wizard.completeJump('education');
    assert.equal(wizard.step, 'education');
    assert.equal(wizard.scrolled, 1);
});

test('locked steps cannot be requested or completed by a stray jump event', () => {
    const { wizard, calls, requestStep } = resumableWizard({ startIndex: 0, reachedStepIndex: 1 });

    requestStep('review');
    wizard.completeJump('review');

    assert.equal(wizard.step, 'choice');
    assert.equal(calls.length, 0);
});

test('saving forward extends the furthest reached step', () => {
    const { wizard, setStep } = resumableWizard({ startIndex: 0, reachedStepIndex: 0 });

    setStep('identity');
    setStep('choice');

    assert.equal(wizard.isStepCompleted(0), true);
    assert.equal(wizard.isStepCompleted(1), false, 'the furthest step is still in progress');
    assert.equal(wizard.isStepAccessible('identity'), true);
    assert.equal(wizard.isStepAccessible('education'), false);
});

test('saved progress reported by the server can reopen later steps', () => {
    const { wizard, requestStep } = resumableWizard({ startIndex: 4, reachedStepIndex: 4 });

    requestStep('choice');
    wizard.syncReachedStep(3);

    assert.equal(wizard.isStepCompleted(3), false, 'the reopened step loses its done state');
    assert.equal(wizard.isStepAccessible('requirements'), true, 'the reopened step stays reachable');
    assert.equal(wizard.isStepAccessible('review'), false, 'steps after it lock again');
    assert.equal(wizard.isStepCompleted(2), true);
});

test('a server sync never locks the step the Applicant is on', () => {
    const { wizard } = resumableWizard({ startIndex: 2, reachedStepIndex: 2 });

    wizard.syncReachedStep(0);

    assert.equal(wizard.isStepAccessible('education'), true);
    assert.equal(wizard.furthestStepIndex, 2);
});

test('read-only inspection keeps every step reachable without saving', () => {
    const { wizard, calls, requestStep } = resumableWizard({ startIndex: 0, reachedStepIndex: 2, isSkippable: true });

    requestStep('review');

    assert.equal(wizard.step, 'review');
    assert.equal(calls.length, 0);
    assert.equal(wizard.isStepCompleted(1), true, 'saved progress still shows as done');
    assert.equal(wizard.isStepCompleted(3), false, 'browsing past saved progress does not mark a step done');
});
