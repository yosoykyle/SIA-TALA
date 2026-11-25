<div class="tala-auth-verify-card">
    <div class="tala-auth-verify-card__body">
        <p class="tala-auth-verify-card__lead">
            @if (filled($email ?? null))
                We sent a verification link to <strong>{{ $email }}</strong>. Open the link in your inbox to verify your email address.
            @else
                We sent a verification link to your email address. Open the link in your inbox to verify your email address.
            @endif
        </p>
        <p class="tala-auth-verify-card__note">
            Check your spam or junk folder if you do not see it within a few minutes.
        </p>
    </div>
    <div class="tala-auth-verify-card__actions tala-workspace-feature__action">
        {{ $resendActionHtml }}
        {{ $logoutActionHtml }}
    </div>
</div>
