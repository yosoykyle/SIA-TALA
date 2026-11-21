<x-filament-panels::page class="tala-term-workspace tala-resource-workspace">
    @include('filament.pages.partials.term-workspace-header')
    @if ($term)
        @php
            $formattedDefaultMaxUnits = $term->default_max_units === null
                ? 'Not recorded'
                : rtrim(rtrim(number_format((float) $term->default_max_units, 2, '.', ''), '0'), '.').' units per teacher';
        @endphp
        <section class="tala-task-work" aria-label="Faculty and rooms">
            <div class="tala-work-toolbar">
                <x-tala-navigation label="Resources view" compact>
                    <x-filament::tabs.item
                        tag="a"
                        :href="\App\Filament\Pages\TermResources::getUrl(['termId' => $term->id, 'resourceView' => 'faculty'])"
                        :active="$resourceView === 'faculty'"
                        icon="heroicon-o-user-group"
                        wire:click.prevent="chooseResourceView('faculty')"
                        wire:loading.attr="disabled"
                        wire:target="chooseResourceView"
                    >
                        Faculty
                    </x-filament::tabs.item>
                    <x-filament::tabs.item
                        tag="a"
                        :href="\App\Filament\Pages\TermResources::getUrl(['termId' => $term->id, 'resourceView' => 'rooms'])"
                        :active="$resourceView === 'rooms'"
                        icon="heroicon-o-building-office"
                        wire:click.prevent="chooseResourceView('rooms')"
                        wire:loading.attr="disabled"
                        wire:target="chooseResourceView"
                    >
                        Rooms
                    </x-filament::tabs.item>
                </x-tala-navigation>
                @if (! $readOnly)
                    <div class="tala-task-commands">
                        {{ $this->manageTeachingQualificationsAction }}
                        {{ $this->addRoomAction }}
                    </div>
                @endif
            </div>
            @if ($resourceView === 'faculty')
                @php
                    $replyPercent = $termFacultyCount > 0 ? (int) round(($facultyResponseCount / $termFacultyCount) * 100) : 0;
                    $replyBreakdown = collect([
                        $facultyResponseCount.' replied',
                        $noResponseCount.' no response',
                        $cannotTeachCount > 0 ? $cannotTeachCount.' cannot teach' : null,
                        $needsGridReviewCount > 0 ? $needsGridReviewCount.' review needed' : null,
                    ])->filter()->implode(' · ');
                    $officialRevisionUrl = \App\Filament\Pages\TermTimetable::getUrl([
                        'termId' => $term->id,
                        'timetableState' => 'official',
                        ...($currentVersion ? ['selectedVersionId' => $currentVersion->id] : []),
                    ]);
                @endphp

                <section class="tala-task-surface p-5 sm:p-6" aria-labelledby="faculty-replies-summary-heading">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div class="min-w-0 flex-1 space-y-2">
                            <h2 id="faculty-replies-summary-heading" class="text-lg font-semibold tabular-nums text-gray-900 dark:text-white">
                                <strong class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ $facultyResponseCount }} of {{ $termFacultyCount }}</strong>
                                <span>faculty replied</span>
                            </h2>
                            <div class="h-2 max-w-md overflow-hidden rounded-full bg-gray-100 dark:bg-white/10" role="progressbar" aria-valuenow="{{ $replyPercent }}" aria-valuemin="0" aria-valuemax="100" aria-label="Faculty reply progress">
                                <div class="h-full rounded-full bg-primary-600 dark:bg-primary-500" style="width: {{ $replyPercent }}%;"></div>
                            </div>
                            <p class="text-sm tabular-nums text-gray-600 dark:text-gray-300">
                                {{ $replyBreakdown }}
                            </p>
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                @if ($facultyDeadline)
                                    Reply by <strong class="font-semibold text-gray-900 dark:text-white">{{ $facultyDeadline->copy()->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</strong> · Asia/Manila ·
                                @else
                                    No due date set ·
                                @endif
                                Faculty can already answer anytime while the teaching calendar is active.
                            </p>
                        </div>
                        @if (! $readOnly)
                            <div class="tala-task-commands">
                                {{ $this->sendAvailabilityReminderAction }}
                            </div>
                        @endif
                    </div>
                </section>

                @if (! $activePackage)
                    <div class="tala-task-finding" role="status">
                        <div>
                            <strong>Teaching calendar not active</strong>
                            <p>{{ $readOnly ? 'The Registrar must activate the teaching calendar before Faculty can submit availability.' : 'Activate the teaching calendar so Faculty can submit availability.' }}</p>
                        </div>
                        <x-filament::button tag="a" color="gray" icon="heroicon-o-calendar-days" :href="\App\Filament\Pages\TermCalendar::getUrl(['termId' => $term->id])">Review calendar</x-filament::button>
                    </div>
                @endif

                @if (! empty($unresolvedLateImpacts))
                    <div class="tala-task-finding" role="status">
                        <div>
                            <strong>{{ count($unresolvedLateImpacts) }} late availability {{ \Illuminate\Support\Str::plural('change', count($unresolvedLateImpacts)) }} {{ count($unresolvedLateImpacts) === 1 ? 'affects' : 'affect' }} the official timetable</strong>
                            @foreach ($unresolvedLateImpacts as $impact)
                                <p class="mt-1">
                                    <strong>{{ $impact['faculty_name'] }}</strong> ({{ $impact['mode_label'] }})
                                    @if (! empty($impact['reason'])) · Reason: “{{ $impact['reason'] }}” @endif
                                    @if (! empty($impact['affected_meetings']))
                                        — {{ collect($impact['affected_meetings'])->map(fn (array $m): string => trim(($m['course_code'] ?? '').' '.($m['section_code'] ?? '').' ('.($m['day_label'] ?? '').' '.($m['time_label'] ?? '').')'))->implode('; ') }}
                                    @endif
                                </p>
                            @endforeach
                        </div>
                        <x-filament::button tag="a" :color="$readOnly ? 'gray' : 'primary'" icon="heroicon-m-arrow-right" icon-position="after" :href="$officialRevisionUrl">{{ $readOnly ? 'Inspect official timetable' : 'Start timetable revision' }}</x-filament::button>
                    </div>
                @endif

                @if ($needsGridReviewCount > 0)
                    <div class="tala-task-finding" role="status">
                        <div>
                            <strong>Review needed: {{ $needsGridReviewCount }} {{ \Illuminate\Support\Str::plural('teacher', $needsGridReviewCount) }} chose times outside the updated teaching hours</strong>
                            <p>Outside hours remain excluded from scheduling. Affected teachers can adjust their teaching times on My Availability.</p>
                        </div>
                    </div>
                @endif

                @if ($missingQualifications)
                    <div class="tala-task-finding" role="status">
                        <p>{{ $missingQualifications }} offered {{ \Illuminate\Support\Str::plural('course', $missingQualifications) }} need a qualified teacher.</p>
                        <x-filament::button tag="a" color="gray" icon="heroicon-o-academic-cap" :href="\App\Filament\Resources\FacultyQualifications\FacultyQualificationResource::getUrl('index', ['termId' => $term->id])">Review qualifications</x-filament::button>
                    </div>
                @endif
            @endif
            {{ $this->table }}
            <section class="tala-planning-coverage tala-planning-setup-links" aria-labelledby="resource-setup-heading">
                <h2 id="resource-setup-heading" class="text-base font-semibold">{{ $resourceView === 'faculty' ? 'Teaching limits and unavailable times' : 'Recurring unavailable times' }}</h2>
                <div class="mt-2">
                    @if ($resourceView === 'faculty')
                        <div class="tala-planning-source-row"><div><strong>Default teaching limit</strong><p class="tabular-nums">{{ $formattedDefaultMaxUnits }}</p></div>@if (! $readOnly)<x-filament::button tag="a" color="gray" icon="heroicon-o-pencil-square" :href="\App\Filament\Resources\Terms\TermResource::getUrl('edit', ['record' => $term->id, 'viewTab' => 'resources', 'resourceView' => 'faculty'])">Edit default</x-filament::button>@endif</div>
                        @if (\App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource::canViewAny())
                            <div class="tala-planning-source-row"><div><strong>Faculty-specific teaching limits</strong><p>Approved unit exceptions for this Term.</p></div><x-filament::button tag="a" color="gray" icon="heroicon-o-adjustments-horizontal" :href="\App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource::getUrl('index', ['termId' => $term->id])">Review exceptions</x-filament::button></div>
                        @endif
                    @endif
                    <div class="tala-planning-source-row"><div><strong>Weekly unavailable times</strong><p>{{ $resourceView === 'faculty' ? 'Recurring weekly exclusions, separate from faculty replies.' : 'Recurring weekly room blocks.' }}</p></div>@if (\App\Filament\Resources\CalendarEvents\CalendarEventResource::canViewAny() && ($resourceView === 'faculty' || ! $readOnly))<x-filament::button tag="a" color="gray" icon="heroicon-o-clock" :href="\App\Filament\Resources\CalendarEvents\CalendarEventResource::getUrl('index', ['termId' => $term->id, 'resourceView' => $resourceView])">Review unavailable times</x-filament::button>@endif</div>
                </div>
            </section>
        </section>
    @else
        <x-servitech-empty-state illustrated heading="Select a Term" description="Select a Term to review teaching availability and rooms." />
    @endif
    <x-filament-actions::modals />
</x-filament-panels::page>
