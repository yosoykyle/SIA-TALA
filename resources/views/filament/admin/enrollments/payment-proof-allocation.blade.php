<div class="tala-context-inset accounting-proof-allocation" aria-live="polite">
    <h3>How this payment will be applied</h3>
    @if ($allocationError)
        <p>{{ $allocationError }}</p>
    @elseif ($allocation === [])
        <p>Enter the independently verified amount to see how it will be applied.</p>
    @else
        @include('filament.admin.enrollments.accounting-payment-effect', ['rows' => $allocation])
        @if (($autoEnrollPreview ?? null) !== null)
            <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700" role="status">
                @if ($autoEnrollPreview['state'] === 'ready')
                    <x-filament::badge color="success" icon="heroicon-o-check-circle">Auto-enroll ready</x-filament::badge>
                    <p class="mt-1 text-sm">All enrollment checks are ready — verifying this payment will automatically enroll the student and issue their COR.</p>
                @elseif ($autoEnrollPreview['state'] === 'blocked')
                    <x-filament::badge color="warning" icon="heroicon-o-exclamation-triangle">Needs Registrar attention after posting</x-filament::badge>
                    <p class="mt-1 text-sm">Payment will be posted, but automatic enrollment is blocked by: {{ implode(', ', $autoEnrollPreview['blockers'] ?? []) }}. The case will route to Registrar → Resolve enrollment issues.</p>
                @elseif ($autoEnrollPreview['state'] === 'partial')
                    <x-filament::badge color="gray" icon="heroicon-o-information-circle">Partial payment</x-filament::badge>
                    <p class="mt-1 text-sm">Payment will be posted. PHP {{ number_format((float) $autoEnrollPreview['remaining'], 2) }} still due before automatic enrollment.</p>
                @endif
            </div>
        @endif
    @endif
</div>
