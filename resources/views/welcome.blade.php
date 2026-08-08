@extends('layouts.landing-bootstrap', ['title' => 'TALA'])

@section('content')
    <a class="skip-link" href="#main-content">Skip to main content</a>

    <nav class="navbar navbar-expand-lg navbar-dark fixed-top bg-transparent" aria-label="Primary navigation">
        <div class="backdrop-blur" aria-hidden="true">
            <span></span>
            <span></span>
            <span></span>
            <span></span>
            <span></span>
            <span></span>
        </div>

        <div class="container">
            <a class="navbar-brand fs-5 fw-bold d-flex align-items-center" href="{{ url('/') }}">
                <img src="{{ asset('talalogo.png') }}" alt="" class="landing-brand-logo">
                <span data-navbar-contrast-target>TALA</span>
            </a>

            <button class="navbar-toggler border-0" type="button" data-navbar-contrast-target data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Open navigation menu">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
                <ul class="navbar-nav gap-3 pt-3 pt-lg-0">
                    <li class="nav-item"><a class="nav-link" data-navbar-contrast-target href="{{ url('/#login') }}">LOGIN</a></li>
                    @if ($admissionsOpen)
                        <li class="nav-item"><a class="nav-link" data-navbar-contrast-target href="{{ route('filament.applicant.auth.register') }}">APPLY</a></li>
                    @endif
                    <li class="nav-item"><a class="nav-link" data-navbar-contrast-target href="{{ url('/#about-us') }}">ABOUT US</a></li>
                    <li class="nav-item"><a class="nav-link" data-navbar-contrast-target href="{{ url('/#faq') }}">FAQ</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <main id="main-content" tabindex="-1">
        <section class="hero-section" id="top" data-navbar-contrast-surface="dark" aria-labelledby="hero-title">
            <div class="container">
                <div class="row align-items-center gx-3 gx-lg-5 gy-5">
                    <div class="col-lg-6">
                        <p class="hero-kicker mb-3">Servitech Institute Asia</p>
                        <h1 class="display-headline" id="hero-title">Tertiary Academic Lifecycle Administration</h1>
                        <p class="hero-lead">
                            TALA connects admissions, enrollment, scheduling, finance, grades, and official academic records through secure workspaces for each school role.
                        </p>
                        <div class="d-flex flex-column flex-sm-row gap-3 mt-4">
                            @if ($admissionsOpen)
                                <a class="btn btn-primary-custom" href="{{ route('filament.applicant.auth.register') }}">
                                    Apply Online
                                    <i class="bi bi-arrow-right ms-2" aria-hidden="true"></i>
                                </a>
                            @else
                                <span class="btn btn-primary-custom disabled" aria-disabled="true">Applications are currently closed</span>
                            @endif
                            <a class="btn btn-secondary-custom" href="{{ url('/#login') }}">Choose a workspace</a>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="portal-overview" data-navbar-contrast-surface="light" aria-labelledby="portal-overview-title">
                            <div class="portal-overview-header">
                                <img src="{{ asset('talalogo.png') }}" alt="" class="hero-mockup-logo">
                                <div>
                                    <p class="portal-label mb-1">TALA access guide</p>
                                    <h2 class="h3 mb-0" id="portal-overview-title">One system. Three clear workspaces.</h2>
                                </div>
                            </div>

                            <ol class="workspace-summary list-unstyled mb-0">
                                <li>
                                    <span class="workspace-number" aria-hidden="true">1</span>
                                    <div>
                                        <strong>Applicant Workspace</strong>
                                        <span>Apply and track admission requirements.</span>
                                    </div>
                                </li>
                                <li>
                                    <span class="workspace-number" aria-hidden="true">2</span>
                                    <div>
                                        <strong>Student Hub</strong>
                                        <span>View enrollment, schedules, finance, and academic records.</span>
                                    </div>
                                </li>
                                <li>
                                    <span class="workspace-number" aria-hidden="true">3</span>
                                    <div>
                                        <strong>Staff Workspace</strong>
                                        <span>Manage verified school operations according to your role.</span>
                                    </div>
                                </li>
                            </ol>

                            <p class="portal-note mb-0">
                                <i class="bi bi-shield-check" aria-hidden="true"></i>
                                Each workspace shows only the records and actions permitted for that account.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="features-section section-block" id="login" data-navbar-contrast-surface="theme" aria-labelledby="login-title">
            <div class="container">
                <div class="section-heading text-center">
                    <p class="section-kicker">Secure role-based access</p>
                    <h2 class="section-title" id="login-title">LOGIN</h2>
                    <p class="section-lead">
                        Choose the workspace that matches your current school relationship. Applicants can create an account; student and staff accounts are activated through official school processes.
                    </p>
                </div>

                <div class="row g-4">
                    <div class="col-lg-4">
                        <article class="workspace-card h-100">
                            <div class="workspace-icon" aria-hidden="true"><i class="bi bi-person-plus"></i></div>
                            <p class="workspace-audience">For prospective and returning applicants</p>
                            <h3>Applicant Workspace</h3>
                            <p>Create or continue an application, submit required digital evidence, and respond to Registrar feedback.</p>
                            <ul class="workspace-capabilities">
                                <li>Save an application draft</li>
                                <li>Upload applicable requirements</li>
                                <li>Track review and correction status</li>
                            </ul>
                            <div class="workspace-actions">
                                @if ($admissionsOpen)
                                    <a class="btn btn-black-action" href="{{ route('filament.applicant.auth.register') }}">Apply Online</a>
                                @else
                                    <span class="btn btn-black-action disabled" aria-disabled="true">Applications Closed</span>
                                @endif
                                <a class="text-link" href="{{ route('filament.applicant.auth.login') }}">Applicant Sign In <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                            </div>
                        </article>
                    </div>

                    <div class="col-lg-4">
                        <article class="workspace-card h-100">
                            <div class="workspace-icon" aria-hidden="true"><i class="bi bi-mortarboard"></i></div>
                            <p class="workspace-audience">For officially handed-over students</p>
                            <h3>Student Hub</h3>
                            <p>Review your current enrollment, published schedule, finance status, holds, grades, and authenticated outputs.</p>
                            <ul class="workspace-capabilities">
                                <li>Follow enrollment next steps</li>
                                <li>Open current COR and schedule</li>
                                <li>Review balances, payments, and grades</li>
                            </ul>
                            <div class="workspace-actions">
                                <a class="btn btn-black-action" href="{{ route('filament.student.auth.login') }}">Student Sign In</a>
                            </div>
                        </article>
                    </div>

                    <div class="col-lg-4">
                        <article class="workspace-card h-100">
                            <div class="workspace-icon" aria-hidden="true"><i class="bi bi-building-gear"></i></div>
                            <p class="workspace-audience">For authorized school personnel</p>
                            <h3>Staff Workspace</h3>
                            <p>Registrar, Accounting, Faculty, Academic Head, and System Super Admin users work from one role-scoped panel.</p>
                            <ul class="workspace-capabilities">
                                <li>Process assigned operational queues</li>
                                <li>Review authoritative school records</li>
                                <li>Access only permitted actions and reports</li>
                            </ul>
                            <div class="workspace-actions">
                                <a class="btn btn-black-action" href="{{ route('filament.admin.auth.login') }}">Staff Sign In</a>
                            </div>
                        </article>
                    </div>
                </div>
            </div>
        </section>

        <section class="info-map-section section-block" data-navbar-contrast-surface="theme" aria-labelledby="location-title">
            <div class="container">
                <div class="row align-items-center gx-3 gx-lg-5 gy-5">
                    <div class="col-lg-5">
                        <p class="section-kicker">Institution</p>
                        <h2 class="section-title text-start" id="location-title">LOCATION</h2>
                        <p class="section-lead text-start">
                            TALA is the school information portal of Servitech Institute Asia. Open the map for campus location guidance.
                        </p>
                        <div class="section-actions">
                            <a href="https://www.google.com/maps?cid=781880921815418296&g_mp=CiVnb29nbGUubWFwcy5wbGFjZXMudjEuUGxhY2VzLkdldFBsYWNlEAMYASAF&hl=en&gl=PH&source=embed" target="_blank" rel="noopener noreferrer" class="btn btn-black-action">
                                Open in Google Maps
                                <i class="bi bi-box-arrow-up-right ms-2" aria-hidden="true"></i>
                            </a>
                        </div>
                    </div>
                    <div class="col-lg-7">
                        <div class="map-box" data-navbar-contrast-surface="light">
                            <iframe title="Map showing Servitech Institute Asia" src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3865.5801177213743!2d121.02881261016364!3d14.335805183447881!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3397d7a36f29214b%3A0xad9cc8e497685b8!2sServitech%20Institute%20Asia%2C%20Inc.!5e0!3m2!1sen!2sph!4v1782779440549!5m2!1sen!2sph" width="100%" height="420" class="map-frame" allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="about-section section-block" id="about-us" data-navbar-contrast-surface="theme" aria-labelledby="about-title">
            <div class="container">
                <div class="section-heading text-center">
                    <p class="section-kicker">Institutional direction</p>
                    <h2 class="section-title" id="about-title">ABOUT US</h2>
                    <p class="section-lead">The portal supports the school's academic mission while keeping each operational responsibility clear and accountable.</p>
                </div>

                <div class="row">
                    <div class="col-lg-9 mx-auto">
                        <div class="accordion accordion-custom" id="aboutAccordion">
                            <div class="accordion-item">
                                <h3 class="accordion-header" id="headingMission">
                                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseMission" aria-expanded="true" aria-controls="collapseMission">OUR MISSION</button>
                                </h3>
                                <div id="collapseMission" class="accordion-collapse collapse show" aria-labelledby="headingMission" data-bs-parent="#aboutAccordion">
                                    <div class="accordion-body">Servitech Institute Asia (SIA) aims to equip each individual with a specialized set of conceptual and practical skills that can support competitive, professional, innovative, and applicable work.</div>
                                </div>
                            </div>

                            <div class="accordion-item">
                                <h3 class="accordion-header" id="headingVision">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseVision" aria-expanded="false" aria-controls="collapseVision">OUR VISION</button>
                                </h3>
                                <div id="collapseVision" class="accordion-collapse collapse" aria-labelledby="headingVision" data-bs-parent="#aboutAccordion">
                                    <div class="accordion-body">SIA serves students through a balanced curriculum that develops ethical, innovative thinkers prepared for positive societal and professional contribution.</div>
                                </div>
                            </div>

                            <div class="accordion-item">
                                <h3 class="accordion-header" id="headingHistory">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseHistory" aria-expanded="false" aria-controls="collapseHistory">TALA'S ROLE</button>
                                </h3>
                                <div id="collapseHistory" class="accordion-collapse collapse" aria-labelledby="headingHistory" data-bs-parent="#aboutAccordion">
                                    <div class="accordion-body">TALA supports the school's move from fragmented academic and administrative processes toward one role-scoped source of truth for admissions, enrollment, scheduling, finance, grades, records, and audit.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="faq-section section-block" id="faq" data-navbar-contrast-surface="theme" aria-labelledby="faq-title">
            <div class="container">
                <div class="section-heading text-center">
                    <p class="section-kicker">Public guidance</p>
                    <h2 class="section-title" id="faq-title">FREQUENTLY ASKED QUESTIONS</h2>
                    <p class="section-lead">Published answers explain common access, admission, enrollment, payment, and academic-record questions.</p>
                </div>

                <div class="row">
                    <div class="col-lg-9 mx-auto">
                        <div class="accordion accordion-custom" id="faqAccordion">
                            @forelse ($faqEntries as $entry)
                                <div class="accordion-item">
                                    <h3 class="accordion-header" id="headingFaq{{ $entry->id }}">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFaq{{ $entry->id }}" aria-expanded="false" aria-controls="collapseFaq{{ $entry->id }}">
                                            <span class="faq-question">
                                                <span class="faq-category">{{ \App\Models\FaqEntry::categoryLabel($entry->category) }}</span>
                                                <span>{{ $entry->question }}</span>
                                            </span>
                                        </button>
                                    </h3>
                                    <div id="collapseFaq{{ $entry->id }}" class="accordion-collapse collapse" aria-labelledby="headingFaq{{ $entry->id }}" data-bs-parent="#faqAccordion">
                                        <div class="accordion-body">{{ $entry->answer }}</div>
                                    </div>
                                </div>
                            @empty
                                <div class="empty-guidance" role="status">
                                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                                    <div>
                                        <strong>No public FAQs are available yet.</strong>
                                        <p class="mb-0">Use the workspace links above or contact the responsible school office for help.</p>
                                    </div>
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="footer-section" data-navbar-contrast-surface="dark">
        <div class="container">
            <div class="row align-items-start g-4">
                <div class="col-lg-7">
                    <a class="footer-brand d-inline-flex align-items-center text-decoration-none" href="{{ url('/') }}">
                        <img src="{{ asset('talalogo.png') }}" alt="" class="footer-logo">
                        <span>TALA</span>
                    </a>
                    <p class="footer-desc mt-3 mb-0">Tertiary Academic Lifecycle Administration for Servitech Institute Asia.</p>
                </div>
                <div class="col-lg-5">
                    <nav class="d-flex flex-wrap justify-content-lg-end gap-3" aria-label="Footer navigation">
                        <a class="footer-link" href="{{ url('/#login') }}">Login</a>
                        @if ($admissionsOpen)
                            <a class="footer-link" href="{{ route('filament.applicant.auth.register') }}">Apply Online</a>
                        @endif
                        <a class="footer-link" href="{{ url('/#about-us') }}">About Us</a>
                        <a class="footer-link" href="{{ url('/#faq') }}">FAQ</a>
                    </nav>
                </div>
            </div>
            <div class="footer-divider mt-5 pt-4 text-center">
                <p class="mb-0">&copy; {{ date('Y') }} Servitech Institute Asia (SIA)</p>
            </div>
        </div>
    </footer>

    <div class="bottom-blur-strip" aria-hidden="true">
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
    </div>

    <button class="btn-scroll-top" type="button" aria-label="Scroll to top">
        <i class="bi bi-arrow-up" aria-hidden="true"></i>
    </button>
@endsection
