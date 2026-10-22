<div class="registrar-late-context">
    <strong>{{ $case->credentialUser?->getFilamentName() }}</strong>
    <p>{{ $context['action_type'] === \App\Models\RegistrationLateAuthority::ActionCourseDrop ? 'Drop the selected subject' : ($before ? 'Replace the selected class' : 'Add the selected subject') }}</p>
    @if ($before)
        <p>{{ $before->proposalItem?->course_title_snapshot }} &middot; {{ $before->section?->code }}</p>
    @endif
    @if ($after)
        <p>Proposed: {{ $afterTitle }} &middot; {{ $after->code }}</p>
    @endif
    <p>{{ $case->term?->label }}</p>
    @if ($confirmation)
        <p>Student confirmation recorded {{ $confirmation->confirmed_at?->timezone('Asia/Manila')->format('M j, Y, g:i A') }}.</p>
    @endif
</div>
