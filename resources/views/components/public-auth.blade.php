@props(['title'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh bg-slate-100 text-slate-950 antialiased">
    <main class="grid min-h-dvh place-items-center px-4 py-10">
        <section class="w-full max-w-md rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <a href="{{ route('home') }}" class="text-sm font-black tracking-tight text-sky-800">T.A.L.A.</a>
            <h1 class="mt-4 text-2xl font-black tracking-tight">{{ $title }}</h1>
            <div class="mt-6">
                {{ $slot }}
            </div>
        </section>
    </main>
</body>
</html>
