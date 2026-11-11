<x-record-identity
    :name="$identityName"
    :number="$identityNumber"
    number-label="Student number"
    :show-number-label="$identityShowNumberLabel ?? false"
    :program="$identityProgram"
    :initials="str($student?->first_name)->substr(0, 1).str($student?->last_name)->substr(0, 1)"
    :photo-url="auth()->user()->avatar_path ? auth()->user()->getFilamentAvatarUrl() : null"
    role="group"
    aria-label="Student identity"
>
    @isset($identityStatus)
        <x-slot:status>
            <x-filament::badge :color="$identityStatus === 'Active' ? 'success' : 'gray'">
                <span class="sr-only">Student status: </span>{{ $identityStatus }}
            </x-filament::badge>
        </x-slot:status>
    @endisset
    @if (filled($identityStudies ?? null))
        <x-slot:supporting>
            <p><span class="sr-only">Current studies: </span>{{ $identityStudies }}</p>
        </x-slot:supporting>
    @endif
    @isset($identityPhotoAction)
        <x-slot:actions>{{ $identityPhotoAction }}</x-slot:actions>
    @endisset
</x-record-identity>
