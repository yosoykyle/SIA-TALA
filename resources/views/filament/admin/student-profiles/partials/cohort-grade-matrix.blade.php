@php
    $matrixSummary = is_array($matrixPreview['summary'] ?? null) ? $matrixPreview['summary'] : null;
    $matrixChanges = is_array($matrixPreview['changes'] ?? null) ? $matrixPreview['changes'] : [];
    $matrixErrors = is_array($matrixPreview['errors'] ?? null) ? $matrixPreview['errors'] : [];
    $changesTotal = (int) ($matrixSummary['changes_total'] ?? 0);
    $upgradesCount = (int) ($matrixSummary['upgrades_count'] ?? 0);
    $newCreditsCount = (int) ($matrixSummary['new_credits_count'] ?? 0);
    $studentsAffected = (int) ($matrixSummary['students_with_changes'] ?? 0);
    $errorCount = (int) ($matrixSummary['error_count'] ?? 0);
@endphp

<div class="tala-import-workspace">
    {{-- Post-Commit Grade Matrix Summary --}}
    @if ($lastMatrixSummary !== null)
        <x-tala-access-alert variant="success" title="Prior grades updated">
            <p>
                Applied <strong>{{ $lastMatrixSummary['total_applied'] }}</strong> prior {{ \Illuminate\Support\Str::plural('grade', $lastMatrixSummary['total_applied']) }}
                ({{ $lastMatrixSummary['updated_cells'] }} upgraded, {{ $lastMatrixSummary['created_cells'] }} newly recorded) across
                <strong>{{ $lastMatrixSummary['students_affected'] }}</strong> {{ \Illuminate\Support\Str::plural('student', $lastMatrixSummary['students_affected']) }}.
                Completion readiness and official transcript snapshots have been refreshed automatically.
            </p>
        </x-tala-access-alert>
    @endif

    {{-- Step A: Download Pre-filled Prior Grade Sheet --}}
    <section class="tala-import-card" aria-labelledby="matrix-download-heading">
        <header class="tala-import-card__header">
            <div>
                <h2 id="matrix-download-heading" tabindex="-1">Download prior grade sheet (.xlsx)</h2>
                <p class="tala-import-card__subtitle">
                    Generate a pre-filled Excel grade sheet with students as rows and their curriculum subjects as columns. Paste official numeric grades (1.00–5.00), INC, DRP, CREDIT, or TC (external transfer) and upload it back below.
                </p>
            </div>
        </header>

        <div class="tala-import-card__body">
            <div class="tala-import-scope-bar">
                <div class="tala-import-field">
                    <label for="matrix-program" class="tala-import-field__label">
                        Program <span class="tala-import-required" aria-hidden="true">*</span>
                    </label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select
                            id="matrix-program"
                            wire:model.live="matrixProgramId"
                        >
                            <option value="">Select program...</option>
                            @foreach ($programs as $programOption)
                                <option value="{{ $programOption->id }}">
                                    {{ $programOption->code }} — {{ $programOption->name }}@if (! $programOption->is_active) (Teach-out)@endif
                                </option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                <div class="tala-import-field">
                    <label for="matrix-curriculum" class="tala-import-field__label">
                        Curriculum Version / Batch <span class="tala-import-required" aria-hidden="true">*</span>
                    </label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select
                            id="matrix-curriculum"
                            wire:model.live="matrixCurriculumVersionId"
                        >
                            <option value="">Select curriculum...</option>
                            @foreach ($matrixCurricula as $cvOption)
                                <option value="{{ $cvOption->id }}">
                                    {{ $cvOption->version_code }} — {{ $cvOption->batchLabel() }} ({{ \App\Models\CurriculumVersion::stateOptions()[$cvOption->state] ?? $cvOption->state }})
                                </option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                <div class="tala-import-field">
                    <label for="matrix-term" class="tala-import-field__label">
                        Prior Year &amp; Term <span class="tala-import-required" aria-hidden="true">*</span>
                    </label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select
                            id="matrix-term"
                            wire:model.live="matrixTermKey"
                        >
                            <option value="">Select period...</option>
                            @foreach ($matrixTermOptions as $termOpt)
                                <option value="{{ $termOpt['key'] }}">
                                    {{ $termOpt['label'] }} ({{ $termOpt['subject_count'] }} {{ \Illuminate\Support\Str::plural('subject', $termOpt['subject_count']) }})
                                </option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                <div class="tala-import-field">
                    <label for="matrix-entry-ay" class="tala-import-field__label">
                        Filter by Entry Academic Year (optional)
                    </label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select
                            id="matrix-entry-ay"
                            wire:model.live="matrixEntryAcademicYearId"
                        >
                            <option value="">All entry years in this curriculum</option>
                            @foreach ($matrixAcademicYears as $ayOption)
                                <option value="{{ $ayOption['id'] }}">{{ $ayOption['label'] }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
            </div>

            <div class="tala-import-template-bar">
                <div class="tala-import-template-bar__copy">
                    <strong>{{ $matrixCohortCount }} {{ \Illuminate\Support\Str::plural('student', $matrixCohortCount) }} · {{ $matrixSubjectCount }} {{ \Illuminate\Support\Str::plural('subject', $matrixSubjectCount) }}</strong>
                    <span>Pre-fills existing CREDIT, numeric grades, INC, DRP, or TC cells. Includes an Instructions &amp; Legend sheet.</span>
                </div>
                <div class="tala-import-template-bar__actions">
                    <x-filament::button
                        type="button"
                        size="sm"
                        color="primary"
                        icon="heroicon-o-arrow-down-tray"
                        wire:click="downloadGradeMatrix"
                    >
                        Download grade sheet (.xlsx)
                    </x-filament::button>
                </div>
            </div>
        </div>
    </section>

    {{-- Step B: Upload Completed Grade Sheet & Review Changes --}}
    <section class="tala-import-card" aria-labelledby="matrix-upload-heading">
        <header class="tala-import-card__header">
            <div>
                <h2 id="matrix-upload-heading" tabindex="-1">Upload filled grade sheet &amp; review changes</h2>
                <p class="tala-import-card__subtitle">
                    Upload your edited grade sheet (.xlsx or .csv). Only changed cells are updated; blank cells never delete existing records.
                </p>
            </div>
        </header>

        <div class="tala-import-card__body">
            <div class="tala-import-scope-bar tala-import-scope-bar--two">
                <div class="tala-import-field">
                    <label for="matrix-ay-label" class="tala-import-field__label">
                        Academic Year label for newly recorded grades (optional)
                    </label>
                    <x-filament::input.wrapper>
                        <x-filament::input
                            id="matrix-ay-label"
                            type="text"
                            wire:model.blur="matrixAcademicYearLabel"
                            placeholder="e.g. AY 2024-2025"
                            maxlength="64"
                        />
                    </x-filament::input.wrapper>
                </div>

                <div class="tala-import-field">
                    <label for="matrix-source-school" class="tala-import-field__label">
                        Default External Transfer school for TC cells (optional)
                    </label>
                    <x-filament::input.wrapper>
                        <x-filament::input
                            id="matrix-source-school"
                            type="text"
                            wire:model.blur="matrixSourceSchool"
                            placeholder="e.g. Previous College / University"
                            maxlength="255"
                        />
                    </x-filament::input.wrapper>
                </div>
            </div>

            <div
                class="tala-import-dropzone-wrap"
                x-data="{
                    dragging: false,
                    uploading: false,
                    progress: 0,
                    handleDrop(e) {
                        this.dragging = false;
                        const files = e.dataTransfer?.files;
                        if (!files || files.length === 0) return;
                        this.$refs.matrixInput.files = files;
                        this.$refs.matrixInput.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }"
                x-on:livewire-upload-start="uploading = true; progress = 5"
                x-on:livewire-upload-progress="progress = $event.detail.progress"
                x-on:livewire-upload-finish="uploading = false; progress = 100"
                x-on:livewire-upload-error="uploading = false; progress = 0"
                x-on:livewire-upload-cancel="uploading = false; progress = 0"
            >
                <input
                    x-ref="matrixInput"
                    id="tala-grade-matrix-input"
                    type="file"
                    accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
                    wire:model="matrixUploadedFile"
                    class="tala-visually-hidden"
                >

                @if (! $matrixHasFile)
                    <label
                        for="tala-grade-matrix-input"
                        x-on:dragover.prevent="dragging = true"
                        x-on:dragleave.prevent="dragging = false"
                        x-on:drop.prevent="handleDrop($event)"
                        x-bind:class="{ 'is-dragging': dragging }"
                        class="tala-import-dropzone"
                    >
                        <span class="tala-import-dropzone__icon" aria-hidden="true">
                            <x-filament::icon icon="heroicon-o-arrow-up-tray" />
                        </span>
                        <span class="tala-import-dropzone__title">
                            Drop your filled grade sheet (.xlsx or .csv) here, or <span>browse files</span>
                        </span>
                        <span class="tala-import-dropzone__hint">
                            Accepted cell values: 1.00–5.00, INC, DRP, CREDIT, or TC (external transfer, excluded from SIA GWA).
                        </span>
                    </label>
                @else
                    <div class="tala-import-file-card">
                        <div class="tala-import-file-card__main">
                            <span class="tala-import-file-card__icon" aria-hidden="true">
                                <x-filament::icon icon="heroicon-o-table-cells" />
                            </span>
                            <div class="tala-import-file-card__info">
                                <strong>{{ $matrixFilename }}</strong>
                                @if ($matrixSummary !== null)
                                    <span>
                                        {{ $matrixSummary['students_scanned'] }} {{ \Illuminate\Support\Str::plural('student', $matrixSummary['students_scanned']) }} scanned ·
                                        {{ $matrixSummary['matched_subject_columns'] }} subject {{ \Illuminate\Support\Str::plural('column', $matrixSummary['matched_subject_columns']) }} matched
                                    </span>
                                @endif
                            </div>
                        </div>
                        <div class="tala-import-file-card__actions">
                            <label for="tala-grade-matrix-input" class="tala-import-file-replace-btn">
                                Replace file
                            </label>
                            <x-filament::button
                                type="button"
                                size="sm"
                                color="gray"
                                outlined
                                wire:click="clearMatrixUploadedFile"
                            >
                                Remove
                            </x-filament::button>
                        </div>
                    </div>
                @endif

                <div x-show="uploading" x-cloak class="tala-import-upload-progress" role="status" aria-live="polite">
                    <progress class="tala-import-upload-progress__native" max="100" x-bind:value="progress"></progress>
                    <span x-text="'Reading grade matrix... ' + progress + '%'"></span>
                </div>
            </div>

            @if ($matrixUploadError)
                <x-tala-access-alert variant="danger" role="alert">
                    <p>{{ $matrixUploadError }}</p>
                </x-tala-access-alert>
            @endif

            @if ($matrixSummary !== null)
                {{-- Diff Metrics --}}
                <div class="tala-import-preview__metrics" role="region" aria-label="Grade matrix diff summary">
                    <div @class(['tala-import-preview__metric', 'is-ready' => $upgradesCount > 0])>
                        <span class="tala-import-preview__metric-label">Grades upgraded</span>
                        <strong class="tala-import-preview__metric-value">{{ $upgradesCount }}</strong>
                        <span class="tala-import-preview__metric-caption">Existing prior records updated</span>
                    </div>

                    <div @class(['tala-import-preview__metric', 'is-ready' => $newCreditsCount > 0])>
                        <span class="tala-import-preview__metric-label">New prior grades</span>
                        <strong class="tala-import-preview__metric-value">{{ $newCreditsCount }}</strong>
                        <span class="tala-import-preview__metric-caption">Newly recorded subject cells</span>
                    </div>

                    <div class="tala-import-preview__metric">
                        <span class="tala-import-preview__metric-label">Students affected</span>
                        <strong class="tala-import-preview__metric-value">{{ $studentsAffected }}</strong>
                        <span class="tala-import-preview__metric-caption">{{ $matrixSummary['unchanged_count'] }} cells unchanged</span>
                    </div>

                    <div @class(['tala-import-preview__metric', 'is-danger' => $errorCount > 0])>
                        <span class="tala-import-preview__metric-label">Cell errors</span>
                        <strong class="tala-import-preview__metric-value">{{ $errorCount }}</strong>
                        <span class="tala-import-preview__metric-caption">
                            {{ $errorCount > 0 ? 'Skipped unless fixed in file' : 'All uploaded cells valid' }}
                        </span>
                    </div>
                </div>

                @if (! empty($matrixSummary['unmatched_subject_columns']))
                    <x-tala-access-alert variant="warning" title="Unrecognized subject columns">
                        <p>Ignored columns not found in this curriculum: {{ implode(', ', $matrixSummary['unmatched_subject_columns']) }}.</p>
                    </x-tala-access-alert>
                @endif

                {{-- Cell Errors --}}
                @if ($matrixErrors !== [])
                    <div class="tala-import-preview__table-wrap is-full-page is-responsive-cards" tabindex="0" role="region" aria-label="Grade matrix cell errors">
                        <table class="tala-import-preview__table">
                            <thead>
                                <tr>
                                    <th scope="col" class="tala-import-preview__col-row">Row</th>
                                    <th scope="col">Student</th>
                                    <th scope="col">Subject</th>
                                    <th scope="col">Cell Value</th>
                                    <th scope="col">Issue</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($matrixErrors as $errItem)
                                    <tr class="is-blocked">
                                        <td class="tala-import-preview__cell-row" data-label="Row">#{{ $errItem['source_row'] }}</td>
                                        <th scope="row" data-label="Student">{{ $errItem['student_number'] ?: 'Missing' }}</th>
                                        <td data-label="Subject">{{ $errItem['subject_code'] ?: '—' }}</td>
                                        <td data-label="Cell Value"><code>{{ $errItem['raw_value'] }}</code></td>
                                        <td data-label="Issue" class="tala-import-preview__reasons--error">{{ $errItem['message'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                {{-- Dry-Run Diff Table --}}
                @if ($matrixChanges === [])
                    <div class="tala-import-preview__empty">
                        <p>No grade changes detected in this file compared to students' current prior academic records.</p>
                    </div>
                @else
                    <div class="tala-import-preview__table-wrap is-full-page is-responsive-cards" tabindex="0" role="region" aria-label="Grade matrix dry-run diff">
                        <table class="tala-import-preview__table">
                            <thead>
                                <tr>
                                    <th scope="col" class="tala-import-preview__col-row">Row</th>
                                    <th scope="col">Student</th>
                                    <th scope="col">Subject</th>
                                    <th scope="col">Grade Change</th>
                                    <th scope="col">Treatment</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($matrixChanges as $change)
                                    <tr>
                                        <td class="tala-import-preview__cell-row" data-label="Row">#{{ $change['source_row'] }}</td>
                                        <th scope="row" data-label="Student">
                                            <div class="tala-import-preview__subject-main">
                                                <span class="tala-import-preview__code">{{ $change['student_number'] }}</span>
                                                <span class="tala-import-preview__title">{{ $change['student_name'] }}</span>
                                            </div>
                                        </th>
                                        <td data-label="Subject">
                                            <strong>{{ $change['subject_code'] }}</strong>
                                            @if (filled($change['subject_title']))
                                                <div class="tala-import-preview__hours-meta">{{ $change['subject_title'] }}</div>
                                            @endif
                                        </td>
                                        <td data-label="Grade Change" class="tabular-nums">
                                            @if ($change['current_grade'] !== null)
                                                <span>{{ $change['current_grade'] }} &rarr; <strong>{{ $change['final_grade'] }}</strong></span>
                                            @else
                                                <strong>New: {{ $change['final_grade'] }}</strong>
                                            @endif
                                        </td>
                                        <td data-label="Treatment">
                                            <div class="tala-import-preview__subject-tags">
                                                @if (! empty($change['is_external_transfer']))
                                                    <x-filament::badge size="sm" color="warning">External transfer (TC)</x-filament::badge>
                                                @elseif ($change['result_status'] === 'failed')
                                                    <x-filament::badge size="sm" color="danger">Failed (5.00)</x-filament::badge>
                                                @elseif ($change['result_status'] === 'inc')
                                                    <x-filament::badge size="sm" color="warning">Incomplete (INC)</x-filament::badge>
                                                @elseif ($change['result_status'] === 'dropped')
                                                    <x-filament::badge size="sm" color="gray">Dropped (DRP)</x-filament::badge>
                                                @else
                                                    <x-filament::badge size="sm" color="success">
                                                        {{ $change['change_type'] === 'upgrade' ? 'Upgrade' : 'New grade' }}
                                                    </x-filament::badge>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif

            @if ($matrixFooterMessage)
                <x-tala-access-alert variant="warning" role="alert">
                    <p>{{ $matrixFooterMessage }}</p>
                </x-tala-access-alert>
            @endif
        </div>

        <footer class="tala-import-card__footer">
            <x-filament::button
                tag="a"
                :href="$returnUrl"
                color="gray"
                outlined
            >
                Cancel
            </x-filament::button>

            <div class="tala-import-card__footer-group">
                @if ($changesTotal === 0)
                    <span class="tala-import-field__help">Upload an edited matrix with grade updates to apply.</span>
                @endif

                <x-filament::button
                    type="button"
                    color="primary"
                    icon="heroicon-m-check"
                    wire:click="openConfirmMatrixModal"
                    wire:loading.attr="disabled"
                    wire:target="openConfirmMatrixModal,commitGradeMatrix"
                >
                    Review &amp; apply {{ $changesTotal }} {{ \Illuminate\Support\Str::plural('grade update', $changesTotal) }}
                </x-filament::button>
            </div>
        </footer>
    </section>

    {{-- Grade Matrix Confirmation Modal --}}
    <x-filament::modal
        id="confirm-matrix-commit-modal"
        width="lg"
        icon="heroicon-o-table-cells"
        icon-color="primary"
        footer-actions-alignment="end"
    >
        <x-slot name="heading">
            Confirm Cohort Grade Matrix update
        </x-slot>

        <x-slot name="description">
            Upserts every changed prior grade cell and refreshes affected students' completion readiness and official transcript snapshots.
        </x-slot>

        <div class="tala-import-confirm-list">
            <div class="tala-import-confirm-row">
                <span>Existing prior grades upgraded</span>
                <strong class="tabular-nums">{{ $upgradesCount }}</strong>
            </div>
            <div class="tala-import-confirm-row">
                <span>New prior grade cells recorded</span>
                <strong class="tabular-nums">{{ $newCreditsCount }}</strong>
            </div>
            <div class="tala-import-confirm-row">
                <span>Students affected</span>
                <strong class="tabular-nums">{{ $studentsAffected }}</strong>
            </div>
            @if ($errorCount > 0)
                <div class="tala-import-confirm-row is-warning">
                    <span>Invalid cells skipped</span>
                    <strong class="tabular-nums">{{ $errorCount }}</strong>
                </div>
            @endif
        </div>

        <x-slot name="footerActions">
            <x-filament::button
                type="button"
                color="gray"
                outlined
                x-on:click="$dispatch('close-modal', { id: 'confirm-matrix-commit-modal' })"
            >
                Cancel
            </x-filament::button>

            <x-filament::button
                type="button"
                color="primary"
                icon="heroicon-m-check"
                wire:click="commitGradeMatrix"
                wire:loading.attr="disabled"
                wire:target="commitGradeMatrix"
            >
                Apply grade updates
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</div>
