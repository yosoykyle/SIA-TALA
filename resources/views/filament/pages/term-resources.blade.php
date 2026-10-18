<x-filament-panels::page class="tala-term-workspace">
    @include('filament.pages.partials.term-workspace-header')
    @if ($term)
        <section class="tala-task-work" aria-label="Faculty and rooms">
            <div class="tala-work-toolbar"><x-tala-navigation label="Teaching resources"><x-filament::tabs.item type="button" wire:click="chooseResourceView('faculty')" :active="$resourceView === 'faculty'" :aria-pressed="$resourceView === 'faculty'">Faculty</x-filament::tabs.item><x-filament::tabs.item type="button" wire:click="chooseResourceView('rooms')" :active="$resourceView === 'rooms'" :aria-pressed="$resourceView === 'rooms'">Rooms</x-filament::tabs.item></x-tala-navigation></div>
            @if ($resourceView === 'faculty')
                @if (! $activePackage)
                    <div class="tala-guide-info tala-guide-warning" role="status"><x-filament::icon icon="heroicon-o-exclamation-circle" /><div>Activate the teaching calendar before requesting availability. <x-filament::link :href="\App\Filament\Pages\TermCalendar::getUrl(['termId' => $term->id])">Review the calendar</x-filament::link></div></div>
                @elseif (! $facultyDeadline)
                    <div class="tala-task-finding"><div><strong>Set a reply deadline before sending requests.</strong><p>Faculty will use this Term’s teaching calendar to record their availability.</p></div>@if (! $readOnly){{ $this->recordFacultyDeadlineAction }}@endif</div>
                @else
                    <div class="tala-work-toolbar"><div class="tala-work-summary"><p>Reply deadline: {{ $facultyDeadline->copy()->timezone('Asia/Manila')->format('M j, Y g:i A') }} (Asia/Manila)</p><p>{{ $awaitingRequestedResponses }} awaiting response · {{ $notRequestedResponses }} not requested</p></div>@if (! $readOnly)<div class="tala-task-commands">{{ $this->recordFacultyDeadlineAction->color('gray') }}{{ $this->requestFacultyAvailabilityAction }}</div>@endif</div>
                @endif
                @if (! $facultyDeadline)<p class="tala-work-footnote">{{ $awaitingRequestedResponses }} awaiting response · {{ $notRequestedResponses }} not requested</p>@endif
                @if ($missingQualifications)<p class="tala-work-footnote" role="status">{{ $missingQualifications }} offered {{ \Illuminate\Support\Str::plural('course', $missingQualifications) }} need an eligible teacher. Review Faculty qualifications.</p>@endif
            @else<p class="tala-work-footnote">Review active rooms and their capacity. Record recurring restrictions through Weekly unavailable times.</p>@endif
            {{ $this->table }}
        </section>
    @else<p>Select a Term to review its Faculty inputs and shared rooms.</p>@endif
    <x-filament-actions::modals />
</x-filament-panels::page>
