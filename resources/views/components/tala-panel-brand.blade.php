@props(['workspace' => null, 'placement' => 'identity', 'crest' => 'plate', 'person' => null])
@if ($placement === 'attribution')
    <span class="tala-brand__attribution" translate="no">
        <img src="{{ asset('talalogo.png') }}" alt="" aria-hidden="true" class="tala-brand__star" width="32" height="32">
        <span>Powered by TALA</span>
    </span>
@else
<span class="tala-brand" translate="no">
    @if ($crest === 'outlined')
        <img src="{{ asset('images/brand/servitech-crest-outlined-192.webp') }}" alt="" aria-hidden="true" class="tala-brand__crest tala-crest-outlined" width="56" height="56" decoding="async">
    @else
        <span class="tala-crest-plate">
            <img src="{{ asset('images/brand/servitech-crest.webp') }}" alt="" aria-hidden="true" class="tala-brand__crest" width="48" height="48">
        </span>
    @endif
    <span class="tala-brand__text">
        <span class="tala-brand__name"><span class="tala-brand__school">Servitech</span> <span class="tala-brand__institution">Institute Asia</span></span>
        @if (filled($person))
            <span class="tala-brand__person" data-tala-brand-person><span class="fi-sr-only">Signed in as </span>{{ $person }}</span>
        @endif
    </span>
</span>
@endif
