@if ($usesAuthDesigner)
    <main id="tala-main-content" class="tala-auth-main" tabindex="-1">
        <div class="tala-auth-school">
            <x-tala-panel-brand crest="outlined" />
        </div>
@else
    @include('filament.components.content-anchor')
@endif
