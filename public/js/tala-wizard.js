// Keeps completed native Wizard steps reachable and marked done after going back (#59 F69).
window.talaResumableWizard = function (wizard, watch, { key, isSkippable, reachedStepIndex }) {
    const currentIndex = () => wizard.getStepIndex(wizard.step);

    wizard.furthestStepIndex = Math.max(reachedStepIndex, currentIndex());

    // Read-only inspection browses steps without saving, so browsing never marks a step done.
    watch('step', () => {
        if (! isSkippable) {
            wizard.furthestStepIndex = Math.max(wizard.furthestStepIndex, currentIndex());
        }
    });

    // Saved progress can shrink when an earlier answer reopens a later step.
    wizard.syncReachedStep = (reachedIndex) => {
        wizard.furthestStepIndex = Math.max(reachedIndex, currentIndex());
    };

    wizard.isStepAccessible = (stepKey) => isSkippable || wizard.getStepIndex(stepKey) <= wizard.furthestStepIndex;

    // A saved step keeps its done state even while the Applicant is back on it.
    wizard.isStepCompleted = (stepIndex) => stepIndex < wizard.furthestStepIndex;

    wizard.requestStep = (stepKey) => {
        const targetIndex = wizard.getStepIndex(stepKey);

        if (isSkippable || targetIndex <= currentIndex()) {
            wizard.step = stepKey;

            return;
        }

        if (! wizard.isStepAccessible(stepKey)) {
            return;
        }

        wizard.$wire.callSchemaComponentMethod(key, 'jumpToStep', {
            currentStepIndex: currentIndex(),
            targetStepIndex: targetIndex,
        });
    };

    wizard.completeJump = (stepKey) => {
        if (! wizard.isStepAccessible(stepKey)) {
            return;
        }

        wizard.step = stepKey;
        wizard.scroll();
    };
};
