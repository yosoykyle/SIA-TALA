<x-filament::section>
    <x-slot name="heading">Plan this Term</x-slot>
    <x-slot name="description">Prepare the teaching inputs, review a timetable candidate, then publish the approved version. Classes and teaching resources can be prepared together.</x-slot>
    <div class="tala-planning-next">
        <div><h3>{{ $nextTask['title'] }}</h3><p>{{ $nextTask['detail'] }}</p></div>
        <x-filament::button tag="a" :href="\App\Filament\Pages\TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => $nextTask['tab']])">Open {{ $tabs[$nextTask['tab']] }}</x-filament::button>
    </div>
    <dl class="tala-planning-facts">
        <div><dt>Teaching calendar</dt><dd>{{ $activePackage ? 'Active v'.$activePackage->version : 'Not active' }} @if ($draftPackages->isNotEmpty()) · Draft to review @endif</dd></div>
        <div><dt>Classes</dt><dd>{{ $counts['confirmed'] }} confirmed of {{ $sourceClasses->count() }} recorded</dd></div>
        <div><dt>Faculty responses</dt><dd>{{ $facultyRecords->count() - $missingDeclarations }} of {{ $facultyRecords->count() }} eligible Faculty recorded</dd></div>
        <div><dt>Official timetable</dt><dd>{{ $currentVersion ? 'Published v'.$currentVersion->version.' · '.$currentVersion->meetings_count.' meetings' : 'Not published' }}</dd></div>
    </dl>
    @if (count($needsAttention) > 1)
        <h3 class="mt-6 font-semibold">Also needs attention</h3>
        <ul class="mt-2 divide-y divide-gray-200 dark:divide-white/10">
            @foreach (array_slice($needsAttention, 1) as $task)<li><a class="tala-planning-record-link" href="{{ \App\Filament\Pages\TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => $task['tab']]) }}"><span>{{ $task['title'] }}<small>{{ $task['detail'] }}</small></span><x-filament::icon icon="heroicon-m-arrow-right" class="size-5 shrink-0" /></a></li>@endforeach
        </ul>
    @endif
    <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">These are recorded preparation facts. Generation checks the complete captured inputs; a published timetable remains official until replaced through review.</p>
</x-filament::section>
