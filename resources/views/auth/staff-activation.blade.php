<x-tala-access-layout title="Activate Staff access" heading-id="activation-heading">
            <h1 id="activation-heading" class="text-2xl font-semibold">Activate Staff access</h1>

            @if (! $isUsable)
                <div class="tala-access-alert mt-5" role="alert">
                    <p class="font-medium">This activation link is expired or no longer valid.</p>
                    <p class="mt-1 text-sm">This link cannot activate access now. Ask a System Administrator to resend the invitation.</p>
                </div>
            @else
                <p class="tala-access-muted mt-2 text-sm">Create your own password. You will set up authenticator-app MFA next.</p>

                @if ($errors->any())
                    <div class="tala-access-alert mt-5" role="alert" aria-labelledby="activation-errors">
                        <p id="activation-errors" class="font-medium">Review the highlighted fields.</p>
                        <ul class="mt-2 list-disc pl-5 text-sm">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('staff-invitations.accept', $invitation) }}" class="mt-6 space-y-5">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <div>
                        <label for="password" class="block text-sm font-medium">Password</label>
                        <input id="password" name="password" type="password" required autocomplete="new-password" minlength="15" maxlength="64" class="tala-access-input mt-1" aria-describedby="password-guidance{{ $errors->has('password') ? ' activation-errors' : '' }}" @if ($errors->has('password')) aria-invalid="true" @endif>
                        <p id="password-guidance" class="tala-access-muted mt-1 text-sm">Use 15–64 characters. Spaces and password-manager paste are allowed.</p>
                    </div>
                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium">Confirm password</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" minlength="15" maxlength="64" class="tala-access-input mt-1" @if ($errors->has('password')) aria-invalid="true" aria-describedby="activation-errors" @endif>
                    </div>
                    <button type="submit" class="tala-access-primary">Continue to MFA setup</button>
                </form>
            @endif
</x-tala-access-layout>
