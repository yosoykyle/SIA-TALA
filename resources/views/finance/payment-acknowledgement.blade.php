@php
    $backUrl = auth()->user()->hasRole(\App\Models\User::StaffRoleAccounting)
        ? \App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('view', ['record' => $acknowledgement['payment']->termAccount->enrollment_id], panel: 'admin')
        : (auth()->user()->hasRole('student') ? \App\Filament\Student\Pages\Finance::getUrl(panel: 'student') : \App\Filament\Applicant\Pages\Enrollment::getUrl(panel: 'applicant'));
@endphp
<x-official-output-layout title="Payment Acknowledgment" :subtitle="$acknowledgement['term']" document-class="finance-output" :back-url="$backUrl" back-label="Back to account">
    <x-slot:documentStyles><link rel="stylesheet" href="{{ asset('css/tala-finance-output.css').'?v='.filemtime(public_path('css/tala-finance-output.css')) }}"></x-slot:documentStyles>
    <p class="finance-owner"><strong>{{ $acknowledgement['owner'] }}</strong></p>
    <dl class="finance-position finance-payment-position">
        @if ($acknowledgement['net_amount'] !== null)<div><dt>Amount still applied</dt><dd>PHP {{ number_format((float) $acknowledgement['net_amount'], 2) }}</dd></div>
        @else<div><dt>Actual verified amount</dt><dd>PHP {{ number_format((float) $acknowledgement['payment']->amount, 2) }}</dd></div>@endif
        <div><dt>Payment status</dt><dd>{{ $acknowledgement['posting_status'] }}</dd></div></dl>
    @if ($acknowledgement['net_amount'] !== null)<dl class="finance-payment-facts"><div><dt>Original verified payment</dt><dd>PHP {{ number_format((float) $acknowledgement['payment']->amount, 2) }}</dd></div><div><dt>Refunded so far</dt><dd>PHP {{ number_format((float) $acknowledgement['reversed_total'], 2) }}</dd></div></dl>@endif
    <dl class="finance-payment-facts"><div><dt>Payment method</dt><dd>{{ $acknowledgement['payment']->channelLabel() }}</dd></div><div><dt>Paid</dt><dd>{{ \App\Support\DisplayDateTime::format($acknowledgement['payment']->paid_at) }}</dd></div><div><dt>Verified</dt><dd>{{ \App\Support\DisplayDateTime::format($acknowledgement['payment']->verified_at) }}</dd></div></dl>
    @if ($acknowledgement['corrections'] !== [])
        <section><h2>Recorded refunds</h2><div class="official-output-table finance-responsive-table"><table><thead><tr><th scope="col">Date</th><th scope="col">Reason</th><th scope="col">Amount</th></tr></thead><tbody>
            @foreach ($acknowledgement['corrections'] as $correction)<tr><td data-label="Date">{{ \App\Support\DisplayDateTime::format($correction['date']) }}</td><td data-label="Reason">{{ $correction['reason'] }}<span class="finance-source">Reference {{ $correction['reference'] }} · Authority {{ $correction['authority'] }}</span></td><td data-label="Amount">PHP {{ number_format((float) $correction['amount'], 2) }}</td></tr>@endforeach
        </tbody></table></div><p class="finance-source">These records reflect completed refunds outside TALA. Recording them does not send money.</p></section>
    @endif
    <section><h2>{{ $acknowledgement['net_amount'] !== null ? 'Original payment allocation' : 'Account effect' }}</h2>
        @if ($acknowledgement['net_amount'] !== null)<p class="finance-source">The original posting stays unchanged. These are the amounts applied before the recorded refunds.</p>@endif
        <div class="official-output-table finance-responsive-table"><table><thead><tr><th scope="col">Applied to</th><th scope="col">{{ $acknowledgement['net_amount'] !== null ? 'Originally applied' : 'Amount applied' }}</th></tr></thead><tbody>
            @foreach ($acknowledgement['allocations'] as $allocation)<tr><td data-label="Applied to">{{ $allocation['target'] }}</td><td data-label="{{ $acknowledgement['net_amount'] !== null ? 'Originally applied' : 'Amount applied' }}">PHP {{ number_format((float) $allocation['amount'], 2) }}</td></tr>@endforeach
        </tbody></table></div>
    </section>
    <div class="official-output-notice">{{ $acknowledgement['disclaimer'] }}</div>
    <section class="finance-source"><h2>Source details</h2><dl class="finance-source-facts">
        <div><dt>Account</dt><dd>TERM-ACCOUNT-{{ $acknowledgement['payment']->term_account_id }}</dd></div>
        <div><dt>Verification basis</dt><dd>{{ $acknowledgement['payment']->verification_basis ? str($acknowledgement['payment']->verification_basis)->headline() : 'Not recorded' }}</dd></div>
        <div><dt>Posting reference</dt><dd>{{ $acknowledgement['payment']->provider_reference ?: 'Not recorded' }}</dd></div>
        @if ($acknowledgement['external_reference'])<div><dt>External reference</dt><dd>{{ $acknowledgement['external_reference'] }}</dd></div>@endif
        <div><dt>Copy generated</dt><dd>{{ \App\Support\DisplayDateTime::format($acknowledgement['generated_at']) }} · {{ config('app.display_timezone') }}</dd></div>
    </dl></section>
</x-official-output-layout>
