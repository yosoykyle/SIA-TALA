@php($historyUrl = $this->enrollmentUrl(['view' => 'history']))
<section @class(['accounting-review-task', 'tala-task-surface' => ! $this->isAccountingWorkspace()]) aria-labelledby="account-review-heading">
    @if ($reviewState === 'ended')
        @php($ended = in_array($case->canonical_outcome, \App\Models\Enrollment::reopenableOutcomes(), true))
        <h2 id="account-review-heading">{{ $ended ? 'Registration ended — Accounting review required' : 'Registration outcome unavailable' }}</h2>
        <p>{{ $ended ? \App\Actions\Finance\AccountingDisplay::enrollmentOutcomeLabel($case->canonical_outcome) : 'Registrar must verify the registration outcome before enrollment can proceed.' }}</p>
        <p>Recorded payments, approved coverage and scheduled fees remain on this account. Review the school's financial decision before making a change. Cancellation does not create a refund or transfer.</p>
        @if ($case->status_reason)<p>{{ $case->status_reason }}</p>@endif
        <div class="accounting-context-actions"><x-filament::button tag="a" :href="$historyUrl" color="gray">Review financial history</x-filament::button></div>
    @elseif ($reviewState === 'reconciliation')
        <h2 id="account-review-heading">Match earlier payments and coverage</h2>
        <p>Match earlier payments and approved coverage to the current payment schedule. The amount due stays unavailable until those matches are recorded.</p>
        <dl class="accounting-review-facts">
            <div><dt>Earlier payments</dt><dd>{{ \App\Actions\Finance\AccountingDisplay::money($position['retained_payment']) }}</dd></div>
            <div><dt>Earlier approved coverage</dt><dd>{{ \App\Actions\Finance\AccountingDisplay::money($position['retained_coverage']) }}</dd></div>
        </dl>
        <div class="accounting-context-actions">{{ $this->getAction('reconcileAssessmentEffects', isMounting: false) }}</div>
    @elseif ($reviewState === 'drop')
        <h2 id="account-review-heading">Review the Course Drop financial effect</h2>
        <p>{{ $sources['course_drop']->courseEnrollment?->termOffering?->curriculumEntry?->courseSpecification?->title ?? 'Recorded subject removal' }} · {{ $sources['course_drop']->courseEnrollment?->section?->code }}</p>
        <p>The academic change is recorded. Review the approved financial result; a Course Drop does not calculate a refund or reduce charges.</p>
        <div class="accounting-context-actions">{{ $this->getAction('recordIndividualAssessment', isMounting: false) }}</div>
        <section class="tala-context-inset accounting-source-details"><h3>Recorded change</h3><p>{{ $sources['course_drop']->reason }}</p><p>Earlier registration and certificate versions remain in history.</p></section>
    @elseif ($reviewState === 'adjustment')
        <h2 id="account-review-heading">{{ $adjustmentFinanceReady ? 'Financial result ready for Registrar' : ($pendingAdjustment->state === \App\Models\RegistrationProposalVersion::StateConfirmed ? 'Review the confirmed registration change' : 'Waiting for the confirmed registration change') }}</h2>
        @if ($change['valid'])
            <p>{{ $change['after']?->course_title_snapshot }} · {{ $change['before']?->section?->code ?? 'New subject' }} &rarr; {{ $change['after']?->section?->code }}</p>
        @else
            <p>Registrar must prepare one exact Add or replacement before Accounting reviews the financial result.</p>
        @endif
        <p>{{ $adjustmentFinanceReady ? 'The recorded financial result is ready. The Registrar can now finalize this subject change.' : ($pendingAdjustment->state === \App\Models\RegistrationProposalVersion::StateConfirmed ? 'The student confirmed this change. Current official subjects stay unchanged until Registrar applies it.' : 'Current official subjects stay unchanged until the student confirms the change and Registrar applies it.') }}</p>
        @if ($pendingAdjustment->state === \App\Models\RegistrationProposalVersion::StateConfirmed && $change['valid'] && ! $adjustmentFinanceReady)
            <div class="accounting-context-actions">
                {{ $this->getAction('confirmNoAdditionalCost', isMounting: false) }}
                {{ $this->getAction('recordIndividualAssessment', isMounting: false)->color('gray') }}
            </div>
        @endif
        <section class="tala-context-inset accounting-source-details"><h3>Change source</h3><p>Proposal version {{ $pendingAdjustment->version }} · {{ \App\Actions\Finance\AccountingDisplay::proposalStateLabel($pendingAdjustment->state) }}</p><p>Registrar owns the official subject and certificate change. Accounting records only its approved financial result.</p></section>
    @else
        <h2 id="account-review-heading">No active charges recorded yet</h2>
        <p>{{ $position['reason'] ?? 'Record an assessment and required enrollment payment before the balance can be calculated.' }}</p>
        <p>Review the registration and assessment sources with Registrar. Recorded charges and payment history remain available; this is not a zero or cleared balance.</p>
        <div class="accounting-context-actions">
            {{ $this->getAction('createAssessment', isMounting: false) }}
            {{ $this->getAction('recordIndividualAssessment', isMounting: false)->color('gray') }}
        </div>
    @endif
</section>
