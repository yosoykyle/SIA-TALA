<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>T.A.L.A. COR Verification</title>
    @livewireStyles
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh bg-slate-950 text-slate-100 antialiased">
    @php
        $statusColor = match ($result['status']) {
            'valid' => 'border-emerald-400/40 bg-emerald-400/10 text-emerald-100',
            'superseded' => 'border-amber-300/40 bg-amber-300/10 text-amber-100',
            'revoked' => 'border-rose-400/40 bg-rose-400/10 text-rose-100',
            'expired' => 'border-slate-300/30 bg-slate-300/10 text-slate-100',
            default => 'border-slate-300/20 bg-slate-300/10 text-slate-100',
        };
    @endphp

    <main class="relative isolate min-h-dvh overflow-hidden">
        <div class="absolute inset-0 -z-10 bg-[radial-gradient(circle_at_top_left,rgba(14,165,233,0.24),transparent_34%),linear-gradient(135deg,#020617_0%,#0f172a_52%,#111827_100%)]"></div>

        <section class="mx-auto flex min-h-dvh w-full max-w-4xl flex-col justify-center gap-8 px-6 py-10 sm:px-8">
            <nav class="flex items-center justify-between gap-4">
                <a href="{{ route('home') }}" class="text-lg font-bold tracking-tight text-white">T.A.L.A.</a>
                <span class="text-sm font-medium text-slate-300">COR Verification</span>
            </nav>

            <article class="rounded-2xl border border-white/10 bg-white/95 p-6 text-slate-950 shadow-2xl shadow-slate-950/30 sm:p-8">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">Certificate of Registration</p>
                        <h1 class="mt-2 text-3xl font-black tracking-tight text-slate-950">Verification Result</h1>
                    </div>
                    <span class="inline-flex w-fit rounded-full border px-3 py-1 text-sm font-bold {{ $statusColor }}">
                        {{ $result['label'] }}
                    </span>
                </div>

                <p class="mt-6 rounded-xl bg-slate-100 px-4 py-3 text-sm leading-6 text-slate-700">
                    {{ $result['message'] }}
                </p>

                @if ($result['status'] !== 'not_found')
                    <dl class="mt-6 grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Student ID</dt>
                            <dd class="mt-1 text-base font-semibold text-slate-950">{{ $result['student_id'] ?? 'Not available' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Student Name</dt>
                            <dd class="mt-1 text-base font-semibold text-slate-950">{{ $result['student_name'] ?? 'Not available' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Term</dt>
                            <dd class="mt-1 text-base font-semibold text-slate-950">{{ $result['term'] ?? 'Not available' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Enrollment Status</dt>
                            <dd class="mt-1 text-base font-semibold text-slate-950">{{ $result['enrollment_status'] ?? 'Not available' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Issued At</dt>
                            <dd class="mt-1 text-base font-semibold text-slate-950">{{ $result['issued_at'] ?? 'Not available' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Expires At</dt>
                            <dd class="mt-1 text-base font-semibold text-slate-950">{{ $result['expires_at'] ?? 'No expiry recorded' }}</dd>
                        </div>
                    </dl>

                    @if ($result['revocation_reason'])
                        <p class="mt-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm leading-6 text-rose-900">
                            {{ $result['revocation_reason'] }}
                        </p>
                    @endif
                @endif
            </article>
        </section>
    </main>
    @livewireScripts
</body>
</html>
