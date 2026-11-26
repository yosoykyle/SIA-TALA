@include('filament.admin.fee-plans.styles')
<div class="accounting-fee-plan-confirmation accounting-fee-plan-publish">
    <div class="accounting-fee-plan-confirmation__header">
        <h3>{{ $reviewed['program'] }}</h3>
        <p class="accounting-fee-plan-meta">{{ $reviewed['term'] }}</p>
    </div>

    <div class="accounting-plan-confirmation__grid">
        <div class="accounting-fee-plan-confirmation__group">
            <div class="accounting-fee-plan-confirmation__group-head">
                <h4>Charges</h4>
            </div>
            <dl>
                @foreach ($reviewed['charges'] as $charge)
                    <div>
                        <dt>{{ $charge['label'] }}</dt>
                        <dd>{{ \App\Actions\Finance\AccountingDisplay::money($charge['amount']) }}</dd>
                    </div>
                @endforeach
                <div class="is-total">
                    <dt>Total charges</dt>
                    <dd>{{ \App\Actions\Finance\AccountingDisplay::money($reviewed['total']) }}</dd>
                </div>
            </dl>
        </div>

        <div class="accounting-fee-plan-confirmation__group">
            <div class="accounting-fee-plan-confirmation__group-head">
                <h4>Payment schedule</h4>
            </div>
            <dl>
                @foreach ($reviewed['payments'] as $payment)
                    <div>
                        <dt>
                            <span class="accounting-fee-plan-confirmation__payment-title">
                                {{ $payment['label'] }}
                                @if ($payment['required_for_enrollment'])
                                    <span class="accounting-fee-plan-required">Required for enrollment</span>
                                @endif
                            </span>
                            <span class="accounting-fee-plan-confirmation__payment-date">{{ \App\Actions\Finance\AccountingDisplay::dateTime($payment['due_at'] ?? null) }}</span>
                        </dt>
                        <dd>{{ \App\Actions\Finance\AccountingDisplay::money($payment['amount']) }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </div>

    <div class="accounting-fee-plan-confirmation__notes">
        <p>@if ($reviewed['predecessor_version'] !== null)After publication, this draft becomes current for new assessments. {{ $reviewed['retained_accounts'] }} existing {{ str('account')->plural($reviewed['retained_accounts']) }} keep their recorded assessments.@else This plan becomes current for new assessments for this program and term after publication.@endif Publishing does not charge or reassess anyone.</p>
        <p>Published plans cannot be edited. Future changes start from a new draft.</p>
    </div>
</div>
