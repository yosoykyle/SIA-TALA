@php
    $preview = $state['preview'];
    $hasFinanceReview = data_get($preview, 'finance_effect.mode') === 'accounting_review_required';
    $pending = $preview['pending_application'] ?? false;
    $statusAfter = $pending ? ($preview['profile_status_on_application'] ?? null) : ($preview['profile_status_after'] ?? null);
    $applicationDate = filled($preview['application_available_on'] ?? null) ? \Carbon\Carbon::parse($preview['application_available_on'])->format('M j, Y') : null;
@endphp
<aside class="tala-lifecycle-effects" aria-labelledby="lifecycle-effects-heading" aria-live="polite">
    <header><h2 class="fi-section-header-heading" id="lifecycle-effects-heading">Review effects</h2></header>
    @if ($hasTerm)
        <dl class="tala-record-fact tala-context-inset">
            <dt>Enrollment in this term</dt>
            <dd>{{ $enrollment?->statusLabel() ?? 'No enrollment recorded.' }}</dd>
        </dl>
    @endif
    @if (! $state['valid'])
        <div class="tala-context-inset"><strong>Complete the details to review</strong><p>{{ $state['message'] }}</p></div>
    @else
            @if ($student?->lifecycle_status !== $statusAfter)
                <x-record-change-comparison before-label="Current status" after-label="After application">
                    <x-slot:before><strong>{{ \App\Models\StudentProfile::lifecycleStatusLabel($student?->lifecycle_status) }}</strong></x-slot:before>
                    <x-slot:after>
                        <strong>{{ \App\Models\StudentProfile::lifecycleStatusLabel($statusAfter) }}</strong>
                        @if ($pending)<p>Applies from {{ $applicationDate }}.</p>@endif
                    </x-slot:after>
                </x-record-change-comparison>
            @else
                <p>Status remains <strong>{{ \App\Models\StudentProfile::lifecycleStatusLabel($student?->lifecycle_status) }}</strong>.</p>
            @endif
            @if ($pending)
                <p>Current status and enrollment stay unchanged until the Registrar applies this approval.</p>
                @if ($student?->lifecycle_status === $statusAfter)<p>Applies from {{ $applicationDate }}.</p>@endif
            @endif
            <dl class="tala-lifecycle-effects-list">
                @if (! $pending)
                <div>
                    <dt>Affected subjects</dt>
                    <dd>{{ count($preview['course_enrollment_ids']) }} in this term
                        @if (count($preview['affected_subjects']))
                            <p>{{ collect($preview['affected_subjects'])->map(fn ($subject) => collect([$subject['code'], $subject['title']])->filter()->implode(' · '))->implode(', ') }}</p>
                        @endif
                    </dd>
                </div>
                @endif
                @if (count($preview['binding_ids']) || count($preview['reservation_ids']))
                    <div><dt>Schedule and seats</dt><dd>{{ count($preview['binding_ids']) }} student assignments and {{ count($preview['reservation_ids']) }} reservations affected</dd></div>
                @endif
                @if ($preview['cor_available_before'] && ! $preview['cor_available_after'])
                    <div><dt>Enrollment record (COR)</dt><dd>Unavailable after this change.</dd></div>
                @endif
                @if (data_get($preview, 'program_before.id') !== data_get($preview, 'program_after.id'))
                    <div><dt>Program</dt><dd>{{ data_get($preview, 'program_before.name') }} → {{ data_get($preview, 'program_after.name') }}</dd></div>
                @endif
                @if ($preview['type'] === \App\Models\StudentLifecycleChange::TypeProgramShift)
                    @php($creditedCount = collect($preview['program_shift_credits'])->where('treatment', \App\Models\ProgramShiftCreditEntry::TreatmentAccepted)->count())
                    <div><dt>Recognized subjects</dt><dd>{{ $creditedCount }} credited.
                        @if ($creditedCount === 0)<p>No subjects are credited by this change. Target curriculum requirements remain to be completed.</p>@endif
                    </dd></div>
                @endif
                @if ($preview['active_hold_count'])
                    <div><dt>Existing restrictions</dt><dd>{{ $preview['active_hold_count'] }} remain unchanged.</dd></div>
                @endif
            </dl>
        <p class="tala-lifecycle-preserved tala-context-inset"><x-filament::icon icon="heroicon-o-shield-check" aria-hidden="true" />Released grades and published timetable stay unchanged.</p>
        @if ($hasFinanceReview)
            <p class="tala-lifecycle-supporting">No automatic refund or balance change.</p>
        @endif
    @endif
</aside>
