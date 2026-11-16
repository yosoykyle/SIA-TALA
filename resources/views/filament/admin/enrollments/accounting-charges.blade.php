@php($chargeHeadingTag = $this->isAccountingWorkspace() ? 'h3' : 'h2')
<section @class(['accounting-charge-summary', 'tala-task-surface' => ! $this->isAccountingWorkspace()]) aria-labelledby="account-approved-charges">
    <{{ $chargeHeadingTag }} id="account-approved-charges">{{ $ordinary ? 'Approved charges' : 'Recorded charges' }}</{{ $chargeHeadingTag }}>
    @if ($recordedAssessment)
        <dl class="registrar-source-charges">
            @foreach ($recordedAssessment->lines as $line)<div><dt>{{ $line->description_snapshot }}</dt><dd>{{ \App\Actions\Finance\AccountingDisplay::money($line->amount) }}</dd></div>@endforeach
            <div><dt><strong>Total</strong></dt><dd><strong>{{ \App\Actions\Finance\AccountingDisplay::money($recordedAssessment->total) }}</strong></dd></div>
        </dl>
        <section class="tala-context-inset accounting-source-details"><h3>Approval details</h3>
            <p>Assessment version {{ $recordedAssessment->version }} · {{ \App\Actions\Finance\AccountingDisplay::assessmentBasisLabel($recordedAssessment->assessment_basis) }} · {{ \App\Actions\Finance\AccountingDisplay::assessmentStateLabel($recordedAssessment->state) }}</p>
            <p>{{ $recordedAssessment->authority_reference }} · {{ \App\Actions\Finance\AccountingDisplay::date($recordedAssessment->authority_date) }}</p>
            @if (data_get($recordedAssessment->source_snapshot, 'no_payment_required_authority_reference'))<p>No enrollment payment: {{ data_get($recordedAssessment->source_snapshot, 'no_payment_required_authority_reference') }}</p>@endif
        </section>
        @unless ($ordinary)<div class="accounting-context-actions"><x-filament::button tag="a" :href="route('finance.statement', $recordedAssessment)" target="_blank" rel="noopener" color="gray" icon="heroicon-o-document-text">Open account statement</x-filament::button></div>@endunless
    @else<p>No assessment has been recorded for this account.</p>@endif
</section>
