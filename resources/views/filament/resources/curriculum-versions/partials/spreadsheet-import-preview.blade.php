@php
    /**
     * @var bool $interactiveFullPage
     * @var bool $isCurriculum
     * @var string|null $sheetName
     * @var bool $detectedBanners
     * @var array{
     *     total: int,
     *     ready_new: int,
     *     ready_existing: int,
     *     ready_count?: int,
     *     already_in_draft_count?: int,
     *     existing_draft_entry_count?: int,
     *     warnings_count: int,
     *     blocked_count: int,
     *     skipped_count: int
     * } $summary
     * @var list<array<string, mixed>> $rows
     * @var list<array{label: string, subjects_count: int, total_units: float}> $placementGroups
     * @var string|null $reviewTab
     * @var list<string>|null $trackedFixRowKeys
     * @var array<int, string>|null $yearOptions
     * @var array<string, string>|null $semesterOptions
     */
    $interactiveFullPage = (bool) ($interactiveFullPage ?? false);
    $isCurriculum = (bool) ($isCurriculum ?? false);
    $summary = $summary ?? [
        'total' => 0,
        'ready_new' => 0,
        'ready_existing' => 0,
        'ready_count' => 0,
        'already_in_draft_count' => 0,
        'existing_draft_entry_count' => 0,
        'warnings_count' => 0,
        'blocked_count' => 0,
        'skipped_count' => 0,
    ];
    $rows = $rows ?? [];
    $placementGroups = $placementGroups ?? [];
    $trackedFixRowKeys = $trackedFixRowKeys ?? [];
    $yearOptions = $yearOptions ?? [];
    $semesterOptions = $semesterOptions ?? [];

    $readyNew = (int) ($summary['ready_new'] ?? 0);
    $readyExisting = (int) ($summary['ready_existing'] ?? 0);
    $readyCount = (int) ($summary['ready_count'] ?? ($readyNew + $readyExisting));
    $alreadyInDraftCount = (int) ($summary['already_in_draft_count'] ?? 0);
    $warningsCount = (int) ($summary['warnings_count'] ?? 0);
    $blockedCount = (int) ($summary['blocked_count'] ?? 0);
    $skippedCount = (int) ($summary['skipped_count'] ?? 0);

    $activeTab = $reviewTab ?? ($blockedCount > 0 ? 'fix_required' : 'ready');

    $fixRows = [];
    $noteRows = [];
    $alreadyInDraftRows = [];
    $readyRows = [];

    foreach ($rows as $row) {
        $status = (string) ($row['status'] ?? '');
        $rowKey = (string) ($row['row_key'] ?? '');

        if ($status === 'blocked' || ($interactiveFullPage && in_array($rowKey, $trackedFixRowKeys, true))) {
            $fixRows[] = $row;
        }

        if ($status !== 'blocked' && $status !== 'skipped' && $status !== 'already_in_draft' && ! empty($row['warnings'])) {
            $noteRows[] = $row;
        }

        if ($status === 'already_in_draft') {
            $alreadyInDraftRows[] = $row;
        }

        if (in_array($status, ['ready_new', 'ready_existing'], true)) {
            $readyRows[] = $row;
        }
    }

    if ($isCurriculum && $readyRows !== []) {
        $termOrderMap = [
            \App\Models\Term::TypeFirstSemester => 1,
            \App\Models\Term::TypeSecondSemester => 2,
            \App\Models\Term::TypeSpecialTerm => 3,
        ];
        usort($readyRows, static function (array $a, array $b) use ($termOrderMap): int {
            $yearCmp = ((int) ($a['year_level'] ?? 99)) <=> ((int) ($b['year_level'] ?? 99));

            if ($yearCmp !== 0) {
                return $yearCmp;
            }

            $termCmp = ($termOrderMap[(string) ($a['term_type'] ?? '')] ?? 9) <=> ($termOrderMap[(string) ($b['term_type'] ?? '')] ?? 9);

            if ($termCmp !== 0) {
                return $termCmp;
            }

            return ((int) ($a['source_row'] ?? 0)) <=> ((int) ($b['source_row'] ?? 0));
        });
    }

    $formatUnits = static fn (mixed $val): string => $val !== null && $val !== ''
        ? rtrim(rtrim(number_format((float) $val, 2, '.', ''), '0'), '.')
        : '—';

    $tableRows = match ($activeTab) {
        'notes' => $noteRows,
        'already_in_draft' => $alreadyInDraftRows,
        'ready' => $readyRows,
        default => $rows,
    };
