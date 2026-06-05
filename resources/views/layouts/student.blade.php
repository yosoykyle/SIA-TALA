<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Student Hub - T.A.L.A.' }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400..600&display=swap" rel="stylesheet">

    <tallstackui:script />
    @PwaHead
    @livewireStyles
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white dark:bg-zinc-800 antialiased">
    <x-layout>
        <x-slot:header>
            <x-layout.header>
                <x-slot:right>
                    <x-dropdown text="Student Name">
                        <x-dropdown.items icon="user" text="Profile" />
                        <x-dropdown.items separator icon="arrow-right-start-on-rectangle" text="Logout" />
                    </x-dropdown>
                </x-slot:right>
            </x-layout.header>
        </x-slot:header>

        <x-slot:menu>
            <x-side-bar smart navigate>
                <x-slot:brand>
                    <div class="flex justify-center py-4">
                        <span class="text-lg font-bold text-zinc-900 dark:text-white">T.A.L.A.</span>
                    </div>
                </x-slot:brand>

                <x-side-bar.item text="Dashboard" icon="home" :route="route('student.dashboard')" />
                <x-side-bar.item text="Schedule" icon="calendar" :route="route('student.schedule')" />
                <x-side-bar.item text="Grades" icon="academic-cap" :route="route('student.grades')" />
                <x-side-bar.item text="Financials" icon="credit-card" :route="route('student.financials')" />
                <x-side-bar.item text="Documents" icon="document-text" :route="route('student.documents')" />

                <x-side-bar.separator text="Support" />

                <x-side-bar.item text="Help" icon="information-circle" :route="route('student.help')" />
            </x-side-bar>
        </x-slot:menu>

        {{ $slot }}
    </x-layout>

    @livewireScripts
    @RegisterServiceWorkerScript
</body>
</html>
