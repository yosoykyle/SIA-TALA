@extends('layouts.landing-bootstrap', ['title' => 'Servitech Institute Asia Inc. — Powered by TALA'])

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
            <a class="navbar-brand landing-brand" href="{{ url('/') }}" aria-label="Servitech Institute Asia Inc. Powered by TALA home">
                <img src="{{ asset('images/brand/servitech-crest-outlined-192.webp') }}" alt="" aria-hidden="true" class="tala-crest-outlined landing-brand-crest" width="44" height="44" decoding="async">
                <span class="landing-brand-text" data-navbar-contrast-target>
                    <span class="landing-brand-name">Servitech Institute Asia</span>
                    <span class="landing-attribution">
                        <img src="{{ asset('talalogo.png') }}" alt="" aria-hidden="true" class="landing-attribution-mark" width="14" height="14">
                        <span>Powered by TALA</span>
                    </span>
                </span>
            </a>

            <div class="collapse navbar-collapse justify-content-center" id="navbarNav">
                <ul class="navbar-nav gap-lg-1 pt-3 pt-lg-0">
                    <li class="nav-item"><a class="nav-link" data-navbar-contrast-target href="#top">Home</a></li>
                    <li class="nav-item"><a class="nav-link" data-navbar-contrast-target href="#programs">Programs</a></li>
                    @if ($notices->isNotEmpty() || in_array('notices', $unavailable, true))
                        <li class="nav-item"><a class="nav-link" data-navbar-contrast-target href="#notices">Announcements</a></li>
                    @endif
                    <li class="nav-item"><a class="nav-link" data-navbar-contrast-target href="#location">Visit</a></li>
                    <li class="nav-item"><a class="nav-link" data-navbar-contrast-target href="#faq">FAQ</a></li>
                </ul>
            </div>

            <a class="btn btn-secondary-custom public-sign-in" href="{{ route('filament.admin.auth.login') }}">
                <x-filament::icon icon="heroicon-o-arrow-right-end-on-rectangle" class="tala-public-icon" aria-hidden="true" />
                <span>Sign in</span>
            </a>

            <button class="navbar-toggler" type="button" data-navbar-contrast-target data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Open navigation menu">
                <span class="navbar-toggler-icon"></span>
            </button>
        </div>
    </nav>

    <main id="main-content" tabindex="-1">
        <section class="hero-section school-masthead" id="top" data-navbar-contrast-surface="dark" data-hero-motion aria-labelledby="hero-title">
            <div class="hero-ambient" aria-hidden="true">
                <span class="hero-ambient__light hero-ambient__light--green"></span>
                <span class="hero-ambient__light hero-ambient__light--blue"></span>
                <span class="hero-ambient__light hero-ambient__light--gold"></span>
                <span class="hero-ambient__light hero-ambient__light--crimson"></span>
                <span class="hero-ambient__arc"></span>
            </div>

            <div class="container">
                @if (session('status'))
                    <div class="alert alert-light" role="status">{{ session('status') }}</div>
                @endif

                <div class="school-identity">
                    <img class="school-identity-crest tala-crest-outlined" src="{{ asset('images/brand/servitech-crest-outlined-320.webp') }}" srcset="{{ asset('images/brand/servitech-crest-outlined-320.webp') }} 1x, {{ asset('images/brand/servitech-crest-outlined-640.webp') }} 2x" width="320" height="320" alt="" aria-hidden="true" fetchpriority="high" decoding="async">
                    <div class="school-identity-copy">
                        <h1 class="display-headline" id="hero-title">Servitech<br>Institute Asia Inc.</h1>
                        <p class="hero-lead">Explore the school’s programs, find admission information, and plan your visit to Servitech.</p>
                        <div class="hero-actions">
                            <a class="btn btn-primary-custom" href="#programs">
                                <span>Explore programs</span>
                                <x-filament::icon icon="heroicon-o-arrow-down" class="tala-public-icon" aria-hidden="true" />
                            </a>
                            <a class="btn btn-hero-quiet" href="#location">
                                <x-filament::icon icon="heroicon-o-map-pin" class="tala-public-icon" aria-hidden="true" />
                                <span>Plan your visit</span>
                            </a>
                        </div>
                    </div>
                </div>

                @php
                    $noticesUnavailable = in_array('notices', $unavailable, true);
                    $updateCount = 1 + ($notices->isNotEmpty() ? $notices->count() : ($noticesUnavailable ? 1 : 0));
                @endphp
                <div class="hero-updates" @if ($notices->isNotEmpty() || $noticesUnavailable) id="notices" @endif role="region" aria-roledescription="carousel" aria-label="Admissions and school announcements" data-hero-updates>
                    <div class="hero-updates__stack" data-hero-updates-stack aria-live="polite">
                        <article class="update-card update-card--admissions admission-status is-active" role="group" aria-roledescription="slide" aria-label="Admissions, 1 of {{ $updateCount }}" data-update-slide>
                            <p class="update-card__type">
                                <x-filament::icon icon="heroicon-o-academic-cap" class="tala-public-icon" aria-hidden="true" />
                                <span>Admissions</span>
                            </p>
                            <div class="admission-grid">
                                <div class="admission-intake">
                                    <h2 id="admission-status-title">
                                        @if ($admissionsOpen)
                                            {{ $applicantEntryReady ? 'Applications are open' : 'Registration is temporarily unavailable' }}
                                        @elseif ($admissionState === 'Closed')
                                            Applications are closed
                                        @elseif ($admissionState === 'Upcoming')
                                            Applications have not opened yet
                                        @elseif ($admissionState === 'Unavailable')
                                            Admissions information is temporarily unavailable
                                        @else
                                            Ask Admissions about applying
                                        @endif
                                    </h2>
                                    @if ($admissionCycle)
                                        <p class="admission-paths">{{ implode(' and ', $admissionCycle['paths']) }}</p>
                                    @endif
                                    @if ($admissionState === 'Unavailable')
                                        <p>Contact Admissions for current availability.</p>
                                    @elseif ($admissionState === 'Missing')
                                        <p>Contact Admissions about applying. Existing accounts can still sign in.</p>
                                    @elseif ($admissionState === 'Closed')
                                        <p>Sign in to continue an existing application, or ask Admissions about the next intake.</p>
                                    @elseif ($admissionState === 'Upcoming')
                                        <p>Existing accounts can still sign in.</p>
                                    @endif
                                </div>

                                @if ($admissionCycle)
                                    <dl class="admission-deadline">
                                        <dt>
                                            <x-filament::icon icon="heroicon-o-calendar-days" class="tala-public-icon" aria-hidden="true" />
                                            {{ $admissionCycle['is_open'] ? 'Applications close' : 'Applications open' }}
                                        </dt>
                                        <dd>{{ $admissionCycle['is_open'] ? $admissionCycle['closes_at'] : $admissionCycle['opens_at'] }} <span>Asia/Manila time</span></dd>
                                    </dl>
                                @endif

                                <div class="admission-entry">
                                    @if ($applicantEntryReady)
                                        <a class="btn btn-hero-solid" href="{{ route('filament.applicant.auth.register') }}">
                                            <x-filament::icon icon="heroicon-o-user-plus" class="tala-public-icon" aria-hidden="true" />
                                            <span>Apply</span>
                                        </a>
                                        <p class="hero-availability">Create an Applicant account first.</p>
                                    @endif
                                    <div class="admission-links">
                                        <a class="hero-inline-link" href="#application-status">
                                            <x-filament::icon icon="heroicon-o-magnifying-glass" class="tala-public-icon" aria-hidden="true" />
                                            <span>Already applied? Check status</span>
                                        </a>
                                        <button type="button" class="hero-inline-link" data-bs-toggle="modal" data-bs-target="#supportModal">
                                            <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="tala-public-icon" aria-hidden="true" />
                                            <span>Contact Admissions</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </article>
                        @foreach ($notices as $notice)
                            <article class="update-card update-card--notice" role="group" aria-roledescription="slide" aria-label="School announcement, {{ $loop->iteration + 1 }} of {{ $updateCount }}" data-update-slide>
                                <p class="update-card__type">
                                    <x-filament::icon icon="heroicon-o-megaphone" class="tala-public-icon" aria-hidden="true" />
                                    <span>School announcement</span>
                                    @if ($notice->published_at)
                                        <span class="update-card__date">Published {{ $notice->published_at->timezone('Asia/Manila')->format('M j, Y') }}</span>
                                    @endif
                                </p>
                                <h2 class="update-card__title">{{ $notice->title }}</h2>
                                <p class="update-card__message">{{ $notice->message }}</p>
                                @if ($notice->link_url)
                                    <a class="hero-inline-link" href="{{ $notice->link_url }}" target="_blank" rel="noopener noreferrer">
                                        <span>{{ $notice->link_label }}</span>
                                        <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="tala-public-icon" aria-hidden="true" />
                                        <span class="visually-hidden">(opens in a new tab)</span>
                                    </a>
                                @endif
                            </article>
                        @endforeach
                        @if ($notices->isEmpty() && $noticesUnavailable)
                            <article class="update-card update-card--notice" role="group" aria-roledescription="slide" aria-label="School announcements, 2 of {{ $updateCount }}" data-update-slide>
                                <p class="update-card__type">
                                    <x-filament::icon icon="heroicon-o-megaphone" class="tala-public-icon" aria-hidden="true" />
                                    <span>School announcements</span>
                                </p>
                                <p class="update-card__message mb-0" role="status">Announcements are temporarily unavailable. <button class="hero-inline-link hero-inline-link--inline" type="button" data-bs-toggle="modal" data-bs-target="#supportModal">Contact Support</button> for current school guidance.</p>
                            </article>
                        @endif
                    </div>

                    <div class="hero-updates__controls">
                        @if ($updateCount > 1)
                            <div class="hero-updates__pager">
                                <button type="button" class="hero-round-control" data-update-previous aria-label="Previous update">
                                    <x-filament::icon icon="heroicon-o-chevron-left" class="tala-public-icon" aria-hidden="true" />
                                </button>
                                <p class="hero-updates__position mb-0" data-update-position>1 of {{ $updateCount }}</p>
                                <button type="button" class="hero-round-control" data-update-next aria-label="Next update">
                                    <x-filament::icon icon="heroicon-o-chevron-right" class="tala-public-icon" aria-hidden="true" />
                                </button>
                            </div>
                        @endif
                        <button type="button" class="hero-motion-toggle" aria-pressed="false" data-hero-motion-toggle>
                            <x-filament::icon icon="heroicon-o-pause" class="tala-public-icon hero-motion-toggle__pause" aria-hidden="true" />
                            <x-filament::icon icon="heroicon-o-play" class="tala-public-icon hero-motion-toggle__play" aria-hidden="true" />
                            <span data-hero-motion-label>Pause motion</span>
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <section class="section-block programs-section" id="programs" data-navbar-contrast-surface="theme" aria-labelledby="programs-title">
            <div class="container">
                <div class="catalog-heading">
                    <div>
                        <h2 class="section-title section-title--display" id="programs-title">Explore programs</h2>
                        <p class="section-lead">Compare program length and whether applications are open.</p>
                        @if ($programs->isNotEmpty())
                            @php
                                $acceptingProgramCount = $programs->whereIn('id', $acceptingProgramIds)->count();
                            @endphp
                            <p class="catalog-summary">
                                <span><strong>{{ $programs->count() }}</strong> {{ Str::plural('program', $programs->count()) }}</span>
                                <span aria-hidden="true">·</span>
                                <span><strong>{{ $acceptingProgramCount }}</strong> accepting applications</span>
                            </p>
                        @endif
                    </div>
                    <button type="button" class="btn btn-secondary-custom" data-bs-toggle="modal" data-bs-target="#supportModal">
                        <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="tala-public-icon" aria-hidden="true" />
                        <span>Ask about admissions</span>
                    </button>
                </div>
                <ul class="program-list list-unstyled mb-0">
                    @forelse ($programs as $program)
                        @php($isAccepting = in_array($program->id, $acceptingProgramIds, true))
                        <li class="program-row">
                            <x-filament::icon icon="heroicon-o-academic-cap" class="program-mark" aria-hidden="true" />
                            <h3>{{ $program->name }}</h3>
                            <dl class="program-facts">
                                <div class="program-fact">
                                    <dt class="visually-hidden">Length</dt>
                                    <dd class="program-duration">
                                        <x-filament::icon icon="heroicon-o-clock" class="tala-public-icon" aria-hidden="true" />
                                        <span>{{ $program->duration_years }} {{ Str::plural('year', $program->duration_years) }}</span>
                                    </dd>
                                </div>
                                <div class="program-fact">
                                    <dt class="visually-hidden">Admission</dt>
                                    <dd class="program-availability {{ $isAccepting ? 'is-open' : '' }}">
                                        <x-filament::icon :icon="$isAccepting ? 'heroicon-o-check-circle' : 'heroicon-o-chat-bubble-left-right'" class="tala-public-icon" aria-hidden="true" />
                                        <span>{{ $isAccepting ? 'Applications open' : 'Ask Admissions about applying' }}</span>
                                    </dd>
                                </div>
                            </dl>
                        </li>
                    @empty
                        <li class="program-empty">
                            <x-filament::icon icon="heroicon-o-information-circle" class="tala-public-icon" aria-hidden="true" />
                            <span>{{ in_array('programs', $unavailable, true) ? 'Program information is temporarily unavailable. Contact Admissions for guidance.' : 'Contact Admissions for program information.' }}</span>
                        </li>
                    @endforelse
                </ul>
            </div>
        </section>

        <section @class(['faq-section', 'section-block', 'faq-section--compact' => $faqEntries->isEmpty()]) id="faq" data-navbar-contrast-surface="theme" aria-labelledby="faq-title">
            <div class="container">
                @if ($faqEntries->isNotEmpty())
                    <div class="discovery-grid">
                        <div class="discovery-intro">
                            <h2 class="section-title" id="faq-title">Questions before applying?</h2>
                            <p class="section-lead">Read the school’s published answers.</p>
                            <button type="button" class="btn btn-secondary-custom" data-bs-toggle="modal" data-bs-target="#supportModal">
                                <x-filament::icon icon="heroicon-o-lifebuoy" class="tala-public-icon" aria-hidden="true" />
                                <span>Contact Support</span>
                            </button>
                        </div>
                        <div class="discovery-content">
                            <div class="accordion accordion-custom" id="faqAccordion">
                                @foreach ($faqEntries as $entry)
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
                                @endforeach
                            </div>
                        </div>
                    </div>
                @else
                    <div class="faq-help">
                        <x-filament::icon icon="heroicon-o-question-mark-circle" class="faq-help__mark" aria-hidden="true" />
                        <div class="faq-help__copy">
                            <h2 class="faq-help__title" id="faq-title">Questions before applying?</h2>
                            <p class="mb-0" role="status">{{ in_array('faqEntries', $unavailable, true) ? 'FAQs are temporarily unavailable.' : 'No public FAQs are available yet.' }} Admissions can answer questions about programs, requirements and applying.</p>
                        </div>
                        <button type="button" class="btn btn-secondary-custom" data-bs-toggle="modal" data-bs-target="#supportModal">
                            <x-filament::icon icon="heroicon-o-lifebuoy" class="tala-public-icon" aria-hidden="true" />
                            <span>Contact Support</span>
                        </button>
                    </div>
                @endif
            </div>
        </section>

        <section class="institution-section section-block" id="location" data-navbar-contrast-surface="theme" aria-labelledby="location-title">
            <div class="container">
                <div class="visit-layout">
                    <div class="visit-details">
                        <h2 class="section-title" id="location-title">Visit Servitech</h2>
                        <p class="section-lead">Find the school on the map, and contact the school before your visit.</p>
                        <dl class="visit-facts">
                            @if (filled(config('institution.address')))
                                <div class="visit-fact">
                                    <dt><x-filament::icon icon="heroicon-o-map-pin" class="tala-public-icon" aria-hidden="true" /> Address</dt>
                                    <dd><address class="institution-address mb-0">{{ config('institution.address') }}</address></dd>
                                </div>
                            @endif
                            @if ($officialReferences['support_phone'])
                                <div class="visit-fact">
                                    <dt><x-filament::icon icon="heroicon-o-phone" class="tala-public-icon" aria-hidden="true" /> Call</dt>
                                    <dd><a class="text-link" href="{{ $officialReferences['support_phone_uri'] }}">{{ $officialReferences['support_phone'] }}</a></dd>
                                </div>
                            @endif
                        </dl>
                        <div class="visit-actions">
                            @if ($officialReferences['map'])
                                <a href="{{ $officialReferences['map'] }}" target="_blank" rel="noopener noreferrer" class="btn btn-secondary-custom">
                                    <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="tala-public-icon" aria-hidden="true" />
                                    <span>Open in Google Maps</span>
                                    <span class="visually-hidden">(opens in a new tab)</span>
                                </a>
                            @else
                                <a href="{{ route('home', ['modal' => 'support']) }}" class="text-link">Contact the school for location guidance</a>
                            @endif
                            <button type="button" class="btn btn-secondary-custom" data-bs-toggle="modal" data-bs-target="#supportModal">
                                <x-filament::icon icon="heroicon-o-lifebuoy" class="tala-public-icon" aria-hidden="true" />
                                <span>Support</span>
                            </button>
                        </div>
                    </div>
                    <div class="visit-map">
                        @if ($officialReferences['map_embed'])
                            <iframe class="institution-map" src="{{ $officialReferences['map_embed'] }}" title="Map showing Servitech Institute Asia Inc. campus location" width="100%" height="448" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                            <p class="form-text mt-2 mb-0">Map provided by Google.@if ($officialReferences['map']) Use Open in Google Maps for directions.@endif</p>
                        @else
                            <div class="location-guidance">
                                <x-filament::icon icon="heroicon-o-map-pin" class="tala-public-icon" aria-hidden="true" />
                                <p class="mb-0">Contact the school for directions and visiting guidance.</p>
                                <button type="button" class="text-link" data-bs-toggle="modal" data-bs-target="#supportModal">Contact Support</button>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </section>

        <section class="section-block application-tracking" id="application-status" data-navbar-contrast-surface="theme" aria-labelledby="application-status-title">
            <div class="container">
                <div class="tracking-layout">
                    <div class="tracking-intro">
                        <h2 class="section-title" id="application-status-title">Check application status</h2>
                        <p class="section-lead">Enter your application reference and account email. We send the status link privately to your verified email.</p>
                        <p class="tracking-help">Lost your reference, or need to upload a copy or respond to a correction? <a class="text-link" href="{{ route('filament.admin.auth.login') }}">Sign in</a> to continue.</p>
                        <button class="text-link" type="button" data-bs-toggle="modal" data-bs-target="#supportModal">Get help with application access</button>
                    </div>
                    <div class="tracking-form-panel">
                        @if (session('tracking_status'))
                            <div class="tracking-confirmation" role="status" tabindex="-1" data-tracking-confirmation>
                                <x-filament::icon icon="heroicon-o-envelope-open" class="tala-public-icon" aria-hidden="true" />
                                <div>
                                    <p class="tracking-confirmation__title mb-1">{{ session('tracking_status') }}</p>
                                    <p class="mb-0">If a link was sent, open the newest one within 15 minutes. Not in your inbox? Check your spam or junk folder.</p>
                                </div>
                            </div>
                        @endif
                        @if ($errors->hasAny(['reference', 'email']))
                            <p class="tracking-error-summary" role="alert">
                                <x-filament::icon icon="heroicon-o-exclamation-circle" class="tala-public-icon" aria-hidden="true" />
                                <span>Check the highlighted {{ $errors->has('reference') && $errors->has('email') ? 'fields' : 'field' }}, then send the request again. Your entries are kept.</span>
                            </p>
                        @endif
                        <form method="POST" action="{{ route('applications.status.request') }}" aria-label="Request application status link" data-tracking-form>
                            @csrf
                            <div class="row g-3">
                                <div class="col-12">
                                    <label for="tracking-reference" class="form-label">Application reference</label>
                                    <input id="tracking-reference" name="reference" type="text" class="form-control tracking-reference-input @error('reference') is-invalid @enderror" @error('reference') aria-invalid="true" @enderror value="{{ old('reference') }}" required maxlength="40" inputmode="text" autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false" placeholder="APP-…" data-reference-input aria-describedby="tracking-reference-help @error('reference') tracking-reference-error @enderror">
                                    <div id="tracking-reference-help" class="form-text">Starts with APP-. Find it on your submission receipt or confirmation email.</div>
                                    @error('reference')<p id="tracking-reference-error" class="invalid-feedback">{{ $message }}</p>@enderror
                                </div>
                                <div class="col-12">
                                    <label for="tracking-email" class="form-label">Account email</label>
                                    <input id="tracking-email" name="email" type="email" class="form-control @error('email') is-invalid @enderror" @error('email') aria-invalid="true" @enderror value="{{ old('email') }}" required maxlength="255" inputmode="email" autocomplete="email" autocapitalize="none" autocorrect="off" spellcheck="false" data-trim-input aria-describedby="tracking-email-help @error('email') tracking-email-error @enderror">
                                    <div id="tracking-email-help" class="form-text">Use the verified email you sign in with.</div>
                                    @error('email')<p id="tracking-email-error" class="invalid-feedback">{{ $message }}</p>@enderror
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary-custom tracking-submit mt-3" data-tracking-submit>
                                <x-filament::icon icon="heroicon-o-envelope" class="tala-public-icon tracking-submit__icon" aria-hidden="true" />
                                <span class="tracking-submit__spinner" aria-hidden="true"></span>
                                <span data-tracking-submit-label>Email status link</span>
                            </button>
                            <p class="form-text mt-2 mb-0">The link expires 15 minutes after your request. Your reference alone cannot open your application.</p>
                        </form>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="footer-section" data-navbar-contrast-surface="dark">
        <div class="container">
            <div class="footer-layout">
                <div class="footer-identity">
                    <a class="footer-brand" href="{{ url('/') }}" aria-label="Servitech Institute Asia Inc. Powered by TALA home">
                        <img src="{{ asset('images/brand/servitech-crest-outlined-192.webp') }}" alt="" aria-hidden="true" class="tala-crest-outlined footer-brand-crest" width="56" height="56" decoding="async" loading="lazy">
                        <span>Servitech Institute Asia Inc.</span>
                    </a>
                    <p class="footer-desc">Programs, admissions and school support.</p>
                </div>
                <nav class="footer-navigation" aria-label="Footer navigation">
                    <div class="footer-link-group">
                        <h2 class="footer-heading">Explore</h2>
                        <ul class="list-unstyled">
                            <li><a class="footer-link" href="#programs">Programs</a></li>
                            @if ($notices->isNotEmpty() || in_array('notices', $unavailable, true))
                                <li><a class="footer-link" href="#notices">Announcements</a></li>
                            @endif
                            <li><a class="footer-link" href="#location">Visit</a></li>
                            <li><a class="footer-link" href="#faq">FAQ</a></li>
                        </ul>
                    </div>
                    <div class="footer-link-group">
                        <h2 class="footer-heading">Access</h2>
                        <ul class="list-unstyled">
                            <li><a class="footer-link" href="{{ route('filament.admin.auth.login') }}">Sign in</a></li>
                            @if ($applicantEntryReady)
                                <li><a class="footer-link" href="{{ route('filament.applicant.auth.register') }}">Create Applicant account</a></li>
                            @endif
                            <li><a class="footer-link" href="#application-status">Check application status</a></li>
                        </ul>
                    </div>
                    <div class="footer-link-group">
                        <h2 class="footer-heading">Help</h2>
                        <ul class="list-unstyled">
                            <li><button class="footer-link footer-link-button" type="button" data-bs-toggle="modal" data-bs-target="#supportModal">Support</button></li>
                            <li><button class="footer-link footer-link-button" type="button" data-bs-toggle="modal" data-bs-target="#privacyModal">Privacy</button></li>
                            <li><button class="footer-link footer-link-button" type="button" data-bs-toggle="modal" data-bs-target="#accessibilityModal">Accessibility</button></li>
                        </ul>
                    </div>
                </nav>
            </div>
            <div class="footer-divider">
                <p class="mb-0">&copy; {{ date('Y') }} Servitech Institute Asia (SIA)</p>
                <p class="footer-attribution mb-0">
                    <img src="{{ asset('talalogo.png') }}" alt="" aria-hidden="true" class="landing-attribution-mark" width="14" height="14">
                    <span>Powered by TALA</span>
                </p>
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
                    <p>Contact Servitech for admissions, campus location, account access, privacy questions, or accessibility assistance.</p>
                    <div class="modal-action-list">
                        @if ($officialReferences['support'])
                            <a class="btn btn-secondary-custom" href="{{ $officialReferences['support'] }}" target="_blank" rel="noopener noreferrer">
                                <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="tala-public-icon" aria-hidden="true" />
                                <span>Open Servitech Facebook</span>
                                <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="tala-public-icon" aria-hidden="true" />
                                <span class="visually-hidden">(opens in a new tab)</span>
                            </a>
                        @endif
                        <a class="btn btn-secondary-custom" href="{{ $officialReferences['support_phone_uri'] }}">
                            <x-filament::icon icon="heroicon-o-phone" class="tala-public-icon" aria-hidden="true" />
                            <span>Call {{ $officialReferences['support_phone'] }}</span>
                        </a>
                    </div>
                    <p class="modal-note mb-0">These are the school’s public contact channels. Response times depend on the school.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade tala-info-modal" id="privacyModal" tabindex="-1" aria-labelledby="privacyModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h3" id="privacyModalTitle">Privacy Notice</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close privacy notice"></button>
                </div>
                <div class="modal-body privacy-notice-copy">
                    <h3 class="h5">Applicant accounts</h3>
                    <p>TALA collects the information needed to create and secure an Applicant account: your email address, a protected password representation, your Privacy Notice acknowledgement, and security and email-verification events.</p>
                    <h3 class="h5">How the information is used</h3>
                    <ul>
                        <li>To create one Applicant credential and prevent duplicate accounts.</li>
                        <li>To verify email ownership, authorize workspace access, and support account recovery.</li>
                        <li>To keep security and accountability evidence required for the account journey.</li>
                    </ul>
                    <p>Account creation does not create an Application or Student record. Authorized TALA users and services may access account information only for their permitted tasks. TALA does not display your password and does not ask for application documents during registration.</p>
                    <h3 class="h5">Application status requests</h3>
                    <p>When you request an application status link, TALA uses your application reference and account email to check eligibility and send a private link to the active, verified Applicant owner. The confirmation is the same whether or not the supplied details match. The short-lived access token is scoped to the application, owner and verified email and expires after 15 minutes. Opening it rechecks access and shows only safe status and next-step information.</p>
                    <h3 class="h5">Questions and requests</h3>
                    <p class="mb-0">Use the Support information on this page for privacy questions or requests. Applicable institutional and legal review still governs retention, correction, access, and lawful disposal.</p>
                    <button type="button" class="btn btn-secondary-custom mt-3" data-bs-toggle="modal" data-bs-target="#supportModal">
                        <x-filament::icon icon="heroicon-o-lifebuoy" class="tala-public-icon" aria-hidden="true" />
                        <span>Contact Support</span>
                    </button>
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
                        <li>Public and authentication content is designed for mobile widths, 200% zoom and reflow, high-contrast preferences, and reduced motion. The moving background on this page can be paused and stays still when reduced motion is requested.</li>
                    </ul>
                    <p class="mb-0">If you encounter an access barrier, use the Support contact paths on this page and describe the page, task, and problem.</p>
                    <button type="button" class="btn btn-secondary-custom mt-3" data-bs-toggle="modal" data-bs-target="#supportModal">
                        <x-filament::icon icon="heroicon-o-lifebuoy" class="tala-public-icon" aria-hidden="true" />
                        <span>Contact Support</span>
                    </button>
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
