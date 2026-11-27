/* TALA public landing interactions. Requires the locally served Bootstrap 5.3 bundle. */

const initPublicLanding = () => {
    if (document.documentElement?.dataset?.talaLandingInitialized === 'true') {
        return;
    }

    if (document.documentElement?.dataset) {
        document.documentElement.dataset.talaLandingInitialized = 'true';
    }

    const colorScheme = window.matchMedia('(prefers-color-scheme: dark)');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const forcedColors = window.matchMedia('(forced-colors: active)');
    const scrollButton = document.querySelector('.btn-scroll-top');
    const skipLink = document.querySelector('.tala-skip-link');
    const ambientStage = document.querySelector?.('.landing-ambient-stage') ?? null;
    const bottomBlurStrip = document.querySelector?.('.bottom-blur-strip') ?? null;
    const heroContainer = document.querySelector?.('.hero-section .hero-container') ?? null;
    const nowMs = () => window.performance?.now?.() ?? Date.now();
    let isSheetDragging = false;
    let scheduleScrollButtonUpdate = () => {};
    let scheduleScrollProgress = () => {};
    let scheduleNavbarContrastUpdate = () => {};

    skipLink?.addEventListener('click', () => {
        window.requestAnimationFrame(() => {
            document.getElementById(skipLink.hash.slice(1))?.focus({ preventScroll: true });
        });
    });

    if (scrollButton) {
        const interactiveRegions = [document.querySelector('iframe.institution-map')].filter(Boolean);
        let cachedButtonMetrics = null;
        let scrollButtonFrame = null;
        let scrollButtonTimer = null;
        let lastScrollButtonCheckAt = 0;

        const refreshScrollButtonMetrics = () => {
            const buttonBounds = scrollButton.getBoundingClientRect?.();

            if (!buttonBounds) {
                cachedButtonMetrics = null;
                return;
            }

            const computedBottom = typeof window.getComputedStyle === 'function'
                ? parseFloat(window.getComputedStyle(scrollButton).bottom)
                : NaN;
            const buttonBottom = Number.isFinite(computedBottom)
                ? (window.innerHeight || 800) - computedBottom
                : (window.innerHeight || 800) - 28;

            cachedButtonMetrics = {
                left: buttonBounds.left,
                right: buttonBounds.right,
                height: buttonBounds.height,
                buttonBottom,
            };
        };

        const updateScrollButton = () => {
            const scrollY = window.scrollY || 0;

            if (scrollY <= 300) {
                scrollButton.classList.toggle('visible', false);
                return;
            }

            if (!cachedButtonMetrics) {
                refreshScrollButtonMetrics();
            }

            const metrics = cachedButtonMetrics;
            const overlapsInteractiveRegion = metrics
                ? interactiveRegions.some((region) => {
                    const regionBounds = region.getBoundingClientRect();

                    return regionBounds.right > metrics.left
                        && regionBounds.left < metrics.right
                        && regionBounds.bottom > metrics.buttonBottom - metrics.height
                        && regionBounds.top < metrics.buttonBottom;
                })
                : false;

            scrollButton.classList.toggle('visible', !overlapsInteractiveRegion);
        };

        const runScrollButtonFrame = () => {
            lastScrollButtonCheckAt = nowMs();

            if (typeof window.requestAnimationFrame === 'function') {
                if (scrollButtonFrame !== null) {
                    return;
                }

                scrollButtonFrame = window.requestAnimationFrame(() => {
                    scrollButtonFrame = null;
                    updateScrollButton();
                });
            } else {
                updateScrollButton();
            }
        };

        scheduleScrollButtonUpdate = ({ immediate = false } = {}) => {
            if (isSheetDragging && !immediate) {
                return;
            }

            if (immediate) {
                if (scrollButtonTimer !== null) {
                    window.clearTimeout?.(scrollButtonTimer);
                    scrollButtonTimer = null;
                }
                refreshScrollButtonMetrics();
                updateScrollButton();
                return;
            }

            if (typeof window.setTimeout === 'function') {
                const elapsed = nowMs() - lastScrollButtonCheckAt;

                if (elapsed >= 120) {
                    runScrollButtonFrame();
                }

                if (scrollButtonTimer !== null) {
                    window.clearTimeout(scrollButtonTimer);
                }

                scrollButtonTimer = window.setTimeout(() => {
                    scrollButtonTimer = null;
                    if (!isSheetDragging) {
                        runScrollButtonFrame();
                    }
                }, 120);
                return;
            }

            runScrollButtonFrame();
        };

        scrollButton.addEventListener('click', () => {
            document.getElementById('main-content')?.focus({ preventScroll: true });
            window.scrollTo({
                top: 0,
                behavior: reducedMotion.matches ? 'auto' : 'smooth'
            });
        });

        window.addEventListener('scroll', () => scheduleScrollButtonUpdate(), { passive: true });
        window.addEventListener('resize', () => scheduleScrollButtonUpdate({ immediate: true }));
        refreshScrollButtonMetrics();
        updateScrollButton();
    }

    const clearRequestedModalUrl = (requestedModal) => {
        const currentUrl = new URL(window.location.href);
        const hadModalParam = currentUrl.searchParams.has('modal');
        const hadSupportHash = requestedModal === 'support' && currentUrl.hash === '#support';

        if (!hadModalParam && !hadSupportHash) {
            return;
        }

        currentUrl.searchParams.delete('modal');

        if (hadSupportHash) {
            currentUrl.hash = '';
        }

        window.history.replaceState(
            window.history.state,
            '',
            `${currentUrl.pathname}${currentUrl.search}${currentUrl.hash}`
        );
    };

    const revealRequestedModal = () => {
        const requestedModal = window.location.hash === '#support'
            ? 'support'
            : new URLSearchParams(window.location.search).get('modal');

        if (!['privacy', 'accessibility', 'support'].includes(requestedModal) || !window.bootstrap?.Modal) {
            return;
        }

        const modalElement = document.getElementById(`${requestedModal}Modal`);

        if (modalElement) {
            clearRequestedModalUrl(requestedModal);
            window.bootstrap.Modal.getOrCreateInstance(modalElement).show();

            modalElement.addEventListener('hidden.bs.modal', () => {
                clearRequestedModalUrl(requestedModal);
            }, { once: true });
        }
    };

    window.addEventListener('hashchange', revealRequestedModal);
    revealRequestedModal();

    const replaceProgramParameter = (programId) => {
        const currentUrl = new URL(window.location.href);

        if (programId) {
            currentUrl.searchParams.set('program', programId);
        } else {
            currentUrl.searchParams.delete('program');
        }

        window.history.replaceState(window.history.state, '', `${currentUrl.pathname}${currentUrl.search}${currentUrl.hash}`);
    };

    const bindProgramDetailModal = (modalElement) => {
        if (!modalElement || modalElement.dataset?.talaModalBound === 'true') {
            return;
        }

        if (modalElement.dataset) {
            modalElement.dataset.talaModalBound = 'true';
        }

        const programId = modalElement.dataset?.programDetailModal;

        modalElement.addEventListener?.('shown.bs.modal', () => replaceProgramParameter(programId));
        modalElement.addEventListener?.('hidden.bs.modal', () => {
            replaceProgramParameter(null);
            document.querySelector(`[data-program-read-more="${programId}"]`)?.focus({ preventScroll: true });
        });
    };

    document.querySelectorAll('[data-program-detail-modal]').forEach(bindProgramDetailModal);

    const requestedProgramModal = new URLSearchParams(window.location.search).get('program');
    const requestedProgramElement = requestedProgramModal
        ? document.getElementById(`programDetailModal-${requestedProgramModal}`)
        : null;

    if (requestedProgramElement && window.bootstrap?.Modal) {
        window.bootstrap.Modal.getOrCreateInstance(requestedProgramElement).show();
    }

    const navbar = document.querySelector('.navbar');
    const navbarBar = navbar?.querySelector('[data-navbar-bar], .container') ?? null;
    const navigation = document.getElementById('navbarNav');
    const navigationToggle = navbar?.querySelector('.navbar-toggler');

    let cachedNavbarHeight = 80;
    let cachedNavbarBottom = 80;

    const updateNavbarHeight = () => {
        if (navigation?.classList.contains('show') && !navbarBar) {
            return;
        }

        const target = navbarBar ?? navbar;
        const rect = target?.getBoundingClientRect?.();
        const height = rect?.height;

        if (height) {
            cachedNavbarHeight = height;
            cachedNavbarBottom = navbar?.getBoundingClientRect?.().bottom ?? height;
            document.documentElement?.style?.setProperty?.('--tala-navbar-height', `${height}px`);
        }
    };

    updateNavbarHeight();
    window.addEventListener('resize', updateNavbarHeight);

    if (navigation && navigationToggle && window.bootstrap?.Collapse) {
        const closeNavigation = () => window.bootstrap.Collapse.getOrCreateInstance(navigation, { toggle: false }).hide();
        let navigationDestination = null;

        navigation.addEventListener('shown.bs.collapse', () => {
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
                event.preventDefault?.();
                closeNavigation();
                navigationToggle.focus();
            }
        });
    }

    const navigationLinks = navigation ? [...navigation.querySelectorAll('a[href^="#"]')] : [];
    const navList = navigation?.querySelector?.('.navbar-nav') ?? null;
    const navIndicator = navList?.querySelector?.('[data-nav-indicator]') ?? null;
    let hoveredNavLink = null;

    const updateNavIndicator = () => {
        if (!navList || !navIndicator) {
            return;
        }

        const isDesktop = typeof window.innerWidth !== 'number' || window.innerWidth >= 992;
        if (!isDesktop || navigation?.classList?.contains('show')) {
            navIndicator.style.opacity = '0';
            return;
        }

        const activeLink = hoveredNavLink
            ?? navigationLinks.find((link) => link.getAttribute?.('aria-current') === 'location' || link.attributes?.has?.('aria-current'))
            ?? null;

        if (!activeLink || !navList.getBoundingClientRect || !activeLink.getBoundingClientRect) {
            navIndicator.style.opacity = '0';
            return;
        }

        const listRect = navList.getBoundingClientRect();
        const linkRect = activeLink.getBoundingClientRect();

        if (linkRect.width > 0) {
            const x = linkRect.left - listRect.left;
            navIndicator.style.transform = `translateX(${x}px)`;
            navIndicator.style.width = `${linkRect.width}px`;
            navIndicator.style.opacity = '1';
        } else {
            navIndicator.style.opacity = '0';
        }
    };

    if (navList && navIndicator) {
        let indicatorFrame = null;
        const scheduleNavIndicatorUpdate = () => {
            if (indicatorFrame !== null || typeof window.requestAnimationFrame !== 'function') {
                if (typeof window.requestAnimationFrame !== 'function') {
                    updateNavIndicator();
                }
                return;
            }

            indicatorFrame = window.requestAnimationFrame(() => {
                indicatorFrame = null;
                updateNavIndicator();
            });
        };

        navList.addEventListener?.('pointerover', (event) => {
            const link = event.target?.closest?.('a[href^="#"]');
            if (link && navigationLinks.includes(link)) {
                hoveredNavLink = link;
                updateNavIndicator();
            }
        });
        navList.addEventListener?.('pointerleave', () => {
            hoveredNavLink = null;
            updateNavIndicator();
        });
        window.addEventListener('resize', scheduleNavIndicatorUpdate);
    }

    const pageSections = [...document.querySelectorAll('main section[id]')];
    const hasStaticSectionOffsets = typeof window.scrollY === 'number'
        && pageSections.length > 0
        && pageSections.every((section) => typeof section.offsetTop === 'number' && typeof section.getBoundingClientRect === 'function');
    let cachedSectionTops = null;
    let lastActiveHash = null;

    const refreshSectionOffsets = () => {
        if (!hasStaticSectionOffsets) {
            cachedSectionTops = null;
            return;
        }

        const scrollY = window.scrollY || 0;
        cachedSectionTops = pageSections.map((section) => ({
            id: section.id,
            top: section.id === 'top' ? 0 : section.getBoundingClientRect().top + scrollY,
        }));
    };

    refreshSectionOffsets();

    const updateActiveNavigation = ({ force = false } = {}) => {
        let currentSection = null;

        if (cachedSectionTops) {
            const scrollY = window.scrollY || 0;
            const boundary = cachedNavbarBottom + 40;
            currentSection = cachedSectionTops
                .filter((section) => (section.id === 'top' ? 0 : section.top - scrollY) <= boundary)
                .at(-1) ?? cachedSectionTops[0];
        } else {
            const boundary = (navbar?.getBoundingClientRect().bottom ?? 0) + 40;
            currentSection = pageSections.filter((section) => section.getBoundingClientRect().top <= boundary).at(-1)
                ?? pageSections[0];
        }

        const currentHash = currentSection?.id === 'top' && window.location.hash === '#notices'
            ? '#notices'
            : currentSection ? `#${currentSection.id}` : '';

        if (force || currentHash !== lastActiveHash) {
            lastActiveHash = currentHash;

            navigationLinks.forEach((link) => {
                if (link.hash === currentHash) {
                    link.setAttribute('aria-current', 'location');
                } else {
                    link.removeAttribute('aria-current');
                }
            });

            updateNavIndicator();
        }
    };

    const contrastTargets = navbar
        ? [...navbar.querySelectorAll('[data-navbar-contrast-target]')].filter(
            (target) => !target.closest?.('.landing-nav-track')
        )
        : [];
    const canSampleContrast = Boolean(navbar && contrastTargets.length && document.elementsFromPoint);
    let contrastFrame = null;
    let contrastTimer = null;
    let lastContrastSampleAt = 0;

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

    const runNavbarContrastAndSpy = ({ forceNav = false } = {}) => {
        updateActiveNavigation({ force: forceNav });

        if (!canSampleContrast) {
            return;
        }

        contrastTargets.forEach((target) => {
            const foreground = foregroundFor(target);

            if (foreground) {
                target.setAttribute('data-navbar-foreground', foreground);
            }
        });
    };

    const queueContrastFrame = ({ forceNav = false } = {}) => {
        lastContrastSampleAt = nowMs();

        if (typeof window.requestAnimationFrame !== 'function') {
            runNavbarContrastAndSpy({ forceNav });
            return;
        }

        if (contrastFrame !== null) {
            return;
        }

        contrastFrame = window.requestAnimationFrame(() => {
            contrastFrame = null;
            runNavbarContrastAndSpy({ forceNav });
        });
    };

    scheduleNavbarContrastUpdate = ({ forceNav = false, immediate = false } = {}) => {
        if (isSheetDragging && !immediate) {
            return;
        }

        if (immediate || typeof window.setTimeout !== 'function') {
            if (contrastTimer !== null) {
                window.clearTimeout?.(contrastTimer);
                contrastTimer = null;
            }
            queueContrastFrame({ forceNav });
            return;
        }

        const elapsed = nowMs() - lastContrastSampleAt;

        if (elapsed >= 120) {
            queueContrastFrame({ forceNav });
        }

        if (contrastTimer !== null) {
            window.clearTimeout(contrastTimer);
        }

        contrastTimer = window.setTimeout(() => {
            contrastTimer = null;
            if (!isSheetDragging) {
                queueContrastFrame({ forceNav });
            }
        }, 120);
    };

    if (canSampleContrast) {
        window.addEventListener('load', () => {
            updateNavbarHeight();
            refreshSectionOffsets();
            scheduleNavbarContrastUpdate({ forceNav: true, immediate: true });
        });
        window.addEventListener('resize', () => {
            refreshSectionOffsets();
            scheduleNavbarContrastUpdate({ forceNav: true, immediate: true });
        });
        navbar.addEventListener('shown.bs.collapse', () => scheduleNavbarContrastUpdate({ immediate: true }));
        navbar.addEventListener('hidden.bs.collapse', () => scheduleNavbarContrastUpdate({ immediate: true }));
        navbar.addEventListener('shown.bs.dropdown', () => scheduleNavbarContrastUpdate({ immediate: true }));
        navbar.addEventListener('hidden.bs.dropdown', () => scheduleNavbarContrastUpdate({ immediate: true }));
        scheduleNavbarContrastUpdate({ forceNav: true, immediate: true });
    } else {
        updateActiveNavigation({ force: true });
        window.addEventListener('resize', () => {
            refreshSectionOffsets();
            updateActiveNavigation({ force: true });
        });
    }

    window.addEventListener('hashchange', () => {
        updateActiveNavigation({ force: true });
    });
    window.addEventListener('scroll', () => scheduleNavbarContrastUpdate(), { passive: true });

    const suppressThemeTransitions = () => {
        const style = document.createElement?.('style');

        if (!style || !document.head) {
            return;
        }

        style.textContent = '*, *::before, *::after { transition: none !important; }';
        document.head.appendChild(style);
        window.getComputedStyle?.(document.documentElement)?.getPropertyValue?.('opacity');

        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(() => {
                style.remove?.();
            });
        } else {
            style.remove?.();
        }
    };

    const applyTheme = (isDark, { suppressTransitions = false } = {}) => {
        const theme = isDark ? 'dark' : 'light';

        if (suppressTransitions) {
            suppressThemeTransitions();
        }

        document.documentElement?.setAttribute?.('data-bs-theme', theme);
        document.documentElement?.classList?.toggle?.('dark', isDark);

        if (document.documentElement?.style) {
            document.documentElement.style.colorScheme = theme;
        }

        if (canSampleContrast) {
            scheduleNavbarContrastUpdate({ immediate: true });
        }
    };

    applyTheme(colorScheme.matches);

    colorScheme.addEventListener('change', (event) => {
        applyTheme(event.matches, { suppressTransitions: true });
    });

    const spotlightCards = [...document.querySelectorAll('[data-spotlight]')];
    const hasHoverPointer = window.matchMedia('(hover: hover)').matches;
    const allowsMotion = window.matchMedia('(prefers-reduced-motion: no-preference)').matches;

    if (spotlightCards.length && hasHoverPointer && allowsMotion) {
        const cardRects = new WeakMap();
        let spotlightScrollGen = 0;

        if ('ResizeObserver' in window) {
            const resizeObserver = new window.ResizeObserver((entries) => {
                entries.forEach((entry) => {
                    cardRects.set(entry.target, {
                        rect: entry.target.getBoundingClientRect(),
                        gen: spotlightScrollGen,
                    });
                });
            });

            spotlightCards.forEach((card) => resizeObserver.observe(card));
        }

        window.addEventListener('scroll', () => {
            spotlightScrollGen += 1;
        }, { passive: true });

        spotlightCards.forEach((card) => {
            card.addEventListener('pointerenter', () => {
                cardRects.set(card, {
                    rect: card.getBoundingClientRect(),
                    gen: spotlightScrollGen,
                });
            });

            card.addEventListener('pointermove', (event) => {
                let entry = cardRects.get(card);

                if (!entry || entry.gen !== spotlightScrollGen) {
                    entry = {
                        rect: card.getBoundingClientRect(),
                        gen: spotlightScrollGen,
                    };
                    cardRects.set(card, entry);
                }

                const { rect } = entry;

                if (rect.width > 0 && rect.height > 0) {
                    const x = ((event.clientX - rect.left) / rect.width) * 100;
                    const y = ((event.clientY - rect.top) / rect.height) * 100;
                    card.style?.setProperty?.('--mouse-x', `${x.toFixed(1)}%`);
                    card.style?.setProperty?.('--mouse-y', `${y.toFixed(1)}%`);
                }
            });
        });
    }

    const copyResetTimers = new WeakMap();
    const showCopyFeedback = (button, feedback, message, succeeded) => {
        const existingTimer = copyResetTimers.get(button);

        if (existingTimer) {
            window.clearTimeout?.(existingTimer);
        }

        button.classList?.toggle?.('is-copied', succeeded);

        if (feedback) {
            feedback.textContent = feedback.textContent === message ? `${message}\u00A0` : message;
        }

        const timer = window.setTimeout?.(() => {
            button.classList?.toggle?.('is-copied', false);

            if (feedback) {
                feedback.textContent = '';
            }

            copyResetTimers.delete(button);
        }, 2000);

        if (timer) {
            copyResetTimers.set(button, timer);
        }
    };

    const handleCopyCommand = (button) => {
        const targetId = button.getAttribute?.('commandfor');
        const target = targetId ? document.getElementById(targetId) : null;
        const text = (target?.textContent ?? '').trim();

        if (!text) {
            return;
        }

        const wrapper = button.closest?.('.copy-control') ?? button.parentElement;
        const feedback = wrapper?.querySelector?.('[data-copy-feedback], [role="status"]') ?? null;
        const clipboard = window.navigator?.clipboard ?? (typeof navigator !== 'undefined' ? navigator.clipboard : null);

        if (!clipboard?.writeText) {
            showCopyFeedback(button, feedback, 'Copy failed', false);
            return;
        }

        Promise.resolve(clipboard.writeText(text))
            .then(() => showCopyFeedback(button, feedback, 'Copied', true))
            .catch(() => showCopyFeedback(button, feedback, 'Copy failed', false));
    };

    document.addEventListener('command', (event) => {
        if (event.command !== '--copy') {
            return;
        }

        const button = event.source?.closest?.('[command="--copy"]') ?? event.target?.closest?.('[command="--copy"]');

        if (button) {
            handleCopyCommand(button);
        }
    }, true);

    if (!('CommandEvent' in window)) {
        document.addEventListener('click', (event) => {
            const button = event.target?.closest?.('button[command="--copy"]');

            if (button) {
                handleCopyCommand(button);
            }
        });
    }

    const supportsScrollTimeline = typeof CSS !== 'undefined'
        && typeof CSS.supports === 'function'
        && CSS.supports('(animation-timeline: view()) and (animation-range: entry)');
    const parallaxTarget = ambientStage ?? document.documentElement;
    const blurStripTarget = bottomBlurStrip ?? document.documentElement;
    const heroProgressTarget = heroContainer ?? document.documentElement;

    const scrollToTopDestination = ({ updateHistory = true } = {}) => {
        window.scrollTo?.({
            top: 0,
            left: 0,
            behavior: reducedMotion.matches ? 'auto' : 'smooth'
        });

        if (updateHistory && window.history?.replaceState && window.location?.pathname !== undefined) {
            window.history.replaceState(
                window.history.state,
                '',
                `${window.location.pathname}${window.location.search ?? ''}#top`
            );
        }
    };

    document.addEventListener('click', (event) => {
        const topLink = event.target?.closest?.('a[href="#top"]');

        if (!topLink) {
            return;
        }

        event.preventDefault?.();
        scrollToTopDestination({ updateHistory: true });
    });

    window.addEventListener('hashchange', () => {
        if (window.location.hash === '#top') {
            scrollToTopDestination({ updateHistory: false });
        }
    });

    let cachedViewportWidth = window.innerWidth || 1;
    let cachedViewportHeight = window.innerHeight || 800;
    let cachedMaxScroll = Math.max(1, (document.documentElement?.scrollHeight ?? cachedViewportHeight) - cachedViewportHeight);
    let lastScrollProgress = '';
    let lastBottomBlurOpacity = '';
    let lastHeroProgress = '';

    const refreshScrollDimensions = () => {
        cachedViewportWidth = window.innerWidth || 1;
        cachedViewportHeight = window.innerHeight || 800;
        cachedMaxScroll = Math.max(1, (document.documentElement?.scrollHeight ?? cachedViewportHeight) - cachedViewportHeight);
    };

    if ('ResizeObserver' in window && document.documentElement) {
        const docResizeObserver = new window.ResizeObserver(() => {
            refreshScrollDimensions();
            refreshSectionOffsets();
        });
        docResizeObserver.observe(document.documentElement);
        if (document.body) {
            docResizeObserver.observe(document.body);
        }
    }

    const updateScrollProgress = () => {
        const scrollY = window.scrollY || 0;
        const scrollProgress = Math.min(1, Math.max(0, scrollY / cachedMaxScroll)).toFixed(4);
        const bottomBlurOpacity = Math.min(1, Math.max(0, (scrollY - 96) / 144)).toFixed(4);

        if (scrollProgress !== lastScrollProgress) {
            lastScrollProgress = scrollProgress;
            parallaxTarget?.style?.setProperty?.('--scroll-progress', scrollProgress);
        }

        if (bottomBlurOpacity !== lastBottomBlurOpacity) {
            lastBottomBlurOpacity = bottomBlurOpacity;
            blurStripTarget?.style?.setProperty?.('--bottom-blur-opacity', bottomBlurOpacity);
        }

        if (!supportsScrollTimeline) {
            const heroProgress = Math.min(1, Math.max(0, scrollY / Math.max(1, cachedViewportHeight))).toFixed(4);

            if (heroProgress !== lastHeroProgress) {
                lastHeroProgress = heroProgress;
                heroProgressTarget?.style?.setProperty?.('--hero-progress', heroProgress);
            }
        }
    };

    let progressFrame = null;
    scheduleScrollProgress = () => {
        if (progressFrame !== null || typeof window.requestAnimationFrame !== 'function') {
            if (typeof window.requestAnimationFrame !== 'function') {
                updateScrollProgress();
            }
            return;
        }

        progressFrame = window.requestAnimationFrame(() => {
            progressFrame = null;
            updateScrollProgress();
        });
    };

    updateScrollProgress();
    window.addEventListener('scroll', scheduleScrollProgress, { passive: true });
    window.addEventListener('resize', () => {
        refreshScrollDimensions();
        scheduleScrollProgress();
    });
    window.addEventListener('load', () => {
        refreshScrollDimensions();
        scheduleScrollProgress();
    });

    if (hasHoverPointer && allowsMotion && typeof window.requestAnimationFrame === 'function') {
        let currentPx = 0;
        let currentPy = 0;
        let targetPx = 0;
        let targetPy = 0;
        let parallaxRafId = null;

        const stepParallax = () => {
            currentPx += (targetPx - currentPx) * 0.1;
            currentPy += (targetPy - currentPy) * 0.1;

            if (Math.abs(targetPx - currentPx) < 0.002 && Math.abs(targetPy - currentPy) < 0.002) {
                currentPx = targetPx;
                currentPy = targetPy;
                parallaxTarget?.style?.setProperty?.('--px', currentPx.toFixed(4));
                parallaxTarget?.style?.setProperty?.('--py', currentPy.toFixed(4));
                parallaxRafId = null;
                return;
            }

            parallaxTarget?.style?.setProperty?.('--px', currentPx.toFixed(4));
            parallaxTarget?.style?.setProperty?.('--py', currentPy.toFixed(4));
            parallaxRafId = window.requestAnimationFrame(stepParallax);
        };

        const scheduleParallax = () => {
            if (parallaxRafId === null) {
                parallaxRafId = window.requestAnimationFrame(stepParallax);
            }
        };

        window.addEventListener('pointermove', (event) => {
            targetPx = Math.min(1, Math.max(-1, ((event.clientX / cachedViewportWidth) * 2) - 1));
            targetPy = Math.min(1, Math.max(-1, ((event.clientY / cachedViewportHeight) * 2) - 1));
            scheduleParallax();
        }, { passive: true });

        document.addEventListener('pointerleave', () => {
            targetPx = 0;
            targetPy = 0;
            scheduleParallax();
        });
    }

    const campusDeck = document.querySelector('[data-campus-deck]');
    const deckCards = campusDeck ? [...campusDeck.querySelectorAll('[data-deck-card]')] : [];

    if (campusDeck && deckCards.length > 1) {
        let activeIndex = 0;
        let isPaused = false;
        let isInViewport = true;
        let cycleTimer = null;
        let exitTimer = null;

        const syncDeckCards = (previousIndex = -1) => {
            const total = deckCards.length;
            const nextIndex = (activeIndex + 1) % total;

            deckCards.forEach((card, index) => {
                const isActive = index === activeIndex;
                const isNext = index === nextIndex;
                const isBack = !isActive && !isNext;
                const isExiting = index === previousIndex && previousIndex !== activeIndex;

                card.classList?.toggle?.('is-active', isActive);
                card.classList?.toggle?.('is-next', isNext);
                card.classList?.toggle?.('is-back', isBack);
                card.classList?.toggle?.('is-exiting', isExiting);
                card.setAttribute?.('aria-hidden', isActive ? 'false' : 'true');
            });

            const counter = campusDeck.querySelector?.('[data-deck-counter]');
            if (counter) {
                counter.textContent = `${activeIndex + 1} / ${total}`;
            }

            if (previousIndex !== -1 && previousIndex !== activeIndex) {
                if (exitTimer) {
                    window.clearTimeout?.(exitTimer);
                }
                const exitingCard = deckCards[previousIndex];
                exitTimer = window.setTimeout?.(() => {
                    exitingCard?.classList?.remove?.('is-exiting');
                    exitTimer = null;
                }, 450);
            }
        };

        const canAutoCycle = () => !reducedMotion.matches
            && !forcedColors.matches
            && !isPaused
            && !document.hidden
            && isInViewport;

        let restartAutoCycle = () => {};
        const goToSlide = (nextIndex, { restartTimer = true } = {}) => {
            const previousIndex = activeIndex;
            activeIndex = ((nextIndex % deckCards.length) + deckCards.length) % deckCards.length;
            syncDeckCards(previousIndex);

            if (restartTimer) {
                restartAutoCycle();
            }
        };

        restartAutoCycle = () => {
            if (cycleTimer) {
                window.clearInterval?.(cycleTimer);
                cycleTimer = null;
            }

            if (canAutoCycle() && typeof window.setInterval === 'function') {
                cycleTimer = window.setInterval(() => {
                    if (canAutoCycle()) {
                        goToSlide(activeIndex + 1, { restartTimer: false });
                    }
                }, 4200);
            }
        };

        campusDeck.querySelector?.('[data-deck-prev]')?.addEventListener?.('click', () => {
            goToSlide(activeIndex - 1);
        });
        campusDeck.querySelector?.('[data-deck-next]')?.addEventListener?.('click', () => {
            goToSlide(activeIndex + 1);
        });
        campusDeck.addEventListener?.('click', (event) => {
            if (event.target?.closest?.('[data-deck-prev], [data-deck-next]')) {
                return;
            }

            const clickedCard = event.target?.closest?.('[data-deck-card]');
            if (clickedCard?.classList?.contains?.('is-active')) {
                goToSlide(activeIndex + 1);
            }
        });

        campusDeck.addEventListener?.('pointerenter', () => {
            isPaused = true;
            restartAutoCycle();
        });
        campusDeck.addEventListener?.('pointerleave', () => {
            isPaused = false;
            restartAutoCycle();
        });
        campusDeck.addEventListener?.('focusin', () => {
            isPaused = true;
            restartAutoCycle();
        });
        campusDeck.addEventListener?.('focusout', (event) => {
            isPaused = Boolean(campusDeck.contains?.(event.relatedTarget));
            restartAutoCycle();
        });

        if ('IntersectionObserver' in window) {
            new window.IntersectionObserver(([entry]) => {
                isInViewport = entry.isIntersecting;
                restartAutoCycle();
            }).observe(campusDeck);
        }

        document.addEventListener('visibilitychange', restartAutoCycle);
        reducedMotion.addEventListener('change', restartAutoCycle);
        forcedColors.addEventListener('change', restartAutoCycle);

        syncDeckCards(-1);
        restartAutoCycle();
    }

    const sheetHandle = document.querySelector('[data-sheet-handle]');
    const contentSheet = sheetHandle?.closest?.('.landing-content-sheet') ?? document.querySelector('.landing-content-sheet');

    if (sheetHandle) {
        let dragPointerId = null;
        let dragStartClientY = 0;
        let dragStartScrollY = 0;
        let pendingClientY = 0;
        let lastClientY = 0;
        let lastMoveTime = 0;
        let velocityY = 0;
        let didDrag = false;
        let suppressNextClick = false;
        let dragRafId = null;
        let snapRafId = null;
        let cachedDragHeroHeight = 800;
        let cachedDragMaxScroll = 0;
        let cachedDragOpenY = 680;

        const cancelDragFrame = () => {
            if (dragRafId !== null) {
                window.cancelAnimationFrame?.(dragRafId);
                dragRafId = null;
            }
        };

        const cancelSnapAnimation = () => {
            if (snapRafId !== null) {
                window.cancelAnimationFrame?.(snapRafId);
                snapRafId = null;
            }
        };

        const setDraggingClass = (active) => {
            isSheetDragging = active;
            document.documentElement?.classList?.toggle?.('is-dragging', active);
            sheetHandle.classList?.toggle?.('is-dragging', active);
            contentSheet?.classList?.toggle?.('is-dragging', active);
        };

        const notifySheetSettled = () => {
            scheduleScrollProgress();
            scheduleScrollButtonUpdate({ immediate: true });
            scheduleNavbarContrastUpdate({ immediate: true });
        };

        const getSheetOpenScrollY = () => {
            const target = contentSheet ?? sheetHandle ?? document.getElementById('notices');
            const navRect = navbar?.getBoundingClientRect?.();
            const navBottom = navRect?.bottom ?? navRect?.height ?? cachedNavbarBottom;

            if (target?.getBoundingClientRect) {
                const rectTop = target.getBoundingClientRect().top;
                return Math.max(0, Math.round((window.scrollY || 0) + rectTop - navBottom - 8));
            }

            return Math.max(0, Math.round((window.innerHeight || 800) * 0.85));
        };

        const instantSheetScrollTo = (y) => {
            try {
                window.scrollTo?.({ top: y, left: 0, behavior: 'instant' });
            } catch {
                window.scrollTo?.(0, y);
            }
        };

        const flushDragMove = () => {
            dragRafId = null;

            if (dragPointerId === null) {
                return;
            }

            const deltaY = pendingClientY - dragStartClientY;
            const nextScrollY = Math.min(cachedDragMaxScroll, Math.max(0, dragStartScrollY - deltaY));
            instantSheetScrollTo(nextScrollY);
        };

        const snapSheetTo = (targetTop) => {
            cancelDragFrame();
            cancelSnapAnimation();
            const clampedTarget = Math.max(0, targetTop);

            if (reducedMotion.matches || typeof window.requestAnimationFrame !== 'function') {
                instantSheetScrollTo(clampedTarget);
                notifySheetSettled();
                return;
            }

            const startY = window.scrollY || 0;
            const distance = clampedTarget - startY;

            if (Math.abs(distance) < 1) {
                instantSheetScrollTo(clampedTarget);
                notifySheetSettled();
                return;
            }

            const duration = 680;
            const startTime = nowMs();

            const stepSnap = (timestamp) => {
                const now = typeof timestamp === 'number' ? timestamp : nowMs();
                const progress = Math.min(1, Math.max(0, (now - startTime) / duration));
                const eased = 1 - Math.pow(1 - progress, 3);
                instantSheetScrollTo(startY + (distance * eased));

                if (progress < 1) {
                    snapRafId = window.requestAnimationFrame(stepSnap);
                } else {
                    snapRafId = null;
                    notifySheetSettled();
                }
            };

            snapRafId = window.requestAnimationFrame(stepSnap);
        };

        sheetHandle.addEventListener('pointerdown', (event) => {
            if (event.button !== undefined && event.button !== 0) {
                return;
            }

            cancelDragFrame();
            cancelSnapAnimation();
            dragPointerId = event.pointerId ?? 'mouse';
            dragStartClientY = event.clientY ?? 0;
            dragStartScrollY = window.scrollY || 0;
            pendingClientY = dragStartClientY;
            lastClientY = dragStartClientY;
            lastMoveTime = nowMs();
            velocityY = 0;
            didDrag = false;
            suppressNextClick = false;
            cachedDragHeroHeight = window.innerHeight || 800;
            cachedDragMaxScroll = Math.max(0, (document.documentElement?.scrollHeight ?? 0) - cachedDragHeroHeight);
            cachedDragOpenY = getSheetOpenScrollY();
            setDraggingClass(true);
            sheetHandle.setPointerCapture?.(event.pointerId);
        });

        sheetHandle.addEventListener('pointermove', (event) => {
            if (
                dragPointerId === null
                || (event.pointerId !== undefined && dragPointerId !== 'mouse' && event.pointerId !== dragPointerId)
            ) {
                return;
            }

            pendingClientY = event.clientY ?? 0;
            const deltaY = pendingClientY - dragStartClientY;

            if (Math.abs(deltaY) >= 6) {
                didDrag = true;
            }

            const now = nowMs();
            const dt = Math.max(1, now - lastMoveTime);
            velocityY = (pendingClientY - lastClientY) / dt;
            lastClientY = pendingClientY;
            lastMoveTime = now;

            if (typeof window.requestAnimationFrame === 'function') {
                if (dragRafId === null) {
                    dragRafId = window.requestAnimationFrame(flushDragMove);
                }
            } else {
                flushDragMove();
            }
        });

        const endSheetDrag = (event) => {
            if (dragPointerId === null) {
                return;
            }

            const clientY = event.clientY ?? pendingClientY;
            const deltaY = clientY - dragStartClientY;
            const wasDragged = didDrag || Math.abs(deltaY) >= 6;
            const hadPendingFrame = dragRafId !== null;

            cancelDragFrame();
            dragPointerId = null;
            setDraggingClass(false);

            if (event.pointerId !== undefined) {
                sheetHandle.releasePointerCapture?.(event.pointerId);
            }

            if (!wasDragged) {
                notifySheetSettled();
                return;
            }

            suppressNextClick = true;
            const currentScrollY = hadPendingFrame
                ? Math.min(cachedDragMaxScroll, Math.max(0, dragStartScrollY - deltaY))
                : (window.scrollY || 0);

            if (hadPendingFrame) {
                instantSheetScrollTo(currentScrollY);
            }

            if (deltaY < 0 && (velocityY < -0.55 || deltaY <= -(cachedDragHeroHeight * 0.38))) {
                snapSheetTo(Math.max(currentScrollY, cachedDragOpenY));
            } else if (deltaY > 0 && (velocityY > 0.55 || deltaY >= cachedDragHeroHeight * 0.38 || currentScrollY < cachedDragHeroHeight * 0.25)) {
                snapSheetTo(0);
            } else {
                notifySheetSettled();
            }
        };

        sheetHandle.addEventListener('pointerup', endSheetDrag);
        sheetHandle.addEventListener('pointercancel', endSheetDrag);

        sheetHandle.addEventListener('click', () => {
            if (suppressNextClick) {
                suppressNextClick = false;
                return;
            }

            const openY = getSheetOpenScrollY();
            const currentScrollY = window.scrollY || 0;
            const threshold = Math.max(40, openY * 0.4);
            snapSheetTo(currentScrollY >= threshold ? 0 : openY);
        });
    }

    const programCatalog = document.querySelector?.('[data-program-catalog]') ?? null;
    const programFilterForm = programCatalog?.querySelector?.('[data-program-filters]') ?? null;
    const programList = programCatalog?.querySelector?.('[data-program-list]') ?? null;

    if (programCatalog && programFilterForm && programList) {
        document.documentElement?.classList?.add?.('has-program-filter-js');

        const programSummary = programCatalog.querySelector?.('[data-program-summary]') ?? null;
        const programStatusRegion = programCatalog.querySelector?.('[data-program-filter-status]') ?? null;
        const programEmptyItem = programList.querySelector?.('[data-program-empty]') ?? null;
        const programClearControls = [...(programCatalog.querySelectorAll?.('[data-program-filter-clear]') ?? [])];
        const totalProgramCount = Number.parseInt(programCatalog.dataset?.catalogTotal ?? '', 10);
        const totalOpenCount = Number.parseInt(programCatalog.dataset?.catalogOpenTotal ?? '', 10);
        let isCatalogComplete = programCatalog.dataset?.catalogComplete === 'true';
        let hydrateCatalogPromise = null;
        let lastAnnouncedStatus = '';

        const getProgramCards = () => [...(programList.querySelectorAll?.('[data-program-card]') ?? [])];

        const readSelectedFilters = () => {
            const checkedDuration = programFilterForm.querySelector?.('input[name="duration"]:checked')?.value ?? '';
            const checkedAvailability = programFilterForm.querySelector?.('input[name="availability"]:checked')?.value ?? '';

            return {
                duration: checkedDuration,
                availability: checkedAvailability,
            };
        };

        const buildFilteredHref = (filters, extraParams = {}) => {
            const baseHref = programCatalog.dataset?.catalogBaseUrl || window.location?.pathname || '/';
            const params = new URLSearchParams();

            if (filters.duration) {
                params.set('duration', filters.duration);
            }

            if (filters.availability) {
                params.set('availability', filters.availability);
            }

            Object.entries(extraParams).forEach(([key, value]) => {
                if (value !== null && value !== undefined && value !== '') {
                    params.set(key, String(value));
                }
            });

            const query = params.toString();
            const separator = query ? `?${query}` : '';

            return `${baseHref}${separator}#programs`;
        };

        const syncHistoryUrl = (filters) => {
            if (!window.history?.replaceState || typeof URL !== 'function' || !window.location?.href) {
                return;
            }

            const nextUrl = new URL(window.location.href);
            const preservedEntries = [];

            nextUrl.searchParams.forEach((value, key) => {
                if (key !== 'duration' && key !== 'availability') {
                    preservedEntries.push([key, value]);
                }
            });

            const nextParams = new URLSearchParams();

            if (filters.duration) {
                nextParams.set('duration', filters.duration);
            }

            if (filters.availability) {
                nextParams.set('availability', filters.availability);
            }

            preservedEntries.forEach(([key, value]) => {
                nextParams.append(key, value);
            });

            const query = nextParams.toString();
            const search = query ? `?${query}` : '';
            const hasFilters = Boolean(filters.duration || filters.availability);
            const nextHash = hasFilters ? '#programs' : (nextUrl.hash || '');

            window.history.replaceState(
                window.history.state,
                '',
                `${nextUrl.pathname}${search}${nextHash}`
            );
        };

        const pluralizeProgram = (count) => (count === 1 ? 'program' : 'programs');

        const updateProgramSummary = (visibleCount, openCount, totalCount, hasFilters) => {
            if (!programSummary) {
                return;
            }

            if (typeof document.createElement === 'function' && typeof programSummary.replaceChildren === 'function') {
                const strong = (value) => {
                    const el = document.createElement('strong');
                    el.textContent = String(value);
                    return el;
                };

                if (hasFilters) {
                    const span = document.createElement('span');
                    span.append('Showing ', strong(visibleCount), ' of ', strong(totalCount), ` ${pluralizeProgram(totalCount)}`);
                    programSummary.replaceChildren(span);
                } else {
                    const countSpan = document.createElement('span');
                    countSpan.append(strong(visibleCount), ` ${pluralizeProgram(visibleCount)}`);

                    const sep = document.createElement('span');
                    sep.className = 'catalog-summary__sep';
                    sep.setAttribute('aria-hidden', 'true');
                    sep.textContent = '·';

                    const openSpan = document.createElement('span');
                    openSpan.append(strong(openCount), ' accepting applications');

                    programSummary.replaceChildren(countSpan, sep, openSpan);
                }
            } else {
                programSummary.textContent = hasFilters
                    ? `Showing ${visibleCount} of ${totalCount} ${pluralizeProgram(totalCount)}`
                    : `${visibleCount} ${pluralizeProgram(visibleCount)} · ${openCount} accepting applications`;
            }
        };

        const announceProgramFilterState = (visibleCount, totalCount, hasFilters) => {
            if (!programStatusRegion) {
                return;
            }

            const message = visibleCount === 0
                ? 'No programs match the selected filters.'
                : hasFilters
                    ? `Showing ${visibleCount} of ${totalCount} ${pluralizeProgram(totalCount)}.`
                    : `Showing all ${visibleCount} ${pluralizeProgram(visibleCount)}.`;

            programStatusRegion.textContent = lastAnnouncedStatus === message ? `${message}\u00A0` : message;
            lastAnnouncedStatus = message;
        };

        const applyProgramFiltersNow = (filters, { announce = true, syncUrl = true } = {}) => {
            const cards = getProgramCards();
            const hasFilters = Boolean(filters.duration || filters.availability);
            let visibleCount = 0;
            let visibleOpenCount = 0;

            cards.forEach((card) => {
                const cardDuration = String(card.dataset?.duration ?? '');
                const cardAvailability = String(card.dataset?.availability ?? '');
                const matchesDuration = !filters.duration || cardDuration === filters.duration;
                const matchesAvailability = !filters.availability || cardAvailability === filters.availability;
                const matches = matchesDuration && matchesAvailability;

                card.hidden = !matches;

                if (matches) {
                    visibleCount += 1;
                    if (cardAvailability === 'open') {
                        visibleOpenCount += 1;
                    }
                }

                const readMoreLink = card.querySelector?.('[data-program-read-more]');
                const programId = card.dataset?.programId;
                if (readMoreLink && programId) {
                    readMoreLink.setAttribute?.('href', buildFilteredHref(filters, { program: programId }));
                }
            });

            document.querySelectorAll?.('[data-program-modal-back]')?.forEach?.((backLink) => {
                backLink.setAttribute?.('href', buildFilteredHref(filters));
            });

            if (programEmptyItem) {
                programEmptyItem.hidden = visibleCount > 0;
            }

            programClearControls.forEach((control) => {
                if (control.closest?.('[data-program-filters]')) {
                    control.hidden = !hasFilters;
                }
            });

            const resolvedTotal = Number.isFinite(totalProgramCount) && totalProgramCount > 0
                ? totalProgramCount
                : cards.length;
            const resolvedOpenTotal = Number.isFinite(totalOpenCount)
                ? totalOpenCount
                : visibleOpenCount;

            updateProgramSummary(
                visibleCount,
                hasFilters ? visibleOpenCount : resolvedOpenTotal,
                resolvedTotal,
                hasFilters
            );

            if (announce) {
                announceProgramFilterState(visibleCount, resolvedTotal, hasFilters);
            }

            if (syncUrl) {
                syncHistoryUrl(filters);
            }

            refreshScrollDimensions();
            refreshSectionOffsets();
            scheduleScrollProgress();
            scheduleNavbarContrastUpdate({ immediate: true });
        };

        const runProgramFilterUpdate = (filters, options = {}) => {
            const update = () => applyProgramFiltersNow(filters, options);

            if (typeof document.startViewTransition === 'function' && !reducedMotion.matches) {
                try {
                    document.startViewTransition(update);
                    return;
                } catch {
                    // Fall through to synchronous update
                }
            }

            update();
        };

        const hydrateFullCatalog = () => {
            if (isCatalogComplete) {
                return Promise.resolve(true);
            }

            if (hydrateCatalogPromise) {
                return hydrateCatalogPromise;
            }

            const baseHref = programCatalog.dataset?.catalogBaseUrl;
            const fetchFn = window.fetch ?? (typeof fetch === 'function' ? fetch : null);
            const Parser = window.DOMParser ?? (typeof DOMParser === 'function' ? DOMParser : null);

            if (!baseHref || !fetchFn || !Parser) {
                return Promise.resolve(false);
            }

            hydrateCatalogPromise = Promise.resolve(
                fetchFn(baseHref, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            )
                .then((response) => {
                    if (!response?.ok) {
                        return false;
                    }

                    return response.text();
                })
                .then((html) => {
                    if (!html) {
                        return false;
                    }

                    const parsed = new Parser().parseFromString(html, 'text/html');
                    const freshCards = [...parsed.querySelectorAll('[data-program-catalog] [data-program-card]')];

                    if (!freshCards.length) {
                        return false;
                    }

                    getProgramCards().forEach((card) => card.remove());

                    freshCards.forEach((freshCard) => {
                        const importedCard = document.importNode(freshCard, true);
                        if (programEmptyItem) {
                            programList.insertBefore(importedCard, programEmptyItem);
                        } else {
                            programList.appendChild(importedCard);
                        }
                    });

                    parsed.querySelectorAll('[data-program-detail-modal]').forEach((freshModal) => {
                        const modalId = freshModal.id;
                        if (modalId && !document.getElementById(modalId) && document.body) {
                            const importedModal = document.importNode(freshModal, true);
                            document.body.appendChild(importedModal);
                            bindProgramDetailModal(importedModal);
                        }
                    });

                    isCatalogComplete = true;
                    if (programCatalog.dataset) {
                        programCatalog.dataset.catalogComplete = 'true';
                    }

                    return true;
                })
                .catch(() => false)
                .finally(() => {
                    hydrateCatalogPromise = null;
                });

            return hydrateCatalogPromise;
        };

        const handleFilterChange = () => {
            const filters = readSelectedFilters();

            if (isCatalogComplete) {
                runProgramFilterUpdate(filters);
                return;
            }

            hydrateFullCatalog().then((hydrated) => {
                if (hydrated) {
                    runProgramFilterUpdate(readSelectedFilters());
                } else {
                    programFilterForm.submit?.();
                }
            });
        };

        const resetProgramFilters = (event) => {
            event?.preventDefault?.();

            const durationAll = programFilterForm.querySelector?.('input[name="duration"][value=""]');
            const availabilityAll = programFilterForm.querySelector?.('input[name="availability"][value=""]');

            if (durationAll) {
                durationAll.checked = true;
            }

            if (availabilityAll) {
                availabilityAll.checked = true;
            }

            handleFilterChange();
        };

        programFilterForm.addEventListener?.('change', (event) => {
            if (event.target?.matches?.('input[type="radio"]')) {
                handleFilterChange();
            }
        });

        programFilterForm.addEventListener?.('submit', (event) => {
            event.preventDefault?.();
            handleFilterChange();
        });

        programClearControls.forEach((control) => {
            control.addEventListener?.('click', resetProgramFilters);
        });
    }

    const trinketShapeCycle = ['spark', 'star', 'ring', 'plus', 'arc', 'squiggle', 'plane', 'dot'];
    const trinketSvgMap = {
        spark: '<svg viewBox="0 0 24 24" focusable="false"><path d="M12 1 C 13 8, 16 11, 23 12 C 16 13, 13 16, 12 23 C 11 16, 8 13, 1 12 C 8 11, 11 8, 12 1 Z"/></svg>',
        star: '<svg viewBox="0 0 24 24" focusable="false"><path d="M12 2.5 L 14.1 9.9 L 21.5 12 L 14.1 14.1 L 12 21.5 L 9.9 14.1 L 2.5 12 L 9.9 9.9 Z"/></svg>',
        ring: '<svg viewBox="0 0 24 24" focusable="false"><circle class="tala-ambient-line" cx="12" cy="12" r="8.75"/></svg>',
        plus: '<svg viewBox="0 0 24 24" focusable="false"><path class="tala-ambient-line" d="M12 3.5 V 20.5 M3.5 12 H 20.5"/></svg>',
        squiggle: '<svg viewBox="0 0 64 20" focusable="false"><path class="tala-ambient-line" d="M2 10 C 8 0, 14 20, 20 10 S 32 0, 38 10 S 50 20, 62 10"/></svg>',
        arc: '<svg viewBox="0 0 32 20" focusable="false"><path class="tala-ambient-line" d="M3 16 C 9 4, 23 4, 29 16"/></svg>',
        plane: '<svg viewBox="0 0 48 44" focusable="false"><path d="M2 16 L 46 2 L 22 40 L 15 27 Z"/></svg>',
        dot: '<span class="tala-ambient-dot"></span>',
    };

    const fgTrinketsContainer = document.querySelector?.('[data-trinkets-fg]') ?? null;
    const fgTrinkets = fgTrinketsContainer
        ? [...(fgTrinketsContainer.querySelectorAll?.('.landing-fg-trinket') ?? [])]
        : [];

    if (fgTrinkets.length > 0 && typeof window.setInterval === 'function') {
        const trinketScaleCycle = [1, 1.14, 0.9, 1.06];
        // Choreographed sweep alternating left & right flanks top-to-bottom
        const leftHalf = Math.ceil(fgTrinkets.length / 2);
        const trinketWaveOrder = [];
        for (let offset = 0; offset < leftHalf; offset += 1) {
            if (offset < fgTrinkets.length) {
                trinketWaveOrder.push(offset);
            }
            const rightIndex = leftHalf + offset;
            if (rightIndex < fgTrinkets.length) {
                trinketWaveOrder.push(rightIndex);
            }
        }

        let waveCursor = 0;
        let trinketInterval = null;
        let morphSwapTimer = null;

        const canMorphTrinkets = () => !reducedMotion.matches
            && !forcedColors.matches
            && !document.hidden;

        const morphNextTrinket = () => {
            if (!canMorphTrinkets() || !trinketWaveOrder.length) {
                return;
            }

            const targetIndex = trinketWaveOrder[waveCursor % trinketWaveOrder.length];
            const trinket = fgTrinkets[targetIndex];
            const glyph = trinket?.querySelector?.('[data-trinket-glyph]') ?? null;

            waveCursor += 1;

            if (!trinket || !glyph) {
                return;
            }

            const currentShape = trinket.dataset?.shape ?? 'spark';
            const currentPos = trinketShapeCycle.indexOf(currentShape);
            const nextShape = trinketShapeCycle[(currentPos + 1) % trinketShapeCycle.length];
            const nextScale = trinketScaleCycle[waveCursor % trinketScaleCycle.length];

            trinket.classList?.add?.('is-morphing');

            if (morphSwapTimer !== null) {
                window.clearTimeout?.(morphSwapTimer);
            }

            morphSwapTimer = window.setTimeout?.(() => {
                morphSwapTimer = null;

                if (trinket.dataset) {
                    trinket.dataset.shape = nextShape;
                }

                glyph.innerHTML = trinketSvgMap[nextShape] ?? trinketSvgMap.spark;
                trinket.style?.setProperty?.('--fmorph-scale', String(nextScale));
                trinket.classList?.remove?.('is-morphing');
            }, 640);
        };

        const syncTrinketMorphLoop = () => {
            if (trinketInterval !== null) {
                window.clearInterval?.(trinketInterval);
                trinketInterval = null;
            }

            if (canMorphTrinkets()) {
                trinketInterval = window.setInterval(morphNextTrinket, 2800);
            } else {
                fgTrinkets.forEach((trinket) => trinket.classList?.remove?.('is-morphing'));
            }
        };

        document.addEventListener?.('visibilitychange', syncTrinketMorphLoop);
        reducedMotion.addEventListener?.('change', syncTrinketMorphLoop);
        forcedColors.addEventListener?.('change', syncTrinketMorphLoop);
        syncTrinketMorphLoop();
    }

    const footerTrinketStream = document.querySelector?.('[data-footer-trinket-stream]') ?? null;

    if (footerTrinketStream) {
        const footerTones = ['green', 'gold', 'crimson'];
        const footerSizes = [12, 15, 18, 21, 14, 19, 23, 16];
        const footerDrifts = [-24, 18, -14, 24, -20, 14, 28, -18];
        let footerCycleCount = 0;
        let isFooterVisible = true;

        const syncFooterStreamPlayback = () => {
            const shouldPause = !isFooterVisible
                || Boolean(document.hidden)
                || reducedMotion.matches
                || forcedColors.matches;

            footerTrinketStream.classList?.toggle?.('is-paused', shouldPause);
        };

        footerTrinketStream.addEventListener?.('animationiteration', (event) => {
            const trinket = event.target?.closest?.('[data-footer-trinket]') ?? event.target;

            if (!trinket?.dataset) {
                return;
            }

            footerCycleCount += 1;

            const currentShape = trinket.dataset.shape ?? 'spark';
            const nextShape = trinketShapeCycle[(trinketShapeCycle.indexOf(currentShape) + 1) % trinketShapeCycle.length];
            const currentTone = trinket.dataset.tone ?? 'green';
            const nextTone = footerTones[(footerTones.indexOf(currentTone) + 1) % footerTones.length];
            const nextSize = footerSizes[footerCycleCount % footerSizes.length];
            const nextDrift = footerDrifts[footerCycleCount % footerDrifts.length];
            // Golden-ratio horizontal lane progression keeps launches well distributed across 6%..94%
            const nextX = Math.round(6 + (((footerCycleCount * 37) % 89)));

            trinket.dataset.shape = nextShape;
            trinket.dataset.tone = nextTone;
            trinket.innerHTML = trinketSvgMap[nextShape] ?? trinketSvgMap.spark;
            trinket.style?.setProperty?.('--fx', `${nextX}%`);
            trinket.style?.setProperty?.('--fsize', `${nextSize}px`);
            trinket.style?.setProperty?.('--fdrift', `${nextDrift}px`);
        });

        if ('IntersectionObserver' in window) {
            const footerObserveTarget = footerTrinketStream.closest?.('.footer-shell') ?? footerTrinketStream;
            new window.IntersectionObserver(([entry]) => {
                isFooterVisible = Boolean(entry?.isIntersecting);
                syncFooterStreamPlayback();
            }, { rootMargin: '80px 0px' }).observe(footerObserveTarget);
        }

        document.addEventListener?.('visibilitychange', syncFooterStreamPlayback);
        reducedMotion.addEventListener?.('change', syncFooterStreamPlayback);
        forcedColors.addEventListener?.('change', syncFooterStreamPlayback);
        syncFooterStreamPlayback();
    }

    const heroSection = document.querySelector?.('.hero-section') ?? null;
    const gravityTrinkets = [
        ...fgTrinkets,
        ...(document.querySelectorAll?.('.landing-ambient .tala-ambient-trinket') ?? []),
    ];
    const gravityAuroras = [...(document.querySelectorAll?.('.landing-ambient .tala-ambient-glow, .landing-aurora-blob') ?? [])];
    const gravityRing = document.querySelector?.('[data-hero-gravity-ring]') ?? null;
    const burstSparkEls = [...(document.querySelectorAll?.('[data-hero-burst-spark]') ?? [])];

    if (heroSection && (gravityTrinkets.length > 0 || gravityAuroras.length > 0) && typeof window.requestAnimationFrame === 'function') {
        const interactiveSelector = 'a, button, input, select, textarea, label, [role="button"], .navbar, .sheet-drag-handle';
        const goldenAngle = 2.399963229728653;
        const holdThresholdMs = 150;
        const moveCancelPx = 12;

        const bodies = gravityTrinkets.map((el, index) => ({
            el,
            isFg: fgTrinkets.includes(el),
            homeX: 0,
            homeY: 0,
            x: 0,
            y: 0,
            vx: 0,
            vy: 0,
            rot: 0,
            vrot: 0,
            scale: 1,
            orbitAngle: index * goldenAngle,
            orbitSpeed: (index % 2 === 0 ? 1 : -1) * (1.35 + ((index % 5) * 0.28)),
            orbitBaseRadius: 26 + ((index * 11) % 42),
        }));

        const auroraBodies = gravityAuroras.map((el, index) => ({
            el,
            homeX: 0,
            homeY: 0,
            x: 0,
            y: 0,
            vx: 0,
            vy: 0,
            scale: 1,
            vscale: 0,
            orbitAngle: index * 2.0943951023931953,
            orbitRadius: 36 + (index * 18),
        }));

        const sparks = burstSparkEls.map((el) => ({
            el,
            active: false,
            x: 0,
            y: 0,
            vx: 0,
            vy: 0,
            rot: 0,
            vrot: 0,
            age: 0,
            life: 0.75,
        }));

        let gravityState = 'idle'; // 'idle' | 'gathering' | 'burst' | 'returning'
        let pendingPointerId = null;
        let activePointerId = null;
        let holdStartTimer = null;
        let downX = 0;
        let downY = 0;
        let pointerX = 0;
        let pointerY = 0;
        let engageAt = 0;
        let burstAt = 0;
        let burstDurationMs = 460;
        let lastFrameAt = 0;
        let gravityRafId = null;
        let ringOpacity = 0;
        let ringScale = 0.4;

        const canRunHeroGravity = () => !reducedMotion.matches && !forcedColors.matches && !document.hidden;

        const clearHoldTimer = () => {
            if (holdStartTimer !== null) {
                window.clearTimeout?.(holdStartTimer);
                holdStartTimer = null;
            }
        };

        const syncBodyToDom = (body) => {
            body.el.style?.setProperty?.('--gx', `${body.x.toFixed(1)}px`);
            body.el.style?.setProperty?.('--gy', `${body.y.toFixed(1)}px`);
            if (body.isFg) {
                body.el.style?.setProperty?.('--gscale', body.scale.toFixed(3));
                body.el.style?.setProperty?.('--grot', `${body.rot.toFixed(1)}deg`);
            }
        };

        const syncAuroraToDom = (aurora) => {
            aurora.el.style?.setProperty?.('--gx', `${aurora.x.toFixed(1)}px`);
            aurora.el.style?.setProperty?.('--gy', `${aurora.y.toFixed(1)}px`);
            aurora.el.style?.setProperty?.('--gscale', aurora.scale.toFixed(3));
        };

        const syncRingToDom = () => {
            if (!gravityRing) {
                return;
            }

            if (ringOpacity <= 0.01) {
                gravityRing.style.opacity = '0';
                return;
            }

            gravityRing.style.opacity = ringOpacity.toFixed(3);
            gravityRing.style.transform = `translate3d(${pointerX.toFixed(1)}px, ${pointerY.toFixed(1)}px, 0) scale(${ringScale.toFixed(3)})`;
        };

        const resetAllToIdle = () => {
            clearHoldTimer();
            pendingPointerId = null;
            activePointerId = null;
            gravityState = 'idle';
            if (gravityRafId !== null) {
                window.cancelAnimationFrame?.(gravityRafId);
                gravityRafId = null;
            }
            heroSection.classList?.remove?.('is-gravity-holding');
            fgTrinketsContainer?.classList?.remove?.('is-gravity-active');
            ringOpacity = 0;
            syncRingToDom();

            bodies.forEach((body) => {
                body.x = 0;
                body.y = 0;
                body.vx = 0;
                body.vy = 0;
                body.rot = 0;
                body.vrot = 0;
                body.scale = 1;
                body.el.style?.removeProperty?.('--gx');
                body.el.style?.removeProperty?.('--gy');
                if (body.isFg) {
                    body.el.style?.removeProperty?.('--gscale');
                    body.el.style?.removeProperty?.('--grot');
                }
            });

            auroraBodies.forEach((aurora) => {
                aurora.x = 0;
                aurora.y = 0;
                aurora.vx = 0;
                aurora.vy = 0;
                aurora.scale = 1;
                aurora.vscale = 0;
                aurora.el.style?.removeProperty?.('--gx');
                aurora.el.style?.removeProperty?.('--gy');
                aurora.el.style?.removeProperty?.('--gscale');
            });

            sparks.forEach((spark) => {
                spark.active = false;
                spark.el.style.opacity = '0';
            });
        };

        const snapshotRestingHomes = () => {
            bodies.forEach((body) => {
                const rect = body.el.getBoundingClientRect?.();
                if (rect && (rect.width > 0 || rect.height > 0)) {
                    body.homeX = rect.left + (rect.width / 2) - body.x;
                    body.homeY = rect.top + (rect.height / 2) - body.y;
                } else {
                    body.homeX = (window.innerWidth || 1280) * 0.5;
                    body.homeY = (window.innerHeight || 800) * 0.5;
                }
            });

            auroraBodies.forEach((aurora) => {
                const rect = aurora.el.getBoundingClientRect?.();
                if (rect && (rect.width > 0 || rect.height > 0)) {
                    aurora.homeX = rect.left + (rect.width / 2) - aurora.x;
                    aurora.homeY = rect.top + (rect.height / 2) - aurora.y;
                } else {
                    aurora.homeX = (window.innerWidth || 1280) * 0.5;
                    aurora.homeY = (window.innerHeight || 800) * 0.5;
                }
            });
        };

        const triggerFireworksSparks = (originX, originY, charge) => {
            const count = sparks.length;
            if (!count) {
                return;
            }

            const speedBoost = 240 + (charge * 420);

            sparks.forEach((spark, index) => {
                const angle = ((Math.PI * 2 * index) / count) + (((index % 3) - 1) * 0.14);
                const variance = 0.72 + ((index % 5) * 0.14);
                const speed = speedBoost * variance;

                spark.active = true;
                spark.x = originX;
                spark.y = originY;
                spark.vx = Math.cos(angle) * speed;
                spark.vy = (Math.sin(angle) * speed) - (80 + charge * 90);
                spark.rot = 0;
                spark.vrot = (index % 2 === 0 ? 1 : -1) * (180 + (index * 24));
                spark.age = 0;
                spark.life = 0.58 + ((index % 4) * 0.11);
            });
        };

        const stepGravityLoop = (timestamp) => {
            const now = typeof timestamp === 'number' ? timestamp : nowMs();
            const dt = Math.min(0.05, Math.max(0.004, (now - lastFrameAt) / 1000));
            lastFrameAt = now;

            if (gravityState === 'gathering') {
                const charge = Math.min(1, Math.max(0, (now - engageAt) / 1400));
                const tighten = 1 - (charge * 0.58);
                const pullStiffness = 28 + (charge * 46);
                const damping = Math.exp(-7.5 * dt);
                const auroraStiffness = 14 + (charge * 22);
                const auroraDamping = Math.exp(-5.4 * dt);

                ringOpacity = 0.24 + (charge * 0.52);
                ringScale = 0.55 + (charge * 0.72) + (Math.sin(now / 150) * 0.06);
                syncRingToDom();

                bodies.forEach((body) => {
                    body.orbitAngle += body.orbitSpeed * (1 + charge * 2.1) * dt;
                    const radius = body.orbitBaseRadius * tighten;
                    const desiredScreenX = pointerX + (Math.cos(body.orbitAngle) * radius);
                    const desiredScreenY = pointerY + (Math.sin(body.orbitAngle) * radius);
                    const targetX = desiredScreenX - body.homeX;
                    const targetY = desiredScreenY - body.homeY;

                    body.vx = (body.vx + ((targetX - body.x) * pullStiffness * dt)) * damping;
                    body.vy = (body.vy + ((targetY - body.y) * pullStiffness * dt)) * damping;
                    body.x += body.vx * dt;
                    body.y += body.vy * dt;
                    body.rot += body.orbitSpeed * 38 * dt;
                    body.scale = 1 + (charge * 0.24);
                    syncBodyToDom(body);
                });

                auroraBodies.forEach((aurora) => {
                    aurora.orbitAngle += 0.65 * (1 + charge * 1.4) * dt;
                    const radius = aurora.orbitRadius * (1 - (charge * 0.5));
                    const targetX = (pointerX + (Math.cos(aurora.orbitAngle) * radius)) - aurora.homeX;
                    const targetY = (pointerY + (Math.sin(aurora.orbitAngle) * radius)) - aurora.homeY;
                    const targetScale = 1 - (charge * 0.34);

                    aurora.vx = (aurora.vx + ((targetX - aurora.x) * auroraStiffness * dt)) * auroraDamping;
                    aurora.vy = (aurora.vy + ((targetY - aurora.y) * auroraStiffness * dt)) * auroraDamping;
                    aurora.x += aurora.vx * dt;
                    aurora.y += aurora.vy * dt;
                    aurora.scale += (targetScale - aurora.scale) * Math.min(1, dt * 6);
                    syncAuroraToDom(aurora);
                });
            } else if (gravityState === 'burst' || gravityState === 'returning') {
                const elapsedMs = Math.max(0, now - burstAt);
                const returnRamp = Math.min(1, Math.max(0, (elapsedMs - 220) / 920));
                const smoothReturn = returnRamp * returnRamp * (3 - (2 * returnRamp));
                const burstWeight = 1 - smoothReturn;

                const trinketSpringK = 12.5 * smoothReturn;
                const trinketDamping = Math.exp(-(2.5 + (2.4 * smoothReturn)) * dt);
                const auroraSpringK = 8.2 * smoothReturn;
                const auroraDamping = Math.exp(-(2.1 + (2.0 * smoothReturn)) * dt);
                const gravityAccel = 190 * burstWeight;
                let allSettled = smoothReturn >= 0.99;

                ringOpacity = Math.max(0, ringOpacity - (dt * 2.2));
                ringScale += dt * 2.6;
                syncRingToDom();

                bodies.forEach((body) => {
                    body.vx = (body.vx + (-body.x * trinketSpringK * dt)) * trinketDamping;
                    body.vy = (body.vy + ((gravityAccel - (body.y * trinketSpringK)) * dt)) * trinketDamping;
                    body.x += body.vx * dt;
                    body.y += body.vy * dt;
                    body.vrot *= Math.exp(-3.4 * dt);
                    body.rot = (body.rot + (body.vrot * dt)) * Math.exp(-(3.2 * smoothReturn) * dt);
                    body.scale += (1 - body.scale) * Math.min(1, dt * (2.5 + (4.5 * smoothReturn)));

                    if (
                        Math.abs(body.x) > 0.65
                        || Math.abs(body.y) > 0.65
                        || Math.abs(body.vx) > 5
                        || Math.abs(body.vy) > 5
                    ) {
                        allSettled = false;
                    }

                    syncBodyToDom(body);
                });

                auroraBodies.forEach((aurora) => {
                    aurora.vx = (aurora.vx + (-aurora.x * auroraSpringK * dt)) * auroraDamping;
                    aurora.vy = (aurora.vy + (-aurora.y * auroraSpringK * dt)) * auroraDamping;
                    aurora.vscale = (aurora.vscale + ((1 - aurora.scale) * auroraSpringK * dt)) * auroraDamping;
                    aurora.x += aurora.vx * dt;
                    aurora.y += aurora.vy * dt;
                    aurora.scale += aurora.vscale * dt;

                    if (
                        Math.abs(aurora.x) > 0.9
                        || Math.abs(aurora.y) > 0.9
                        || Math.abs(aurora.scale - 1) > 0.012
                        || Math.abs(aurora.vx) > 6
                        || Math.abs(aurora.vy) > 6
                    ) {
                        allSettled = false;
                    }

                    syncAuroraToDom(aurora);
                });

                if (elapsedMs >= burstDurationMs) {
                    gravityState = 'returning';
                }

                let anySparkAlive = false;
                sparks.forEach((spark) => {
                    if (spark.active) {
                        anySparkAlive = true;
                    }
                });

                if (allSettled && !anySparkAlive && ringOpacity <= 0.01) {
                    resetAllToIdle();
                    return;
                }
            }

            // Update fireworks mini-sparks independently during burst + return
            const sparkDrag = Math.exp(-2.8 * dt);
            sparks.forEach((spark) => {
                if (!spark.active) {
                    return;
                }

                spark.age += dt;
                if (spark.age >= spark.life) {
                    spark.active = false;
                    spark.el.style.opacity = '0';
                    return;
                }

                const progress = spark.age / spark.life;
                spark.vy += 520 * dt;
                spark.vx *= sparkDrag;
                spark.vy *= sparkDrag;
                spark.x += spark.vx * dt;
                spark.y += spark.vy * dt;
                spark.rot += spark.vrot * dt;

                const fade = progress < 0.12 ? (progress / 0.12) : (1 - ((progress - 0.12) / 0.88));
                const scale = 1.15 - (progress * 0.55);
                spark.el.style.opacity = (Math.max(0, fade) * 0.92).toFixed(3);
                spark.el.style.transform = `translate3d(${spark.x.toFixed(1)}px, ${spark.y.toFixed(1)}px, 0) scale(${scale.toFixed(3)}) rotate(${spark.rot.toFixed(1)}deg)`;
            });

            if (gravityState !== 'idle') {
                gravityRafId = window.requestAnimationFrame(stepGravityLoop);
            }
        };

        const startGravityLoop = () => {
            lastFrameAt = nowMs();
            if (gravityRafId === null) {
                gravityRafId = window.requestAnimationFrame(stepGravityLoop);
            }
        };

        const engageGathering = () => {
            if (!canRunHeroGravity() || pendingPointerId === null) {
                return;
            }

            activePointerId = pendingPointerId;
            pendingPointerId = null;
            gravityState = 'gathering';
            engageAt = nowMs();
            heroSection.classList?.add?.('is-gravity-holding');
            fgTrinketsContainer?.classList?.add?.('is-gravity-active');
            snapshotRestingHomes();
            startGravityLoop();
        };

        const releaseGathering = () => {
            clearHoldTimer();
            pendingPointerId = null;

            if (gravityState !== 'gathering') {
                return;
            }

            const charge = Math.min(1, Math.max(0.18, (nowMs() - engageAt) / 1400));
            activePointerId = null;
            gravityState = 'burst';
            burstAt = nowMs();
            burstDurationMs = Math.round(380 + (charge * 180));
            heroSection.classList?.remove?.('is-gravity-holding');

            const burstPower = 380 + (charge * 520);

            bodies.forEach((body, index) => {
                const screenX = body.homeX + body.x;
                const screenY = body.homeY + body.y;
                const dx = screenX - pointerX;
                const dy = screenY - pointerY;
                const dist = Math.hypot(dx, dy);
                const fallbackAngle = index * goldenAngle;
                const nx = dist > 2 ? (dx / dist) : Math.cos(fallbackAngle);
                const ny = dist > 2 ? (dy / dist) : Math.sin(fallbackAngle);
                const variance = 0.78 + ((index % 5) * 0.12);

                body.vx = nx * burstPower * variance;
                body.vy = (ny * burstPower * variance) - (65 + charge * 85);
                body.vrot = (index % 2 === 0 ? 1 : -1) * (170 + charge * 220);
                body.scale = 1.16 + (charge * 0.18);
            });

            const auroraBurstPower = 180 + (charge * 280);

            auroraBodies.forEach((aurora, index) => {
                const dx = (aurora.homeX + aurora.x) - pointerX;
                const dy = (aurora.homeY + aurora.y) - pointerY;
                const dist = Math.hypot(dx, dy);
                const fallbackAngle = index * 2.0943951023931953;
                const nx = dist > 2 ? (dx / dist) : Math.cos(fallbackAngle);
                const ny = dist > 2 ? (dy / dist) : Math.sin(fallbackAngle);

                aurora.vx = nx * auroraBurstPower;
                aurora.vy = ny * auroraBurstPower;
                aurora.vscale = 0.9 + (charge * 0.85);
            });

            triggerFireworksSparks(pointerX, pointerY, charge);
            startGravityLoop();
        };

        heroSection.addEventListener?.('pointerdown', (event) => {
            if (!canRunHeroGravity()) {
                return;
            }

            if (event.button !== undefined && event.button !== 0) {
                return;
            }

            if (event.target?.closest?.(interactiveSelector)) {
                return;
            }

            clearHoldTimer();
            pendingPointerId = event.pointerId ?? 'mouse';
            downX = event.clientX ?? 0;
            downY = event.clientY ?? 0;
            pointerX = downX;
            pointerY = downY;

            holdStartTimer = window.setTimeout?.(() => {
                holdStartTimer = null;
                engageGathering();
            }, holdThresholdMs) ?? null;
        });

        window.addEventListener('pointermove', (event) => {
            const eventId = event.pointerId ?? 'mouse';

            if (pendingPointerId !== null && eventId === pendingPointerId) {
                const moveDist = Math.hypot((event.clientX ?? 0) - downX, (event.clientY ?? 0) - downY);
                if (moveDist > moveCancelPx) {
                    clearHoldTimer();
                    pendingPointerId = null;
                    return;
                }
                pointerX = event.clientX ?? pointerX;
                pointerY = event.clientY ?? pointerY;
                return;
            }

            if (gravityState === 'gathering' && activePointerId !== null && eventId === activePointerId) {
                pointerX = event.clientX ?? pointerX;
                pointerY = event.clientY ?? pointerY;
            }
        }, { passive: true });

        const handlePointerRelease = (event) => {
            const eventId = event?.pointerId ?? 'mouse';

            if (pendingPointerId !== null && eventId === pendingPointerId) {
                clearHoldTimer();
                pendingPointerId = null;
            }

            if (activePointerId !== null && eventId === activePointerId) {
                releaseGathering();
            }
        };

        window.addEventListener('pointerup', handlePointerRelease);
        window.addEventListener('pointercancel', handlePointerRelease);
        window.addEventListener('blur', resetAllToIdle);
        window.addEventListener('scroll', () => {
            if (pendingPointerId !== null) {
                clearHoldTimer();
                pendingPointerId = null;
            }

            if (gravityState !== 'idle' && (window.scrollY || 0) > (window.innerHeight || 800) * 0.75) {
                resetAllToIdle();
            }
        }, { passive: true });

        reducedMotion.addEventListener?.('change', () => {
            if (reducedMotion.matches) {
                resetAllToIdle();
            }
        });
        forcedColors.addEventListener?.('change', () => {
            if (forcedColors.matches) {
                resetAllToIdle();
            }
        });
    }

    const revealElements = [...document.querySelectorAll('[data-reveal]')];

    if (revealElements.length && !supportsScrollTimeline && allowsMotion && 'IntersectionObserver' in window) {
        document.documentElement?.classList?.add('has-reveal-fallback');

        const revealObserver = new window.IntersectionObserver((entries, observer) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-revealed');
                    observer.unobserve(entry.target);
                }
            });
        }, {
            threshold: 0.08,
            rootMargin: '0px 0px -32px 0px',
        });

        revealElements.forEach((element) => {
            const rect = element.getBoundingClientRect?.();

            if (rect && rect.top < window.innerHeight && rect.bottom > 0) {
                element.classList.add('is-revealed');
            } else {
                revealObserver.observe(element);
            }
        });
    }
};

if (document.readyState && document.readyState !== 'loading') {
    initPublicLanding();
} else {
    document.addEventListener('DOMContentLoaded', initPublicLanding);
}
