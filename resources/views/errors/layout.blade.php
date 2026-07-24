<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $statusCode }} — {{ $pageTitle }} | TALA</title>
    <link rel="icon" href="{{ asset('talalogo.png') }}">
    <link rel="stylesheet" href="{{ asset('css/tala-error.css') }}">
</head>
<body>
    <main class="error-shell" aria-labelledby="error-title">
        <article class="error-card">
            <a class="brand" href="{{ url('/') }}" aria-label="TALA home">
                <img src="{{ asset('talalogo.png') }}" alt="" width="52" height="52">
                <span>TALA</span>
            </a>

            <p class="status-code">Error {{ $statusCode }}</p>
            <h1 id="error-title">{{ $pageTitle }}</h1>
            <p class="summary">{{ $summary }}</p>

            <section class="next-step" aria-labelledby="next-step-title">
                <h2 id="next-step-title">What you can do</h2>
                <p>{{ $guidance }}</p>
            </section>

            <a class="primary-action" href="{{ url('/') }}">Return to TALA home</a>
            <p class="support-note">If the problem continues, contact the office responsible for your request.</p>
        </article>
    </main>
</body>
</html>
