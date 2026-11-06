<x-content-progress label="Processing your request" wire:loading.delay.short.block />

@if ($usesAuthDesigner && $showRecoveryLinks)
    @if (request()->routeIs('public.sign-in') && app(\App\Actions\Applicants\ApplicantEntryReadinessService::class)->registrationIsAvailable())
        <p class="tala-auth-recovery">
            <x-filament::link :href="route('public.apply')">New applicant? Apply for admission</x-filament::link>
        </p>
    @endif
    <nav class="tala-auth-recovery" aria-label="Access help">
        <x-filament::link :href="route('home')">Return to school home</x-filament::link>
        <x-filament::link :href="route('home', ['modal' => 'support'])" icon="heroicon-m-arrow-right" icon-position="after" icon-size="sm">Contact school support</x-filament::link>
    </nav>
@endif
