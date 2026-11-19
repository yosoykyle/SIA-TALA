// Filament's theme-switcher buttons call `(theme = '...') && close()`, assuming an enclosing dropdown x-data scope.
// On standalone auth pages (.fi-auth-theme-switcher-wrapper), provide a no-op `close()` scope so Alpine never falls through to `window.close()`.
const ensureStandaloneThemeSwitcherCloseScope = () => {
    document.querySelectorAll('.fi-auth-theme-switcher-wrapper:not([x-data])').forEach((wrapper) => {
        wrapper.setAttribute('x-data', '{ close() {} }');
    });
};

// System-wide live validation cleanup: when a user edits a previously invalid field in any deferred Filament form
// and the input becomes natively valid (`checkValidity()`), clear its stale `.fi-invalid`, `aria-invalid`, and field error message immediately.
const clearResolvedFieldValidationState = (event) => {
    const target = event.target;
    if (
        ! (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement)
        || typeof target.checkValidity !== 'function'
        || ! target.checkValidity()
    ) {
        return;
    }

    const inputWrapper = target.closest('.fi-input-wrp');
    const fieldContainer = target.closest('.fi-fo-field, .fi-fo-field-wrp');

    if (! inputWrapper && ! fieldContainer) {
        return;
    }

    if (target.hasAttribute('aria-invalid')) {
        target.removeAttribute('aria-invalid');
    }

    inputWrapper?.classList.remove('fi-invalid');
    fieldContainer?.querySelectorAll('.fi-fo-field-wrp-error-message, .fi-fo-field-wrp-error-list, [data-tala-client-error]').forEach((errorNode) => {
        errorNode.remove();
    });
};

document.addEventListener('input', clearResolvedFieldValidationState, true);
document.addEventListener('change', clearResolvedFieldValidationState, true);

const extractCleanFieldLabel = (fieldContainer) => {
    const labelNode = fieldContainer?.querySelector('.fi-fo-field-label-content, .fi-fo-field-label');
    if (! labelNode) {
        return '';
    }

    const clone = labelNode.cloneNode(true);
    if (clone instanceof Element) {
        clone.querySelectorAll('.fi-fo-field-label-required-mark, sup').forEach((mark) => mark.remove());
    }

    return (clone.textContent ?? '').replace(/\*\s*$/, '').replace(/\s+/g, ' ').trim();
};

const resolveClientFieldErrorMessage = (control, fieldContainer, isAuthForm) => {
    const label = extractCleanFieldLabel(fieldContainer);
    const lowerLabel = label.toLowerCase();

    if (control.validity?.valueMissing) {
        if (control instanceof HTMLInputElement && control.type === 'checkbox') {
            return lowerLabel.includes('privacy')
                ? 'Acknowledge the Privacy Notice to continue.'
                : 'Check this box to continue.';
        }

        if (control instanceof HTMLSelectElement) {
            return lowerLabel !== ''
                ? `Select ${lowerLabel}.`
                : 'Select an option.';
        }

        if (lowerLabel !== '') {
            return isAuthForm
                ? `Enter your ${lowerLabel}.`
                : `Enter ${lowerLabel}.`;
        }

        return 'This field is required.';
    }

    if (control.validity?.typeMismatch && control instanceof HTMLInputElement && control.type === 'email') {
        return 'Enter a valid email address.';
    }

    if (control.validity?.rangeUnderflow && control instanceof HTMLInputElement && control.min !== '') {
        return label !== ''
            ? `${label} must be at least ${control.min}.`
            : `Must be at least ${control.min}.`;
    }

    if (control.validity?.rangeOverflow && control instanceof HTMLInputElement && control.max !== '') {
        return label !== ''
            ? `${label} must not exceed ${control.max}.`
            : `Must not exceed ${control.max}.`;
    }

    if (control.validity?.stepMismatch && control instanceof HTMLInputElement) {
        return lowerLabel !== ''
            ? `Enter a valid ${lowerLabel} value.`
            : 'Enter a valid value.';
    }

    if (control.validity?.patternMismatch && control instanceof HTMLInputElement) {
        return control.title || (lowerLabel !== '' ? `Enter a valid ${lowerLabel}.` : 'Enter a valid format.');
    }

    return control.validationMessage || 'Check this field.';
};

