@php
    $case = $getRecord();
    $row = \App\Filament\Resources\Enrollments\Tables\StudentAccountsTable::rowData($case, $getLivewire()->activeTab);
@endphp
<div class="accounting-account-status">
    <x-filament::badge :color="$row['color']">{{ $row['status'] }}</x-filament::badge>
    @if ($row['detail'])<p>{{ $row['detail'] }}</p>@endif
</div>
