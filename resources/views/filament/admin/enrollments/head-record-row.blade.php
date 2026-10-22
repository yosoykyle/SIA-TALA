@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
@php
    $case = $getRecord();
    $learner = $case->credentialUser?->getFilamentName() ?? 'Identity unavailable';
    $program = \App\Filament\Resources\Enrollments\Tables\EnrollmentsTable::headProgram($case)?->name ?? 'Program not assigned';
    $outcome = match ($case->canonical_outcome) {
        \App\Models\Enrollment::OutcomeOfficiallyEnrolled => 'Officially enrolled',
        \App\Models\Enrollment::OutcomeInProgress => 'In progress',
        \App\Models\Enrollment::OutcomeCancelledByLearner => 'Cancelled by learner',
        \App\Models\Enrollment::OutcomeCancelledByRegistrar => 'Cancelled by Registrar',
        \App\Models\Enrollment::OutcomeCancelled => 'Cancelled (legacy)',
        \App\Models\Enrollment::OutcomeNotEnrolled => 'Not enrolled',
        default => 'Unclassified outcome',
    };
    $progress = \App\Filament\Resources\Enrollments\Tables\EnrollmentsTable::headProgress($case);
@endphp
<article class="academic-enrollment-row">
    <div class="academic-enrollment-identity"><h2>{{ $learner }}</h2><p>{{ $case->studentProfile?->student_number ?? $case->admissionApplication?->application_reference ?? 'Number not assigned' }}</p></div>
    <div class="academic-enrollment-program"><p>{{ $program }}</p><p>{{ $case->term?->label ?? 'Term unavailable' }}</p></div>
    <div class="academic-enrollment-status"><x-filament::badge :color="$case->canonical_outcome === \App\Models\Enrollment::OutcomeOfficiallyEnrolled ? 'success' : 'gray'">{{ $outcome }}</x-filament::badge>@if ($progress)<p>{{ $progress }}</p>@endif</div>
    <a class="academic-enrollment-open" href="{{ \App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('view', ['record' => $case, 'oversightTermId' => $getLivewire()->oversightTermId, 'returnFilters' => $getLivewire()->tableFilters ?? [], 'returnSearch' => $getLivewire()->tableSearch]) }}" aria-label="Inspect enrollment for {{ $learner }}, {{ $case->term?->label }}">Inspect enrollment <span aria-hidden="true">→</span></a>
</article>
