@if ($usesAuthDesigner)
    @php
        $currentPanelId = \Filament\Facades\Filament::getCurrentOrDefaultPanel()?->getId();
        $preferredAuthImage = match ($currentPanelId) {
            'student' => 'student.webp',
            'applicant' => 'applicant.webp',
            default => 'admin.webp',
        };
        $authSlides = \App\Support\AuthBannerMedia::slides($preferredAuthImage);
        $authSlideUrls = array_column($authSlides, 'url');
    @endphp
    <main id="tala-main-content" class="tala-auth-main" tabindex="-1">
        <div class="tala-auth-school">
            <x-tala-panel-brand crest="outlined" />
        </div>
        @if (count($authSlideUrls) > 1)
            <div
                class="tala-auth-slideshow"
                data-auth-slideshow
                data-slides='@json($authSlideUrls)'
                aria-hidden="true"
            ></div>
        @endif
        <x-tala-ambient-backdrop variant="auth" />
        <x-tala-context-entry-notice />
        @if (filled($statusNotice = session('status')))
            <x-tala-access-alert variant="info" class="mb-4" role="status">
                <p>{{ $statusNotice }}</p>
            </x-tala-access-alert>
        @endif
@else
    @if ($isMandatoryMfaSetup ?? false)
        <x-tala-ambient-backdrop />
    @endif
    @include('filament.components.content-anchor')
@endif
