@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
@php
    $student = $case->studentProfile;
    $name = \App\Actions\Finance\AccountingDisplay::learnerName($case);
    $initials = \App\Actions\Finance\AccountingDisplay::learnerInitials($case);
@endphp
@if ($this->isAccountingWorkspace())
    @include('filament.admin.enrollments.accounting-selected-record')
@else
<div class="accounting-record-workspace tala-student-profile">
    <x-record-identity :name="$name" :number="\App\Actions\Finance\AccountingDisplay::learnerIdentifier($case)"
        :program="\App\Actions\Finance\AccountingDisplay::learnerProgram($case, '') ?: null"
        :initials="$initials" :photo-url="$case->credentialUser?->getFilamentAvatarUrl()" heading-level="h1">
        <x-slot:supporting><p class="accounting-identity-term">{{ $case->term?->label ?? 'Term unavailable' }}</p></x-slot:supporting>
    </x-record-identity>
    <x-tala-navigation label="Student account views" class="accounting-record-navigation">
        @foreach ([
            'account' => ['Account', true, in_array($accountingView, ['accounting-account', 'accounting-awaiting-source', 'accounting-unassessed'], true)],
            'changes' => ['Student status changes'.(($lifecycleStatus['pending'] ?? 0) > 0 ? ' ('.$lifecycleStatus['pending'].')' : ''), ($lifecycleStatus['pending'] ?? 0) + ($lifecycleStatus['reviewed'] ?? 0) > 0, $accountingView === 'accounting-lifecycle'],
            'proof' => ['Review receipts', $account?->paymentEvidenceVersions->contains('state', \App\Models\PaymentEvidenceVersion::StateSubmitted), $accountingView === 'accounting-proof'],
            'history' => ['Account history', $account !== null || ($lifecycleStatus['reviewed'] ?? 0) > 0, $accountingView === 'accounting-history'],
        ] as $view => [$label, $visible, $active])
            @if ($visible)
                <x-filament::tabs.item tag="a" :href="$this->enrollmentUrl(['view' => $view])" :active="$active" :aria-current="$active ? 'page' : null">{{ $label }}</x-filament::tabs.item>
            @endif
        @endforeach
    </x-tala-navigation>
    @include('filament.admin.enrollments.'.$accountingView)
</div>
@endif
