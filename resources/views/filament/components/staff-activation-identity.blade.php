@php
    $displayName = filled($invitedName ?? null) ? $invitedName : ($invitedEmail ?? 'Invited staff');
    $displayEmail = filled($invitedName ?? null) ? ($invitedEmail ?? null) : null;
    $staffIdentifier = filled($invitedStaffIdentifier ?? null) ? $invitedStaffIdentifier : null;
@endphp

<x-record-identity
    :name="$displayName"
    :program="$displayEmail"
    :number="$staffIdentifier"
    :number-label="$staffIdentifier !== null ? 'Staff ID' : null"
    :show-number-label="$staffIdentifier !== null"
    :initials="$invitedInitials"
    role="group"
    aria-label="Invited staff account details"
    class="tala-record-identity--access !mt-0"
>
    @if (($invitedRoles ?? []) !== [])
        <x-slot:supporting>
            <div class="tala-access-invite-context__roles" aria-label="Assigned roles">
                @foreach ($invitedRoles as $roleLabel)
                    <x-filament::badge color="success">{{ $roleLabel }}</x-filament::badge>
                @endforeach
            </div>
        </x-slot:supporting>
    @endif
</x-record-identity>
