@php
    $validateHttpsUrl = static function (?string $url): ?string {
        if (! is_string($url) || blank($url)) {
            return null;
        }

        return parse_url($url, PHP_URL_SCHEME) === 'https' ? $url : null;
    };

    $resolvedReferences = is_array($officialReferences ?? null) ? $officialReferences : null;
    $supportFacebookUrl = $resolvedReferences['support'] ?? $validateHttpsUrl(config('institution.public.support_facebook_url'));
    $supportPhone = trim((string) ($resolvedReferences['support_phone'] ?? config('institution.public.support_phone', '0947 737 9208')));
    $supportPhoneUri = trim((string) ($resolvedReferences['support_phone_uri'] ?? config('institution.public.support_phone_uri', 'tel:+639477379208')));
    $campusAddress = trim((string) config('institution.address', '162 R. Magsaysay Ave, San Pedro, Laguna, Philippines'));
    $mapUrl = $resolvedReferences['map'] ?? $validateHttpsUrl(config('institution.public.map_url'));
@endphp

<div class="tala-support-channels">
    <p class="tala-info-content__lead">
        Contact <strong>Servitech Institute Asia Inc.</strong> for Registrar and Admissions assistance, account sign-in or password recovery help, email verification, data privacy inquiries, or accessibility support.
    </p>

    <dl class="tala-support-channels__grid">
        @if ($supportPhone !== '')
            <div class="tala-support-channels__item">
                <dt class="tala-support-channels__term">Admissions &amp; Registrar Phone</dt>
                <dd class="tala-support-channels__detail">
                    @if ($supportPhoneUri !== '')
                        <a class="tala-support-channels__link" href="{{ $supportPhoneUri }}">{{ $supportPhone }}</a>
                    @else
                        <span>{{ $supportPhone }}</span>
                    @endif
                </dd>
            </div>
        @endif

        @if (filled($supportFacebookUrl))
            <div class="tala-support-channels__item">
                <dt class="tala-support-channels__term">Official Facebook Page</dt>
                <dd class="tala-support-channels__detail">
                    <a class="tala-support-channels__link" href="{{ $supportFacebookUrl }}" target="_blank" rel="noopener noreferrer">
                        Servitech Institute Asia Official Page
                        <span class="sr-only">(opens in a new tab)</span>
                    </a>
                </dd>
            </div>
        @endif

        <div class="tala-support-channels__item">
            <dt class="tala-support-channels__term">Office Hours</dt>
            <dd class="tala-support-channels__detail">Monday to Friday, 7:00 AM – 5:00 PM (Closed on holidays)</dd>
        </div>

        @if ($campusAddress !== '')
            <div class="tala-support-channels__item">
                <dt class="tala-support-channels__term">Campus Registrar &amp; Admissions Office</dt>
                <dd class="tala-support-channels__detail">
                    <address class="tala-support-channels__address">{{ $campusAddress }}</address>
                    @if (filled($mapUrl))
                        <a class="tala-support-channels__sublink" href="{{ $mapUrl }}" target="_blank" rel="noopener noreferrer">
                            Get directions on Google Maps
                            <span class="sr-only">(opens in a new tab)</span>
                        </a>
                    @endif
                </dd>
            </div>
        @endif
    </dl>

    @if ($supportPhone !== '' || filled($supportFacebookUrl))
        <div class="tala-support-channels__actions">
            @if ($supportPhone !== '' && $supportPhoneUri !== '')
                <x-filament::button tag="a" :href="$supportPhoneUri" color="primary" size="sm">
                    Call {{ $supportPhone }}
                </x-filament::button>
            @endif
            @if (filled($supportFacebookUrl))
                <x-filament::button tag="a" :href="$supportFacebookUrl" target="_blank" rel="noopener noreferrer" color="gray" outlined size="sm">
                    Open Servitech Facebook
                </x-filament::button>
            @endif
        </div>
    @endif

    <p class="tala-support-channels__note">
        For faster account or application assistance, include the email address registered on your Servitech account when contacting school support.
    </p>
</div>
