@assets
    <link rel="stylesheet" href="{{ asset('css/tala-catalog.css').'?v='.filemtime(public_path('css/tala-catalog.css')) }}">
@endassets

@php
    $readyCount = (int) ($summary['ready_new'] ?? 0);
    $updateCount = (int) ($summary['update_unactivated'] ?? 0);
    $alreadyOnboardedCount = (int) ($summary['already_onboarded'] ?? 0);
    $blockedCount = (int) ($summary['blocked_count'] ?? 0);
    $skippedCount = (int) ($summary['skipped_count'] ?? 0);
    $applicantOptedInCount = (int) ($summary['applicant_links_opted_in'] ?? 0);
    $estimatedPriorCredits = (int) ($summary['estimated_prior_credits'] ?? 0);
    $totalSkippedSummary = $skippedCount + $alreadyOnboardedCount + $blockedCount;
    $importableCount = $readyCount + $updateCount;

    $steps = [
        [
            'number' => 1,
            'title' => '1. Choose program & upload',
            'meta' => $hasFile
                ? ($filename ?? 'File ready').' · '.$parsedRowCount.' '.\Illuminate\Support\Str::plural('student', $parsedRowCount)
                : 'Pick program & .xlsx / .csv',
        ],
        [
            'number' => 2,
            'title' => '2. Match columns',
            'meta' => $autoSkippedMapping && $activeStep === 3
                ? 'Auto-matched from headers'
                : ($hasFile
                    ? count($headerOptions).' '.\Illuminate\Support\Str::plural('column', count($headerOptions)).' detected'
                    : 'Verify columns if needed'),
        ],
        [
            'number' => 3,
            'title' => '3. Review & import',
            'meta' => $hasFile && $parsedRowCount > 0
                ? ($blockedCount > 0
                    ? $importableCount.' ready · '.$blockedCount.' needs review'
                    : $importableCount.' ready to import')
                : 'Check rows & confirm',
        ],
    ];
@endphp

