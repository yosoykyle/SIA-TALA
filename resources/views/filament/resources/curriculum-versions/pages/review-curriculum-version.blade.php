@assets
    <link rel="stylesheet" href="{{ asset('css/tala-catalog.css').'?v='.filemtime(public_path('css/tala-catalog.css')) }}">
@endassets

<x-filament-panels::page>
    <div
        class="tala-curriculum-board"
        x-data="{ pendingYear: null, pendingReview: false }"
        x-effect="if ($wire.activeYear || $wire.activeStep) { pendingYear = null; pendingReview = false }"
    >
        @if ($readOnly)
            <x-tala-access-alert variant="info" title="Read-only oversight view">
                <p>Academic Head access is read-only. Registrar remains the recording and activation authority.</p>
            </x-tala-access-alert>
        @endif

        @if ($copyBanner)
            <x-tala-access-alert variant="info" title="Copied curriculum draft">
                <p>{{ $copyBanner }}</p>
            </x-tala-access-alert>
        @endif

        @if ($protectedReason)
            <x-tala-access-alert variant="info">
                <p>{{ $protectedReason }}</p>
            </x-tala-access-alert>
        @endif

        @if ($attention !== [] && $activeStep !== 'review')
            <x-tala-access-alert variant="warning" title="Before this curriculum can be published">
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

        @if ($showAssignedBatchesStrip)
            @include('filament.resources.curriculum-versions.partials.assigned-batches-strip', [
                'assignedBatches' => $assignedBatches,
                'canAddBatch' => $canAddBatch,
            ])
        @endif

        @if ($isEmptyPlan && ! $canEdit)
            <div class="tala-task-surface tala-catalog-empty-card">
                <x-servitech-empty-state
                    illustrated
                    heading="No subjects in this curriculum"
                    description="No subjects have been placed in this study plan."
                />
            </div>
        @else
            {{-- Year-by-Year Stepper + Review Final Step --}}
            <nav class="tala-year-grid" aria-label="Curriculum setup steps">
                @foreach ($yearTabs as $tab)
                    @php
                        $isThisYearActive = $activeStep === 'year' && $activeYear === $tab['year'];
                    @endphp
                    <button
                        type="button"
                        wire:key="year-card-{{ $tab['year'] }}"
                        x-on:click="pendingYear = {{ $tab['year'] }}; pendingReview = false"
                        wire:click="selectYear({{ $tab['year'] }})"
                        @if ($isThisYearActive) aria-current="step" @endif
                        x-bind:aria-current="(!pendingReview && (pendingYear ?? ({{ $activeStep === 'year' ? $activeYear : 'null' }})) === {{ $tab['year'] }}) ? 'step' : false"
                        x-bind:class="{ 'is-active': !pendingReview && (pendingYear ?? ({{ $activeStep === 'year' ? $activeYear : 'null' }})) === {{ $tab['year'] }} }"
                        @class([
                            'tala-year-card',
                            'is-active' => $isThisYearActive,
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
                        <strong class="tala-year-card__title">{{ $tab['short_ordinal'] ?? $tab['ordinal_label'] }}</strong>
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

                <button
                    type="button"
                    wire:key="step-card-review"
                    x-on:click="pendingReview = true; pendingYear = null"
                    wire:click="selectReviewStep"
                    @if ($activeStep === 'review') aria-current="step" @endif
                    x-bind:aria-current="(pendingReview || (!pendingYear && '{{ $activeStep }}' === 'review')) ? 'step' : false"
                    x-bind:class="{ 'is-active': pendingReview || (!pendingYear && '{{ $activeStep }}' === 'review') }"
                    @class([
                        'tala-year-card',
                        'is-active' => $activeStep === 'review',
                        'has-warning' => $isEditableDraft && count($reviewData['blockers']) > 0,
                    ])
                >
                    <span class="tala-year-card__top">
                        <span class="tala-year-card__eyebrow">{{ $isEditableDraft ? 'Final step' : 'Overview' }}</span>
                        @if ($isEditableDraft && count($reviewData['blockers']) > 0)
                            <span class="tala-year-card__status tala-year-card__status--warning">
                                <x-filament::icon icon="heroicon-m-exclamation-triangle" aria-hidden="true" />
                                {{ count($reviewData['blockers']) }} {{ \Illuminate\Support\Str::plural('issue', count($reviewData['blockers'])) }}
                            </span>
                        @endif
                    </span>
                    <strong class="tala-year-card__title">Review</strong>
                    <span class="tala-year-card__meta">
                        {{ $totalSubjectsCount }} {{ \Illuminate\Support\Str::plural('subject', $totalSubjectsCount) }} · {{ $totalUnitsLabel }} units
                    </span>
                </button>
            </nav>

            {{-- Active Step Content --}}
            @if ($activeStep === 'review')
                @include('filament.resources.curriculum-versions.partials.review-step', [
                    'reviewData' => $reviewData,
                    'isEditableDraft' => $isEditableDraft,
                    'canEdit' => $canEdit,
                    'externalQualifications' => $externalQualifications,
                ])
            @else
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
                                        type="search"
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
                        wire:target="selectYear, selectReviewStep, goToNextStep, goToPreviousStep, addYear, removeEmptyYear, clearSearch, toggleEntrySelection, toggleSemesterSelection, clearSelectedEntries, undoRecentMoves, dismissMovedMarkers"
                    >
                        @if ($isEditableDraft && $canEdit && $this->recentlyMovedEntries !== [])
                            @php($movedTotal = count($this->recentlyMovedEntries))
                            <div
                                class="tala-moved-banner flex flex-wrap items-center justify-between gap-3 px-4 py-2.5 rounded-lg border"
                                role="status"
                                aria-label="Recently moved subjects"
                            >
                                <div class="flex flex-wrap items-center gap-2 text-sm">
                                    <x-filament::icon icon="heroicon-m-arrows-right-left" class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    <span class="font-medium tabular-nums">
                                        {{ $movedTotal }} {{ \Illuminate\Support\Str::plural('subject', $movedTotal) }} moved — highlighted below.
                                    </span>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-filament::button type="button" size="sm" color="gray" icon="heroicon-m-arrow-uturn-left" wire:click="undoRecentMoves">
                                        Undo move
                                    </x-filament::button>
                                    <x-filament::button type="button" size="sm" color="gray" wire:click="dismissMovedMarkers">
                                        Dismiss
                                    </x-filament::button>
                                </div>
                            </div>
                        @endif
                        @if ($isEditableDraft && $canEdit && $selectedEntriesSummary['count'] > 0)
                            <div
                                class="tala-task-surface flex flex-wrap items-center justify-between gap-3 px-4 py-2.5"
                                style="border-color: var(--tala-selected-edge); background: var(--tala-brand-tint); color: var(--tala-on-brand-tint);"
                                role="region"
                                aria-label="Selected subjects batch actions"
                            >
                                <div class="flex flex-wrap items-center gap-2 text-sm">
                                    <span class="font-semibold tabular-nums">
                                        {{ $selectedEntriesSummary['count'] }} {{ \Illuminate\Support\Str::plural('subject', $selectedEntriesSummary['count']) }} selected
                                    </span>
                                    @if ($selectedEntriesSummary['years_label'] !== '')
                                        <span style="opacity: 0.85;">({{ $selectedEntriesSummary['years_label'] }})</span>
                                    @endif
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    {{ $this->bulkMoveSelectedEntriesAction }}
                                    {{ $this->bulkRemoveSelectedEntriesAction }}
                                    <x-filament::button
                                        type="button"
                                        size="sm"
                                        color="gray"
                                        wire:click="clearSelectedEntries"
                                    >
                                        Clear selection
                                    </x-filament::button>
                                </div>
                            </div>
                        @endif

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
                                        @if ($canEdit && $semester['total_count'] > 0)
                                            <x-slot:afterHeader>
                                                <div class="tala-semester-card__actions">
                                                    @if ($isEditableDraft && $semester['rows'] !== [])
                                                        <label class="inline-flex items-center gap-1.5 text-xs font-medium cursor-pointer select-none px-1.5 py-1 rounded" style="color: var(--tala-muted); min-block-size: 2rem;">
                                                            <input
                                                                type="checkbox"
                                                                class="fi-checkbox-input fi-valid"
                                                                wire:click="toggleSemesterSelection({{ $activeYear }}, '{{ $semester['term_type'] }}')"
                                                                @checked($semester['all_selected'])
                                                                aria-label="Select all subjects in {{ $semester['label'] }}"
                                                            />
                                                            <span>Select all</span>
                                                        </label>
                                                    @endif
                                                    <x-filament::button
                                                        type="button"
                                                        size="sm"
                                                        color="gray"
                                                        icon="heroicon-m-plus"
                                                        wire:click="openSubjectEditor('existing', {{ $activeYear }}, '{{ $semester['term_type'] }}')"
                                                    >
                                                        Add subjects
                                                    </x-filament::button>
                                                </div>
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
                                                                'is-selected' => ($selectedSubject && $selectedSubject->id === $row['id']) || ($isEditableDraft && $canEdit && $row['is_checked']),
                                                                'is-incomplete' => $row['incomplete_message'] !== null,
                                                                'is-moved' => ($row['just_moved_label'] ?? null) !== null || ($row['source_moved_label'] ?? null) !== null,
                                                            ])
                                                        >
                                                            <span class="tala-semester-row__lead">
                                                                @if ($isEditableDraft && $canEdit)
                                                                    <input
                                                                        type="checkbox"
                                                                        class="fi-checkbox-input fi-valid shrink-0"
                                                                        wire:click.stop="toggleEntrySelection({{ $row['id'] }})"
                                                                        @checked($row['is_checked'])
                                                                        aria-label="Select {{ $row['code'] }}"
                                                                    />
                                                                @endif
                                                                @if ($canEdit && ! $isSearching)
                                                                    <span
                                                                        class="tala-semester-row__grip"
                                                                        wire:sort:handle
                                                                        x-on:click.stop
                                                                        title="Drag to reorder subject"
                                                                        aria-hidden="true"
                                                                    >
                                                                        <x-filament::icon icon="heroicon-m-bars-2" aria-hidden="true" />
                                                                    </span>
                                                                @endif
                                                                <span class="tala-semester-row__main">
                                                                    <span class="tala-semester-row__primary">
                                                                        <span class="tala-semester-row__code">{{ $row['code'] }}</span>
                                                                        <span class="tala-semester-row__title">{{ $row['title'] }}</span>
                                                                        @if ($row['is_elective'])
                                                                            <x-filament::badge size="sm" color="gray">{{ $row['elective_label'] }}</x-filament::badge>
                                                                        @endif
                                                                        @if ($row['is_externally_arranged'])
                                                                            <x-filament::badge size="sm" color="warning">Externally arranged — no class schedule</x-filament::badge>
                                                                        @elseif ($row['room_summary'])
                                                                            <x-filament::badge size="sm" color="gray">{{ $row['room_summary'] }}</x-filament::badge>
                                                                        @endif
                                                                        @if (($row['just_moved_label'] ?? null) !== null || ($row['source_moved_label'] ?? null) !== null)
                                                                            <x-filament::badge size="sm" color="info">{{ $row['just_moved_label'] ?? $row['source_moved_label'] }}</x-filament::badge>
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
                                                                <span class="tala-semester-row__action" aria-hidden="true">
                                                                    <span class="tala-row-edit-hint">{{ $canEdit ? 'Edit' : 'View' }}</span>
                                                                    <x-filament::icon icon="heroicon-m-chevron-right" class="tala-semester-row__chevron" aria-hidden="true" />
                                                                </span>
                                                            </span>
                                                        </button>
                                                    </li>
                                                @endforeach
                                            </ul>
                                            <div class="tala-semester-total">
                                                <span>Total</span>
                                                <span></span>
                                                <span class="tala-semester-total__units">{{ $semester['total_units'] }} units</span>
                                            </div>
                                        @elseif ($isSearching)
                                            <p class="tala-semester-search-empty">No matches in {{ $semester['label'] }}.</p>
                                        @else
                                            <div class="tala-semester-empty">
                                                <x-servitech-empty-state
                                                    :illustrated="false"
                                                    icon="heroicon-o-book-open"
                                                    heading="No subjects yet"
                                                    :description="'No subjects planned for '.$semester['label'].' in '.($activeYearTab['ordinal_label'] ?? 'Year '.$activeYear).'.'"
                                                >
                                                    @if ($canEdit)
                                                        <x-filament::button
                                                            type="button"
                                                            size="sm"
                                                            color="primary"
                                                            icon="heroicon-m-plus"
                                                            wire:click="openSubjectEditor('existing', {{ $activeYear }}, '{{ $semester['term_type'] }}')"
                                                        >
                                                            Add subjects
                                                        </x-filament::button>
                                                    @endif
                                                </x-servitech-empty-state>
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
                                    @if ($canEdit && ($canRemoveSummer || $summerPanel['total_count'] > 0))
                                        <x-slot:afterHeader>
                                            <div class="tala-semester-card__actions">
                                                @if ($isEditableDraft && $summerPanel['rows'] !== [])
                                                    <label class="inline-flex items-center gap-1.5 text-xs font-medium cursor-pointer select-none px-1.5 py-1 rounded" style="color: var(--tala-muted); min-block-size: 2rem;">
                                                        <input
                                                            type="checkbox"
                                                            class="fi-checkbox-input fi-valid"
                                                            wire:click="toggleSemesterSelection({{ $activeYear }}, '{{ \App\Models\Term::TypeSpecialTerm }}')"
                                                            @checked($summerPanel['all_selected'])
                                                            aria-label="Select all subjects in {{ $summerPanel['label'] }}"
                                                        />
                                                        <span>Select all</span>
                                                    </label>
                                                @endif
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
                                                @if ($summerPanel['total_count'] > 0)
                                                    <x-filament::button
                                                        type="button"
                                                        size="sm"
                                                        color="gray"
                                                        icon="heroicon-m-plus"
                                                        wire:click="openSubjectEditor('existing', {{ $activeYear }}, '{{ \App\Models\Term::TypeSpecialTerm }}')"
                                                    >
                                                        Add subjects
                                                    </x-filament::button>
                                                @endif
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
                                                            'is-selected' => ($selectedSubject && $selectedSubject->id === $row['id']) || ($isEditableDraft && $canEdit && $row['is_checked']),
                                                            'is-incomplete' => $row['incomplete_message'] !== null,
                                                            'is-moved' => ($row['just_moved_label'] ?? null) !== null || ($row['source_moved_label'] ?? null) !== null,
                                                        ])
                                                    >
                                                        <span class="tala-semester-row__lead">
                                                            @if ($isEditableDraft && $canEdit)
                                                                <input
                                                                    type="checkbox"
                                                                    class="fi-checkbox-input fi-valid shrink-0"
                                                                    wire:click.stop="toggleEntrySelection({{ $row['id'] }})"
                                                                    @checked($row['is_checked'])
                                                                    aria-label="Select {{ $row['code'] }}"
                                                                />
                                                            @endif
                                                            @if ($canEdit && ! $isSearching)
                                                                <span
                                                                    class="tala-semester-row__grip"
                                                                    wire:sort:handle
                                                                    x-on:click.stop
                                                                    title="Drag to reorder subject"
                                                                    aria-hidden="true"
                                                                >
                                                                    <x-filament::icon icon="heroicon-m-bars-2" aria-hidden="true" />
                                                                </span>
                                                            @endif
                                                            <span class="tala-semester-row__main">
                                                                <span class="tala-semester-row__primary">
                                                                    <span class="tala-semester-row__code">{{ $row['code'] }}</span>
                                                                    <span class="tala-semester-row__title">{{ $row['title'] }}</span>
                                                                    @if ($row['is_elective'])
                                                                        <x-filament::badge size="sm" color="gray">{{ $row['elective_label'] }}</x-filament::badge>
                                                                    @endif
                                                                    @if ($row['is_externally_arranged'])
                                                                        <x-filament::badge size="sm" color="warning">Externally arranged — no class schedule</x-filament::badge>
                                                                    @elseif ($row['room_summary'])
                                                                        <x-filament::badge size="sm" color="gray">{{ $row['room_summary'] }}</x-filament::badge>
                                                                    @endif
                                                                    @if (($row['just_moved_label'] ?? null) !== null || ($row['source_moved_label'] ?? null) !== null)
                                                                        <x-filament::badge size="sm" color="info">{{ $row['just_moved_label'] ?? $row['source_moved_label'] }}</x-filament::badge>
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
                                                            <span class="tala-semester-row__action" aria-hidden="true">
                                                                <span class="tala-row-edit-hint">{{ $canEdit ? 'Edit' : 'View' }}</span>
                                                                <x-filament::icon icon="heroicon-m-chevron-right" class="tala-semester-row__chevron" aria-hidden="true" />
                                                            </span>
                                                        </span>
                                                    </button>
                                                </li>
                                            @endforeach
                                        </ul>
                                        <div class="tala-semester-total">
                                            <span>Total</span>
                                            <span></span>
                                            <span class="tala-semester-total__units">{{ $summerPanel['total_units'] }} units</span>
                                        </div>
                                    @elseif ($isSearching)
                                        <p class="tala-semester-search-empty">No matches in Special Term (Summer).</p>
                                    @else
                                        <div class="tala-semester-empty">
                                            <x-servitech-empty-state
                                                :illustrated="false"
                                                icon="heroicon-o-sun"
                                                heading="No Summer subjects yet"
                                                description="Add OJT, practicum, or midyear subjects, or remove this term if not needed."
                                            >
                                                @if ($canEdit)
                                                    <x-filament::button
                                                        type="button"
                                                        size="sm"
                                                        color="primary"
                                                        icon="heroicon-m-plus"
                                                        wire:click="openSubjectEditor('existing', {{ $activeYear }}, '{{ \App\Models\Term::TypeSpecialTerm }}')"
                                                    >
                                                        Add subjects
                                                    </x-filament::button>
                                                @endif
                                            </x-servitech-empty-state>
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
                                        Add Summer
                                    </x-filament::button>
                                </div>
                            @endif
                        @endif
                    </div>
                </section>

                {{-- Published curricula with attached external certifications keep them visible --}}
                @if (! $canEdit && ($externalQualifications ?? []) !== [])
                    <x-filament::section
                        :compact="true"
                        heading="TESDA & External Certifications"
                        description="Industry certificates assessed outside class subjects, such as TESDA NC II or NC III."
                        class="tala-semester-card tala-certifications-card"
                    >
                        <div class="tala-certifications-card__body">
                            @include('filament.resources.curriculum-versions.partials.external-qualifications', [
                                'qualifications' => $externalQualifications,
                                'canEdit' => false,
                            ])
                        </div>
                    </x-filament::section>
                @endif
            @endif

            {{-- Wizard Footer Bar (Editable Drafts Only) --}}
            @if ($isEditableDraft && $canEdit)
                <footer class="tala-wizard-footer" aria-label="Curriculum wizard navigation">
                    <div class="tala-wizard-footer__group">
                        <x-filament::button
                            type="button"
                            color="gray"
                            outlined
                            wire:click="openExitModal"
                        >
                            Leave editor
                        </x-filament::button>
                    </div>

                    <div class="tala-wizard-footer__group">
                        <span class="tala-wizard-footer__autosave" role="status">
                            <x-filament::icon icon="heroicon-m-check-circle" aria-hidden="true" />
                            <span>Changes save automatically</span>
                        </span>

                        @if ($canGoBackStep)
                            <x-filament::button
                                type="button"
                                color="gray"
                                icon="heroicon-m-arrow-left"
                                wire:click="goToPreviousStep"
                            >
                                Back
                            </x-filament::button>
                        @endif

                        @if ($activeStep === 'year' && $nextStepLabel !== null)
                            <x-filament::button
                                type="button"
                                color="primary"
                                icon="heroicon-m-arrow-right"
                                icon-position="after"
                                wire:click="goToNextStep"
                            >
                                {{ $nextStepLabel }}
                            </x-filament::button>
                        @elseif ($activeStep === 'review' && $this->publishCurriculumAction->isVisible())
                            @if ($reviewData['blockers'] !== [])
                                <span class="tala-wizard-footer__note">Resolve issues above to publish</span>
                            @endif
                            {{ $this->publishCurriculumAction }}
                        @endif
                    </div>
                </footer>
            @endif
        @endif

        {{-- Subject Inspector Modal --}}
        <x-filament::modal
            id="curriculum-subject-inspector"
            width="4xl"
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

                    @if (($selectedSubjectUrl ?? null) || $canEdit)
                        <div class="tala-subject-editor-heading__links" role="toolbar" aria-label="Subject actions">
                            <div class="tala-subject-editor-heading__links-start">
                                @if ($selectedSubjectUrl ?? null)
                                    <x-filament::button
                                        tag="a"
                                        size="sm"
                                        color="gray"
                                        icon="heroicon-m-arrow-top-right-on-square"
                                        :href="$selectedSubjectUrl"
                                    >
                                        Open subject
                                    </x-filament::button>
                                @endif
                                @if ($canEdit && $subjectMode === 'placement')
                                    {{ $this->copySelectedSubjectAction }}
                                @endif
                                @if ($canEdit)
                                    {{ $this->replaceSelectedSubjectAction }}
                                @endif
                            </div>
                            @if ($canEdit)
                                <div class="tala-subject-editor-heading__links-end">
                                    {{ $this->removeSelectedSubjectAction }}
                                </div>
                            @endif
                        </div>
                    @endif

                    @if ($canEdit && $selectedSubjectIsShared)
                        <x-tala-access-alert variant="info" title="Draft-only changes">
                            <p>Changes to units, weekly lecture/lab hours, room type, or prerequisites apply only to this draft curriculum. Published curricula keep their current version.</p>
                        </x-tala-access-alert>
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
                        <div class="tala-inspector-footer__primary">
                            <x-filament::button type="button" color="gray" wire:click="closeSubjectEditor">Cancel</x-filament::button>
                            <x-filament::button type="submit" form-id="curriculum-subject-form" wire:loading.attr="disabled" wire:target="saveCurriculumSubject">
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

        {{-- Add Subjects from Catalog / New Subject Modal --}}
        @if ($canEdit)
            <x-filament::modal
                id="add-curriculum-subjects"
                width="5xl"
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
                        <x-filament::button type="submit" form-id="curriculum-add-subject-form" wire:loading.attr="disabled" wire:target="saveCurriculumSubject">
                            Add to plan
                        </x-filament::button>
                    </div>
                </x-slot:footer>
            </x-filament::modal>

            {{-- Safe 3-Choice Exit / Cancel Modal --}}
            @include('filament.resources.curriculum-versions.partials.exit-draft-modal', [
                'curriculum' => $curriculum,
                'confirmingExitDiscard' => $confirmingExitDiscard,
                'totalSubjectsCount' => $totalSubjectsCount,
                'canDiscardDraft' => $canDiscardDraft,
            ])
        @endif
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