const renderClientFieldValidationError = (control, isAuthForm) => {
    control.setAttribute('aria-invalid', 'true');

    const inputWrapper = control.closest('.fi-input-wrp');
    inputWrapper?.classList.add('fi-invalid');

    const fieldContainer = control.closest('.fi-fo-field, .fi-fo-field-wrp');
    if (! fieldContainer) {
        return;
    }

    const contentCol = fieldContainer.querySelector('.fi-fo-field-content-col') ?? fieldContainer;
    let errorParagraph = contentCol.querySelector('.fi-fo-field-wrp-error-message');
    const message = resolveClientFieldErrorMessage(control, fieldContainer, isAuthForm);

    if (! errorParagraph) {
        errorParagraph = document.createElement('p');
        errorParagraph.className = 'fi-fo-field-wrp-error-message';
        errorParagraph.setAttribute('data-validation-error', '');
        errorParagraph.setAttribute('data-tala-client-error', 'true');
        contentCol.appendChild(errorParagraph);
    }

    errorParagraph.textContent = message;
};

// Dynamic auth header banner crossfade slideshow (`public/images/auth/*`):
// mounts `.tala-auth-slideshow` inside `.fi-auth-media-wrapper` beneath `.fi-auth-media-overlay`,
// preloads each photo before transitioning, pauses when tab is hidden, and disables motion under `prefers-reduced-motion: reduce`.
let authSlideshowTimer = null;
let mountedSlideshowNode = null;

const stopAuthBannerSlideshow = () => {
    if (authSlideshowTimer) {
        window.clearInterval(authSlideshowTimer);
        authSlideshowTimer = null;
    }
    mountedSlideshowNode = null;
};

const preloadSlideImage = (url) => new Promise((resolve) => {
    const probe = new Image();
    probe.decoding = 'async';
    probe.onload = () => resolve(true);
    probe.onerror = () => resolve(false);
    probe.src = url;
});

const setUpAuthBannerSlideshow = () => {
    const authMain = document.querySelector('.tala-auth-main');
    const slideshow = authMain?.querySelector('.tala-auth-slideshow[data-auth-slideshow]') ?? null;
    const mediaWrapper = authMain?.querySelector('.fi-auth-media-wrapper') ?? null;

    if (! authMain || ! slideshow || ! mediaWrapper) {
        stopAuthBannerSlideshow();
        return;
    }

    if (mountedSlideshowNode === slideshow && authSlideshowTimer !== null) {
        return;
    }

    stopAuthBannerSlideshow();

    let slides = [];
    try {
        const parsed = JSON.parse(slideshow.getAttribute('data-slides') ?? '[]');
        if (Array.isArray(parsed)) {
            slides = parsed.filter((url) => typeof url === 'string' && url.trim() !== '');
        }
    } catch {
        slides = [];
    }

    if (slides.length <= 1) {
        return;
    }

    const mediaOverlay = mediaWrapper.querySelector('.fi-auth-media-overlay');
    if (slideshow.parentElement !== mediaWrapper) {
        mediaWrapper.insertBefore(slideshow, mediaOverlay ?? null);
    }

    let slideElements = Array.from(slideshow.querySelectorAll('.tala-auth-slideshow__slide'));
    if (slideElements.length !== slides.length) {
        slideshow.replaceChildren();
        slideElements = slides.map((url, index) => {
            const img = document.createElement('img');
            img.className = index === 0
                ? 'tala-auth-slideshow__slide is-active'
                : 'tala-auth-slideshow__slide';
            img.src = url;
            img.alt = '';
            img.decoding = 'async';
            slideshow.appendChild(img);

            return img;
        });
    }

    mountedSlideshowNode = slideshow;

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    let currentIndex = 0;
    let transitioning = false;

    authSlideshowTimer = window.setInterval(async () => {
        if (
            document.hidden
            || transitioning
            || ! slideshow.isConnected
            || window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ) {
            return;
        }

        const nextIndex = (currentIndex + 1) % slideElements.length;
        transitioning = true;

        const loaded = await preloadSlideImage(slides[nextIndex]);
        transitioning = false;

        if (! loaded || ! slideshow.isConnected) {
            return;
        }

        slideElements[currentIndex]?.classList.remove('is-active');
        slideElements[nextIndex]?.classList.add('is-active');
        currentIndex = nextIndex;
    }, 12000);
};

