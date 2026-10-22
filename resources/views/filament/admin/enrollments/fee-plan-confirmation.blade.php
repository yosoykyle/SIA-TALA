<div class="accounting-plan-confirmation">
    <div>
        <p><strong>{{ $case->credentialUser?->getFilamentName() }}</strong></p>
        <p>{{ $case->currentProposalVersion?->curriculumVersion?->program?->name }} · {{ $case->term?->label }}</p>
    </div>
    @if ($plan)
        <p class="accounting-plan-reference">Fee Plan · Version {{ $plan->version }}</p>
        <section aria-labelledby="confirmed-plan-charges">
            <h3 id="confirmed-plan-charges">Charges</h3>
            <dl>
                @foreach ($plan->charges as $charge)
                    <div><dt>{{ $charge->label }}</dt><dd>PHP {{ number_format((float) $charge->amount, 2) }}</dd></div>
                @endforeach
            </dl>
        </section>
        <section aria-labelledby="confirmed-plan-payments">
            <h3 id="confirmed-plan-payments">Payment dates</h3>
            <dl>
                @foreach ($plan->obligations->sortBy('sequence') as $obligation)
                    <div><dt>{{ $obligation->label }}<span>Due {{ \App\Support\DisplayDateTime::format($obligation->due_at, 'M j, Y · g:i A') }}</span></dt><dd>PHP {{ number_format((float) $obligation->amount, 2) }}</dd></div>
                @endforeach
            </dl>
        </section>
        <p class="accounting-plan-reference">Approval: {{ $plan->authority_reference }} · {{ $plan->authority_date?->format('M j, Y') }}</p>
        <p>This records the plan exactly. Ready initial subjects will be sent to the learner for confirmation.</p>
    @else
        <p>The selected plan is unavailable. Close this dialog and review the current Fee Plan.</p>
    @endif
</div>
