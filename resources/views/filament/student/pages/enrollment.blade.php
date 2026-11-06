<x-filament-panels::page>
    @assets
        <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
    @endassets
    @php
        $official = $enrollment?->canonical_outcome === \App\Models\Enrollment::OutcomeOfficiallyEnrolled;
        $terminal = $enrollment && in_array($enrollment->canonical_outcome, \App\Models\Enrollment::reopenableOutcomes(), true);
        $confirmed = $proposal?->confirmation !== null;
        $canConfirm = $proposal?->state === \App\Models\RegistrationProposalVersion::StateIssued
            && $preparedForConfirmation && $assessment
            && (int) $assessment->source_proposal_version_id === (int) $proposal->id
            && ($paymentRequirement['state'] ?? 'Unavailable') !== 'Unavailable';
        $reservationDeadline = $proposal?->items->map(fn ($item) => $item->reservation?->deadline)->filter()->min();
        $hasExpiredPlacement = ($proposal?->items->contains(fn ($item) => $item->reservation?->status !== \App\Models\EnrollmentSeatReservation::StatusActive || ($item->reservation?->deadline !== null && $item->reservation->deadline->isPast())) ?? false)
            || ($readiness !== null && ! ($readiness['placement'] ?? true));
        $money = fn ($value) => $value === null ? 'Not yet posted' : 'PHP '.number_format((float) $value, 2);
    @endphp
    <div class="learner-enrollment student-registration">
        <div wire:loading class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300" role="status">
            <x-filament::loading-indicator class="h-4 w-4" /> Updating enrollment details…
        </div>
        @if ($official)
            @include('filament.student.pages.enrollment-official')
        @elseif ($terminal)
            @include('filament.student.pages.enrollment-closed')
        @elseif ($confirmed && ($paymentRequirement['state'] ?? '') === 'Cleared')
            @if ($hasExpiredPlacement)
                @include('filament.student.pages.enrollment-recovery')
            @else
                @include('filament.student.pages.enrollment-waiting')
            @endif
        @elseif ($canConfirm && ! $terminal)
            @include('filament.student.pages.enrollment-review')
        @elseif ($proposal && ! $confirmed && ! $canConfirm && ! $terminal)
            @include('filament.student.pages.enrollment-preparing')
        @else
            <p class="student-enrollment-context">{{ $enrollment?->term?->label ?? $entryContext['term']?->label ?? 'Enrollment term unavailable' }}@if ($enrollment?->admissionApplication?->program) · {{ $enrollment->admissionApplication->program->name }}@endif</p>
            @if (! $enrollment)
                <section class="student-enrollment-task tala-task-surface" aria-labelledby="enrollment-entry-heading">
                    <h2 id="enrollment-entry-heading">{{ $this->startRegistrationAction->isDisabled() ? 'Enrollment is unavailable' : 'Start your enrollment' }}</h2>
                    <p>{{ $entryContext['reason'] ?? 'Open enrollment to review the subjects and fees prepared for this term. The Registrar reviews your classes before you confirm.' }}</p>
                    <div class="student-finance-actions">{{ $this->startRegistrationAction }}</div>
                </section>
            @else
                <div class="student-enrollment-task-layout">
                    <section class="student-enrollment-task tala-task-surface" aria-labelledby="enrollment-task-heading">
                        @if (! $confirmed)
                            <h2 id="enrollment-task-heading">Your classes are being prepared</h2>
                            <p>The Registrar is preparing your subjects and schedule. You can confirm when your classes and fees are ready.</p>
                            <p>No action is needed from you yet.</p>
                        @elseif ($latestEvidence?->state === \App\Models\PaymentEvidenceVersion::StateSubmitted)
                            <h2 id="enrollment-task-heading">Payment proof awaiting review</h2>
                            <p>Accounting is reviewing your {{ $money($latestEvidence->claimed_amount) }} receipt. Your balance changes after verification.</p>
                        @elseif (($paymentRequirement['state'] ?? 'Unavailable') === 'Unavailable')
                            <h2 id="enrollment-task-heading">Accounting needs to review your fees</h2>
                            <p>Your enrollment payment amount is unavailable. Contact Accounting before paying.</p>
                        @else
                            <h2 id="enrollment-task-heading">Complete your enrollment payment</h2>
                            <p>Your subjects are confirmed. Pay {{ $money($paymentRequirement['balance'] ?? null) }} to meet the enrollment requirement.</p>
                            <p>The Registrar completes official enrollment after your payment and records are checked.</p>
                        @endif
                        @if (($readiness['shortages'] ?? []) !== [])
                            <ul class="student-registration-shortages">@foreach ($readiness['shortages'] as $shortage)<li><strong>{{ $shortage['course'] }} · {{ $shortage['section'] }}</strong><p>{{ $shortage['recovery'] }} Contact {{ $shortage['owner'] }}.</p></li>@endforeach</ul>
                        @endif
                        @if ($confirmed && $latestEvidence)
                            <div class="student-enrollment-receipt"><p><strong>{{ $latestEvidence->original_name }}</strong><br>{{ str($latestEvidence->state)->headline() }} · {{ $money($latestEvidence->claimed_amount) }}<br>Submitted {{ $latestEvidence->submitted_at?->timezone(config('app.display_timezone'))->format('M j, Y · g:i A') }}</p>@if ($latestEvidence->review_note)<p>{{ $latestEvidence->review_note }}</p>@endif<x-payment-proof-viewer :proof="$latestEvidence" label="View submitted proof" /></div>
                        @endif
                        <div class="student-finance-actions">
                            @if ($confirmed && ($paymentRequirement['state'] ?? '') === 'ActionNeeded' && $latestEvidence?->state !== \App\Models\PaymentEvidenceVersion::StateSubmitted)
                                {{ $this->submitPaymentEvidenceAction }} {{ $this->payExactCurrentDueAction->outlined() }}
                            @endif
                            <x-filament::button wire:click="$refresh" color="gray" icon="heroicon-o-arrow-path">Refresh enrollment</x-filament::button>
                        </div>
                        @if ($confirmed && ! ($checkout['enabled'] ?? false) && $latestEvidence?->state !== \App\Models\PaymentEvidenceVersion::StateSubmitted && ! empty($checkout['reason']))<p class="student-enrollment-payment-help">{{ $checkout['reason'] }}</p>@endif
                    </section>
                    @include('filament.student.pages.enrollment-fees')
                </div>
                @if ($proposal && $proposal->items->isNotEmpty())
                    @include('filament.student.pages.enrollment-subjects', ['preparing' => ! $confirmed, 'subjectsHeading' => $confirmed ? 'Confirmed subjects' : 'Proposed subjects'])
                    @if ($schedulePresentation && $schedulePresentation['meetings'] !== [])
                        <details class="learner-disclosure"><summary>{{ $confirmed ? 'Confirmed schedule' : 'Preview schedule' }}</summary><div><p class="student-registration-help">Seats remain temporary until official enrollment.@if ($reservationDeadline) Reserved until {{ $reservationDeadline->timezone(config('app.display_timezone'))->format('M j, Y · g:i A') }}.@endif</p><x-enrollment-schedule :presentation="$schedulePresentation" :source-key="'proposal-'.$proposal->id" /></div></details>
                    @endif
                @endif
                @include('filament.student.pages.enrollment-help')
                @if ($isStudent && $corHistory !== [])<p class="student-registration-help"><x-filament::link :href="\App\Filament\Student\Pages\CorView::getUrl(panel: 'student')">View earlier registrations</x-filament::link></p>@endif
            @endif
        @endif
    </div>
</x-filament-panels::page>
