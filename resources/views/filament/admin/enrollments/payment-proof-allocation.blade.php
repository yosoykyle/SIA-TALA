<div class="tala-context-inset accounting-proof-allocation" aria-live="polite">
    <h3>How this payment will be applied</h3>
    @if ($allocationError)
        <p>{{ $allocationError }}</p>
    @elseif ($allocation === [])
        <p>Enter the independently verified amount to see how it will be applied.</p>
    @else
        @include('filament.admin.enrollments.accounting-payment-effect', ['rows' => $allocation])
    @endif
</div>
