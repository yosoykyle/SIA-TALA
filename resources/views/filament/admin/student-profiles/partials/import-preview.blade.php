@php
    /**
     * @var array{
     *     total: int,
     *     ready_new: int,
     *     update_unactivated: int,
     *     already_onboarded: int,
     *     blocked_count: int,
     *     skipped_count: int,
     *     warnings_count: int,
     *     applicant_collisions_blocked: int,
     *     applicant_links_opted_in: int,
     *     estimated_prior_credits: int
     * } $summary
     * @var list<array<string, mixed>> $rows
     * @var string $reviewTab
     * @var list<string> $trackedFixRowKeys
     * @var array<string, string> $standingOptions
     * @var array<string, string> $lifecycleOptions
     */
    $summary = $summary ?? [
        'total' => 0,
        'ready_new' => 0,
        'update_unactivated' => 0,
        'already_onboarded' => 0,
        'blocked_count' => 0,
        'skipped_count' => 0,
        'warnings_count' => 0,
        'applicant_collisions_blocked' => 0,
        'applicant_links_opted_in' => 0,
        'estimated_prior_credits' => 0,
    ];
    $rows = $rows ?? [];
    $trackedFixRowKeys = $trackedFixRowKeys ?? [];
    $standingOptions = $standingOptions ?? [];
    $lifecycleOptions = $lifecycleOptions ?? [];

    $readyCount = (int) ($summary['ready_new'] ?? 0);
    $updateCount = (int) ($summary['update_unactivated'] ?? 0);
    $alreadyOnboardedCount = (int) ($summary['already_onboarded'] ?? 0);
    $blockedCount = (int) ($summary['blocked_count'] ?? 0);
    $skippedCount = (int) ($summary['skipped_count'] ?? 0);
    $estimatedPriorCredits = (int) ($summary['estimated_prior_credits'] ?? 0);

    $activeTab = $reviewTab ?? ($blockedCount > 0 ? 'fix_required' : 'ready');

    $fixRows = [];
    $readyRows = [];
    $updateRows = [];
    $alreadyOnboardedRows = [];

    foreach ($rows as $row) {
        $status = (string) ($row['status'] ?? '');
        $rowKey = (string) ($row['row_key'] ?? '');

        if ($status === \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusBlocked
            || in_array($rowKey, $trackedFixRowKeys, true)
            || ! empty($row['is_applicant_collision'])) {
            $fixRows[] = $row;
        }

        if ($status === \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusReady) {
            $readyRows[] = $row;
        }

        if ($status === \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusUpdateUnactivated) {
            $updateRows[] = $row;
        }

        if ($status === \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusAlreadyOnboarded) {
            $alreadyOnboardedRows[] = $row;
        }
    }

    $tableRows = match ($activeTab) {
        'ready' => $readyRows,
        'update_unactivated' => $updateRows,
        'already_onboarded' => $alreadyOnboardedRows,
        default => $rows,
    };
@endphp

