<x-filament-panels::page>
    <div class="tala-oversight-workflow">
        <section class="tala-planning-context" aria-label="Oversight scope">
            <div class="tala-term-picker">{{ $this->termSelection }}</div>
            <p class="tala-term-context-links text-sm">Read-only · Registrar owns these records</p>
        </section>
        <section aria-labelledby="oversight-term-heading">
            <h2 id="oversight-term-heading" class="sr-only">Selected Term evidence</h2>
            @if ($term)
                <dl>
                    <div class="tala-source-record"><div><dt>Teaching calendar</dt><dd>{{ $calendar ? 'Active version '.$calendar->version : 'No active calendar' }}</dd></div><x-filament::link :href="\App\Filament\Pages\TermCalendar::getUrl(['termId' => $termId])">Inspect calendar</x-filament::link></div>
                    <div class="tala-source-record"><div><dt>Timetable</dt><dd>{{ $timetable ? 'Official version '.$timetable->version : 'No published timetable' }}</dd></div><div class="tala-task-commands">
                        @if ($timetable)<x-filament::link :href="\App\Filament\Pages\TermTimetable::getUrl(['termId' => $termId, 'selectedVersionId' => $timetable->id])">View official timetable</x-filament::link>@endif
                        @if ($draft)<x-filament::link :href="\App\Filament\Pages\TermTimetable::getUrl(['termId' => $termId, 'selectedRunId' => $draft->id])">View draft evidence</x-filament::link>@endif
                    </div></div>
                    <div class="tala-source-record"><div><dt>Examination Period</dt><dd>{{ $examination['status'] === 'Available' ? $examination['opens_on']->format('M j, Y').' – '.$examination['closes_on']->format('M j, Y') : 'Examination Period unavailable' }} · Informational</dd></div>
                    <details class="text-sm"><summary class="cursor-pointer font-medium">Calendar source details</summary><p class="mt-2">{{ $examination['authority_reference'] ?: 'Authority not recorded' }} · Calendar v{{ $examination['package_version'] ?? 'Unavailable' }} · {{ $examination['owner'] }} · As of {{ $examination['as_of']->format('M j, Y g:i A') }} PHT</p><p>{{ $examination['message'] }}</p></details></div>
                </dl>
                @if ($attention)<section class="tala-oversight-findings" aria-label="Selected Term concerns">@foreach ($attention as $finding)<div class="tala-task-finding"><div><h3>{{ $finding['title'] }}</h3><p>{{ $finding['detail'] }}</p><p class="tala-work-footnote">Responsible: {{ $finding['owner'] }}</p></div><x-filament::link :href="$finding['url']">Inspect source</x-filament::link></div>@endforeach</section>@endif
                <div class="tala-task-commands mt-4">
                    <x-filament::link :href="\App\Filament\Pages\TermOverview::getUrl(['termId' => $termId])">Term Planning overview</x-filament::link>
                    <x-filament::link :href="\App\Filament\Pages\TermClasses::getUrl(['termId' => $termId])">Recorded classes</x-filament::link>
                    <x-filament::link :href="\App\Filament\Pages\TermResources::getUrl(['termId' => $termId])">Faculty & room evidence</x-filament::link>
                </div>
            @else<p class="mt-3 text-sm">{{ $terms->isEmpty() ? 'No Terms recorded. Registrar records Term setup.' : 'No exact Term is selected. Select a Term to inspect its calendar and timetable.' }}</p>@endif
        </section>
        <section class="tala-task-work" aria-labelledby="oversight-authority-heading">
            <div class="tala-task-heading"><div><h2 id="oversight-authority-heading">Academic Authority</h2><p>Curriculum evidence · Institution-wide · approved academic sources</p></div><x-filament::link :href="\App\Filament\Pages\CatalogCurriculaWorkbench::getUrl(['oversightTermId' => $termId])">Catalog & Curricula</x-filament::link></div>
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse ($authorityRows as $row)<div class="tala-evidence-row">
                    <div><h3 class="font-semibold"><x-filament::link :href="$row['url']">{{ $row['program']->name }}</x-filament::link></h3><p class="text-sm text-gray-600 dark:text-gray-300">{{ $row['program']->code }} · {{ $row['curriculum']?->version_code ?? 'No curriculum recorded' }} · Registrar</p></div>
                    <div><x-filament::badge :color="$row['readiness']['color']">{{ $row['readiness']['status'] }}</x-filament::badge>@if ($row['readiness']['status'] !== 'Ready for offerings')<p class="mt-1 text-sm">{{ $row['readiness']['blocker'] }}</p>@endif</div>
                </div>@empty<p class="py-3 text-sm">No Programs recorded. Academic authority evidence is unavailable.</p>@endforelse
            </div>
        </section>
        <section><h2 class="text-lg font-semibold">Other academic evidence</h2><p class="mt-1 text-sm text-gray-600 dark:text-gray-300">These destinations use their own filters.</p><div class="mt-3 flex flex-wrap gap-4">
            @foreach (collect($this->approvalAreas())->whereIn('title', ['Grades & Progress', 'Lifecycle & Completion']) as $area)
                <div>
                    <span class="font-medium">{{ $area['title'] }}:</span>
                    <x-filament::link :href="$area['url']">{{ $area['action'] }}</x-filament::link>
                </div>
            @endforeach
            @if (\App\Filament\Resources\StudentProfiles\StudentProfileResource::canAccess())<x-filament::link :href="\App\Filament\Resources\StudentProfiles\StudentProfileResource::getUrl('index', ['oversightTermId' => $termId])">Student academic records</x-filament::link>@endif
        </div></section>
        <p class="text-xs text-gray-600 dark:text-gray-300">Checked {{ $checkedAt->format('M j, Y g:i A') }} PHT · Read-only source evidence</p>
    </div>
</x-filament-panels::page>
