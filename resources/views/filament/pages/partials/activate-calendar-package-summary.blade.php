<div class="space-y-4 rounded-xl border border-slate-200 bg-slate-50/60 p-4 text-sm dark:border-white/10 dark:bg-gray-800/60">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 pb-3 dark:border-white/10">
        <div>
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Target Term</span>
            <h3 class="text-base font-semibold text-slate-900 dark:text-white">
                {{ $term->academicYear?->label }} · {{ $term->label }}
            </h3>
            <p class="text-xs text-slate-500 dark:text-slate-400">Exact Term ID: {{ $term->id }} · {{ $term->label }}</p>
        </div>
        <span @class([
            'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset',
            'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-950 dark:text-emerald-300' => in_array($term->state, [\App\Models\Term::StateActive, 'Active', 'ACTIVE'], true),
            'bg-slate-100 text-slate-700 ring-slate-600/20 dark:bg-gray-700 dark:text-gray-300' => ! in_array($term->state, [\App\Models\Term::StateActive, 'Active', 'ACTIVE'], true),
        ])>
            Term State: {{ ucfirst(strtolower($term->state)) }}
        </span>
    </div>

    @if (! $package)
        <div class="py-3 text-center text-slate-600 dark:text-slate-300">
            <p class="text-sm">Please select a draft package above to review its authority, readiness, and downstream operational windows before activating.</p>
        </div>
    @else
        <!-- Package Authority & Dates -->
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <span class="text-xs font-medium text-slate-500 dark:text-slate-400">Package Version & Authority</span>
                <p class="font-semibold text-slate-900 dark:text-white">v{{ $package->version }} · {{ $package->authority_reference ?? 'No authority reference' }}</p>
                @if ($package->authority_date)
                    <p class="text-xs text-slate-500 dark:text-slate-400">Approved: {{ $package->authority_date->toDateString() }}</p>
                @endif
            </div>
            <div>
                <span class="text-xs font-medium text-slate-500 dark:text-slate-400">Administrative & Class Dates</span>
                <p class="text-slate-900 dark:text-white">Admin: {{ $package->administrative_starts_on?->toDateString() }} to {{ $package->administrative_ends_on?->toDateString() }}</p>
                <p class="text-xs text-slate-600 dark:text-slate-300">Classes: {{ $package->classes_start_on?->toDateString() }} to {{ $package->classes_end_on?->toDateString() }}</p>
            </div>
        </div>

        <!-- Readiness Summary -->
        <div
            @if($readiness && $readiness['ready'])
                x-data
                x-init="window.dispatchEvent(new CustomEvent('close-notification', { detail: { id: 'calendar_package_activation_feedback' } }))"
            @endif
            class="rounded-lg border p-3 @if($readiness && $readiness['ready']) border-emerald-200 bg-emerald-50/70 dark:border-emerald-500/30 dark:bg-emerald-950/30 @else border-amber-200 bg-amber-50/70 dark:border-amber-500/30 dark:bg-amber-950/30 @endif"
        >
            <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-semibold uppercase tracking-wider @if($readiness && $readiness['ready']) text-emerald-800 dark:text-emerald-200 @else text-amber-800 dark:text-amber-200 @endif">
                    Package Readiness
                </span>
                @if ($readiness && $readiness['ready'])
                    <span role="status" class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300">
                        All required checks passed
                    </span>
                @else
                    <span role="alert" class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900/50 dark:text-amber-300">
                        Action required ({{ count($readiness['blockers'] ?? []) }} blocker{{ count($readiness['blockers'] ?? []) === 1 ? '' : 's' }})
                    </span>
                @endif
            </div>

            @if ($readiness && ! $readiness['ready'])
                <ul class="mt-2 space-y-1.5 text-xs text-amber-900 dark:text-amber-200">
                    @foreach ($readiness['blockers'] as $blocker)
                        <li>
                            <strong>{{ $blocker['source'] }}:</strong> {{ $blocker['reason'] }}
                            <span class="block text-slate-600 dark:text-slate-300">Action: {{ $blocker['next_action'] }} ({{ $blocker['recovery'] }})</span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-1 text-xs text-emerald-700 dark:text-emerald-300">
                    All calendar authority, date bounds, operational windows, and teaching grid checks have passed. Ready for activation.
                </p>
            @endif
        </div>

        <!-- Downstream Operational Windows -->
        <div class="space-y-2 border-t border-slate-200 pt-3 dark:border-white/10">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Downstream operational windows</span>
            @php
                $typeLabels = \App\Models\TermCalendarWindow::typeOptions();
                $canonicalTypes = array_keys($typeLabels);
                $recordedWindows = $package->windows->sortBy(function ($w) use ($canonicalTypes) {
                    $idx = array_search($w->window_type, $canonicalTypes, true);
                    return $idx !== false ? $idx : 999;
                });
                $coreTypes = [
                    \App\Models\TermCalendarWindow::TypeEnrollment,
                    \App\Models\TermCalendarWindow::TypeExaminationPeriod,
                    \App\Models\TermCalendarWindow::TypeGradeEntry,
                ];
                $missingCoreTypes = collect($coreTypes)->filter(fn ($t) => ! $recordedWindows->contains('window_type', $t));
            @endphp
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($recordedWindows as $w)
                    <div class="rounded-md border border-slate-200 bg-white p-2.5 dark:border-white/10 dark:bg-gray-900">
                        <p class="font-medium text-slate-900 dark:text-white">{{ $typeLabels[$w->window_type] ?? $w->window_type }}</p>
                        <p class="text-xs text-slate-600 dark:text-slate-300">
                            {{ $w->opens_on?->toDateString() ?? 'Not set' }} to {{ $w->closes_on?->toDateString() ?? 'Not set' }}
                        </p>
                        @if ($w->cutoff_at)
                            <p class="text-xs text-slate-400">Cutoff: {{ substr((string) $w->cutoff_at, 0, 5) }}</p>
                        @endif
                    </div>
                @endforeach
                @foreach ($missingCoreTypes as $missingType)
                    <div class="rounded-md border border-dashed border-amber-300 bg-amber-50/50 p-2.5 dark:border-amber-500/30 dark:bg-amber-950/20">
                        <p class="font-medium text-amber-900 dark:text-amber-200">{{ $typeLabels[$missingType] ?? $missingType }}</p>
                        <p class="text-xs text-amber-700 dark:text-amber-400">Not recorded</p>
                    </div>
                @endforeach
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400">
                <strong>Policy boundary:</strong> Activating this package sets the operational calendar dates and marks the Term as Active. Package activation does not itself open enrollment; enrollment also requires an official published timetable and its remaining readiness prerequisites.
            </p>
        </div>

        <!-- Activation Consequence Notice -->
        <div class="border-t border-slate-200 pt-3 text-xs text-slate-600 dark:border-white/10 dark:text-slate-300">
            <p class="font-medium text-slate-900 dark:text-white">Activation consequences:</p>
            <ul class="mt-1 list-disc pl-4 space-y-0.5">
                <li>Draft v{{ $package->version }} becomes <strong>Active</strong>, with activation timestamp and acting Registrar recorded.</li>
                <li>Target Term <strong>{{ $term->label }}</strong> is marked <strong>Active</strong>.</li>
                <li>Any previously active package for this exact Term becomes <strong>Closed</strong>. Active packages for other Terms remain unchanged.</li>
            </ul>
        </div>
    @endif
</div>
