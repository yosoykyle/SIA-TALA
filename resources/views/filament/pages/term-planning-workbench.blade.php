<x-filament-panels::page>

    <div class="space-y-6">

        <section aria-label="Selected Term" class="tala-planning-context">

            <div class="tala-planning-picker">{{ $this->termSelection }}</div>

            @if ($term)

                <div class="flex flex-wrap items-center gap-3">

                    <x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => 'plan'])" :outlined="$viewTab !== 'plan'">Plan overview</x-filament::button>

                    <x-filament::dropdown placement="bottom-end">

                        <x-slot name="trigger"><x-filament::button color="gray" outlined icon="heroicon-m-chevron-down" icon-position="after">Term options</x-filament::button></x-slot>

                        <x-filament::dropdown.list>

                            <x-filament::dropdown.list.item tag="a" :href="$destinations['overview']">View Term record</x-filament::dropdown.list.item>

                            @if (! $readOnly)

                                <x-filament::dropdown.list.item tag="a" :href="\App\Filament\Resources\Terms\TermResource::getUrl('create', ['termId' => $term->id, 'viewTab' => $viewTab])">Create another Term</x-filament::dropdown.list.item>

                            @endif

                        </x-filament::dropdown.list>

                    </x-filament::dropdown>

                </div>

            @endif

        </section>

        @if ($term)

            <div>

                <details class="tala-mobile-planning-tasks">

                    <summary><span class="font-semibold">{{ $tabs[$viewTab] }}</span> · Planning tasks</summary>

                    <x-tala-navigation label="Term planning tasks" class="tala-planning-tasks">

                        @foreach (collect($tabs)->except('plan') as $key => $label)

                            <x-filament::tabs.item tag="a" :href="\App\Filament\Pages\TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => $key])" :active="$viewTab === $key" :aria-current="$viewTab === $key ? 'page' : null"><span aria-hidden="true">{{ $loop->iteration }}</span> {{ $label }}</x-filament::tabs.item>

                        @endforeach

                    </x-tala-navigation>

                </details>

                <x-tala-navigation label="Term planning tasks" class="tala-planning-tasks tala-desktop-planning-tasks">

                    @foreach (collect($tabs)->except('plan') as $key => $label)

                        <x-filament::tabs.item tag="a" :href="\App\Filament\Pages\TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => $key])" :active="$viewTab === $key" :aria-current="$viewTab === $key ? 'page' : null">

                            <span class="tala-planning-task-heading"><span class="tala-planning-task-number" aria-hidden="true">{{ $loop->iteration }}</span><span class="tala-planning-task-label">{{ $label }}</span></span>

                            <span class="tala-planning-task-summary">{{ $taskSummaries[$key] }}</span>

                        </x-filament::tabs.item>

                    @endforeach

                </x-tala-navigation>

            </div>

            <section class="space-y-4" aria-label="{{ $tabs[$viewTab] }}">
                @if ($readOnly)<p class="text-sm text-gray-600 dark:text-gray-300">Read-only oversight. Registrar prepares, reviews and publishes the timetable.</p>@endif

                @includeIf('filament.pages.partials.term-planning-'.$viewTab)

                @if ($viewTab === 'overview') @include('filament.pages.partials.term-calendar-overview') @endif

            </section>

        @else

            <x-filament::section>

                <x-slot name="heading">Prepare a Term timetable</x-slot>

                <x-slot name="description">Choose the Term you want to work on. Set its teaching calendar, prepare classes and resources, then review and publish a timetable.</x-slot>

                <div class="divide-y divide-gray-200 dark:divide-white/10">

                    @foreach ($terms->take(6) as $availableTerm)

                        <a class="tala-planning-record-link" href="{{ \App\Filament\Pages\TermPlanningWorkbench::getUrl(['termId' => $availableTerm->id, 'viewTab' => 'plan']) }}"><span>{{ $availableTerm->label }}<small>{{ $availableTerm->academicYear?->label }} · {{ \App\Models\Term::typeOptions()[$availableTerm->type] ?? $availableTerm->type }}</small></span><span>Open plan</span></a>

                    @endforeach

                </div>

                @if (! $readOnly)<div class="mt-4"><x-filament::button tag="a" :href="\App\Filament\Resources\Terms\TermResource::getUrl('create', ['viewTab' => 'plan'])">Create a Term</x-filament::button></div>@endif

            </x-filament::section>

        @endif

    </div>

</x-filament-panels::page>
