@props(['brand', 'person' => null])

<header class="tala-applicant-topbar">
    <div class="tala-applicant-topbar__identity">{{ $brand }}</div>

    @if (filled($person))
        <p class="tala-applicant-topbar__person" data-tala-brand-person>
            <x-filament::icon icon="heroicon-o-user-circle" class="tala-applicant-topbar__person-icon" />
            <span class="fi-sr-only">Signed in as </span>
            <span class="tala-applicant-topbar__person-name">{{ $person }}</span>
        </p>
    @endif

    <div class="tala-applicant-topbar__end">
        @include('filament.components.applicant-clock')
        @include('filament.components.applicant-clock', ['variant' => 'compact'])
    </div>
</header>
