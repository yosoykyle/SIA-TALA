@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
@php
    $money = fn ($value) => $value === null ? '—' : 'PHP '.number_format((float) $value, 2);
@endphp
<div class="accounting-export">
    <dl class="accounting-export-scope">
        <div><dt>{{ number_format($count) }} {{ str('matching account')->plural($count) }}</dt><dd>{{ number_format($excluded) }} {{ str('preparation case')->plural($excluded) }} excluded (no account yet)</dd></div>
        <div><dt>{{ $program }} · {{ $term }}</dt><dd @class(['accounting-export-empty-search' => blank($search)])>{{ filled($search) ? 'Search: '.$search : 'No search' }}</dd></div>
        <div class="accounting-export-purpose"><dt>Purpose</dt><dd>{{ $purpose }}</dd></div>
    </dl>

    @if ($count > 10000)
        <p role="status" class="accounting-export-notice">More than 10,000 accounts match. Close this dialog and narrow the term, program or search before downloading.</p>
    @endif

    @if ($rows === [])
        <div class="accounting-export-empty"><h3>No accounts to export</h3><p>{{ $excluded > 0 ? 'Matching preparation cases have no account yet.' : 'No account records match the current search and filters.' }} Close this dialog and adjust the search or filters.</p></div>
    @else
        <div class="accounting-export-table-wrap accounting-account-export-table" role="region" aria-label="Account status preview" tabindex="0">
            <table class="accounting-export-table">
                <thead><tr><th scope="col">Learner</th><th scope="col">Term</th><th scope="col">Due now</th><th scope="col">Later fees</th><th scope="col">Remaining</th></tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <th scope="row">{{ $row['Learner name'] }}<span>{{ $row['Learner number'] }}</span><span>{{ $row['Program'] }}</span>@if ($row['Account status'] === 'Needs review')<span class="accounting-export-review">Needs review · amounts unavailable</span>@endif</th>
                            <td>{{ $row['Term'] }}</td>
                            <td>{{ $money($row['Due now (PHP)']) }}</td><td>{{ $money($row['Later fees (PHP)']) }}</td><td>{{ $money($row['Remaining term fees (PHP)']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="accounting-export-mobile-preview">
            <p class="accounting-export-note">Amounts in PHP</p>
            @foreach ($rows as $row)
                <article class="accounting-export-mobile-row">
                    <h3>{{ $row['Learner name'] }}</h3><p>{{ $row['Learner number'] }}</p><p>{{ $row['Term'] }}</p>
                    @if ($row['Account status'] === 'Needs review')<p>Needs review · amounts unavailable</p>@endif
                    <dl>
                        @foreach (['Due now' => 'Due now (PHP)', 'Later fees' => 'Later fees (PHP)', 'Remaining' => 'Remaining term fees (PHP)'] as $label => $key)
                            <div><dt>{{ $label }}</dt><dd>{{ $row[$key] === null ? '—' : number_format((float) $row[$key], 2) }}</dd></div>
                        @endforeach
                    </dl>
                </article>
            @endforeach
        </div>
        <p class="accounting-export-note">Preview of the first {{ count($rows) }} matching {{ str('account')->plural(count($rows)) }} in the current list order. Current sources are rechecked when you download.</p>
    @endif

    <details class="accounting-export-columns"><summary>CSV columns and source details · {{ count($headers) }} columns</summary>
        <ol>@foreach ($headers as $header)<li>{{ $header }}</li>@endforeach</ol>
        <p>Purpose: {{ $purpose }}</p>
        <p>Amounts use PHP and two decimal places. Needs review leaves derived amounts blank; recorded assessment and approval details remain attributable. The time column uses Asia/Manila.</p>
    </details>
    <p class="accounting-export-note">CSV · up to 10,000 accounts · Private retrieval only for the initiator.</p>
</div>
