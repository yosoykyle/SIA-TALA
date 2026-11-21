<x-filament::modal
    id="tala-auth-support-dialog"
    heading="Contact School Support"
    width="lg"
    footer-actions-alignment="end"
    :sticky-header="true"
    :sticky-footer="true"
    teleport="body"
    class="tala-auth-info-modal"
>
    <x-tala-support-channels-content />

    <x-slot name="footerActions">
        <x-filament::button
            color="gray"
            outlined
            type="button"
            x-on:click="$dispatch('close-modal', { id: 'tala-auth-support-dialog' }); $dispatch('open-modal', { id: 'tala-auth-privacy-dialog' })"
        >
            Data Privacy Notice
        </x-filament::button>
        <x-filament::button
            color="primary"
            type="button"
            x-on:click="$dispatch('close-modal', { id: 'tala-auth-support-dialog' })"
        >
            Close
        </x-filament::button>
    </x-slot>
</x-filament::modal>

<x-filament::modal
    id="tala-auth-privacy-dialog"
    heading="Data Privacy Notice"
    width="2xl"
    footer-actions-alignment="end"
    :sticky-header="true"
    :sticky-footer="true"
    teleport="body"
    class="tala-auth-info-modal"
>
    <x-tala-privacy-notice-content />

    <x-slot name="footerActions">
        <x-filament::button
            color="gray"
            outlined
            type="button"
            x-on:click="$dispatch('close-modal', { id: 'tala-auth-privacy-dialog' }); $dispatch('open-modal', { id: 'tala-auth-support-dialog' })"
        >
            Contact school support
        </x-filament::button>
        <x-filament::button
            color="primary"
            type="button"
            x-on:click="$dispatch('close-modal', { id: 'tala-auth-privacy-dialog' })"
        >
            Close
        </x-filament::button>
    </x-slot>
</x-filament::modal>

<script data-navigate-once>
    (() => {
        if (window.__talaAuthInfoModalsInitialized) {
            return;
        }
        window.__talaAuthInfoModalsInitialized = true;

        const resolveModalKind = (trigger) => {
            const explicit = trigger.getAttribute('data-tala-open-auth-modal');
            if (explicit === 'support' || explicit === 'privacy') {
                return explicit;
            }

            const href = trigger.getAttribute('href') || '';
            if (href.includes('modal=privacy')) {
                return 'privacy';
            }
            if (href.includes('modal=support') || href.endsWith('#support')) {
                return 'support';
            }

            return null;
        };

        document.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            if (!target) {
                return;
            }

            const trigger = target.closest(
                '[data-tala-open-auth-modal], .tala-auth-main a[href*="modal=privacy"], .tala-auth-main a[href*="modal=support"], .tala-auth-main a[href$="#support"], .fi-simple-layout a[href*="modal=privacy"], .fi-simple-layout a[href*="modal=support"]'
            );

            if (!trigger) {
                return;
            }

            if (
                trigger.tagName === 'A'
                && (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey)
            ) {
                return;
            }

            const kind = resolveModalKind(trigger);
            if (!kind) {
                return;
            }

            const targetId = `tala-auth-${kind}-dialog`;
            const otherId = kind === 'support' ? 'tala-auth-privacy-dialog' : 'tala-auth-support-dialog';

            event.preventDefault();
            event.stopPropagation();

            window.dispatchEvent(new CustomEvent('close-modal', { detail: { id: otherId } }));
            window.dispatchEvent(new CustomEvent('open-modal', { detail: { id: targetId } }));
        }, true);
    })();
</script>
