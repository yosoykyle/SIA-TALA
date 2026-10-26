<x-filament-panels::page class="tala-term-workspace tala-resource-workspace">
    @include('filament.pages.partials.term-workspace-header')
    @if ($term)
        <section class="tala-task-work" aria-label="Faculty and rooms">
            <div class="tala-work-toolbar">
                <p class="tala-work-summary">{{ $resourceView === 'faculty' ? 'Faculty linked to this Term’s courses, requests or assignments.' : 'Room capacity and equipment from the shared catalog.' }}</p>
                <x-filament::button color="gray" :icon="$resourceView === 'faculty' ? 'heroicon-o-building-office' : 'heroicon-o-user-group'" wire:click="chooseResourceView('{{ $resourceView === 'faculty' ? 'rooms' : 'faculty' }}')">{{ $resourceView === 'faculty' ? 'View rooms' : 'View faculty' }}</x-filament::button>
            </div>
            @if ($resourceView === 'faculty')
                @if (! $activePackage)
                    <div class="tala-task-finding" role="status"><p>{{ $readOnly ? 'The Registrar must activate the teaching calendar before availability can be requested.' : 'Activate the teaching calendar before requesting availability.' }}</p><x-filament::button tag="a" color="gray" icon="heroicon-o-calendar-days" :href="\App\Filament\Pages\TermCalendar::getUrl(['termId' => $term->id])">Review calendar</x-filament::button></div>
                @elseif (! $facultyDeadline)
                    <div class="tala-task-finding"><div><strong>{{ $readOnly ? 'No reply deadline recorded' : 'Set a reply deadline' }}</strong><p>{{ $readOnly ? 'The Registrar records a deadline before sending availability requests.' : 'Faculty need this Term’s teaching calendar and a deadline before requests can be sent.' }}</p></div>@if (! $readOnly){{ $this->recordFacultyDeadlineAction->icon('heroicon-o-clock') }}@endif</div>
                @else
                    <div class="tala-work-toolbar"><div class="tala-work-summary"><p>Reply by {{ $facultyDeadline->copy()->timezone('Asia/Manila')->format('M j, Y · g:i A') }} · Asia/Manila</p></div>@if (! $readOnly)<div class="tala-task-commands">{{ $this->recordFacultyDeadlineAction->color('gray')->icon('heroicon-o-clock') }}{{ $this->requestFacultyAvailabilityAction }}</div>@endif</div>
                @endif
                <p class="tala-work-footnote">
                    This Term: {{ $termFacultyCount }} faculty · {{ $facultyResponseCount }} {{ \Illuminate\Support\Str::plural('reply', $facultyResponseCount) }} recorded
                    @if ($awaitingRequestedResponses) · {{ $awaitingRequestedResponses }} awaiting response @endif
                    @if ($notRequestedResponses) · {{ $notRequestedResponses }} not requested @endif
                </p>
                @if ($missingQualifications)
                    <div class="tala-task-finding" role="status"><p>{{ $missingQualifications }} offered {{ \Illuminate\Support\Str::plural('course', $missingQualifications) }} need a qualified teacher.</p><x-filament::button tag="a" color="gray" icon="heroicon-o-academic-cap" :href="\App\Filament\Resources\FacultyQualifications\FacultyQualificationResource::getUrl('index', ['termId' => $term->id])">Review qualifications</x-filament::button></div>
                @endif
            @endif
            {{ $this->table }}
            <details class="tala-planning-coverage">
                <summary>{{ $resourceView === 'faculty' ? 'Teaching limits and unavailable times' : 'Recurring unavailable times' }}</summary>
<div class="tala-planning-reveal">
                @if ($resourceView === 'faculty')
                    <div class="tala-planning-source-row"><div><strong>Default teaching limit</strong><p>{{ $term->default_max_units === null ? 'Not recorded' : $term->default_max_units.' units per teacher' }} · Faculty-specific limits take precedence.</p></div>@if (! $readOnly)<x-filament::button tag="a" color="gray" icon="heroicon-o-pencil-square" :href="\App\Filament\Resources\Terms\TermResource::getUrl('edit', ['record' => $term->id, 'viewTab' => 'resources', 'resourceView' => 'faculty'])">Edit default</x-filament::button>@endif</div>
                    @if (\App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource::canViewAny())
                        <div class="tala-planning-source-row"><div><strong>Faculty-specific teaching limits</strong><p>Review approved exceptions for this Term.</p></div><x-filament::button tag="a" color="gray" icon="heroicon-o-adjustments-horizontal" :href="\App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource::getUrl('index', ['termId' => $term->id])">Review exceptions</x-filament::button></div>
                    @endif
                @endif
                <div class="tala-planning-source-row"><div><strong>Weekly unavailable times</strong><p>{{ $resourceView === 'faculty' ? 'Recurring exclusions are separate from the Faculty reply and dated calendar exceptions.' : 'An active catalog entry does not mean a room is free at every time.' }}</p></div>@if (\App\Filament\Resources\CalendarEvents\CalendarEventResource::canViewAny() && ($resourceView === 'faculty' || ! $readOnly))<x-filament::button tag="a" color="gray" icon="heroicon-o-clock" :href="\App\Filament\Resources\CalendarEvents\CalendarEventResource::getUrl('index', ['termId' => $term->id, 'resourceView' => $resourceView])">Review unavailable times</x-filament::button>@endif</div>

</div>
</details>
        </section>
    @else<p>Select a Term to review teaching availability and rooms.</p>@endif
    <x-filament-actions::modals />
</x-filament-panels::page>
