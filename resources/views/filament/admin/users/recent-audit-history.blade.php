@php
    /** @var list<array{event: string, occurred_at: string, actor: string, source: string, status: string}> $events */
    $events = $events ?? (isset($getState) ? ($getState() ?? []) : []);
@endphp

@if ($events === [])
    <p class="text-sm text-gray-500 dark:text-gray-400">
        No recent account or security events recorded yet.
    </p>
@else
    <ul class="divide-y divide-gray-200 dark:divide-white/10">
        @foreach ($events as $event)
            <li class="flex flex-col gap-1 py-2.5 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-950 dark:text-white">
                        {{ $event['event'] }}
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $event['source'] }} · By {{ $event['actor'] }}
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <span @class([
                        'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                        'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-400/10 dark:text-emerald-400 dark:ring-emerald-400/20' => $event['status'] === 'Recorded',
                        'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-400/10 dark:text-amber-400 dark:ring-amber-400/20' => $event['status'] === 'Attention',
                        'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-400/10 dark:text-gray-300 dark:ring-gray-400/20' => ! in_array($event['status'], ['Recorded', 'Attention'], true),
                    ])>
                        {{ $event['status'] }}
                    </span>
                    <time class="text-xs text-gray-500 tabular-nums dark:text-gray-400">
                        {{ $event['occurred_at'] }}
                    </time>
                </div>
            </li>
        @endforeach
    </ul>
@endif
