@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
<div class="accounting-export-identity">
    <p><strong>{{ $case->credentialUser?->getFilamentName() }}</strong> · {{ $case->studentProfile?->student_number ?? $case->admissionApplication?->application_reference }}</p>
    <p>{{ $case->studentProfile?->program?->name ?? $case->admissionApplication?->program?->name }} · {{ $case->term?->label }}</p>
</div>
