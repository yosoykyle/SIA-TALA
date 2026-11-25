@php
    /** @var array{valid: bool, preview: array<string, mixed>, message: string} $state */
    /** @var \App\Models\StudentProfile|null $student */
    /** @var \App\Models\StudentLifecycleChange|null $existingShift */
    /** @var bool $canApplyNow */
    /** @var ?string $applyBlockedReason */
    /** @var bool $applyAndEnroll */
    /** @var int $alreadySatisfiedCount */
    /** @var int $acceptedCreditCount */
    /** @var int $remainingCount */
    $preview = $state['preview'] ?? [];
@endphp
<aside class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm dark:border-white/10 dark:bg-white/5" aria-live="polite">
    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Review effects</h3>

    @if ($existingShift !== null)
        @php
            $creditedEntries = $existingShift->programShiftCredits
                ->where('treatment', \App\Models\ProgramShiftCreditEntry::TreatmentAccepted)
                ->where('state', \App\Models\ProgramShiftCreditEntry::StateRecorded);
        @endphp
        <dl class="mt-3 grid gap-2 sm:grid-cols-2">
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Program</dt>
                <dd class="font-medium text-gray-900 dark:text-white">
                    {{ $student?->program?->name ?? 'Current program' }} → {{ $existingShift->targetProgram?->name ?? 'Target program' }}
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Curriculum</dt>
                <dd class="font-medium text-gray-900 dark:text-white">
                    {{ $student?->curriculumVersion?->name ?? 'Current curriculum' }} → {{ $existingShift->targetCurriculumVersion?->name ?? 'Target curriculum' }}
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Recorded shift credits</dt>
                <dd class="font-medium text-gray-900 dark:text-white">
                    {{ $creditedEntries->count() }} accepted {{ \Illuminate\Support\Str::plural('subject', $creditedEntries->count()) }}
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Enrollment routing</dt>
                <dd class="font-medium text-gray-900 dark:text-white">Individually Advised (Status: Irregular – Shiftee)</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Accounting</dt>
                <dd class="text-gray-700 dark:text-gray-300">No enrollment-linked Accounting lifecycle review is required.</dd>
            </div>
        </dl>
        @if (! $canApplyNow && filled($applyBlockedReason))
            <div class="tala-notice--warning mt-3" role="alert">
                <h4>Cannot apply and start enrollment right now</h4>
                <p>{{ $applyBlockedReason }}</p>
            </div>
        @endif
    @elseif (! $state['valid'])
        <div class="tala-notice--warning mt-3" role="alert">
            <h4>Complete the required details to review effects</h4>
            <p>{{ $state['message'] }}</p>
        </div>
    @else
        <dl class="mt-3 grid gap-2 sm:grid-cols-2">
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Program</dt>
                <dd class="font-medium text-gray-900 dark:text-white">
                    {{ data_get($preview, 'program_before.name') }} → {{ data_get($preview, 'program_after.name') }}
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Curriculum</dt>
                <dd class="font-medium text-gray-900 dark:text-white">
                    {{ data_get($preview, 'curriculum_version_before.name') }} → {{ data_get($preview, 'curriculum_version_after.name') }}
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Credit summary</dt>
                <dd class="font-medium text-gray-900 dark:text-white">
                    {{ $acceptedCreditCount }} shift {{ \Illuminate\Support\Str::plural('credit', $acceptedCreditCount) }}
                    @if ($alreadySatisfiedCount > 0)
                        · {{ $alreadySatisfiedCount }} already satisfied by SIA record
                    @endif
                    · {{ $remainingCount }} remaining
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Enrollment routing</dt>
                <dd class="font-medium text-gray-900 dark:text-white">
                    @if ($canApplyNow && $applyAndEnroll)
                        Individually Advised (Status: Irregular – Shiftee) · routes to Prepare subjects
                    @else
                        Recorded as approved · student stays on current program until applied
                    @endif
                </dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Accounting</dt>
                <dd class="text-gray-700 dark:text-gray-300">{{ data_get($preview, 'finance_effect.message', 'No enrollment-linked Accounting review is required.') }}</dd>
            </div>
            @if (($preview['active_hold_count'] ?? 0) > 0)
                <div class="sm:col-span-2">
                    <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Existing restrictions</dt>
                    <dd class="text-gray-700 dark:text-gray-300">{{ $preview['active_hold_count'] }} active {{ \Illuminate\Support\Str::plural('hold', (int) $preview['active_hold_count']) }} remain unchanged.</dd>
                </div>
            @endif
        </dl>
    @endif

    <p class="mt-3 flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400">
        <x-filament::icon icon="heroicon-o-shield-check" class="h-4 w-4 shrink-0" aria-hidden="true" />
        <span>Released grades and earlier academic history stay unchanged.</span>
    </p>
</aside>
