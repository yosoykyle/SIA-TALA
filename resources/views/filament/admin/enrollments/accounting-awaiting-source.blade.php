@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
@php
    $outcome = $case->canonical_outcome;
    $ended = in_array($outcome, [...\App\Models\Enrollment::cancelledOutcomes(), \App\Models\Enrollment::OutcomeNotEnrolled], true);
    $officiallyEnrolled = $outcome === \App\Models\Enrollment::OutcomeOfficiallyEnrolled;
    $statusLabel = \App\Actions\Finance\AccountingDisplay::enrollmentOutcomeLabel($outcome);
@endphp
<div class="registrar-case accounting-awaiting-source">
    <section @class(['accounting-awaiting-summary', 'tala-task-surface' => ! $this->isAccountingWorkspace()]) aria-labelledby="accounting-awaiting-heading">
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

        <section class="tala-context-inset accounting-source-details">
            <h3>Enrollment details</h3>
            <dl>
                <div><dt>Status</dt><dd>{{ $statusLabel }}</dd></div>
                @if ($case->updated_at)<div><dt>Last updated</dt><dd>{{ \App\Actions\Finance\AccountingDisplay::dateTime($case->updated_at) }}</dd></div>@endif
                @if ($case->case_reference)
                    <div class="accounting-awaiting-reference"><dt>Enrollment reference</dt><dd>{{ $case->case_reference }}</dd></div>
                @endif
            </dl>
        </section>
    </section>
    @if (($lifecycleStatus['pending'] ?? 0) > 0)
        @include('filament.admin.enrollments.accounting-lifecycle')
    @endif
</div>
