<x-content-progress label="Processing your request" wire:loading.delay.short.block />

@if ($usesAuthDesigner && $showRecoveryLinks)
    @if (request()->routeIs('public.sign-in') && app(\App\Actions\Applicants\ApplicantEntryReadinessService::class)->registrationIsAvailable())
        <p class="tala-auth-recovery">
            <x-filament::link :href="route('public.apply')">New applicant? Apply for admission</x-filament::link>
        </p>
    @endif
    <nav class="tala-auth-recovery" aria-label="Access help">
        <x-filament::link :href="route('home')">Back to school site</x-filament::link>
        <x-filament::link :href="route('home', ['modal' => 'support'])" data-tala-open-auth-modal="support" aria-haspopup="dialog" aria-controls="tala-auth-support-dialog" icon="heroicon-m-arrow-right" icon-position="after" icon-size="sm">Contact support</x-filament::link>
    </nav>
@elseif ($isMandatoryMfaSetup ?? false)
    <nav class="tala-auth-recovery tala-mfa-setup-support" aria-label="Account setup support">
        <x-filament::link :href="route('home', ['modal' => 'support'])" data-tala-open-auth-modal="support" aria-haspopup="dialog" aria-controls="tala-auth-support-dialog" icon="heroicon-m-arrow-right" icon-position="after" icon-size="sm">Contact support</x-filament::link>
    </nav>
@endif

@if (($usesAuthDesigner ?? false) || ($isMandatoryMfaSetup ?? false))
    @include('filament.components.auth-info-modals')
@endif
