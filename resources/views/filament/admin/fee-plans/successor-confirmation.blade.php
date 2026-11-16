@include('filament.admin.fee-plans.styles')
<div class="accounting-fee-plan-confirmation">
    <div class="accounting-fee-plan-confirmation__header">
        <h3>{{ $reviewed['program'] }}</h3>
        <p class="accounting-fee-plan-meta">{{ $reviewed['term'] }} · Current plan published {{ \App\Actions\Finance\AccountingDisplay::dateTime($reviewed['published_at'] ?? null, 'M j, Y', 'date unavailable') }}</p>
    </div>

    <div class="accounting-fee-plan-confirmation__group">
        <dl>
            <div>
                <dt>Total charges</dt>
                <dd>{{ \App\Actions\Finance\AccountingDisplay::money($reviewed['total']) }}</dd>
            </div>
            <div>
                <dt>Enrollment payment</dt>
                <dd>{{ \App\Actions\Finance\AccountingDisplay::money($reviewed['enrollment_payment']) }}</dd>
            </div>
            <div>
                <dt>Scheduled payments</dt>
                <dd>{{ $reviewed['payment_count'] }}</dd>
            </div>
        </dl>
    </div>

    <div class="accounting-fee-plan-confirmation__notes">
        <p>The new draft copies these charges and payment dates. This plan stays current until the draft is approved and published.</p>
        <p>Existing assessments and payments stay unchanged.</p>
        @if (filled($reviewed['authority_reference'] ?? null))
            <p class="accounting-fee-plan-meta">Current approval: {{ $reviewed['authority_reference'] }}</p>
        @endif
    </div>
</div>
