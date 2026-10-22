@php
    try {
        $deadline = \App\Support\DisplayDateTime::format(app(\App\Actions\Calendar\CalendarPhaseGateService::class)->enrollmentDeadline((int) $case->term_id), 'M j, Y · g:i A');
    } catch (\App\Actions\Calendar\Exceptions\CalendarGateViolation) {
        $deadline = 'Unavailable — review the published enrollment period';
    }
@endphp
<div class="registrar-confirmation-context">
    <p><strong>{{ $case->credentialUser?->getFilamentName() }}</strong></p>
    <p>{{ $case->term?->label }} · {{ $case->currentProposalVersion?->items->count() }} subjects · {{ (float) $case->currentProposalVersion?->items->sum('units_snapshot') }} units</p>
    <dl class="registrar-case-record-reference"><dt>Enrollment deadline</dt><dd>{{ $deadline }} · {{ config('app.display_timezone') }}</dd></dl>
</div>
