@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
<div class="tala-context-inset accounting-export-identity">
    <p><strong>{{ \App\Actions\Finance\AccountingDisplay::learnerName($case) }}</strong> · {{ \App\Actions\Finance\AccountingDisplay::learnerIdentifier($case) }}</p>
    <p>{{ \App\Actions\Finance\AccountingDisplay::learnerProgram($case, '') }} · {{ $case->term?->label }}</p>
</div>
