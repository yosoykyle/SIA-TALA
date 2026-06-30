<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'TALA' }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <tallstackui:setup />
</head>
<body class="min-h-screen bg-stone-50 text-zinc-950 antialiased font-sans">
    <x-layout.header without-mobile-button class="sticky top-0 z-50 !bg-white/70 backdrop-blur-lg !border-b !border-zinc-200/40 !h-auto !py-3 shadow-sm">
        <x-slot:left>
            <a href="{{ url('/') }}" class="flex items-center gap-3 group transition-transform duration-200 active:scale-95">
                <img src="{{ asset('talalogo.png') }}" alt="TALA" class="size-11 rounded-xl border border-zinc-200/60 object-cover shadow-sm transition-transform group-hover:scale-105">
                <span class="flex flex-col">
                    <span class="text-lg font-bold tracking-tight text-zinc-950">TALA</span>
                    <span class="text-xs font-medium text-zinc-500">Admissions & Records Portal</span>
                </span>
            </a>
        </x-slot:left>

        <x-slot:right>
            <nav aria-label="Primary navigation" class="flex flex-wrap items-center gap-2 text-sm">
                <a href="{{ url('/#admissions') }}" class="rounded-xl px-3.5 py-2 font-semibold text-zinc-600 hover:bg-zinc-500/10 hover:text-zinc-950 transition-colors duration-200">Admissions</a>
                <a href="{{ url('/#access') }}" class="rounded-xl px-3.5 py-2 font-semibold text-zinc-600 hover:bg-zinc-500/10 hover:text-zinc-950 transition-colors duration-200">Access</a>
                <a href="{{ url('/#faq') }}" class="rounded-xl px-3.5 py-2 font-semibold text-zinc-600 hover:bg-zinc-500/10 hover:text-zinc-950 transition-colors duration-200">FAQ</a>
            </nav>
        </x-slot:right>
    </x-layout.header>

    @yield('content')
</body>
</html>
