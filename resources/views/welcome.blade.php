<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>T.A.L.A. School Information System</title>

    @PwaHead
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh bg-slate-50 font-sans text-slate-950 antialiased">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-slate-950 focus:shadow">
        Skip to content
    </a>

    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
            <a href="{{ route('home') }}" class="flex items-center gap-3" aria-label="T.A.L.A. home">
                <img src="{{ asset('talalogo.jpg') }}" alt="" class="h-10 w-10 rounded-md object-cover ring-1 ring-slate-200">
                <div>
                    <p class="text-sm font-semibold text-slate-950">T.A.L.A. SIS</p>
                    <p class="text-xs text-slate-500">School Information System</p>
                </div>
            </a>

            <nav class="hidden items-center gap-6 text-sm font-medium text-slate-600 md:flex" aria-label="Public navigation">
                <a href="#school" class="hover:text-slate-950">School</a>
                <a href="#admissions" class="hover:text-slate-950">Admissions</a>
                <a href="#records" class="hover:text-slate-950">Records</a>
                <a href="{{ route('faq') }}" class="hover:text-slate-950">FAQ</a>
            </nav>

            <a href="{{ route('login') }}" class="inline-flex min-h-11 items-center rounded-md bg-blue-700 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-700 focus:ring-offset-2">
                Sign in
            </a>
        </div>
    </header>

    <main id="main-content">
        <section class="border-b border-slate-200 bg-white">
            <div class="mx-auto grid max-w-7xl gap-10 px-4 py-14 sm:px-6 lg:grid-cols-[1fr_0.85fr] lg:px-8 lg:py-20">
                <div class="flex flex-col justify-center">
                    <p class="text-sm font-semibold uppercase text-blue-700">College information system</p>
                    <h1 class="mt-4 max-w-3xl text-4xl font-semibold leading-tight text-slate-950 sm:text-5xl">
                        A focused school portal for admissions, enrollment, schedules, grades, and financial records.
                    </h1>
                    <p class="mt-6 max-w-2xl text-base leading-7 text-slate-600">
                        T.A.L.A. supports the school lifecycle from applicant intake to official enrollment, staff-managed academic records, payment evidence, schedule publication, and finalized grade history.
                    </p>

                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <a href="#admissions" class="inline-flex min-h-11 items-center justify-center rounded-md bg-blue-700 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-700 focus:ring-offset-2">
                            View admissions flow
                        </a>
                        <a href="{{ route('login') }}" class="inline-flex min-h-11 items-center justify-center rounded-md border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-800 transition hover:border-slate-400 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2">
                            Account sign in
                        </a>
                    </div>
                </div>

                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 shadow-sm">
                    <div class="rounded-md border border-slate-200 bg-white p-5">
                        <div class="flex items-center gap-3">
                            <img src="{{ asset('talalogo.jpg') }}" alt="" class="h-12 w-12 rounded-md object-cover">
                            <div>
                                <p class="text-base font-semibold text-slate-950">T.A.L.A. workflow</p>
                                <p class="text-sm text-slate-500">Admissions to official records</p>
                            </div>
                        </div>

                        <dl class="mt-6 grid gap-3">
                            <div class="rounded-md border border-slate-200 p-4">
                                <dt class="text-xs font-semibold uppercase text-slate-500">01 Admissions</dt>
                                <dd class="mt-1 text-sm font-semibold text-slate-950">Applicant intake and requirement review</dd>
                            </div>
                            <div class="rounded-md border border-slate-200 p-4">
                                <dt class="text-xs font-semibold uppercase text-slate-500">02 Enrollment</dt>
                                <dd class="mt-1 text-sm font-semibold text-slate-950">Sectioning, COR, and capacity control</dd>
                            </div>
                            <div class="rounded-md border border-slate-200 p-4">
                                <dt class="text-xs font-semibold uppercase text-slate-500">03 Operations</dt>
                                <dd class="mt-1 text-sm font-semibold text-slate-950">Schedules, grades, payments, and staff review</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>
        </section>

        <section id="school" class="border-b border-slate-200 bg-slate-50">
            <div class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
                <div class="max-w-3xl">
                    <p class="text-sm font-semibold uppercase text-blue-700">School context</p>
                    <h2 class="mt-3 text-3xl font-semibold text-slate-950">Built for a college registrar, accounting, faculty, and academic records workflow.</h2>
                    <p class="mt-4 text-base leading-7 text-slate-600">
                        The system keeps public pages informational while protected staff workflows manage sensitive student records, admissions decisions, payments, schedules, and grades.
                    </p>
                </div>
            </div>
        </section>

        <section id="admissions" class="bg-white">
            <div class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
                <div class="grid gap-10 lg:grid-cols-[0.9fr_1.1fr]">
                    <div>
                        <p class="text-sm font-semibold uppercase text-blue-700">Applicant intake</p>
                        <h2 class="mt-3 text-3xl font-semibold text-slate-950">A controlled path from application details to enrollment readiness.</h2>
                        <p class="mt-4 text-base leading-7 text-slate-600">
                            Applicant intake is the first operational checkpoint. Registrar staff validate the admission offering, required documents, duplicate checks, and readiness before the student account is promoted.
                        </p>
                    </div>

                    <ol class="grid gap-3">
                        @foreach ([
                            ['label' => '01', 'title' => 'Admission offering', 'text' => 'The school defines the active term, program, entry route, requirements, and capacity rules.'],
                            ['label' => '02', 'title' => 'Applicant record', 'text' => 'The applicant profile and uploaded evidence are attached to the controlled intake record.'],
                            ['label' => '03', 'title' => 'Registrar review', 'text' => 'Staff review requirements, readiness blockers, and payment or enrollment prerequisites.'],
                            ['label' => '04', 'title' => 'Student account', 'text' => 'After approval and handover, the account can be promoted for protected student access.'],
                        ] as $step)
                            <li class="grid gap-4 rounded-lg border border-slate-200 bg-slate-50 p-4 sm:grid-cols-[3rem_1fr]">
                                <span class="flex h-10 w-10 items-center justify-center rounded-md bg-blue-700 text-sm font-semibold text-white">{{ $step['label'] }}</span>
                                <div>
                                    <h3 class="font-semibold text-slate-950">{{ $step['title'] }}</h3>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">{{ $step['text'] }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>
        </section>

        <section id="records" class="border-y border-slate-200 bg-slate-950 text-white">
            <div class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
                <div class="grid gap-10 lg:grid-cols-[0.9fr_1.1fr] lg:items-center">
                    <div>
                        <p class="text-sm font-semibold uppercase text-blue-300">Protected records</p>
                        <h2 class="mt-3 text-3xl font-semibold">Sensitive student records stay behind authenticated school workflows.</h2>
                        <p class="mt-4 text-base leading-7 text-slate-300">
                            COR access, class schedules, financial standing, finalized grades, and operational decisions are protected surfaces. Public pages only explain the process.
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach (['Admissions', 'Enrollment', 'Schedules', 'Financial records', 'Grades', 'Help resources'] as $item)
                            <div class="rounded-lg border border-white/15 bg-white/5 p-4">
                                <p class="text-sm font-semibold text-white">{{ $item }}</p>
                                <p class="mt-1 text-xs leading-5 text-slate-300">Managed through approved authenticated workflows</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <section class="bg-white">
            <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-14 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-8">
                <div>
                    <p class="text-sm font-semibold uppercase text-blue-700">Need help?</p>
                    <h2 class="mt-3 text-3xl font-semibold text-slate-950">Use the FAQ or sign in with your assigned school account.</h2>
                    <p class="mt-4 max-w-2xl text-base leading-7 text-slate-600">
                        Public users can read published guidance. Staff and approved account holders use authenticated workflows for protected records.
                    </p>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row">
                    <a href="{{ route('faq') }}" class="inline-flex min-h-11 items-center justify-center rounded-md border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-800 transition hover:border-slate-400 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2">
                        View FAQ
                    </a>
                    <a href="{{ route('login') }}" class="inline-flex min-h-11 items-center justify-center rounded-md bg-blue-700 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-700 focus:ring-offset-2">
                        Sign in
                    </a>
                </div>
            </div>
        </section>
    </main>

    @RegisterServiceWorkerScript
</body>
</html>
