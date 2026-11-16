@if ($usesAuthDesigner)
    <main id="tala-main-content" class="tala-auth-main" tabindex="-1">
        <div class="tala-auth-school">
            <x-tala-panel-brand crest="outlined" />
        </div>
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
