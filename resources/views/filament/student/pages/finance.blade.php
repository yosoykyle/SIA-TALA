<x-filament-panels::page>
    @assets<link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">@endassets
    @php
        $money = fn ($value) => $value === null ? 'Unavailable' : 'PHP '.number_format((float) $value, 2);
        $date = fn ($value) => $value === null ? 'Not recorded' : \Carbon\CarbonImmutable::parse($value)->timezone(config('app.display_timezone'))->format('M j, Y · g:i A');
        $dueDate = fn ($value) => $value === null ? 'Not recorded' : \Carbon\CarbonImmutable::parse($value)->timezone(config('app.display_timezone'))->format('M j, Y');
    @endphp
    <div class="student-finance-shell">
        @if ($pastAccounts->isNotEmpty() || $this->showHistory)
            <x-tala-navigation label="Fees sections" stretch class="student-workspace-nav">
                <x-filament::tabs.item
                    tag="a"
                    wire:navigate
                    :href="static::getUrl(panel: 'student')"
                    :active="! $this->showHistory"
                    icon="heroicon-o-banknotes"
                >
                    This term
                </x-filament::tabs.item>
                <x-filament::tabs.item
                    tag="a"
                    wire:navigate
                    :href="static::getUrl(['view' => 'history'], panel: 'student')"
                    :active="$this->showHistory"
                    icon="heroicon-o-clock"
                    aria-label="Past statements and payments"
                >
                    Past statements
                </x-filament::tabs.item>
            </x-tala-navigation>
        @endif
        @if ($this->showHistory)
            @include('filament.student.pages.finance-history')
        @else
            @include('filament.student.pages.finance-account')
        @endif
    </div>
</x-filament-panels::page>
