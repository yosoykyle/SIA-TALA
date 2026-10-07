/* TALA public landing interactions. Requires the locally served Bootstrap 5.3 bundle. */

document.addEventListener('DOMContentLoaded', () => {
    const colorScheme = window.matchMedia('(prefers-color-scheme: dark)');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const forcedColors = window.matchMedia('(forced-colors: active)');
    const scrollButton = document.querySelector('.btn-scroll-top');
    const skipLink = document.querySelector('.tala-skip-link');
    const trackingSection = document.getElementById('application-status');
    const trackingForm = trackingSection?.querySelector('form');
    const hero = document.querySelector('[data-hero-motion]');
    const heroMotionToggle = hero?.querySelector('[data-hero-motion-toggle]');

    skipLink?.addEventListener('click', () => {
        window.requestAnimationFrame(() => {
            document.getElementById(skipLink.hash.slice(1))?.focus({ preventScroll: true });
        });
    });

    if (scrollButton) {
        const interactiveRegions = [trackingForm, document.querySelector('iframe.institution-map')].filter(Boolean);
        const updateScrollButton = () => {
            const buttonBounds = scrollButton.getBoundingClientRect();
            const buttonBottom = window.innerHeight - parseFloat(window.getComputedStyle(scrollButton).bottom);
            const overlapsInteractiveRegion = interactiveRegions.some((region) => {
                const regionBounds = region.getBoundingClientRect();

                return regionBounds.right > buttonBounds.left
                    && regionBounds.left < buttonBounds.right
                    && regionBounds.bottom > buttonBottom - buttonBounds.height
                    && regionBounds.top < buttonBottom;
            });

            scrollButton.classList.toggle('visible', window.scrollY > 300 && !overlapsInteractiveRegion);
        };

        scrollButton.addEventListener('click', () => {
            document.getElementById('main-content')?.focus({ preventScroll: true });
            window.scrollTo({
                top: 0,
                behavior: reducedMotion.matches ? 'auto' : 'smooth'
            });
        });

        window.addEventListener('scroll', updateScrollButton, { passive: true });
        window.addEventListener('resize', updateScrollButton);
        updateScrollButton();
    }

    const normalizeReference = (value, isFinal = false) => {
        const normalized = value
            .normalize('NFKC')
            .toUpperCase()
            .replace(/["'“”‘’()]/g, '')
            .replace(/[‐-―−﹘﹣－]/g, '-')
            .replace(/\s+/g, '-')
            .replace(/-{2,}/g, '-');

        return isFinal ? normalized.replace(/^-+/, '').replace(/[-.,;:]+$/, '') : normalized.replace(/^-+/, '');
    };

    trackingSection?.querySelectorAll('[data-reference-input]').forEach((input) => {
        const applyReferenceFormat = (event) => {
            const normalized = normalizeReference(input.value, event?.type === 'blur');

            if (normalized !== input.value) {
                const caretFromEnd = input.value.length - (input.selectionEnd ?? input.value.length);
                input.value = normalized;

                if (document.activeElement === input && input.setSelectionRange) {
                    const caret = Math.max(0, normalized.length - caretFromEnd);
                    input.setSelectionRange(caret, caret);
                }
            }
        };

        input.addEventListener('input', applyReferenceFormat);
        input.addEventListener('blur', applyReferenceFormat);
    });

    trackingSection?.querySelectorAll('[data-trim-input]').forEach((input) => {
        input.addEventListener('blur', () => {
            input.value = input.value.trim();
        });
    });

    if (trackingForm) {
        const submitButton = trackingForm.querySelector('[data-tracking-submit]');
        const submitLabel = submitButton?.querySelector('[data-tracking-submit-label]');
        const idleLabel = submitLabel?.textContent ?? '';
        const resetSubmit = () => {
            delete trackingForm.dataset.submitting;
            submitButton?.removeAttribute('aria-disabled');
            submitButton?.classList.remove('is-sending');

            if (submitLabel) {
                submitLabel.textContent = idleLabel;
            }
        };

        trackingForm.addEventListener('submit', (event) => {
            if (trackingForm.dataset.submitting === 'true') {
                event.preventDefault();

                return;
            }

            trackingForm.querySelectorAll('[data-reference-input]').forEach((input) => {
                input.value = normalizeReference(input.value, true);
            });
            trackingForm.querySelectorAll('[data-trim-input]').forEach((input) => {
                input.value = input.value.trim();
            });
            trackingForm.dataset.submitting = 'true';
            submitButton?.setAttribute('aria-disabled', 'true');
            submitButton?.classList.add('is-sending');

            if (submitLabel) {
                submitLabel.textContent = 'Sending request…';
            }
        });

        window.addEventListener('pageshow', resetSubmit);
    }

    const trackingConfirmation = trackingSection?.querySelector('[data-tracking-confirmation]');
    const trackingInvalidField = trackingSection?.querySelector('[aria-invalid="true"]');

    const trackingFocusTarget = trackingInvalidField ?? trackingConfirmation;

    if (trackingFocusTarget) {
        const focusTrackingResult = () => {
            if (document.activeElement !== trackingFocusTarget) {
                trackingFocusTarget.focus();
            }
        };

        focusTrackingResult();
        window.addEventListener('load', () => {
            const restoreTrackingFocus = () => {
                const active = document.activeElement;

                if (!active || active === document.body || active === document.documentElement || active === trackingSection) {
                    focusTrackingResult();
                }
            };

            if (window.requestAnimationFrame) {
                window.requestAnimationFrame(restoreTrackingFocus);
            } else {
                restoreTrackingFocus();
            }
        }, { once: true });
    }

    if (hero && heroMotionToggle) {
        const heroMotionLabel = heroMotionToggle.querySelector('[data-hero-motion-label]');
        const setHeroMotionPaused = (paused) => {
            hero.classList.toggle('is-motion-paused', paused);
            heroMotionToggle.setAttribute('aria-pressed', paused ? 'true' : 'false');

            if (heroMotionLabel) {
                heroMotionLabel.textContent = paused ? 'Play motion' : 'Pause motion';
            }
        };

        heroMotionToggle.addEventListener('click', () => {
            setHeroMotionPaused(heroMotionToggle.getAttribute('aria-pressed') !== 'true');
        });

        hero.classList.add('is-motion-enhanced');

        if ('IntersectionObserver' in window) {
            new window.IntersectionObserver(([entry]) => {
                hero.classList.toggle('is-offscreen', !entry.isIntersecting);
            }).observe(hero);
        }
    }

    const updates = document.querySelector('[data-hero-updates]');
    const updateSlides = updates ? [...updates.querySelectorAll('[data-update-slide]')] : [];

    if (updates && updateSlides.length > 1) {
        const stack = updates.querySelector('[data-hero-updates-stack]');
        const position = updates.querySelector('[data-update-position]');
        let activeIndex = 0;
        let isPointerWithin = false;
        let isFocusWithin = false;

        const showUpdate = (nextIndex, direction = 1) => {
            const previous = updateSlides[activeIndex];
            activeIndex = (nextIndex + updateSlides.length) % updateSlides.length;
            updates.classList.toggle('is-reverse', direction < 0);

            updateSlides.forEach((slide, index) => {
                const isActive = index === activeIndex;
                slide.classList.toggle('is-active', isActive);
                slide.classList.toggle('is-leaving', slide === previous && !isActive);
                slide.setAttribute('aria-hidden', isActive ? 'false' : 'true');
                slide.inert = !isActive;
            });

            if (position) {
                position.textContent = `${activeIndex + 1} of ${updateSlides.length}`;
            }
        };

        const canAdvanceAutomatically = () => !reducedMotion.matches
            && !forcedColors.matches
            && !isPointerWithin
            && !isFocusWithin
            && !document.hidden
            && !hero?.classList.contains('is-motion-paused')
            && !hero?.classList.contains('is-offscreen');

        const syncLiveRegion = () => {
            stack?.setAttribute('aria-live', canAdvanceAutomatically() ? 'off' : 'polite');
        };

        updates.classList.add('is-enhanced');
        showUpdate(0);
        updates.querySelector('[data-update-previous]')?.addEventListener('click', () => showUpdate(activeIndex - 1, -1));
        updates.querySelector('[data-update-next]')?.addEventListener('click', () => showUpdate(activeIndex + 1, 1));
        updates.addEventListener('pointerenter', () => { isPointerWithin = true; syncLiveRegion(); });
        updates.addEventListener('pointerleave', () => { isPointerWithin = false; syncLiveRegion(); });
        updates.addEventListener('focusin', () => { isFocusWithin = true; syncLiveRegion(); });
        updates.addEventListener('focusout', (event) => {
            isFocusWithin = updates.contains(event.relatedTarget);
            syncLiveRegion();
        });
        heroMotionToggle?.addEventListener('click', syncLiveRegion);
        forcedColors.addEventListener('change', syncLiveRegion);
        reducedMotion.addEventListener('change', syncLiveRegion);
        syncLiveRegion();

        window.setInterval(() => {
            syncLiveRegion();

            if (canAdvanceAutomatically()) {
                showUpdate(activeIndex + 1, 1);
            }
        }, 7000);

        const revealAnnouncements = () => {
            if (window.location.hash === '#notices') {
                showUpdate(1, 1);
            }
        };

        window.addEventListener('hashchange', revealAnnouncements);
        document.querySelectorAll('a[href="#notices"]').forEach((link) => {
            link.addEventListener('click', () => showUpdate(1, 1));
        });
        revealAnnouncements();
    }

    const requestedModal = new URLSearchParams(window.location.search).get('modal');

    if (['privacy', 'accessibility', 'support'].includes(requestedModal) && window.bootstrap?.Modal) {
        const modalElement = document.getElementById(`${requestedModal}Modal`);

        if (modalElement) {
            window.bootstrap.Modal.getOrCreateInstance(modalElement).show();

            modalElement.addEventListener('hidden.bs.modal', () => {
                const currentUrl = new URL(window.location.href);
                currentUrl.searchParams.delete('modal');
                window.history.replaceState({}, '', `${currentUrl.pathname}${currentUrl.search}${currentUrl.hash}`);
            }, { once: true });
        }
    }

    const navbar = document.querySelector('.navbar');
    const navigation = document.getElementById('navbarNav');
    const navigationToggle = navbar?.querySelector('.navbar-toggler');

    const updateNavbarHeight = () => {
        const height = navbar?.getBoundingClientRect().height;

        if (height) {
            document.documentElement.style?.setProperty('--tala-navbar-height', `${height}px`);
        }
    };

    updateNavbarHeight();
    window.addEventListener('resize', updateNavbarHeight);

    if (navigation && navigationToggle && window.bootstrap?.Collapse) {
        const closeNavigation = () => window.bootstrap.Collapse.getOrCreateInstance(navigation, { toggle: false }).hide();
        let navigationDestination = null;

        navigation.addEventListener('shown.bs.collapse', () => {
            updateNavbarHeight();
            navigationToggle.setAttribute('aria-label', 'Close navigation menu');
            navigation.querySelector('a[href]')?.focus({ preventScroll: true });
        });
        navigation.addEventListener('hidden.bs.collapse', () => {
            updateNavbarHeight();
            navigationToggle.setAttribute('aria-label', 'Open navigation menu');

            if (navigationDestination) {
                navigationDestination.setAttribute('tabindex', '-1');
                navigationDestination.focus({ preventScroll: true });
                navigationDestination = null;
            } else if (navigation.contains(document.activeElement)) {
                navigationToggle.focus({ preventScroll: true });
            }
        });
        navigation.addEventListener('click', (event) => {
            const link = event.target.closest('a[href^="#"]');

            if (!link || !navigation.classList.contains('show')) {
                return;
            }

            navigationDestination = document.getElementById(link.hash.slice(1));

            closeNavigation();
        });
        navbar.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && navigation.classList.contains('show') && !navbar.querySelector('.dropdown-menu.show')) {
                event.preventDefault();
                closeNavigation();
                navigationToggle.focus();
            }
        });
    }

    const navigationLinks = navigation ? [...navigation.querySelectorAll('a[href^="#"]')] : [];
    const pageSections = [...document.querySelectorAll('main > section[id]')];
    const updateActiveNavigation = () => {
        const boundary = (navbar?.getBoundingClientRect().bottom ?? 0) + 40;
        const currentSection = pageSections.filter((section) => section.getBoundingClientRect().top <= boundary).at(-1)
            ?? pageSections[0];
        const currentHash = currentSection?.id === 'top' && window.location.hash === '#notices'
            ? '#notices'
            : currentSection ? `#${currentSection.id}` : '';

        navigationLinks.forEach((link) => {
            if (link.hash === currentHash) {
                link.setAttribute('aria-current', 'location');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    };

    const contrastTargets = navbar
        ? navbar.querySelectorAll('[data-navbar-contrast-target]')
        : [];
    let scheduleNavbarContrastUpdate = () => {};

    if (navbar && contrastTargets.length && document.elementsFromPoint) {
        let animationFrame = null;

        const foregroundFor = (target) => {
            const bounds = target.getBoundingClientRect();

            if (bounds.width === 0 || bounds.height === 0) {
                return null;
            }

            const sampleX = Math.min(
                window.innerWidth - 1,
                Math.max(0, bounds.left + (bounds.width / 2))
            );
            const sampleY = Math.min(
                window.innerHeight - 1,
                Math.max(0, bounds.top + (bounds.height / 2))
            );
            const surface = document.elementsFromPoint(sampleX, sampleY)
                .filter((element) => !navbar.contains(element))
                .map((element) => element.closest('iframe.institution-map, [data-navbar-contrast-surface]'))
                .find((element) => element);
            const surfaceTone = surface?.matches('iframe.institution-map')
                ? 'light'
                : surface?.getAttribute('data-navbar-contrast-surface') ?? 'dark';
            const isLightSurface = surfaceTone === 'light'
                || (
                    surfaceTone === 'theme'
                    && document.documentElement.getAttribute('data-bs-theme') !== 'dark'
                );

            return isLightSurface ? 'black' : 'white';
        };

        const updateNavbarContrast = () => {
            updateActiveNavigation();
            contrastTargets.forEach((target) => {
                const foreground = foregroundFor(target);

                if (foreground) {
                    target.setAttribute('data-navbar-foreground', foreground);
                }
            });
        };

        scheduleNavbarContrastUpdate = () => {
            if (animationFrame !== null) {
                return;
            }

            animationFrame = window.requestAnimationFrame(() => {
                animationFrame = null;
                updateNavbarContrast();
            });
        };

        window.addEventListener('load', scheduleNavbarContrastUpdate);
        window.addEventListener('resize', scheduleNavbarContrastUpdate);
        window.addEventListener('scroll', scheduleNavbarContrastUpdate, { passive: true });
        navbar.addEventListener('shown.bs.collapse', scheduleNavbarContrastUpdate);
        navbar.addEventListener('hidden.bs.collapse', scheduleNavbarContrastUpdate);
        navbar.addEventListener('shown.bs.dropdown', scheduleNavbarContrastUpdate);
        navbar.addEventListener('hidden.bs.dropdown', scheduleNavbarContrastUpdate);
        scheduleNavbarContrastUpdate();
    }

    updateActiveNavigation();
    window.addEventListener('hashchange', updateActiveNavigation);
    window.addEventListener('scroll', updateActiveNavigation, { passive: true });

    colorScheme.addEventListener('change', (event) => {
        document.documentElement.setAttribute('data-bs-theme', event.matches ? 'dark' : 'light');
        scheduleNavbarContrastUpdate();
    });
});
