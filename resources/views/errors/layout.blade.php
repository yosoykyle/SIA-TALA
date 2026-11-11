<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $statusCode }} — {{ $pageTitle }} | Servitech Institute Asia Inc.</title>
    <link rel="icon" href="{{ asset('talalogo.png') }}">
    <link rel="stylesheet" href="{{ asset('fonts/filament/filament/inter/index.css') }}">
    <link rel="stylesheet" href="{{ asset('css/tala-foundation.css') }}">
    <link rel="stylesheet" href="{{ asset('css/tala-error.css') }}">
</head>
@php
    $admissionRecovery = request()->is('applicant*', 'admin/admission*', 'admissions/evidence/*', 'outputs/applications/*')
        || ($statusCode === 403 && auth()->user() instanceof \App\Models\User && (auth()->user()->hasRole('applicant') || auth()->user()->hasRole(\App\Models\User::StaffRoleRegistrar)));
@endphp
<body class="admission-recovery" data-recovery-code="{{ $statusCode }}">
    <a class="tala-skip-link" href="#error-content">Skip to main content</a>
    @php
        $authenticatedUser = $statusCode === 403 && auth()->user() instanceof \App\Models\User ? auth()->user() : null;
        $workspacePath = $authenticatedUser?->authorizedWorkspacePath();
        $workspaceName = $authenticatedUser?->authorizedWorkspaceName();
        $canSwitchAccount = $statusCode === 403 && $workspacePath !== null && $workspaceName !== null;
        $isApplicantVerificationFailure = $statusCode === 403
            && request()->routeIs('filament.applicant.auth.email-verification.verify')
            && $authenticatedUser?->hasRole('applicant')
            && ! $authenticatedUser->hasVerifiedEmail();
        $guidance = $isApplicantVerificationFailure
            ? 'This verification link is expired, malformed, or no longer the newest link. Request a new link from the verification page.'
            : $guidance;
        $mood = match (true) {
            $statusCode === 404 => 'This page wandered off.',
            $statusCode === 403 => 'This door is not yours to open.',
            $statusCode === 419 => 'You were away for a while.',
            $statusCode === 429 => 'Slow down a little.',
            $statusCode === 503 => 'We are tidying up.',
            $statusCode >= 500 => 'That one is on us.',
            default => 'Something is not right with that request.',
        };
    @endphp

    <main id="error-content" class="error-shell" aria-labelledby="error-title" tabindex="-1">
        <article class="error-card error-stage">
            <a class="brand" href="{{ url('/') }}" aria-label="Servitech Institute Asia home">
                <span class="brand-crest-plate">
                    <img src="{{ asset('images/brand/servitech-crest.webp') }}" alt="" aria-hidden="true" class="brand-crest" width="48" height="48">
                </span>
                <span class="brand-text">
                    <span class="brand-name">Servitech Institute Asia</span>
                </span>
            </a>

            <div class="error-hero">
            <div class="error-numeral" aria-hidden="true">
                @foreach (str_split((string) $statusCode) as $digit)<span>{{ $digit }}</span>@endforeach
                <svg class="error-plane" viewBox="0 0 220 120" focusable="false">
                    <path class="error-plane-trail" d="M6 96 C 46 108, 74 70, 52 56 C 30 42, 22 78, 62 80 C 104 82, 132 46, 168 34" />
                    <path class="error-plane-body" d="M170 22 L 214 8 L 190 46 L 183 33 Z" />
                    <path class="error-plane-fold" d="M183 33 L 214 8 L 176 30 Z" />
                </svg>
                <svg class="error-trinket error-trinket-spark" viewBox="0 0 24 24" focusable="false"><path d="M12 1 C 13 8, 16 11, 23 12 C 16 13, 13 16, 12 23 C 11 16, 8 13, 1 12 C 8 11, 11 8, 12 1 Z" /></svg>
                <svg class="error-trinket error-trinket-spark error-trinket-spark-small" viewBox="0 0 24 24" focusable="false"><path d="M12 1 C 13 8, 16 11, 23 12 C 16 13, 13 16, 12 23 C 11 16, 8 13, 1 12 C 8 11, 11 8, 12 1 Z" /></svg>
                <svg class="error-trinket error-trinket-ring" viewBox="0 0 24 24" focusable="false"><circle cx="12" cy="12" r="9" /></svg>
                <svg class="error-trinket error-trinket-plus" viewBox="0 0 24 24" focusable="false"><path d="M12 3 V 21 M3 12 H 21" /></svg>
                <svg class="error-trinket error-trinket-squiggle" viewBox="0 0 64 20" focusable="false"><path d="M2 10 C 8 0, 14 20, 20 10 S 32 0, 38 10 S 50 20, 62 10" /></svg>
                <svg class="error-trinket error-trinket-plane" viewBox="0 0 48 44" focusable="false"><path d="M2 16 L 46 2 L 22 40 L 15 27 Z" /></svg>
                <span class="error-trinket error-trinket-dot"></span>
                <span class="error-trinket error-trinket-dot error-trinket-dot-far"></span>
            </div>
            <div class="error-copy">
            <p class="error-mood">{{ $mood }}</p>
            @unless ($admissionRecovery)<p class="status-code">Error {{ $statusCode }}</p>@endunless
            <h1 id="error-title">{{ $pageTitle }}</h1>
            <p class="summary">{{ $summary }}</p>

            <section class="next-step" aria-labelledby="next-step-title">
                <h2 id="next-step-title">What you can do</h2>
                <p>{{ $guidance }}</p>
            </section>

            <div class="error-actions">
                @if ($isApplicantVerificationFailure)
                    <a class="primary-action" href="{{ route('filament.applicant.auth.email-verification.prompt') }}"><svg class="action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3.5 6.5h17v11h-17zM4 7l8 6.5L20 7" /></svg>Request a new verification link</a>
                    <button class="secondary-action" type="button" data-open-account-switch><svg class="action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 4 3 8l4 4M3 8h13a4 4 0 0 1 4 4M17 20l4-4-4-4M21 16H8a4 4 0 0 1-4-4" /></svg>Use another account</button>
                @elseif ($canSwitchAccount)
                    <a class="primary-action" href="{{ url($workspacePath) }}"><svg class="action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M10 6 4 12l6 6M4 12h16" /></svg>Return to {{ $workspaceName }}</a>
                    <button class="secondary-action" type="button" data-open-account-switch><svg class="action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 4 3 8l4 4M3 8h13a4 4 0 0 1 4 4M17 20l4-4-4-4M21 16H8a4 4 0 0 1-4-4" /></svg>Use another account</button>
                @else
                    <a class="primary-action" href="{{ url('/') }}"><svg class="action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 11.5 12 4l9 7.5M5.5 10v9.5h13V10M10 19.5v-5h4v5" /></svg>Back to home</a>
                @endif
                <a class="secondary-action" href="{{ route('home', ['modal' => 'support']) }}"><svg class="action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="8.5" /><circle cx="12" cy="12" r="3.5" /><path d="M6 6l3.5 3.5M18 6l-3.5 3.5M6 18l3.5-3.5M18 18l-3.5-3.5" /></svg>Contact school support</a>
            </div>
            @if ($admissionRecovery)
                <p class="status-code">HTTP {{ $statusCode }}</p>
            @endif
            </div>
            </div>
        </article>
    </main>

    @if ($canSwitchAccount)
        <dialog class="account-switch-dialog" data-account-switch-dialog aria-labelledby="account-switch-title">
            <form method="dialog" class="dialog-close-form">
                <button class="dialog-close" type="submit" aria-label="Close account switch confirmation">&times;</button>
            </form>
            <h2 id="account-switch-title">Use another account?</h2>
            <p>
                You are signed in as <strong>{{ $authenticatedUser->getFilamentName() }}</strong>
                ({{ $authenticatedUser->email }}). Continuing will securely sign out this account.
            </p>
            <div class="dialog-actions">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="primary-action" type="submit"><svg class="action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M14 4h5.5v16H14M10 8l-4 4 4 4M6 12h10" /></svg>Sign out and continue</button>
                </form>
                <a class="secondary-action" href="{{ url($workspacePath) }}"><svg class="action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M10 6 4 12l6 6M4 12h16" /></svg>Stay in {{ $workspaceName }}</a>
            </div>
        </dialog>
    @endif
    <script src="{{ asset('js/tala-error.js') }}" defer></script>
</body>
</html>
