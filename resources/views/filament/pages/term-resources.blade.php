<x-filament-panels::page>
    @include('filament.pages.partials.term-workspace-header')
    @if ($term)
        <section class="tala-task-work" aria-label="Faculty and rooms">
        <div class="tala-work-toolbar"><x-tala-navigation label="Teaching resources"><x-filament::tabs.item type="button" wire:click="chooseResourceView('faculty')" :active="$resourceView === 'faculty'">Faculty</x-filament::tabs.item><x-filament::tabs.item type="button" wire:click="chooseResourceView('rooms')" :active="$resourceView === 'rooms'">Rooms</x-filament::tabs.item></x-tala-navigation>
            @if ($resourceView === 'faculty' && ! $readOnly)<div class="flex flex-wrap gap-2">{{ $this->requestFacultyAvailabilityAction }} {{ $this->recordFacultyDeadlineAction->color('gray') }}</div>@endif
        </div>
        @if ($resourceView === 'faculty')
            @if (! $activePackage)<p class="text-sm" role="status">Activate the Term calendar before requesting availability. <x-filament::link :href="\App\Filament\Pages\TermCalendar::getUrl(['termId' => $term->id])">Calendar</x-filament::link></p>
            @elseif (! $facultyDeadline)<p class="text-sm" role="status">Set a reply deadline before sending requests.</p>@endif
            <div class="tala-work-summary"><p class="text-sm">Reply by: {{ $facultyDeadline?->copy()->timezone('Asia/Manila')->format('M j, Y g:i A') ?? 'Not recorded' }} (Asia/Manila)</p><p class="text-sm">{{ $missingDeclarations }} eligible Faculty awaiting response · {{ $missingQualifications }} offered courses without eligible qualification</p></div><p class="mb-4 text-sm text-gray-600 dark:text-gray-300">Faculty teaching-load default: {{ $term->default_max_units === null ? 'Not recorded' : $term->default_max_units.' units' }}. Active Faculty load exceptions take precedence.</p>@else<p class="mb-4 text-sm text-gray-600 dark:text-gray-300">Active rooms available for planning. Weekly unavailable times also constrain meetings.</p>@endif
        {{ $this->table }}
        </section>
    @else<p>Select a Term to review its Faculty inputs and shared rooms.</p>@endif
    <x-filament-actions::modals />
</x-filament-panels::page>
