@php
    $guidanceHref = $actionUrl ?? $enrollmentUrl ?? null;
    $guidanceButtonLabel = $guidanceActionLabel ?? $actionLabel ?? null;
@endphp
<x-servitech-task-state class="{{ $guidanceClass ?? '' }}" :heading="$guidanceHeading ?? $effect['label']" :description="$guidanceReason ?? $effect['student_reason']" :scope="$effect['scope_label']" office="Registrar Office" attention aria-label="Enrollment guidance">
    @if (filled($guidanceHref) && filled($guidanceButtonLabel))
        <x-filament::button tag="a" color="gray" :href="$guidanceHref" icon="heroicon-m-arrow-right" icon-position="after">{{ $guidanceButtonLabel }}</x-filament::button>
    @endif
</x-servitech-task-state>
