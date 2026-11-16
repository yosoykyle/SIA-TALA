<x-filament-panels::page>
    @assets
        <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
    @endassets
    @php
        $identity = $this->profileIdentity();
        $hasPhoto = filled($this->getUser()->avatar_path);
    @endphp
    <div class="student-information student-profile-page tala-account-profile">
        <x-record-identity
            :name="$identity['name']"
            :number="$identity['identifier']"
            :number-label="$identity['identifierLabel']"
            :show-number-label="filled($identity['identifierLabel'])"
            :program="$identity['email']"
            :initials="$identity['initials']"
            :photo-url="$identity['photoUrl']"
            role="group"
            aria-label="Account profile"
        >
            <x-slot:status>
                <x-filament::badge color="success">{{ $identity['roleBadge'] }}</x-filament::badge>
            </x-slot:status>
            <x-slot:actions>
                {{ $this->changePhotoAction }}
                @if ($hasPhoto)
                    {{ $this->removePhotoAction }}
                @endif
                @if ($identity['studentProfileUrl'])
                    <x-filament::button tag="a" :href="$identity['studentProfileUrl']" color="gray" icon="heroicon-o-identification">
                        Student details
                    </x-filament::button>
                @endif
                @if ($identity['switchWorkspaceUrl'])
                    <x-filament::button tag="a" :href="$identity['switchWorkspaceUrl']" color="gray" icon="heroicon-o-arrows-right-left">
                        Switch workspace
                    </x-filament::button>
                @endif
            </x-slot:actions>
        </x-record-identity>

        {{ $this->content }}
    </div>
</x-filament-panels::page>
