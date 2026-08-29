<a
    href="#tala-main-content"
    class="tala-skip-link"
    x-init="
        const rememberActionGroup = (actionGroup) => {
            window.talaActionGroupReturnFocus = actionGroup;
            window.talaActionGroupReturnHref = actionGroup.closest('tr')?.querySelector('a[href]')?.href;
        };
        window.talaRestoreActionGroupFocus = () => {
            const freshActionGroup = window.talaActionGroupReturnHref
                ? Array.from(document.querySelectorAll('.fi-ta-row a[href]'))
                    .find((link) => link.href === window.talaActionGroupReturnHref)
                    ?.closest('tr')
                    ?.querySelector('.fi-ac-icon-btn-group')
                : null;
            const target = freshActionGroup ?? window.talaActionGroupReturnFocus;
            if (
                target?.isConnected
                && ! target.closest('[inert]')
                && (document.activeElement === document.body || ! document.activeElement?.isConnected)
            ) {
                target.focus({ preventScroll: true });
            }
        };
        document.addEventListener('focusin', (event) => {
            const actionGroup = event.target.closest('.fi-ac-icon-btn-group');
            if (actionGroup) {
                rememberActionGroup(actionGroup);
            }
        });
        document.addEventListener('pointerdown', (event) => {
            window.talaFocusRestorePending = false;
            const actionGroup = event.target.closest('.fi-ac-icon-btn-group');
            if (actionGroup) {
                rememberActionGroup(actionGroup);
            }
        }, true);
        document.addEventListener('keydown', () => {
            window.talaFocusRestorePending = false;
        }, true);
        window.addEventListener('transitionend', () => {
            if (window.talaFocusRestorePending) {
                window.setTimeout(window.talaRestoreActionGroupFocus, 0);
            }
        });
        window.addEventListener('modal-closed', () => {
            window.talaFocusRestorePending = true;
            window.setTimeout(window.talaRestoreActionGroupFocus, 0);
        });
    "
    x-on:click="$nextTick(() => document.getElementById('tala-main-content')?.focus({ preventScroll: true }))"
>Skip to main content</a>
