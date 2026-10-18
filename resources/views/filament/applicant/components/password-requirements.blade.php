<ul class="mt-1 space-y-1 text-sm" aria-label="Password requirements">
    @foreach ($checks as $check)
        <li @class([
            'flex items-center gap-2',
            'text-success-600 dark:text-success-400' => $check['met'],
            'text-gray-500 dark:text-gray-400' => ! $check['met'],
        ])>
            <x-filament::icon
                :icon="$check['met'] ? 'heroicon-m-check-circle' : 'heroicon-o-minus-circle'"
                class="h-4 w-4 shrink-0"
                aria-hidden="true"
            />
            <span>{{ $check['label'] }}</span>
            <span class="sr-only">{{ $check['met'] ? 'Met' : 'Not met' }}</span>
        </li>
    @endforeach
</ul>
