@props(['name', 'number', 'program' => null, 'photoUrl' => null, 'initials' => null, 'headingLevel' => 'h2', 'numberLabel' => null, 'showNumberLabel' => false])
@php($headingTag = $headingLevel === 'h1' ? 'h1' : 'h2')
<header {{ $attributes->class(['tala-record-identity', 'tala-identity-surface']) }}>
    @if ($program || $initials)
        @include('filament.components.learner-journey-art', ['artClass' => 'student-identity-art', 'artAlignment' => 'xMaxYMax meet'])
    @endif
    <div class="tala-record-identity-person">
        @isset($avatar){{ $avatar }}@else
            @if ($initials)<div class="student-profile-avatar" aria-hidden="true" x-data="{ failed: false }">@if ($photoUrl)<img src="{{ $photoUrl }}" alt="" x-show="! failed" x-on:error="failed = true"><span x-show="failed" x-cloak>{{ $initials }}</span>@else{{ $initials }}@endif</div>@endif
        @endisset
        <div>
            @isset($status)<div class="tala-record-identity-heading">@endisset
            @isset($heading)
                <{{ $headingTag }} {{ $heading->attributes }}>{{ $heading }}</{{ $headingTag }}>
            @else
                <{{ $headingTag }}>{{ $name }}</{{ $headingTag }}>
            @endisset
            @isset($status){{ $status }}</div>@endisset
            @if ($program)<p>{{ $program }}</p>@endif
            @if (filled($number))<p>@if ($numberLabel)<span @class(['sr-only' => ! $showNumberLabel, 'tala-record-identity-number-label' => $showNumberLabel])>{{ $numberLabel }}@unless ($showNumberLabel): @endunless</span>@endif{{ $number }}</p>@endif
            @isset($supporting){{ $supporting }}@endisset
        </div>
    </div>
    @isset($details){{ $details }}@endisset
    @isset($actions)<div class="tala-record-actions">{{ $actions }}</div>@endisset
</header>