// 3-state transient auth ambient choreography on `.tala-ambient--auth`:
// 1. `data-auth-error-pulse="peak"` (700ms swell) -> `data-auth-error-pulse="settle"` (600ms settle) -> removed (1.1s smooth CSS glide back to green/blue)
// 2. `data-auth-state="loading"` (full-card processing sweep while auth form commit is in flight)
// 3. `data-auth-state="success"` (2400ms soft celebratory emerald/mint/sky bloom on valid auth submission/redirect)
const AUTH_ERROR_SELECTOR = '.fi-fo-field-wrp-error-message, .fi-invalid, [aria-invalid="true"]';
let authErrorObserver = null;
let observedAuthMain = null;
let authErrorPeakTimer = null;
let authErrorSettleTimer = null;
let authStateTimer = null;
let lastAuthErrorState = false;
let pendingAuthSubmit = false;
let lastAuthPulseAt = 0;

const getAuthAmbientTargets = () => {
    const authMain = document.querySelector('.tala-auth-main');
    const backdrop = authMain?.querySelector('.tala-ambient--auth') ?? null;

    return { authMain, backdrop };
};

const clearAuthAmbientTimers = () => {
    if (authErrorPeakTimer) {
        window.clearTimeout(authErrorPeakTimer);
        authErrorPeakTimer = null;
    }

    if (authErrorSettleTimer) {
        window.clearTimeout(authErrorSettleTimer);
        authErrorSettleTimer = null;
    }

    if (authStateTimer) {
        window.clearTimeout(authStateTimer);
        authStateTimer = null;
    }
};

const triggerAuthErrorPulse = (backdrop) => {
    if (! backdrop || backdrop.getAttribute('data-auth-state') === 'success') {
        return;
    }

    const now = Date.now();
    if (now - lastAuthPulseAt < 120) {
        return;
    }
    lastAuthPulseAt = now;

    clearAuthAmbientTimers();
    backdrop.removeAttribute('data-auth-state');

    if (backdrop.getAttribute('data-auth-error-pulse') === 'peak') {
        backdrop.setAttribute('data-auth-error-pulse', 'settle');
        void backdrop.offsetWidth;
    }

    backdrop.setAttribute('data-auth-error-pulse', 'peak');

    authErrorPeakTimer = window.setTimeout(() => {
        if (backdrop.getAttribute('data-auth-error-pulse') === 'peak') {
            backdrop.setAttribute('data-auth-error-pulse', 'settle');
        }
        authErrorPeakTimer = null;
    }, 700);

    authErrorSettleTimer = window.setTimeout(() => {
        backdrop.removeAttribute('data-auth-error-pulse');
        authErrorSettleTimer = null;
    }, 1300);
};

const setAuthLoadingState = (backdrop) => {
    if (! backdrop) {
        return;
    }

    clearAuthAmbientTimers();
    backdrop.removeAttribute('data-auth-error-pulse');
    backdrop.setAttribute('data-auth-state', 'loading');
};

const setAuthSuccessState = (backdrop) => {
    if (! backdrop) {
        return;
    }

    clearAuthAmbientTimers();
    backdrop.removeAttribute('data-auth-error-pulse');
    backdrop.setAttribute('data-auth-state', 'success');

    authStateTimer = window.setTimeout(() => {
        if (backdrop.getAttribute('data-auth-state') === 'success') {
            backdrop.removeAttribute('data-auth-state');
        }
        authStateTimer = null;
    }, 2400);
};

const syncAuthAmbientErrorState = (forcePulse = false) => {
    const { authMain, backdrop } = getAuthAmbientTargets();

    if (! authMain || ! backdrop) {
        lastAuthErrorState = false;
        pendingAuthSubmit = false;
        return;
    }

    if (backdrop.getAttribute('data-auth-state') === 'success') {
        return;
    }

    const hasError = authMain.querySelector(AUTH_ERROR_SELECTOR) !== null;

    if (hasError && (! lastAuthErrorState || forcePulse || pendingAuthSubmit)) {
        pendingAuthSubmit = false;
        triggerAuthErrorPulse(backdrop);
    }

    lastAuthErrorState = hasError;
};

