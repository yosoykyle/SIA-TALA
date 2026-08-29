/* TALA public landing interactions. Requires the locally served Bootstrap 5.3 bundle. */

document.addEventListener('DOMContentLoaded', () => {
    const colorScheme = window.matchMedia('(prefers-color-scheme: dark)');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const scrollButton = document.querySelector('.btn-scroll-top');
    const skipLink = document.querySelector('.tala-skip-link');

    skipLink?.addEventListener('click', () => {
        window.requestAnimationFrame(() => {
            document.getElementById(skipLink.hash.slice(1))?.focus({ preventScroll: true });
        });
    });

    if (scrollButton) {
        const updateScrollButton = () => {
            scrollButton.classList.toggle('visible', window.scrollY > 300);
        };

        scrollButton.addEventListener('click', () => {
            document.getElementById('main-content')?.focus({ preventScroll: true });
            window.scrollTo({
                top: 0,
                behavior: reducedMotion.matches ? 'auto' : 'smooth'
            });
        });

        window.addEventListener('scroll', updateScrollButton, { passive: true });
        updateScrollButton();
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

    if (navigation && navigationToggle && window.bootstrap?.Collapse) {
        const closeNavigation = () => window.bootstrap.Collapse.getOrCreateInstance(navigation, { toggle: false }).hide();

        navigation.addEventListener('shown.bs.collapse', () => {
            navigationToggle.setAttribute('aria-label', 'Close navigation menu');
            navigation.querySelector('a[href]')?.focus({ preventScroll: true });
        });
        navigation.addEventListener('hidden.bs.collapse', () => {
            navigationToggle.setAttribute('aria-label', 'Open navigation menu');
        });
        navigation.addEventListener('click', (event) => {
            const link = event.target.closest('a[href^="#"]');

            if (!link || !navigation.classList.contains('show')) {
                return;
            }

            const destination = document.getElementById(link.hash.slice(1));

            if (destination) {
                navigation.addEventListener('hidden.bs.collapse', () => {
                    destination.setAttribute('tabindex', '-1');
                    destination.focus({ preventScroll: true });
                }, { once: true });
            }

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
                .map((element) => element.closest('[data-navbar-contrast-surface]'))
                .find((element) => element);
            const surfaceTone = surface?.getAttribute('data-navbar-contrast-surface') ?? 'dark';
            const isLightSurface = surfaceTone === 'light'
                || (
                    surfaceTone === 'theme'
                    && document.documentElement.getAttribute('data-bs-theme') !== 'dark'
                );

            return isLightSurface ? 'black' : 'white';
        };

        const updateNavbarContrast = () => {
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

    colorScheme.addEventListener('change', (event) => {
        document.documentElement.setAttribute('data-bs-theme', event.matches ? 'dark' : 'light');
        scheduleNavbarContrastUpdate();
    });
});
