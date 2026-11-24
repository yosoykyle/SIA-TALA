@php
    $resolvedRemainingSeconds = array_key_exists('remainingSeconds', get_defined_vars())
        ? ($remainingSeconds === null ? null : max(0, (int) $remainingSeconds))
        : (isset($expiresAtMilliseconds) && $expiresAtMilliseconds !== null
            ? max(0, (int) floor(($expiresAtMilliseconds - now()->getTimestampMs()) / 1000))
            : null);

    $resolvedClock = $initialClock
        ?? ($resolvedRemainingSeconds !== null
            ? sprintf('%02d:%02d', intdiv($resolvedRemainingSeconds, 60), $resolvedRemainingSeconds % 60)
            : null);
@endphp

<div class="tala-auth-verify-card tala-verification-card">
    <div class="tala-auth-verify-card__body space-y-2">
        <p class="tala-auth-verify-card__lead tala-verification-card__lead text-sm text-gray-700 dark:text-gray-200">
            A verification link was sent to your email address.
        </p>

        @if ($resolvedRemainingSeconds === null)
            <p class="tala-auth-verify-card__timer tala-verification-card__timer text-base font-semibold tabular-nums text-warning-700 dark:text-warning-300">
                Link expires 60 minutes after it was sent.
            </p>
        @else
            <p
                role="timer"
                wire:key="verification-countdown-{{ $sentAtKey ?? 'active' }}"
                class="tala-auth-verify-card__timer tala-verification-card__timer text-base font-semibold tabular-nums text-warning-700 dark:text-warning-300"
                x-data="{
                    initialSeconds: {{ $resolvedRemainingSeconds }},
                    startedAt: Date.now(),
                    elapsedSeconds: 0,
                    timerId: null,
                    init() {
                        this.timerId = setInterval(() => {
                            this.elapsedSeconds = Math.max(0, Math.floor((Date.now() - this.startedAt) / 1000));
                        }, 1000);
                    },
                    destroy() {
                        if (this.timerId !== null) {
                            clearInterval(this.timerId);
                        }
                    },
                    get remainingSeconds() {
                        return Math.max(0, this.initialSeconds - this.elapsedSeconds);
                    },
                    get clock() {
                        const minutes = Math.floor(this.remainingSeconds / 60);
                        const seconds = this.remainingSeconds % 60;

                        return String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
                    },
                }"
                x-text="remainingSeconds > 0 ? 'Link expires in ' + clock : 'This link has expired. Resend a new verification link.'"
            >
                Link expires in {{ $resolvedClock }}
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
