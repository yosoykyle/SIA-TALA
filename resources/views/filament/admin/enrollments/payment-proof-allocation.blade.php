<div class="accounting-proof-allocation" aria-live="polite">
    <h3>How this payment will be applied</h3>
    @if ($allocationError)
        <p>{{ $allocationError }}</p>
    @elseif ($allocation === [])
        <p>Enter the independently verified amount to see how it will be applied.</p>
    @else
        @foreach ($allocation as $row)
            <div><strong>{{ $row['label'] }}</strong><p>PHP {{ number_format((float) $row['applied'], 2) }} applied · PHP {{ number_format((float) $row['remaining'], 2) }} remaining</p></div>
        @endforeach
    @endif
</div>
