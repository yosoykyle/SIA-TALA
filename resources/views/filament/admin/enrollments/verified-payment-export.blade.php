@php
    $date = fn ($value) => $value ? \App\Support\DisplayDateTime::format(\Carbon\CarbonImmutable::parse($value), 'M j, Y · g:i A') : 'Not recorded';
@endphp
<div class="accounting-export accounting-payment-export">
    <p class="accounting-export-note">Dates filter when Accounting verified the entry (Asia/Manila).</p>
    @if ($error)
        <p role="status" class="accounting-export-notice">{{ $error }}</p>
    @else
        @if ($count > 0)<h3>{{ number_format($count) }} matching {{ str('entry')->plural($count) }}</h3>@endif
        @if ($count > 10000)<p role="status" class="accounting-export-notice">More than 10,000 entries match. Narrow the verified dates or entry type before downloading.</p>@endif
        @if ($rows === [])
            <div class="accounting-export-empty"><h3>No matching payment entries</h3><p>Change the entry type or verified dates to find entries. No CSV file will be created for an empty result.</p></div>
        @else
            <div class="accounting-export-table-wrap" role="region" aria-label="Verified payments preview" tabindex="0">
                <table class="accounting-export-table accounting-payment-export-table">
                    <thead><tr><th scope="col">Entry / reference</th><th scope="col">Amount</th><th scope="col">Payment or refund date</th><th scope="col">Verified date</th></tr></thead>
                    <tbody>@foreach ($rows as $row)
                        <tr><th scope="row">{{ $row['Entry type'] }}<span>{{ $row['Payment reference'] }}</span>@if ($row['Reverses payment reference'])<span>Reverses {{ $row['Reverses payment reference'] }}</span>@endif</th>
                            <td>PHP {{ number_format((float) $row['Amount (PHP)'], 2) }}</td><td>{{ $date($row['Payment or refund at (Asia/Manila)']) }}</td><td>{{ $date($row['Verified at (Asia/Manila)']) }}</td></tr>
                    @endforeach</tbody>
                </table>
            </div>
            <p class="accounting-export-note">Preview of the first {{ count($rows) }} matching {{ str('entry')->plural(count($rows)) }}. Sources are rechecked on download.</p>
        @endif
    @endif
    <details class="accounting-export-columns"><summary>CSV columns and source details · {{ count($headers) }} columns</summary>
        <ol>@foreach ($headers as $header)<li>{{ $header }}</li>@endforeach</ol>
        <p>Amounts remain positive. Entry type identifies each verified payment or reversal. Both the actual payment/refund time and Accounting verification time are retained. External references are masked.</p>
    </details>
    <p class="accounting-export-note">Purpose: {{ \App\Actions\Finance\CreateContextualFinanceExport::VerifiedPaymentsPurpose }}<br>Private file · available only to you.</p>
</div>
