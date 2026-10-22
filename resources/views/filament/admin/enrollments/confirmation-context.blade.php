<div class="registrar-confirmation-context">
    <p><strong>{{ $case->credentialUser?->getFilamentName() }}</strong></p>
    <p>{{ $proposal->items->count() }} {{ str('subject')->plural($proposal->items->count()) }} · {{ (float) $proposal->items->sum('units_snapshot') }} units</p>
    @include('filament.admin.enrollments.subjects', ['sourceKey' => 'confirm'])
</div>
