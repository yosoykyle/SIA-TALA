/* TALA public landing interactions. Requires the locally served Bootstrap 5.3 bundle. */

document.addEventListener('DOMContentLoaded', () => {
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const colorScheme = window.matchMedia('(prefers-color-scheme: dark)');
    const scrollButton = document.querySelector('.btn-scroll-top');

    if (scrollButton) {
        const updateScrollButton = () => {
            scrollButton.classList.toggle('visible', window.scrollY > 300);
        };

        scrollButton.addEventListener('click', () => {
            window.scrollTo({
                top: 0,
                behavior: reducedMotion.matches ? 'auto' : 'smooth'
            });
        });

        window.addEventListener('scroll', updateScrollButton, { passive: true });
        updateScrollButton();
    }

    const navbar = document.querySelector('.navbar');
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
        scheduleNavbarContrastUpdate();
    }

    colorScheme.addEventListener('change', (event) => {
        document.documentElement.setAttribute('data-bs-theme', event.matches ? 'dark' : 'light');
        scheduleNavbarContrastUpdate();
    });
});
