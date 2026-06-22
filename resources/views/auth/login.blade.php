<x-public-auth title="Sign in to T.A.L.A.">
    <form method="POST" action="{{ route('login.store') }}" class="grid gap-5">
        @csrf

        <div>
            <label for="email" class="text-sm font-semibold text-slate-700">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-950 shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200">
            @error('email')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="text-sm font-semibold text-slate-700">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-950 shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200">
            @error('password')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center justify-between gap-4">
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input name="remember" type="checkbox" class="rounded border-slate-300 text-sky-600 focus:ring-sky-500">
                Remember me
            </label>
            <a href="{{ route('password.request') }}" class="text-sm font-semibold text-sky-700 hover:text-sky-900">Forgot password?</a>
        </div>

        <button type="submit" class="rounded-lg bg-sky-700 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-sky-800 focus:outline-none focus:ring-2 focus:ring-sky-300">
            Sign in
        </button>
    </form>
</x-public-auth>