<x-filament-panels::page>
    <div
        class="tala-import-workspace"
        x-data
        x-on:import-step-changed.window="$nextTick(() => {
            const target = document.getElementById($event.detail?.targetId ?? '');
            if (target) { target.focus(); }
        })"
    >
        {{-- Mode Navigation: Import student list vs Upload prior grade sheet --}}
        <x-tala-navigation stretch label="Student import workflow">
            <x-filament::tabs.item
                :active="$workspaceMode === 'roster'"
                icon="heroicon-m-user-group"
                wire:click="setWorkspaceMode('roster')"
            >
                Import student list
            </x-filament::tabs.item>

            <x-filament::tabs.item
                :active="$workspaceMode === 'matrix'"
                icon="heroicon-m-table-cells"
                wire:click="setWorkspaceMode('matrix')"
            >
                Upload prior grade sheet
            </x-filament::tabs.item>
        </x-tala-navigation>

        @if ($workspaceMode === 'matrix')
            @include('filament.admin.student-profiles.partials.cohort-grade-matrix', [
                'programs' => $programs,
                'matrixCurricula' => $matrixCurricula,
                'matrixAcademicYears' => $matrixAcademicYears,
                'matrixTermOptions' => $matrixTermOptions,
                'matrixCohortCount' => $matrixCohortCount,
                'matrixSubjectCount' => $matrixSubjectCount,
                'matrixHasFile' => $matrixHasFile,
                'matrixFilename' => $matrixFilename,
                'matrixUploadError' => $matrixUploadError,
                'matrixFooterMessage' => $matrixFooterMessage,
                'matrixPreview' => $matrixPreview,
                'lastMatrixSummary' => $lastMatrixSummary,
                'returnUrl' => $returnUrl,
            ])
        @else
            {{-- Persistent Post-Import Completion Summary + 1-Click Undo Batch --}}
            @if ($lastImportSummary !== null)
                @php
                    $isReverted = ! empty($lastImportSummary['reverted']);
                    $batchId = $lastImportSummary['import_batch_id'] ?? null;
                    $canUndoLast = ! $isReverted && is_array($lastBatchUndoStatus) && ! empty($lastBatchUndoStatus['allowed']);
                    $undoBlockerReason = is_array($lastBatchUndoStatus) ? ($lastBatchUndoStatus['reason'] ?? null) : null;
                @endphp
                <section @class(['tala-import-card tala-import-completion', 'is-reverted' => $isReverted]) aria-labelledby="cohort-import-completion-heading">
                    <div class="tala-import-completion__head">
                        <span class="tala-import-completion__badge" aria-hidden="true">
                            <x-filament::icon :icon="$isReverted ? 'heroicon-o-arrow-uturn-left' : 'heroicon-o-check-circle'" />
                        </span>
                        <div class="tala-import-completion__title-wrap">
                            <div class="tala-import-completion__title-row">
                                <h2 id="cohort-import-completion-heading" tabindex="-1">
                                    @if ($isReverted)
                                        Import batch undone
                                    @else
                                        Student list imported
                                    @endif
                                </h2>
                                @if ($batchId !== null)
                                    <span class="tala-import-completion__pill">Batch #{{ $batchId }}</span>
                                @endif
                            </div>
                            <p class="tala-import-card__subtitle">
                                @if ($isReverted)
                                    {{ (int) ($lastImportSummary['reverted_profiles'] ?? 0) }} student {{ \Illuminate\Support\Str::plural('profile', (int) ($lastImportSummary['reverted_profiles'] ?? 0)) }} and associated draft records from this batch have been removed cleanly.
                                @else
                                    Review the summary below. You can undo this batch any time before a student signs in or enrolls.
                                @endif
                            </p>
                        </div>
                    </div>

                    @if (! $isReverted)
                        <dl class="tala-import-completion__stats" aria-label="Import summary">
                            <div class="tala-import-completion__stat">
                                <dt>Students added</dt>
                                <dd>
                                    <strong>{{ (int) ($lastImportSummary['created_students'] ?? 0) }}</strong>
                                    @if (($lastImportSummary['updated_students'] ?? 0) > 0)
                                        <span>+{{ $lastImportSummary['updated_students'] }} updated</span>
                                    @endif
                                </dd>
                            </div>
                            <div class="tala-import-completion__stat">
                                <dt>Prior subjects credited</dt>
                                <dd><strong>{{ (int) ($lastImportSummary['prior_credits_recorded'] ?? 0) }}</strong></dd>
                            </div>
                            <div class="tala-import-completion__stat">
                                <dt>Activation emails sent</dt>
                                <dd>
                                    <strong>{{ (int) ($lastImportSummary['activation_emails_sent'] ?? 0) }}</strong>
                                    @if (($lastImportSummary['skipped_rows_count'] ?? 0) > 0)
                                        <span>{{ $lastImportSummary['skipped_rows_count'] }} skipped</span>
                                    @endif
                                </dd>
                            </div>
                        </dl>
                    @endif

                    @if (! $isReverted && $batchId !== null && ! $canUndoLast && filled($undoBlockerReason))
                        <p class="tala-import-completion__note">{{ $undoBlockerReason }}</p>
                    @endif

                    <footer class="tala-import-completion__footer">
                        <div class="tala-import-completion__footer-left">
                            @if ($batchId !== null && $canUndoLast)
                                <x-filament::button
                                    type="button"
                                    size="sm"
                                    color="danger"
                                    outlined
                                    icon="heroicon-o-arrow-uturn-left"
                                    wire:click="undoImportBatch({{ (int) $batchId }})"
                                    wire:loading.attr="disabled"
                                    wire:target="undoImportBatch"
                                >
                                    Undo this import batch
                                </x-filament::button>
                            @endif

                            @if ($hasSkippedRowsCsv)
                                <x-filament::button
                                    type="button"
                                    size="sm"
                                    color="gray"
                                    outlined
                                    icon="heroicon-o-arrow-down-tray"
                                    wire:click="downloadSkippedRowsCsv"
                                >
                                    Download skipped rows (.csv)
                                </x-filament::button>
                            @endif
                        </div>

                        <div class="tala-import-completion__footer-right">
                            <x-filament::button
                                type="button"
                                size="sm"
                                color="gray"
                                outlined
                                wire:click="startAnotherImport"
                            >
                                Import another list
                            </x-filament::button>

                            <x-filament::button
                                tag="a"
                                size="sm"
                                color="primary"
                                :href="$returnUrl"
                            >
                                Open Student Records
                            </x-filament::button>
                        </div>
                    </footer>
                </section>
            @endif

            {{-- 3-Step Horizontal Stepper --}}
            <nav class="tala-import-stepper" aria-label="Cohort roster import steps">
                @foreach ($steps as $stepItem)
                    @php
                        $num = $stepItem['number'];
                        $isActive = $activeStep === $num;
                        $isCompleted = $num < $activeStep;
                        $isClickable = $num === 1 || ($hasFile && $parsedRowCount > 0 && $num <= $maxUnlockedStep);
                    @endphp
                    <button
                        type="button"
                        wire:click="goToStep({{ $num }})"
                        @if (! $isClickable) disabled @endif
                        @if ($isActive) aria-current="step" @endif
                        @class([
                            'tala-import-step',
                            'is-active' => $isActive,
                            'is-completed' => $isCompleted,
                        ])
                    >
                        <span class="tala-import-step__top">
                            <strong class="tala-import-step__title">{{ $stepItem['title'] }}</strong>
                            @if ($isCompleted)
                                <span class="tala-import-step__done" aria-hidden="true">
                                    <x-filament::icon icon="heroicon-m-check-circle" />
                                </span>
                            @endif
                        </span>
                        <span class="tala-import-step__meta">{{ $stepItem['meta'] }}</span>
                    </button>
                @endforeach
            </nav>

            {{-- STEP 1: PROGRAM & BATCH SETTINGS + UPLOAD FILE + RECENT BATCHES --}}
            @if ($activeStep === 1)
                <section class="tala-import-card" aria-labelledby="ongoing-import-step-1-heading">
                    <header class="tala-import-card__header">
                        <div>
                            <h2 id="ongoing-import-step-1-heading" tabindex="-1">Choose program &amp; upload student list</h2>
                            <p class="tala-import-card__subtitle">
                                Pick the Program, Curriculum, Batch Year, and completed terms once, then upload a simple 5-column student list (.xlsx or .csv).
                            </p>
                        </div>
                    </header>

                    <div class="tala-import-card__body">
                        {{-- Program & Batch Scope Bar --}}
                        <div class="tala-import-scope-bar" role="region" aria-label="Program and batch settings">
                            <div class="tala-import-field">
                                <label for="cohort-scope-program" class="tala-import-field__label">
                                    Program <span class="tala-import-required" aria-hidden="true">*</span>
                                </label>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select
                                        id="cohort-scope-program"
                                        wire:model.live="programId"
                                    >
                                        <option value="">Select program...</option>
                                        @foreach ($programs as $programOption)
                                            <option value="{{ $programOption->id }}">
                                                {{ $programOption->code }} — {{ $programOption->name }}@if (! $programOption->is_active) (Teach-out)@endif
                                            </option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                                <p class="tala-import-field__help">Includes active and inactive teach-out programs with published curricula.</p>
                            </div>

                            <div class="tala-import-field">
                                <label for="cohort-scope-curriculum" class="tala-import-field__label">
                                    Curriculum Version / Batch <span class="tala-import-required" aria-hidden="true">*</span>
                                </label>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select
                                        id="cohort-scope-curriculum"
                                        wire:model.live="curriculumVersionId"
                                    >
                                        <option value="">Select curriculum version...</option>
                                        @foreach ($rosterCurricula as $cvOption)
                                            <option value="{{ $cvOption->id }}">
                                                {{ $cvOption->version_code }} — {{ $cvOption->batchLabel() }} ({{ \App\Models\CurriculumVersion::stateOptions()[$cvOption->state] ?? $cvOption->state }})
                                            </option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                                <p class="tala-import-field__help">Supports both Active and Retired grandfathered curricula.</p>
                            </div>

                            <div class="tala-import-field">
                                <label for="cohort-scope-entry-ay" class="tala-import-field__label">
                                    Batch / Entry School Year
                                </label>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select
                                        id="cohort-scope-entry-ay"
                                        wire:model.live="entryAcademicYearId"
                                    >
                                        <option value="">Not specified</option>
                                        @foreach ($rosterAcademicYears as $ayOption)
                                            <option value="{{ $ayOption['id'] }}">{{ $ayOption['label'] }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                                <p class="tala-import-field__help">Auto-selected when linked to this curriculum batch. No historical term record needed.</p>
                            </div>

                            <div class="tala-import-field">
                                <label for="cohort-scope-completed-through" class="tala-import-field__label">
                                    Completed terms so far (for regular students)
                                </label>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select
                                        id="cohort-scope-completed-through"
                                        wire:model.live="completedThroughKey"
                                    >
                                        <option value="none">None — review subjects individually</option>
                                        @foreach ($rosterTermOptions as $termOpt)
                                            <option value="{{ $termOpt['key'] }}">
                                                {{ $termOpt['label'] }} ({{ $termOpt['cumulative_subject_count'] }} subjects · {{ $termOpt['cumulative_units'] }} units)
                                            </option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                                <p class="tala-import-field__help">
                                    Automatically marks prior curriculum subjects through this term as passed (CREDIT) for regular students so next-term enrollment opens immediately. Irregular students are reviewed individually.
                                </p>
                            </div>
                        </div>

                        {{-- Accessible File Dropzone + Native Progress --}}
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
                                    this.$refs.fileInput.files = files;
                                    this.$refs.fileInput.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                            }"
                            x-on:livewire-upload-start="uploading = true; progress = 5"
                            x-on:livewire-upload-progress="progress = $event.detail.progress"
                            x-on:livewire-upload-finish="uploading = false; progress = 100"
                            x-on:livewire-upload-error="uploading = false; progress = 0"
                            x-on:livewire-upload-cancel="uploading = false; progress = 0"
                        >
                            <input
                                x-ref="fileInput"
                                id="tala-ongoing-spreadsheet-input"
                                type="file"
                                accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
                                wire:model="uploadedFile"
                                class="tala-visually-hidden"
                            >

                            @if (! $hasFile)
                                <label
                                    for="tala-ongoing-spreadsheet-input"
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
                                        Drop your student list (.xlsx or .csv) here, or <span>browse files</span>
                                    </span>
                                    <span class="tala-import-dropzone__hint">
                                        Core columns: Student Number, First Name, Middle Name, Last Name, Email. Unmodified TALA templates jump straight to Step 3 review.
                                    </span>
                                </label>
                            @else
                                <div class="tala-import-file-card">
                                    <div class="tala-import-file-card__main">
                                        <span class="tala-import-file-card__icon" aria-hidden="true">
                                            <x-filament::icon icon="heroicon-o-table-cells" />
                                        </span>
                                        <div class="tala-import-file-card__info">
                                            <strong>{{ $filename }}</strong>
                                            <span>
                                                Students worksheet ({{ $sheetName ?? 'Sheet 1' }}): {{ $parsedRowCount }} {{ \Illuminate\Support\Str::plural('student', $parsedRowCount) }} detected
                                            </span>
                                        </div>
                                    </div>
                                    <div class="tala-import-file-card__actions">
                                        <label for="tala-ongoing-spreadsheet-input" class="tala-import-file-replace-btn">
                                            Replace file
                                        </label>
                                        <x-filament::button
                                            type="button"
                                            size="sm"
                                            color="gray"
                                            outlined
                                            wire:click="clearUploadedFile"
                                        >
                                            Remove
                                        </x-filament::button>
                                    </div>
                                </div>
                            @endif

                            <div x-show="uploading" x-cloak class="tala-import-upload-progress" role="status" aria-live="polite">
                                <progress class="tala-import-upload-progress__native" max="100" x-bind:value="progress"></progress>
                                <span x-text="'Reading spreadsheet... ' + progress + '%'"></span>
                            </div>
                        </div>

                        @if ($hasFile && count($sheets) > 1)
                            <div class="tala-import-worksheet-box">
                                <div class="tala-import-field">
                                    <label for="tala-ongoing-sheet-select" class="tala-import-field__label">
                                        Students worksheet tab
                                    </label>
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select
                                            id="tala-ongoing-sheet-select"
                                            wire:model.live="sheetIndex"
                                        >
                                            @foreach ($sheets as $sheet)
                                                <option value="{{ $sheet['index'] }}">
                                                    {{ $sheet['name'] }} ({{ $sheet['row_count'] }} {{ \Illuminate\Support\Str::plural('row', $sheet['row_count']) }})
                                                </option>
                                            @endforeach
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                    <p class="tala-import-field__help">
                                        Select which tab contains your student roster. Reference Codes tabs are skipped automatically.
                                    </p>
                                </div>
                            </div>
                        @endif

                        @if ($uploadError)
                            <x-tala-access-alert variant="danger" role="alert">
                                <p>{{ $uploadError }}</p>
                            </x-tala-access-alert>
                        @endif

                        <div class="tala-import-template-bar">
                            <div class="tala-import-template-bar__copy">
                                <strong>Need the student list template?</strong>
                                <span>Includes the 5 core columns plus optional Standing, Lifecycle, Financial Hold Note, LRN, Birth Date, and Phone (with live Reference Codes on Sheet 2).</span>
                            </div>
                            <div class="tala-import-template-bar__actions">
                                <x-filament::button
                                    type="button"
                                    size="sm"
                                    color="gray"
                                    outlined
                                    icon="heroicon-o-arrow-down-tray"
                                    wire:click="downloadTemplate('xlsx')"
                                >
                                    Download Excel (.xlsx)
                                </x-filament::button>
                                <x-filament::button
                                    type="button"
                                    size="sm"
                                    color="gray"
                                    outlined
                                    icon="heroicon-o-document-text"
                                    wire:click="downloadTemplate('csv')"
                                >
                                    Download CSV (.csv)
                                </x-filament::button>
                            </div>
                        </div>
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
                            @if (! $hasFile)
                                <span class="tala-import-field__help">Upload a roster file to proceed.</span>
                            @endif

                            <x-filament::button
                                type="button"
                                color="primary"
                                icon="heroicon-m-arrow-right"
                                icon-position="after"
                                wire:click="continueFromStep1"
                            >
                                Continue to review
                            </x-filament::button>
                        </div>
                    </footer>
                </section>

                {{-- Recent Import Batches Panel (with 1-click Undo for untouched batches) --}}
                @if ($recentBatches !== [])
                    <section class="tala-import-card" aria-labelledby="recent-import-batches-heading">
                        <header class="tala-import-card__header">
                            <div>
                                <h2 id="recent-import-batches-heading">Recent cohort import batches</h2>
                                <p class="tala-import-card__subtitle">
                                    Untouched batches where no student has signed in or enrolled can be reversed in one click.
                                </p>
                            </div>
                        </header>

                        <div class="tala-import-card__body">
                            <div class="tala-import-batches-list">
                                @foreach ($recentBatches as $batchItem)
                                    <div class="tala-import-batch-row" wire:key="recent-batch-{{ $batchItem['id'] }}">
                                        <div class="tala-import-batch-row__info">
                                            <strong>
                                                Batch #{{ $batchItem['id'] }} · {{ $batchItem['program_code'] }}
                                                @if ($batchItem['curriculum_code'] !== '')
                                                    ({{ $batchItem['curriculum_code'] }})
                                                @endif
                                            </strong>
                                            <span>
                                                {{ $batchItem['student_count'] }} {{ \Illuminate\Support\Str::plural('student', $batchItem['student_count']) }}
                                                @if (filled($batchItem['entry_ay_label']))
                                                    · {{ $batchItem['entry_ay_label'] }}
                                                @endif
                                                @if (filled($batchItem['filename']))
                                                    · {{ $batchItem['filename'] }}
                                                @endif
                                                @if ($batchItem['created_at_label'] !== '')
                                                    · {{ $batchItem['created_at_label'] }}
                                                @endif
                                            </span>
                                            @if (! $batchItem['is_reverted'] && ! $batchItem['can_undo'] && filled($batchItem['undo_reason']))
                                                <span class="tala-import-batch-row__note">{{ $batchItem['undo_reason'] }}</span>
                                            @endif
                                        </div>

                                        <div class="tala-import-batch-row__actions">
                                            @if ($batchItem['is_reverted'])
                                                <x-filament::badge size="sm" color="gray">Undone</x-filament::badge>
                                            @elseif ($batchItem['can_undo'])
                                                <x-filament::badge size="sm" color="success">Active</x-filament::badge>
                                                <x-filament::button
                                                    type="button"
                                                    size="sm"
                                                    color="danger"
                                                    outlined
                                                    icon="heroicon-o-arrow-uturn-left"
                                                    wire:click="undoImportBatch({{ $batchItem['id'] }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="undoImportBatch"
                                                >
                                                    Undo batch
                                                </x-filament::button>
                                            @else
                                                <x-filament::badge size="sm" color="gray">Locked (in use)</x-filament::badge>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </section>
                @endif
            @endif

            {{-- STEP 2: MATCH COLUMNS --}}
            @if ($activeStep === 2)
                @php
                    $mappingHints = [
                        \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldStudentNumber => 'Required. Unique institutional Student Number (e.g. SIA-2023-0045).',
                        \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldFirstName => 'Required. Student first / given name.',
                        \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldMiddleName => 'Optional middle name or initial.',
                        \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldLastName => 'Required. Student surname / family name.',
                        \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldEmail => 'Required. Student email address for Student Hub account activation.',
                        \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldAcademicStanding => 'Regular, Irregular, Probationary, Deficient, Completion Candidate, or Graduation Candidate.',
                        \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldLifecycleStatus => 'ACTIVE, LEAVE_OF_ABSENCE, INACTIVE, WITHDRAWN, TRANSFERRED_OUT, or COMPLETED.',
                        \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldFinancialHoldNote => 'If provided, creates prior-balance enrollment and official record release holds.',
                        \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldPriorIdentifier => 'Optional Learner Reference Number (LRN) or prior school ID.',
                        \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldBirthDate => 'Optional birth date (YYYY-MM-DD).',
                        \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldPhone => 'Optional contact phone number.',
                        \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldAddress => 'Optional home address.',
                        \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldProgramCode => 'Optional per-row override. Leave ignored to use the Step 1 cohort Program.',
                        \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldCurriculumVersionCode => 'Optional per-row override. Leave ignored to use the Step 1 cohort Curriculum.',
                        \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldEntryTermCode => 'Optional per-row entry term override.',
                    ];

                    $requiredFieldItems = [];
                    $optionalFieldItems = [];

                    foreach ($fieldLabels as $fieldKey => $rawFieldLabel) {
                        $isRequired = in_array($fieldKey, $requiredFields, true);
                        $cleanLabel = rtrim((string) $rawFieldLabel, '* ');
                        $selectedVal = (string) ($columnMap[$fieldKey] ?? 'ignore');
                        $isMapped = $selectedVal !== '' && $selectedVal !== 'ignore';
                        $item = [
                            'key' => $fieldKey,
                            'label' => $cleanLabel,
                            'isRequired' => $isRequired,
                            'isMapped' => $isMapped,
                            'error' => $mappingErrors[$fieldKey] ?? null,
                            'help' => $mappingHints[$fieldKey] ?? null,
                        ];

                        if ($isRequired) {
                            $requiredFieldItems[] = $item;
                        } else {
                            $optionalFieldItems[] = $item;
                        }
                    }

                    $requiredMappedCount = count(array_filter($requiredFieldItems, static fn (array $item): bool => $item['isMapped']));
                    $optionalMappedCount = count(array_filter($optionalFieldItems, static fn (array $item): bool => $item['isMapped']));
                @endphp

                <section class="tala-import-card" aria-labelledby="ongoing-import-step-2-heading">
                    <header class="tala-import-card__header">
                        <div>
                            <h2 id="ongoing-import-step-2-heading" tabindex="-1">Match spreadsheet columns</h2>
                            <p class="tala-import-card__subtitle">
                                Confirm how columns from <strong>{{ $sheetName ?? $filename }}</strong> map to TALA cohort roster fields.
                            </p>
                        </div>
                    </header>

                    <div class="tala-import-card__body">
                        @if ($mappingErrors !== [])
                            <x-tala-access-alert variant="danger" title="Select required columns" role="alert">
                                <p>{{ implode(' ', $mappingErrors) }}</p>
                            </x-tala-access-alert>
                        @endif

                        <div class="tala-import-mapping-groups">
                            <div class="tala-import-mapping-group">
                                <div class="tala-import-mapping-group__head">
                                    <h3>Required student columns</h3>
                                    <span class="tala-import-mapping-group__count tabular-nums">
                                        {{ $requiredMappedCount }} of {{ count($requiredFieldItems) }} mapped
                                    </span>
                                </div>

                                <div class="tala-import-mapping-grid">
                                    @foreach ($requiredFieldItems as $item)
                                        @php
                                            $errId = 'col-map-err-'.$item['key'];
                                            $helpId = 'col-map-help-'.$item['key'];
                                        @endphp
                                        <div @class([
                                            'tala-import-mapping-card',
                                            'is-mapped' => $item['isMapped'] && $item['error'] === null,
                                            'has-error' => $item['error'] !== null,
                                        ])>
                                            <div class="tala-import-mapping-card__head">
                                                <label for="col-map-{{ $item['key'] }}" class="tala-import-field__label">
                                                    {{ $item['label'] }}
                                                    <span class="tala-import-required" aria-hidden="true">*</span>
                                                </label>
                                                @if ($item['error'] !== null)
                                                    <span class="tala-import-pill-missing">Required</span>
                                                @elseif ($item['isMapped'])
                                                    <span class="tala-import-pill-mapped">
                                                        <x-filament::icon icon="heroicon-m-check" class="tala-import-pill-mapped__icon" aria-hidden="true" />
                                                        Mapped
                                                    </span>
                                                @else
                                                    <span class="tala-import-pill-missing">Not mapped</span>
                                                @endif
                                            </div>

                                            <x-filament::input.wrapper :valid="$item['error'] === null" class="tala-import-mapping-select">
                                                <x-filament::input.select
                                                    id="col-map-{{ $item['key'] }}"
                                                    wire:model.live="columnMap.{{ $item['key'] }}"
                                                    aria-invalid="{{ $item['error'] !== null ? 'true' : 'false' }}"
                                                    aria-describedby="{{ $item['error'] !== null ? $errId : ($item['help'] !== null ? $helpId : '') }}"
                                                >
                                                    <option value="ignore">Ignore / Not in file</option>
                                                    @foreach ($headerOptions as $colIdx => $colLabel)
                                                        <option value="{{ (string) $colIdx }}">{{ $colLabel }}</option>
                                                    @endforeach
                                                </x-filament::input.select>
                                            </x-filament::input.wrapper>

                                            @if ($item['error'] !== null)
                                                <p id="{{ $errId }}" class="tala-import-field__error">{{ $item['error'] }}</p>
                                            @elseif ($item['help'] !== null)
                                                <p id="{{ $helpId }}" class="tala-import-field__help">{{ $item['help'] }}</p>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="tala-import-mapping-group">
                                <div class="tala-import-mapping-group__head">
                                    <h3>Optional student details &amp; overrides</h3>
                                    <span class="tala-import-mapping-group__count tabular-nums">
                                        {{ $optionalMappedCount }} of {{ count($optionalFieldItems) }} mapped
                                    </span>
                                </div>

                                <div class="tala-import-mapping-grid">
                                    @foreach ($optionalFieldItems as $item)
                                        @php
                                            $helpId = 'col-map-help-'.$item['key'];
                                        @endphp
                                        <div @class([
                                            'tala-import-mapping-card',
                                            'is-mapped' => $item['isMapped'] && $item['error'] === null,
                                            'has-error' => $item['error'] !== null,
                                        ])>
                                            <div class="tala-import-mapping-card__head">
                                                <label for="col-map-{{ $item['key'] }}" class="tala-import-field__label">
                                                    {{ $item['label'] }}
                                                </label>
                                                @if ($item['isMapped'])
                                                    <span class="tala-import-pill-mapped">
                                                        <x-filament::icon icon="heroicon-m-check" class="tala-import-pill-mapped__icon" aria-hidden="true" />
                                                        Mapped
                                                    </span>
                                                @else
                                                    <span class="tala-import-pill-optional">Optional</span>
                                                @endif
                                            </div>

                                            <x-filament::input.wrapper :valid="$item['error'] === null" class="tala-import-mapping-select">
                                                <x-filament::input.select
                                                    id="col-map-{{ $item['key'] }}"
                                                    wire:model.live="columnMap.{{ $item['key'] }}"
                                                    aria-describedby="{{ $item['help'] !== null ? $helpId : '' }}"
                                                >
                                                    <option value="ignore">Ignore / Not in file</option>
                                                    @foreach ($headerOptions as $colIdx => $colLabel)
                                                        <option value="{{ (string) $colIdx }}">{{ $colLabel }}</option>
                                                    @endforeach
                                                </x-filament::input.select>
                                            </x-filament::input.wrapper>

                                            @if ($item['help'] !== null)
                                                <p id="{{ $helpId }}" class="tala-import-field__help">{{ $item['help'] }}</p>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                    <footer class="tala-import-card__footer">
                        <div class="tala-import-card__footer-group">
                            <x-filament::button
                                type="button"
                                color="gray"
                                icon="heroicon-m-arrow-left"
                                wire:click="goToStep(1)"
                            >
                                Back to scope &amp; upload
                            </x-filament::button>
                            <x-filament::button
                                tag="a"
                                :href="$returnUrl"
                                color="gray"
                                outlined
                            >
                                Cancel
                            </x-filament::button>
                        </div>

                        <x-filament::button
                            type="button"
                            color="primary"
                            icon="heroicon-m-arrow-right"
                            icon-position="after"
                            wire:click="goToStep(3)"
                        >
                            Review &amp; preview import
                        </x-filament::button>
                    </footer>
                </section>
            @endif

            {{-- STEP 3: REVIEW & CONFIRM IMPORT --}}
            @if ($activeStep === 3)
                <section class="tala-import-card" aria-labelledby="ongoing-import-step-3-heading">
                    <header class="tala-import-card__header">
                        <div>
                            <h2 id="ongoing-import-step-3-heading" tabindex="-1">Review &amp; confirm cohort import</h2>
                            <p class="tala-import-card__subtitle">
                                Reviewing <strong>{{ $sheetName ?? $filename }}</strong>
                                @if (filled($selectedScopeSummary['program_label'] ?? null))
                                    for <strong>{{ $selectedScopeSummary['program_label'] }}</strong>
                                @endif
                                @if (filled($selectedScopeSummary['curriculum_label'] ?? null))
                                    · {{ $selectedScopeSummary['curriculum_label'] }}
                                @endif
                            </p>
                        </div>

                        <div class="tala-import-template-bar__actions">
                            <x-filament::button
                                type="button"
                                size="sm"
                                color="gray"
                                outlined
                                icon="heroicon-o-adjustments-horizontal"
                                wire:click="goToStep(2)"
                            >
                                Adjust column mapping
                            </x-filament::button>
                            <x-filament::button
                                type="button"
                                size="sm"
                                color="gray"
                                outlined
                                wire:click="goToStep(1)"
                            >
                                Change cohort scope
                            </x-filament::button>
                        </div>
                    </header>

                    <div class="tala-import-card__body">
                        <div class="tala-import-scope-pill-strip" role="region" aria-label="Selected cohort scope">
                            @if (filled($selectedScopeSummary['entry_ay_label'] ?? null))
                                <span class="tala-import-preview__term-chip">Entry AY: {{ $selectedScopeSummary['entry_ay_label'] }}</span>
                            @endif
                            <span class="tala-import-preview__term-chip">Quick-credit: {{ $selectedScopeSummary['completed_through_label'] }}</span>
                            @if ($autoSkippedMapping)
                                <span class="tala-import-pill-mapped">Headers auto-matched</span>
                            @endif
                        </div>

                        @include('filament.admin.student-profiles.partials.import-preview', [
                            'summary' => $summary,
                            'rows' => $rows,
                            'reviewTab' => $reviewTab,
                            'trackedFixRowKeys' => $trackedFixRowKeys,
                            'standingOptions' => $standingOptions,
                            'lifecycleOptions' => $lifecycleOptions,
                        ])

                        <label class="tala-import-mode-option">
                            <input
                                type="checkbox"
                                wire:model.live="sendActivationEmails"
                                class="tala-import-checkbox"
                            >
                            <span class="tala-import-mode-option__copy">
                                <strong>Send 7-day student activation emails on import</strong>
                                <span>Off by default so you can review imported records first. When checked, sends each eligible student a 7-day Servitech Student Hub account activation link immediately after import.</span>
                            </span>
                        </label>

                        @if ($footerValidationMessage)
                            <x-tala-access-alert variant="warning" role="alert">
                                <p>{{ $footerValidationMessage }}</p>
                            </x-tala-access-alert>
                        @endif
                    </div>
                </section>

                <footer class="tala-import-sticky-footer" aria-label="Import actions">
                    <div class="tala-import-sticky-footer__summary">
                        <span class="tala-import-sticky-footer__counts">
                            <strong>{{ $readyCount }} new {{ \Illuminate\Support\Str::plural('student', $readyCount) }}</strong>
                            @if ($updateCount > 0)
                                <span>· {{ $updateCount }} unactivated {{ \Illuminate\Support\Str::plural('update', $updateCount) }}</span>
                            @endif
                            @if ($estimatedPriorCredits > 0)
                                <span>· {{ $estimatedPriorCredits }} prior {{ \Illuminate\Support\Str::plural('subject', $estimatedPriorCredits) }} auto-credited</span>
                            @endif
                            @if ($totalSkippedSummary > 0)
                                <span>· {{ $totalSkippedSummary }} not imported</span>
                            @endif
                        </span>
                    </div>

                    <div class="tala-import-sticky-footer__actions">
                        <x-filament::button
                            type="button"
                            color="gray"
                            icon="heroicon-m-arrow-left"
                            wire:click="goToStep(2)"
                        >
                            Adjust column mapping
                        </x-filament::button>

                        <x-filament::button
                            tag="a"
                            :href="$returnUrl"
                            color="gray"
                            outlined
                        >
                            Cancel
                        </x-filament::button>

                        @php
                            $importButtonLabel = $blockedCount > 0 && $importableCount > 0
                                ? "Skip {$blockedCount} unresolved & review confirmation ({$importableCount})"
                                : "Review & confirm import ({$importableCount})";
                        @endphp

                        <x-filament::button
                            type="button"
                            color="primary"
                            icon="heroicon-m-arrow-up-tray"
                            wire:click="openConfirmImportModal"
                            wire:loading.attr="disabled"
                            wire:target="openConfirmImportModal,commitImport"
                        >
                            {{ $importButtonLabel }}
                        </x-filament::button>
                    </div>
                </footer>
            @endif

            {{-- Confirmation Gate Modal --}}
            <x-filament::modal
                id="confirm-cohort-import-modal"
                width="lg"
                icon="heroicon-o-check-badge"
                icon-color="primary"
                footer-actions-alignment="end"
            >
                <x-slot name="heading">
                    Confirm cohort student import
                </x-slot>

                <x-slot name="description">
                    Review the exact changes that will be applied to Servitech Student Records before continuing.
                </x-slot>

                <div class="tala-import-confirm-list">
                    <div class="tala-import-confirm-row">
                        <span>New student profiles to create</span>
                        <strong class="tabular-nums">{{ $readyCount }} {{ \Illuminate\Support\Str::plural('student', $readyCount) }}</strong>
                    </div>
                    @if ($applicantOptedInCount > 0)
                        <div class="tala-import-confirm-row">
                            <span>Existing applicant accounts to link &amp; archive draft intake</span>
                            <strong class="tabular-nums">{{ $applicantOptedInCount }}</strong>
                        </div>
                    @endif
                    <div class="tala-import-confirm-row">
                        <span>Unactivated student profiles to update</span>
                        <strong class="tabular-nums">{{ $updateCount }} {{ \Illuminate\Support\Str::plural('profile', $updateCount) }}</strong>
                    </div>
                    <div class="tala-import-confirm-row">
                        <span>Prior curriculum subjects to auto-credit (CREDIT)</span>
                        <strong class="tabular-nums">{{ $estimatedPriorCredits }} {{ \Illuminate\Support\Str::plural('subject', $estimatedPriorCredits) }}</strong>
                    </div>
                    <div class="tala-import-confirm-row">
                        <span>Activation emails to send (7-day link)</span>
                        <strong class="tabular-nums">
                            @if ($sendActivationEmails)
                                {{ $importableCount }} {{ \Illuminate\Support\Str::plural('email', $importableCount) }} — 7-day link
                            @else
                                0 (default off — send later from Student Records)
                            @endif
                        </strong>
                    </div>
                    @if ($blockedCount > 0)
                        <div class="tala-import-confirm-row is-warning">
                            <span>Unresolved rows skipped into downloadable CSV</span>
                            <strong class="tabular-nums">{{ $blockedCount }}</strong>
                        </div>
                    @endif
                </div>

                <x-slot name="footerActions">
                    <x-filament::button
                        type="button"
                        color="gray"
                        outlined
                        x-on:click="$dispatch('close-modal', { id: 'confirm-cohort-import-modal' })"
                    >
                        Cancel
                    </x-filament::button>

                    <x-filament::button
                        type="button"
                        color="primary"
                        icon="heroicon-m-check"
                        wire:click="commitImport"
                        wire:loading.attr="disabled"
                        wire:target="commitImport"
                    >
                        Confirm &amp; import
                    </x-filament::button>
                </x-slot>
            </x-filament::modal>
        @endif
    </div>
</x-filament-panels::page>
