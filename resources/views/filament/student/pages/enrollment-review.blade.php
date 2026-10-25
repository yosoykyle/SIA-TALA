<div class="student-registration student-review">
    <div class="student-registration-heading">
        <div><h2>{{ $proposal->curriculumVersion?->program?->name ?? 'Program unavailable' }}</h2><p>{{ \App\Models\Term::typeOptions()[$enrollment->term?->type] ?? $enrollment->term?->label ?? 'Term unavailable' }} · School year {{ $enrollment->term?->academicYear?->label ?? 'unavailable' }}</p></div>
    </div>
    @if ($reservationDeadline)<p class="student-review-deadline">Temporary seats reserved until {{ $reservationDeadline->timezone(config('app.display_timezone'))->format('F j, Y · g:i A') }} ({{ config('app.display_timezone') }}).</p>@endif
    <div class="student-review-layout tala-task-surface">
        <div class="student-review-subjects">
            @include('filament.student.pages.enrollment-subjects', ['preparing' => false])
        </div>
        <aside class="student-review-payment" aria-label="Fees and confirmation">
            <h2>Enrollment payment</h2>
            @if (($paymentRequirement['state'] ?? '') === 'Cleared')
                <p class="student-review-amount">Requirement met</p>
                <p>{{ ($paymentRequirement['satisfaction_basis'] ?? '') === 'NoPaymentRequired' ? 'No enrollment payment is required.' : 'Payment or approved coverage satisfies the enrollment requirement.' }}</p>
            @else<p class="student-review-amount">{{ $money($paymentRequirement['balance']) }}</p>@endif
            <dl class="learner-money"><div><dt>Total term fees</dt><dd>{{ $money($assessment->total) }}</dd></div></dl>
            <details class="learner-disclosure"><summary>Fee breakdown and payment dates</summary><div class="student-registration-details">
                <dl class="learner-money">@foreach ($assessment->lines as $line)<div><dt>{{ $line->description_snapshot }}</dt><dd>{{ $money($line->amount) }}</dd></div>@endforeach</dl>
                @foreach ($assessment->obligations as $obligation)<p>{{ $obligation->label }} · {{ $money($obligation->amount) }}<br>Due {{ $obligation->due_at?->timezone(config('app.display_timezone'))->format('M j, Y · g:i A') ?? 'date unavailable' }}</p>@endforeach
            </div></details>
            {{ $this->confirmProposalAction }}
            <p>{{ ($paymentRequirement['state'] ?? '') === 'Cleared' ? 'The Registrar completes official enrollment after you confirm.' : 'Pay after confirming. The Registrar completes official enrollment.' }}</p>
        </aside>
    </div>
    @if ($schedulePresentation && $schedulePresentation['meetings'] !== [])
        <section class="student-review-schedule tala-task-surface"><h2>Proposed schedule</h2><x-enrollment-schedule :presentation="$schedulePresentation" :source-key="'proposal-'.$proposal->id" /></section>
    @endif
    @include('filament.student.pages.enrollment-help')
</div>
