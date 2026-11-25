@props(['title', 'headingId', 'wide' => false])
<!DOCTYPE html>
<html lang="en" data-tala-system-appearance>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} | Servitech Institute Asia Inc.</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('images/brand/servitech-crest-favicon.png') }}">
    <script>
        (() => {
            try {
                const stored = localStorage.getItem('theme');
                const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                const mode = stored === 'dark' || stored === 'light' ? stored : (prefersDark ? 'dark' : 'light');
                document.documentElement.dataset.talaAppearance = mode;
                document.documentElement.classList.toggle('dark', mode === 'dark');
            } catch (_) {}
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('fonts/filament/filament/inter/index.css') }}">
    <link rel="stylesheet" href="{{ asset('css/tala-foundation.css') }}">
</head>
<body class="tala-access-page">
    <a class="tala-skip-link" href="#tala-main-content">Skip to main content</a>
    <main id="tala-main-content" @class(['tala-access-main', 'tala-access-main-wide' => $wide]) tabindex="-1">
        <section class="tala-access-card" aria-labelledby="{{ $headingId }}">
            <header class="tala-access-header">
                <x-tala-panel-brand workspace="Servitech Institute Asia" />
                <button
                    type="button"
                    class="tala-access-theme-toggle"
                    data-tala-theme-toggle
                    aria-label="Switch color theme"
                    title="Switch light or dark theme"
                >
                    <svg class="tala-access-theme-icon tala-access-theme-icon--sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                    </svg>
                    <svg class="tala-access-theme-icon tala-access-theme-icon--moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                    </svg>
                </button>
            </header>
            {{ $slot }}
            <nav class="tala-access-help" aria-label="Access help">
                <a href="{{ route('home') }}">Servitech Institute Asia home</a>
                <a href="{{ route('home', ['modal' => 'support']) }}">Contact support</a>
            </nav>
        </section>
    </main>
    <script>
        (() => {
            const btn = document.querySelector('[data-tala-theme-toggle]');
            if (!btn) return;
            const syncLabel = () => {
                const isDark = document.documentElement.classList.contains('dark');
                btn.setAttribute('aria-pressed', String(isDark));
                btn.setAttribute('aria-label', isDark ? 'Switch to light theme' : 'Switch to dark theme');
            };
            syncLabel();
            btn.addEventListener('click', () => {
                const next = document.documentElement.classList.contains('dark') ? 'light' : 'dark';
                document.documentElement.dataset.talaAppearance = next;
                document.documentElement.classList.toggle('dark', next === 'dark');
                try { localStorage.setItem('theme', next); } catch (_) {}
                syncLabel();
            });
        })();
    </script>
</body>
</html>
