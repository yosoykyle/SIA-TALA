@assets
    <link rel="stylesheet" href="{{ asset('css/tala-catalog.css').'?v='.filemtime(public_path('css/tala-catalog.css')) }}">
@endassets

@php
    $readyCount = (int) ($summary['ready_new'] ?? 0);
    $alreadyOnboardedCount = (int) ($summary['already_onboarded'] ?? 0);
    $blockedCount = (int) ($summary['blocked_count'] ?? 0);
    $skippedCount = (int) ($summary['skipped_count'] ?? 0);
    $priorReadyCount = (int) ($summary['prior_subjects_ready'] ?? 0);
    $priorBlockedCount = (int) ($summary['prior_subjects_blocked'] ?? 0);
    $totalSkippedSummary = $skippedCount + $alreadyOnboardedCount + $blockedCount;
    $importableCount = $readyCount + $priorReadyCount;

    $canProceedFromStep1 = $hasFile
        && ($parsedRowCount > 0 || $priorSubjectsCount > 0)
        && $uploadError === null;

    $steps = [
        [
            'number' => 1,
            'eyebrow' => 'Step 1',
            'title' => 'Upload file',
            'meta' => $hasFile
                ? ($filename ?? 'File ready').' · '.$parsedRowCount.' '.\Illuminate\Support\Str::plural('student', $parsedRowCount)
                : 'Choose .xlsx or .csv',
        ],
        [
            'number' => 2,
            'eyebrow' => 'Step 2',
            'title' => 'Match columns',
            'meta' => $hasFile
                ? count($headerOptions).' '.\Illuminate\Support\Str::plural('column', count($headerOptions)).' detected'
                : 'Verify spreadsheet columns',
        ],
        [
            'number' => 3,
            'eyebrow' => 'Step 3',
            'title' => 'Review & import',
            'meta' => $hasFile && ($parsedRowCount > 0 || $priorSubjectsCount > 0)
                ? ($blockedCount > 0
                    ? $readyCount.' ready · '.$blockedCount.' fix required'
                    : $readyCount.' ready to onboard')
                : 'Check rows & confirm',
        ],
    ];
@endphp

