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

    document.querySelectorAll('[data-program-detail-modal]').forEach((modalElement) => {
        const programId = modalElement.dataset.programDetailModal;

        modalElement.addEventListener('shown.bs.modal', () => replaceProgramParameter(programId));
        modalElement.addEventListener('hidden.bs.modal', () => {
            replaceProgramParameter(null);
            document.querySelector(`[data-program-read-more="${programId}"]`)?.focus({ preventScroll: true });
        });
    });

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
