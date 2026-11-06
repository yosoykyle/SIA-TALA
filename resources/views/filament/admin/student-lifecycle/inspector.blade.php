@php
    $student = $change->studentProfile;
    $pending = $change->isPendingChange();
    $applicationAudit = $change->applicationAudit;
    $effects = $applicationAudit?->properties->get('application_effects') ?: $change->impact_snapshot;
    $sourceBlocked = $change->isPendingProgramShift() && (! $change->targetProgram || ! $change->targetCurriculumVersion);
    $future = $pending && $change->isFutureDated();
    $cancelled = $change->state === \App\Models\StudentLifecycleChange::StateCancelled;
    $shift = $change->type === \App\Models\StudentLifecycleChange::TypeProgramShift;
    $financeReview = ! $cancelled && ! $pending && data_get($effects, 'finance_effect.mode') === 'accounting_review_required';
    $studentUrl = $student && \Illuminate\Support\Facades\Gate::allows('view', $student)
        ? \App\Filament\Resources\StudentProfiles\StudentProfileResource::getUrl('view', ['record' => $student, 'viewTab' => 'academic-progress'])
        : null;
    $currentArea = match (true) {
        $sourceBlocked => 'Lifecycle · Source review',
        $cancelled => 'Lifecycle · Cancelled approval',
        $future => 'Lifecycle · Recorded approval',
        $pending => $shift ? 'Lifecycle · Apply program shift' : 'Lifecycle · Apply approval',
        default => 'Lifecycle · Recorded change',
    };
    $currentTask = match (true) {
        $sourceBlocked => 'Review the missing target program or curriculum with the Registrar before application. This approval can still be cancelled.',
        $cancelled => 'This approval was cancelled before application. It changed no current student facts.',
        $future => 'Approval is recorded. Current student facts stay unchanged; it is not applied automatically.',
        $pending => 'Review the approved change, then apply it against the current student record.',
        default => 'The approved change is recorded. Review its original effects before deciding any financial adjustment.',
    };
    $nextOwner = $cancelled || $sourceBlocked ? null : ($financeReview ? 'Accounting' : ($future ? 'Registrar' : 'Student'));
    $nextArea = $financeReview ? 'Student accounts · Financial review' : ($future ? ($shift ? 'Lifecycle · Apply program shift' : 'Lifecycle · Apply approval') : 'Student record · Program and status');
    $accountUrl = $financeReview && auth()->user()?->hasRole(\App\Models\User::StaffRoleAccounting)
        && $change->enrollment && \Illuminate\Support\Facades\Gate::allows('view', $change->enrollment)
        ? \App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('view', ['record' => $change->enrollment])
        : null;
    $nextTask = match (true) {
        $financeReview => 'Review any financial consequence separately. No automatic refund or balance change.',
        $future => 'Return here on or after '.$change->applicationAvailableOn()->format('M j, Y').' to review and apply this approval.',
        $pending => 'The applied change appears in the student record. Expected return does not automatically readmit a student.',
        default => 'Read the recorded change in School record changes. Contact the Registrar about corrections.',
    };
@endphp
<x-record-identity :name="$student ? collect([$student->first_name, $student->middle_name, $student->last_name])->filter()->implode(' ') : 'Student record unavailable'" :number="$student?->student_number ?? 'Student number unavailable'" :program="$student?->program?->name" :photo-url="$student?->user?->getFilamentAvatarUrl()" :initials="$student ? mb_substr($student->first_name, 0, 1).mb_substr($student->last_name, 0, 1) : null" />
<section class="tala-lifecycle-inspector-section" aria-labelledby="lifecycle-details-heading">
    <header><h3 id="lifecycle-details-heading">Original change record</h3><x-filament::badge :color="$change->statusColor()">{{ $change->statusLabel() }}</x-filament::badge></header>
    <dl class="tala-lifecycle-details">
        <div><dt>Change</dt><dd>{{ \App\Models\StudentLifecycleChange::typeOptions()[$change->type] ?? str($change->type)->headline() }}</dd></div>
        <div><dt>Effective date and term</dt><dd>{{ $change->effective_on->format('M j, Y') }} · {{ collect([$change->term?->label, $change->term?->academicYear?->label])->filter()->implode(' · ') ?: 'Term not recorded' }}</dd></div>
        @if ($change->expectedReturnTerm)<div><dt>Expected return</dt><dd>{{ collect([$change->expectedReturnTerm->label, $change->expectedReturnTerm->academicYear?->label])->filter()->implode(' · ') }}</dd></div>@endif
    </dl>
