<div class="accounting-export accounting-payment-export">
    <p class="accounting-export-note">Dates filter when Accounting verified the entry (Asia/Manila).</p>
    @if ($error)
        <p role="status" class="tala-warning-banner accounting-export-notice">{{ $error }}</p>
    @else
        @if ($count > 0)<h3>{{ number_format($count) }} matching {{ str('entry')->plural($count) }}</h3>@endif
        @if ($count > 10000)<p role="status" class="tala-warning-banner accounting-export-notice">More than 10,000 entries match. Narrow the verified dates or entry type before downloading.</p>@endif
        @if ($rows === [])
            <div class="accounting-export-empty"><h3>No matching payment entries</h3><p>Change the entry type or verified dates to find entries. No CSV file will be created for an empty result.</p></div>
        @else
            <x-data-table label="Verified payments preview" class="accounting-export-table-wrap">
                <thead><tr><th scope="col">Entry / reference</th><th scope="col" class="tala-data-table-number">Amount</th><th scope="col">Payment or refund date</th><th scope="col">Verified date</th></tr></thead>
                <tbody>@foreach ($rows as $row)
                    <tr><th scope="row">{{ $row['Entry type'] }}<span class="tala-data-table-secondary">{{ $row['Payment reference'] }}</span>@if ($row['Reverses payment reference'])<span class="tala-data-table-secondary">Reverses {{ $row['Reverses payment reference'] }}</span>@endif</th>
                        <td class="tala-data-table-number">{{ \App\Actions\Finance\AccountingDisplay::money($row['Amount (PHP)']) }}</td><td>{{ \App\Actions\Finance\AccountingDisplay::dateTime($row['Payment or refund at (Asia/Manila)'] ?? null) }}</td><td>{{ \App\Actions\Finance\AccountingDisplay::dateTime($row['Verified at (Asia/Manila)'] ?? null) }}</td></tr>
                @endforeach</tbody>
            </x-data-table>
            <p class="accounting-export-note">Preview of the first {{ count($rows) }} matching {{ str('entry')->plural(count($rows)) }}. Sources are rechecked on download.</p>
        @endif
    @endif
    <p class="accounting-export-note">CSV ({{ count($headers) }} columns) includes verified payment and reversal references, positive PHP amounts, payment/refund and verification timestamps, and masked external references (PHP · Asia/Manila).</p>
    <p class="accounting-export-note">Purpose: {{ \App\Actions\Finance\CreateContextualFinanceExport::VerifiedPaymentsPurpose }} · Private file available only to you.</p>
</div>
