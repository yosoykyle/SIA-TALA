@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
<div class="accounting-correction-context">
    @if (filled($identity))<p class="accounting-correction-identity">{{ $identity }}</p>@endif
    @if (filled($unavailable))
        <p class="accounting-correction-unavailable" role="status">{{ $unavailable }}</p>
    @else
        @if (filled($lead))<p class="accounting-correction-lead">{{ $lead }}</p>@endif
        @if ($facts !== [])
            <dl class="accounting-correction-facts">
                @foreach ($facts as $label => $value)
                    <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                @endforeach
            </dl>
        @endif
    @endif
</div>
