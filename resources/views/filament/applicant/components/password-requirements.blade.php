@php
    $initialPassword = (string) ($password ?? '');
    $initialConfirmation = (string) ($passwordConfirmation ?? '');
    $initialHasConfirmation = $initialConfirmation !== '';
    $initialMatch = $initialPassword !== '' && $initialHasConfirmation && $initialPassword === $initialConfirmation;
    $initialMismatch = $initialHasConfirmation && $initialPassword !== $initialConfirmation;
@endphp

<div
    class="tala-password-checklist -mt-2 rounded-lg border border-gray-200/80 bg-gray-50/75 px-3 py-2 text-xs dark:border-white/10 dark:bg-white/[0.03]"
    data-tala-password-checklist
    x-data="{
        password: @js($initialPassword),
        confirmation: @js($initialConfirmation),
        get length() { return Array.from(this.password || '').length; },
        get lengthMet() { return this.length >= 15 && this.length <= 64; },
        get tooLong() { return this.length > 64; },
        get lengthLabel() {
            if (this.length === 0) return '0 of 15–64 characters entered';
            if (this.tooLong) return `${this.length} of 15–64 characters entered (maximum 64)`;
            if (this.lengthMet) return `${this.length} of 15–64 characters entered (ready)`;
            return `${this.length} of 15–64 characters entered (minimum 15)`;
        },
        get lengthStatusWord() {
            return this.lengthMet ? 'Met' : ['Not', 'met'].join(' ');
        },
        get hasConfirmation() { return (this.confirmation || '').length > 0; },
        get passwordsMatch() { return this.length > 0 && this.hasConfirmation && this.password === this.confirmation; },
        get matchMismatch() { return this.hasConfirmation && this.password !== this.confirmation; },
        get matchStatusLabel() {
            if (this.passwordsMatch) return 'Matched';
            if (this.matchMismatch) return 'Does not match yet';
            return 'Confirm below';
        },
        syncFromDom() {
            const form = this.$el.closest('form') || document;
            const pw = form.querySelector('[data-tala-password-input=\'password\']');
            const cf = form.querySelector('[data-tala-password-input=\'password_confirmation\']');
            if (pw) this.password = pw.value || '';
            if (cf) this.confirmation = cf.value || '';
        }
    }"
    x-init="syncFromDom()"
    x-on:input.window="if ($event.target?.matches?.('[data-tala-password-input]')) syncFromDom()"
    x-on:change.window="if ($event.target?.matches?.('[data-tala-password-input]')) syncFromDom()"
>
    <ul class="grid gap-1 sm:grid-cols-2 sm:gap-2" role="list" aria-label="Password requirements">
        <li
            data-password-requirement="length"
            aria-live="polite"
            aria-atomic="true"
            x-bind:class="{
                'text-success-600 dark:text-success-400': lengthMet,
                'text-danger-600 dark:text-danger-400': tooLong,
                'text-gray-600 dark:text-gray-300': !lengthMet && !tooLong
            }"
            @class([
                'flex items-center gap-1.5 font-medium',
                'text-success-600 dark:text-success-400' => $check['met'],
                'text-danger-600 dark:text-danger-400' => $check['too_long'],
                'text-gray-600 dark:text-gray-300' => ! $check['met'] && ! $check['too_long'],
            ])
        >
            <x-filament::icon
                :icon="$check['met'] ? 'heroicon-m-check-circle' : ($check['too_long'] ? 'heroicon-m-exclamation-circle' : 'heroicon-o-minus-circle')"
                class="h-3.5 w-3.5 shrink-0"
                aria-hidden="true"
            />
            <span>15–64 characters</span>
            <span class="sr-only" x-text="lengthLabel">{{ $check['label'] }}</span>
            <span class="sr-only" x-text="lengthStatusWord">{{ $check['met'] ? 'Met' : 'Not met' }}</span>
        </li>

        <li
            data-password-requirement="match"
            x-bind:class="{
                'text-success-600 dark:text-success-400': passwordsMatch,
                'text-amber-600 dark:text-amber-400': matchMismatch,
                'text-gray-600 dark:text-gray-300': !passwordsMatch && !matchMismatch
            }"
            @class([
                'flex items-center gap-1.5 font-medium',
                'text-success-600 dark:text-success-400' => $initialMatch,
                'text-amber-600 dark:text-amber-400' => $initialMismatch,
                'text-gray-600 dark:text-gray-300' => ! $initialMatch && ! $initialMismatch,
            ])
        >
            <x-filament::icon
                :icon="$initialMatch ? 'heroicon-m-check-circle' : 'heroicon-o-minus-circle'"
                class="h-3.5 w-3.5 shrink-0"
                aria-hidden="true"
            />
            <span>Passwords match</span>
            <span class="sr-only" x-text="matchStatusLabel">{{ $initialMatch ? 'Matched' : ($initialMismatch ? 'Does not match yet' : 'Confirm below') }}</span>
        </li>
    </ul>

    <p class="mt-1 flex flex-wrap items-center justify-between gap-x-2 gap-y-0.5 text-[11px] text-gray-500 dark:text-gray-400">
        <span>Spaces and password managers are supported.</span>
        <span class="tabular-nums text-gray-600 dark:text-gray-300" x-text="`${length} / 15–64`">{{ $check['length'] }} / 15–64</span>
        <span class="sr-only">Use 15–64 characters. Spaces and password-manager paste are allowed.</span>
    </p>
</div>
