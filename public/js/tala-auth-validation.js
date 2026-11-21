(() => {
    if (window.__talaAuthValidationInitialized) {
        return;
    }
    window.__talaAuthValidationInitialized = true;

    const AUTH_FORM_SELECTOR = '.tala-auth-main form';
    const CONTROL_SELECTOR = 'input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="reset"]), select, textarea';
    const CLIENT_ERROR_ATTR = 'data-tala-client-error';

    const isVisibleControl = (control) => {
        if (control.disabled || control.readOnly) {
            return false;
        }
        if (control.closest('[hidden], [inert], [aria-hidden="true"]')) {
            return false;
        }
        return control.getClientRects().length > 0;
    };

    const getFieldWrapper = (control) => control.closest('[data-field-wrapper]') || control.closest('.fi-fo-field-wrp');

    const getFieldLabel = (control) => {
        const wrapper = getFieldWrapper(control);
        const labelNode = wrapper?.querySelector('label .fi-fo-field-label-content, label');
        const raw = (labelNode?.textContent || control.getAttribute('aria-label') || control.name || 'this')
            .replace(/\*/g, '')
            .replace(/\s+/g, ' ')
            .trim();
        return raw ? raw.toLowerCase() : 'this';
    };

    const linkErrorId = (control, errorId, attach) => {
        if (!errorId) {
            return;
        }
        const ids = (control.getAttribute('aria-describedby') || '')
            .split(/\s+/)
            .filter((id) => id && id !== errorId);
        if (attach) {
            ids.push(errorId);
        }
        if (ids.length > 0) {
            control.setAttribute('aria-describedby', ids.join(' '));
        } else {
            control.removeAttribute('aria-describedby');
        }
    };

    const getErrorMessage = (control) => {
        const validity = control.validity;
        if (!validity || validity.valid) {
            return null;
        }
        const label = getFieldLabel(control);
        if (validity.valueMissing) {
            if (control.type === 'checkbox') {
                return 'Please confirm this requirement before continuing.';
            }
            return `The ${label} field is required.`;
        }
        if (validity.typeMismatch && control.type === 'email') {
            return `The ${label} field must be a valid email address.`;
        }
        if (validity.tooShort && control.minLength > 0) {
            return `The ${label} field must be at least ${control.minLength} characters.`;
        }
        if (validity.tooLong && control.maxLength > 0) {
            return `The ${label} field must not exceed ${control.maxLength} characters.`;
        }
        return control.validationMessage || `Please enter a valid ${label}.`;
    };

    const clearClientError = (control) => {
        const wrapper = getFieldWrapper(control);
        if (!wrapper) {
            return;
        }
        const existing = wrapper.querySelector(`[${CLIENT_ERROR_ATTR}="true"]`);
        if (existing) {
            linkErrorId(control, existing.id, false);
            existing.remove();
        }
        if (control.dataset.talaClientInvalid === 'true') {
            delete control.dataset.talaClientInvalid;
            control.setAttribute('aria-invalid', 'false');
            const inputWrp = control.closest('.fi-input-wrp');
            if (inputWrp && !wrapper.querySelector(`[data-validation-error]:not([${CLIENT_ERROR_ATTR}="true"])`)) {
                inputWrp.classList.remove('fi-invalid');
            }
        }
    };

    const showClientError = (control, message) => {
        const wrapper = getFieldWrapper(control);
        if (!wrapper) {
            return;
        }

        wrapper.querySelectorAll(`[data-validation-error]:not([${CLIENT_ERROR_ATTR}="true"])`).forEach((node) => {
            node.remove();
        });

        let errorEl = wrapper.querySelector(`[${CLIENT_ERROR_ATTR}="true"]`);
        if (!errorEl) {
            errorEl = document.createElement('p');
            errorEl.className = 'fi-fo-field-wrp-error-message';
            errorEl.setAttribute('data-validation-error', '');
            errorEl.setAttribute(CLIENT_ERROR_ATTR, 'true');
            if (control.id) {
                errorEl.id = `${control.id}-tala-client-error`;
            }
            const contentHost = wrapper.querySelector('.fi-fo-field-content-col') || wrapper;
            contentHost.appendChild(errorEl);
        }

        errorEl.textContent = message;
        control.dataset.talaClientInvalid = 'true';
        control.setAttribute('aria-invalid', 'true');
        linkErrorId(control, errorEl.id, true);

        const inputWrp = control.closest('.fi-input-wrp');
        if (inputWrp) {
            inputWrp.classList.add('fi-invalid');
        }
    };

    // Capture phase runs BEFORE Livewire's wire:submit listener on <form>, giving
    // instant client-side feedback on submit with zero server roundtrip lag while
    // keeping fields completely calm while typing or tabbing prior to submission.
    document.addEventListener('submit', (event) => {
        const form = event.target instanceof HTMLFormElement ? event.target : null;
        if (!form || !form.matches(AUTH_FORM_SELECTOR)) {
            return;
        }

        const controls = Array.from(form.querySelectorAll(CONTROL_SELECTOR)).filter(isVisibleControl);
        let firstInvalid = null;

        for (const control of controls) {
            const message = getErrorMessage(control);
            if (message !== null) {
                showClientError(control, message);
                if (!firstInvalid) {
                    firstInvalid = control;
                }
            } else {
                clearClientError(control);
            }
        }

        form.dataset.talaSubmitted = 'true';

        if (firstInvalid) {
            event.preventDefault();
            event.stopImmediatePropagation();
            firstInvalid.focus({ preventScroll: false });
        }
    }, true);

    // Clear client error state immediately on input/change once the value becomes valid.
    const handleRecovery = (event) => {
        const control = event.target;
        if (!(control instanceof HTMLElement) || !control.matches(CONTROL_SELECTOR)) {
            return;
        }
        if (!control.closest(AUTH_FORM_SELECTOR)) {
            return;
        }
        if (control.dataset.talaClientInvalid === 'true' && control.validity?.valid) {
            clearClientError(control);
        }
    };

    document.addEventListener('input', handleRecovery, true);
    document.addEventListener('change', handleRecovery, true);
})();
