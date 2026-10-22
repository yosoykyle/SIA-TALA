<x-filament-panels::page>
    @assets<link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">@endassets
    @php
        $money = fn ($value) => $value === null ? 'Unavailable' : 'PHP '.number_format((float) $value, 2);
        $date = fn ($value) => $value === null ? 'Not recorded' : \Carbon\CarbonImmutable::parse($value)->timezone(config('app.display_timezone'))->format('M j, Y · g:i A');
    @endphp
    @if ($this->showHistory)
        @include('filament.student.pages.finance-history')
    @else
        @if ($pastAccounts->isNotEmpty())
            <x-filament::link :href="static::getUrl(['view' => 'history'])">Past statements and payments</x-filament::link>
        @endif
        @include('filament.student.pages.finance-account')
    @endif
</x-filament-panels::page>