const setUpAuthAmbientErrorWatcher = () => {
    ensureStandaloneThemeSwitcherCloseScope();
    setUpAuthBannerSlideshow();

    const { authMain } = getAuthAmbientTargets();

    if (! authMain) {
        if (authErrorObserver) {
            authErrorObserver.disconnect();
            authErrorObserver = null;
        }
        clearAuthAmbientTimers();
        observedAuthMain = null;
        lastAuthErrorState = false;
        pendingAuthSubmit = false;
        return;
    }

    if (observedAuthMain !== authMain) {
        if (authErrorObserver) {
            authErrorObserver.disconnect();
        }
        observedAuthMain = authMain;
        lastAuthErrorState = false;
        authErrorObserver = new MutationObserver(() => {
            syncAuthAmbientErrorState(false);
        });
        authErrorObserver.observe(authMain, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class', 'aria-invalid'],
        });
    }

    syncAuthAmbientErrorState(false);
};

setUpAuthAmbientErrorWatcher();
document.addEventListener('DOMContentLoaded', setUpAuthAmbientErrorWatcher);
document.addEventListener('alpine:init', ensureStandaloneThemeSwitcherCloseScope);
document.addEventListener('livewire:navigated', setUpAuthAmbientErrorWatcher);

// 0ms instant client-side validation for forms inside `.fi-body`:
// ensures Filament page and modal forms carry `novalidate` before submission so native browser popups do not swallow submit events,
// blocks futile Livewire round-trips on empty/malformed required inputs, renders inline Filament field errors,
// focuses the first invalid control, and fires the auth ambient error pulse immediately when inside `.tala-auth-main`.
const ensureFilamentFormsNoValidate = (root = document) => {
    if (! (root instanceof Document || root instanceof Element)) {
        return;
    }

    root.querySelectorAll('.fi-body form:not([novalidate])').forEach((form) => {
        if (form instanceof HTMLFormElement) {
            form.setAttribute('novalidate', '');
        }
    });
};

ensureFilamentFormsNoValidate();
document.addEventListener('DOMContentLoaded', () => ensureFilamentFormsNoValidate());
document.addEventListener('livewire:navigated', () => ensureFilamentFormsNoValidate());
document.addEventListener('focusin', (event) => {
    const target = event.target;
    if (target instanceof HTMLElement) {
        const form = target.closest('.fi-body form') ?? (target.getAttribute('form') ? document.getElementById(target.getAttribute('form')) : null);
        if (form instanceof HTMLFormElement && ! form.hasAttribute('novalidate')) {
            form.setAttribute('novalidate', '');
        }
    }
}, true);
document.addEventListener('pointerdown', (event) => {
    const target = event.target;
    if (target instanceof HTMLElement) {
        const button = target.closest('button[type="submit"], input[type="submit"]');
        const formId = button?.getAttribute('form');
        const form = (formId ? document.getElementById(formId) : null) ?? target.closest('.fi-body form');
        if (form instanceof HTMLFormElement && ! form.hasAttribute('novalidate')) {
            form.setAttribute('novalidate', '');
        }
    }
}, true);

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (! (form instanceof HTMLFormElement)) {
        return;
    }

    const isAuthForm = form.closest('.tala-auth-main') !== null;
    const isFilamentBodyForm = isAuthForm || form.closest('.fi-body') !== null;
    if (! isFilamentBodyForm) {
        return;
    }

    const bypassValidation = event.submitter instanceof HTMLElement && event.submitter.hasAttribute('formnovalidate');

    if (! bypassValidation && form.hasAttribute('novalidate') && ! form.checkValidity()) {
        event.preventDefault();
        event.stopImmediatePropagation();

        const invalidControls = Array.from(form.querySelectorAll('input, textarea, select')).filter((control) => (
            (control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement || control instanceof HTMLSelectElement)
            && ! control.disabled
            && control.type !== 'hidden'
            && typeof control.checkValidity === 'function'
            && ! control.checkValidity()
        ));

        invalidControls.forEach((control) => {
            renderClientFieldValidationError(control, isAuthForm);
        });

        invalidControls[0]?.focus();

        if (isAuthForm) {
            const { backdrop } = getAuthAmbientTargets();
            pendingAuthSubmit = false;
            lastAuthErrorState = true;
            triggerAuthErrorPulse(backdrop);
        }

        return;
    }

    if (isAuthForm) {
        const { backdrop } = getAuthAmbientTargets();
        if (! form.checkValidity()) {
            pendingAuthSubmit = false;
            triggerAuthErrorPulse(backdrop);
            return;
        }

        pendingAuthSubmit = true;
        setAuthLoadingState(backdrop);
    }
}, true);

