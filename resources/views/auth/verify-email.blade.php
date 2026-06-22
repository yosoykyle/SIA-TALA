<x-public-auth title="Verify your email">
    @if (session('status') === 'verification-link-sent')
        <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            A new verification link has been sent to your email address.
        </div>
    @endif

    <div class="grid gap-5 text-sm leading-6 text-slate-700">
        <p>Your account must verify its email address before accessing protected T.A.L.A. pages.</p>

        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="rounded-lg bg-sky-700 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-sky-800 focus:outline-none focus:ring-2 focus:ring-sky-300">
                Resend verification link
            </button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="text-sm font-semibold text-slate-600 hover:text-slate-950">
                Sign out
            </button>
        </form>
    </div>
</x-public-auth>
