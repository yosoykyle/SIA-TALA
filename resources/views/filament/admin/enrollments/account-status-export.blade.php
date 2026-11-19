@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
<div class="accounting-export">
    <dl class="tala-context-inset accounting-export-scope">
        <div><dt>{{ number_format($count) }} {{ str('matching account')->plural($count) }}</dt><dd>{{ number_format($excluded) }} {{ str('preparation case')->plural($excluded) }} excluded (no account yet)</dd></div>
        <div><dt>{{ $program }} · {{ $term }}</dt><dd @class(['accounting-export-empty-search' => blank($search)])>{{ filled($search) ? 'Search: '.$search : 'No search' }}</dd></div>
        <div class="accounting-export-purpose"><dt>Purpose</dt><dd>{{ $purpose }}</dd></div>
    </dl>

    @if ($count > 10000)
        <p role="status" class="tala-warning-banner accounting-export-notice">More than 10,000 accounts match. Close this dialog and narrow the term, program or search before downloading.</p>
    @endif

    @if ($rows === [])
        <div class="accounting-export-empty"><h3>No accounts to export</h3><p>{{ $excluded > 0 ? 'Matching preparation cases have no account yet.' : 'No account records match the current search and filters.' }} Close this dialog and adjust the search or filters.</p></div>
    @else
        <x-data-table label="Account status preview" class="accounting-export-table-wrap accounting-account-export-table">
            <thead><tr><th scope="col">Student / applicant</th><th scope="col">Term</th><th scope="col" class="tala-data-table-number">Due now</th><th scope="col" class="tala-data-table-number">Later fees</th><th scope="col" class="tala-data-table-number">Remaining</th></tr></thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <th scope="row">{{ $row['Student / applicant name'] ?? $row['Learner name'] ?? '' }}<span class="tala-data-table-secondary">{{ $row['Student / applicant number'] ?? $row['Learner number'] ?? '' }}</span><span class="tala-data-table-secondary">{{ $row['Program'] }}</span>@if ($row['Account status'] === 'Needs review')<span class="accounting-export-review">Needs review · amounts unavailable</span>@endif</th>
                        <td>{{ $row['Term'] }}</td>
                        <td class="tala-data-table-number">{{ \App\Actions\Finance\AccountingDisplay::money($row['Due now (PHP)'], '—') }}</td>
                        <td class="tala-data-table-number">{{ \App\Actions\Finance\AccountingDisplay::money($row['Later fees (PHP)'], '—') }}</td>
                        <td class="tala-data-table-number">{{ \App\Actions\Finance\AccountingDisplay::money($row['Remaining term fees (PHP)'], '—') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </x-data-table>
        <div class="accounting-export-mobile-preview">
            <p class="accounting-export-note">Amounts in PHP</p>
            @foreach ($rows as $row)
                <article class="accounting-export-mobile-row">
                    <h3>{{ $row['Student / applicant name'] ?? $row['Learner name'] ?? '' }}</h3><p>{{ $row['Student / applicant number'] ?? $row['Learner number'] ?? '' }}</p><p>{{ $row['Term'] }}</p>
                    @if ($row['Account status'] === 'Needs review')<p>Needs review · amounts unavailable</p>@endif
                    <dl>
                        @foreach (['Due now' => 'Due now (PHP)', 'Later fees' => 'Later fees (PHP)', 'Remaining' => 'Remaining term fees (PHP)'] as $label => $key)
                            <div><dt>{{ $label }}</dt><dd>{{ \App\Actions\Finance\AccountingDisplay::money($row[$key], '—', withCurrency: false) }}</dd></div>
                        @endforeach
                    </dl>
                </article>
            @endforeach
        </div>
        <p class="accounting-export-note">Preview of the first {{ count($rows) }} matching {{ str('account')->plural(count($rows)) }} in the current list order. Current sources are rechecked when you download.</p>
    @endif

    <p class="accounting-export-note">CSV ({{ count($headers) }} columns · up to 10,000 accounts) includes learner and account identifiers, term charges, verified payments, and approval references (PHP · Asia/Manila · private to you).</p>
</div>
