@php
    $invitedUser = $invitation->relationLoaded('user') ? $invitation->user : ($invitation->user_id ? $invitation->user : null);
    $invitedName = $invitedUser instanceof \App\Models\User && filled($invitedUser->name) ? (string) $invitedUser->name : null;
    $invitedEmail = filled($invitation->email) ? (string) $invitation->email : ($invitedUser instanceof \App\Models\User ? (string) $invitedUser->email : null);
    $invitedRoles = collect(is_array($invitation->staff_roles) ? $invitation->staff_roles : [])
        ->filter(static fn (mixed $role): bool => is_string($role) && trim($role) !== '')
        ->map(static fn (string $role): string => \App\Filament\Resources\Users\Tables\UsersTable::roleLabel($role))
        ->values()
        ->all();
@endphp

<x-tala-access-layout title="Activate account" heading-id="activation-heading">
    <div class="tala-access-heading-block">
        <h1 id="activation-heading">Activate your account</h1>
        @if ($isUsable)
            <p class="tala-access-muted mt-1.5 text-sm">Set your password below. You will set up authenticator-app MFA on the next step.</p>
        @endif
    </div>

    @if (! $isUsable)
        <x-tala-access-alert variant="danger" title="This activation link is expired or no longer valid." class="mt-5" role="alert">
            <p>This link cannot activate access now. Ask a System Administrator to resend the invitation.</p>
        </x-tala-access-alert>
    @else
        @if ($invitedName !== null || $invitedEmail !== null || $invitedRoles !== [])
            <div class="tala-access-invite-context" aria-label="Invited staff account details">
                @if ($invitedName !== null)
                    <p class="tala-access-invite-context__name">{{ $invitedName }}</p>
                @endif
                @if ($invitedEmail !== null)
                    <p class="tala-access-invite-context__email">{{ $invitedEmail }}</p>
                @endif
                @if ($invitedRoles !== [])
                    <div class="tala-access-invite-context__roles" aria-label="Assigned roles">
                        @foreach ($invitedRoles as $roleLabel)
                            <span class="tala-access-role-pill">{{ $roleLabel }}</span>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        @if ($errors->any())
            <x-tala-access-alert variant="danger" class="mt-5" role="alert" aria-labelledby="activation-errors">
                <p id="activation-errors" class="font-medium">Review the highlighted fields.</p>
                <ul class="mt-2 list-disc pl-5 text-sm">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-tala-access-alert>
        @endif

        <form method="POST" action="{{ route('staff-invitations.accept', $invitation) }}" class="tala-access-form" data-activation-form>
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div class="tala-access-field">
                <label for="password" class="tala-access-label">Password</label>
                <div class="tala-access-password-field">
                    <input
                        id="password"
                        name="password"
                        type="password"
                        required
                        autocomplete="new-password"
                        minlength="15"
                        maxlength="64"
                        class="tala-access-input tala-access-input--with-toggle"
                        aria-describedby="password-guidance{{ $errors->has('password') ? ' activation-errors' : '' }}"
                        @if ($errors->has('password')) aria-invalid="true" @endif
                        data-activation-password
                    >
                    <button
                        type="button"
                        class="tala-access-password-toggle"
                        data-toggle-password="password"
                        aria-label="Show password"
                        aria-pressed="false"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        </svg>
                    </button>
                </div>
                <p id="password-guidance" class="tala-access-muted mt-1.5 text-xs">Use 15 to 64 characters. Spaces and password-manager paste are allowed.</p>
            </div>

            <div class="tala-access-field">
                <label for="password_confirmation" class="tala-access-label">Confirm password</label>
                <div class="tala-access-password-field">
                    <input
                        id="password_confirmation"
                        name="password_confirmation"
                        type="password"
                        required
                        autocomplete="new-password"
                        minlength="15"
                        maxlength="64"
                        class="tala-access-input tala-access-input--with-toggle"
                        @if ($errors->has('password')) aria-invalid="true" aria-describedby="activation-errors" @endif
                        data-activation-password-confirm
                    >
                    <button
                        type="button"
                        class="tala-access-password-toggle"
                        data-toggle-password="password_confirmation"
                        aria-label="Show password confirmation"
                        aria-pressed="false"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        </svg>
                    </button>
                </div>
            </div>

            <ul class="tala-access-checklist" aria-label="Password requirements" aria-live="polite">
                <li class="tala-access-checklist__item" data-check="length">
                    <span class="tala-access-checklist__dot" aria-hidden="true"></span>
                    <span>15 to 64 characters</span>
                </li>
                <li class="tala-access-checklist__item" data-check="match">
                    <span class="tala-access-checklist__dot" aria-hidden="true"></span>
                    <span>Passwords match</span>
                </li>
            </ul>

            <button type="submit" class="tala-access-primary">Activate account</button>
        </form>

        <script>
            (() => {
                const form = document.querySelector('[data-activation-form]');
                if (!form) return;
                const pw = form.querySelector('[data-activation-password]');
                const confirmPw = form.querySelector('[data-activation-password-confirm]');
                const lengthItem = form.querySelector('[data-check="length"]');
                const matchItem = form.querySelector('[data-check="match"]');

                const updateChecklist = () => {
                    const value = pw ? pw.value : '';
                    const confirmValue = confirmPw ? confirmPw.value : '';
                    const lengthOk = value.length >= 15 && value.length <= 64;
                    const matchOk = value.length > 0 && value === confirmValue;

                    if (lengthItem) lengthItem.dataset.met = String(lengthOk);
                    if (matchItem) matchItem.dataset.met = String(matchOk);

                    if (confirmPw) {
                        confirmPw.setCustomValidity(
                            confirmValue.length > 0 && value !== confirmValue
                                ? 'Passwords do not match.'
                                : ''
                        );
                    }
                };

                pw?.addEventListener('input', updateChecklist);
                confirmPw?.addEventListener('input', updateChecklist);
                updateChecklist();

                form.querySelectorAll('[data-toggle-password]').forEach((btn) => {
                    btn.addEventListener('click', () => {
                        const targetId = btn.getAttribute('data-toggle-password');
                        const input = targetId ? document.getElementById(targetId) : null;
                        if (!input) return;
                        const showing = input.type === 'text';
                        input.type = showing ? 'password' : 'text';
                        btn.setAttribute('aria-pressed', String(!showing));
                        btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
                    });
                });
            })();
        </script>
    @endif
</x-tala-access-layout>
