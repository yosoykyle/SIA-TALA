@props(['path' => null, 'continuing' => false])
{{-- The applicant's Student type, emphasized the same way on every case list (#99 Scope 10). --}}
@php
    $label = $continuing ? 'Continuing' : \App\Models\AdmissionCycle::studentTypeLabel($path);
    $color = $continuing ? 'gray' : \App\Models\AdmissionCycle::studentTypeColor($path);
    $icon = $continuing ? 'heroicon-m-user' : \App\Models\AdmissionCycle::studentTypeIcon($path);
@endphp
@if ($continuing || filled($path))
    <x-filament::badge :color="$color" :icon="$icon" size="md" {{ $attributes->merge(['class' => 'tala-student-type-badge']) }}>{{ $label }}</x-filament::badge>
@endif
