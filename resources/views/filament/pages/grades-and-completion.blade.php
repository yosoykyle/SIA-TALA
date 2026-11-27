<x-filament-panels::page :class="$this->viewTab === 'inc-corrections' ? 'tala-inc-page' : ''">
    @if ($this->viewTab === 'overview')
        <div class="tala-workspace-launcher">
            <p class="tala-workspace-launcher__scope">Across all terms</p>
            @php($classGradesPriority = $overviewCounts['submitted'] > 0 || $overviewCounts['incomplete'] === 0)
            @php($incPriority = ! $classGradesPriority && $overviewCounts['incomplete'] > 0)
            <nav class="tala-workspace-launcher__main" aria-label="Grade work">
                <x-workspace-card :featured="$classGradesPriority" :action-color="$classGradesPriority ? 'primary' : 'gray'" :href="$overviewCounts['submitted'] > 0 ? $this::getUrl(['viewTab' => 'grade-review', 'filters' => ['state' => ['value' => \App\Models\GradeRoster::StateSubmitted]]]) : $this::getUrl(['viewTab' => 'grade-review'])" icon="heroicon-o-document-check" label="Class grades"
                    :figure="$overviewCounts['submitted']" :figure-label="\Illuminate\Support\Str::plural('class roster', $overviewCounts['submitted']).' awaiting review'"
                    description="Review, return, or release submitted class rosters." action="Review class grades">
                    @if ($overviewCounts['submitted'] === 0)<p class="tala-workspace-card__note">No class submissions waiting.</p>@endif
                </x-workspace-card>
                <x-workspace-card :featured="$incPriority" :action-color="$incPriority ? 'primary' : 'gray'" :href="$this::getUrl(['viewTab' => 'inc-corrections'])" icon="heroicon-o-arrow-path-rounded-square" :label="'INC & Corrections'"
                    :figure="$overviewCounts['incomplete']" :figure-label="'incomplete course '.\Illuminate\Support\Str::plural('result', $overviewCounts['incomplete'])"
                    description="Review and release INC completions and grade corrections." note="INC means incomplete." action="Open results" />
                <x-workspace-card :action-color="'gray'" :href="$this::getUrl(['viewTab' => 'academic-progress'])" icon="heroicon-o-identification" label="Student records"
                    description="Onboard continuing students, review released grades, averages, and academic progress." note="Import cohorts, manage prior records, or find a student." action="Open student records" />
            </nav>
            <section class="tala-workspace-launcher__supporting" aria-labelledby="other-records-title">
                <h2 id="other-records-title">Other records</h2>
                <nav class="tala-task-surface tala-workspace-launcher__other" aria-label="Other records">
                    <x-workspace-card compact :href="$this::getUrl(['viewTab' => 'lifecycle'])" icon="heroicon-o-arrow-path" label="Lifecycle" description="Leave, transfers and student status." />
                    <x-workspace-card compact :href="$this::getUrl(['viewTab' => 'completion-tor'])" icon="heroicon-o-document-text" :label="'Completion & TOR'" description="Graduation records and transcript requests." />
                </nav>
            </section>
        </div>
    @elseif (in_array($this->viewTab, ['grade-review', 'inc-corrections', 'academic-progress'], true))
        @if ($this->viewTab === 'academic-progress')
            <section class="tala-scoped-directory" aria-label="Student directory" x-data="{}">
                <x-filament-tables::search-field label="Search students" placeholder="Student name or number" />
                {{ $this->table }}
            </section>
        @elseif ($this->viewTab === 'grade-review')
            @include('filament.admin.grade-rosters.class-queue')
        @else
            {{ $this->table }}
        @endif
        @if ($this->viewTab === 'inc-corrections')
            <x-supporting-details summary="INC completion and grade correction are different tasks">
                <p><strong>INC means incomplete.</strong> Faculty records completion of the remaining course requirement; Registrar reviews and releases the result.</p>
                <p><strong>A correction changes an already released grade.</strong> Find the student’s course result, then record the institutionally approved correction. The earlier result stays in history.</p>
                <p>One student may appear more than once when different courses need attention. Search by student, course or class, or choose a term.</p>
            </x-supporting-details>
        @endif
    @else
        @php($area = $this->activeWorkArea())
        <x-filament::section :icon="$area['icon']">
            <x-slot name="heading">{{ $area['title'] }}</x-slot>
            <x-slot name="description">{{ $area['description'] }}</x-slot>
            <x-filament::button :href="$area['url']" tag="a" icon="heroicon-m-arrow-right" icon-position="after">
                {{ $area['action'] }}
            </x-filament::button>
        </x-filament::section>
    @endif
</x-filament-panels::page>
