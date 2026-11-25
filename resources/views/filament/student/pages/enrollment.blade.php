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
        $resolvedHowToPayDisplay = is_array($howToPayDisplay ?? null) ? $howToPayDisplay : ($whereToPayDisplay ?? null);
        $hasElectronicChannels = is_bool($hasEnabledDigitalPaymentChannels ?? null)
            ? $hasEnabledDigitalPaymentChannels
            : (is_array($resolvedHowToPayDisplay)
                ? (bool) ($resolvedHowToPayDisplay['hasEnabledDigitalChannels'] ?? ! empty($resolvedHowToPayDisplay['electronicChannels'] ?? []))
                : collect($paymentChannels ?? [])->contains(fn ($channel): bool => is_array($channel) && in_array($channel['key'] ?? null, ['bank_transfer', 'e_wallets', 'gcash'], true)));
        $showProgressStepper = $enrollment !== null
            && ! $terminal
            && ! (! $confirmed && ($irregularReview['is_irregular'] ?? false) && ! $proposal);
        $enrollmentStepIndex = match (true) {
            $official => 3,
            $confirmed => 2,
            $canConfirm => 1,
            default => 0,
        };
    @endphp
    <div class="learner-enrollment student-registration">
        <div wire:loading class="flex items-center gap-2 text-sm" style="color: var(--tala-muted);" role="status">
            <x-filament::loading-indicator class="h-4 w-4" /> Updating enrollment details…
        </div>
        @if ($showProgressStepper)
            <ol class="tala-irregular-progress" aria-label="Enrollment progress">
                @foreach (['Prepare subjects', 'Confirm subjects', 'Pay fees', 'Officially enrolled'] as $index => $stepLabel)
                    <li @class(['is-done' => $official || $index < $enrollmentStepIndex]) @if (! $official && $index === $enrollmentStepIndex) aria-current="step" @endif>{{ $stepLabel }}</li>
                @endforeach
            </ol>
        @endif
        @if ($isStudent && $enrollment !== null && ($academicGuidance['needs_attention'] ?? false))
            @include('filament.student.pages.academic-guidance', [
                'effect' => $academicGuidance,
                'enrollmentUrl' => \App\Filament\Student\Pages\Academics::getUrl(array_filter(['view' => 'plan', 'enrollmentTerm' => $enrollment->term_id]), panel: 'student'),
                'guidanceActionLabel' => 'View study plan',
            ])
        @endif
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
            @if ($enrollment)<p class="student-enrollment-context">{{ $enrollment?->term?->label ?? $entryContext['term']?->label ?? 'Enrollment term unavailable' }}@if ($entryContext['term']?->academicYear) · {{ $entryContext['term']->academicYear->label }}@endif @if ($enrollment?->admissionApplication?->program) · {{ $enrollment->admissionApplication->program->name }}@endif</p>@endif
            @if (! $enrollment)
                <x-servitech-task-state :heading="match ($entryContext['context_state']) {
                        'academic_restriction' => 'Academic review needed',
                        'ambiguous' => 'The Registrar needs to confirm your Term',
                        'closed' => 'Enrollment is not open',
                        default => $entryContext['can_start'] ? 'Start your enrollment' : 'Enrollment is unavailable',
                    }" :description="$entryContext['reason'] ?? 'Review the subjects and fees prepared for this Term. The Registrar checks your classes before you confirm.'" :scope="$academicGuidance['scope_label'] ?? null" :office="in_array($entryContext['context_state'], ['academic_restriction', 'ambiguous'], true) ? 'Registrar Office' : null" :attention="in_array($entryContext['context_state'], ['academic_restriction', 'ambiguous'], true)">
                    @if ($entryContext['can_start']){{ $this->startRegistrationAction }}@endif
                    @if ($isStudent)
                        <x-filament::button tag="a" color="gray" :href="\App\Filament\Student\Pages\Academics::getUrl(array_filter(['view' => 'plan', 'enrollmentTerm' => $entryContext['term']?->id]), panel: 'student')" icon="heroicon-o-map">View study plan</x-filament::button>
                    @endif
                </x-servitech-task-state>
            @else
                <div class="student-enrollment-task-layout">
                    <section class="student-enrollment-task tala-task-surface" aria-labelledby="enrollment-task-heading">
                        @if (! $confirmed && ($irregularReview['is_irregular'] ?? false) && ! $proposal)
                            @php
                                $requestState = $irregularRequest?->state;
                                $progressIndex = match ($requestState) {
                                    \App\Models\IrregularEnrollmentRequest::StateSubmitted => 1,
                                    \App\Models\IrregularEnrollmentRequest::StateAccepted => 2,
                                    default => 0,
                                };
                            @endphp
                            <h2 id="enrollment-task-heading">{{ match ($requestState) {
                                \App\Models\IrregularEnrollmentRequest::StateSubmitted => 'Irregular application – submitted – for Registrar approval',
                                \App\Models\IrregularEnrollmentRequest::StateReturned => 'Irregular application returned for correction',
                                default => 'Apply for irregular enrollment',
                            } }}</h2>
                            <ol class="tala-irregular-progress" aria-label="Irregular enrollment progress">
                                @foreach (['Applied as irregular', 'Registrar approval', 'Assessment', 'Payment', 'Enrolled'] as $index => $step)
                                    <li @class(['is-done' => $index < $progressIndex]) @if ($index === $progressIndex) aria-current="step" @endif>{{ $step }}</li>
                                @endforeach
                            </ol>
                            @if ($requestState === \App\Models\IrregularEnrollmentRequest::StateSubmitted)
                                <p>Your irregular application has been submitted. Please wait for Registrar approval. You cannot edit your subjects unless the Registrar returns your application for correction.</p>
                            @elseif ($requestState === \App\Models\IrregularEnrollmentRequest::StateReturned)
                                <p><strong>Registrar note:</strong> {{ $irregularRequest->return_reason }}</p>
                            @else
                                <x-irregular-reasons :review="$irregularReview" audience="student" />
                                <p>Confirm your reason, choose your retakes and other subjects, then submit your application to the Registrar.</p>
                            @endif
                            @if ($this->applyAsIrregularAction->isVisible())<div class="student-tile-actions">{{ $this->applyAsIrregularAction }}</div>@endif
                        @elseif (! $confirmed)
                            <h2 id="enrollment-task-heading">Your classes are being prepared</h2>
                            <p>The Registrar is preparing your subjects and schedule. You can confirm when your classes and fees are ready.</p>
                            <p>No action is needed from you yet.</p>
                        @elseif ($latestEvidence?->state === \App\Models\PaymentEvidenceVersion::StateSubmitted)
                            <h2 id="enrollment-task-heading">Submitted receipt awaiting review</h2>
                            <p>Accounting is reviewing your {{ $money($latestEvidence->claimed_amount) }} receipt. Your balance changes after verification.</p>
                        @elseif (($paymentRequirement['state'] ?? 'Unavailable') === 'Unavailable')
                            <h2 id="enrollment-task-heading">Accounting needs to review your fees</h2>
                            <p>Your enrollment payment amount is unavailable. Contact Accounting before paying.</p>
                        @else
                            <h2 id="enrollment-task-heading">Complete your enrollment payment</h2>
                            @if ($hasElectronicChannels)
                                <p>Your subjects are confirmed. Pay {{ $money($paymentRequirement['balance'] ?? null) }} through a school payment channel below or onsite at the Campus Cashier.</p>
                                <p>Once Accounting verifies your receipt (or records your onsite Cashier payment), your enrollment becomes official automatically and your COR will be ready immediately. Onsite Cashier payments are recorded directly: no receipt upload needed.</p>
                            @else
                                <p>Your subjects are confirmed. Pay {{ $money($paymentRequirement['balance'] ?? null) }} onsite at the Campus Cashier below: electronic transfer channels are currently unavailable.</p>
                                <p>Once Accounting records your onsite Cashier payment (no receipt upload needed), your enrollment becomes official automatically and your COR will be ready immediately.</p>
                            @endif
                        @endif
                        @if (($readiness['shortages'] ?? []) !== [])
                            <ul class="student-registration-shortages">@foreach ($readiness['shortages'] as $shortage)<li><strong>{{ $shortage['course'] }} · {{ $shortage['section'] }}</strong><p>{{ $shortage['recovery'] }} Contact {{ $shortage['owner'] }}.</p></li>@endforeach</ul>
                        @endif
                        @if ($confirmed && $latestEvidence)
                            <div class="student-enrollment-receipt"><p><strong>{{ $latestEvidence->original_name }}</strong><br>{{ str($latestEvidence->state)->headline() }} · {{ $money($latestEvidence->claimed_amount) }}<br>Submitted {{ $latestEvidence->submitted_at?->timezone(config('app.display_timezone'))->format('M j, Y · g:i A') }}</p>@if ($latestEvidence->review_note)<p>{{ $latestEvidence->review_note }}</p>@endif<x-payment-proof-viewer :proof="$latestEvidence" label="View receipt" /></div>
                        @endif
                        <div class="student-finance-actions">
                            @if ($confirmed && ($paymentRequirement['state'] ?? '') === 'ActionNeeded' && $latestEvidence?->state !== \App\Models\PaymentEvidenceVersion::StateSubmitted && $hasElectronicChannels)
                                {{ $this->submitPaymentEvidenceAction->color('primary') }}
                            @endif
                            <x-filament::button wire:click="$refresh" color="gray" icon="heroicon-o-arrow-path">Refresh enrollment</x-filament::button>
                        </div>
                    </section>
                    @include('filament.student.pages.enrollment-fees')
                </div>
                @if ($confirmed && ($paymentRequirement['state'] ?? '') === 'ActionNeeded' && $latestEvidence?->state !== \App\Models\PaymentEvidenceVersion::StateSubmitted)
                    @include('filament.student.pages.how-to-pay', [
                        'display' => $resolvedHowToPayDisplay,
                        'channels' => $paymentChannels ?? [],
                        'variant' => 'page',
                        'surface' => 'enrollment',
                    ])
                @endif
                @if ($proposal && $proposal->items->isNotEmpty())
                    @include('filament.student.pages.enrollment-subjects', ['preparing' => ! $confirmed, 'subjectsHeading' => $confirmed ? 'Confirmed subjects' : 'Proposed subjects'])
                    @if ($schedulePresentation && $schedulePresentation['meetings'] !== [])
                        <details class="learner-disclosure"><summary>{{ $confirmed ? 'Confirmed schedule' : 'Preview schedule' }}</summary><div><p class="student-registration-help">Seats remain temporary until official enrollment.@if ($reservationDeadline) Reserved until {{ $reservationDeadline->timezone(config('app.display_timezone'))->format('M j, Y · g:i A') }}.@endif</p><x-enrollment-schedule :presentation="$schedulePresentation" :source-key="'proposal-'.$proposal->id" /></div></details>
                    @endif
                @elseif ($enrollment)
                    <x-enrollment-proposal-explanation :enrollment="$enrollment" :proposal="$proposal" :audience="$isStudent ? 'student' : 'applicant'" />
                @endif
                @include('filament.student.pages.enrollment-help')
                @if ($isStudent && $corHistory !== [])<p class="student-registration-help"><x-filament::link :href="\App\Filament\Student\Pages\CorView::getUrl(panel: 'student')">View earlier registrations</x-filament::link></p>@endif
            @endif
        @endif
    </div>
</x-filament-panels::page>
