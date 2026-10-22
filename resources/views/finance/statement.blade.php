@php
    $available = $statement['position']['state'] !== 'Unavailable';
    $money = fn ($value) => $value === null ? 'Unavailable' : 'PHP '.number_format((float) $value, 2);
    $date = fn ($value) => $value === null ? 'Not recorded' : \App\Support\DisplayDateTime::format(\Carbon\CarbonImmutable::parse($value), 'M j, Y');
    $backUrl = auth()->user()->hasRole(\App\Models\User::StaffRoleAccounting)
        ? \App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('view', ['record' => $statement['account']->enrollment_id], panel: 'admin')
        : (auth()->user()->hasRole('student') ? \App\Filament\Student\Pages\Finance::getUrl(panel: 'student') : \App\Filament\Applicant\Pages\Enrollment::getUrl(panel: 'applicant'));
@endphp
<x-official-output-layout title="Statement of Account" :context="$statement['is_historical'] ? 'Earlier account · current position' : 'Account position as of generation'" :subtitle="$statement['term']" document-class="finance-output" :back-url="$backUrl" back-label="Back to account">
    <x-slot:documentStyles><link rel="stylesheet" href="{{ asset('css/tala-finance-output.css').'?v='.filemtime(public_path('css/tala-finance-output.css')) }}"></x-slot:documentStyles>
    <p class="finance-owner"><strong>{{ $statement['owner'] }}</strong> · {{ $statement['program'] }}</p>
    @if ($statement['is_historical'])
        <p class="finance-source">Opened from an earlier assessment. This statement shows the account position as of generation.</p>
    @endif
    @if ($available)
        <dl class="finance-position">
            <div><dt>Amount due now</dt><dd>{{ $money($statement['position']['current_due']) }}</dd></div>
            <div><dt>Later fees remaining</dt><dd>{{ $money(collect($statement['position']['obligations'])->where('is_due', false)->sum('balance')) }}</dd></div>
            <div><dt>Remaining term balance</dt><dd>{{ $money($statement['position']['remaining_balance']) }}</dd></div>
        </dl>
    @else
        <section class="official-output-notice finance-unavailable" role="status"><h2>Balance unavailable</h2><p>{{ $statement['position']['reason'] ?? 'Accounting must review the assessment source before a balance can be shown.' }}</p><p>Contact Accounting, then refresh your account.</p><p class="finance-source">This is not a zero or cleared balance. The records below remain on file.</p></section>
    @endif
    <section>
        <h2>{{ ! $statement['has_current_assessment'] ? 'Retained historical charges' : ($available ? 'Charges' : 'Recorded charges') }}</h2>
        @if (! $available)<p class="finance-source">{{ $statement['has_current_assessment'] ? 'Recorded assessment details.' : 'Retained historical assessment details.' }} A current balance cannot be calculated yet.</p>@endif
        @if ($statement['assessment']->lines->isNotEmpty())
            <div class="official-output-table finance-responsive-table"><table><thead><tr><th scope="col">Description</th><th scope="col">Amount</th></tr></thead><tbody>
                @foreach ($statement['assessment']->lines as $line)<tr><td data-label="Description">{{ $line->description_snapshot }}</td><td data-label="Amount">{{ $money($line->amount) }}</td></tr>@endforeach
            </tbody></table></div>
        @else<p>Charge details are not recorded. Contact Accounting for the assessment breakdown.</p>@endif
    </section>
    <section><h2>{{ $available ? 'Account activity' : 'Recorded account activity' }}</h2>
        @if ($statement['activity']->isEmpty())<p>No verified payment or approved coverage is recorded. Submitted payment proof is separate from a verified payment.</p>
        @else<div class="official-output-table finance-responsive-table"><table><thead><tr><th scope="col">Date</th><th scope="col">Activity</th><th scope="col">Details</th><th scope="col">Amount</th></tr></thead><tbody>
            @foreach ($statement['activity'] as $event)<tr><td data-label="Date">{{ $date($event['date']) }}</td><td data-label="Activity">{{ $event['description'] }}<span class="finance-source">{{ $event['state'] }}</span></td><td data-label="Details">{{ $event['details'] }}<span class="finance-source">{{ $event['reference'] ?: 'Reference not recorded' }}@if ($event['external_reference']) · External reference {{ $event['external_reference'] }}@endif</span></td><td data-label="Amount">{{ $money($event['amount']) }}</td></tr>@endforeach
        </tbody></table></div>@endif
    </section>
    @if ($available)
        <section><h2>Payment schedule</h2><div class="official-output-table finance-responsive-table"><table><thead><tr><th scope="col">Description</th><th scope="col">Due</th><th scope="col">Scheduled</th><th scope="col">Remaining</th><th scope="col">Status</th></tr></thead><tbody>
            @foreach ($statement['position']['obligations'] as $obligation)<tr><td data-label="Description">{{ $obligation['label'] }}</td><td data-label="Due">{{ $date($obligation['due_at']) }}</td><td data-label="Scheduled">{{ $money($obligation['amount']) }}</td><td data-label="Remaining">{{ $money($obligation['balance']) }}</td><td data-label="Status">{{ $obligation['balance'] === '0.00' ? 'Satisfied' : ($obligation['is_due'] ? 'Due' : 'Upcoming') }}</td></tr>@endforeach
        </tbody></table></div></section>
    @endif
    <p class="finance-source">Account TERM-ACCOUNT-{{ $statement['account']->id }} · {{ $statement['has_current_assessment'] ? 'Assessment' : 'Historical assessment' }} {{ $statement['assessment']->version }} · {{ $statement['assessment']->assessment_basis ? str($statement['assessment']->assessment_basis)->headline() : 'Basis not recorded' }} · Authority {{ $statement['authority_reference'] ?: 'Not recorded' }} · {{ $statement['authority_date'] ?: 'Date not recorded' }}<br>As of {{ \App\Support\DisplayDateTime::format($statement['generated_at']) }} · {{ config('app.display_timezone') }}</p>
    <div class="official-output-notice">{{ $statement['disclaimer'] }}</div>
</x-official-output-layout>
