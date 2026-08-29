<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Exact Term</x-slot>
            <x-slot name="description">
                Your declaration belongs to one Term only. It is never copied to another Term and does not edit a published timetable by itself.
            </x-slot>

            <div class="flex flex-wrap gap-2">
                @forelse ($terms as $option)
                    <x-filament::button
                        :color="$term?->id === $option->id ? 'primary' : 'gray'"
                        :outlined="$term?->id !== $option->id"
                        type="button"
                        wire:click="selectTerm({{ $option->id }})"
                    >
                        {{ $option->academicYear?->label }} · {{ $option->label }}
                    </x-filament::button>
                @empty
                    <p class="text-sm text-gray-600 dark:text-gray-300">No Term is available. Contact the Registrar; Faculty cannot create a Term from this page.</p>
                @endforelse
            </div>
        </x-filament::section>

        @if ($term)
            <div class="grid gap-4 md:grid-cols-3">
                <x-filament::section compact>
                    <x-slot name="heading">Request state</x-slot>
                    <p class="text-sm text-gray-700 dark:text-gray-200">
                        {{ $availabilityRequested ? 'Action requested by the Registrar' : ($hasDeclaration ? 'Declaration already recorded' : 'No request for this exact Term') }}
                    </p>
                </x-filament::section>
                <x-filament::section compact>
                    <x-slot name="heading">Due date</x-slot>
                    <p class="text-sm text-gray-700 dark:text-gray-200">
                        {{ $availabilityDueAt?->timezone(config('app.display_timezone'))->format('M j, Y g:i A') ?? 'Unavailable — contact the Registrar' }}
                    </p>
                </x-filament::section>
                <x-filament::section compact>
                    <x-slot name="heading">Institutional capacity evidence</x-slot>
                    <p class="text-sm text-gray-700 dark:text-gray-200">
                        {{ $qualificationCount }} active course qualification(s) · {{ $termUnitLimit !== null ? $termUnitLimit.' unit limit' : 'No unit limit recorded' }}
                    </p>
                </x-filament::section>
            </div>

            @if (! $availabilityRequested && ! $hasDeclaration)
                <x-filament::section icon="heroicon-o-information-circle" icon-color="warning">
                    <x-slot name="heading">Availability request not available</x-slot>
                    <x-slot name="description">
                        The Registrar must issue the exact-Term action request before your first declaration. No schedule or role is inferred from this unavailable state.
                    </x-slot>
                </x-filament::section>
            @endif
        @endif

        @if ($hasPublishedImpact)
            <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning">
                <x-slot name="heading">Published timetable review required</x-slot>
                <x-slot name="description">
                    Your latest correction conflicts with at least one current assignment. The published timetable remains official until the Registrar validates and publishes a complete successor.
                </x-slot>
            </x-filament::section>
        @endif

        {{ $this->table }}

        <x-filament::section compact>
            <x-slot name="heading">Historical unavailable-time records</x-slot>
            <x-slot name="description">
                Earlier scheduling-block records remain read-only evidence. New Faculty declarations use this page.
            </x-slot>
            <x-filament::link :href="$historicalBlocksUrl">View historical records</x-filament::link>
        </x-filament::section>
    </div>
</x-filament-panels::page>
