@php
    $outcomeLabel = match ($enrollment->canonical_outcome) {
        \App\Models\Enrollment::OutcomeCancelledByLearner => 'Cancelled by you',
        \App\Models\Enrollment::OutcomeCancelledByRegistrar => 'Cancelled by the Registrar',
        \App\Models\Enrollment::OutcomeNotEnrolled => 'Not enrolled this term',
        default => 'Cancelled',
    };
    $closedOn = $enrollment->cancelled_at?->timezone(config('app.display_timezone'))->format('F j, Y');
@endphp
<div class="student-registration learner-status-page">
    <div class="student-registration-heading">
        <div>
            <h2>{{ $proposal?->curriculumVersion?->program?->name ?? $enrollment->studentProfile?->program?->name ?? $enrollment->admissionApplication?->program?->name ?? 'Program unavailable' }}</h2>
            <p>{{ \App\Models\Term::typeOptions()[$enrollment->term?->type] ?? $enrollment->term?->label ?? 'Term unavailable' }} · School year {{ $enrollment->term?->academicYear?->label ?? 'unavailable' }}</p>
        </div>
    </div>
    <section class="student-academic-notice tala-task-surface" aria-labelledby="closed-heading">
        <x-filament::badge color="danger">Enrollment closed</x-filament::badge>
        <h2 id="closed-heading">{{ $outcomeLabel }}</h2>
        <p>{{ $closedOn ? 'This enrollment was closed on '.$closedOn.'.' : 'This enrollment is closed.' }} Its temporary seats were released.</p>
        <p>Contact the Registrar to ask about reopening your enrollment. Your previous classes and enrollment approval need to be checked again.</p>
        @if ($isStudent)<div class="learner-status-actions"><x-filament::button tag="a" :href="\App\Filament\Student\Pages\Finance::getUrl(panel: 'student')" color="gray">View fees and payments</x-filament::button></div>@endif
    </section>
    <details class="learner-disclosure">
        <summary>What stays on record</summary>
        <div>
            <p>Money already verified stays on your account. Closing the enrollment does not refund it.</p>
            <dl class="learner-money">
                <div><dt>Verified payments</dt><dd>{{ $money($paymentRequirement['payment_applied'] ?? null) }}</dd></div>
                <div><dt>Approved coverage</dt><dd>{{ $money($paymentRequirement['coverage_applied'] ?? null) }}</dd></div>
                <div><dt>Confirmed subjects</dt><dd>{{ $confirmed && $proposal ? $proposal->items->count() : 'None confirmed' }}</dd></div>
            </dl>
        </div>
    </details>
</div>
