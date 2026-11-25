{{-- #99: TOR preview beside the crediting table (2/5 of the full-screen Credit subjects modal). --}}
@props(['copies' => []])
{{-- wire:ignore: the preview never depends on the form, and re-rendering its Alpine tabs/PDF viewer broke the modal. --}}
<aside class="tala-crediting-preview" aria-label="Transferee documents" wire:ignore wire:key="crediting-tor-preview">
    @if ($copies === [])
        <div class="tala-crediting-preview__empty">
            <x-filament::icon icon="heroicon-o-document-magnifying-glass" class="size-6" />
            <p>No submitted TOR or transfer document to preview. Compare against the paper copy the Registrar received.</p>
        </div>
    @else
        <div x-data="{ active: 0 }" class="tala-crediting-preview__body">
            @if (count($copies) > 1)
                <div class="tala-crediting-preview__tabs" role="tablist" aria-label="Choose a document">
                    @foreach ($copies as $index => $copy)
                        <button type="button" role="tab" class="tala-crediting-preview__tab" x-on:click="active = {{ $index }}"
                            x-bind:aria-selected="active === {{ $index }}" x-bind:class="{ 'is-active': active === {{ $index }} }">{{ $copy['label'] }}</button>
                    @endforeach
                </div>
            @endif
            @foreach ($copies as $index => $copy)
                <template x-if="active === {{ $index }}">
                    <div class="tala-crediting-preview__doc">
                        <div class="tala-crediting-preview__head">
                            <p class="tala-crediting-preview__title">{{ $copy['label'] }}</p>
                            <span class="tala-crediting-preview__links">
                                <a href="{{ $copy['view_url'] }}" target="_blank" rel="noopener">Open</a> · <a href="{{ $copy['download_url'] }}">Download</a>
                            </span>
                        </div>
                        <x-document-viewer
                            :id="'crediting-preview-'.$copy['id']"
                            :view-url="$copy['view_url']"
                            :download-url="$copy['download_url']"
                            :title="$copy['label']"
                            :inline="true"
                            :show-inline-download="false"
                            privacy-note="Private to authorized school staff. If it does not display, use Open or Download above."
                            loading-message="Loading document…"
                            :preview-aria-label="$copy['label'].' preview'"
                        />
                    </div>
                </template>
            @endforeach
        </div>
    @endif
</aside>
