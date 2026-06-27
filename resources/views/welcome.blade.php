@extends('layouts.public', ['title' => 'TALA'])

@section('content')
    <main class="min-h-screen">
        <!-- Hero / Welcome Section -->
        <section class="relative bg-stone-50 overflow-hidden border-b border-zinc-200/40">
            <div class="relative z-10 mx-auto grid max-w-7xl gap-10 px-4 py-16 sm:px-6 lg:grid-cols-[1.15fr_0.85fr] lg:px-8 lg:py-24">
                <div class="flex flex-col justify-center gap-6">
                    <div class="text-left">
                        <x-badge text="Public entry point" color="emerald" light round class="font-bold px-3 py-1 text-xs" />
                    </div>
                    <div class="space-y-4">
                        <h1 class="max-w-3xl text-5xl font-extrabold tracking-tight text-zinc-950 sm:text-6xl text-left">
                            Welcome to <span class="text-emerald-700">TALA</span>
                        </h1>
                        <p class="max-w-2xl text-lg leading-relaxed text-zinc-700 font-medium text-left">
                            Apply online, sign in to your assigned workspace, and follow school guidance for admissions, enrollment, finance evidence, records, and academic access.
                        </p>
                    </div>
                    <div class="flex flex-col gap-4 sm:flex-row pt-2 justify-start items-stretch sm:items-center">
                        <x-button href="{{ route('filament.applicant.auth.register') }}" text="Apply Online" color="emerald" round lg class="font-extrabold px-6 py-3.5 shadow-md shadow-emerald-700/10 transition-transform duration-200 active:scale-95" />
                        <x-button href="{{ route('filament.applicant.auth.login') }}" text="Sign In" outline color="zinc" round lg class="font-extrabold px-6 py-3.5 bg-white hover:bg-zinc-50 transition-transform duration-200 active:scale-95" />
                    </div>
                </div>

                <div>
                    <x-card class="bg-white/60 border border-zinc-200/50 shadow-xl backdrop-blur-md" round="xl">
                        <x-slot:header>
                            <div class="flex items-center gap-4 py-1">
                                <div class="relative">
                                    <div class="absolute -inset-1 rounded-2xl bg-emerald-500/10 blur-sm"></div>
                                    <img src="{{ asset('logo.png') }}" alt="TALA application mark" class="relative size-14 rounded-2xl border border-zinc-200/60 object-cover shadow-sm">
                                </div>
                                <div>
                                    <h3 class="text-base font-bold text-zinc-950">Account boundaries</h3>
                                    <p class="text-xs font-semibold text-zinc-500">Official Role & Workspace Guidance</p>
                                </div>
                            </div>
                        </x-slot:header>
                        
                        <div class="space-y-4 py-2">
                            <p class="text-sm leading-relaxed text-zinc-600">
                                Applicants apply. Students and staff sign in after official account activation.
                            </p>
                            
                            <div class="grid gap-3">
                                <div class="group rounded-xl border border-zinc-200/50 bg-white/70 p-3.5 shadow-sm transition-all duration-200 hover:bg-white/95">
                                    <h4 class="text-sm font-bold text-zinc-950">Applicant Workspace</h4>
                                    <p class="mt-1 text-xs leading-relaxed text-zinc-500">For application draft, checklist, document upload guidance, and status updates.</p>
                                </div>
                                
                                <div class="group rounded-xl border border-zinc-200/50 bg-white/70 p-3.5 shadow-sm transition-all duration-200 hover:bg-white/95">
                                    <h4 class="text-sm font-bold text-zinc-950">Student Hub</h4>
                                    <p class="mt-1 text-xs leading-relaxed text-zinc-500">For current student records after handover, including enrollment, schedule, outputs, and holds.</p>
                                </div>
                                
                                <div class="group rounded-xl border border-zinc-200/50 bg-white/70 p-3.5 shadow-sm transition-all duration-200 hover:bg-white/95">
                                    <h4 class="text-sm font-bold text-zinc-950">Staff Workspace</h4>
                                    <p class="mt-1 text-xs leading-relaxed text-zinc-500">For authorized registrar, accounting, faculty, academic head, and system administration work.</p>
                                </div>
                            </div>
                        </div>
                    </x-card>
                </div>
            </div>
        </section>

        <!-- Admissions Guidance Section -->
        <section id="admissions" class="relative border-y border-zinc-200/60 bg-stone-50/50 py-16">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="max-w-3xl">
                    <h2 class="text-3xl font-bold tracking-tight text-zinc-950">Admissions Guidance</h2>
                    <p class="mt-3 text-base leading-relaxed text-zinc-600 font-medium">Use Apply Online to create an applicant account. Program and strand choices are shown as guidance until an official offering is opened by staff.</p>
                </div>
                
                <div class="mt-10 grid gap-6 md:grid-cols-3">
                    <x-card class="bg-white/75 border border-zinc-200/50 shadow-md hover:shadow-lg transition-all duration-300 hover:-translate-y-0.5 backdrop-blur-md" round="xl">
                        <h3 class="text-lg font-bold text-zinc-950">Senior High School</h3>
                        <p class="mt-2 text-sm leading-relaxed text-zinc-500">Applicant guidance for strand selection, document preparation, and evaluation follow-up.</p>
                    </x-card>
                    
                    <x-card class="bg-white/75 border border-zinc-200/50 shadow-md hover:shadow-lg transition-all duration-300 hover:-translate-y-0.5 backdrop-blur-md" round="xl">
                        <h3 class="text-lg font-bold text-zinc-950">College Programs</h3>
                        <p class="mt-2 text-sm leading-relaxed text-zinc-500">Applicant guidance for program selection, submitted requirements, and admission readiness.</p>
                    </x-card>
                    
                    <x-card class="bg-white/75 border border-zinc-200/50 shadow-md hover:shadow-lg transition-all duration-300 hover:-translate-y-0.5 backdrop-blur-md" round="xl">
                        <h3 class="text-lg font-bold text-zinc-950">Transferee and Returning</h3>
                        <p class="mt-2 text-sm leading-relaxed text-zinc-500">Applicant guidance for additional review, records checking, and school-office instructions.</p>
                    </x-card>
                </div>
            </div>
        </section>

        <!-- Access Rules Section -->
        <section id="access" class="bg-white py-16">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="grid gap-10 lg:grid-cols-[0.8fr_1.2fr]">
                    <div class="flex flex-col justify-center">
                        <h2 class="text-3xl font-bold tracking-tight text-zinc-950">Access Rules</h2>
                        <p class="mt-3 text-base leading-relaxed text-zinc-600 font-medium">The public site has no student or staff signup. Account access is assigned by role and official school process.</p>
                    </div>
                    
                    <div class="grid gap-6 sm:grid-cols-3">
                        <div class="rounded-2xl border border-amber-200 bg-amber-50/30 p-6 shadow-sm backdrop-blur-md flex flex-col justify-between h-full group hover:shadow-md transition-all duration-200">
                            <div>
                                <h3 class="font-bold text-amber-950 text-base">Applicant registration only</h3>
                                <p class="mt-2 text-sm leading-relaxed text-amber-900/80">Apply Online opens the Applicant Workspace registration surface. Student access begins after official handover.</p>
                            </div>
                            <x-button href="{{ route('filament.applicant.auth.register') }}" text="Apply Online" color="amber" class="mt-4 font-bold shadow-sm self-start transition-transform group-hover:translate-x-0.5" />
                        </div>
                        
                        <div class="rounded-2xl border border-sky-200 bg-sky-50/30 p-6 shadow-sm backdrop-blur-md flex flex-col justify-between h-full group hover:shadow-md transition-all duration-200">
                            <div>
                                <h3 class="font-bold text-sky-950 text-base">Student Hub sign in</h3>
                                <p class="mt-2 text-sm leading-relaxed text-sky-900/80">Students use Student Hub after their account has been activated by the proper office.</p>
                            </div>
                            <x-button href="{{ route('filament.student.auth.login') }}" text="Student Sign In" color="sky" class="mt-4 font-bold shadow-sm self-start transition-transform group-hover:translate-x-0.5" />
                        </div>
                        
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50/40 p-6 shadow-sm backdrop-blur-md flex flex-col justify-between h-full group hover:shadow-md transition-all duration-200">
                            <div>
                                <h3 class="font-bold text-zinc-950 text-base">Staff Workspace sign in</h3>
                                <p class="mt-2 text-sm leading-relaxed text-zinc-700">Registrar, accounting, faculty, academic head, and system admin users sign in through the staff workspace.</p>
                            </div>
                            <x-button href="{{ route('filament.admin.auth.login') }}" text="Staff Sign In" color="zinc" class="mt-4 font-bold shadow-sm self-start transition-transform group-hover:translate-x-0.5" />
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- FAQ Section -->
        <section id="faq" class="border-t border-zinc-200/60 bg-stone-50/50 py-16">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <h2 class="text-3xl font-bold tracking-tight text-zinc-950 text-center">FAQ</h2>
                
                <div class="mt-8 divide-y divide-zinc-200/50 rounded-2xl border border-zinc-200/50 bg-white/60 backdrop-blur-md shadow-md overflow-hidden">
                    <details class="group p-5" open>
                        <summary class="cursor-pointer font-bold text-zinc-950 flex items-center justify-between list-none">
                            <span>Who can create an account?</span>
                            <span class="transition-transform duration-200 group-open:rotate-180">
                                <svg class="size-5 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7" />
                                </svg>
                            </span>
                        </summary>
                        <p class="mt-3 text-sm leading-relaxed text-zinc-600 font-medium">Only applicants create accounts through Apply Online. Student and staff accounts are activated through official school processes.</p>
                    </details>
                    
                    <details class="group p-5">
                        <summary class="cursor-pointer font-bold text-zinc-950 flex items-center justify-between list-none">
                            <span>Where do students go?</span>
                            <span class="transition-transform duration-200 group-open:rotate-180">
                                <svg class="size-5 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7" />
                                </svg>
                            </span>
                        </summary>
                        <p class="mt-3 text-sm leading-relaxed text-zinc-600 font-medium">Students sign in after handover and use Student Hub for current records, schedule, outputs, grades, holds, and notices.</p>
                    </details>
                    
                    <details class="group p-5">
                        <summary class="cursor-pointer font-bold text-zinc-950 flex items-center justify-between list-none">
                            <span>Can staff register from this page?</span>
                            <span class="transition-transform duration-200 group-open:rotate-180">
                                <svg class="size-5 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7" />
                                </svg>
                            </span>
                        </summary>
                        <p class="mt-3 text-sm leading-relaxed text-zinc-600 font-medium">No. Staff accounts are created and managed by authorized System Super Admin users.</p>
                    </details>
                </div>
            </div>
        </section>
    </main>
@endsection
