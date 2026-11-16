<div class="accounting-plan-confirmation">
    <div class="tala-context-inset">
        <p><strong>{{ \App\Actions\Finance\AccountingDisplay::learnerName($case) }}</strong></p>
        <p>{{ $case->currentProposalVersion?->curriculumVersion?->program?->name ?? \App\Actions\Finance\AccountingDisplay::learnerProgram($case) }} · {{ $case->term?->label }}</p>
    </div>
    @if ($plan)
        <p class="accounting-plan-reference">Fee Plan · Version {{ $plan->version }}</p>
        <section class="tala-context-inset" aria-labelledby="confirmed-plan-charges">
            <h3 id="confirmed-plan-charges">Charges</h3>
            <dl>
                @foreach ($plan->charges as $charge)
                    <div><dt>{{ $charge->label }}</dt><dd>{{ \App\Actions\Finance\AccountingDisplay::money($charge->amount) }}</dd></div>
                @endforeach
            </dl>
        </section>
        <section class="tala-context-inset" aria-labelledby="confirmed-plan-payments">
            <h3 id="confirmed-plan-payments">Payment dates</h3>
            <dl>
                @foreach ($plan->obligations->sortBy('sequence') as $obligation)
                    <div><dt>{{ $obligation->label }}<span>Due {{ \App\Actions\Finance\AccountingDisplay::dateTime($obligation->due_at) }}</span></dt><dd>{{ \App\Actions\Finance\AccountingDisplay::money($obligation->amount) }}</dd></div>
                @endforeach
            </dl>
        </section>
        <p class="accounting-plan-reference">Approval: {{ $plan->authority_reference }} · {{ \App\Actions\Finance\AccountingDisplay::date($plan->authority_date) }}</p>
        <p>This records the plan exactly. Ready initial subjects will be sent to the learner for confirmation.</p>
    @else
        <p class="tala-warning-banner" role="status">The selected plan is unavailable. Close this dialog and review the current Fee Plan.</p>
    @endif
</div>
