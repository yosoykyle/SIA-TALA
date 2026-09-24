<div class="space-y-4">
    @forelse ($applications as $application)
        @php
            $existingCase = $casesMap[$application->id] ?? null;
            $isOfficiallyEnrolled = $existingCase?->canonical_outcome === \App\Models\Enrollment::OutcomeOfficiallyEnrolled;
            $isCancelled = $existingCase && in_array($existingCase->canonical_outcome, \App\Models\Enrollment::cancelledOutcomes(), true);
            $isNotEnrolled = $existingCase?->canonical_outcome === \App\Models\Enrollment::OutcomeNotEnrolled;
        @endphp
        <article class="rounded-lg border border-slate-200 bg-white p-4 shadow-xs dark:border-white/10 dark:bg-gray-900">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-xs font-semibold uppercase tracking-wider text-slate-900 dark:text-white">
                            {{ $application->application_reference }}
                        </span>
                        @if (! $existingCase)
                            <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-amber-600/20 ring-inset dark:bg-amber-950 dark:text-amber-300">
                                Ready for Case Creation
                            </span>
                        @elseif ($isOfficiallyEnrolled)
                            <span class="inline-flex items-center rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-emerald-600/20 ring-inset dark:bg-emerald-950 dark:text-emerald-300">
                                Officially Enrolled: {{ $existingCase->case_reference }}
                            </span>
                        @elseif ($isCancelled)
                            <span class="inline-flex items-center rounded-md bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700 ring-1 ring-rose-600/20 ring-inset dark:bg-rose-950 dark:text-rose-300">
                                {{ str($existingCase->canonical_outcome)->headline() }}: {{ $existingCase->case_reference }}
                            </span>
                        @elseif ($isNotEnrolled)
                            <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 ring-1 ring-slate-600/20 ring-inset dark:bg-gray-800 dark:text-gray-300">
                                Not Enrolled: {{ $existingCase->case_reference }}
                            </span>
                        @else
                            <span class="inline-flex items-center rounded-md bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700 ring-1 ring-sky-600/20 ring-inset dark:bg-sky-950 dark:text-sky-300">
                                Case Active: {{ $existingCase->case_reference }} ({{ str($existingCase->status)->headline() }})
                            </span>
                        @endif
                    </div>
                    <h4 class="text-sm font-semibold text-slate-900 dark:text-white">
                        {{ collect([$application->first_name, $application->middle_name, $application->last_name])->filter()->implode(' ') }}
                    </h4>
                    <p class="text-xs text-slate-600 dark:text-gray-400">
                        {{ $application->program?->name }} ({{ $application->program?->code }}) · {{ str($application->application_path)->headline() }} · {{ $application->admissionCycle?->label }}
                    </p>
                    <p class="text-xs font-medium text-slate-700 dark:text-gray-300">
                        Exact Term: <span class="font-semibold">{{ $application->term?->label ?? 'Unassigned' }}</span>
                    </p>
                </div>
                <div class="shrink-0 pt-1">
                    @if ($existingCase)
                        <a
                            href="{{ \App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('view', ['record' => $existingCase]) }}"
                            class="inline-flex min-h-[44px] items-center justify-center gap-1 rounded-md bg-white px-3 py-2 text-xs font-semibold text-slate-900 shadow-xs ring-1 ring-slate-300 ring-inset hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#1e4976] sm:min-h-0 sm:px-2.5 sm:py-1.5 dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:hover:bg-gray-700"
                        >
                            Open case &rarr;
                        </a>
                    @else
                        <button
                            type="button"
                            wire:click="startRegistrationForApplicant({{ $application->id }}, {{ $application->term_id ?? 'null' }})"
                            wire:loading.attr="disabled"
                            class="inline-flex min-h-[44px] items-center justify-center gap-1 rounded-md bg-[#0f2b48] px-3 py-2 text-xs font-semibold text-white shadow-xs hover:bg-[#1e4976] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#1e4976] disabled:opacity-50 sm:min-h-0 sm:px-2.5 sm:py-1.5 dark:bg-primary-600 dark:hover:bg-primary-500"
                        >
                            Start registration &rarr;
                        </button>
                    @endif
                </div>
            </div>
            <div class="mt-3 border-t border-slate-100 pt-2 text-xs text-slate-500 dark:border-white/5 dark:text-gray-400">
                @if (! $existingCase)
                    <p>Derived ready from verified admissions credentials for exact term {{ $application->term?->label }}.</p>
                @elseif ($isOfficiallyEnrolled)
                    <p class="font-medium text-emerald-700 dark:text-emerald-400">Next step: Official enrollment finalized.</p>
                @elseif ($isCancelled || $isNotEnrolled)
                    <p class="font-medium text-rose-700 dark:text-rose-400">Next step: Case closed/cancelled. Consult Registrar for authorized reopening or subsequent term intake.</p>
                @else
                    <p class="font-medium text-slate-600 dark:text-gray-300">Next step: Proceed with proposal course placement, schedule conflict review, and checkpoint clearance on case dossier.</p>
                @endif
            </div>
        </article>
    @empty
        <x-filament::callout color="gray" icon="heroicon-m-information-circle">
            <x-slot name="heading">No ready applicants</x-slot>
            <x-slot name="description">Applications appear automatically when the derived Admissions projection is ready.</x-slot>
        </x-filament::callout>
    @endforelse
</div>
