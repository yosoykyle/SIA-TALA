<div class="registrar-recovery-context">
    <strong>{{ $case->credentialUser?->getFilamentName() }}</strong>
    <p>{{ $case->term?->label }}</p>
    @if ($reopening ?? false)
        <p>Prepare the subjects and check enrollment requirements again. Earlier records and payments stay recorded; previous seats are not restored.</p>
    @endif
</div>
