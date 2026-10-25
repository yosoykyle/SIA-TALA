<section class="accounting-review-task tala-task-surface" aria-labelledby="account-review-heading">
    @if ($reviewState === 'ended')
        @php($ended = in_array($case->canonical_outcome, \App\Models\Enrollment::reopenableOutcomes(), true))
        <h2 id="account-review-heading">{{ $ended ? 'Registration ended — Accounting review required' : 'Registration outcome unavailable' }}</h2>
        <p>{{ $ended ? match ($case->canonical_outcome) { \App\Models\Enrollment::OutcomeCancelledByLearner => 'Cancelled by learner', \App\Models\Enrollment::OutcomeCancelledByRegistrar => 'Cancelled by Registrar', \App\Models\Enrollment::OutcomeNotEnrolled => 'Not enrolled', default => 'Cancelled' } : 'Registrar must verify the registration outcome before enrollment can proceed.' }}</p>
        <p>Recorded payments, coverage and obligations remain in this Term Account. Review the external financial result; no refund, forfeiture or transfer is inferred.</p>
        @if ($case->status_reason)<p>{{ $case->status_reason }}</p>@endif
        <div class="accounting-context-actions"><x-filament::button tag="a" :href="$historyUrl" color="gray">Review financial history</x-filament::button></div>
    @elseif ($reviewState === 'reconciliation')
        <h2 id="account-review-heading">Reconcile retained payment and coverage</h2>
        <p>Review earlier financial effects against the current assessment. A current balance is unavailable until their named obligations are reconciled.</p>
        <dl class="accounting-review-facts">
            <div><dt>Retained verified payment</dt><dd>{{ $money($position['retained_payment']) }}</dd></div>
            <div><dt>Retained approved coverage</dt><dd>{{ $money($position['retained_coverage']) }}</dd></div>
        </dl>
        <div class="accounting-context-actions">{{ $this->getAction('reconcileAssessmentEffects', isMounting: false) }}</div>
    @elseif ($reviewState === 'drop')
        <h2 id="account-review-heading">Review the Course Drop financial effect</h2>
        <p>{{ $sources['course_drop']->courseEnrollment?->termOffering?->curriculumEntry?->courseSpecification?->title ?? 'Recorded subject removal' }} · {{ $sources['course_drop']->courseEnrollment?->section?->code }}</p>
        <p>The academic change is recorded. Review the approved financial result; a Course Drop does not calculate a refund or reduce charges.</p>
        <div class="accounting-context-actions">{{ $this->getAction('recordIndividualAssessment', isMounting: false) }}</div>
        <details class="registrar-case-reference"><summary>Recorded change</summary><p>{{ $sources['course_drop']->reason }}</p><p>Earlier registration and certificate versions remain in history.</p></details>
    @elseif ($reviewState === 'adjustment')
        <h2 id="account-review-heading">{{ $adjustmentFinanceReady ? 'Financial result ready for Registrar' : ($pendingAdjustment->state === \App\Models\RegistrationProposalVersion::StateConfirmed ? 'Review the confirmed registration change' : 'Waiting for the confirmed registration change') }}</h2>
        @if ($change['valid'])
            <p>{{ $change['after']?->course_title_snapshot }} · {{ $change['before']?->section?->code ?? 'New subject' }} &rarr; {{ $change['after']?->section?->code }}</p>
        @else
            <p>Registrar must prepare one exact Add or replacement before Accounting reviews the financial result.</p>
        @endif
        <p>{{ $adjustmentFinanceReady ? 'The recorded financial result is available for the matching guarded Registrar action.' : ($pendingAdjustment->state === \App\Models\RegistrationProposalVersion::StateConfirmed ? 'The learner confirmed this change. Current official subjects stay unchanged until Registrar applies it.' : 'Current official subjects stay unchanged until the learner confirms the change and Registrar applies it.') }}</p>
        @if ($pendingAdjustment->state === \App\Models\RegistrationProposalVersion::StateConfirmed && $change['valid'] && ! $adjustmentFinanceReady)
            <div class="accounting-context-actions">
                {{ $this->getAction('confirmNoAdditionalCost', isMounting: false) }}
                {{ $this->getAction('recordIndividualAssessment', isMounting: false)->color('gray') }}
            </div>
        @endif
        <details class="registrar-case-reference"><summary>Change source</summary><p>Proposal version {{ $pendingAdjustment->version }} · {{ str($pendingAdjustment->state)->headline() }}</p><p>Registrar owns the official subject and certificate change. Accounting records only its approved financial result.</p></details>
    @else
        <h2 id="account-review-heading">Account balance unavailable</h2>
        <p>{{ $position['reason'] ?? 'Accounting must establish the current assessment and enrollment-payment requirement.' }}</p>
        <p>Review the registration and assessment sources with Registrar. Recorded charges and payment history remain available; this is not a zero or cleared balance.</p>
        <div class="accounting-context-actions">
            {{ $this->getAction('createAssessment', isMounting: false) }}
            {{ $this->getAction('recordIndividualAssessment', isMounting: false)->color('gray') }}
        </div>
    @endif
</section>
