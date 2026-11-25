@php
    /**
     * @var array{
     *     total: int,
     *     ready_new: int,
     *     already_onboarded: int,
     *     blocked_count: int,
     *     skipped_count: int,
     *     warnings_count: int,
     *     prior_subjects_total: int,
     *     prior_subjects_ready: int,
     *     prior_subjects_blocked: int
     * } $summary
     * @var list<array<string, mixed>> $rows
     * @var list<array<string, mixed>> $priorSubjects
     * @var string $reviewTab
     * @var list<string> $trackedFixRowKeys
     * @var array<string, string> $standingOptions
     */
    $summary = $summary ?? [
        'total' => 0,
        'ready_new' => 0,
        'already_onboarded' => 0,
        'blocked_count' => 0,
        'skipped_count' => 0,
        'warnings_count' => 0,
        'prior_subjects_total' => 0,
        'prior_subjects_ready' => 0,
        'prior_subjects_blocked' => 0,
    ];
    $rows = $rows ?? [];
    $priorSubjects = $priorSubjects ?? [];
    $trackedFixRowKeys = $trackedFixRowKeys ?? [];
    $standingOptions = $standingOptions ?? [];

    $readyCount = (int) ($summary['ready_new'] ?? 0);
    $alreadyOnboardedCount = (int) ($summary['already_onboarded'] ?? 0);
    $blockedCount = (int) ($summary['blocked_count'] ?? 0);
    $skippedCount = (int) ($summary['skipped_count'] ?? 0);
    $priorReadyCount = (int) ($summary['prior_subjects_ready'] ?? 0);
    $priorBlockedCount = (int) ($summary['prior_subjects_blocked'] ?? 0);
    $priorTotalCount = (int) ($summary['prior_subjects_total'] ?? 0);

    $activeTab = $reviewTab ?? ($blockedCount > 0 ? 'fix_required' : 'ready');

    $fixRows = [];
    $readyRows = [];
    $alreadyOnboardedRows = [];

    foreach ($rows as $row) {
        $status = (string) ($row['status'] ?? '');
        $rowKey = (string) ($row['row_key'] ?? '');

        if ($status === \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusBlocked || in_array($rowKey, $trackedFixRowKeys, true)) {
            $fixRows[] = $row;
        }

        if ($status === \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusReady) {
            $readyRows[] = $row;
        }

        if ($status === \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusAlreadyOnboarded) {
            $alreadyOnboardedRows[] = $row;
        }
    }

    $tableRows = match ($activeTab) {
        'ready' => $readyRows,
        'already_onboarded' => $alreadyOnboardedRows,
        default => $rows,
    };
@endphp

