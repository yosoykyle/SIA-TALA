<x-servitech-task-state class="{{ $guidanceClass ?? '' }}" :heading="$guidanceHeading ?? $effect['label']" :description="$guidanceReason ?? $effect['student_reason']" :scope="$effect['scope_label']" office="Registrar Office" attention aria-label="Enrollment guidance">
    <x-filament::button tag="a" color="gray" :href="$enrollmentUrl" icon="heroicon-m-arrow-right" icon-position="after">{{ $guidanceActionLabel ?? 'Review enrollment' }}</x-filament::button>
</x-servitech-task-state>
