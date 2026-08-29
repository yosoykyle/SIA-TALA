@props(['title', 'headingId', 'wide' => false])
<!DOCTYPE html>
<html lang="en" data-tala-system-appearance>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — TALA</title>
    <link rel="icon" href="{{ asset('talalogo.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('fonts/filament/filament/inter/index.css') }}">
    <link rel="stylesheet" href="{{ asset('css/tala-foundation.css') }}">
</head>
<body class="tala-access-page">
    <a class="tala-skip-link" href="#tala-main-content">Skip to main content</a>
    <main id="tala-main-content" @class(['tala-access-main', 'tala-access-main-wide' => $wide]) tabindex="-1">
        <section class="tala-access-card" aria-labelledby="{{ $headingId }}">
            <x-tala-panel-brand workspace="TALA" />
            {{ $slot }}
            <nav class="tala-access-help" aria-label="Access help">
                <a href="{{ route('home') }}">TALA home</a>
                <a href="{{ route('home', ['modal' => 'support']) }}">Contact school support</a>
            </nav>
        </section>
    </main>
</body>
</html>
