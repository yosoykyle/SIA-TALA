<x-filament::section>

    <x-slot name="heading">Prepare the people and rooms</x-slot>

    <x-slot name="description">Faculty must be qualified for the offered courses and record their availability for this Term. On-campus meetings also need suitable rooms.</x-slot>

    @if (! $activePackage)<p class="mb-4 text-sm">Activate Calendar before recording the response deadline or sending availability requests. <x-filament::link :href="\App\Filament\Pages\TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => 'overview'])">Open Calendar</x-filament::link></p>@endif
    <div class="tala-planning-next"><div><h3>Faculty availability</h3><p>{{ $facultyDeadline ? 'Responses due '.$facultyDeadline->timezone('Asia/Manila')->format('M j, Y · g:i A').' (Asia/Manila)' : 'Set the response deadline before sending a request.' }}</p></div>

        @if (! $readOnly && $activePackage)<div class="flex flex-wrap gap-3">{{ $this->recordFacultyDeadlineAction->label($facultyDeadline ? 'Update deadline' : 'Set deadline')->color('gray') }} @if ($facultyDeadline){{ $this->requestFacultyAvailabilityAction }}@endif</div>@endif

    </div>

    @if ($missingQualifications)<p class="mt-4 text-sm">{{ $missingQualifications }} offered course(s) have no active Faculty qualification. <x-filament::link :href="\App\Filament\Resources\FacultyQualifications\FacultyQualificationResource::getUrl('index', ['termId' => $term->id])">Record qualifications</x-filament::link></p>@endif

    @if ($facultyRecords->isNotEmpty())

        <div class="overflow-x-auto mt-4"><table class="tala-planning-table"><caption class="sr-only">Faculty qualified for this Term's courses</caption><thead><tr><th>Faculty</th><th>Qualified courses</th><th>Term response</th></tr></thead><tbody>

            @foreach ($facultyRecords as $row)<tr><th scope="row">{{ $row['faculty']->name }}</th><td>{{ implode(', ', $row['courses']) }}</td><td>{{ $row['declaration'] ? ($row['declaration']->declaration === \App\Models\FacultyAvailabilityDeclaration::DeclarationUnavailable ? 'Unavailable this Term' : 'Availability recorded') : 'Awaiting Faculty response' }}@if ($row['declaration'])<span>v{{ $row['declaration']->version }} · {{ $row['declaration']->declared_at->timezone('Asia/Manila')->format('M j, g:i A') }} · {{ count($row['declaration']->hard_unavailability ?? []) }} unavailable period(s)</span>@endif</td></tr>@endforeach

        </tbody></table></div>

    @else<p class="mt-4 text-sm text-gray-600 dark:text-gray-300">Choose this Term's courses in Classes to see their eligible Faculty here.</p>@endif

    <h3 class="mt-6 font-semibold">Available room records</h3>

    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Active room records are shared across Terms. Capacity, room type, equipment and unavailable times still need to suit each meeting.</p>

    <div class="overflow-x-auto mt-3"><table class="tala-planning-table"><thead><tr><th>Room</th><th>Type</th><th>Seats</th><th>Setup</th></tr></thead><tbody>

        @forelse ($rooms as $room)<tr><th scope="row">{{ $room->code }}<span>{{ $room->name }}</span></th><td>{{ \App\Models\Room::typeOptions()[$room->room_type] ?? $room->room_type }}</td><td>{{ $room->capacity }}</td><td><x-filament::link :href="\App\Filament\Resources\Rooms\RoomResource::getUrl('view', ['record' => $room, 'termId' => $term->id])">View room</x-filament::link></td></tr>@empty<tr><td colspan="4">No active room records. <x-filament::link :href="$destinations['resources']">Add rooms</x-filament::link></td></tr>@endforelse

    </tbody></table></div>

    <details class="tala-planning-details"><summary>Qualifications, load limits and unavailable times</summary><div class="tala-planning-reveal mt-3 space-y-3">@foreach ($resourceSources as $source)<p><x-filament::link :href="$source['url']">{{ $source['label'] }}</x-filament::link> · {{ $source['description'] }}</p>@endforeach</div></details>

</x-filament::section>

