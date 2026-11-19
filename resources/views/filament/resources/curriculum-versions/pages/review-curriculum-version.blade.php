@assets
    <link rel="stylesheet" href="{{ asset('css/tala-catalog.css').'?v='.filemtime(public_path('css/tala-catalog.css')) }}">
@endassets

<x-filament-panels::page>
    <div
        class="tala-curriculum-board"
        x-data="{ pendingYear: null }"
        x-effect="if ($wire.activeYear) { pendingYear = null }"
    >
        @if ($readOnly)
            <x-tala-access-alert variant="info" title="Read-only oversight view">
                <p>Academic Head access is read-only. Registrar remains the recording and activation authority.</p>
            </x-tala-access-alert>
        @endif

        @if ($protectedReason)
            <x-tala-access-alert variant="info">
                <p>{{ $protectedReason }}</p>
            </x-tala-access-alert>
        @endif

        @if ($attention !== [])
            <x-tala-access-alert variant="warning" title="Before this curriculum can be activated">
                <ul>
                    @foreach ($attention as $item)
                        <li>
                            {{ $item['message'] }}
                            @if ($item['url'] ?? null)
                                <a class="tala-workspace-text-link" {{ \Filament\Support\generate_href_html($item['url']) }}>{{ $item['link'] }}</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-tala-access-alert>
        @endif

        @if ($isEmptyPlan)
            <div class="tala-task-surface tala-catalog-empty-card">
                <x-servitech-empty-state
                    illustrated
                    heading="Start building this study plan"
                    description="Organize subjects across Year 1–{{ count($yearTabs) }} semesters, or add a Special Term (Summer) when needed."
                >
                    @if ($canEdit)
                        <x-filament::button
                            type="button"
                            color="primary"
                            icon="heroicon-m-plus"
                            wire:click="openSubjectEditor('existing', 1, '{{ \App\Models\Term::TypeFirstSemester }}')"
                        >
                            Add subjects
                        </x-filament::button>
                        <x-filament::button
                            type="button"
                            color="gray"
                            icon="heroicon-o-pencil-square"
                            wire:click="mountAction('planSettings')"
                        >
                            Curriculum details
                        </x-filament::button>
                    @endif
                </x-servitech-empty-state>
            </div>
        @else
            <section class="tala-year-grid" aria-label="Curriculum years">
                @foreach ($yearTabs as $tab)
                    <button
                        type="button"
                        wire:key="year-card-{{ $tab['year'] }}"
                        x-on:click="pendingYear = {{ $tab['year'] }}"
                        wire:click="selectYear({{ $tab['year'] }})"
                        aria-pressed="{{ $activeYear === $tab['year'] ? 'true' : 'false' }}"
                        x-bind:aria-pressed="((pendingYear ?? {{ $activeYear }}) === {{ $tab['year'] }}) ? 'true' : 'false'"
                        x-bind:class="{ 'is-active': (pendingYear ?? {{ $activeYear }}) === {{ $tab['year'] }} }"
                        @class([
                            'tala-year-card',
                            'is-active' => $activeYear === $tab['year'],
                            'has-warning' => $tab['incomplete_count'] > 0,
                        ])
                    >
                        <span class="tala-year-card__top">
                            <span class="tala-year-card__eyebrow">{{ $tab['label'] }}</span>
                            @if ($isSearching && $tab['match_count'] > 0)
                                <span class="tala-year-card__status tala-year-card__status--match">
                                    {{ $tab['match_count'] }} {{ \Illuminate\Support\Str::plural('match', $tab['match_count']) }}
                                </span>
                            @elseif ($tab['incomplete_count'] > 0)
                                <span class="tala-year-card__status tala-year-card__status--warning">
                                    <x-filament::icon icon="heroicon-m-exclamation-triangle" aria-hidden="true" />
                                    {{ $tab['incomplete_count'] }} incomplete
                                </span>
                            @endif
                        </span>
                        <strong class="tala-year-card__title">{{ $tab['ordinal_label'] ?? $tab['label'] }}</strong>
                        <span class="tala-year-card__meta">
                            {{ $tab['total_count'] }} {{ \Illuminate\Support\Str::plural('subject', $tab['total_count']) }} · {{ $tab['units'] }} units
                        </span>
                    </button>
                @endforeach

                @if ($canAddYear)
                    <button
                        type="button"
                        wire:click="addYear"
                        class="tala-year-card tala-year-card--add"
                    >
                        <x-filament::icon icon="heroicon-m-plus" class="tala-year-card__add-icon" aria-hidden="true" />
                        <strong class="tala-year-card__title">Add year</strong>
                        <span class="tala-year-card__meta">Extend study plan</span>
                    </button>
                @endif
            </section>

            <section class="tala-year-workspace-card" aria-label="{{ ($activeYearTab['ordinal_label'] ?? 'Year '.$activeYear).' study plan' }}">
                <div class="tala-year-header">
                    <div class="tala-year-header__summary">
                        <h2>{{ $activeYearTab['ordinal_label'] ?? 'Year '.$activeYear }}</h2>
                        <p class="tala-year-header__meta">
                            {{ $activeYearTotalCount }} {{ \Illuminate\Support\Str::plural('subject', $activeYearTotalCount) }} · {{ $activeYearUnits }} units
                        </p>
                    </div>

                    <div class="tala-year-header__tools">
                        <div class="tala-year-bar__search-wrap">
                            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass" class="tala-year-bar__search">
                                <x-filament::input
                                    type="text"
                                    role="searchbox"
                                    wire:model.live.debounce.200ms="search"
                                    placeholder="Search subjects across all years..."
                                    aria-label="Search subjects across all years"
                                />
                            </x-filament::input.wrapper>
                            @if ($isSearching)
                                <x-filament::icon-button
                                    icon="heroicon-m-x-mark"
                                    color="gray"
                                    size="sm"
                                    label="Clear search"
                                    wire:click="clearSearch"
                                    class="tala-year-search__clear"
                                />
                            @endif
                        </div>

                        @if ($canRemoveActiveYear)
                            <x-filament::button
                                type="button"
                                size="sm"
                                color="danger"
                                outlined
                                icon="heroicon-m-trash"
                                wire:click="removeEmptyYear({{ $activeYear }})"
                            >
                                Remove empty Year {{ $activeYear }}
                            </x-filament::button>
                        @endif
                    </div>
                </div>

                <div
                    class="tala-year-workspace-card__body"
                    wire:loading.class="is-loading"
                    wire:target="selectYear, addYear, removeEmptyYear, clearSearch"
                >
                    @if ($isSearching && $totalMatches === 0)
                        <div class="tala-task-surface tala-catalog-empty-card">
                            <x-servitech-empty-state
                                :illustrated="false"
                                icon="heroicon-o-magnifying-glass"
                                heading="No matching subjects"
                                :description="'No subjects in this study plan match “'.$search.'”.'"
                            >
                                <x-filament::button type="button" color="gray" icon="heroicon-m-x-mark" wire:click="clearSearch">
                                    Clear search
                                </x-filament::button>
                            </x-servitech-empty-state>
                        </div>
                    @elseif (! $canEdit && $activeYearTotalCount === 0 && ! $summerVisible && ! $isSearching)
                        <div class="tala-task-surface tala-catalog-empty-card">
                            <x-servitech-empty-state
                                :illustrated="false"
                                icon="heroicon-o-calendar"
                                heading="No subjects in {{ $activeYearTab['ordinal_label'] ?? 'Year '.$activeYear }}"
                                description="No subjects are scheduled for this year level."
                            />
                        </div>
                    @else
                        <div class="tala-semester-grid">
                            @foreach ($regularSemesters as $semester)
                                <x-filament::section
                                    wire:key="semester-{{ $activeYear }}-{{ $semester['term_type'] }}"
                                    :compact="true"
                                    :heading="$semester['label']"
                                    :description="$semester['total_count'].' '.\Illuminate\Support\Str::plural('subject', $semester['total_count']).' · '.$semester['total_units'].' units'"
                                    class="tala-semester-card"
                                >
                                    @if ($canEdit)
                                        <x-slot:afterHeader>
                                            <x-filament::button
                                                type="button"
                                                size="sm"
                                                :color="$semester['total_count'] === 0 ? 'primary' : 'gray'"
                                                icon="heroicon-m-plus"
                                                wire:click="openSubjectEditor('existing', {{ $activeYear }}, '{{ $semester['term_type'] }}')"
                                            >
                                                Add subject
                                            </x-filament::button>
                                        </x-slot:afterHeader>
                                    @endif

                                    @if ($semester['rows'] !== [])
                                        <div class="tala-semester-list-head" aria-hidden="true">
                                            <span>Subject</span>
                                            <span>Prereq</span>
                                            <span>Units</span>
                                        </div>
                                        <ul
                                            class="tala-semester-list"
                                            role="list"
                                            @if ($canEdit && ! $isSearching) wire:sort="sortSemesterEntry" @endif
                                        >
                                            @foreach ($semester['rows'] as $row)
                                                <li
                                                    wire:key="entry-{{ $row['id'] }}"
                                                    @if ($canEdit && ! $isSearching) wire:sort:item="{{ $row['id'] }}" @endif
                                                    class="tala-semester-item"
                                                >
                                                    <button
                                                        type="button"
                                                        wire:click="selectCurriculumSubject({{ $row['id'] }})"
                                                        @class([
                                                            'tala-semester-row',
                                                            'is-selected' => $selectedSubject && $selectedSubject->id === $row['id'],
                                                            'is-incomplete' => $row['incomplete_message'] !== null,
                                                        ])
                                                    >
                                                        <span class="tala-semester-row__lead">
                                                            @if ($canEdit && ! $isSearching)
                                                                <span
                                                                    class="tala-semester-row__grip"
                                                                    wire:sort:handle
                                                                    x-on:click.stop
                                                                    title="Drag to reorder subject"
                                                                    aria-hidden="true"
                                                                >
                                                                    <x-filament::icon icon="heroicon-m-bars-2" />
                                                                </span>
                                                            @endif
                                                            <span class="tala-semester-row__main">
                                                                <span class="tala-semester-row__primary">
                                                                    <span class="tala-semester-row__code">{{ $row['code'] }}</span>
                                                                    <span class="tala-semester-row__title">{{ $row['title'] }}</span>
                                                                    @if ($row['is_elective'])
                                                                        <span class="tala-semester-row__tag">{{ $row['elective_label'] }}</span>
                                                                    @endif
                                                                </span>
                                                                @if ($row['incomplete_message'] !== null)
                                                                    <span class="tala-semester-row__meta">
                                                                        <span class="tala-semester-row__warning">
                                                                            <x-filament::icon icon="heroicon-m-exclamation-triangle" aria-hidden="true" />
                                                                            {{ $row['incomplete_message'] }}
                                                                        </span>
                                                                    </span>
                                                                @endif
                                                            </span>
                                                        </span>
                                                        <span class="tala-semester-row__prereq-col">
                                                            @if ($row['prerequisites'] !== '')
                                                                <span class="tala-semester-row__prereq">{{ $row['prerequisites'] }}</span>
                                                            @endif
                                                        </span>
                                                        <span class="tala-semester-row__trailing">
                                                            <span class="tala-semester-row__units">
                                                                {{ $row['units'] }}<span class="tala-visually-hidden"> units</span>
                                                            </span>
                                                            <x-filament::icon icon="heroicon-m-chevron-right" class="tala-semester-row__chevron" aria-hidden="true" />
                                                        </span>
                                                    </button>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @elseif ($isSearching)
                                        <p class="tala-semester-search-empty">No matches in {{ $semester['label'] }}.</p>
                                    @else
                                        <div class="tala-semester-empty">
                                            <x-servitech-empty-state
                                                :illustrated="false"
                                                icon="heroicon-o-book-open"
                                                heading="No subjects yet"
                                                :description="'Add subjects planned for '.$semester['label'].' in '.($activeYearTab['ordinal_label'] ?? 'Year '.$activeYear).'.'"
                                            />
                                        </div>
                                    @endif
                                </x-filament::section>
                            @endforeach
                        </div>

                        @if ($summerPanel !== null)
                            <x-filament::section
                                wire:key="semester-{{ $activeYear }}-summer"
                                :compact="true"
                                :heading="$summerPanel['label']"
                                :description="$summerPanel['total_count'].' '.\Illuminate\Support\Str::plural('subject', $summerPanel['total_count']).' · '.$summerPanel['total_units'].' units'"
                                class="tala-semester-card tala-semester-card--summer"
                            >
                                @if ($canEdit)
                                    <x-slot:afterHeader>
                                        <div class="tala-semester-card__actions">
                                            @if ($canRemoveSummer)
                                                <x-filament::button
                                                    type="button"
                                                    size="sm"
                                                    color="gray"
                                                    outlined
                                                    icon="heroicon-m-x-mark"
                                                    wire:click="removeEmptySummerForYear({{ $activeYear }})"
                                                >
                                                    Remove Summer
                                                </x-filament::button>
                                            @endif
                                            <x-filament::button
                                                type="button"
                                                size="sm"
                                                :color="$summerPanel['total_count'] === 0 ? 'primary' : 'gray'"
                                                icon="heroicon-m-plus"
                                                wire:click="openSubjectEditor('existing', {{ $activeYear }}, '{{ \App\Models\Term::TypeSpecialTerm }}')"
                                            >
                                                Add subject
                                            </x-filament::button>
                                        </div>
                                    </x-slot:afterHeader>
                                @endif

                                @if ($summerPanel['rows'] !== [])
                                    <div class="tala-semester-list-head" aria-hidden="true">
                                        <span>Subject</span>
                                        <span>Prereq</span>
                                        <span>Units</span>
                                    </div>
                                    <ul
                                        class="tala-semester-list tala-semester-list--summer"
                                        role="list"
                                        @if ($canEdit && ! $isSearching) wire:sort="sortSemesterEntry" @endif
                                    >
                                        @foreach ($summerPanel['rows'] as $row)
                                            <li
                                                wire:key="entry-summer-{{ $row['id'] }}"
                                                @if ($canEdit && ! $isSearching) wire:sort:item="{{ $row['id'] }}" @endif
                                                class="tala-semester-item"
                                            >
                                                <button
                                                    type="button"
                                                    wire:click="selectCurriculumSubject({{ $row['id'] }})"
                                                    @class([
                                                        'tala-semester-row',
                                                        'is-selected' => $selectedSubject && $selectedSubject->id === $row['id'],
                                                        'is-incomplete' => $row['incomplete_message'] !== null,
                                                    ])
                                                >
                                                    <span class="tala-semester-row__lead">
                                                        @if ($canEdit && ! $isSearching)
                                                            <span
                                                                class="tala-semester-row__grip"
                                                                wire:sort:handle
                                                                x-on:click.stop
                                                                title="Drag to reorder subject"
                                                                aria-hidden="true"
                                                            >
                                                                <x-filament::icon icon="heroicon-m-bars-2" />
                                                            </span>
                                                        @endif
                                                        <span class="tala-semester-row__main">
                                                            <span class="tala-semester-row__primary">
                                                                <span class="tala-semester-row__code">{{ $row['code'] }}</span>
                                                                <span class="tala-semester-row__title">{{ $row['title'] }}</span>
                                                                @if ($row['is_elective'])
                                                                    <span class="tala-semester-row__tag">{{ $row['elective_label'] }}</span>
                                                                @endif
                                                            </span>
                                                            @if ($row['incomplete_message'] !== null)
                                                                <span class="tala-semester-row__meta">
                                                                    <span class="tala-semester-row__warning">
                                                                        <x-filament::icon icon="heroicon-m-exclamation-triangle" aria-hidden="true" />
                                                                        {{ $row['incomplete_message'] }}
                                                                    </span>
                                                                </span>
                                                            @endif
                                                        </span>
                                                    </span>
                                                    <span class="tala-semester-row__prereq-col">
                                                        @if ($row['prerequisites'] !== '')
                                                            <span class="tala-semester-row__prereq">{{ $row['prerequisites'] }}</span>
                                                        @endif
                                                    </span>
                                                    <span class="tala-semester-row__trailing">
                                                        <span class="tala-semester-row__units">
                                                            {{ $row['units'] }}<span class="tala-visually-hidden"> units</span>
                                                        </span>
                                                        <x-filament::icon icon="heroicon-m-chevron-right" class="tala-semester-row__chevron" aria-hidden="true" />
                                                    </span>
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @elseif ($isSearching)
                                    <p class="tala-semester-search-empty">No matches in Special Term (Summer).</p>
                                @else
                                    <div class="tala-semester-empty">
                                        <x-servitech-empty-state
                                            :illustrated="false"
                                            icon="heroicon-o-sun"
                                            heading="No Summer / Special Term subjects yet"
                                            :description="'Add OJT, internship, or Summer subjects for '.($activeYearTab['ordinal_label'] ?? 'Year '.$activeYear).', or remove this term if not needed.'"
                                        />
                                    </div>
                                @endif
                            </x-filament::section>
                        @elseif ($canEdit && ! $summerVisible)
                            <div class="tala-summer-add-bar">
                                <span class="tala-summer-add-bar__text">
                                    Need a Special Term (Summer) in {{ $activeYearTab['ordinal_label'] ?? 'Year '.$activeYear }} for OJT, practicum, or midyear subjects?
                                </span>
                                <x-filament::button
                                    type="button"
                                    size="sm"
                                    color="gray"
                                    icon="heroicon-m-plus"
                                    wire:click="enableSummerForYear({{ $activeYear }})"
                                >
                                    Add Summer term
                                </x-filament::button>
                            </div>
                        @endif
                    @endif
                </div>
            </section>
        @endif

        @if ($canEdit || ($externalQualifications ?? []) !== [])
            <x-filament::section
                :compact="true"
                heading="TESDA & External Certifications"
                description="Industry certificates assessed outside class subjects, such as TESDA NC II or NC III."
                class="tala-semester-card tala-certifications-card"
            >
                @if ($canEdit)
                    <x-slot:afterHeader>
                        <x-filament::button
                            type="button"
                            size="sm"
                            color="gray"
                            icon="heroicon-m-plus"
                            wire:click="mountAction('addExternalCompetency')"
                        >
                            Add certificate
                        </x-filament::button>
                    </x-slot:afterHeader>
                @endif

                <div class="tala-certifications-card__body">
                    @if (($externalQualifications ?? []) !== [])
                        @include('filament.resources.curriculum-versions.partials.external-qualifications', [
                            'qualifications' => $externalQualifications,
                            'canEdit' => $canEdit,
                        ])
                    @else
                        <p class="tala-certifications-empty">
                            No external certifications or TESDA assessments are required for this study plan yet.
                        </p>
                    @endif
                </div>
            </x-filament::section>
        @endif

        <x-filament::modal
            id="curriculum-subject-inspector"
            width="2xl"
            :sticky-header="true"
            :sticky-footer="true"
            :heading="$selectedSubject?->courseSpecification?->title ?? 'Subject details'"
            :description="$selectedSubject?->courseSpecification?->course?->code"
            x-on:close-modal.window="if ($event.detail.id === 'curriculum-subject-inspector' && $wire.selectedEntryId !== null) { $wire.closeSubjectEditor() }"
        >
            @if ($selectedSubject)
                <div class="tala-inspector-body">
                    @if ($selectedSubjectWarning)
                        <x-tala-access-alert variant="warning">
                            <p>{{ $selectedSubjectWarning }}</p>
                        </x-tala-access-alert>
                    @endif

                    @if ($selectedSubjectFacts !== [])
                        <dl class="tala-subject-editor-facts">
                            @foreach ($selectedSubjectFacts as $factLabel => $factValue)
                                <div>
                                    <dt>{{ $factLabel }}</dt>
                                    <dd>{{ $factValue }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif

                    @if (($selectedSubjectUrl ?? null) || ($canEdit && $subjectMode === 'placement'))
                        <div class="tala-subject-editor-heading__links">
                            @if ($selectedSubjectUrl ?? null)
                                <a class="tala-workspace-text-link" {{ \Filament\Support\generate_href_html($selectedSubjectUrl) }}>Open in Subjects</a>
                            @endif
                            @if ($canEdit && $subjectMode === 'placement')
                                {{ $this->copySelectedSubjectAction }}
                            @endif
                        </div>
                    @endif

                    @if ($canEdit && $subjectMode === 'placement')
                        <p class="tala-subject-editor-heading__hint">These teaching details are shared across curricula. Here you can move the subject within this plan, or revise it for this curriculum.</p>
                    @endif

                    @if ($canEdit)
                        <form id="curriculum-subject-form" wire:submit="saveCurriculumSubject" novalidate>
                            {{ $this->subjectForm }}
                        </form>
                    @endif
                </div>
            @endif

            <x-slot:footer>
                <div class="tala-inspector-footer">
                    @if ($canEdit && $selectedSubject)
                        <div class="tala-inspector-footer__danger">
                            {{ $this->removeSelectedSubjectAction }}
                        </div>
                        <div class="tala-inspector-footer__primary">
                            <x-filament::button type="button" color="gray" wire:click="closeSubjectEditor">Cancel</x-filament::button>
                            <x-filament::button type="submit" form="curriculum-subject-form" wire:loading.attr="disabled" wire:target="saveCurriculumSubject">
                                Save changes
                            </x-filament::button>
                        </div>
                    @else
                        <div class="tala-inspector-footer__primary">
                            <x-filament::button type="button" color="gray" wire:click="closeSubjectEditor">Close</x-filament::button>
                        </div>
                    @endif
                </div>
            </x-slot:footer>
        </x-filament::modal>

        @if ($canEdit)
            <x-filament::modal
                id="add-curriculum-subjects"
                width="2xl"
                :sticky-header="true"
                :sticky-footer="true"
                heading="Add subjects"
                description="Choose existing catalog subjects or define new subjects for a curriculum year and semester."
                x-on:close-modal.window="if ($event.detail.id === 'add-curriculum-subjects' && $wire.subjectEditorOpen && $wire.selectedEntryId === null) { $wire.closeSubjectEditor() }"
            >
                @if ($selectedSubject === null)
                    <div class="tala-add-subjects-modal-body">
                        <x-filament::tabs label="How to add subjects" class="tala-segmented-tabs">
                            <x-filament::tabs.item :active="$subjectMode === 'existing'" wire:click="openSubjectEditor('existing')">Existing subjects</x-filament::tabs.item>
                            <x-filament::tabs.item :active="$subjectMode === 'new'" wire:click="openSubjectEditor('new')">New subjects</x-filament::tabs.item>
                        </x-filament::tabs>
                        <form id="curriculum-add-subject-form" wire:submit="saveCurriculumSubject" novalidate>
                            {{ $this->subjectForm }}
                        </form>
                    </div>
                @endif

                <x-slot:footer>
                    <div class="tala-add-subjects-modal-footer">
                        <x-filament::button type="button" color="gray" wire:click="closeSubjectEditor">Cancel</x-filament::button>
                        <x-filament::button type="submit" form="curriculum-add-subject-form" wire:loading.attr="disabled" wire:target="saveCurriculumSubject">
                            Add to plan
                        </x-filament::button>
                    </div>
                </x-slot:footer>
            </x-filament::modal>
        @endif
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
