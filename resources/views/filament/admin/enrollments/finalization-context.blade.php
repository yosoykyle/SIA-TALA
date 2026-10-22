<div class="registrar-confirmation-context">
    <p><strong>{{ $case->credentialUser?->getFilamentName() }}</strong></p>
    <p>{{ $case->currentProposalVersion?->curriculumVersion?->program?->name }} · {{ $case->term?->label }}</p>
    <p>{{ $case->currentProposalVersion?->items->count() }} {{ str('subject')->plural($case->currentProposalVersion?->items->count() ?? 0) }} · {{ (float) $case->currentProposalVersion?->items->sum('units_snapshot') }} units</p>
    @include('filament.admin.enrollments.subjects', ['proposal' => $case->currentProposalVersion, 'sourceKey' => 'finalization'])
</div>
