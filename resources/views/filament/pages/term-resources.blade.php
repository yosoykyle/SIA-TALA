<x-filament-panels::page class="tala-term-workspace tala-resource-workspace">
    @include('filament.pages.partials.term-workspace-header')
    @if ($term)
        @php
            $formattedDefaultMaxUnits = ($term->default_max_units === null
                ? '24'
                : rtrim(rtrim(number_format((float) $term->default_max_units, 2, '.', ''), '0'), '.')).' units/teacher';
            $activeRoomCount = count($rooms);
            $schoolFacultyUrl = \App\Filament\Pages\SchoolResources::getUrl(['tab' => 'faculty', 'termId' => $term->id]);
            $schoolRoomsUrl = \App\Filament\Pages\SchoolResources::getUrl(['tab' => 'rooms', 'termId' => $term->id]);
        @endphp
        <section class="tala-task-work" aria-label="Faculty and rooms">
            <div class="tala-work-tabs tala-work-tabs--full">
                <x-tala-navigation label="Resources view" compact stretch class="tala-work-tabs__nav">
                    <x-filament::tabs.item
                        tag="a"
                        :href="\App\Filament\Pages\TermResources::getUrl(['termId' => $term->id, 'resourceView' => 'faculty'])"
                        :active="$resourceView === 'faculty'"
                        icon="heroicon-o-user"
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
                        icon="heroicon-o-building-office-2"
                        wire:click.prevent="chooseResourceView('rooms')"
                        wire:loading.attr="disabled"
                        wire:target="chooseResourceView"
                    >
                        Rooms
                    </x-filament::tabs.item>
                </x-tala-navigation>
            </div>

            @if ($this->isTermClosed())
                <x-tala-access-alert variant="neutral" icon="heroicon-o-lock-closed" title="Closed term · Read-only">
                    <p>This term is closed. Per-term faculty availability, load overrides, and room closures are preserved for historical reference and cannot be edited.</p>
                </x-tala-access-alert>
            @endif

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
                    $hasInlineNotices = ! $activePackage
                        || ! empty($unresolvedLateImpacts)
                        || $needsGridReviewCount > 0
                        || $missingQualifications > 0;
                @endphp

                <section class="tala-task-surface tala-resource-readiness-bar" aria-labelledby="faculty-replies-summary-heading">
                    <div class="tala-resource-readiness-bar__row">
                        <div class="tala-resource-readiness-bar__main">
                            <div class="tala-resource-readiness-bar__headline">
                                <h2 id="faculty-replies-summary-heading" class="tala-resource-readiness-bar__heading tabular-nums">
                                    <strong class="tala-resource-readiness-bar__count">{{ $facultyResponseCount }} of {{ $termFacultyCount }}</strong>
                                    <span>faculty replied</span>
                                </h2>
                                <span class="tala-resource-readiness-bar__breakdown tabular-nums">{{ $replyBreakdown }}</span>
                            </div>
                            @if ($termFacultyCount > 0)
                                <div class="tala-resource-readiness-bar__track" role="progressbar" aria-valuenow="{{ $replyPercent }}" aria-valuemin="0" aria-valuemax="100" aria-label="Faculty reply progress">
                                    <div class="tala-resource-readiness-bar__fill" style="width: {{ $replyPercent }}%;"></div>
                                </div>
                            @endif
                            <p class="tala-resource-readiness-bar__meta tabular-nums">
                                @if ($facultyDeadline)
                                    Reply by: <strong>{{ $facultyDeadline->copy()->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</strong> (Asia/Manila)
                                @else
                                    Reply by: <strong>No due date set</strong>
                                @endif
                                · Load limit: <strong>{{ $formattedDefaultMaxUnits }}</strong>
                            </p>
                            <p class="tala-resource-readiness-bar__meta">
                                <strong>This term:</strong> faculty availability, load limits, and room closures. <strong>Shared across terms:</strong> <em>Assign subjects</em> and <em>Subjects</em> update school-wide qualifications (same catalog as School Resources).
                            </p>
                        </div>
                        <div class="tala-resource-readiness-bar__actions">
                            @if (! $readOnly && ! $this->isTermClosed() && ! $this->hasPublishedTimetable())
                                {{ $this->sendAvailabilityReminderAction }}
                            @endif
                            <x-filament::button tag="a" color="gray" size="sm" icon="heroicon-o-academic-cap" :href="$schoolFacultyUrl">
                                Edit qualified subjects in School Resources
                            </x-filament::button>
                        </div>
                    </div>

                    @if ($hasInlineNotices)
                        <div class="tala-resource-readiness-bar__notices">
                            @if (! $activePackage)
                                <div class="tala-resource-readiness-bar__notice tala-resource-readiness-bar__notice--warning">
                                    <div class="tala-resource-readiness-bar__notice-copy">
                                        <x-filament::icon icon="heroicon-o-calendar" class="tala-resource-readiness-bar__notice-icon" aria-hidden="true" />
                                        <p>
                                            <strong>Teaching calendar not active.</strong>
                                            {{ $readOnly ? 'The Registrar must activate the teaching calendar before faculty can submit availability.' : 'Activate the teaching calendar so faculty can submit availability.' }}
                                        </p>
                                    </div>
                                    <x-filament::button tag="a" color="gray" size="sm" icon="heroicon-o-calendar" :href="\App\Filament\Pages\TermCalendar::getUrl(['termId' => $term->id])">Review calendar</x-filament::button>
                                </div>
                            @endif

                            @if (! empty($unresolvedLateImpacts))
                                <div class="tala-resource-readiness-bar__notice tala-resource-readiness-bar__notice--danger">
                                    <div class="tala-resource-readiness-bar__notice-copy">
                                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="tala-resource-readiness-bar__notice-icon" aria-hidden="true" />
                                        <div>
                                            <p>
                                                <strong>{{ count($unresolvedLateImpacts) }} late availability {{ \Illuminate\Support\Str::plural('change', count($unresolvedLateImpacts)) }} {{ count($unresolvedLateImpacts) === 1 ? 'affects' : 'affect' }} the official timetable</strong>
                                            </p>
                                            @foreach ($unresolvedLateImpacts as $impact)
                                                <p class="tala-resource-readiness-bar__notice-detail">
                                                    <strong>{{ $impact['faculty_name'] }}</strong> ({{ $impact['mode_label'] }})
                                                    @if (! empty($impact['reason'])) · Reason: “{{ $impact['reason'] }}” @endif
                                                    @if (! empty($impact['affected_meetings']))
                                                        : {{ collect($impact['affected_meetings'])->map(fn (array $m): string => trim(($m['course_code'] ?? '').' '.($m['section_code'] ?? '').' ('.($m['day_label'] ?? '').' '.($m['time_label'] ?? '').')'))->implode('; ') }}
                                                    @endif
                                                </p>
                                            @endforeach
                                        </div>
                                    </div>
                                    <x-filament::button tag="a" size="sm" :color="$readOnly ? 'gray' : 'primary'" icon="heroicon-m-arrow-right" icon-position="after" :href="$officialRevisionUrl">{{ $readOnly ? 'Inspect official timetable' : 'Start timetable revision' }}</x-filament::button>
                                </div>
                            @endif

                            @if ($needsGridReviewCount > 0)
                                <div class="tala-resource-readiness-bar__notice tala-resource-readiness-bar__notice--warning">
                                    <div class="tala-resource-readiness-bar__notice-copy">
                                        <x-filament::icon icon="heroicon-o-clock" class="tala-resource-readiness-bar__notice-icon" aria-hidden="true" />
                                        <p>
                                            <strong>Review needed: {{ $needsGridReviewCount }} {{ \Illuminate\Support\Str::plural('teacher', $needsGridReviewCount) }} chose times outside the updated teaching hours.</strong>
                                            Outside hours remain excluded from scheduling. Affected teachers can adjust their teaching times on My Availability.
                                        </p>
                                    </div>
                                </div>
                            @endif

                            @if ($missingQualifications > 0)
                                <div class="tala-resource-readiness-bar__notice tala-resource-readiness-bar__notice--warning">
                                    <div class="tala-resource-readiness-bar__notice-copy">
                                        <x-filament::icon icon="heroicon-o-academic-cap" class="tala-resource-readiness-bar__notice-icon" aria-hidden="true" />
                                        <p>
                                            <strong>{{ $missingQualifications }} {{ \Illuminate\Support\Str::plural('subject', $missingQualifications) }} need a qualified teacher.</strong>
                                            @if (! $readOnly && ! $this->isTermClosed())
                                                Use Assign subjects below or click Subjects on a teacher row to assign eligible teachers across terms.
                                            @endif
                                        </p>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                </section>
            @else
                <section class="tala-task-surface tala-resource-readiness-bar" aria-labelledby="rooms-summary-heading">
                    <div class="tala-resource-readiness-bar__row">
                        <div class="tala-resource-readiness-bar__main">
                            <div class="tala-resource-readiness-bar__headline">
                                <h2 id="rooms-summary-heading" class="tala-resource-readiness-bar__heading tabular-nums">
                                    <strong class="tala-resource-readiness-bar__count">{{ $activeRoomCount }}</strong>
                                    <span>active campus {{ \Illuminate\Support\Str::plural('room', $activeRoomCount) }}</span>
                                </h2>
                                @if (($activeRoomClosureCount ?? 0) > 0)
                                    <span class="tala-resource-readiness-bar__breakdown tabular-nums">· {{ $activeRoomClosureCount }} active {{ \Illuminate\Support\Str::plural('closure', $activeRoomClosureCount) }} this term</span>
                                @endif
                            </div>
                            <p class="tala-resource-readiness-bar__meta">
                                Active campus rooms ready for this term. Manage the shared room catalog in School Resources, or record term-only maintenance closures per room below.
                            </p>
                        </div>
                        <div class="tala-resource-readiness-bar__actions">
                            <x-filament::button tag="a" color="gray" size="sm" icon="heroicon-o-building-office-2" :href="$schoolRoomsUrl">
                                Edit room catalog in School Resources
                            </x-filament::button>
                        </div>
                    </div>
                </section>
            @endif

            {{ $this->table }}
        </section>
    @else
        <section class="tala-task-surface" aria-label="Faculty and rooms">
            <x-servitech-empty-state
                :illustrated="false"
                icon="heroicon-o-users"
                heading="Choose a term"
                description="Choose a term above to review faculty availability and room readiness for that term."
            />
        </section>
    @endif
    <x-filament-actions::modals />
</x-filament-panels::page>
