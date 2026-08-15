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
                    <li class="nav-item dropdown">
                        <button class="nav-link dropdown-toggle" type="button" data-navbar-contrast-target data-bs-toggle="dropdown" aria-expanded="false">
                            LOGIN
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-label="Choose a sign-in workspace">
                            <li><a class="dropdown-item" href="{{ route('filament.applicant.auth.login') }}">Applicant Login</a></li>
                            <li><a class="dropdown-item" href="{{ route('filament.student.auth.login') }}">Student Login</a></li>
                            <li><a class="dropdown-item" href="{{ route('filament.admin.auth.login') }}">Staff Login</a></li>
                        </ul>
                    </li>
                    @if ($applicantEntryReady)
                        <li class="nav-item"><a class="nav-link" data-navbar-contrast-target href="{{ route('filament.applicant.auth.register') }}">CREATE ACCOUNT</a></li>
                    @endif
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
                            Use the appropriate secure workspace to create or access your TALA account and continue an authorized school task.
                        </p>
                        <div class="d-flex flex-column flex-sm-row gap-3 mt-4">
                            @if ($applicantEntryReady)
                                <a class="btn btn-primary-custom" href="{{ route('filament.applicant.auth.register') }}">
                                    Create Applicant Account
                                    <i class="bi bi-arrow-right ms-2" aria-hidden="true"></i>
                                </a>
                            @elseif ($admissionsOpen)
                                <span class="btn btn-primary-custom disabled" aria-disabled="true">Applicant registration is temporarily unavailable</span>
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
                                        <span>Create and verify your Applicant account.</span>
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
                            <p>Create a minimal account, verify your email address, or sign in to your existing Applicant Workspace.</p>
                            <ul class="workspace-capabilities">
                                <li>Create one Applicant credential</li>
                                <li>Verify the registered email address</li>
                                <li>Open the Applicant Workspace</li>
                            </ul>
                            <div class="workspace-actions">
                                @if ($applicantEntryReady)
                                    <a class="btn btn-black-action" href="{{ route('filament.applicant.auth.register') }}">Create Applicant Account</a>
                                @elseif ($admissionsOpen)
                                    <span class="btn btn-black-action disabled" aria-disabled="true">Registration Temporarily Unavailable</span>
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

        <section class="institution-section section-block" data-navbar-contrast-surface="theme" aria-labelledby="location-title">
            <div class="container">
                <div class="institution-card">
                    <div>
                        <p class="section-kicker">Institution</p>
                        <h2 class="section-title text-start" id="location-title">SERVITECH INSTITUTE ASIA</h2>
                        <p class="section-lead text-start mb-0">
                            TALA is the school information portal of Servitech Institute Asia. Use the external map for campus location guidance.
                        </p>
                    </div>
                    @if ($officialReferences['map'])
                        <a href="{{ $officialReferences['map'] }}" target="_blank" rel="noopener noreferrer" class="btn btn-black-action flex-shrink-0">
                            Open in Google Maps
                            <i class="bi bi-box-arrow-up-right ms-2" aria-hidden="true"></i>
                        </a>
                    @endif
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
                        @if ($applicantEntryReady)
                            <a class="footer-link" href="{{ route('filament.applicant.auth.register') }}">Create Applicant Account</a>
                        @endif
                        <button class="footer-link footer-link-button" type="button" data-bs-toggle="modal" data-bs-target="#supportModal">Support</button>
                        <button class="footer-link footer-link-button" type="button" data-bs-toggle="modal" data-bs-target="#privacyModal">Privacy</button>
                        <button class="footer-link footer-link-button" type="button" data-bs-toggle="modal" data-bs-target="#accessibilityModal">Accessibility</button>
                        <a class="footer-link" href="{{ url('/#faq') }}">FAQ</a>
                    </nav>
                </div>
            </div>
            <div class="footer-divider mt-5 pt-4 text-center">
                <p class="mb-0">&copy; {{ date('Y') }} Servitech Institute Asia (SIA)</p>
            </div>
        </div>
    </footer>

    <div class="modal fade tala-info-modal" id="supportModal" tabindex="-1" aria-labelledby="supportModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h3" id="supportModalTitle">Support</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close support information"></button>
                </div>
                <div class="modal-body">
                    <p>If you need help with TALA account access or verification, use either public Servitech contact below.</p>
                    <div class="modal-action-list">
                        @if ($officialReferences['support'])
                            <a class="btn btn-black-action" href="{{ $officialReferences['support'] }}" target="_blank" rel="noopener noreferrer">
                                Open Servitech Facebook
                                <i class="bi bi-box-arrow-up-right ms-2" aria-hidden="true"></i>
                            </a>
                        @endif
                        <a class="text-link" href="{{ $officialReferences['support_phone_uri'] }}">Call {{ $officialReferences['support_phone'] }}</a>
                    </div>
                    <p class="modal-note mb-0">TALA does not claim a response time or monitoring schedule for these public contact paths.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade tala-info-modal" id="privacyModal" tabindex="-1" aria-labelledby="privacyModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h3" id="privacyModalTitle">Applicant Privacy Notice</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close privacy notice"></button>
                </div>
                <div class="modal-body privacy-notice-copy">
                    <p>TALA collects only the information needed to create and secure an Applicant account at this stage: your email address, a protected password representation, your Privacy Notice acknowledgement, and security and email-verification events.</p>
                    <h3 class="h5">How the information is used</h3>
                    <ul>
                        <li>To create one Applicant credential and prevent duplicate accounts.</li>
                        <li>To verify email ownership, authorize workspace access, and support account recovery.</li>
                        <li>To keep security and accountability evidence required for the account journey.</li>
                    </ul>
                    <p>Account creation does not create an Application or Student record. Authorized TALA users and services may access account information only for their permitted tasks. TALA does not display your password and does not ask for application documents during registration.</p>
                    <h3 class="h5">Questions and requests</h3>
                    <p class="mb-0">Use the Support information on this page for privacy questions or requests. Applicable institutional and legal review still governs retention, correction, access, and lawful disposal.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade tala-info-modal" id="accessibilityModal" tabindex="-1" aria-labelledby="accessibilityModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h3" id="accessibilityModalTitle">Accessibility</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close accessibility information"></button>
                </div>
                <div class="modal-body">
                    <p>TALA is designed so this entry journey can be completed with a keyboard and without relying on color alone.</p>
                    <ul>
                        <li>A skip link, semantic landmarks, visible focus, labelled controls, and keyboard-operable menus and dialogs support navigation.</li>
                        <li>Registration fields support paste, autofill, password managers, associated validation, and clear recovery actions.</li>
                        <li>Public and authentication content is designed for mobile widths, 200% zoom and reflow, high-contrast preferences, and reduced motion.</li>
                    </ul>
                    <p class="mb-0">If you encounter an access barrier, use the Support contact paths on this page and describe the page, task, and problem.</p>
                </div>
            </div>
        </div>
    </div>

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
