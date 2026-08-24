<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Activate Staff access — TALA</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-950">
    <main class="mx-auto flex min-h-screen max-w-xl items-center px-4 py-10">
        <section class="w-full rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="activation-heading">
            <img src="{{ asset('talalogo.png') }}" alt="TALA" class="mb-6 h-12 w-auto">
            <h1 id="activation-heading" class="text-2xl font-semibold">Activate Staff access</h1>

            @if (! $isUsable)
                <div class="mt-5 rounded-lg border border-amber-300 bg-amber-50 p-4" role="alert">
                    <p class="font-medium">This activation link is expired or no longer valid.</p>
                    <p class="mt-1 text-sm">Ask a System Administrator to resend the invitation.</p>
                </div>
            @else
                <p class="mt-2 text-sm text-slate-600">Create your own password. You will set up authenticator-app MFA next.</p>

                @if ($errors->any())
                    <div class="mt-5 rounded-lg border border-red-300 bg-red-50 p-4" role="alert" aria-labelledby="activation-errors">
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
                        <input id="password" name="password" type="password" required autocomplete="new-password" minlength="15" maxlength="64" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/30">
                        <p class="mt-1 text-sm text-slate-600">Use 15–64 characters. Spaces and password-manager paste are allowed.</p>
                    </div>
                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium">Confirm password</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" minlength="15" maxlength="64" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/30">
                    </div>
                    <button type="submit" class="min-h-11 w-full rounded-lg bg-blue-700 px-4 py-2.5 font-medium text-white hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-700 focus:ring-offset-2">Continue to MFA setup</button>
                </form>
            @endif
        </section>
    </main>
</body>
</html>
