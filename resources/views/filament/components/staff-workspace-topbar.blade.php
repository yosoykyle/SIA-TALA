@props(['workspace', 'brand', 'person' => null, 'studentNumber' => null])

<header class="tala-staff-topbar" data-tala-workspace="{{ $workspace }}">
    <div class="tala-staff-topbar__identity">{{ $brand }}</div>

    @if (filled($person))
        <p class="tala-staff-topbar__person" data-tala-brand-person>
            <x-filament::icon icon="heroicon-o-user-circle" class="tala-staff-topbar__person-icon" />
            <span class="fi-sr-only">Signed in as </span>
            <span class="tala-staff-topbar__person-name">{{ $person }}</span>
            @if (filled($studentNumber))
                <span class="tala-topbar-student-number" data-tala-student-number>
                    <span class="fi-sr-only">Student number </span>{{ $studentNumber }}
                </span>
            @endif
        </p>
    @endif

    <div class="tala-staff-topbar__end">
        @include('filament.components.applicant-clock')
        @include('filament.components.applicant-clock', ['variant' => 'compact'])
    </div>
</header>
