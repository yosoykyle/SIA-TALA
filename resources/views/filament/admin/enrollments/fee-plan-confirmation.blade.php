<div class="accounting-plan-confirmation">
    <div class="tala-context-inset">
        <p><strong>{{ \App\Actions\Finance\AccountingDisplay::learnerName($case) }}</strong></p>
        <p>{{ $case->currentProposalVersion?->curriculumVersion?->program?->name ?? \App\Actions\Finance\AccountingDisplay::learnerProgram($case) }} · {{ $case->term?->label }}</p>
    </div>
    @if ($plan)
        <p class="accounting-plan-reference">Standard fee plan{{ $plan->published_at ? ' · Updated '.\App\Actions\Finance\AccountingDisplay::date($plan->published_at) : '' }}</p>
        <div class="accounting-plan-confirmation__grid">
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
        </div>
        @if (filled($plan->authority_reference) || $plan->authority_date)
            <p class="accounting-plan-reference">Approval note: {{ collect([$plan->authority_reference, $plan->authority_date ? \App\Actions\Finance\AccountingDisplay::date($plan->authority_date) : null])->filter()->implode(' · ') }}</p>
        @endif
        <p>This records the plan exactly. Ready initial subjects will be sent to the learner for confirmation.</p>
    @else
        <p class="tala-warning-banner" role="status">The selected plan is unavailable. Close this dialog and review the current Fee Plan.</p>
    @endif
</div>
