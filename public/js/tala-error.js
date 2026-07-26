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
