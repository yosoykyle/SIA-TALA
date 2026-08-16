<div class="space-y-4">
    @forelse ($applications as $application)
        <article class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <h3 class="font-semibold">{{ $application->application_reference }}</h3>
            <p>{{ collect([$application->first_name, $application->middle_name, $application->last_name])->filter()->implode(' ') }}</p>
            <p class="text-sm text-gray-600 dark:text-gray-300">
                {{ $application->program?->name }} · {{ str($application->application_path)->headline() }} · {{ $application->admissionCycle?->label }}
            </p>
            <p class="mt-2 text-sm">Derived ready from the current decision and due official credential results.</p>
        </article>
    @empty
        <x-filament::callout color="gray" icon="heroicon-m-information-circle">
            <x-slot name="heading">No ready applicants</x-slot>
            <x-slot name="description">Applications appear automatically when the derived Admissions projection is ready.</x-slot>
        </x-filament::callout>
    @endforelse
</div>
