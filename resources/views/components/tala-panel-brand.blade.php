@props(['workspace' => null, 'crest' => 'outlined', 'person' => null])
<span class="tala-brand" translate="no">
    @if ($crest === 'plate')
        <span class="tala-crest-plate">
            <img src="{{ asset('images/brand/servitech-crest.webp') }}" alt="" aria-hidden="true" class="tala-brand__crest" width="48" height="48">
        </span>
    @else
        <img src="{{ asset('images/brand/servitech-crest-outlined-192.webp') }}" alt="" aria-hidden="true" class="tala-brand__crest tala-crest-outlined" width="56" height="56" decoding="async">
    @endif
    <span class="tala-brand__text">
        <span class="tala-brand__name"><span class="tala-brand__school">Servitech</span> <span class="tala-brand__institution">Institute Asia</span></span>
        @if (filled($person))
            <span class="tala-brand__person" data-tala-brand-person><span class="fi-sr-only">Signed in as </span>{{ $person }}</span>
        @endif
    </span>
</span>
