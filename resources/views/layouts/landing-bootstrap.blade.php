<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'TALA' }}</title>

    <script>
        (function () {
            var theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            document.documentElement.setAttribute('data-bs-theme', theme);
        })();
    </script>

    <link href="{{ asset('landing/vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('landing/css/styles.css') }}" rel="stylesheet">
    <link href="{{ asset('fonts/filament/filament/inter/index.css') }}" rel="stylesheet">
    <link href="{{ asset('css/tala-foundation.css') }}" rel="stylesheet">
    <link rel="icon" href="{{ asset('talalogo.png') }}">
</head>
<body>
    @yield('content')

    <script src="{{ asset('landing/vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('landing/js/main.js') }}"></script>
</body>
</html>
