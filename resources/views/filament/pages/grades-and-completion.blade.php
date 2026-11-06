<x-filament-panels::page :class="$this->viewTab === 'inc-corrections' ? 'tala-inc-page' : ''">
    @if ($this->viewTab === 'overview')
        <div class="tala-workspace-launcher">
            <p class="tala-workspace-launcher__scope">Across all terms</p>
            <nav class="tala-workspace-launcher__main" aria-label="Grade work">
                <x-workspace-card :href="$this::getUrl(['viewTab' => 'grade-review', 'filters' => ['state' => ['value' => \App\Models\GradeRoster::StateSubmitted]]])" icon="heroicon-o-document-check" label="Class grades"
                    :figure="$overviewCounts['submitted']" :figure-label="\Illuminate\Support\Str::plural('class roster', $overviewCounts['submitted']).' awaiting review'"
                    description="Review Faculty submissions; return results for changes or release the complete roster." action="Review class grades">
                    @if ($overviewCounts['submitted'] === 0)<p class="tala-workspace-card__note">No class submissions waiting.</p>@endif
                    <a class="tala-workspace-text-link" {{ \Filament\Support\generate_href_html($this::getUrl(['viewTab' => 'grade-review'])) }}>Find any class or earlier term <x-filament::icon icon="heroicon-m-arrow-right" /></a>
                </x-workspace-card>
                <x-workspace-card :href="$this::getUrl(['viewTab' => 'inc-corrections', 'filters' => ['result_scope' => ['value' => 'inc']]])" icon="heroicon-o-arrow-path-rounded-square" :label="'INC & Corrections'"
                    :figure="$overviewCounts['incomplete']" :figure-label="'incomplete course '.\Illuminate\Support\Str::plural('result', $overviewCounts['incomplete'])"
                    description="Faculty records completion; Registrar reviews and releases it." note="INC means incomplete." action="Open results">
                    <a class="tala-workspace-text-link" {{ \Filament\Support\generate_href_html($this::getUrl(['viewTab' => 'inc-corrections', 'filters' => ['result_scope' => ['value' => 'all']]])) }}>Find a released grade to correct <x-filament::icon icon="heroicon-m-arrow-right" /></a>
                </x-workspace-card>
                <x-workspace-card :href="$this::getUrl(['viewTab' => 'academic-progress'])" icon="heroicon-o-identification" label="Student records"
                    description="Released grades and averages across classes and terms." note="Find a student’s academic record." action="Find a student" />
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