<div class="tala-import-preview">
    {{-- 4 Compact Metric Tiles --}}
    <div class="tala-import-preview__metrics" role="region" aria-label="Cohort roster preview summary">
        <div @class([
            'tala-import-preview__metric',
            'is-ready' => $readyCount > 0,
        ])>
            <span class="tala-import-preview__metric-label">Ready to onboard</span>
            <strong class="tala-import-preview__metric-value">{{ $readyCount }}</strong>
            <span class="tala-import-preview__metric-caption">
                @if ($estimatedPriorCredits > 0)
                    {{ $estimatedPriorCredits }} prior {{ \Illuminate\Support\Str::plural('subject', $estimatedPriorCredits) }} auto-credited
                @else
                    New student accounts &amp; profiles
                @endif
            </span>
        </div>

        <div @class([
            'tala-import-preview__metric',
            'is-ready' => $updateCount > 0,
        ])>
            <span class="tala-import-preview__metric-label">Unactivated updates</span>
            <strong class="tala-import-preview__metric-value">{{ $updateCount }}</strong>
            <span class="tala-import-preview__metric-caption">
                {{ $updateCount > 0 ? 'Not yet signed in or enrolled — safe to update' : 'No existing unactivated profiles' }}
            </span>
        </div>

        <div class="tala-import-preview__metric">
            <span class="tala-import-preview__metric-label">Already active</span>
            <strong class="tala-import-preview__metric-value">{{ $alreadyOnboardedCount }}</strong>
            <span class="tala-import-preview__metric-caption">
                {{ $alreadyOnboardedCount > 0 ? 'Left unchanged in TALA' : 'No active student conflicts' }}
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
    @if ($rows !== [])
        <x-tala-navigation compact stretch label="Filter imported student rows">
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

            @if ($updateCount > 0)
                <x-filament::tabs.item
                    :active="$activeTab === 'update_unactivated'"
                    icon="heroicon-m-arrow-path"
                    wire:click="setReviewTab('update_unactivated')"
                >
                    Unactivated updates ({{ $updateCount }})
                </x-filament::tabs.item>
            @endif

            @if ($alreadyOnboardedCount > 0)
                <x-filament::tabs.item
                    :active="$activeTab === 'already_onboarded'"
                    icon="heroicon-m-check-badge"
                    wire:click="setReviewTab('already_onboarded')"
                >
                    Already active ({{ $alreadyOnboardedCount }})
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
    @if ($rows === [])
        <div class="tala-import-preview__empty">
            <p>No student rows were found in the selected worksheet. Check your file or column mapping in the previous steps.</p>
        </div>
    @elseif ($activeTab === 'fix_required')
        <div class="tala-import-fix-section">
            @if ($blockedCount > 1)
                <div class="tala-import-fix-toolbar">
                    <span class="tala-import-fix-toolbar__copy">
                        Fix highlighted fields below (validated when you leave each field), or skip rows you do not want to import now.
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
                            $issueBadge = (string) ($row['status_label'] ?? $row['issue_badge'] ?? 'Fix required');
                            $fieldErrors = is_array($row['field_errors'] ?? null) ? $row['field_errors'] : [];
                            $isApplicantCollision = ! empty($row['is_applicant_collision']);

                            $errFor = static fn (string $field): ?string => ! empty($fieldErrors[$field])
                                ? implode(' ', (array) $fieldErrors[$field])
                                : null;
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
                                            Already active
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

                            {{-- Explicit Opt-in Toggle for Applicant-Email Collision --}}
                            @if (! $isRowSkipped && $isApplicantCollision)
                                <label class="tala-import-applicant-optin">
                                    <input
                                        type="checkbox"
                                        wire:model.live="rowOverrides.{{ $rowKey }}.link_existing_applicant"
                                        class="tala-import-checkbox"
                                    >
                                    <span class="tala-import-mode-option__copy">
                                        <strong>Link existing applicant account &amp; archive draft intake</strong>
                                        <span>Attaches this student profile to the existing applicant user for {{ $row['email'] }} and withdraws any open draft admissions intake.</span>
                                    </span>
                                </label>
                            @endif

                            @if (! $isRowSkipped)
                                <div class="tala-import-fix-card__fields">
                                    {{-- Student Number --}}
                                    @php $errNum = $errFor('student_number'); @endphp
                                    <div class="tala-import-field">
                                        <label for="fix-{{ $rowKey }}-student_number" class="tala-import-field__label">Student Number</label>
                                        <x-filament::input.wrapper :valid="$errNum === null">
                                            <x-filament::input
                                                id="fix-{{ $rowKey }}-student_number"
                                                type="text"
                                                wire:model.blur="rowOverrides.{{ $rowKey }}.student_number"
                                                placeholder="e.g. SIA-2023-0045"
                                                maxlength="64"
                                                autocorrect="off"
                                                autocapitalize="none"
                                                spellcheck="false"
                                                aria-invalid="{{ $errNum !== null ? 'true' : 'false' }}"
                                                :aria-describedby="$errNum !== null ? 'fix-'.$rowKey.'-student_number-err' : null"
                                            />
                                        </x-filament::input.wrapper>
                                        @if ($errNum !== null)
                                            <p id="fix-{{ $rowKey }}-student_number-err" class="tala-import-field__error">{{ $errNum }}</p>
                                        @endif
                                    </div>

                                    {{-- First Name --}}
                                    @php $errFname = $errFor('first_name'); @endphp
                                    <div class="tala-import-field">
                                        <label for="fix-{{ $rowKey }}-first_name" class="tala-import-field__label">First Name</label>
                                        <x-filament::input.wrapper :valid="$errFname === null">
                                            <x-filament::input
                                                id="fix-{{ $rowKey }}-first_name"
                                                type="text"
                                                wire:model.blur="rowOverrides.{{ $rowKey }}.first_name"
                                                maxlength="255"
                                                aria-invalid="{{ $errFname !== null ? 'true' : 'false' }}"
                                                :aria-describedby="$errFname !== null ? 'fix-'.$rowKey.'-first_name-err' : null"
                                            />
                                        </x-filament::input.wrapper>
                                        @if ($errFname !== null)
                                            <p id="fix-{{ $rowKey }}-first_name-err" class="tala-import-field__error">{{ $errFname }}</p>
                                        @endif
                                    </div>

                                    {{-- Last Name --}}
                                    @php $errLname = $errFor('last_name'); @endphp
                                    <div class="tala-import-field">
                                        <label for="fix-{{ $rowKey }}-last_name" class="tala-import-field__label">Last Name</label>
                                        <x-filament::input.wrapper :valid="$errLname === null">
                                            <x-filament::input
                                                id="fix-{{ $rowKey }}-last_name"
                                                type="text"
                                                wire:model.blur="rowOverrides.{{ $rowKey }}.last_name"
                                                maxlength="255"
                                                aria-invalid="{{ $errLname !== null ? 'true' : 'false' }}"
                                                :aria-describedby="$errLname !== null ? 'fix-'.$rowKey.'-last_name-err' : null"
                                            />
                                        </x-filament::input.wrapper>
                                        @if ($errLname !== null)
                                            <p id="fix-{{ $rowKey }}-last_name-err" class="tala-import-field__error">{{ $errLname }}</p>
                                        @endif
                                    </div>

                                    {{-- Email --}}
                                    @php $errEmail = $errFor('email'); @endphp
                                    <div class="tala-import-field tala-import-field--wide">
                                        <label for="fix-{{ $rowKey }}-email" class="tala-import-field__label">Email</label>
                                        <x-filament::input.wrapper :valid="$errEmail === null">
                                            <x-filament::input
                                                id="fix-{{ $rowKey }}-email"
                                                type="email"
                                                wire:model.blur="rowOverrides.{{ $rowKey }}.email"
                                                maxlength="255"
                                                autocorrect="off"
                                                autocapitalize="none"
                                                spellcheck="false"
                                                aria-invalid="{{ $errEmail !== null ? 'true' : 'false' }}"
                                                :aria-describedby="$errEmail !== null ? 'fix-'.$rowKey.'-email-err' : null"
                                            />
                                        </x-filament::input.wrapper>
                                        @if ($errEmail !== null)
                                            <p id="fix-{{ $rowKey }}-email-err" class="tala-import-field__error">{{ $errEmail }}</p>
                                        @endif
                                    </div>

                                    {{-- Academic Standing --}}
                                    @php
                                        $errStanding = $errFor('academic_standing');
                                        $currentStandingVal = (string) ($row['raw_academic_standing'] ?? $row['academic_standing'] ?? 'Regular');
                                        $standingInList = array_key_exists($currentStandingVal, $standingOptions);
                                    @endphp
                                    <div class="tala-import-field">
                                        <label for="fix-{{ $rowKey }}-academic_standing" class="tala-import-field__label">Academic Standing</label>
                                        <x-filament::input.wrapper :valid="$errStanding === null">
                                            <x-filament::input.select
                                                id="fix-{{ $rowKey }}-academic_standing"
                                                wire:model.live="rowOverrides.{{ $rowKey }}.academic_standing"
                                                aria-invalid="{{ $errStanding !== null ? 'true' : 'false' }}"
                                                :aria-describedby="$errStanding !== null ? 'fix-'.$rowKey.'-academic_standing-err' : null"
                                            >
                                                @if (! $standingInList && $currentStandingVal !== '')
                                                    <option value="{{ $currentStandingVal }}">Invalid: {{ $currentStandingVal }}</option>
                                                @endif
                                                @foreach ($standingOptions as $standingVal => $standingLabel)
                                                    <option value="{{ $standingVal }}">{{ $standingLabel }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                        @if ($errStanding !== null)
                                            <p id="fix-{{ $rowKey }}-academic_standing-err" class="tala-import-field__error">{{ $errStanding }}</p>
                                        @endif
                                    </div>

                                    {{-- Lifecycle Status --}}
                                    @php
                                        $errLifecycle = $errFor('lifecycle_status');
                                        $currentLifecycleVal = (string) ($row['raw_lifecycle_status'] ?? $row['lifecycle_status'] ?? 'ACTIVE');
                                        $lifecycleInList = array_key_exists($currentLifecycleVal, $lifecycleOptions);
                                    @endphp
                                    <div class="tala-import-field">
                                        <label for="fix-{{ $rowKey }}-lifecycle_status" class="tala-import-field__label">Lifecycle Status</label>
                                        <x-filament::input.wrapper :valid="$errLifecycle === null">
                                            <x-filament::input.select
                                                id="fix-{{ $rowKey }}-lifecycle_status"
                                                wire:model.live="rowOverrides.{{ $rowKey }}.lifecycle_status"
                                                aria-invalid="{{ $errLifecycle !== null ? 'true' : 'false' }}"
                                                :aria-describedby="$errLifecycle !== null ? 'fix-'.$rowKey.'-lifecycle_status-err' : null"
                                            >
                                                @if (! $lifecycleInList && $currentLifecycleVal !== '')
                                                    <option value="{{ $currentLifecycleVal }}">Invalid: {{ $currentLifecycleVal }}</option>
                                                @endif
                                                @foreach ($lifecycleOptions as $lcVal => $lcLabel)
                                                    <option value="{{ $lcVal }}">{{ $lcLabel }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                        @if ($errLifecycle !== null)
                                            <p id="fix-{{ $rowKey }}-lifecycle_status-err" class="tala-import-field__error">{{ $errLifecycle }}</p>
                                        @endif
                                    </div>

                                    {{-- Birth Date --}}
                                    @php $errDob = $errFor('birth_date'); @endphp
                                    <div class="tala-import-field">
                                        <label for="fix-{{ $rowKey }}-birth_date" class="tala-import-field__label">Birth Date</label>
                                        <x-filament::input.wrapper :valid="$errDob === null">
                                            <x-filament::input
                                                id="fix-{{ $rowKey }}-birth_date"
                                                type="text"
                                                wire:model.blur="rowOverrides.{{ $rowKey }}.birth_date"
                                                placeholder="YYYY-MM-DD"
                                                autocorrect="off"
                                                autocapitalize="none"
                                                spellcheck="false"
                                                aria-invalid="{{ $errDob !== null ? 'true' : 'false' }}"
                                                :aria-describedby="$errDob !== null ? 'fix-'.$rowKey.'-birth_date-err' : null"
                                            />
                                        </x-filament::input.wrapper>
                                        @if ($errDob !== null)
                                            <p id="fix-{{ $rowKey }}-birth_date-err" class="tala-import-field__error">{{ $errDob }}</p>
                                        @endif
                                    </div>

                                    {{-- LRN --}}
                                    @php $errLrn = $errFor('prior_identifier'); @endphp
                                    <div class="tala-import-field">
                                        <label for="fix-{{ $rowKey }}-prior_identifier" class="tala-import-field__label">LRN</label>
                                        <x-filament::input.wrapper :valid="$errLrn === null">
                                            <x-filament::input
                                                id="fix-{{ $rowKey }}-prior_identifier"
                                                type="text"
                                                wire:model.blur="rowOverrides.{{ $rowKey }}.prior_identifier"
                                                maxlength="255"
                                                autocorrect="off"
                                                autocapitalize="none"
                                                spellcheck="false"
                                                aria-invalid="{{ $errLrn !== null ? 'true' : 'false' }}"
                                                :aria-describedby="$errLrn !== null ? 'fix-'.$rowKey.'-prior_identifier-err' : null"
                                            />
                                        </x-filament::input.wrapper>
                                        @if ($errLrn !== null)
                                            <p id="fix-{{ $rowKey }}-prior_identifier-err" class="tala-import-field__error">{{ $errLrn }}</p>
                                        @endif
                                    </div>

                                    {{-- Phone --}}
                                    @php $errPhone = $errFor('phone'); @endphp
                                    <div class="tala-import-field">
                                        <label for="fix-{{ $rowKey }}-phone" class="tala-import-field__label">Phone</label>
                                        <x-filament::input.wrapper :valid="$errPhone === null">
                                            <x-filament::input
                                                id="fix-{{ $rowKey }}-phone"
                                                type="text"
                                                wire:model.blur="rowOverrides.{{ $rowKey }}.phone"
                                                maxlength="64"
                                                aria-invalid="{{ $errPhone !== null ? 'true' : 'false' }}"
                                                :aria-describedby="$errPhone !== null ? 'fix-'.$rowKey.'-phone-err' : null"
                                            />
                                        </x-filament::input.wrapper>
                                        @if ($errPhone !== null)
                                            <p id="fix-{{ $rowKey }}-phone-err" class="tala-import-field__error">{{ $errPhone }}</p>
                                        @endif
                                    </div>

                                    {{-- Financial Hold Note --}}
                                    @php $errHold = $errFor('financial_hold_note'); @endphp
                                    <div class="tala-import-field tala-import-field--wide">
                                        <label for="fix-{{ $rowKey }}-financial_hold_note" class="tala-import-field__label">Financial Hold Note</label>
                                        <x-filament::input.wrapper :valid="$errHold === null">
                                            <x-filament::input
                                                id="fix-{{ $rowKey }}-financial_hold_note"
                                                type="text"
                                                wire:model.blur="rowOverrides.{{ $rowKey }}.financial_hold_note"
                                                placeholder="Optional prior balance note"
                                                maxlength="1000"
                                                aria-invalid="{{ $errHold !== null ? 'true' : 'false' }}"
                                                :aria-describedby="$errHold !== null ? 'fix-'.$rowKey.'-financial_hold_note-err' : null"
                                            />
                                        </x-filament::input.wrapper>
                                        @if ($errHold !== null)
                                            <p id="fix-{{ $rowKey }}-financial_hold_note-err" class="tala-import-field__error">{{ $errHold }}</p>
                                        @endif
                                    </div>

                                    @if ($errFor('program_code') !== null || $errFor('curriculum_version_code') !== null)
                                        @php $errProg = $errFor('program_code'); @endphp
                                        <div class="tala-import-field">
                                            <label for="fix-{{ $rowKey }}-program_code" class="tala-import-field__label">Program Code</label>
                                            <x-filament::input.wrapper :valid="$errProg === null">
                                                <x-filament::input
                                                    id="fix-{{ $rowKey }}-program_code"
                                                    type="text"
                                                    wire:model.blur="rowOverrides.{{ $rowKey }}.program_code"
                                                    maxlength="64"
                                                    autocorrect="off"
                                                    autocapitalize="none"
                                                    spellcheck="false"
                                                    aria-invalid="{{ $errProg !== null ? 'true' : 'false' }}"
                                                    :aria-describedby="$errProg !== null ? 'fix-'.$rowKey.'-program_code-err' : null"
                                                />
                                            </x-filament::input.wrapper>
                                            @if ($errProg !== null)
                                                <p id="fix-{{ $rowKey }}-program_code-err" class="tala-import-field__error">{{ $errProg }}</p>
                                            @endif
                                        </div>

                                        @php $errCv = $errFor('curriculum_version_code'); @endphp
                                        <div class="tala-import-field">
                                            <label for="fix-{{ $rowKey }}-curriculum_version_code" class="tala-import-field__label">Curriculum Version Code</label>
                                            <x-filament::input.wrapper :valid="$errCv === null">
                                                <x-filament::input
                                                    id="fix-{{ $rowKey }}-curriculum_version_code"
                                                    type="text"
                                                    wire:model.blur="rowOverrides.{{ $rowKey }}.curriculum_version_code"
                                                    maxlength="64"
                                                    autocorrect="off"
                                                    autocapitalize="none"
                                                    spellcheck="false"
                                                    aria-invalid="{{ $errCv !== null ? 'true' : 'false' }}"
                                                    :aria-describedby="$errCv !== null ? 'fix-'.$rowKey.'-curriculum_version_code-err' : null"
                                                />
                                            </x-filament::input.wrapper>
                                            @if ($errCv !== null)
                                                <p id="fix-{{ $rowKey }}-curriculum_version_code-err" class="tala-import-field__error">{{ $errCv }}</p>
                                            @endif
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
                    No new students are currently ready to onboard.
                @elseif ($activeTab === 'update_unactivated')
                    No existing unactivated profiles match rows in this file.
                @elseif ($activeTab === 'already_onboarded')
                    No incoming rows match students who are already active or enrolled.
                @else
                    No rows match this filter.
                @endif
            </p>
        </div>
    @else
        <div class="tala-import-preview__table-wrap is-full-page is-responsive-cards" tabindex="0" role="region" aria-label="Cohort roster validation preview">
            <table class="tala-import-preview__table">
                <thead>
                    <tr>
                        <th scope="col" class="tala-import-preview__col-row">Row</th>
                        <th scope="col">Student</th>
                        <th scope="col">Email</th>
                        <th scope="col">Cohort &amp; Quick-Credit</th>
                        <th scope="col">Standing &amp; Flags</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tableRows as $row)
                        @php
                            $status = (string) ($row['status'] ?? 'ready');
                            $badgeColor = match ($status) {
                                \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusReady,
                                \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusUpdateUnactivated => 'success',
                                \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusAlreadyOnboarded,
                                \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusSkipped => 'gray',
                                \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusBlocked => 'danger',
                                default => 'gray',
                            };
                            $creditsCount = (int) ($row['prior_credits_to_apply'] ?? 0);
                        @endphp
                        <tr @class([
                            'is-blocked' => $status === \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusBlocked,
                            'is-skipped' => in_array($status, [
                                \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusSkipped,
                                \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusAlreadyOnboarded,
                            ], true),
                            'has-warning' => ! empty($row['warnings']) && in_array($status, [
                                \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusReady,
                                \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusUpdateUnactivated,
                            ], true),
                        ])>
                            <td class="tala-import-preview__cell-row" data-label="Row">#{{ $row['source_row'] }}</td>
                            <th scope="row" class="tala-import-preview__cell-subject" data-label="Student">
                                <div class="tala-import-preview__subject-main">
                                    <span class="tala-import-preview__code">
                                        {{ ($row['student_number'] ?? '') !== '' ? $row['student_number'] : 'Missing #' }}
                                    </span>
                                    <span class="tala-import-preview__title">
                                        {{ ($row['student_name'] ?? '') !== '' ? $row['student_name'] : 'Missing name' }}
                                    </span>
                                </div>
                            </th>
                            <td data-label="Email">{{ ($row['email'] ?? '') !== '' ? $row['email'] : 'Missing email' }}</td>
                            <td data-label="Cohort & Quick-Credit">
                                <div class="tala-import-preview__units-main">
                                    <strong>{{ ($row['program_code'] ?? '') !== '' ? $row['program_code'] : 'Missing program' }}</strong>
                                    @if (filled($row['curriculum_version_code'] ?? null))
                                        <span>· {{ $row['curriculum_version_code'] }}</span>
                                    @endif
                                </div>
                                @if ($creditsCount > 0)
                                    <div class="tala-import-preview__hours-meta">
                                        +{{ $creditsCount }} curriculum {{ \Illuminate\Support\Str::plural('subject', $creditsCount) }} (CREDIT)
                                    </div>
                                @endif
                            </td>
                            <td data-label="Standing & Flags">
                                <div class="tala-import-preview__subject-tags">
                                    <span class="tala-import-preview__term-chip">{{ $row['academic_standing'] ?? 'Regular' }}</span>
                                    @if (filled($row['financial_hold_note'] ?? null))
                                        <x-filament::badge size="sm" color="warning">Prior balance hold</x-filament::badge>
                                    @endif
                                    @if (! empty($row['has_duplicate_warning']))
                                        <x-filament::badge size="sm" color="warning">Possible duplicate</x-filament::badge>
                                    @endif
                                    @if (! empty($row['is_applicant_collision']) && ! empty($row['link_existing_applicant']))
                                        <x-filament::badge size="sm" color="success">Link applicant</x-filament::badge>
                                    @endif
                                </div>
                            </td>
                            <td class="tala-import-preview__cell-status" data-label="Status">
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

                                    @if (! empty($row['warnings']) && in_array($status, [
                                        \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusReady,
                                        \App\Actions\StudentProfiles\OngoingStudentSpreadsheetImportService::StatusUpdateUnactivated,
                                    ], true))
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
