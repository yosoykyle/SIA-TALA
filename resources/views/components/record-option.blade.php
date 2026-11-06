@props(['selected' => false, 'result' => '—', 'resultDescription' => null])
<button type="button" {{ $attributes->class(['tala-record-option']) }} aria-pressed="{{ $selected ? 'true' : 'false' }}">
    <span>{{ $slot }}</span>
    @isset($meta){{ $meta }}@else<span class="tala-record-option-result"><small>Result</small><strong>{{ $result ?? '—' }}</strong>@if ($resultDescription)<small>{{ $resultDescription }}</small>@endif</span>@endisset
    @isset($context)<span class="tala-record-option-context">{{ $context }}</span>@endisset
</button>
