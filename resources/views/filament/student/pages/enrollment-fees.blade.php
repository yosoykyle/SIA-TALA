<aside class="student-enrollment-fees tala-task-surface" aria-label="Enrollment payment">
    <h2>Enrollment payment</h2>
    <p class="student-enrollment-amount">{{ $money($paymentRequirement['balance'] ?? null) }}</p>
    <dl class="learner-money">
        <div><dt>Total term fees</dt><dd>{{ $money($assessment?->total) }}</dd></div>
        @if ((float) ($paymentRequirement['payment_applied'] ?? 0) > 0)<div><dt>Verified payment</dt><dd>{{ $money($paymentRequirement['payment_applied']) }}</dd></div>@endif
        @if ((float) ($paymentRequirement['coverage_applied'] ?? 0) > 0)<div><dt>Approved fee assistance</dt><dd>{{ $money($paymentRequirement['coverage_applied']) }}</dd></div>@endif
    </dl>
    @if (! $confirmed)<p>No payment action is needed while your classes are being prepared.</p>@endif
    @if ($assessment)
        <details class="learner-disclosure"><summary>Fee breakdown</summary><div><dl class="learner-money">@foreach ($assessment->lines as $line)<div wire:key="assessment-line-{{ $line->id }}"><dt>{{ $line->description_snapshot }}</dt><dd>{{ $money($line->amount) }}</dd></div>@endforeach</dl></div></details>
    @endif
    @if ($isStudent)<x-filament::link :href="\App\Filament\Student\Pages\Finance::getUrl(panel: 'student')">View fees and payments</x-filament::link>@endif
</aside>
