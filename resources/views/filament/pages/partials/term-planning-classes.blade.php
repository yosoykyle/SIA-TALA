<x-filament::section>

    <x-slot name="heading">Prepare the classes to schedule</x-slot>

    <x-slot name="description">Choose the courses offered this Term, create their classes, then confirm the learners and approved class facts.</x-slot>

    @if (! $activePackage)<p class="mb-4 text-sm">Activate the teaching calendar before preparing or confirming classes. <x-filament::link :href="\App\Filament\Pages\TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => 'overview'])">Open Calendar</x-filament::link></p>@endif

    <div class="flex flex-wrap gap-3 mb-5">

        <x-filament::button tag="a" color="gray" :href="$classSources->firstWhere('resource', \App\Filament\Resources\TermOfferings\TermOfferingResource::class)['url']">Choose courses &amp; classes</x-filament::button>

        @if (! $readOnly && $offerings->isNotEmpty())<x-filament::button tag="a" :href="\App\Filament\Resources\Sections\SectionResource::getUrl('create', ['termId' => $term->id])">New class section</x-filament::button>@endif
        @if (! $readOnly){{ $this->recordCohortAction->label('Record learner cohort')->color('gray') }}@endif

    </div>

    @if ($sourceClasses->isNotEmpty())

        <div class="overflow-x-auto"><table class="tala-planning-table"><caption class="sr-only">Class preparation for {{ $term->label }}</caption><thead><tr><th>Class &amp; course</th><th>Learners / capacity</th><th>Preparation</th><th>Next action</th></tr></thead><tbody>

            @foreach ($sourceClasses as $class)

                @php($isCurrent = $contextClasses->contains('id', $class->id))

                @php($classDemands = $demands->filter(fn ($demand) => $demand->sectionDeliveryGroup?->section_id === $class->id))

                <tr wire:key="planning-class-{{ $class->id }}"><th scope="row">{{ $class->class_reference ?: $class->code }}<span>{{ $class->courseRevision?->course?->code ?? $class->termOffering->course()?->code }} · {{ $class->courseRevision?->title ?? $class->termOffering->courseSpecification()?->title }}</span></th>

                <td>{{ $class->cohorts->sum('pivot.expected_count') }} / {{ $class->capacity }}<span>{{ $class->cohorts->pluck('reference')->join(', ') ?: 'No confirmed cohort linked' }}</span></td>

                <td>{{ $class->confirmed_at && $isCurrent ? 'Confirmed' : ($class->confirmed_at ? 'Earlier teaching calendar' : ($class->term_calendar_package_id && $isCurrent ? 'Prepared · confirm learners' : 'Prepare approved facts')) }}<span>{{ $classDemands->count() }} meeting requirement(s) · {{ $classDemands->where('validation_state', \App\Models\SchedulingDemand::ValidationActionRequired)->count() }} with findings</span></td>

                <td>@if (! $readOnly && $activePackage && ! $class->confirmed_at)

                    @if ($class->term_calendar_package_id && $isCurrent){{ ($this->confirmClassAction)(['sectionId' => $class->id]) }}@else{{ ($this->prepareClassOfferingAction->label('Prepare class')->color('gray'))(['sectionId' => $class->id]) }}@endif

                    @elseif (! $readOnly && $activePackage && $class->confirmed_at && ! $isCurrent)<x-filament::link :href="\App\Filament\Resources\Sections\SectionResource::getUrl('create', ['termId' => $term->id])">Create replacement class</x-filament::link><span>The teaching calendar changed.</span>
                    @else<x-filament::link :href="\App\Filament\Resources\Sections\SectionResource::getUrl('view', ['record' => $class, 'termId' => $term->id])">View class</x-filament::link>@endif</td></tr>

            @endforeach

        </tbody></table></div>

    @else

        <p class="text-sm text-gray-600 dark:text-gray-300">No classes are recorded for this Term. Choose courses above, then create their sections and delivery groups.</p>

    @endif

    <details class="tala-planning-details"><summary>Meeting requirements and class setup</summary><div class="space-y-3 mt-3">

        @foreach ($classSources as $source)<p><x-filament::link :href="$source['url']">{{ $source['label'] }}</x-filament::link> · {{ $source['description'] }}</p>@endforeach

        <p>{{ $cohorts->count() }} learner cohort(s) recorded; {{ $demands->count() }} meeting requirement(s). Generation validates the current sources.</p>

        @if (! $readOnly){{ $this->manageTeachingAssignmentAction->label('Assign grading roster Faculty')->color('gray')->outlined() }}<p class="text-sm">Roster assignment determines who records grades for confirmed classes; timetable generation chooses from eligible teaching Faculty.</p>@endif

    </div></details>

</x-filament::section>

