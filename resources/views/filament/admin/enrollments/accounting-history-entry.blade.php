@php
    $title = match ($kind) {
        'payment' => $record->state === \App\Models\Payment::StateReversal ? 'Payment reversal' : ($record->evidence_status === 'verified' && $record->verified_at ? 'Verified payment' : 'Unverified payment claim'),
        'assessment' => 'Assessment version '.$record->version,
        'proof' => 'Submitted receipt',
        'coverage' => 'Approved coverage',
        default => 'Payment export',
    };
    $status = match ($kind) {
        'payment' => $record->channelDetailLabel(),
        'assessment' => \App\Actions\Finance\AccountingDisplay::assessmentStateLabel($record->state),
        'proof' => \App\Actions\Finance\AccountingDisplay::evidenceStateLabel($record->state),
        'coverage' => \App\Actions\Finance\AccountingDisplay::coverageStateLabel($record->state),
        default => \App\Actions\Finance\AccountingDisplay::financeExportOutcomeLabel($record->outcome),
    };
    $amount = match ($kind) {
        'assessment' => $record->total,
        'proof' => $record->claimed_amount,
        'payment', 'coverage' => $record->amount,
        default => null,
    };
@endphp
<tr wire:key="account-history-{{ $kind }}-{{ $record->id }}" role="row">
    <td role="cell"><span class="tala-data-table-mobile-label" aria-hidden="true">Recorded on</span>{{ \App\Actions\Finance\AccountingDisplay::dateTime($entry['at'], fallback: 'Date not recorded') }}</td>
    <th scope="row" role="rowheader"><span class="accounting-history-record-title">{{ $title }}</span><span class="tala-data-table-secondary">{{ $status }}</span>
        <div class="accounting-history-source">

            @switch($kind)
                @case('payment')
                    @php
                        $paymentFacts = collect([
                            'Payment reference' => $record->provider_reference,
                            'Verified by' => $record->verifier?->getFilamentName(),
                            'Verified at' => $record->verified_at ? \App\Actions\Finance\AccountingDisplay::dateTime($record->verified_at, fallback: 'Date not recorded') : null,
                            'Verification basis' => $record->verification_basis,
                            'External check' => $record->external_check_reference,
                            'Official receipt' => $record->or_number,
                            'Reversal approval' => $record->reversal_authority_reference,
                            'Reason' => $record->reversal_reason,
                            'External refund' => $record->external_refund_reference,
                        ])->filter(fn ($value): bool => filled($value));
                    @endphp
                    @if ($paymentFacts->isNotEmpty() || $record->allocations->isNotEmpty())
                        <x-supporting-details summary="Audit details" inset>
                            @if ($paymentFacts->isNotEmpty())
                                <dl class="registrar-source-charges">
                                    @foreach ($paymentFacts as $label => $value)<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach
                                </dl>
                            @endif
                            @foreach ($record->allocations as $allocation)<p>{{ $allocation->assessmentObligation?->label }} · {{ \App\Actions\Finance\AccountingDisplay::money($allocation->amount) }}</p>@endforeach
                        </x-supporting-details>
                    @endif
                    @break
                @case('assessment')
                    <p>{{ \App\Actions\Finance\AccountingDisplay::assessmentBasisLabel($record->assessment_basis) }} · {{ $record->authority_reference }}@if ($record->authority_date) · {{ \App\Actions\Finance\AccountingDisplay::date($record->authority_date) }}@endif</p>
                    @if ($record->lines->isNotEmpty())
                        <x-supporting-details summary="Audit details" inset>
                            <dl class="registrar-source-charges">@foreach ($record->lines as $line)<div><dt>{{ $line->description_snapshot }}</dt><dd>{{ \App\Actions\Finance\AccountingDisplay::money($line->amount) }}</dd></div>@endforeach</dl>
                        </x-supporting-details>
                    @endif
                    @if (data_get($record->source_snapshot, 'no_payment_required_authority_reference'))<p>No enrollment payment: {{ data_get($record->source_snapshot, 'no_payment_required_authority_reference') }}</p>@endif
                    @break
                @case('proof')
                    <p>{{ \App\Actions\Finance\AccountingDisplay::channelDetailLabel($record->channel, $record->channel_snapshot) }}@if ($record->payment_reference) · {{ $record->payment_reference }}@endif</p>
                    <p>Paid on {{ \App\Actions\Finance\AccountingDisplay::dateTime($record->paid_at, fallback: 'Date not recorded') }}</p>
                    <p>{{ collect($record->obligation_snapshot ?? [])->pluck('label')->filter()->implode(', ') }}</p>
                    @if ($record->reviewed_at)<p>{{ \App\Actions\Finance\AccountingDisplay::dateTime($record->reviewed_at, fallback: 'Date not recorded') }} · {{ $record->review_note }}</p>@endif
                    @if ($record->possible_duplicate)<p>Possible duplicate: compare the original external source before verification.</p>@endif
                    @break
                @case('coverage')
                    <p>{{ \App\Actions\Finance\AccountingDisplay::coverageCategoryLabel($record->category) }} · {{ $record->safe_source_description }}</p>
                    <p>{{ $record->obligation?->label }} · Effective {{ \App\Actions\Finance\AccountingDisplay::date($record->effective_date) }}</p>
                    <p>{{ $record->authority_reference }} · {{ \App\Actions\Finance\AccountingDisplay::date($record->authority_date) }}</p>
                    @if ($record->reversed_at)<p>{{ $record->reversal_authority_reference }} · {{ $record->reversal_reason }}</p>@endif
                    @break
                @case('export')
                    <p>{{ $record->row_count }} {{ str('row')->plural($record->row_count) }} · {{ \App\Actions\Finance\AccountingDisplay::financeExportOutcomeLabel($record->outcome) }}</p>
                    @break
            @endswitch
        </div>
    </th>
    @if ($group['amountLabel'] !== '')
    <td class="tala-data-table-number" role="cell">
        <span class="tala-data-table-mobile-label" aria-hidden="true">{{ $group['amountLabel'] }}</span>
        @if ($amount !== null){{ \App\Actions\Finance\AccountingDisplay::money($amount) }}@if ($kind === 'proof')<span class="tala-data-table-secondary">claimed</span>@endif @endif
        <div class="accounting-history-document">
            @if ($kind === 'payment')
                @if (\App\Actions\Finance\AccountingDisplay::isVerifiedPayment($record))
                    <x-filament::link :href="route('finance.payments.acknowledgement', $record)" target="_blank" rel="noopener">Open acknowledgment</x-filament::link>
                @endif
                @php($mapOrAction = ($this->getAction('mapOfficialReceipt', isMounting: false))(['payment' => $record->id]))
                @if ($mapOrAction->isVisible())
                    {{ $mapOrAction->button() }}
                @endif
            @elseif ($kind === 'assessment')
                <x-filament::button tag="a" color="gray" icon="heroicon-o-document-text" :href="route('finance.statement', $record)" target="_blank" rel="noopener">Open account statement</x-filament::button>
            @elseif ($kind === 'proof')
                <x-payment-proof-viewer :proof="$record" label="View receipt" />
            @elseif ($kind === 'export' && $record->outcome === \App\Models\FinanceExport::OutcomeGenerated)
                @if ((int) $record->initiated_by === (int) auth()->id())
                    <x-filament::link :href="route('finance.exports.download', $record)">Download recorded export</x-filament::link>
                @else<p>Private file available to the Accounting user who generated it.</p>@endif
            @endif
        </div>
    </td>
    @endif
</tr>