<div class="tala-import-preview">
    {{-- 4 Compact Metric Tiles --}}
    <div class="tala-import-preview__metrics" role="region" aria-label="Onboarding preview summary">
        <div @class([
            'tala-import-preview__metric',
            'is-ready' => $readyCount > 0,
        ])>
            <span class="tala-import-preview__metric-label">Ready to onboard</span>
            <strong class="tala-import-preview__metric-value">{{ $readyCount }}</strong>
            <span class="tala-import-preview__metric-caption">
                New student accounts &amp; profiles
            </span>
        </div>

        <div class="tala-import-preview__metric">
            <span class="tala-import-preview__metric-label">Already onboarded</span>
            <strong class="tala-import-preview__metric-value">{{ $alreadyOnboardedCount }}</strong>
            <span class="tala-import-preview__metric-caption">
                {{ $alreadyOnboardedCount > 0 ? 'Safely skipped (idempotent)' : 'No existing conflicts' }}
            </span>
        </div>

        <div @class([
            'tala-import-preview__metric',
            'is-warning' => $priorBlockedCount > 0,
        ])>
            <span class="tala-import-preview__metric-label">Prior subjects ready</span>
            <strong class="tala-import-preview__metric-value">{{ $priorReadyCount }}</strong>
            <span class="tala-import-preview__metric-caption">
                @if ($priorBlockedCount > 0)
                    {{ $priorBlockedCount }} prior {{ \Illuminate\Support\Str::plural('row', $priorBlockedCount) }} blocked
                @elseif ($priorTotalCount > 0)
                    From Sheet 2 (Prior Subjects)
                @else
                    Sheet 2 optional
                @endif
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

    {{-- Tab Filter Bar --}}
    @if ($rows !== [] || $priorSubjects !== [])
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

            <x-filament::tabs.item
                :active="$activeTab === 'ready'"
                icon="heroicon-m-check-circle"
                wire:click="setReviewTab('ready')"
            >
                Ready ({{ $readyCount }})
            </x-filament::tabs.item>

            @if ($alreadyOnboardedCount > 0)
                <x-filament::tabs.item
                    :active="$activeTab === 'already_onboarded'"
                    icon="heroicon-m-check-badge"
                    wire:click="setReviewTab('already_onboarded')"
                >
                    Already onboarded ({{ $alreadyOnboardedCount }})
                </x-filament::tabs.item>
            @endif

            @if ($priorSubjects !== [])
                <x-filament::tabs.item
                    :active="$activeTab === 'prior_subjects'"
                    :badge="$priorBlockedCount > 0 ? $priorBlockedCount : null"
                    badge-color="warning"
                    icon="heroicon-m-book-open"
                    wire:click="setReviewTab('prior_subjects')"
                >
                    Prior subjects ({{ $priorTotalCount }})
                </x-filament::tabs.item>
            @endif

            <x-filament::tabs.item
                :active="$activeTab === 'all'"
                wire:click="setReviewTab('all')"
            >
                All students ({{ count($rows) }})
            </x-filament::tabs.item>
        </x-tala-navigation>
    @endif

    {{-- Tab Content --}}
    @if ($rows === [] && $priorSubjects === [])
        <div class="tala-import-preview__empty">
            <p>No student rows were found in the selected worksheet. Check your file or column mapping in the previous steps.</p>
        </div>
    @elseif ($activeTab === 'fix_required')
        <div class="tala-import-fix-section">
            @if ($blockedCount > 1)
                <div class="tala-import-fix-toolbar">
                    <span class="tala-import-fix-toolbar__copy">
                        Fix missing or invalid student details inline below, or skip rows you do not want to onboard now.
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
                    <p>All student rows have been resolved or skipped.</p>
                </div>
            @else
                <div class="tala-import-fix-list">
                    @foreach ($fixRows as $row)
                        @php
                            $rowKey = (string) ($row['row_key'] ?? '');
                            $rowStatus = (string) ($row['status'] ?? 'blocked');
                            $isRowBlocked = $rowStatus === \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusBlocked;
                            $isRowSkipped = $rowStatus === \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusSkipped || ! empty($row['skip']);
                            $isAlreadyOnboarded = $rowStatus === \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusAlreadyOnboarded;
                            $issueBadge = (string) ($row['issue_badge'] ?? $row['status_label'] ?? 'Fix required');
                        @endphp

                        <article
                            wire:key="fix-student-row-{{ $rowKey }}"
                            @class([
                                'tala-import-fix-card',
                                'is-blocked' => $isRowBlocked,
                                'is-skipped' => $isRowSkipped || $isAlreadyOnboarded,
                                'is-resolved' => ! $isRowBlocked && ! $isRowSkipped && ! $isAlreadyOnboarded,
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
                                    @elseif ($isAlreadyOnboarded)
                                        <x-filament::badge size="sm" color="gray">
                                            Already onboarded
                                        </x-filament::badge>
                                    @else
                                        <x-filament::badge size="sm" color="success">
                                            Fixed: {{ $row['status_label'] }}
                                        </x-filament::badge>
                                    @endif

                                    <strong class="tala-import-fix-card__subject">
                                        {{ ($row['student_number'] ?? '') !== '' ? $row['student_number'] : 'No Student #' }}
                                        ·
                                        {{ ($row['student_name'] ?? '') !== '' ? $row['student_name'] : 'Unnamed student' }}
                                    </strong>
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

                            @if (! $isRowSkipped)
                                <div class="tala-import-fix-card__fields">
                                    <div class="tala-import-field">
                                        <label for="fix-{{ $rowKey }}-num" class="tala-import-field__label">Student Number</label>
                                        <x-filament::input.wrapper>
                                            <x-filament::input
                                                id="fix-{{ $rowKey }}-num"
                                                type="text"
                                                wire:model.live.debounce.300ms="rowOverrides.{{ $rowKey }}.student_number"
                                                placeholder="e.g. SIA-2023-0045"
                                                maxlength="64"
                                            />
                                        </x-filament::input.wrapper>
                                    </div>

                                    <div class="tala-import-field">
                                        <label for="fix-{{ $rowKey }}-fname" class="tala-import-field__label">First Name</label>
                                        <x-filament::input.wrapper>
                                            <x-filament::input
                                                id="fix-{{ $rowKey }}-fname"
                                                type="text"
                                                wire:model.live.debounce.300ms="rowOverrides.{{ $rowKey }}.first_name"
                                                maxlength="255"
                                            />
                                        </x-filament::input.wrapper>
                                    </div>

                                    <div class="tala-import-field">
                                        <label for="fix-{{ $rowKey }}-lname" class="tala-import-field__label">Last Name</label>
                                        <x-filament::input.wrapper>
                                            <x-filament::input
                                                id="fix-{{ $rowKey }}-lname"
                                                type="text"
                                                wire:model.live.debounce.300ms="rowOverrides.{{ $rowKey }}.last_name"
                                                maxlength="255"
                                            />
                                        </x-filament::input.wrapper>
                                    </div>

                                    <div class="tala-import-field tala-import-field--wide">
                                        <label for="fix-{{ $rowKey }}-email" class="tala-import-field__label">Email</label>
                                        <x-filament::input.wrapper>
                                            <x-filament::input
                                                id="fix-{{ $rowKey }}-email"
                                                type="email"
                                                wire:model.live.debounce.300ms="rowOverrides.{{ $rowKey }}.email"
                                                maxlength="255"
                                            />
                                        </x-filament::input.wrapper>
                                    </div>

                                    <div class="tala-import-field">
                                        <label for="fix-{{ $rowKey }}-prog" class="tala-import-field__label">Program Code</label>
                                        <x-filament::input.wrapper>
                                            <x-filament::input
                                                id="fix-{{ $rowKey }}-prog"
                                                type="text"
                                                wire:model.live.debounce.300ms="rowOverrides.{{ $rowKey }}.program_code"
                                                placeholder="e.g. DIT"
                                                maxlength="64"
                                            />
                                        </x-filament::input.wrapper>
                                    </div>

                                    <div class="tala-import-field">
                                        <label for="fix-{{ $rowKey }}-cv" class="tala-import-field__label">Curriculum Code</label>
                                        <x-filament::input.wrapper>
                                            <x-filament::input
                                                id="fix-{{ $rowKey }}-cv"
                                                type="text"
                                                wire:model.live.debounce.300ms="rowOverrides.{{ $rowKey }}.curriculum_version_code"
                                                placeholder="Optional if single active"
                                                maxlength="64"
                                            />
                                        </x-filament::input.wrapper>
                                    </div>

                                    <div class="tala-import-field">
                                        <label for="fix-{{ $rowKey }}-standing" class="tala-import-field__label">Standing</label>
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select
                                                id="fix-{{ $rowKey }}-standing"
                                                wire:model.live="rowOverrides.{{ $rowKey }}.academic_standing"
                                            >
                                                @foreach ($standingOptions as $standingVal => $standingLabel)
                                                    <option value="{{ $standingVal }}">{{ $standingLabel }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                    </div>
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    @elseif ($activeTab === 'prior_subjects')
        @if ($priorSubjects === [])
            <div class="tala-import-preview__empty">
                <p>No Sheet 2 (Prior Subjects) rows were found in this workbook.</p>
            </div>
        @else
            <div class="tala-import-preview__table-wrap is-full-page" tabindex="0" role="region" aria-label="Parsed prior subject rows">
                <table class="tala-import-preview__table">
                    <thead>
                        <tr>
                            <th scope="col" class="tala-import-preview__col-row">Row</th>
                            <th scope="col">Student Number</th>
                            <th scope="col">Subject Code</th>
                            <th scope="col">Period</th>
                            <th scope="col">Grade / Status</th>
                            <th scope="col">Validation</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($priorSubjects as $prior)
                            @php
                                $priorStatus = (string) ($prior['status'] ?? 'ready');
                                $isPriorBlocked = $priorStatus === \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusBlocked;
                                $periodLabel = collect([
                                    filled($prior['year_level'] ?? null) ? 'Year '.$prior['year_level'] : null,
                                    $prior['academic_year_label'] ?? null,
                                    $prior['term_label'] ?? null,
                                ])->filter()->implode(' · ');
                            @endphp
                            <tr @class(['is-blocked' => $isPriorBlocked])>
                                <td class="tala-import-preview__cell-row">#{{ $prior['source_row'] }}</td>
                                <td><strong>{{ ($prior['student_number'] ?? '') !== '' ? $prior['student_number'] : 'Missing' }}</strong></td>
                                <td><span class="tala-import-preview__code">{{ ($prior['subject_code'] ?? '') !== '' ? $prior['subject_code'] : 'Missing' }}</span></td>
                                <td>{{ $periodLabel !== '' ? $periodLabel : 'Prior Institutional Record' }}</td>
                                <td>{{ $prior['final_grade'] ?? 'CREDIT' }} ({{ $prior['result_status'] ?? 'credited' }})</td>
                                <td class="tala-import-preview__cell-status">
                                    <div class="tala-import-preview__status-stack">
                                        <div class="tala-import-preview__status-badges">
                                            <x-filament::badge size="sm" :color="$isPriorBlocked ? 'danger' : 'success'">
                                                {{ $prior['status_label'] ?? ($isPriorBlocked ? 'Blocked' : 'Ready') }}
                                            </x-filament::badge>
                                        </div>
                                        @if (! empty($prior['errors']))
                                            <ul class="tala-import-preview__reasons tala-import-preview__reasons--error">
                                                @foreach ($prior['errors'] as $errorText)
                                                    <li>{{ $errorText }}</li>
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
    @elseif ($tableRows === [])
        <div class="tala-import-preview__empty">
            <p>
                @if ($activeTab === 'ready')
                    No new students are currently ready to onboard.
                @elseif ($activeTab === 'already_onboarded')
                    No incoming rows match students already onboarded in TALA.
                @else
                    No rows match this filter.
                @endif
            </p>
        </div>
    @else
        <div class="tala-import-preview__table-wrap is-full-page" tabindex="0" role="region" aria-label="Parsed ongoing student rows">
            <table class="tala-import-preview__table">
                <thead>
                    <tr>
                        <th scope="col" class="tala-import-preview__col-row">Row</th>
                        <th scope="col">Student</th>
                        <th scope="col">Email</th>
                        <th scope="col">Program &amp; Curriculum</th>
                        <th scope="col">Standing &amp; Notes</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tableRows as $row)
                        @php
                            $status = (string) ($row['status'] ?? 'ready');
                            $badgeColor = match ($status) {
                                \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusReady => 'success',
                                \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusAlreadyOnboarded,
                                \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusSkipped => 'gray',
                                \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusBlocked => 'danger',
                                default => 'gray',
                            };
                            $inlineCreditsCount = count((array) ($row['resolved_inline_prior_credits'] ?? []));
                        @endphp
                        <tr @class([
                            'is-blocked' => $status === \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusBlocked,
                            'is-skipped' => in_array($status, [
                                \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusSkipped,
                                \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusAlreadyOnboarded,
                            ], true),
                            'has-warning' => ! empty($row['warnings']) && $status === \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusReady,
                        ])>
                            <td class="tala-import-preview__cell-row">#{{ $row['source_row'] }}</td>
                            <td class="tala-import-preview__cell-subject">
                                <div class="tala-import-preview__subject-main">
                                    <span class="tala-import-preview__code">
                                        {{ ($row['student_number'] ?? '') !== '' ? $row['student_number'] : 'Missing #' }}
                                    </span>
                                    <span class="tala-import-preview__title">
                                        {{ ($row['student_name'] ?? '') !== '' ? $row['student_name'] : 'Missing name' }}
                                    </span>
                                </div>
                            </td>
                            <td>{{ ($row['email'] ?? '') !== '' ? $row['email'] : 'Missing email' }}</td>
                            <td>
                                <div class="tala-import-preview__units-main">
                                    <strong>{{ ($row['program_code'] ?? '') !== '' ? $row['program_code'] : 'Missing program' }}</strong>
                                    @if (filled($row['curriculum_version_code'] ?? null))
                                        <span>· {{ $row['curriculum_version_code'] }}</span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <div class="tala-import-preview__subject-tags">
                                    <span class="tala-import-preview__term-chip">{{ $row['academic_standing'] ?? 'Regular' }}</span>
                                    @if (filled($row['financial_hold_note'] ?? null))
                                        <x-filament::badge size="sm" color="warning">Prior hold</x-filament::badge>
                                    @endif
                                    @if ($inlineCreditsCount > 0)
                                        <x-filament::badge size="sm" color="gray">{{ $inlineCreditsCount }} inline {{ \Illuminate\Support\Str::plural('subject', $inlineCreditsCount) }}</x-filament::badge>
                                    @endif
                                </div>
                            </td>
                            <td class="tala-import-preview__cell-status">
                                <div class="tala-import-preview__status-stack">
                                    <div class="tala-import-preview__status-badges">
                                        <x-filament::badge size="sm" :color="$badgeColor" class="tala-import-status-badge">
                                            {{ $row['status_label'] ?? '' }}
                                        </x-filament::badge>
                                    </div>

                                    @if (! empty($row['already_onboarded_reason']))
                                        <p class="tala-import-preview__calm-note">{{ $row['already_onboarded_reason'] }}</p>
                                    @endif

                                    @if (! empty($row['errors']))
                                        <ul class="tala-import-preview__reasons tala-import-preview__reasons--error">
                                            @foreach ($row['errors'] as $errorText)
                                                <li>{{ $errorText }}</li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    @if (! empty($row['warnings']) && $status === \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusReady)
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
