document.addEventListener('DOMContentLoaded', () => {
    document.querySelector('[data-print-acknowledgment]')?.addEventListener('click', () => {
        window.print();
    });
});
