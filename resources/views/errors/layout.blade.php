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
    @php
        $authenticatedUser = auth()->user() instanceof \App\Models\User ? auth()->user() : null;
        $workspacePath = $authenticatedUser?->authorizedWorkspacePath();
        $workspaceName = $authenticatedUser?->authorizedWorkspaceName();
        $canSwitchAccount = $statusCode === 403 && $workspacePath !== null && $workspaceName !== null;
    @endphp

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

            <div class="error-actions">
                @if ($canSwitchAccount)
                    <a class="primary-action" href="{{ url($workspacePath) }}">Return to {{ $workspaceName }}</a>
                    <button class="secondary-action" type="button" data-open-account-switch>Use another account</button>
                @else
                    <a class="primary-action" href="{{ url('/') }}">Return to TALA home</a>
                @endif
            </div>
            <p class="support-note">If the problem continues, contact the office responsible for your request.</p>
        </article>
    </main>

    @if ($canSwitchAccount)
        <dialog class="account-switch-dialog" data-account-switch-dialog aria-labelledby="account-switch-title">
            <form method="dialog" class="dialog-close-form">
                <button class="dialog-close" type="submit" aria-label="Close account switch confirmation">&times;</button>
            </form>
            <h2 id="account-switch-title">Use another TALA account?</h2>
            <p>
                You are signed in as <strong>{{ $authenticatedUser->name }}</strong>
                ({{ $authenticatedUser->email }}). Continuing will securely sign out this account.
            </p>
            <div class="dialog-actions">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="primary-action" type="submit">Sign out and continue</button>
                </form>
                <a class="secondary-action" href="{{ url($workspacePath) }}">Stay in {{ $workspaceName }}</a>
            </div>
        </dialog>
        <script src="{{ asset('js/tala-error.js') }}" defer></script>
    @endif
</body>
</html>
