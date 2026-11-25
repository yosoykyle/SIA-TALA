@php
    /** @var list<array{
     *     id: int,
     *     term: string,
     *     requested_by: string,
     *     requested_at_display: string,
     *     status_label: string,
     *     status_color: string,
     *     outcome: string,
     *     candidate_rows_count: int,
     *     runtime_display: string,
     *     attempts: int,
     *     solver_version: string,
     *     failure_classification: string
     * }> $runs */
    $runs = $runs ?? [];
@endphp

@if ($runs === [])
    <div class="tala-profile-inset text-sm" style="color: var(--tala-muted);">
        No timetable solver runs are recorded yet.
    </div>
@else
    <div class="space-y-3">
        @foreach ($runs as $run)
            <article class="tala-profile-inset space-y-2.5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-2 min-w-0">
                        <span class="text-sm font-semibold" style="color: var(--tala-text);">
                            Run #{{ $run['id'] }} · {{ $run['term'] }}
                        </span>
                        <x-filament::badge :color="$run['status_color']" size="sm">
                            {{ $run['status_label'] }}
                        </x-filament::badge>
                        <x-filament::badge color="gray" size="sm">
                            {{ $run['outcome'] }}
                        </x-filament::badge>
                    </div>
                    <time class="text-xs tabular-nums" style="color: var(--tala-muted);">
                        {{ $run['requested_at_display'] }} (Asia/Manila)
                    </time>
                </div>

                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-xs sm:grid-cols-4">
                    <div>
                        <dt style="color: var(--tala-muted);">Requested by</dt>
                        <dd class="font-medium" style="color: var(--tala-text);">{{ $run['requested_by'] }}</dd>
                    </div>
                    <div>
                        <dt style="color: var(--tala-muted);">Runtime</dt>
                        <dd class="font-medium tabular-nums" style="color: var(--tala-text);">{{ $run['runtime_display'] }}</dd>
                    </div>
                    <div>
                        <dt style="color: var(--tala-muted);">Candidate rows</dt>
                        <dd class="font-medium tabular-nums" style="color: var(--tala-text);">{{ number_format($run['candidate_rows_count']) }}</dd>
                    </div>
                    <div>
                        <dt style="color: var(--tala-muted);">Attempts</dt>
                        <dd class="font-medium tabular-nums" style="color: var(--tala-text);">{{ number_format($run['attempts']) }}</dd>
                    </div>
                </dl>

                <div class="flex flex-wrap items-center justify-between gap-2 border-t pt-2 text-xs" style="border-color: var(--tala-edge); color: var(--tala-muted);">
                    <span>Solver: {{ $run['solver_version'] }}</span>
                    <span>Failure: {{ $run['failure_classification'] }}</span>
                </div>
            </article>
        @endforeach
    </div>
@endif
