<dl class="tala-context-inset tala-context-inset--split">
    <div>
        <dt>Program</dt>
        <dd>{{ $reviewed['program'] }}</dd>
    </div>
    <div>
        <dt>Term</dt>
        <dd>{{ $reviewed['term'] }}@if (filled($reviewed['version'] ?? null)) · Version {{ $reviewed['version'] }}@endif</dd>
    </div>
</dl>
