@if ($target instanceof \App\Models\User)
    <div class="tala-user-access-context" role="group" aria-label="Target account">
        <x-record-identity
            :name="$displayName"
            :program="$email"
            :number="$identityPill"
            :initials="$initials"
            class="border-0 rounded-none"
        >
            <x-slot:status>
                <div class="tala-user-access-context__badges">
                    <x-filament::badge :color="$statusColor">
                        {{ $statusLabel }}
                    </x-filament::badge>
                    @if ($showMfaBadge)
                        <x-filament::badge :color="$mfaColor">
                            MFA: {{ $mfaLabel }}
                        </x-filament::badge>
                    @endif
                </div>
            </x-slot:status>

            @if ($roles !== [])
                <x-slot:supporting>
                    <div class="tala-user-access-context__roles" aria-label="Assigned roles">
                        @foreach ($roles as $role)
                            <x-filament::badge :color="$role['color']" :icon="$role['icon'] ?? null">
                                {{ $role['label'] }}
                            </x-filament::badge>
                        @endforeach
                    </div>
                </x-slot:supporting>
            @endif
        </x-record-identity>

        @if (filled($consequenceSummary ?? null))
            <div class="tala-user-access-context__footer">
                <p class="tala-user-access-context__consequence">{{ $consequenceSummary }}</p>
            </div>
        @endif
    </div>
@endif
