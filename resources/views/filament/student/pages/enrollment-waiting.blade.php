@php
    $seatDeadline = $proposal?->items->map(fn ($item) => $item->reservation?->deadline)->filter()->min();
    $subjectCount = $proposal?->items->count() ?? 0;
@endphp
<div class="student-registration learner-status-page">
    <div class="student-registration-heading">
        <div>
            <h2>{{ $proposal?->curriculumVersion?->program?->name ?? $enrollment->studentProfile?->program?->name ?? $enrollment->admissionApplication?->program?->name ?? 'Program unavailable' }}</h2>
            <p>{{ \App\Models\Term::typeOptions()[$enrollment->term?->type] ?? $enrollment->term?->label ?? 'Term unavailable' }} · School year {{ $enrollment->term?->academicYear?->label ?? 'unavailable' }}</p>
        </div>
    </div>
    <section class="student-academic-notice tala-task-surface" aria-labelledby="waiting-heading" role="status">
        <x-filament::badge color="warning">Not official yet</x-filament::badge>
        <h2 id="waiting-heading">Your part is complete</h2>
        <p>The <strong>Registrar</strong> checks your records and finalizes enrollment. Your Certificate of Registration appears here after that.</p>
        <x-workflow-handoff current-owner="Registrar" current-task="Finalize enrollment" next-owner="You" next-task="View registration" current-icon="heroicon-o-clipboard-document-check" next-icon="heroicon-o-document-text" />
        <dl class="learner-money">
            <div><dt>Subjects confirmed</dt><dd>{{ $subjectCount }} {{ $subjectCount === 1 ? 'subject' : 'subjects' }} · {{ (float) $proposal?->items->sum('units_snapshot') }} units</dd></div>
            <div><dt>Enrollment payment</dt><dd>{{ ($paymentRequirement['satisfaction_basis'] ?? '') === 'NoPaymentRequired' ? 'No payment required' : 'Requirement met' }}</dd></div>
        </dl>
        @if ($seatDeadline)<p class="student-registration-help">Temporary seats are reserved until {{ $seatDeadline->timezone(config('app.display_timezone'))->format('M j, Y · g:i A') }}.</p>@endif
        <div class="learner-status-actions">
            <x-filament::button wire:click="$refresh" color="gray" icon="heroicon-o-arrow-path">Refresh status</x-filament::button>
            @if ($isStudent)<x-filament::link :href="\App\Filament\Student\Pages\Finance::getUrl(panel: 'student')">View fees and payments</x-filament::link>@endif
        </div>
    </section>
    @if ($proposal && $proposal->items->isNotEmpty())
        @include('filament.student.pages.enrollment-subjects', ['preparing' => false, 'subjectsHeading' => 'Confirmed subjects', 'showSummary' => false])
    @endif
    @if ($schedulePresentation && $schedulePresentation['meetings'] !== [])
        <details class="learner-disclosure"><summary>Reserved schedule</summary><x-enrollment-schedule :presentation="$schedulePresentation" :source-key="'proposal-'.$proposal->id" /></details>
    @endif
    <x-enrollment-email-retry :action="$this->resendRegistrationEmailAction" />
</div>
