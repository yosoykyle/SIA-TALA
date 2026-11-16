@props(['heading'])
<header {{ $attributes->class(['tala-record-browser-header', 'tala-record-collection-controls']) }}>
    <div class="tala-record-collection-heading"><h2>{{ $heading }}</h2></div>
    @if ((isset($search) && trim((string) $search) !== '') || (isset($filters) && trim((string) $filters) !== ''))
        <div class="tala-record-collection-search">
            @if (isset($search) && trim((string) $search) !== '')
                <div class="tala-record-collection-search__field">{{ $search }}</div>
            @endif
            @if (isset($filters) && trim((string) $filters) !== '')
                {{ $filters }}
            @endif
        </div>
    @endif
    {{ $slot }}
</header>
