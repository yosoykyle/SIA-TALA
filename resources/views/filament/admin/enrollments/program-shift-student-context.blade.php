@php
    /** @var \App\Models\StudentProfile|null $student */
    /** @var \App\Models\Term|null $term */
    /** @var \App\Models\StudentLifecycleChange|null $existingShift */
    /** @var \App\Models\Enrollment|null $existingEnrollment */
    /** @var list<string> $holdWarnings */
    /** @var int $passedSiaCount */
    /** @var bool $hasPriorTransferCredits */
@endphp
@if ($student !== null)
    <div class="space-y-3">
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm dark:border-white/10 dark:bg-white/5">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Current program</div>
                    <div class="mt-0.5 font-semibold text-gray-900 dark:text-white">{{ $student->program?->name ?? 'Unavailable' }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Current curriculum</div>
                    <div class="mt-0.5 font-semibold text-gray-900 dark:text-white">
                        {{ $student->curriculumVersion?->name ?? 'Unavailable' }}
                        @if ($student->curriculumVersion?->version_code)
                            <span class="font-normal text-gray-500 dark:text-gray-400">({{ $student->curriculumVersion->version_code }})</span>
                        @endif
                    </div>
                </div>
                <div>
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Status &amp; standing</div>
                    <div class="mt-0.5 font-semibold text-gray-900 dark:text-white">
                        {{ \App\Models\StudentProfile::lifecycleStatusLabel($student->lifecycle_status) }}
                        · {{ $student->academic_standing ?: 'Not recorded' }}
                    </div>
                </div>
                <div>
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Passed SIA results</div>
                    <div class="mt-0.5 font-semibold text-gray-900 dark:text-white">
                        {{ $passedSiaCount }} {{ \Illuminate\Support\Str::plural('subject', $passedSiaCount) }}
                    </div>
                </div>
            </div>
        </div>

        @if ($holdWarnings !== [])
            <div class="tala-notice--warning" role="alert">
                <h4>Active enrollment hold</h4>
                @foreach ($holdWarnings as $warning)
                    <p>{{ $warning }}</p>
                @endforeach
            </div>
        @endif

        @if ($existingEnrollment !== null)
            <div class="tala-notice--warning" role="alert">
                <h4>Existing enrollment in {{ $term?->label ?? 'this term' }}</h4>
                <p>
                    This student already has a {{ $existingEnrollment->statusLabel() }} enrollment ({{ $existingEnrollment->case_reference }}) for this term.
                    @if ($existingEnrollment->canonical_outcome === \App\Models\Enrollment::OutcomeOfficiallyEnrolled || $existingEnrollment->officially_enrolled_at !== null)
                        A program shift cannot target a term where the student is already officially enrolled.
                    @else
                        Resolve or cancel the existing case before applying a shift and starting a new enrollment.
                    @endif
                </p>
            </div>
        @endif

        @if ($hasPriorTransferCredits)
            <div class="rounded-lg border border-amber-300/70 bg-amber-50 p-3.5 text-sm text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                <p class="font-semibold">Prior external transfer credits on record</p>
                <p class="mt-1">
                    External transfer credits stay on the official Transcript of Records (TOR), but they are not auto-carried into the new curriculum.
                    Only released passing SIA subjects can be mapped as internal program-shift credits below.
                </p>
            </div>
        @endif

        @if ($existingShift !== null)
            @php
                $creditedCount = $existingShift->programShiftCredits
                    ->where('treatment', \App\Models\ProgramShiftCreditEntry::TreatmentAccepted)
                    ->where('state', \App\Models\ProgramShiftCreditEntry::StateRecorded)
                    ->count();
            @endphp
            <div class="rounded-lg border border-primary-300 bg-primary-50/70 p-4 text-sm text-gray-900 dark:border-primary-500/40 dark:bg-primary-500/10 dark:text-gray-100">
                <p class="font-semibold">
                    Existing approved shift found:
                    {{ $student->program?->name ?? 'Current program' }} → {{ $existingShift->targetProgram?->name ?? 'Target program' }}
                    · {{ $creditedCount }} {{ \Illuminate\Support\Str::plural('credit', $creditedCount) }}
                </p>
                <p class="mt-1 text-gray-700 dark:text-gray-300">
                    Target curriculum: <strong>{{ $existingShift->targetCurriculumVersion?->name ?? 'Unavailable' }}</strong>
                    @if ($existingShift->effective_on)
                        · Effective {{ $existingShift->effective_on->format('M j, Y') }}
                    @endif
                </p>
                <p class="mt-1 text-gray-700 dark:text-gray-300">
                    Continue to the next step to review and apply this approved shift and start an individually advised enrollment.
                </p>
            </div>
        @endif
    </div>
@endif
