<div
    class="sr-only"
    role="status"
    aria-live="polite"
    aria-atomic="true"
    x-data="{
        announcement: '',
        content: null,
        observer: null,
        frame: null,
        focusTimer: null,
        pendingFocus: null,
        owned: new Map(),
        init() {
            this.content = this.$el.closest('.fi-main');
            if (! this.content) return;
            this.observer = new MutationObserver((changes) => {
                if (changes.some((change) => ! this.$el.contains(change.target))) this.schedule();
            });
            this.observer.observe(this.content, { childList: true, subtree: true });
            this.schedule();
        },
        visible(control) {
            return control.getClientRects().length > 0 && ! control.disabled && ! control.closest('[inert], [aria-hidden=true]');
        },
        component(control) {
            return control.closest('[wire\\:id]');
        },
        release(control, owned) {
            const descriptions = (control.getAttribute('aria-describedby') || '').split(/\s+/).filter((id) => id && (! owned.description || id !== owned.errorId));
            if (descriptions.length) control.setAttribute('aria-describedby', descriptions.join(' '));
            else control.removeAttribute('aria-describedby');
            if (control.getAttribute('aria-invalid') === 'true') {
                if (owned.invalid === null) control.removeAttribute('aria-invalid');
                else if (owned.invalid !== 'true') control.setAttribute('aria-invalid', owned.invalid);
            }
            this.owned.delete(control);
        },
        sync() {
            const invalid = [];
            const current = new Set();
            for (const wrapper of this.content.querySelectorAll('[data-field-wrapper]')) {
                const error = Array.from(wrapper.querySelectorAll('[data-validation-error]')).find((node) => node.closest('[data-field-wrapper]') === wrapper);
                if (! error || ! error.textContent.trim()) continue;
                const control = Array.from(wrapper.querySelectorAll('.fi-select-input-btn, input:not([type=hidden]), select, textarea, [role=switch]')).find((node) => node.closest('[data-field-wrapper]') === wrapper && node.id && this.visible(node));
                if (! control) continue;
                const errorId = error.id || control.id + '-tala-error';
                const prior = this.owned.get(control);
                if (prior && prior.errorId !== errorId) this.release(control, prior);
                error.id = errorId;
                const descriptions = new Set((control.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
                if (! this.owned.has(control)) this.owned.set(control, { errorId, invalid: control.getAttribute('aria-invalid'), description: ! descriptions.has(errorId) });
                descriptions.add(errorId);
                control.setAttribute('aria-describedby', Array.from(descriptions).join(' '));
                control.setAttribute('aria-invalid', 'true');
                current.add(control);
                invalid.push(control);
            }
            for (const [control, owned] of this.owned) {
                if (! current.has(control)) this.release(control, owned);
            }
            if (! invalid.length) this.announcement = '';
            return invalid;
        },
        schedule() {
            if (this.frame !== null) return;
            this.frame = requestAnimationFrame(() => {
                this.frame = null;
                if (this.$el.isConnected) this.focusInvalid(this.sync());
            });
        },
        focusInvalid(invalid) {
            if (! this.pendingFocus || Date.now() < this.pendingFocus.after) return;
            if (Date.now() > this.pendingFocus.until) {
                this.pendingFocus = null;
                return;
            }
            const control = invalid.find((node) => this.component(node)?.getAttribute('wire:id') === this.pendingFocus.livewireId);
            if (! control) return;
            this.pendingFocus = null;
            control.focus({ preventScroll: true });
            this.announcement = 'Please correct the highlighted fields.';
        },
        validationFailed(event) {
            const livewireId = event.detail?.livewireId;
            if (! livewireId || ! this.content) return;
            if (! Array.from(this.content.querySelectorAll('[wire\\:id]')).some((node) => node.getAttribute('wire:id') === livewireId)) return;
            clearTimeout(this.focusTimer);
            this.announcement = '';
            this.pendingFocus = { livewireId, after: Date.now() + 250, until: Date.now() + 1500 };
            this.$nextTick(() => {
                this.focusTimer = setTimeout(() => {
                    this.focusInvalid(this.sync());
                }, 250);
            });
        },
        destroy() {
            this.observer?.disconnect();
            if (this.frame !== null) cancelAnimationFrame(this.frame);
            clearTimeout(this.focusTimer);
            for (const [control, owned] of this.owned) this.release(control, owned);
        }
    }"
    x-on:form-validation-error.window="validationFailed($event)"
    x-text="announcement"
></div>
