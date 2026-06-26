<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'T.A.L.A. Help Center' }}</title>
    @PwaHead
    @livewireStyles
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh bg-slate-950 text-slate-100 antialiased">
    {{ $slot }}

    @livewireScripts
    @RegisterServiceWorkerScript
</body>
</html>
