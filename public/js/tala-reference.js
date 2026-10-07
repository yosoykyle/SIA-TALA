document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy-reference]');
    if (!button) return;
    const feedback = button.closest('[data-tala-reference]').querySelector('[role="status"]');
    try {
        await navigator.clipboard.writeText(button.dataset.copyReference);
        feedback.textContent = 'Reference copied.';
    } catch {
        feedback.textContent = 'Copy unavailable. Select the reference and copy it.';
    }
});
