<section class="accounting-financial-summary" aria-labelledby="accounting-financial-context-heading">
    <h2 id="accounting-financial-context-heading">Charges and payment dates</h2>
    <div class="accounting-financial-summary-grid">
        <section aria-labelledby="account-approved-charges">
            <h3 id="account-approved-charges">{{ $ordinary ? 'Approved charges' : 'Recorded charges' }}</h3>
            @if ($recordedAssessment)
                <x-data-table label="Recorded assessment charges">
                    <thead><tr><th scope="col">Charge</th><th scope="col" class="tala-data-table-number">Amount</th></tr></thead>
                    <tbody>
                        @foreach ($recordedAssessment->lines as $line)<tr><th scope="row">{{ $line->description_snapshot }}</th><td class="tala-data-table-number">{{ \App\Actions\Finance\AccountingDisplay::money($line->amount) }}</td></tr>@endforeach
                        <tr><th scope="row">Total</th><td class="tala-data-table-number">{{ \App\Actions\Finance\AccountingDisplay::money($recordedAssessment->total) }}</td></tr>
                    </tbody>
                </x-data-table>
            @else<p>No assessment has been recorded for this account.</p>@endif
        </section>
        <section aria-labelledby="account-payment-dates">
            <h3 id="account-payment-dates">Payment dates</h3>
            @if ($ordinary)
                @php($laterFees = collect($position['obligations'])->where('is_due', false)->sum('balance'))
                @if ($laterFees > 0)<p class="accounting-comparison-note">Later fees <strong>{{ \App\Actions\Finance\AccountingDisplay::money($laterFees) }}</strong></p>@endif
                <x-data-table label="Current assessment payment dates">
                    <thead><tr><th scope="col">Target and due date</th><th scope="col" class="tala-data-table-number">Remaining</th></tr></thead>
                    <tbody>
                        @foreach ($position['obligations'] as $obligation)
                            @php($obligationStatus = \App\Actions\Finance\AccountingDisplay::obligationStatus($obligation))
                            <tr><th scope="row">{{ $obligation['label'] }}<span class="tala-data-table-secondary">{{ \App\Actions\Finance\AccountingDisplay::dateTime($obligation['due_at'] ?? null) }}</span></th><td class="tala-data-table-number">{{ \App\Actions\Finance\AccountingDisplay::money($obligation['balance']) }}<span class="tala-data-table-secondary">{{ $obligationStatus['label'] }}</span></td></tr>
                        @endforeach
                    </tbody>
                </x-data-table>
            @else<p>Current payment dates are unavailable while the account needs review.</p>@endif
        </section>
    </div>
    @if ($recordedAssessment)
        <div class="accounting-financial-summary-source">
            <p>{{ \App\Actions\Finance\AccountingDisplay::assessmentBasisLabel($recordedAssessment->assessment_basis) }} · {{ $recordedAssessment->authority_reference }}@if ($recordedAssessment->authority_date) · {{ \App\Actions\Finance\AccountingDisplay::date($recordedAssessment->authority_date) }}@endif</p>
            @if (data_get($recordedAssessment->source_snapshot, 'no_payment_required_authority_reference'))<p>No enrollment payment: {{ data_get($recordedAssessment->source_snapshot, 'no_payment_required_authority_reference') }}</p>@endif
            <x-filament::button tag="a" color="gray" icon="heroicon-o-document-text" :href="route('finance.statement', $recordedAssessment)" target="_blank" rel="noopener">Open account statement</x-filament::button>
        </div>
    @endif
</section>