</section>
@if ($shift)
    <section class="tala-lifecycle-inspector-section" aria-labelledby="lifecycle-program-heading">
        <header><h3 id="lifecycle-program-heading">Program information</h3></header>
        <x-record-change-comparison before-label="Program before change" after-label="Approved program" :highlight-after="! $cancelled && ! $sourceBlocked">
            <x-slot:before><strong>{{ data_get($change->impact_snapshot, 'program_before.name') ?? 'Not recorded' }}</strong>@if ($curriculum = data_get($change->impact_snapshot, 'curriculum_version_before.name'))<p>{{ $curriculum }}</p>@endif</x-slot:before>
            <x-slot:after><strong>{{ data_get($change->impact_snapshot, 'program_after.name') ?? $change->targetProgram?->name ?? 'Target program unavailable' }}</strong><p>{{ data_get($change->impact_snapshot, 'curriculum_version_after.name') ?? $change->targetCurriculumVersion?->name ?? 'Target curriculum unavailable' }}</p></x-slot:after>
        </x-record-change-comparison>
        <p class="tala-lifecycle-inspector-note">Earlier academic records stay unchanged. No new enrollment or class placement is created.</p>
    </section>
@else
    <section class="tala-lifecycle-inspector-section" aria-labelledby="lifecycle-effect-heading">
        <header><h3 id="lifecycle-effect-heading">{{ $pending ? 'Approved effect' : 'Recorded effect' }}</h3></header>
        @if ($status = data_get($effects, $pending ? 'profile_status_on_application' : 'profile_status_after'))
            <p>{{ $pending ? 'Student status on application' : 'Student status after this change' }}: <strong>{{ \App\Models\StudentProfile::lifecycleStatusLabel($status) }}</strong></p>
        @else
            <p class="tala-lifecycle-inspector-note">The original student-status effect is not recorded.</p>
        @endif
        @if ($pending)<p class="tala-lifecycle-inspector-note">Current status, subjects and restrictions stay unchanged until Registrar applies this approval.</p>@endif
        @if (data_get($change->impact_snapshot, 'future_leave'))
            <p class="tala-lifecycle-inspector-note">This future leave was recorded without changing the student status. Enrollment in the selected term is restricted from {{ \Carbon\Carbon::parse(data_get($change->impact_snapshot, 'future_leave_restriction_starts_on'))->format('M j, Y') }}.</p>
        @endif
        <p class="tala-lifecycle-inspector-note">Released grades and earlier enrollment records stay available.</p>
    </section>
@endif
    <section class="tala-lifecycle-inspector-section" aria-labelledby="lifecycle-reason-heading">
        <header><h3 id="lifecycle-reason-heading">Reason for change</h3></header>
        <p>{{ $change->reason ?: 'A reason was not recorded.' }}</p>
    </section>
@include('filament.admin.student-lifecycle.record')
@if ($pending || $cancelled || $sourceBlocked || $financeReview)
<section class="tala-lifecycle-next-steps" aria-labelledby="lifecycle-next-heading">
    <h3 id="lifecycle-next-heading">Next steps</h3>
    <x-workflow-handoff
        current-owner="Registrar"
        :current-area="$currentArea"
        :current-task="$currentTask"
        :next-owner="$nextOwner"
        :next-area="$nextArea"
        :next-task="$nextTask"
        :next-url="$financeReview ? $accountUrl : null"
        :next-link-label="$financeReview ? 'Open student account' : 'Open student record'"
    />
</section>
@endif
<x-record-actions>
    @foreach (['apply', 'cancel'] as $actionName)
        @php($action = (clone $this->getTable()->getAction($actionName))->record($change)->button())
        @if ($action->isVisible()){{ $action }}@endif
    @endforeach
    <x-slot:secondary>@if ($studentUrl && ! $future)<x-filament::button color="gray" tag="a" icon="heroicon-m-arrow-top-right-on-square" :href="$studentUrl">Open student record</x-filament::button>@endif</x-slot:secondary>
</x-record-actions>