@endphp

<div class="tala-import-preview">
    {{-- 4 Compact Metric Tiles --}}
    <div class="tala-import-preview__metrics" role="region" aria-label="Import summary">
        <div @class([
            'tala-import-preview__metric',
            'is-ready' => $readyCount > 0,
        ])>
            <span class="tala-import-preview__metric-label">Ready to import</span>
            <strong class="tala-import-preview__metric-value">{{ $readyCount }}</strong>
            <span class="tala-import-preview__metric-caption">
                {{ $readyNew }} new · {{ $readyExisting }} catalog {{ \Illuminate\Support\Str::plural('match', $readyExisting) }}
            </span>
        </div>

        <div class="tala-import-preview__metric">
            <span class="tala-import-preview__metric-label">
                {{ $isCurriculum && ($alreadyInDraftCount > 0 || $skippedCount === 0) ? 'Already in draft' : 'Skipped' }}
            </span>
            <strong class="tala-import-preview__metric-value">
                {{ $isCurriculum && ($alreadyInDraftCount > 0 || $skippedCount === 0) ? $alreadyInDraftCount : $skippedCount }}
            </strong>
            <span class="tala-import-preview__metric-caption">
                @if ($isCurriculum && $alreadyInDraftCount > 0)
                    Safely skipped in this draft{{ $skippedCount > 0 ? " · {$skippedCount} manual skip" : '' }}
                @elseif ($skippedCount > 0)
                    Marked to skip on import
                @else
                    {{ $isCurriculum ? 'No duplicates in this draft' : 'No rows skipped' }}
                @endif
            </span>
        </div>

        <div @class([
            'tala-import-preview__metric',
            'is-warning' => $warningsCount > 0,
        ])>
            <span class="tala-import-preview__metric-label">Notes</span>
            <strong class="tala-import-preview__metric-value">{{ $warningsCount }}</strong>
            <span class="tala-import-preview__metric-caption">
                {{ $warningsCount > 0 ? 'Valid rows with advisory notes' : 'No advisory notes' }}
            </span>
        </div>

        <div @class([
            'tala-import-preview__metric',
            'is-danger' => $blockedCount > 0,
        ])>
            <span class="tala-import-preview__metric-label">Fix required</span>
            <strong class="tala-import-preview__metric-value">{{ $blockedCount }}</strong>
            <span class="tala-import-preview__metric-caption">
                @if ($blockedCount > 0)
                    Edit inline or skip to proceed
                @elseif ($skippedCount > 0)
                    {{ $skippedCount }} {{ \Illuminate\Support\Str::plural('row', $skippedCount) }} skipped
                @else
                    No unresolved issues
                @endif
            </span>
        </div>
    </div>

    {{-- Tab Filter Bar (Full-Page Mode) --}}
    @if ($interactiveFullPage && $rows !== [])
        <x-tala-navigation compact stretch label="Filter import rows">
            @if ($blockedCount > 0 || $fixRows !== [])
                <x-filament::tabs.item
                    :active="$activeTab === 'fix_required'"
                    :badge="$blockedCount > 0 ? $blockedCount : null"
                    badge-color="danger"
                    icon="heroicon-m-exclamation-triangle"
                    wire:click="setReviewTab('fix_required')"
                >
                    Fix required ({{ $blockedCount }})
                </x-filament::tabs.item>
            @endif

            @if ($warningsCount > 0)
                <x-filament::tabs.item
                    :active="$activeTab === 'notes'"
                    icon="heroicon-m-information-circle"
                    wire:click="setReviewTab('notes')"
                >
                    Notes ({{ $warningsCount }})
                </x-filament::tabs.item>
            @endif

            @if ($isCurriculum && $alreadyInDraftCount > 0)
                <x-filament::tabs.item
                    :active="$activeTab === 'already_in_draft'"
                    icon="heroicon-m-check-badge"
                    wire:click="setReviewTab('already_in_draft')"
                >
                    Already in draft ({{ $alreadyInDraftCount }})
                </x-filament::tabs.item>
            @endif

            <x-filament::tabs.item
                :active="$activeTab === 'ready'"
                icon="heroicon-m-check-circle"
                wire:click="setReviewTab('ready')"
            >
                Ready ({{ $readyCount }})
            </x-filament::tabs.item>

            <x-filament::tabs.item
                :active="$activeTab === 'all'"
                wire:click="setReviewTab('all')"
            >
                All rows ({{ count($rows) }})
            </x-filament::tabs.item>
        </x-tala-navigation>
    @endif

    {{-- Curriculum Placement Breakdown --}}
    @if ($isCurriculum && $placementGroups !== [] && (! $interactiveFullPage || in_array($activeTab, ['ready', 'all'], true)))
        <div class="tala-import-preview__placements" role="region" aria-label="Placements by year and semester">
            <span class="tala-import-preview__placements-heading">Placements by term:</span>
            <div class="tala-import-preview__placements-list">
                @foreach ($placementGroups as $group)
                    <span class="tala-import-preview__placement-pill">
                        <strong>{{ $group['label'] }}</strong>
                        <span>
                            {{ $group['subjects_count'] }} {{ \Illuminate\Support\Str::plural('subject', $group['subjects_count']) }}
                            · {{ $formatUnits($group['total_units']) }} {{ (float) $group['total_units'] === 1.0 ? 'unit' : 'units' }}
                        </span>
                    </span>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Tab Content --}}
    @if ($rows === [])
        <div class="tala-import-preview__empty">
            <p>No subject rows were found in the selected worksheet. Check your file or column mapping in the previous steps.</p>
        </div>
    @elseif ($interactiveFullPage && $activeTab === 'fix_required')
        <div class="tala-import-fix-section">
            @if ($blockedCount > 1)
                <div class="tala-import-fix-toolbar">
                    <span class="tala-import-fix-toolbar__copy">
                        Fix missing codes, titles, units, or placements inline below, or skip rows you do not want to import now.
                    </span>
                    <x-filament::button
                        type="button"
                        size="sm"
                        color="gray"
                        outlined
                        wire:click="skipAllBlockedRows"
                    >
                        Skip all {{ $blockedCount }} unresolved rows
                    </x-filament::button>
                </div>
            @endif

            @if ($fixRows === [])
                <div class="tala-import-preview__empty">
                    <p>All rows have been resolved or skipped.</p>
                </div>
            @else
                <div class="tala-import-fix-list">
                    @foreach ($fixRows as $row)
                        @php
                            $rowKey = (string) ($row['row_key'] ?? '');
                            $rowStatus = (string) ($row['status'] ?? 'blocked');
                            $isRowBlocked = $rowStatus === 'blocked';
                            $isRowSkipped = $rowStatus === 'skipped' || ! empty($row['skip']);
                            $issueBadge = (string) ($row['issue_badge'] ?? $row['status_label'] ?? 'Fix required');
                            $editableFields = (array) ($row['editable_issue_fields'] ?? []);

                            if ($isRowBlocked && $editableFields === []) {
                                $editableFields = $isCurriculum
                                    ? ['code', 'title', 'credit_units', 'year_level', 'term_type']
                                    : ['code', 'title', 'credit_units'];
                            }
                        @endphp

                        <article
                            wire:key="fix-row-{{ $rowKey }}"
                            @class([
                                'tala-import-fix-card',
                                'is-blocked' => $isRowBlocked,
                                'is-skipped' => $isRowSkipped,
                                'is-resolved' => ! $isRowBlocked && ! $isRowSkipped,
                            ])
                        >
                            <div class="tala-import-fix-card__header">
                                <div class="tala-import-fix-card__identity">
                                    <span class="tala-import-fix-card__row-num">Row #{{ $row['source_row'] }}</span>

                                    @if ($isRowBlocked)
                                        <x-filament::badge size="sm" color="danger">
                                            {{ $issueBadge }}
                                        </x-filament::badge>
                                    @elseif ($isRowSkipped)
                                        <x-filament::badge size="sm" color="gray">
                                            Skipped
                                        </x-filament::badge>
                                    @else
                                        <x-filament::badge size="sm" color="success">
                                            Fixed: {{ $row['status_label'] }}
                                        </x-filament::badge>
                                    @endif

                                    <strong class="tala-import-fix-card__subject">
                                        {{ ($row['code'] ?? '') !== '' ? $row['code'] : 'No code' }}
                                        ·
                                        {{ ($row['title'] ?? '') !== '' ? $row['title'] : 'Untitled subject' }}
                                    </strong>

                                    @if ($isCurriculum && ! empty($row['placement_label']) && $row['placement_label'] !== '—')
                                        <span class="tala-import-fix-card__placement">{{ $row['placement_label'] }}</span>
                                    @endif
                                </div>

                                <x-filament::button
                                    type="button"
                                    size="sm"
                                    :color="$isRowSkipped ? 'primary' : 'gray'"
                                    :outlined="! $isRowSkipped"
                                    wire:click="toggleSkipRow('{{ $rowKey }}')"
                                >
                                    {{ $isRowSkipped ? 'Include row' : 'Skip this row' }}
                                </x-filament::button>
                            </div>

                            @if ($isRowBlocked && ! empty($row['errors']))
                                <ul class="tala-import-fix-card__errors">
                                    @foreach ($row['errors'] as $errorText)
                                        <li>{{ $errorText }}</li>
                                    @endforeach
                                </ul>
                            @endif

                            @if (! $isRowSkipped && $editableFields !== [])
                                <div class="tala-import-fix-card__fields">
                                    @if (in_array('code', $editableFields, true))
                                        <div class="tala-import-field">
                                            <label for="fix-{{ $rowKey }}-code" class="tala-import-field__label">Subject code</label>
                                            <x-filament::input.wrapper>
                                                <x-filament::input
                                                    id="fix-{{ $rowKey }}-code"
                                                    type="text"
                                                    wire:model.live.debounce.300ms="rowOverrides.{{ $rowKey }}.code"
                                                    placeholder="e.g. GE 1"
                                                    maxlength="64"
                                                />
                                            </x-filament::input.wrapper>
                                        </div>
                                    @endif

                                    @if (in_array('title', $editableFields, true))
                                        <div class="tala-import-field tala-import-field--wide">
                                            <label for="fix-{{ $rowKey }}-title" class="tala-import-field__label">Subject name</label>
                                            <x-filament::input.wrapper>
                                                <x-filament::input
                                                    id="fix-{{ $rowKey }}-title"
                                                    type="text"
                                                    wire:model.live.debounce.300ms="rowOverrides.{{ $rowKey }}.title"
                                                    placeholder="Descriptive subject title"
                                                    maxlength="255"
                                                />
                                            </x-filament::input.wrapper>
                                        </div>
                                    @endif

                                    @if (in_array('credit_units', $editableFields, true))
                                        <div class="tala-import-field">
                                            <label for="fix-{{ $rowKey }}-units" class="tala-import-field__label">Units</label>
                                            <x-filament::input.wrapper>
                                                <x-filament::input
                                                    id="fix-{{ $rowKey }}-units"
                                                    type="number"
                                                    step="0.25"
                                                    min="0"
                                                    max="30"
                                                    wire:model.live.debounce.300ms="rowOverrides.{{ $rowKey }}.credit_units"
                                                    placeholder="e.g. 3"
                                                />
                                            </x-filament::input.wrapper>
                                        </div>
                                    @endif

                                    @if ($isCurriculum && in_array('year_level', $editableFields, true))
                                        <div class="tala-import-field">
                                            <label for="fix-{{ $rowKey }}-year" class="tala-import-field__label">Year level</label>
                                            <x-filament::input.wrapper>
                                                <x-filament::input.select
                                                    id="fix-{{ $rowKey }}-year"
                                                    wire:model.live="rowOverrides.{{ $rowKey }}.year_level"
                                                >
                                                    <option value="">Select year</option>
                                                    @foreach ($yearOptions as $yearVal => $yearLabel)
                                                        <option value="{{ $yearVal }}">{{ $yearLabel }}</option>
                                                    @endforeach
                                                </x-filament::input.select>
                                            </x-filament::input.wrapper>
                                        </div>
                                    @endif

                                    @if ($isCurriculum && in_array('term_type', $editableFields, true))
                                        <div class="tala-import-field">
                                            <label for="fix-{{ $rowKey }}-term" class="tala-import-field__label">Semester</label>
                                            <x-filament::input.wrapper>
                                                <x-filament::input.select
                                                    id="fix-{{ $rowKey }}-term"
                                                    wire:model.live="rowOverrides.{{ $rowKey }}.term_type"
                                                >
                                                    <option value="">Select semester</option>
                                                    @foreach ($semesterOptions as $termVal => $termLabel)
                                                        <option value="{{ $termVal }}">{{ $termLabel }}</option>
                                                    @endforeach
                                                </x-filament::input.select>
                                            </x-filament::input.wrapper>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    @elseif ($tableRows === [])
        <div class="tala-import-preview__empty">
            <p>
                @if ($activeTab === 'ready')
                    No rows are currently ready to import.
                @elseif ($activeTab === 'notes')
                    No rows have advisory notes.
                @elseif ($activeTab === 'already_in_draft')
                    No incoming subjects match subjects already placed in this draft.
                @else
                    No rows match this filter.
                @endif
            </p>
        </div>
    @else
        {{-- Clean Full-Width Preview Table --}}
        <div @class(['tala-import-preview__table-wrap', 'is-full-page' => $interactiveFullPage]) tabindex="0" role="region" aria-label="Parsed spreadsheet rows">
            <table class="tala-import-preview__table">
                <thead>
                    <tr>
                        <th scope="col" class="tala-import-preview__col-row">Row</th>
                        <th scope="col">Subject</th>
                        @if ($isCurriculum)
                            <th scope="col" class="tala-import-preview__col-placement">Placement</th>
                        @endif
                        <th scope="col" class="tala-import-preview__col-units">Units &amp; hours</th>
                        <th scope="col">Prerequisites</th>
                        <th scope="col">Status &amp; notes</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tableRows as $row)
                        @php
                            $status = (string) ($row['status'] ?? 'ready_new');
                            $badgeColor = match ($status) {
                                'ready_new', 'ready_existing' => 'success',
                                'already_in_draft', 'skipped' => 'gray',
                                'blocked' => 'danger',
                                default => 'gray',
                            };
                            $displayStatusLabel = match ($status) {
                                'ready_existing' => 'Catalog match',
                                default => (string) ($row['issue_badge'] ?? $row['status_label'] ?? ''),
                            };
                            $lecHours = (float) ($row['lec_hours'] ?? 0);
                            $labHours = (float) ($row['lab_hours'] ?? 0);
                            $practicumHours = $row['practicum_hours'] ?? null;
                            $isExternallyArranged = ($row['scheduling_treatment'] ?? '') === \App\Models\CourseSpecification::SchedulingExternallyArranged;
                            $isTesda = ($row['academic_classification'] ?? '') === \App\Models\CourseSpecification::AcademicClassificationTesdaCompetency;
                            $isNonCredit = ($row['academic_classification'] ?? '') === \App\Models\CourseSpecification::AcademicClassificationNonCredit;

                            $hoursParts = [];
                            if ($isExternallyArranged) {
                                $hoursParts[] = $practicumHours !== null
                                    ? $formatUnits($practicumHours).'h practicum'
                                    : 'Externally arranged';
                            } else {
                                if ($lecHours > 0) {
                                    $hoursParts[] = $formatUnits($lecHours).'h Lec';
                                }
                                if ($labHours > 0) {
                                    $hoursParts[] = $formatUnits($labHours).'h Lab';
                                }
                            }
                            $hoursSummary = implode(' · ', $hoursParts);
                        @endphp
                        <tr @class([
                            'is-blocked' => $status === 'blocked',
                            'is-skipped' => $status === 'skipped' || $status === 'already_in_draft',
                            'has-warning' => $status !== 'blocked' && $status !== 'skipped' && $status !== 'already_in_draft' && ! empty($row['warnings']),
                        ])>
                            <td class="tala-import-preview__cell-row">
                                #{{ $row['source_row'] }}
                            </td>
                            <td class="tala-import-preview__cell-subject">
                                <div class="tala-import-preview__subject-main">
                                    <span class="tala-import-preview__code">
                                        {{ ($row['code'] ?? '') !== '' ? $row['code'] : 'Missing code' }}
                                    </span>
                                    <span class="tala-import-preview__title">
                                        {{ ($row['title'] ?? '') !== '' ? $row['title'] : 'Missing subject name' }}
                                    </span>
                                </div>
                                @if ($isTesda || $isExternallyArranged || $isNonCredit || ! empty($row['matched_course_code']))
                                    <div class="tala-import-preview__subject-tags">
                                        @if ($isTesda)
                                            <x-filament::badge size="sm" color="warning">TESDA Competency</x-filament::badge>
                                        @endif
                                        @if ($isNonCredit)
                                            <x-filament::badge size="sm" color="gray">Non-credit</x-filament::badge>
                                        @endif
                                        @if ($isExternallyArranged)
                                            <x-filament::badge size="sm" color="warning">Arranged / Practicum</x-filament::badge>
                                        @endif
                                        @if (! empty($row['matched_course_code']) && $row['matched_course_code'] !== ($row['code'] ?? ''))
                                            <span class="tala-import-preview__match-hint">
                                                Matches catalog {{ $row['matched_course_code'] }}
                                            </span>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            @if ($isCurriculum)
                                <td class="tala-import-preview__cell-placement">
                                    @if (($row['placement_label'] ?? '') !== '')
                                        <span class="tala-import-preview__term-chip">{{ $row['placement_label'] }}</span>
                                    @else
                                        <span class="tala-import-preview__prereq-none">—</span>
                                    @endif
                                </td>
                            @endif
                            <td class="tala-import-preview__cell-units">
                                <div class="tala-import-preview__units-main">
                                    @if ($row['credit_units'] !== null && $row['credit_units'] !== '')
                                        <strong>{{ $formatUnits($row['credit_units']) }}</strong>
                                        <span>{{ (float) $row['credit_units'] === 1.0 ? 'unit' : 'units' }}</span>
                                    @else
                                        <span class="tala-import-preview__missing">Missing units</span>
                                    @endif
                                </div>
                                @if ($hoursSummary !== '')
                                    <div class="tala-import-preview__hours-meta">{{ $hoursSummary }}</div>
                                @endif
                            </td>
                            <td class="tala-import-preview__cell-prereq">
                                @if (! empty($row['resolved_prerequisites']))
                                    <div class="tala-import-preview__prereq-list">
                                        @foreach ($row['resolved_prerequisites'] as $prereq)
                                            <x-filament::badge size="sm" color="gray">
                                                {{ $prereq['code'] }}
                                            </x-filament::badge>
                                        @endforeach
                                    </div>
                                @elseif (($row['prerequisites_text'] ?? '') !== '')
                                    <span class="tala-import-preview__prereq-raw">{{ $row['prerequisites_text'] }}</span>
                                @else
                                    <span class="tala-import-preview__prereq-none">None</span>
                                @endif
                            </td>
                            <td class="tala-import-preview__cell-status">
                                <div class="tala-import-preview__status-stack">
                                    <div class="tala-import-preview__status-badges">
                                        @if ($status === 'ready_existing')
                                            <span class="tala-import-status-pill tala-import-status-pill--catalog">
                                                {{ $displayStatusLabel }}
                                            </span>
                                        @else
                                            <x-filament::badge size="sm" :color="$badgeColor" class="tala-import-status-badge">
                                                {{ $displayStatusLabel }}
                                            </x-filament::badge>
                                        @endif
                                        @if (! empty($row['warnings']) && $status !== 'skipped' && $status !== 'already_in_draft')
                                            <x-filament::badge size="sm" color="warning" class="tala-import-status-badge">
                                                {{ count($row['warnings']) }} {{ \Illuminate\Support\Str::plural('note', count($row['warnings'])) }}
                                            </x-filament::badge>
                                        @endif
                                    </div>

                                    @if ($status === 'already_in_draft' && ! empty($row['already_in_draft_reason']))
                                        <p class="tala-import-preview__calm-note">{{ $row['already_in_draft_reason'] }}</p>
                                    @endif

                                    @if (! empty($row['errors']))
                                        <ul class="tala-import-preview__reasons tala-import-preview__reasons--error">
                                            @foreach ($row['errors'] as $errorText)
                                                <li>{{ $errorText }}</li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    @if (! empty($row['warnings']) && $status !== 'skipped' && $status !== 'already_in_draft')
                                        <ul class="tala-import-preview__reasons tala-import-preview__reasons--warning">
                                            @foreach ($row['warnings'] as $warningText)
                                                <li>{{ $warningText }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
