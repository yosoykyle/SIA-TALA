@extends('layouts.landing-bootstrap', ['title' => 'TALA'])

@section('content')
    <nav class="navbar navbar-expand-lg navbar-dark fixed-top bg-transparent">
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
                <img src="{{ asset('landing/images/talalogo.png') }}" alt="TALA" class="landing-brand-logo">
                <span>TALA</span>
            </a>

            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
                <ul class="navbar-nav gap-3 pt-3 pt-lg-0">
                    <li class="nav-item"><a class="nav-link" href="{{ url('/#login') }}">LOGIN</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('filament.applicant.auth.register') }}">APPLY</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ url('/#about-us') }}">ABOUT US</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ url('/#faq') }}">FAQ</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <main>
        <section class="hero-section d-flex flex-column justify-content-between" id="top" data-navbar-theme="dark">
            <div class="container text-center flex-grow-1 d-flex flex-column justify-content-center">
                <h1 class="display-headline mt-5">
                    Timetable-Integrated Academic Lifecycle Administration
                </h1>
                <p class="mx-auto text-white-50 fs-5 hero-lead">
                    Apply online, sign in to your assigned workspace, and follow school guidance for admissions, enrollment, finance evidence, records, and academic access.
                </p>
                <div class="d-flex flex-column flex-sm-row justify-content-center gap-3 mt-3">
                    <a class="btn btn-primary-custom" href="{{ route('filament.applicant.auth.register') }}">
                        <div class="btn-blur" aria-hidden="true">
                            <span></span>
                            <span></span>
                            <span></span>
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                        Apply Online
                    </a>
                    <a class="btn btn-secondary-custom" href="{{ url('/#login') }}">
                        <div class="btn-blur" aria-hidden="true">
                            <span></span>
                            <span></span>
                            <span></span>
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                        Sign In
                    </a>
                </div>
            </div>

            <div class="container col-lg-8 wireframe-container">
                <div class="ratio ratio-16x9">
                    <div class="image-placeholder-placeholder d-flex align-items-center justify-content-center">
                        <div class="text-center p-4">
                            <img src="{{ asset('landing/images/talalogo.png') }}" alt="TALA application mark" class="mb-3 rounded-4 hero-mockup-logo">
                            <p class="mb-0 fw-semibold text-muted">Public entry point for the Applicant Workspace, Student Hub, and Staff Workspace.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="features-section" id="login" data-navbar-theme="light">
            <div class="container text-center">
                <h2 class="section-title mb-3 typewriter typewriter-login"><span class="visually-hidden">LOGIN</span></h2>
                <p class="text-center text-muted mb-5 mx-auto landing-section-lead">
                    Use the workspace assigned to your role. Applicants may create an account; students and staff sign in after official account activation.
                </p>
                <div class="row g-4">
                    <div class="col-lg-4 col-md-6">
                        <div class="feature-card img-applicant">
                            <div class="card-blur" aria-hidden="true">
                                <span></span>
                                <span></span>
                                <span></span>
                                <span></span>
                                <span></span>
                                <span></span>
                            </div>
                            <div class="feature-card-content">
                                <h3 class="fw-bold card-title m-0">APPLICANT</h3>
                                <p class="feature-card-text my-2">Create an application account, continue your draft, upload allowed evidence, and track admission status.</p>
                                <a class="btn btn-black-action mt-auto w-100" href="{{ route('filament.applicant.auth.register') }}">
                                    <div class="btn-blur" aria-hidden="true">
                                        <span></span>
                                        <span></span>
                                        <span></span>
                                        <span></span>
                                        <span></span>
                                        <span></span>
                                    </div>
                                    Apply Online
                                </a>
                                <a class="btn btn-black-action mt-2 w-100" href="{{ route('filament.applicant.auth.login') }}">
                                    <div class="btn-blur" aria-hidden="true">
                                        <span></span>
                                        <span></span>
                                        <span></span>
                                        <span></span>
                                        <span></span>
                                        <span></span>
                                    </div>
                                    Applicant Sign In
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4 col-md-6">
                        <div class="feature-card img-student">
                            <div class="card-blur" aria-hidden="true">
                                <span></span>
                                <span></span>
                                <span></span>
                                <span></span>
                                <span></span>
                                <span></span>
                            </div>
                            <div class="feature-card-content">
                                <h3 class="fw-bold card-title m-0">STUDENT</h3>
                                <p class="feature-card-text my-2">Access current records after handover, including enrollment, schedule, outputs, finance status, holds, and grades.</p>
                                <a class="btn btn-black-action mt-auto w-100" href="{{ route('filament.student.auth.login') }}">
                                    <div class="btn-blur" aria-hidden="true">
                                        <span></span>
                                        <span></span>
                                        <span></span>
                                        <span></span>
                                        <span></span>
                                        <span></span>
                                    </div>
                                    Student Sign In
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4 col-md-12">
                        <div class="feature-card img-staff">
                            <div class="card-blur" aria-hidden="true">
                                <span></span>
                                <span></span>
                                <span></span>
                                <span></span>
                                <span></span>
                                <span></span>
                            </div>
                            <div class="feature-card-content">
                                <h3 class="fw-bold card-title m-0">STAFF</h3>
                                <p class="feature-card-text my-2">Registrar, Accounting, Faculty, Academic Head, and System Super Admin users work in the staff panel.</p>
                                <a class="btn btn-black-action mt-auto w-100" href="{{ route('filament.admin.auth.login') }}">
                                    <div class="btn-blur" aria-hidden="true">
                                        <span></span>
                                        <span></span>
                                        <span></span>
                                        <span></span>
                                        <span></span>
                                        <span></span>
                                    </div>
                                    Staff Sign In
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="info-map-section" data-navbar-theme="light">
            <div class="container">
                <div class="row align-items-center justify-content-between">
                    <div class="col-lg-5 mb-5 mb-lg-0">
                        <h2 class="section-title mb-3 text-start typewriter typewriter-location"><span class="visually-hidden">LOCATION</span></h2>
                        <p class="text-muted mb-4 landing-section-lead">
                            Servitech Institute Asia is the institutional context for this TALA portal. Use the map link for location guidance.
                        </p>
                        <a href="https://www.google.com/maps?cid=781880921815418296&g_mp=CiVnb29nbGUubWFwcy5wbGFjZXMudjEuUGxhY2VzLkdldFBsYWNlEAMYASAF&hl=en&gl=PH&source=embed" target="_blank" rel="noopener noreferrer" class="btn btn-black-action py-2 px-4">
                            <div class="btn-blur" aria-hidden="true">
                                <span></span>
                                <span></span>
                                <span></span>
                                <span></span>
                                <span></span>
                                <span></span>
                            </div>
                            Open in Google Maps
                        </a>
                    </div>
                    <div class="col-lg-6">
                        <div class="map-box">
                            <iframe title="Servitech Institute Asia map" src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3865.5801177213743!2d121.02881261016364!3d14.335805183447881!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3397d7a36f29214b%3A0xad9cc8e497685b8!2sServitech%20Institute%20Asia%2C%20Inc.!5e0!3m2!1sen!2sph!4v1782779440549!5m2!1sen!2sph" width="100%" height="380" class="map-frame" allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="about-section" id="about-us" data-navbar-theme="light">
            <div class="container text-center">
                <h2 class="section-title mb-5 typewriter typewriter-about"><span class="visually-hidden">ABOUT US</span></h2>

                <div class="row">
                    <div class="col-lg-8 mx-auto">
                        <div class="accordion accordion-custom" id="aboutAccordion">
                            <div class="accordion-item img-mission">
                                <div class="card-blur" aria-hidden="true">
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                </div>
                                <h3 class="accordion-header" id="headingMission">
                                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseMission" aria-expanded="true" aria-controls="collapseMission">
                                        OUR MISSION
                                    </button>
                                </h3>
                                <div id="collapseMission" class="accordion-collapse collapse show" aria-labelledby="headingMission" data-bs-parent="#aboutAccordion">
                                    <div class="accordion-body">
                                        Servitech Institute Asia (SIA) aims to equip each individual with a specialized set of conceptual and practical skills that can support competitive, professional, innovative, and applicable work.
                                    </div>
                                </div>
                            </div>

                            <div class="accordion-item img-vision">
                                <div class="card-blur" aria-hidden="true">
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                </div>
                                <h3 class="accordion-header" id="headingVision">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseVision" aria-expanded="false" aria-controls="collapseVision">
                                        OUR VISION
                                    </button>
                                </h3>
                                <div id="collapseVision" class="accordion-collapse collapse" aria-labelledby="headingVision" data-bs-parent="#aboutAccordion">
                                    <div class="accordion-body">
                                        SIA serves students through a balanced curriculum that develops ethical, innovative thinkers prepared for positive societal and professional contribution.
                                    </div>
                                </div>
                            </div>

                            <div class="accordion-item img-history">
                                <div class="card-blur" aria-hidden="true">
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                </div>
                                <h3 class="accordion-header" id="headingHistory">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseHistory" aria-expanded="false" aria-controls="collapseHistory">
                                        OUR HISTORY
                                    </button>
                                </h3>
                                <div id="collapseHistory" class="accordion-collapse collapse" aria-labelledby="headingHistory" data-bs-parent="#aboutAccordion">
                                    <div class="accordion-body">
                                        TALA supports the school's move from fragmented academic and administrative processes toward one role-scoped source of truth for admissions, records, finance, scheduling, grades, and audit.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="faq-section" id="faq" data-navbar-theme="light">
            <div class="container text-center">
                <h2 class="section-title mb-5 typewriter typewriter-faq"><span class="visually-hidden">FAQ</span></h2>
                <div class="row">
                    <div class="col-lg-8 mx-auto">
                        <div class="accordion accordion-custom" id="faqAccordion">
                            <div class="accordion-item img-faq-1">
                                <div class="card-blur" aria-hidden="true">
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                </div>
                                <h3 class="accordion-header" id="headingFaqOne">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFaqOne" aria-expanded="false" aria-controls="collapseFaqOne">
                                        HOW DO I APPLY FOR ADMISSION?
                                    </button>
                                </h3>
                                <div id="collapseFaqOne" class="accordion-collapse collapse" aria-labelledby="headingFaqOne" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        Use <a class="link-light fw-bold" href="{{ route('filament.applicant.auth.register') }}">Apply Online</a> to create an applicant account. The Applicant Workspace guides draft application, checklist, and allowed evidence steps.
                                    </div>
                                </div>
                            </div>

                            <div class="accordion-item img-faq-2">
                                <div class="card-blur" aria-hidden="true">
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                </div>
                                <h3 class="accordion-header" id="headingFaqTwo">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFaqTwo" aria-expanded="false" aria-controls="collapseFaqTwo">
                                        WHAT WORKSPACES CAN I ACCESS?
                                    </button>
                                </h3>
                                <div id="collapseFaqTwo" class="accordion-collapse collapse" aria-labelledby="headingFaqTwo" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        Applicants use the Applicant Workspace before handover. Students use Student Hub after official activation. Staff users use the Staff Workspace according to assigned role and authorization.
                                    </div>
                                </div>
                            </div>

                            <div class="accordion-item img-faq-3">
                                <div class="card-blur" aria-hidden="true">
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                </div>
                                <h3 class="accordion-header" id="headingFaqThree">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFaqThree" aria-expanded="false" aria-controls="collapseFaqThree">
                                        CAN STUDENTS OR STAFF REGISTER HERE?
                                    </button>
                                </h3>
                                <div id="collapseFaqThree" class="accordion-collapse collapse" aria-labelledby="headingFaqThree" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        No. Public self-registration is only for applicants. Student and staff accounts are activated through official school processes.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="footer-section">
        <div class="container">
            <div class="row pt-4">
                <div class="col-lg-7 mb-4 mb-lg-0">
                    <a class="footer-brand mb-3 d-inline-flex align-items-center text-decoration-none" href="{{ url('/') }}">
                        <img src="{{ asset('landing/images/talalogo.png') }}" alt="TALA" class="footer-logo">
                        <span>TALA</span>
                    </a>
                    <p class="footer-desc mb-4">Timetable-Integrated Academic Lifecycle Administration for Servitech Institute Asia.</p>
                </div>
                <div class="col-lg-5">
                    <div class="d-flex flex-wrap justify-content-lg-end gap-3">
                        <a class="footer-link" href="{{ url('/#login') }}">Login</a>
                        <a class="footer-link" href="{{ route('filament.applicant.auth.register') }}">Apply Online</a>
                        <a class="footer-link" href="{{ url('/#about-us') }}">About Us</a>
                        <a class="footer-link" href="{{ url('/#faq') }}">FAQ</a>
                    </div>
                </div>
            </div>
            <div class="row mt-5 pt-4 footer-divider">
                <div class="col-12 text-center text-muted">
                    <p class="mb-0">&copy; {{ date('Y') }} Servitech Institute Asia (SIA)</p>
                </div>
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

    <button class="btn-scroll-top" aria-label="Scroll to top">
        <div class="btn-blur" aria-hidden="true">
            <span></span>
            <span></span>
            <span></span>
            <span></span>
            <span></span>
            <span></span>
        </div>
        <i class="bi bi-arrow-up"></i>
    </button>
@endsection