<x-filament-panels::page>
    <div class="tala-import-workspace">
        {{-- Post-Import Skipped Rows Banner --}}
        @if ($lastImportSummary !== null)
            <x-tala-access-alert :variant="$hasSkippedRowsCsv ? 'warning' : 'success'" title="Import completed">
                <div class="tala-import-template-bar" style="margin-block-start: .375rem;">
                    <div class="tala-import-template-bar__copy">
                        <strong>
                            {{ $lastImportSummary['created_students'] }} {{ \Illuminate\Support\Str::plural('student', $lastImportSummary['created_students']) }} onboarded
                        </strong>
                        <span>
                            {{ $lastImportSummary['prior_credits_recorded'] }} prior {{ \Illuminate\Support\Str::plural('credit', $lastImportSummary['prior_credits_recorded']) }} recorded
                            · {{ $lastImportSummary['activation_emails_sent'] }} activation {{ \Illuminate\Support\Str::plural('email', $lastImportSummary['activation_emails_sent']) }} sent
                            @if ($lastImportSummary['skipped_rows_count'] > 0)
                                · {{ $lastImportSummary['skipped_rows_count'] }} {{ \Illuminate\Support\Str::plural('row', $lastImportSummary['skipped_rows_count']) }} skipped
                            @endif
                        </span>
                    </div>
                    <div class="tala-import-template-bar__actions">
                        @if ($hasSkippedRowsCsv)
                            <x-filament::button
                                type="button"
                                size="sm"
                                color="gray"
                                icon="heroicon-o-arrow-down-tray"
                                wire:click="downloadSkippedRowsCsv"
                            >
                                Download skipped rows (.csv)
                            </x-filament::button>
                        @endif
                        <x-filament::button
                            tag="a"
                            size="sm"
                            color="primary"
                            :href="$returnUrl"
                        >
                            Return to Student Records
                        </x-filament::button>
                    </div>
                </div>
            </x-tala-access-alert>
        @endif

        {{-- 3-Step Horizontal Stepper --}}
        <nav class="tala-import-stepper" aria-label="Ongoing student import steps">
            @foreach ($steps as $stepItem)
                @php
                    $num = $stepItem['number'];
                    $isActive = $activeStep === $num;
                    $isCompleted = $num < $activeStep;
                    $isClickable = $num === 1 || ($hasFile && ($parsedRowCount > 0 || $priorSubjectsCount > 0) && $num <= $maxUnlockedStep);
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
                        <span class="tala-import-step__eyebrow">{{ $stepItem['eyebrow'] }}</span>
                        @if ($isCompleted)
                            <span class="tala-import-step__done" aria-hidden="true">
                                <x-filament::icon icon="heroicon-m-check-circle" />
                            </span>
                        @endif
                    </span>
                    <strong class="tala-import-step__title">{{ $stepItem['title'] }}</strong>
                    <span class="tala-import-step__meta">{{ $stepItem['meta'] }}</span>
                </button>
            @endforeach
        </nav>

        {{-- STEP 1: UPLOAD FILE & TEMPLATES --}}
        @if ($activeStep === 1)
            <section class="tala-import-card" aria-labelledby="ongoing-import-step-1-heading">
                <header class="tala-import-card__header">
                    <div>
                        <h2 id="ongoing-import-step-1-heading">Upload student roster spreadsheet</h2>
                        <p class="tala-import-card__subtitle">
                            Upload the 3-sheet Servitech onboarding workbook (.xlsx) with Students and optional Prior Subjects, or a flat CSV (.csv) student roster.
                        </p>
                    </div>
                </header>

                <div class="tala-import-card__body">
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
                                    Drop your Excel (.xlsx) or CSV (.csv) file here, or <span>browse files</span>
                                </span>
                                <span class="tala-import-dropzone__hint">
                                    Supports 3-sheet workbooks (Students + Prior Subjects + Reference Codes) or flat CSV student rosters up to 10 MB.
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
                                            Students sheet ({{ $sheetName ?? 'Sheet 1' }}): {{ $parsedRowCount }} {{ \Illuminate\Support\Str::plural('row', $parsedRowCount) }}
                                            @if ($priorSubjectsSheetName !== null)
                                                · Prior Subjects sheet ({{ $priorSubjectsSheetName }}): {{ $priorSubjectsCount }} {{ \Illuminate\Support\Str::plural('row', $priorSubjectsCount) }}
                                            @endif
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
                            <div class="tala-import-upload-progress__bar">
                                <div class="tala-import-upload-progress__fill" x-bind:style="'width: ' + progress + '%'"></div>
                            </div>
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
                                    @if ($priorSubjectsSheetName !== null)
                                        Detected Prior Subjects worksheet "{{ $priorSubjectsSheetName }}" ({{ $priorSubjectsCount }} {{ \Illuminate\Support\Str::plural('row', $priorSubjectsCount) }}) will also be validated automatically.
                                    @else
                                        Select which tab contains your student roster. Reference Codes tabs are ignored automatically.
                                    @endif
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
                            <strong>Need a template?</strong>
                            <span>Includes 2 example rows to show the format (replace with your students) plus live Reference Codes on Sheet 3.</span>
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

                    <x-filament::button
                        type="button"
                        color="primary"
                        icon="heroicon-m-arrow-right"
                        icon-position="after"
                        wire:click="goToStep(2)"
                        :disabled="! $canProceedFromStep1"
                    >
                        Continue to match columns
                    </x-filament::button>
                </footer>
            </section>
        @endif

        {{-- STEP 2: MATCH COLUMNS --}}
        @if ($activeStep === 2)
            @php
                $mappingHints = [
                    \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldStudentNumber => 'Required. Unique institutional Student Number (e.g. SIA-2023-0045).',
                    \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldFirstName => 'Required. Student first / given name.',
                    \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldMiddleName => 'Optional middle name or initial.',
                    \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldLastName => 'Required. Student surname / family name.',
                    \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldEmail => 'Required. Sign-in email used for passwordless account activation.',
                    \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldProgramCode => 'Required. Program code matching TALA (e.g. DIT, DTHM).',
                    \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldCurriculumVersionCode => 'Optional if Program has a single Active curriculum; otherwise specify version code.',
                    \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldEntryTermCode => 'Optional entry term label or code (e.g. AY 2023-2024 - First Semester).',
                    \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldAcademicStanding => 'Regular or Irregular (defaults to Regular).',
                    \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldLifecycleStatus => 'ACTIVE, LEAVE_OF_ABSENCE, INACTIVE, WITHDRAWN, or TRANSFERRED_OUT.',
                    \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldBirthDate => 'Optional birth date (YYYY-MM-DD).',
                    \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldPhone => 'Optional contact phone number.',
                    \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldAddress => 'Optional home address.',
                    \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldPriorIdentifier => 'Optional LRN or prior school identifier.',
                    \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldFinancialHoldNote => 'If provided, creates an active Accounting enrollment hold.',
                    \App\Actions\StudentProfiles\OngoingStudentSpreadsheetParser::FieldPriorCompletedSubjects => 'Optional semicolon-delimited list (e.g. GE 1:1.75; IT 101:1.50).',
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
                        <h2 id="ongoing-import-step-2-heading">Match spreadsheet columns</h2>
                        <p class="tala-import-card__subtitle">
                            Confirm how columns from <strong>{{ $sheetName ?? $filename }}</strong> map to TALA student onboarding fields. Sample values from your file are shown beside each column header.
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
                                <h3>Required student fields</h3>
                                <span class="tala-import-mapping-group__count tabular-nums">
                                    {{ $requiredMappedCount }} of {{ count($requiredFieldItems) }} mapped
                                </span>
                            </div>

                            <div class="tala-import-mapping-grid">
                                @foreach ($requiredFieldItems as $item)
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
                                            >
                                                <option value="ignore">Ignore / Not in file</option>
                                                @foreach ($headerOptions as $colIdx => $colLabel)
                                                    <option value="{{ (string) $colIdx }}">{{ $colLabel }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>

                                        @if ($item['error'] !== null)
                                            <p class="tala-import-field__error">{{ $item['error'] }}</p>
                                        @elseif ($item['help'] !== null)
                                            <p class="tala-import-field__help">{{ $item['help'] }}</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="tala-import-mapping-group">
                            <div class="tala-import-mapping-group__head">
                                <h3>Optional student &amp; academic history fields</h3>
                                <span class="tala-import-mapping-group__count tabular-nums">
                                    {{ $optionalMappedCount }} of {{ count($optionalFieldItems) }} mapped
                                </span>
                            </div>

                            <div class="tala-import-mapping-grid">
                                @foreach ($optionalFieldItems as $item)
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
                                            >
                                                <option value="ignore">Ignore / Not in file</option>
                                                @foreach ($headerOptions as $colIdx => $colLabel)
                                                    <option value="{{ (string) $colIdx }}">{{ $colLabel }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>

                                        @if ($item['error'] !== null)
                                            <p class="tala-import-field__error">{{ $item['error'] }}</p>
                                        @elseif ($item['help'] !== null)
                                            <p class="tala-import-field__help">{{ $item['help'] }}</p>
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
                            Back to upload
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

        {{-- STEP 3: REVIEW & IMPORT --}}
        @if ($activeStep === 3)
            <section class="tala-import-card" aria-labelledby="ongoing-import-step-3-heading">
                <header class="tala-import-card__header">
                    <div>
                        <h2 id="ongoing-import-step-3-heading">Review &amp; confirm import</h2>
                        <p class="tala-import-card__subtitle">
                            Review validated student accounts from <strong>{{ $sheetName ?? $filename }}</strong>
                            @if ($priorSubjectsSheetName !== null)
                                and prior subjects from <strong>{{ $priorSubjectsSheetName }}</strong>
                            @endif
                            before onboarding into TALA.
                        </p>
                    </div>
                </header>

                <div class="tala-import-card__body">
                    @include('filament.admin.student-profiles.partials.import-preview', [
                        'summary' => $summary,
                        'rows' => $rows,
                        'priorSubjects' => $priorSubjects,
                        'reviewTab' => $reviewTab,
                        'trackedFixRowKeys' => $trackedFixRowKeys,
                        'standingOptions' => $standingOptions,
                    ])

                    <label class="tala-import-mode-option" style="margin-block-start: .25rem;">
                        <input
                            type="checkbox"
                            wire:model.live="sendActivationEmails"
                            class="tala-import-checkbox"
                        >
                        <span class="tala-import-mode-option__copy">
                            <strong>Send activation emails on import</strong>
                            <span>Dispatches passwordless account setup emails to each newly onboarded student immediately after import. You can also resend individually from each Student Profile later.</span>
                        </span>
                    </label>
                </div>
            </section>

            <footer class="tala-import-sticky-footer" aria-label="Import actions">
                <div class="tala-import-sticky-footer__summary">
                    <span class="tala-import-sticky-footer__counts">
                        <strong>{{ $readyCount }} {{ \Illuminate\Support\Str::plural('student', $readyCount) }} ready to onboard</strong>
                        @if ($priorReadyCount > 0)
                            <span>· {{ $priorReadyCount }} prior {{ \Illuminate\Support\Str::plural('subject', $priorReadyCount) }} ready</span>
                        @endif
                        @if ($totalSkippedSummary > 0)
                            <span>· {{ $totalSkippedSummary }} skipped</span>
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
                        Back to columns
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
                            ? "Skip {$blockedCount} unresolved ". \Illuminate\Support\Str::plural('row', $blockedCount) ." & import {$readyCount} ". \Illuminate\Support\Str::plural('student', $readyCount)
                            : ($readyCount > 0
                                ? "Confirm import ({$readyCount} ". \Illuminate\Support\Str::plural('student', $readyCount) .")"
                                : "Record {$priorReadyCount} prior ". \Illuminate\Support\Str::plural('subject', $priorReadyCount));
                    @endphp

                    <x-filament::button
                        type="button"
                        color="primary"
                        icon="heroicon-m-arrow-up-tray"
                        wire:click="commitImport"
                        wire:loading.attr="disabled"
                        wire:target="commitImport"
                        :disabled="$importableCount === 0"
                    >
                        {{ $importButtonLabel }}
                    </x-filament::button>
                </div>
            </footer>
        @endif
    </div>
</x-filament-panels::page>
