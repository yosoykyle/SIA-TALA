document.addEventListener('DOMContentLoaded', () => {
    const dialog = document.querySelector('[data-account-switch-dialog]');
    const openButton = document.querySelector('[data-open-account-switch]');

    if (!(dialog instanceof HTMLDialogElement) || !(openButton instanceof HTMLButtonElement)) {
        return;
    }

    openButton.addEventListener('click', () => {
        dialog.showModal();
    });

    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            dialog.close();
        }
    });
});

document.addEventListener('DOMContentLoaded', () => {
    if (!document.body.classList.contains('admission-recovery')) return;
    const preference = window.matchMedia('(prefers-reduced-motion: reduce)');
    const deadline = 5000;
    let settled = preference.matches || performance.now() >= deadline;
    let onscreen = true;
    const update = () => {
        settled = settled || preference.matches || performance.now() >= deadline;
        document.body.dataset.motion = settled ? 'settled' : (document.hidden || !onscreen ? 'paused' : 'running');
    };
    document.body.style.setProperty('--recovery-duration', `${Math.max(0, deadline - performance.now())}ms`);
    setTimeout(update, Math.max(0, deadline - performance.now()));
    document.addEventListener('visibilitychange', update);
    preference.addEventListener('change', update);
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(([entry]) => { onscreen = entry.isIntersecting; update(); });
        observer.observe(document.querySelector('.error-card'));
    }
    update();
});
