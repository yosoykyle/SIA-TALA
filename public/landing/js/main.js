/* ============================================================
   TALALANDIT — Landing Page Scripts
   Requires Bootstrap 5.3.3 bundle (served locally from public/landing)
   ============================================================ */

document.addEventListener('DOMContentLoaded', () => {
    // ---- Scroll-to-top star button ----
    const scrollBtn = document.querySelector('.btn-scroll-top');

    if (scrollBtn) {
        // Click: scroll to top
        scrollBtn.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        // Show/hide based on scroll position (300px threshold)
        window.addEventListener('scroll', () => {
            if (window.scrollY > 300) {
                scrollBtn.classList.add('visible');
            } else {
                scrollBtn.classList.remove('visible');
            }
        }, { passive: true });
    }

    // ---- Navbar theme auto-switch via IntersectionObserver ----
    const navbar = document.querySelector('.navbar');
    const sections = document.querySelectorAll('section[data-navbar-theme]');

    if (navbar && sections.length) {
        // Only observe where the navbar actually sits (top strip of viewport)
        const observerOptions = {
            root: null,
            // Negative bottom margin = only trigger when section crosses the top ~80px
            rootMargin: '0px 0px -90% 0px',
            threshold: 0
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const theme = entry.target.getAttribute('data-navbar-theme');

                    if (theme === 'light') {
                        navbar.classList.add('navbar-light-theme');
                    } else {
                        navbar.classList.remove('navbar-light-theme');
                    }
                }
            });
        }, observerOptions);

        sections.forEach(section => observer.observe(section));
    }

    // ---- Live system theme detection ----
    // Updates data-bs-theme in real-time if user changes OS dark/light mode
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
        document.documentElement.setAttribute('data-bs-theme', e.matches ? 'dark' : 'light');
    });

    // ---- Typewriter viewport scroll reveal observer ----
    const typewriterElements = document.querySelectorAll('.typewriter');
    const typewriterTimers = new Map(); // track active timers per element

    function startTyping(el) {
        // Read the full text from the visually-hidden span
        const fullText = (el.querySelector('.visually-hidden') || el).textContent.trim();
        let index = 0;

        el.setAttribute('data-typed', '');
        el.classList.add('active');

        function typeNext() {
            if (index <= fullText.length) {
                el.setAttribute('data-typed', fullText.slice(0, index));
                index++;
                const timer = setTimeout(typeNext, 75); // ~75ms per character
                typewriterTimers.set(el, timer);
            } else {
                typewriterTimers.delete(el);
            }
        }

        typeNext();
    }

    function resetTyping(el) {
        // Cancel any running timer
        if (typewriterTimers.has(el)) {
            clearTimeout(typewriterTimers.get(el));
            typewriterTimers.delete(el);
        }
        el.setAttribute('data-typed', '');
        el.classList.remove('active');
    }

    if (typewriterElements.length) {
        const typewriterObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    startTyping(entry.target);
                } else {
                    resetTyping(entry.target);
                }
            });
        }, { threshold: 0.15 });

        typewriterElements.forEach(el => {
            el.setAttribute('data-typed', ''); // start empty
            typewriterObserver.observe(el);
        });
    }
});
