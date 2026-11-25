<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'TALA' }}</title>

    <script>
        (function () {
            var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            var theme = prefersDark ? 'dark' : 'light';
            var root = document.documentElement;
            root.setAttribute('data-bs-theme', theme);
            root.classList.toggle('dark', prefersDark);
            root.style.colorScheme = theme;
        })();
    </script>

    <link href="{{ asset('landing/vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('fonts/filament/filament/inter/index.css') }}" rel="stylesheet">
    <link href="{{ asset('css/tala-foundation.css') }}" rel="stylesheet">
    <link href="{{ asset('landing/css/styles.css') }}" rel="stylesheet">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('images/brand/servitech-crest-favicon.png') }}">
</head>
<body>
    @yield('content')

    <script src="{{ asset('landing/vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('landing/js/main.js') }}"></script>
    <script src="{{ asset('js/tala-reference.js') }}" defer></script>
</body>
</html>
