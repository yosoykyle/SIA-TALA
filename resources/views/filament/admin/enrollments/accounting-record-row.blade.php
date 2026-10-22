@php
    $case = $getRecord();
    $row = \App\Filament\Resources\Enrollments\Tables\StudentAccountsTable::rowData($case, $getLivewire()->activeTab);
    $name = $case->credentialUser?->getFilamentName() ?? 'Identity unavailable';
@endphp
<article class="accounting-account-record">
    <div class="accounting-account-person">
        <h2>{{ $name }}</h2>
        <p>{{ $case->studentProfile?->student_number ?? $case->admissionApplication?->application_reference ?? 'Number not assigned' }}</p>
    </div>
    <div class="accounting-account-program">
        <p>{{ $row['program'] }}</p>
        <p>{{ $case->term?->label ?? 'Term unavailable' }}</p>
    </div>
    <div class="accounting-account-status">
        <x-filament::badge :color="$row['color']">{{ $row['status'] }}</x-filament::badge>
        @if ($row['detail'])<p>{{ $row['detail'] }}</p>@endif
    </div>
    <x-filament::button tag="a" :href="\App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('view', ['record' => $case])" color="gray" icon="heroicon-m-arrow-right" icon-position="after">
        {{ $row['action'] }}<span class="sr-only"> for {{ $name }}</span>
    </x-filament::button>
</article>
