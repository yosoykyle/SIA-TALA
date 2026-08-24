<x-filament-panels::page>
    <div class="space-y-6">
        @if ($captureNotice !== null)
            <div
                @class([
                    'rounded-xl border px-4 py-3 text-sm',
                    'border-warning-300 bg-warning-50 text-warning-900 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-100' => $captureStale,
                    'border-gray-200 bg-white text-gray-700 dark:border-white/10 dark:bg-gray-900 dark:text-gray-200' => ! $captureStale,
                ])
                role="status"
            >
                <span class="font-semibold">{{ $captureStale ? 'Stale capture.' : 'Current capture.' }}</span>
                {{ $captureNotice }}
            </div>
        @endif

        <x-filament::section>
            <x-slot name="heading">Evidence boundary</x-slot>
            <x-slot name="description">
                System Health reports bounded local evidence. External provider, custody, SLA, backup, and recovery facts remain Unknown until separately verified.
            </x-slot>

            <dl class="grid gap-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="font-medium text-gray-950 dark:text-white">Prospective RPO target</dt>
                    <dd class="text-gray-600 dark:text-gray-300">{{ config('tala_operations.prospective_rpo_hours') }} hours — planning target, not achieved evidence</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-950 dark:text-white">Prospective RTO target</dt>
                    <dd class="text-gray-600 dark:text-gray-300">{{ config('tala_operations.prospective_rto_hours') }} hours — planning target, not achieved evidence</dd>
                </div>
            </dl>
        </x-filament::section>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
