(() => {
    if (window.__talaReferenceInitialized) {
        return;
    }
    window.__talaReferenceInitialized = true;

    const copyResetTimers = new WeakMap();

    const announce = (feedback, message) => {
        if (!feedback) {
            return;
        }

        feedback.textContent = feedback.textContent === message ? `${message}\u00A0` : message;
    };

    const scheduleStateReset = (button) => {
        const priorTimer = copyResetTimers.get(button);
        if (priorTimer !== undefined) {
            window.clearTimeout(priorTimer);
        }

        const timer = window.setTimeout(() => {
            delete button.dataset.copied;
            delete button.dataset.failed;
            copyResetTimers.delete(button);
        }, 2000);

        copyResetTimers.set(button, timer);
    };

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-copy-reference]');
        if (!button) {
            return;
        }

        const container = button.closest('[data-tala-reference]');
        const feedback = container?.querySelector('[role="status"]');
        const value = button.dataset.copyReference ?? '';

        if (!value || !navigator.clipboard?.writeText) {
            delete button.dataset.copied;
            button.dataset.failed = 'true';
            scheduleStateReset(button);
            announce(feedback, 'Copy unavailable. Select the reference and copy it.');
            return;
        }

        try {
            await navigator.clipboard.writeText(value);
            delete button.dataset.failed;
            button.dataset.copied = 'true';
            scheduleStateReset(button);
            announce(feedback, button.dataset.copyFeedback || 'Reference copied.');
        } catch {
            delete button.dataset.copied;
            button.dataset.failed = 'true';
            scheduleStateReset(button);
            announce(feedback, 'Copy unavailable. Select the reference and copy it.');
        }
    });
})();
