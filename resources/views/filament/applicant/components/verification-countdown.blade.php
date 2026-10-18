<div class="space-y-2">
    <p class="text-sm text-gray-300 dark:text-gray-300">A verification link was sent to your email address.</p>

    @if ($expiresAtMilliseconds === null)
        <p class="text-base font-semibold tabular-nums text-warning-600 dark:text-warning-400">
            Link expires 60 minutes after it was sent.
        </p>
    @else
        <p
            role="timer"
            class="text-base font-semibold tabular-nums text-warning-600 dark:text-warning-400"
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

    <p class="text-sm text-gray-400 dark:text-gray-400">Only the most recent verification link can be used.</p>
</div>
