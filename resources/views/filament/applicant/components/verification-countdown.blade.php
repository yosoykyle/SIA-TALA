<div class="tala-auth-verify-card tala-verification-card">
    <div class="tala-auth-verify-card__body space-y-2">
        <p class="tala-auth-verify-card__lead tala-verification-card__lead text-sm text-gray-700 dark:text-gray-200">
            A verification link was sent to your email address.
        </p>

        @if ($expiresAtMilliseconds === null)
            <p class="tala-auth-verify-card__timer tala-verification-card__timer text-base font-semibold tabular-nums text-warning-700 dark:text-warning-300">
                Link expires 60 minutes after it was sent.
            </p>
        @else
            <p
                role="timer"
                class="tala-auth-verify-card__timer tala-verification-card__timer text-base font-semibold tabular-nums text-warning-700 dark:text-warning-300"
                x-data="{
                    expiresAt: {{ $expiresAtMilliseconds }},
                    now: Date.now(),
                    init() {
                        setInterval(() => this.now = Date.now(), 1000);
                    },
                    get remainingSeconds() {
                        return Math.max(0, Math.floor((this.expiresAt - this.now) / 1000));
                    },
                    get clock() {
                        const minutes = Math.floor(this.remainingSeconds / 60);
                        const seconds = this.remainingSeconds % 60;

                        return String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
                    },
                }"
                x-text="remainingSeconds > 0 ? 'Link expires in ' + clock : 'This link has expired. Resend a new verification link.'"
            >
                Link expires in {{ $initialClock }}
            </p>
        @endif

        <p class="tala-auth-verify-card__note tala-verification-card__note text-sm text-gray-600 dark:text-gray-300">
            Only the most recent verification link can be used.
        </p>
    </div>

    @if (isset($resendActionHtml))
        <div class="tala-auth-verify-card__actions tala-workspace-feature__action">
            {{ $resendActionHtml }}
        </div>
    @endif
</div>
