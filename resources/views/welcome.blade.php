@extends('layouts.landing-bootstrap', ['title' => 'Servitech Institute Asia Inc.'])

@section('content')
    @php
        $noticesUnavailable = in_array('notices', $unavailable, true);
        $hasNoticesRegion = $notices->isNotEmpty() || $noticesUnavailable;
        $cashUnavailable = in_array('cashChannel', $unavailable, true);
        $cashChannelData = isset($cashChannel) && is_array($cashChannel) ? $cashChannel : null;
        $workspaceActionData = isset($workspaceAction) && is_array($workspaceAction) && filled($workspaceAction['url'] ?? null)
            ? $workspaceAction
            : null;
        $campusSlideList = isset($campusSlides) && is_array($campusSlides)
            ? array_values(array_filter($campusSlides, static fn (mixed $slide): bool => is_array($slide) && filled($slide['url'] ?? null)))
            : [];
        $campusSlideCount = count($campusSlideList);
        $registrarSchedule = isset($registrarHours) && is_array($registrarHours) ? $registrarHours : [
            'label' => 'Registrar Office',
            'days' => 'Monday to Friday',
            'hours' => '7:00 AM – 5:00 PM',
            'note' => 'Closed on holidays',
        ];
        $admissionBadgeTone = match (true) {
            $admissionsOpen && $applicantEntryReady => 'open',
            $admissionState === 'Upcoming' => 'upcoming',
            $admissionState === 'Unavailable' => 'warning',
            default => 'neutral',
        };
        $admissionBadgeLabel = match (true) {
            $admissionsOpen && $applicantEntryReady => 'Admissions · Open',
            $admissionsOpen => 'Admissions · Paused',
            $admissionState === 'Upcoming' => 'Admissions · Upcoming',
            $admissionState === 'Closed' => 'Admissions · Closed',
            $admissionState === 'Unavailable' => 'Admissions · Unavailable',
            default => 'Admissions',
        };
    @endphp

    <a class="tala-skip-link" href="#main-content">
        <span>Skip to main content</span>
        <svg class="tala-skip-link__icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </a>

    <div class="landing-scroll-progress" aria-hidden="true"></div>

    @php
        $fgTrinkets = [
            // Left flank & corners (framing centered hero and content without overlapping text)
            ['spark', 4, 14, 24, 'green', 0.74, 'twinkle', 6.2, -1.1],
            ['dot', 14, 10, 10, 'gold', 0.64, 'bob', 7.2, -2.3],
            ['ring', 22, 20, 22, 'gold', 0.62, 'bob', 8.4, -3.5],
            ['plus', 8, 29, 18, 'green', 0.68, 'turn', 16.0, -4.1],
            ['squiggle', 3, 43, 36, 'gold', 0.60, 'sway', 11.2, -2.8],
            ['star', 19, 48, 16, 'green', 0.72, 'twinkle', 6.8, -1.6],
            ['arc', 6, 61, 28, 'green', 0.64, 'sway', 9.8, -4.9],
            ['dot', 23, 68, 11, 'gold', 0.62, 'twinkle', 6.5, -0.8],
            ['plane', 10, 82, 28, 'green', 0.76, 'glide', 13.4, -5.6],

            // Right flank & corners
            ['ring', 78, 12, 21, 'green', 0.66, 'bob', 8.0, -1.9],
            ['star', 93, 17, 17, 'gold', 0.74, 'twinkle', 6.6, -3.2],
            ['plane', 84, 28, 28, 'gold', 0.68, 'glide', 14.6, -6.4],
            ['dot', 95, 39, 12, 'green', 0.66, 'bob', 7.4, -1.3],
            ['plus', 77, 46, 18, 'gold', 0.64, 'turn', 17.5, -5.1],
            ['spark', 90, 58, 26, 'green', 0.78, 'twinkle', 7.1, -2.6],
            ['arc', 79, 71, 26, 'green', 0.62, 'sway', 10.4, -4.3],
            ['squiggle', 89, 80, 34, 'gold', 0.64, 'sway', 11.8, -3.0],
            ['spark', 82, 91, 20, 'gold', 0.70, 'twinkle', 6.9, -1.7],
        ];
        $fgShapes = [
            'spark' => '<svg viewBox="0 0 24 24" focusable="false"><path d="M12 1 C 13 8, 16 11, 23 12 C 16 13, 13 16, 12 23 C 11 16, 8 13, 1 12 C 8 11, 11 8, 12 1 Z"/></svg>',
            'star' => '<svg viewBox="0 0 24 24" focusable="false"><path d="M12 2.5 L 14.1 9.9 L 21.5 12 L 14.1 14.1 L 12 21.5 L 9.9 14.1 L 2.5 12 L 9.9 9.9 Z"/></svg>',
            'ring' => '<svg viewBox="0 0 24 24" focusable="false"><circle class="tala-ambient-line" cx="12" cy="12" r="8.75"/></svg>',
            'plus' => '<svg viewBox="0 0 24 24" focusable="false"><path class="tala-ambient-line" d="M12 3.5 V 20.5 M3.5 12 H 20.5"/></svg>',
            'squiggle' => '<svg viewBox="0 0 64 20" focusable="false"><path class="tala-ambient-line" d="M2 10 C 8 0, 14 20, 20 10 S 32 0, 38 10 S 50 20, 62 10"/></svg>',
            'arc' => '<svg viewBox="0 0 32 20" focusable="false"><path class="tala-ambient-line" d="M3 16 C 9 4, 23 4, 29 16"/></svg>',
            'plane' => '<svg viewBox="0 0 48 44" focusable="false"><path d="M2 16 L 46 2 L 22 40 L 15 27 Z"/></svg>',
            'dot' => '<span class="tala-ambient-dot"></span>',
        ];
    @endphp

    <div class="landing-ambient-stage" aria-hidden="true">
        <div class="landing-dot-matrix"></div>
        <div class="landing-aurora-blob landing-aurora-blob--sage"></div>
        <x-tala-ambient-backdrop variant="auth" class="landing-ambient" />
        <div class="landing-trinkets-fg" data-trinkets-fg>
            @foreach ($fgTrinkets as [$shape, $fx, $fy, $fsize, $ftone, $fopacity, $fmotion, $fdur, $fdelay])
                @php
                    $colorDelay = round((($fx * 0.08) + ($fy * 0.14)) * -1, 2);
                @endphp
                <span
                    class="landing-fg-trinket"
                    data-shape="{{ $shape }}"
                    data-tone="{{ $ftone }}"
                    data-motion="{{ $fmotion }}"
                    style="--fx: {{ $fx }}%; --fy: {{ $fy }}%; --fsize: {{ $fsize }}px; --fopacity: {{ $fopacity }}; --fdur: {{ $fdur }}s; --fdelay: {{ $fdelay }}s; --fcolor-delay: {{ $colorDelay }}s;"
                >
                    <span class="landing-fg-trinket__glyph" data-trinket-glyph>{!! $fgShapes[$shape] !!}</span>
                </span>
            @endforeach
        </div>
        @php
            $burstSparkPool = [
                ['spark', 'green', 14],
                ['star', 'gold', 12],
                ['dot', 'crimson', 8],
                ['spark', 'gold', 15],
                ['plus', 'green', 12],
                ['dot', 'gold', 9],
                ['star', 'crimson', 13],
                ['ring', 'green', 13],
                ['spark', 'crimson', 11],
                ['dot', 'green', 8],
                ['star', 'gold', 14],
                ['plus', 'crimson', 11],
                ['spark', 'green', 13],
                ['dot', 'gold', 7],
            ];
        @endphp
        <div class="hero-gravity-fx" data-hero-gravity-fx aria-hidden="true">
            <span class="hero-gravity-ring" data-hero-gravity-ring></span>
            @foreach ($burstSparkPool as [$sShape, $sTone, $sSize])
                <span
                    class="hero-burst-spark"
                    data-hero-burst-spark
                    data-tone="{{ $sTone }}"
                    style="--ssize: {{ $sSize }}px;"
                >
                    {!! $fgShapes[$sShape] !!}
                </span>
            @endforeach
        </div>
    </div>

    <nav class="navbar navbar-expand-lg fixed-top bg-transparent" aria-label="Primary navigation">
        <div class="backdrop-blur" aria-hidden="true">
            <span></span>
            <span></span>
            <span></span>
            <span></span>
            <span></span>
            <span></span>
        </div>

        <div class="container landing-navbar__bar" data-navbar-bar>
            <a class="navbar-brand landing-brand" href="{{ url('/') }}" aria-label="Servitech Institute Asia Inc. home">
                <img src="{{ asset('images/brand/servitech-crest-outlined-192.webp') }}" alt="" aria-hidden="true" class="tala-crest-outlined landing-brand-crest" width="40" height="40" decoding="async">
                <span class="landing-brand-text" data-navbar-contrast-target>
                    <span class="landing-brand-name">Servitech Institute Asia</span>
                </span>
            </a>

            <div class="landing-navbar__right">
                <div class="collapse navbar-collapse" id="navbarNav">
                    <ul class="navbar-nav landing-nav-track">
                        <span class="landing-nav-indicator" data-nav-indicator aria-hidden="true"></span>
                        <li class="nav-item">
                            <a class="nav-link" data-navbar-contrast-target href="#top">
                                <x-filament::icon icon="heroicon-o-home" class="tala-public-icon nav-link__icon" aria-hidden="true" />
                                <span>Home</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-navbar-contrast-target href="#notices">
                                <x-filament::icon icon="heroicon-o-megaphone" class="tala-public-icon nav-link__icon" aria-hidden="true" />
                                <span>Announcements</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-navbar-contrast-target href="#about">
                                <x-filament::icon icon="heroicon-o-building-library" class="tala-public-icon nav-link__icon" aria-hidden="true" />
                                <span>About</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-navbar-contrast-target href="#programs">
                                <x-filament::icon icon="heroicon-o-academic-cap" class="tala-public-icon nav-link__icon" aria-hidden="true" />
                                <span>Programs</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-navbar-contrast-target href="#faq">
                                <x-filament::icon icon="heroicon-o-question-mark-circle" class="tala-public-icon nav-link__icon" aria-hidden="true" />
                                <span>FAQ</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-navbar-contrast-target href="#location">
                                <x-filament::icon icon="heroicon-o-map-pin" class="tala-public-icon nav-link__icon" aria-hidden="true" />
                                <span>Visit</span>
                            </a>
                        </li>
                    </ul>
                </div>

                <div class="landing-navbar__actions">
                    @if ($workspaceActionData !== null)
                        <a class="btn btn-secondary-custom public-sign-in public-sign-in--workspace" href="{{ $workspaceActionData['url'] }}">
                            <x-filament::icon icon="heroicon-o-squares-2x2" class="tala-public-icon" aria-hidden="true" />
                            <span class="public-sign-in__label-full">{{ $workspaceActionData['label'] }}</span>
                            <span class="public-sign-in__label-compact" aria-hidden="true">Workspace</span>
                        </a>
                    @else
                        <a class="btn btn-secondary-custom public-sign-in" href="{{ route('public.sign-in') }}">
                            <x-filament::icon icon="heroicon-o-arrow-right-end-on-rectangle" class="tala-public-icon" aria-hidden="true" />
                            <span>Sign in</span>
                        </a>
                    @endif

                    <button class="navbar-toggler" type="button" data-navbar-contrast-target data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Open navigation menu">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                </div>
            </div>
        </div>
    </nav>

    <main id="main-content" tabindex="-1">
        <section class="hero-section school-masthead" id="top" data-navbar-contrast-surface="theme" aria-labelledby="hero-title">
            <div class="container hero-container">
                @if (session('status'))
                    <div class="landing-status-alert" role="status">
                        <x-filament::icon icon="heroicon-o-information-circle" class="tala-public-icon" aria-hidden="true" />
                        <span>{{ session('status') }}</span>
                    </div>
                @endif

                <div class="hero-monolith">
                    <img
                        class="school-identity-crest tala-crest-outlined"
                        src="{{ asset('images/brand/servitech-crest-outlined-320.webp') }}"
                        srcset="{{ asset('images/brand/servitech-crest-outlined-320.webp') }} 1x, {{ asset('images/brand/servitech-crest-outlined-640.webp') }} 2x"
                        width="320"
                        height="320"
                        alt=""
                        aria-hidden="true"
                        fetchpriority="high"
                        decoding="async"
                    >

                    <h1 class="display-headline" id="hero-title">Servitech<br>Institute Asia Inc.</h1>

                    <p class="hero-lead">Explore the school’s programs, find live admission schedules, and plan your visit to Servitech.</p>

                    <div class="hero-actions">
                        @if ($applicantEntryReady)
                            <a class="btn btn-primary-custom btn-hero-primary" href="{{ route('public.apply') }}">
                                <span>Apply</span>
                                <x-filament::icon icon="heroicon-o-arrow-right" class="tala-public-icon" aria-hidden="true" />
                            </a>
                        @else
                            <a class="btn btn-primary-custom btn-hero-primary" href="#admission-status-title">
                                <span>View admission information</span>
                                <x-filament::icon icon="heroicon-o-arrow-down" class="tala-public-icon" aria-hidden="true" />
                            </a>
                        @endif
                        <a class="btn btn-secondary-custom btn-hero-secondary" href="#programs">
                            <span>Explore programs</span>
                            <x-filament::icon icon="heroicon-o-arrow-down" class="tala-public-icon" aria-hidden="true" />
                        </a>
                    </div>

                    <dl class="hero-facts" aria-label="Institutional facts">
                        <div class="hero-fact">
                            <dt>Established</dt>
                            <dd>2017</dd>
                        </div>
                        <div class="hero-fact">
                            <dt>Location</dt>
                            <dd>San Pedro, Laguna</dd>
                        </div>
                        <div class="hero-fact">
                            <dt>Recognized by</dt>
                            <dd>TESDA</dd>
                        </div>
                    </dl>
                </div>

                <a class="hero-scroll-cue" href="#notices">
                    <span>Scroll to explore</span>
                    <x-filament::icon icon="heroicon-o-chevron-down" class="tala-public-icon" aria-hidden="true" />
                </a>
            </div>
        </section>

        <div class="landing-content-sheet">
            <div class="landing-content-sheet__handle">
                <button
                    type="button"
                    class="sheet-drag-handle"
                    data-sheet-handle
                    aria-label="Drag or click to toggle content sheet"
                >
                    <span class="sheet-drag-pill" aria-hidden="true"></span>
                </button>
            </div>

        <section
            class="section-block updates-section"
            id="notices"
            data-navbar-contrast-surface="theme"
            aria-labelledby="admission-status-title"
        >
            <div class="container">
                <div class="updates-bento">
                    <article class="bento-card bento-card--admissions" data-spotlight data-reveal>
                        <div class="bento-card__body admission-card__body">
                            <div class="admission-row">
                                <div class="admission-heading-group">
                                    <h2 class="bento-card__title" id="admission-status-title">
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

                                    <div class="admission-intake">
                                        @if ($admissionCycle && $openIntakeCount <= 1)
                                            <p class="admission-paths">{{ implode(' and ', $admissionCycle['paths']) }}</p>
                                        @endif

                                        @if ($openIntakeCount > 1)
                                            <p class="admission-note">Choose a Program and Student type to see your intake and deadline.</p>
                                        @elseif ($admissionState === 'Unavailable')
                                            <p class="admission-note">Contact Admissions for current availability.</p>
                                        @elseif ($admissionState === 'Missing')
                                            <p class="admission-note">Contact Admissions about applying. Existing accounts can still sign in.</p>
                                        @elseif ($admissionState === 'Closed')
                                            <p class="admission-note">Sign in to continue an existing application, or ask Admissions about the next intake.</p>
                                        @elseif ($admissionState === 'Upcoming')
                                            <p class="admission-note">Existing accounts can still sign in.</p>
                                        @elseif ($applicantEntryReady)
                                            <p class="admission-note">Create an Applicant account first to submit your application online.</p>
                                        @endif
                                    </div>
                                </div>

                                <div class="admission-aside">
                                    @if ($admissionCycle && $openIntakeCount <= 1)
                                        <dl class="admission-deadline">
                                            <dt>
                                                <x-filament::icon icon="heroicon-o-calendar-days" class="tala-public-icon" aria-hidden="true" />
                                                <span>{{ $admissionCycle['is_open'] ? 'Applications close' : 'Applications open' }}</span>
                                            </dt>
                                            <dd>
                                                <strong>{{ $admissionCycle['is_open'] ? $admissionCycle['closes_at'] : $admissionCycle['opens_at'] }}</strong>
                                            </dd>
                                        </dl>
                                    @endif

                                    <span class="status-pill status-pill--{{ $admissionBadgeTone }}">
                                        <span class="status-pill__dot" aria-hidden="true"></span>
                                        <span>{{ $admissionBadgeLabel }}</span>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="bento-card__footer admission-footer">
                            @if ($workspaceActionData !== null)
                                <a class="btn btn-secondary-custom btn-pill-action" href="{{ $workspaceActionData['url'] }}">
                                    <x-filament::icon icon="heroicon-o-squares-2x2" class="tala-public-icon" aria-hidden="true" />
                                    <span>{{ $workspaceActionData['label'] }}</span>
                                </a>
                            @else
                                <a class="btn btn-secondary-custom btn-pill-action" href="{{ route('public.sign-in') }}">
                                    <x-filament::icon icon="heroicon-o-arrow-right-end-on-rectangle" class="tala-public-icon" aria-hidden="true" />
                                    <span>Sign in for application updates</span>
                                </a>
                            @endif
                        </div>
                    </article>

                    <article class="bento-card bento-card--announcements" data-spotlight data-reveal aria-labelledby="announcements-heading">
                        <div class="bento-card__body">
                            <div class="bento-card__header">
                                <h2 class="bento-card__title" id="announcements-heading">School announcements</h2>
                                @if ($notices->isNotEmpty())
                                    <span class="status-pill status-pill--upcoming">
                                        <span class="status-pill__dot" aria-hidden="true"></span>
                                        <span>{{ $notices->count() }} {{ Str::plural('active', $notices->count()) }}</span>
                                    </span>
                                @elseif ($noticesUnavailable)
                                    <span class="status-pill status-pill--neutral">
                                        <span class="status-pill__dot" aria-hidden="true"></span>
                                        <span>Unavailable</span>
                                    </span>
                                @else
                                    <span class="status-pill status-pill--neutral">
                                        <span class="status-pill__dot" aria-hidden="true"></span>
                                        <span>Up to date</span>
                                    </span>
                                @endif
                            </div>

                            <div class="announcements-body">
                                @if ($notices->isNotEmpty())
                                    <div @class(['notices-list', 'notices-list--grid' => $notices->count() > 1])>
                                        @foreach ($notices as $notice)
                                            <div class="notice-row">
                                                <div class="notice-row__head">
                                                    <h3 class="notice-row__title">{{ $notice->title }}</h3>
                                                    @if ($notice->published_at)
                                                        <time class="notice-row__date" datetime="{{ $notice->published_at->timezone('Asia/Manila')->toIso8601String() }}">
                                                            {{ $notice->published_at->timezone('Asia/Manila')->format('M j, Y') }}
                                                        </time>
                                                    @endif
                                                </div>
                                                <p class="notice-row__message">{{ $notice->message }}</p>
                                                @if ($notice->link_url)
                                                    <div class="notice-row__actions">
                                                        <a class="text-link notice-row__link" href="{{ $notice->link_url }}" target="_blank" rel="noopener noreferrer">
                                                            <span>{{ $notice->link_label }}</span>
                                                            <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="tala-public-icon" aria-hidden="true" />
                                                            <span class="visually-hidden">(opens in a new tab)</span>
                                                        </a>
                                                    </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @elseif ($noticesUnavailable)
                                    <div class="notices-empty-inset" role="status">
                                        <div class="notices-empty-inset__head">
                                            <x-filament::icon icon="heroicon-o-megaphone" class="tala-public-icon notices-empty-inset__icon" aria-hidden="true" />
                                            <h3 class="notice-row__title">Announcements are temporarily unavailable.</h3>
                                        </div>
                                        <p class="notice-row__message mb-0">Use the school support channels below for current guidance.</p>
                                    </div>
                                @else
                                    <div class="notices-empty-inset">
                                        <div class="notices-empty-inset__head">
                                            <x-filament::icon icon="heroicon-o-megaphone" class="tala-public-icon notices-empty-inset__icon" aria-hidden="true" />
                                            <h3 class="notice-row__title">No active school announcements right now.</h3>
                                        </div>
                                        <p class="notice-row__message mb-0">Published school advisories and calendar notices appear here automatically.</p>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="bento-card__footer announcements-footer">
                            <p class="announcements-footer__note mb-0">
                                <x-filament::icon icon="heroicon-o-megaphone" class="tala-public-icon" aria-hidden="true" />
                                <span>Official advisories from Servitech</span>
                            </p>
                        </div>
                    </article>
                </div>
            </div>
        </section>

        {{-- About SIA (#61 F100): overview and objectives supplied by the owner (Oct 17); campus slides shared with AuthBannerMedia. --}}
        <section class="section-block about-section" id="about" data-navbar-contrast-surface="theme" aria-labelledby="about-title">
            <div class="container">
                <div class="about-bento">
                    <article class="bento-card about-story" data-spotlight data-reveal>
                        <div class="bento-card__body about-story__body">
                            <h2 class="section-title" id="about-title">About Servitech Institute Asia</h2>
                            <p class="about-lead">Servitech Institute Asia is an educational institution in Laguna, Region IV-A, established in 2017 to give people a fighting edge through quality education. It aims to offer senior high school, college, and technical-vocational programs, with a current focus on TESDA technical-vocational training.</p>
                        </div>
                        <div class="bento-card__footer about-actions">
                            <a class="btn btn-secondary-custom" href="#programs">
                                <span>Explore programs</span>
                                <x-filament::icon icon="heroicon-o-arrow-down" class="tala-public-icon" aria-hidden="true" />
                            </a>
                            <a class="btn btn-secondary-custom" href="#location">
                                <x-filament::icon icon="heroicon-o-map-pin" class="tala-public-icon" aria-hidden="true" />
                                <span>Plan your visit</span>
                            </a>
                        </div>
                    </article>

                    <div class="about-deck-panel" data-reveal>
                        @if ($campusSlideCount > 0)
                            <div class="campus-deck" data-campus-deck role="region" aria-label="Servitech campus photos">
                                <div class="campus-deck__stage">
                                    @foreach ($campusSlideList as $slide)
                                        @php
                                            $deckState = match (true) {
                                                $loop->index === 0 => 'is-active',
                                                $loop->index === 1 => 'is-next',
                                                default => 'is-back',
                                            };
                                            $slideLabel = ucfirst(str_replace(['-', '_'], ' ', pathinfo((string) ($slide['filename'] ?? ''), PATHINFO_FILENAME)));
                                            $slideAlt = filled($slideLabel)
                                                ? "Servitech Institute Asia campus — {$slideLabel}"
                                                : "Servitech Institute Asia campus photo {$loop->iteration}";
                                        @endphp
                                        <figure
                                            class="campus-deck__card {{ $deckState }}"
                                            data-deck-card
                                            aria-hidden="{{ $loop->index === 0 ? 'false' : 'true' }}"
                                        >
                                            <img
                                                src="{{ $slide['url'] }}"
                                                alt="{{ $slideAlt }}"
                                                loading="{{ $loop->index === 0 ? 'eager' : 'lazy' }}"
                                                decoding="async"
                                            >
                                        </figure>
                                    @endforeach
                                </div>

                                @if ($campusSlideCount > 1)
                                    <div class="campus-deck__controls">
                                        <span class="campus-deck__meta">
                                            <x-filament::icon icon="heroicon-o-photo" class="tala-public-icon" aria-hidden="true" />
                                            <span>Campus gallery</span>
                                            <span class="campus-deck__counter" data-deck-counter aria-live="polite">1 / {{ $campusSlideCount }}</span>
                                        </span>
                                        <div class="campus-deck__nav">
                                            <button type="button" class="btn btn-secondary-custom campus-deck__btn" data-deck-prev aria-label="Previous campus photo">
                                                <x-filament::icon icon="heroicon-o-chevron-left" class="tala-public-icon" aria-hidden="true" />
                                            </button>
                                            <button type="button" class="btn btn-secondary-custom campus-deck__btn" data-deck-next aria-label="Next campus photo">
                                                <x-filament::icon icon="heroicon-o-chevron-right" class="tala-public-icon" aria-hidden="true" />
                                            </button>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @else
                            <div class="bento-card campus-deck__empty">
                                <img
                                    src="{{ asset('images/brand/servitech-crest-outlined-320.webp') }}"
                                    alt="Servitech Institute Asia emblem"
                                    class="tala-crest-outlined campus-deck__empty-crest"
                                    width="96"
                                    height="96"
                                    loading="lazy"
                                    decoding="async"
                                >
                                <p class="campus-deck__empty-label mb-0">Servitech Institute Asia · San Pedro, Laguna</p>
                            </div>
                        @endif
                    </div>

                    {{-- Objectives supplied by the owner (Oct 17). --}}
                    <section class="bento-card about-objectives" aria-labelledby="about-objectives-title" data-spotlight data-reveal>
                        <div class="about-objectives__header">
                            <h3 class="about-objectives__title" id="about-objectives-title">Objectives</h3>
                            <p class="about-objectives__intro">Servitech Institute Asia shall exist to meet the following objectives:</p>
                        </div>
                        <ul class="about-objectives__list">
                            <li>
                                <x-filament::icon icon="heroicon-o-check-circle" class="about-objectives__icon" aria-hidden="true" />
                                <span>Be the leading TechVoc Training and Assessment Center in Region IV-A.</span>
                            </li>
                            <li>
                                <x-filament::icon icon="heroicon-o-check-circle" class="about-objectives__icon" aria-hidden="true" />
                                <span>Be the leading provider of quality training and assessment for all its students.</span>
                            </li>
                            <li>
                                <x-filament::icon icon="heroicon-o-check-circle" class="about-objectives__icon" aria-hidden="true" />
                                <span>Be an institution that will provide job assistance to all its graduates.</span>
                            </li>
                        </ul>
                    </section>
                </div>
            </div>
        </section>

        @php
            $programsUnavailable = in_array('programs', $unavailable, true);
            $totalPrograms = $totalProgramCount ?? $programs->count();
            $visibleProgramCount = $programs->count();
            $visibleAcceptingCount = $programs->whereIn('id', $acceptingProgramIds)->count();
            $allOpenProgramCount = $openProgramCount ?? $visibleAcceptingCount;
            $allNotOpenProgramCount = $notOpenProgramCount ?? max(0, $totalPrograms - $allOpenProgramCount);
            $activeDuration = $programFilters['duration'] ?? null;
            $activeAvailability = $programFilters['availability'] ?? null;
            $hasActiveProgramFilters = $programFilters !== [];
            $showDurationFilter = count($programDurations) > 1;
        @endphp

        <section
            class="section-block programs-section"
            id="programs"
            data-navbar-contrast-surface="theme"
            aria-labelledby="programs-title"
            data-program-catalog
            data-catalog-complete="{{ $hasActiveProgramFilters ? 'false' : 'true' }}"
            data-catalog-total="{{ $totalPrograms }}"
            data-catalog-open-total="{{ $allOpenProgramCount }}"
            data-catalog-base-url="{{ route('home') }}"
        >
            <div class="container">
                <div class="catalog-heading" data-reveal>
                    <div>
                        <h2 class="section-title" id="programs-title">Explore programs</h2>
                        <p class="section-lead">See program lengths and which programs are currently accepting applications.</p>
                    </div>
                </div>

                @if ($totalPrograms > 0 && ! $programsUnavailable)
                    <search aria-label="Filter programs" data-reveal>
                        <form class="program-filters" method="GET" action="{{ route('home') }}#programs" data-program-filters>
                            <div class="program-filters__controls">
                                @if ($showDurationFilter)
                                    <fieldset class="program-filter-group">
                                        <legend class="visually-hidden">Program length</legend>
                                        <div class="program-segmented">
                                            <div class="program-segmented__option">
                                                <input
                                                    class="visually-hidden program-segmented__input"
                                                    type="radio"
                                                    id="program-duration-all"
                                                    name="duration"
                                                    value=""
                                                    @checked($activeDuration === null)
                                                >
                                                <label class="program-segmented__pill" for="program-duration-all">
                                                    <span>All lengths</span>
                                                    <span class="program-segmented__count" aria-hidden="true">{{ $totalPrograms }}</span>
                                                </label>
                                            </div>
                                            @foreach ($programDurations as $duration)
                                                @php
                                                    $durationCount = ($programDurationCounts ?? [])[$duration] ?? null;
                                                @endphp
                                                <div class="program-segmented__option">
                                                    <input
                                                        class="visually-hidden program-segmented__input"
                                                        type="radio"
                                                        id="program-duration-{{ $duration }}"
                                                        name="duration"
                                                        value="{{ $duration }}"
                                                        @checked($activeDuration === $duration)
                                                    >
                                                    <label class="program-segmented__pill" for="program-duration-{{ $duration }}">
                                                        <span>{{ $duration }} {{ Str::plural('year', $duration) }}</span>
                                                        @if ($durationCount !== null)
                                                            <span class="program-segmented__count" aria-hidden="true">{{ $durationCount }}</span>
                                                        @endif
                                                    </label>
                                                </div>
                                            @endforeach
                                        </div>
                                    </fieldset>
                                @endif

                                <fieldset class="program-filter-group">
                                    <legend class="visually-hidden">Admissions availability</legend>
                                    <div class="program-segmented">
                                        <div class="program-segmented__option">
                                            <input
                                                class="visually-hidden program-segmented__input"
                                                type="radio"
                                                id="program-availability-all"
                                                name="availability"
                                                value=""
                                                @checked($activeAvailability === null)
                                            >
                                            <label class="program-segmented__pill" for="program-availability-all">
                                                <span>All</span>
                                                <span class="program-segmented__count" aria-hidden="true">{{ $totalPrograms }}</span>
                                            </label>
                                        </div>
                                        <div class="program-segmented__option">
                                            <input
                                                class="visually-hidden program-segmented__input"
                                                type="radio"
                                                id="program-availability-open"
                                                name="availability"
                                                value="open"
                                                @checked($activeAvailability === 'open')
                                            >
                                            <label class="program-segmented__pill" for="program-availability-open">
                                                <span class="program-segmented__dot program-segmented__dot--open" aria-hidden="true"></span>
                                                <span>Applications open</span>
                                                <span class="program-segmented__count" aria-hidden="true">{{ $allOpenProgramCount }}</span>
                                            </label>
                                        </div>
                                        <div class="program-segmented__option">
                                            <input
                                                class="visually-hidden program-segmented__input"
                                                type="radio"
                                                id="program-availability-enquire"
                                                name="availability"
                                                value="enquire"
                                                @checked($activeAvailability === 'enquire')
                                            >
                                            <label class="program-segmented__pill" for="program-availability-enquire">
                                                <span>Not currently open</span>
                                                <span class="program-segmented__count" aria-hidden="true">{{ $allNotOpenProgramCount }}</span>
                                            </label>
                                        </div>
                                    </div>
                                </fieldset>
                            </div>

                            <div class="program-filters__actions">
                                <p class="catalog-summary" data-program-summary>
                                    @if ($hasActiveProgramFilters)
                                        <span>Showing <strong>{{ $visibleProgramCount }}</strong> of <strong>{{ $totalPrograms }}</strong> {{ Str::plural('program', $totalPrograms) }}</span>
                                    @else
                                        <span><strong>{{ $visibleProgramCount }}</strong> {{ Str::plural('program', $visibleProgramCount) }}</span>
                                        <span class="catalog-summary__sep" aria-hidden="true">·</span>
                                        <span><strong>{{ $visibleAcceptingCount }}</strong> accepting applications</span>
                                    @endif
                                </p>
                                <button class="btn btn-secondary-custom program-filters__submit" type="submit">Apply filters</button>
                                <a
                                    class="program-filters__clear"
                                    href="{{ route('home') }}#programs"
                                    data-program-filter-clear
                                    @if (! $hasActiveProgramFilters) hidden @endif
                                >
                                    <x-filament::icon icon="heroicon-o-x-mark" class="tala-public-icon" aria-hidden="true" />
                                    <span>Clear filters</span>
                                </a>
                            </div>
                        </form>
                    </search>
                    <p class="visually-hidden" role="status" aria-live="polite" data-program-filter-status></p>
                @endif

                <ul class="program-list list-unstyled mb-0" data-program-list data-reveal>
                    @foreach ($programs as $program)
                        @php
                            $isAccepting = in_array($program->id, $acceptingProgramIds, true);
                        @endphp
                        <li
                            class="program-row bento-card {{ $isAccepting ? 'program-row--open' : '' }}"
                            style="view-transition-name: program-card-{{ $program->id }};"
                            data-spotlight
                            data-program-card
                            data-program-id="{{ $program->id }}"
                            data-duration="{{ $program->duration_years }}"
                            data-availability="{{ $isAccepting ? 'open' : 'enquire' }}"
                        >
                            <div class="program-row__header">
                                <span class="program-duration">
                                    <x-filament::icon icon="heroicon-o-clock" class="tala-public-icon" aria-hidden="true" />
                                    <span>{{ $program->duration_years }} {{ Str::plural('year', $program->duration_years) }}</span>
                                </span>
                                <span class="program-availability {{ $isAccepting ? 'is-open' : '' }}">
                                    <x-filament::icon :icon="$isAccepting ? 'heroicon-o-check-circle' : 'heroicon-o-calendar-days'" class="tala-public-icon" aria-hidden="true" />
                                    <span>{{ $isAccepting ? 'Applications open' : 'Not currently open' }}</span>
                                </span>
                            </div>

                            <div class="program-row__body">
                                <h3 class="program-row__title">{{ $program->name }}</h3>

                                @if (filled($program->description))
                                    <p class="program-summary">{{ $program->description }}</p>
                                @endif
                            </div>

                            <div class="program-row__footer">
                                <a
                                    class="text-link program-read-more"
                                    href="{{ route('home', [...$programFilters, 'program' => $program->id]) }}#programs"
                                    data-bs-toggle="modal"
                                    data-bs-target="#programDetailModal-{{ $program->id }}"
                                    data-program-read-more="{{ $program->id }}"
                                >
                                    <span>Read more<span class="visually-hidden"> about {{ $program->name }}</span></span>
                                    <x-filament::icon icon="heroicon-o-arrow-right" class="tala-public-icon" aria-hidden="true" />
                                </a>
                            </div>
                        </li>
                    @endforeach

                    <li
                        class="program-empty bento-card"
                        data-program-empty
                        @if ($programs->isNotEmpty()) hidden @endif
                    >
                        <div class="program-empty__copy">
                            <x-filament::icon icon="heroicon-o-information-circle" class="tala-public-icon" aria-hidden="true" />
                            <span data-program-empty-message>{{ $programsUnavailable ? 'Program information is temporarily unavailable. Contact Admissions for guidance.' : ($hasActiveProgramFilters || $totalPrograms > 0 ? 'No programs match these filters. Clear filters to view all offered programs.' : 'Contact Admissions for program information.') }}</span>
                        </div>
                        @if ($totalPrograms > 0 && ! $programsUnavailable)
                            <a
                                class="btn btn-secondary-custom btn-pill-action program-empty__reset"
                                href="{{ route('home') }}#programs"
                                data-program-filter-clear
                            >
                                <span>Clear filters</span>
                            </a>
                        @endif
                    </li>
                </ul>
            </div>
        </section>

        <section class="faq-section section-block" id="faq" data-navbar-contrast-surface="theme" aria-labelledby="faq-title">
            <div class="container">
                @if ($faqEntries->isNotEmpty())
                    <div class="discovery-grid">
                        <div class="discovery-intro" data-reveal>
                            <h2 class="section-title" id="faq-title">Frequently Asked Questions</h2>
                            <p class="section-lead">Find out what you need to know before starting your journey at SIA.</p>
                        </div>
                        <div class="discovery-content" data-reveal>
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
                    <div class="faq-help bento-card" data-reveal>
                        <x-filament::icon icon="heroicon-o-question-mark-circle" class="faq-help__mark" aria-hidden="true" />
                        <div class="faq-help__copy">
                            <h2 class="faq-help__title" id="faq-title">Frequently Asked Questions</h2>
                            <p class="mb-0" role="status">{{ in_array('faqEntries', $unavailable, true) ? 'FAQs are temporarily unavailable.' : 'No public FAQs are available yet.' }} Admissions can answer questions about programs, requirements and applying.</p>
                        </div>
                    </div>
                @endif
            </div>
        </section>

        <section class="institution-section section-block" id="location" data-navbar-contrast-surface="theme" aria-labelledby="location-title">
            <div class="container">
                <div class="visit-bento">
                    <div class="visit-column">
                        <article class="bento-card visit-location-card" data-spotlight data-reveal>
                            <div class="bento-card__body visit-location-card__body">
                                <h2 class="section-title visit-location-card__title" id="location-title">Visit Servitech</h2>

                                <div class="visit-address-inset">
                                    <div class="visit-address-inset__meta">
                                        <x-filament::icon icon="heroicon-o-map-pin" class="tala-public-icon" aria-hidden="true" />
                                        <span>Campus address</span>
                                    </div>
                                    <div id="campus-address"><address class="institution-address mb-0">{{ config('institution.address') }}</address></div>
                                    <div class="visit-address-inset__copy">
                                        <span class="copy-control">
                                            <button
                                                type="button"
                                                class="btn btn-icon-copy"
                                                command="--copy"
                                                commandfor="campus-address"
                                                aria-label="Copy campus address"
                                            >
                                                <span class="copy-icon-stack" aria-hidden="true">
                                                    <x-filament::icon icon="heroicon-o-clipboard-document" class="tala-public-icon copy-icon copy-icon--idle" />
                                                    <x-filament::icon icon="heroicon-o-check" class="tala-public-icon copy-icon copy-icon--done" />
                                                </span>
                                                <span>Copy address</span>
                                            </button>
                                            <span class="copy-tip" role="status" aria-live="polite" data-copy-feedback></span>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="bento-card__footer visit-actions">
                                @if ($officialReferences['map'])
                                    <a href="{{ $officialReferences['map'] }}" target="_blank" rel="noopener noreferrer" class="btn btn-primary-custom">
                                        <x-filament::icon icon="heroicon-o-paper-airplane" class="tala-public-icon" aria-hidden="true" />
                                        <span>Get directions</span>
                                        <span class="visually-hidden">(opens Google Maps in a new tab)</span>
                                    </a>
                                @else
                                    <a href="{{ route('home', ['modal' => 'support']) }}" class="text-link">Contact the school for location guidance</a>
                                @endif

                                <button type="button" class="btn btn-secondary-custom" data-bs-toggle="modal" data-bs-target="#supportModal">
                                    <x-filament::icon icon="heroicon-o-lifebuoy" class="tala-public-icon" aria-hidden="true" />
                                    <span>Contact support</span>
                                </button>
                            </div>
                        </article>

                        <div class="bento-card visit-hours-panel" data-spotlight data-reveal aria-label="Campus office hours">
                            <div class="visit-hours-pane" aria-labelledby="visit-registrar-title">
                                <div class="visit-hours-pane__head">
                                    <span class="visit-card__icon-wrap" aria-hidden="true">
                                        <x-filament::icon icon="heroicon-o-clock" class="visit-card__icon" />
                                    </span>
                                    <h3 class="visit-card__title" id="visit-registrar-title">Registrar office hours</h3>
                                </div>
                                <dl class="cashier-schedule">
                                    <div class="cashier-schedule__row">
                                        <dt>{{ $registrarSchedule['days'] ?? 'Monday to Friday' }}</dt>
                                        <dd>{{ $registrarSchedule['hours'] ?? '7:00 AM – 5:00 PM' }}</dd>
                                    </div>
                                </dl>
                                @if (filled($registrarSchedule['note'] ?? null))
                                    <p class="visit-card__note">{{ $registrarSchedule['note'] }}</p>
                                @endif
                            </div>

                            <div class="visit-hours-pane" aria-labelledby="visit-cashier-title">
                                <div class="visit-hours-pane__head">
                                    <span class="visit-card__icon-wrap" aria-hidden="true">
                                        <x-filament::icon icon="heroicon-o-banknotes" class="visit-card__icon" />
                                    </span>
                                    <h3 class="visit-card__title" id="visit-cashier-title">Accounting &amp; cashier hours</h3>
                                </div>

                                @if ($cashUnavailable)
                                    <p class="visit-card__lead" role="status">Cashier hours are temporarily unavailable.</p>
                                    <p class="visit-card__note">Contact support for current onsite payment hours.</p>
                                @elseif ($cashChannelData !== null)
                                    @php
                                        $todayStatus = is_array($cashChannelData['today_status'] ?? null) ? $cashChannelData['today_status'] : null;
                                        $cashTone = match ($todayStatus['tone'] ?? null) {
                                            'success', 'open' => 'open',
                                            'warning' => 'upcoming',
                                            default => 'neutral',
                                        };
                                        $weeklyRows = is_array($cashChannelData['weekly_summary_rows'] ?? null) ? $cashChannelData['weekly_summary_rows'] : [];
                                    @endphp

                                    <div class="visit-cashier-meta">
                                        @if (filled($todayStatus['badge'] ?? null))
                                            <span class="status-pill status-pill--{{ $cashTone }} visit-card__badge">
                                                <span class="status-pill__dot" aria-hidden="true"></span>
                                                <span>{{ $todayStatus['badge'] }}</span>
                                            </span>
                                        @endif

                                        @if (filled($cashChannelData['location'] ?? null))
                                            <span class="visit-card__location">{{ $cashChannelData['location'] }}</span>
                                        @endif
                                    </div>

                                    @if ($weeklyRows !== [])
                                        <dl class="cashier-schedule">
                                            @foreach ($weeklyRows as $row)
                                                <div class="cashier-schedule__row">
                                                    <dt>{{ $row['days'] ?? '' }}</dt>
                                                    <dd>{{ $row['hours'] ?? '' }}</dd>
                                                </div>
                                            @endforeach
                                        </dl>
                                    @elseif (filled($cashChannelData['hours'] ?? null))
                                        <p class="visit-card__lead">
                                            <strong class="visit-card__strong">{{ $cashChannelData['hours'] }}</strong>
                                        </p>
                                    @endif

                                    @if (filled($cashChannelData['cutoff_note'] ?? null))
                                        <p class="visit-card__note">{{ $cashChannelData['cutoff_note'] }}</p>
                                    @elseif (filled($todayStatus['detail'] ?? null))
                                        <p class="visit-card__note">{{ $todayStatus['detail'] }}</p>
                                    @endif
                                @else
                                    <p class="visit-card__lead">Onsite cashier window is currently closed.</p>
                                    <p class="visit-card__note">Sign in to your account or contact support for current payment options.</p>
                                @endif
                            </div>
                        </div>

                        <section class="visit-guidelines-strip" aria-labelledby="visit-rules-title" data-reveal>
                            <h3 class="visit-guidelines-strip__title" id="visit-rules-title">Visitor guidelines</h3>
                            <ul class="visit-guidelines-strip__grid">
                                <li>
                                    <x-filament::icon icon="heroicon-o-shield-check" class="tala-public-icon" aria-hidden="true" />
                                    <span>Register at the guardhouse</span>
                                </li>
                                <li>
                                    <x-filament::icon icon="heroicon-o-identification" class="tala-public-icon" aria-hidden="true" />
                                    <span>Bring a valid ID</span>
                                </li>
                                <li>
                                    <x-filament::icon icon="heroicon-o-clipboard-document-check" class="tala-public-icon" aria-hidden="true" />
                                    <span>Follow campus policies</span>
                                </li>
                                <li>
                                    <x-filament::icon icon="heroicon-o-academic-cap" class="tala-public-icon" aria-hidden="true" />
                                    <span>Respect the learning environment</span>
                                </li>
                            </ul>
                        </section>
                    </div>

                    <div class="visit-map" data-reveal>
                        @if ($officialReferences['map_embed'])
                            <div class="visit-map__frame">
                                <iframe class="institution-map" src="{{ $officialReferences['map_embed'] }}" title="Map showing Servitech Institute Asia Inc. campus location" width="100%" height="520" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                            </div>
                            <p class="visit-map__caption">Map provided by Google.@if ($officialReferences['map']) Use Get directions for a route to campus.@endif</p>
                        @else
                            <div class="location-guidance bento-card">
                                <x-filament::icon icon="heroicon-o-map-pin" class="tala-public-icon" aria-hidden="true" />
                                <p class="mb-0">Contact the school for directions and visiting guidance.</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </section>
        </div>
    </main>

    <footer class="footer-section">
        <div class="container">
            <div class="footer-shell" data-navbar-contrast-surface="theme">
                @php
                    $footerRisingTrinkets = [
                        // [shape, fx%, sizePx, tone, opacity, durSec, negDelaySec, driftPx, rotDeg, travelPx]
                        ['spark', 7, 18, 'green', 0.56, 6.4, -0.8, 18, 28, -460],
                        ['dot', 14, 11, 'gold', 0.48, 5.6, -3.1, -14, 0, -420],
                        ['plane', 21, 21, 'crimson', 0.44, 7.8, -5.2, 26, 18, -500],
                        ['star', 28, 15, 'gold', 0.54, 6.1, -1.9, -18, -32, -450],
                        ['ring', 35, 19, 'green', 0.46, 8.4, -6.4, 12, 24, -490],
                        ['plus', 42, 14, 'crimson', 0.42, 5.9, -2.7, -16, 65, -430],
                        ['spark', 49, 22, 'gold', 0.58, 7.2, -4.5, 20, -22, -510],
                        ['arc', 56, 20, 'green', 0.46, 6.8, -0.4, -22, 20, -460],
                        ['dot', 63, 12, 'crimson', 0.46, 5.4, -3.9, 15, 0, -410],
                        ['star', 70, 17, 'green', 0.54, 7.5, -5.8, -19, 34, -480],
                        ['squiggle', 77, 24, 'gold', 0.48, 8.8, -2.2, 22, -16, -500],
                        ['plus', 84, 15, 'green', 0.50, 6.3, -4.1, -15, -58, -440],
                        ['plane', 90, 20, 'gold', 0.52, 7.1, -1.3, -24, -14, -490],
                        ['ring', 95, 14, 'crimson', 0.40, 6.6, -5.0, -12, 26, -450],
                    ];
                @endphp
                <div class="footer-shell__ambient" aria-hidden="true">
                    <span class="footer-aurora-wave footer-aurora-wave--green"></span>
                    <span class="footer-aurora-wave footer-aurora-wave--gold"></span>
                    <span class="footer-aurora-wave footer-aurora-wave--crimson"></span>
                    <div class="footer-trinket-stream" data-footer-trinket-stream>
                        @foreach ($footerRisingTrinkets as [$rShape, $rX, $rSize, $rTone, $rOpacity, $rDur, $rDelay, $rDrift, $rRot, $rTravel])
                            <span
                                class="footer-rising-trinket"
                                data-footer-trinket
                                data-shape="{{ $rShape }}"
                                data-tone="{{ $rTone }}"
                                style="--fx: {{ $rX }}%; --fsize: {{ $rSize }}px; --fopacity: {{ $rOpacity }}; --fdur: {{ $rDur }}s; --fdelay: {{ $rDelay }}s; --fdrift: {{ $rDrift }}px; --frot: {{ $rRot }}deg; --ftravel: {{ $rTravel }}px;"
                            >
                                {!! $fgShapes[$rShape] !!}
                            </span>
                        @endforeach
                    </div>
                </div>

                <div class="footer-shell__inner">
                    <div class="footer-top-band">
                        <div class="footer-identity">
                            <a class="footer-brand" href="{{ url('/') }}" aria-label="Servitech Institute Asia Inc. home">
                                <img src="{{ asset('images/brand/servitech-crest-outlined-192.webp') }}" alt="" aria-hidden="true" class="tala-crest-outlined footer-brand-crest" width="48" height="48" decoding="async" loading="lazy">
                                <span>Servitech Institute Asia Inc.</span>
                            </a>
                            <p class="footer-desc">Programs, admissions and school support.</p>
                        </div>

                        <div class="footer-top-actions">
                            @if ($applicantEntryReady)
                                <a class="btn btn-primary-custom" href="{{ route('public.apply') }}">
                                    <span>Apply</span>
                                    <x-filament::icon icon="heroicon-o-arrow-right" class="tala-public-icon" aria-hidden="true" />
                                </a>
                                <a class="btn btn-secondary-custom" href="#programs">
                                    <span>Explore programs</span>
                                </a>
                            @else
                                <a class="btn btn-primary-custom" href="#programs">
                                    <span>Explore programs</span>
                                    <x-filament::icon icon="heroicon-o-arrow-right" class="tala-public-icon" aria-hidden="true" />
                                </a>
                            @endif
                        </div>
                    </div>

                    <nav class="footer-navigation" aria-label="Footer navigation">
                        <div class="footer-link-group">
                            <h2 class="footer-heading">Explore</h2>
                            <ul class="list-unstyled">
                                <li><a class="footer-link" href="#top">Home</a></li>
                                <li><a class="footer-link" href="#notices">Announcements</a></li>
                                <li><a class="footer-link" href="#about">About</a></li>
                                <li><a class="footer-link" href="#programs">Programs</a></li>
                                <li><a class="footer-link" href="#faq">FAQ</a></li>
                                <li><a class="footer-link" href="#location">Visit</a></li>
                            </ul>
                        </div>
                        <div class="footer-link-group">
                            <h2 class="footer-heading">Access</h2>
                            <ul class="list-unstyled">
                                @if ($workspaceActionData !== null)
                                    <li><a class="footer-link" href="{{ $workspaceActionData['url'] }}">{{ $workspaceActionData['label'] }}</a></li>
                                @else
                                    <li><a class="footer-link" href="{{ route('public.sign-in') }}">Sign in</a></li>
                                @endif
                                @if ($applicantEntryReady)
                                    <li><a class="footer-link" href="{{ route('public.apply') }}">Create Applicant account</a></li>
                                @endif
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

                    <div class="footer-divider">
                        <p class="mb-0">&copy; {{ date('Y') }} Servitech Institute Asia (SIA)</p>
                        <p class="footer-meta mb-0">San Pedro, Laguna · TESDA Recognized</p>
                    </div>
                </div>

                <div class="footer-watermark" aria-hidden="true">
                    <span>SERVITECH</span>
                </div>
            </div>
        </div>
    </footer>

    @foreach ($programs as $program)
        @php
            $isAccepting = in_array($program->id, $acceptingProgramIds, true);
        @endphp
        <div class="modal fade tala-info-modal program-detail-modal" id="programDetailModal-{{ $program->id }}" tabindex="-1" aria-labelledby="program-detail-title-{{ $program->id }}" aria-hidden="true" data-program-detail-modal="{{ $program->id }}">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title h3" id="program-detail-title-{{ $program->id }}">{{ $program->name }}</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close program details">
                            <x-filament::icon icon="heroicon-o-x-mark" class="tala-public-icon" aria-hidden="true" />
                        </button>
                    </div>
                    <div class="modal-body">
                        <dl class="program-facts">
                            <div class="program-fact"><dt>Program length:&nbsp;</dt><dd>{{ $program->duration_years }} {{ Str::plural('year', $program->duration_years) }}</dd></div>
                            <div class="program-fact"><dt>Admissions:&nbsp;</dt><dd>{{ $isAccepting ? 'Applications open' : 'Not currently open for online applications' }}</dd></div>
                        </dl>
                        @if (filled($program->description))
                            <p class="program-modal-description">{{ $program->description }}</p>
                        @endif
                        <p>Contact Admissions for course and qualification details and the requirements for your Student type.</p>
                        <div class="modal-action-list">
                            @if ($isAccepting && $applicantEntryReady)
                                <a class="btn btn-primary-custom" href="{{ route('public.apply', ['program' => $program->id]) }}">Apply for {{ $program->name }}</a>
                            @else
                                <a class="btn btn-secondary-custom" href="{{ route('home', ['modal' => 'support']) }}">Contact Admissions</a>
                            @endif
                            <a class="btn btn-secondary-custom" href="{{ route('home', $programFilters) }}#programs" data-bs-dismiss="modal" data-program-modal-back>Back to all programs</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    <div class="modal fade tala-info-modal" id="supportModal" tabindex="-1" aria-labelledby="supportModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h3" id="supportModalTitle">Support</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close support information">
                        <x-filament::icon icon="heroicon-o-x-mark" class="tala-public-icon" aria-hidden="true" />
                    </button>
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
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h3" id="privacyModalTitle">Privacy Notice</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close privacy notice">
                        <x-filament::icon icon="heroicon-o-x-mark" class="tala-public-icon" aria-hidden="true" />
                    </button>
                </div>
                <div class="modal-body privacy-notice-copy">
                    @include('components.tala-privacy-notice-content')
                    <button type="button" class="btn btn-secondary-custom mt-3" data-bs-toggle="modal" data-bs-target="#supportModal">
                        <x-filament::icon icon="heroicon-o-lifebuoy" class="tala-public-icon" aria-hidden="true" />
                        <span>Contact Support</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade tala-info-modal" id="accessibilityModal" tabindex="-1" aria-labelledby="accessibilityModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h3" id="accessibilityModalTitle">Accessibility</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close accessibility information">
                        <x-filament::icon icon="heroicon-o-x-mark" class="tala-public-icon" aria-hidden="true" />
                    </button>
                </div>
                <div class="modal-body">
                    <p>TALA is designed so this entry journey can be completed with a keyboard and without relying on color alone.</p>
                    <ul>
                        <li>A skip link, semantic landmarks, visible focus, labelled controls, and keyboard-operable menus and dialogs support navigation.</li>
                        <li>Registration fields support paste, autofill, password managers, associated validation, and clear recovery actions.</li>
                        <li>Public and authentication content is designed for mobile widths, 200% zoom and reflow, high-contrast preferences, and reduced motion. Ambient motion on this page stays still automatically when reduced motion is requested.</li>
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
