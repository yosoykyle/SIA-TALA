@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
@php
    $outcome = $case->canonical_outcome;
    $ended = in_array($outcome, [...\App\Models\Enrollment::cancelledOutcomes(), \App\Models\Enrollment::OutcomeNotEnrolled], true);
    $officiallyEnrolled = $outcome === \App\Models\Enrollment::OutcomeOfficiallyEnrolled;
    $program = $case->studentProfile?->program?->name ?? $case->admissionApplication?->program?->name;
    $statusLabel = match ($outcome) {
        \App\Models\Enrollment::OutcomeInProgress => 'In progress',
        \App\Models\Enrollment::OutcomeOfficiallyEnrolled => 'Officially enrolled',
        \App\Models\Enrollment::OutcomeNotEnrolled => 'Not enrolled',
        \App\Models\Enrollment::OutcomeCancelledByLearner => 'Cancelled by learner',
        \App\Models\Enrollment::OutcomeCancelledByRegistrar => 'Cancelled by Registrar',
        default => str($outcome)->headline()->toString(),
    };
@endphp
<div class="registrar-case accounting-awaiting-source">
    <section class="tala-task-surface accounting-awaiting-summary" aria-labelledby="accounting-awaiting-heading">
        <div class="accounting-awaiting-context">
            @if ($program)<p>{{ $program }}</p>@endif
            @if ($case->term?->label)<p>{{ $case->term->label }}</p>@endif
        </div>
        <div class="accounting-awaiting-message">
        <x-filament::icon :icon="$ended ? 'heroicon-o-archive-box' : ($officiallyEnrolled ? 'heroicon-o-exclamation-circle' : 'heroicon-o-clock')" class="accounting-awaiting-icon" aria-hidden="true" />
        <div>
            <h2 id="accounting-awaiting-heading">{{ $ended ? 'Enrollment ended' : ($officiallyEnrolled ? 'Account review needed' : 'Waiting for the Registrar') }}</h2>
            <p>
                @if ($ended)
                    This enrollment is {{ $statusLabel }}. No current account source is recorded for it.
                @elseif ($officiallyEnrolled)
                    The enrollment is official, but no term account is recorded. Review the enrollment record before taking any accounting action.
                @else
                    The subjects and reserved classes need a Registrar update before Accounting can prepare charges.
                @endif
            </p>
        </div>
        </div>

    @if (! $ended && ! $officiallyEnrolled)
        <x-workflow-handoff current-owner="Registrar" current-task="Prepare subjects" next-owner="Accounting" next-task="Prepare charges" next-icon="heroicon-o-banknotes" />
    @endif

    <details class="registrar-case-reference accounting-awaiting-details">
        <summary>Enrollment details</summary>
        <dl>
            <div><dt>Status</dt><dd>{{ $statusLabel }}</dd></div>
            @if ($case->updated_at)<div><dt>Last updated</dt><dd>{{ \App\Support\DisplayDateTime::format($case->updated_at, 'M j, Y · g:i A') }}</dd></div>@endif
            @if ($case->case_reference)
                <div class="accounting-awaiting-reference"><dt>Enrollment reference</dt><dd>{{ $case->case_reference }}</dd></div>
            @endif
        </dl>
    </details>
    </section>
</div>
