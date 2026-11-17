@props(['label', 'records' => false])
<div {{ $attributes->class(['tala-data-table-scroll']) }} role="region" aria-label="{{ $label }}" tabindex="0">
    <table @class(['tala-data-table', 'tala-data-table--records' => $records]) @if ($records) role="table" @endif>{{ $slot }}</table>
</div>
