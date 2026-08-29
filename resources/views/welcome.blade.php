@extends('layouts.landing-bootstrap', ['title' => 'TALA'])

@section('content')
    <a class="tala-skip-link" href="#main-content">
        <span>Skip to main content</span>
        <svg class="tala-skip-link__icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </a>

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
                <img src="{{ asset('images/brand/servitech-crest.webp') }}" alt="Servitech Institute Asia" class="landing-crest" width="48" height="48">
                <img src="{{ asset('talalogo.png') }}" alt="" class="landing-brand-logo">
                <span data-navbar-contrast-target>TALA</span>
            </a>

            <div class="collapse navbar-collapse justify-content-center" id="navbarNav">
                <ul class="navbar-nav gap-lg-3 pt-3 pt-lg-0">
                    <li class="nav-item"><a class="nav-link" data-navbar-contrast-target href="#top">Admissions</a></li>
                    <li class="nav-item"><a class="nav-link" data-navbar-contrast-target href="#programs">Programs</a></li>
                    <li class="nav-item"><a class="nav-link" data-navbar-contrast-target href="#location">Visit</a></li>
                    <li class="nav-item"><a class="nav-link" data-navbar-contrast-target href="#faq">FAQ</a></li>
                </ul>
            </div>

            <div class="dropdown public-sign-in">
                <button class="nav-link dropdown-toggle" type="button" data-navbar-contrast-target data-bs-toggle="dropdown" aria-expanded="false">
                    Sign in
                </button>
                <ul class="dropdown-menu dropdown-menu-end" aria-label="Choose a sign-in workspace">
                    <li><a class="dropdown-item" href="{{ route('filament.applicant.auth.login') }}">Applicant sign in</a></li>
                    <li><a class="dropdown-item" href="{{ route('filament.student.auth.login') }}">Student sign in</a></li>
                    <li><a class="dropdown-item" href="{{ route('filament.admin.auth.login') }}">Staff sign in</a></li>
                </ul>
            </div>

            <button class="navbar-toggler border-0" type="button" data-navbar-contrast-target data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Open navigation menu">
                <span class="navbar-toggler-icon"></span>
            </button>
        </div>
    </nav>

    <main id="main-content" tabindex="-1">
        <section class="hero-section" id="top" data-navbar-contrast-surface="dark" aria-labelledby="hero-title">
            <div class="container">
                <div class="row align-items-center gx-lg-5 gy-4">
                    <div class="col-lg-7">
                        <p class="hero-kicker mb-3">Admissions and academic services</p>
                        @if (session('status'))
                            <div class="alert alert-light" role="status">{{ session('status') }}</div>
                        @endif
                        <h1 class="display-headline" id="hero-title">A clearer way through every step of your Servitech journey.</h1>
                        <p class="hero-lead">TALA brings applications, enrollment, schedules, records, and accounts into one guided service.</p>
                        <div class="hero-actions d-flex flex-wrap gap-3 mt-4">
                            @if ($applicantEntryReady)
                                <a class="btn btn-primary-custom" href="{{ route('filament.applicant.auth.register') }}">
                                    Apply
                                    <x-filament::icon icon="heroicon-o-arrow-right" class="tala-public-icon ms-2" aria-hidden="true" />
                                </a>
                            @elseif ($admissionsOpen)
                                <span class="hero-entry-state">Applicant registration is temporarily unavailable</span>
                            @elseif ($admissionState === 'Closed')
                                <span class="hero-entry-state">Applications are currently closed</span>
                            @else
                                <span class="hero-entry-state">{{ $admissionState === 'Upcoming' ? 'Applications have not opened yet' : 'Apply is currently unavailable' }}</span>
                            @endif
                            <a class="btn btn-secondary-custom" href="{{ route('filament.applicant.auth.login') }}">Applicant sign in</a>
                        </div>
                        <p class="hero-availability mt-3 mb-0">Existing accounts can still sign in. Creating an account does not create an application.</p>
                    </div>

                    <div class="col-lg-5">
                        <aside class="admission-status" data-navbar-contrast-surface="theme" aria-labelledby="admission-status-title">
                            <p class="section-kicker">Current admissions status</p>
                            <h2 id="admission-status-title">
                                @if ($admissionsOpen)
                                    {{ $applicantEntryReady ? 'Applications are open' : 'Registration is temporarily unavailable' }}
                                @elseif ($admissionState === 'Closed')
                                    Application intake is closed
                                @elseif ($admissionState === 'Upcoming')
                                    Applications have not opened yet
                                @else
                                    Admission availability is unconfirmed
                                @endif
                            </h2>
                            @if ($admissionCycle)
                                <p class="mb-2"><strong>{{ $admissionCycle['label'] }}</strong> ({{ $admissionCycle['code'] }})</p>
                                <p class="mb-2">{{ $admissionCycle['term'] ?? 'Target term unavailable' }} · {{ implode(' and ', $admissionCycle['paths']) ?: 'Paths unavailable' }}</p>
                                <p>{{ $admissionCycle['is_open'] ? 'Open until' : 'Opens' }} {{ $admissionCycle['is_open'] ? $admissionCycle['closes_at'] : $admissionCycle['opens_at'] }}.</p>
                            @endif
                            @if ($admissionState === 'Unavailable')
                                <p>Admission availability could not be checked. Apply is unavailable until the Registrar’s source can be read.</p>
                            @elseif ($admissionState === 'Missing')
                                <p>No published admission cycle is available. Apply is unavailable; existing accounts can still sign in.</p>
                            @elseif ($admissionState === 'Closed')
                                <p>Existing Applicants can continue their permitted application tasks. The next intake will appear when published by the Registrar.</p>
                            @elseif ($admissionState === 'Upcoming')
                                <p>This published intake has not opened. Existing accounts can still sign in.</p>
                            @endif
                            <p class="admission-source mb-0">Source: Registrar’s Admission Cycle · Checked {{ $asOf }} (Asia/Manila).</p>
                            <button type="button" class="hero-support-link" data-bs-toggle="modal" data-bs-target="#supportModal">Contact the school for admission guidance</button>
                        </aside>
                    </div>
                </div>
            </div>
        </section>

        <section class="features-section section-block" id="login" data-navbar-contrast-surface="theme" aria-labelledby="login-title">
            <div class="container">
                <div class="section-heading">
                    <h2 class="section-title" id="login-title">Sign in to your workspace</h2>
                    <p class="section-lead">Choose the context for your school task. Signing in shows only the workspaces authorized for your account.</p>
                </div>
                <div class="row g-4 context-choices">
                    <div class="col-md-4">
                        <h3>Applicant Workspace</h3>
                        <p>Create and verify your Applicant account. Existing Applicants can continue their application.</p>
                        <a class="text-link" href="{{ route('filament.applicant.auth.login') }}">Applicant sign in</a>
                    </div>
                    <div class="col-md-4">
                        <h3>Student Hub</h3>
                        <p>View enrollment, schedules, finance, and academic records.</p>
                        <a class="text-link" href="{{ route('filament.student.auth.login') }}">Student sign in</a>
                    </div>
                    <div class="col-md-4">
                        <h3>Staff Workspace</h3>
                        <p>Manage verified school operations according to your role.</p>
                        <a class="text-link" href="{{ route('filament.admin.auth.login') }}">Staff sign in</a>
                    </div>
                </div>
                <div class="journey-heading">
                    <h2 class="section-title" id="journey-title">One connected learner journey</h2>
                    <p class="section-lead">Each stage keeps its school decisions and next steps clear.</p>
                </div>
                <ol class="learner-journey row g-4 list-unstyled mb-0" aria-labelledby="journey-title">
                    <li class="col-sm-6 col-lg-3"><h3>Apply</h3><p>Create an account, complete an application, and follow its review.</p></li>
                    <li class="col-sm-6 col-lg-3"><h3>Enroll</h3><p>Follow registration, account readiness, and official enrollment.</p></li>
                    <li class="col-sm-6 col-lg-3"><h3>Study</h3><p>Use your published schedule and released academic records.</p></li>
                    <li class="col-sm-6 col-lg-3"><h3>Complete</h3><p>Follow completion decisions and the school’s records process.</p></li>
                </ol>
            </div>
        </section>

        <section class="section-block" id="programs" data-navbar-contrast-surface="theme" aria-labelledby="programs-title">
            <div class="container">
                <div class="row g-4 gx-lg-5">
                    <div class="col-lg-4">
                        <p class="section-kicker">Program paths</p>
                        <h2 class="section-title" id="programs-title">See where your Servitech journey can begin.</h2>
                        <p class="section-lead">Programs and admission availability come from the school’s published records. An active Program does not automatically accept new applications.</p>
                    </div>
                    <div class="col-lg-8">
                        <ul class="program-list list-unstyled mb-0">
                            @forelse ($programs as $program)
                                <li class="program-row">
                                    <span class="program-code">{{ $program->code }}</span>
                                    <h3>{{ $program->name }}</h3>
                                    <p>{{ in_array($program->id, $acceptingProgramIds, true) ? 'Accepting applications in the current intake' : 'No current application intake confirmed' }}</p>
                                </li>
                            @empty
                                <li>{{ in_array('programs', $unavailable, true) ? 'Program records are temporarily unavailable. Contact the Registrar through Support for guidance.' : 'No active Programs are currently listed. Contact the Registrar through Support for guidance.' }}</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        <section class="section-block" id="notices" data-navbar-contrast-surface="theme" aria-labelledby="notices-title">
            <div class="container">
                <h2 class="section-title text-start" id="notices-title">Current notices</h2>
                <div class="row g-3">
                    @forelse ($notices as $notice)
                        <div class="col-md-6">
                            <article class="public-content-card h-100">
                                <h3>{{ $notice->title }}</h3>
                                <p class="public-content-message">{{ $notice->message }}</p>
                                @if ($notice->link_url)
                                    <a href="{{ $notice->link_url }}" target="_blank" rel="noopener noreferrer">{{ $notice->link_label }} <span class="visually-hidden">(opens in a new tab)</span></a>
                                @endif
                                <p class="public-content-meta">System Administration · Version {{ $notice->version }} · Published {{ $notice->published_at?->timezone('Asia/Manila')->format('M j, Y') }}</p>
                            </article>
                        </div>
                    @empty
                        <p>{{ in_array('notices', $unavailable, true) ? 'Notices are temporarily unavailable. System Administration owns this content; use Support for assistance. Sign-in remains available.' : 'There are no current public notices. Existing-account sign-in remains available.' }}</p>
                    @endforelse
                </div>
            </div>
        </section>

        <section class="faq-section section-block" id="faq" data-navbar-contrast-surface="theme" aria-labelledby="faq-title">
            <div class="container">
                <div class="section-heading text-center">
                    <p class="section-kicker">Public guidance</p>
                    <h2 class="section-title" id="faq-title">Frequently asked questions</h2>
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
                                    <x-filament::icon icon="heroicon-o-information-circle" class="tala-public-icon" aria-hidden="true" />
                                    <div>
                                        <strong>{{ in_array('faqEntries', $unavailable, true) ? 'FAQs are temporarily unavailable.' : 'No public FAQs are available yet.' }}</strong>
                                        <p class="mb-0">Use the workspace links above or contact the responsible school office for help.</p>
                                    </div>
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <section class="institution-section section-block" id="location" data-navbar-contrast-surface="theme" aria-labelledby="location-title">
            <div class="container">
                <div class="institution-card">
                    <div>
                        <p class="section-kicker">Institution</p>
                        <h2 class="section-title text-start" id="location-title">Servitech Institute Asia</h2>
                        <p class="section-lead text-start mb-0">
                            TALA is the school information portal of Servitech Institute Asia. Use the external map for campus location guidance.
                        </p>
                    </div>
                    @if ($officialReferences['map'])
                        <a href="{{ $officialReferences['map'] }}" target="_blank" rel="noopener noreferrer" class="btn btn-black-action flex-shrink-0">
                            Open in Google Maps
                            <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="tala-public-icon ms-2" aria-hidden="true" />
                            <span class="visually-hidden">(opens in a new tab)</span>
                        </a>
                    @else
                        <a href="{{ route('home', ['modal' => 'support']) }}" class="text-link">Contact the school for location guidance</a>
                    @endif
                </div>
            </div>
        </section>
    </main>

    <footer class="footer-section" data-navbar-contrast-surface="dark">
        <div class="container">
            <div class="row align-items-start g-4">
                <div class="col-lg-7">
                    <a class="footer-brand d-inline-flex align-items-center text-decoration-none" href="{{ url('/') }}">
                        <img src="{{ asset('images/brand/servitech-crest.webp') }}" alt="Servitech Institute Asia" class="landing-crest" width="48" height="48">
                        <img src="{{ asset('talalogo.png') }}" alt="" class="footer-logo">
                        <span>TALA</span>
                    </a>
                    <p class="footer-desc mt-3 mb-0">Tertiary Academic Lifecycle Administration for Servitech Institute Asia.</p>
                </div>
                <div class="col-lg-5">
                    <nav class="d-flex flex-wrap justify-content-lg-end gap-3" aria-label="Footer navigation">
                        <a class="footer-link" href="{{ url('/#login') }}">Sign in</a>
                        @if ($applicantEntryReady)
                            <a class="footer-link" href="{{ route('filament.applicant.auth.register') }}">Create Applicant account</a>
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
                                <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="tala-public-icon ms-2" aria-hidden="true" />
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
        <x-filament::icon icon="heroicon-o-arrow-up" class="tala-public-icon" aria-hidden="true" />
    </button>
@endsection
