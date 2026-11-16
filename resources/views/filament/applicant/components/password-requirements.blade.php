<div class="mt-1 space-y-1 text-sm">
    <p class="text-gray-600 dark:text-gray-400">
        Use 15–64 characters. Spaces and password-manager paste are allowed.
    </p>
    <p
        aria-live="polite"
        aria-atomic="true"
        @class([
            'flex items-center gap-2',
            'text-success-600 dark:text-success-400' => $check['met'],
            'text-danger-600 dark:text-danger-400' => $check['too_long'],
            'text-gray-500 dark:text-gray-400' => ! $check['met'] && ! $check['too_long'],
        ])
    >
        <x-filament::icon
            :icon="$check['met'] ? 'heroicon-m-check-circle' : ($check['too_long'] ? 'heroicon-m-exclamation-circle' : 'heroicon-o-minus-circle')"
            class="h-4 w-4 shrink-0"
            aria-hidden="true"
        />
        <span>{{ $check['label'] }}</span>
        <span class="sr-only">{{ $check['met'] ? 'Met' : 'Not met' }}</span>
    </p>
</div>