document.addEventListener('invalid', (event) => {
    if (event.target instanceof Element && event.target.closest('.tala-auth-main')) {
        const { backdrop } = getAuthAmbientTargets();
        pendingAuthSubmit = false;
        lastAuthErrorState = true;
        triggerAuthErrorPulse(backdrop);
    }
}, true);

const snapshotHasValidationErrors = (snapshot) => {
    try {
        const parsed = typeof snapshot === 'string' ? JSON.parse(snapshot) : snapshot;
        const errors = parsed?.memo?.errors;

        return Boolean(errors && typeof errors === 'object' && Object.keys(errors).length > 0);
    } catch {
        return null;
    }
};

const AUTH_SUBMIT_METHODS = new Set([
    'authenticate',
    'request',
    'resetPassword',
    'register',
    'sendEmailVerificationNotification',
]);

const isAuthSubmitCall = (call) => {
    if (! call || typeof call !== 'object') {
        return false;
    }

    if (typeof call.method === 'string' && AUTH_SUBMIT_METHODS.has(call.method)) {
        return true;
    }

    const params = Array.isArray(call.params) ? call.params : [];

    return params.some((param) => typeof param === 'string' && /resend/i.test(param));
};

document.addEventListener('livewire:init', () => {
    window.Livewire?.hook('commit', ({ component, commit, succeed, fail }) => {
        const isInsideAuthMain = component?.el instanceof Element && component.el.closest('.tala-auth-main') !== null;
        if (! isInsideAuthMain) {
            return;
        }

        const { backdrop } = getAuthAmbientTargets();
        const isActionCommit = pendingAuthSubmit || (Array.isArray(commit?.calls) && commit.calls.some(isAuthSubmitCall));

        if (isActionCommit) {
            setAuthLoadingState(backdrop);
        }

        succeed(({ snapshot, effect }) => {
            const { authMain: currentAuthMain, backdrop: currentBackdrop } = getAuthAmbientTargets();
            if (! currentAuthMain || ! currentBackdrop) {
                pendingAuthSubmit = false;
                return;
            }

            const hasRedirect = Boolean(effect && effect.redirect);
            if (hasRedirect) {
                pendingAuthSubmit = false;
                lastAuthErrorState = false;
                setAuthSuccessState(currentBackdrop);
                return;
            }

            const serverHasErrors = snapshotHasValidationErrors(snapshot);
            if (serverHasErrors === true) {
                pendingAuthSubmit = false;
                lastAuthErrorState = true;
                triggerAuthErrorPulse(currentBackdrop);
                return;
            }

            if (serverHasErrors === false && isActionCommit) {
                pendingAuthSubmit = false;
                lastAuthErrorState = false;
                setAuthSuccessState(currentBackdrop);
                return;
            }

            window.requestAnimationFrame(() => {
                const { authMain: nextAuthMain, backdrop: nextBackdrop } = getAuthAmbientTargets();
                if (! nextAuthMain || ! nextBackdrop || nextBackdrop.getAttribute('data-auth-state') === 'success') {
                    pendingAuthSubmit = false;
                    return;
                }

                const hasError = nextAuthMain.querySelector(AUTH_ERROR_SELECTOR) !== null;
                lastAuthErrorState = hasError;

                if (hasError) {
                    pendingAuthSubmit = false;
                    triggerAuthErrorPulse(nextBackdrop);
                } else if (isActionCommit) {
                    pendingAuthSubmit = false;
                    setAuthSuccessState(nextBackdrop);
                } else if (nextBackdrop.getAttribute('data-auth-state') === 'loading') {
                    nextBackdrop.removeAttribute('data-auth-state');
                }
            });
        });

        fail(() => {
            pendingAuthSubmit = false;
            const { backdrop: nextBackdrop } = getAuthAmbientTargets();
            triggerAuthErrorPulse(nextBackdrop);
        });
    });
});

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
