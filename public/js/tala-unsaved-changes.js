// Adapt Filament's SPA guard to the shared modal; retain its dirty hash and cleanup lifecycle.
window.setUpSpaModeUnsavedDataChangesAlert = ({ resolveLivewireComponentUsing, $wire }) => {
    let approvedDestination = null;
    let active = true;

    const hasUnsavedChanges = () => (
        typeof resolveLivewireComponentUsing() !== 'undefined'
        && ! $wire?.__instance?.effects?.redirect
        && window.jsMd5(JSON.stringify($wire.data).replace(/\\/g, '')) !== $wire.savedDataHash
    );

    const navigateHandler = (event) => {
        const destination = event.detail.url.href;

        if (destination === approvedDestination) {
            approvedDestination = null;
            return;
        }

        if (event.defaultPrevented || ! hasUnsavedChanges()) return;

        event.preventDefault();
        window.dispatchEvent(new CustomEvent('tala-confirm-leave', {
            detail: {
                leave: () => {
                    if (! active) return;
                    approvedDestination = destination;
                    window.Livewire.navigate(destination);
                },
            },
        }));
    };

    const beforeUnloadHandler = (event) => {
        if (! hasUnsavedChanges()) return;
        event.preventDefault();
        event.returnValue = true;
    };

    document.addEventListener('livewire:navigate', navigateHandler);
    window.addEventListener('beforeunload', beforeUnloadHandler);

    $wire.__instance.addCleanup(() => {
        active = false;
        document.removeEventListener('livewire:navigate', navigateHandler);
        window.removeEventListener('beforeunload', beforeUnloadHandler);
    });
};
