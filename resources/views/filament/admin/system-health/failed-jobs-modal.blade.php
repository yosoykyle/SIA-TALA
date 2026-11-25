@php
    /** @var list<array{id: int, uuid: string, job_name: string, queue: string, failed_at_display: string, exception_summary: string}> $jobs */
    $jobs = $jobs ?? [];
@endphp

@if ($jobs === [])
    <div class="tala-profile-inset text-sm" style="color: var(--tala-muted);">
        No failed queue jobs are currently recorded.
    </div>
@else
    <ul class="divide-y divide-gray-200 dark:divide-white/10">
        @foreach ($jobs as $job)
            <li class="space-y-1.5 py-3 first:pt-0 last:pb-0">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-semibold text-gray-950 dark:text-white">
                            {{ $job['job_name'] }}
                        </span>
                        <x-filament::badge color="gray" size="sm">
                            Queue: {{ $job['queue'] }}
                        </x-filament::badge>
                    </div>
                    <time class="text-xs text-gray-500 tabular-nums dark:text-gray-400">
                        {{ $job['failed_at_display'] }} (Asia/Manila)
                    </time>
                </div>
                <p class="text-xs text-gray-600 dark:text-gray-300 break-words">
                    {{ $job['exception_summary'] }}
                </p>
            </li>
        @endforeach
    </ul>
@endif
