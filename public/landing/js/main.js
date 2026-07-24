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
    const themedSections = document.querySelectorAll('section[data-navbar-theme]');

    if (navbar && themedSections.length && 'IntersectionObserver' in window) {
        const navbarObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    navbar.classList.toggle(
                        'navbar-light-theme',
                        entry.target.getAttribute('data-navbar-theme') === 'light'
                    );
                }
            });
        }, {
            root: null,
            rootMargin: '0px 0px -90% 0px',
            threshold: 0
        });

        themedSections.forEach((section) => navbarObserver.observe(section));
    }

    colorScheme.addEventListener('change', (event) => {
        document.documentElement.setAttribute('data-bs-theme', event.matches ? 'dark' : 'light');
    });
});
