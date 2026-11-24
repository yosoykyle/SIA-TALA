@assets
    <link rel="stylesheet" href="{{ asset('css/tala-catalog.css').'?v='.filemtime(public_path('css/tala-catalog.css')) }}">
@endassets

@php
    $readyCount = (int) ($summary['ready_count'] ?? (($summary['ready_new'] ?? 0) + ($summary['ready_existing'] ?? 0)));
    $blockedCount = (int) ($summary['blocked_count'] ?? 0);
    $skippedCount = (int) ($summary['skipped_count'] ?? 0);
    $alreadyInDraftCount = (int) ($summary['already_in_draft_count'] ?? 0);
    $totalSkippedSummary = $skippedCount + $alreadyInDraftCount + $blockedCount;

    $canProceedFromStep1 = $hasFile
        && $parsedRowCount > 0
        && $uploadError === null
        && (! $isCurriculum || $existingCount === 0 || $importMode !== 'replace_draft' || $confirmReplaceDraft);

    $steps = [
        [
            'number' => 1,
            'eyebrow' => 'Step 1',
            'title' => 'Upload file',
            'meta' => $hasFile
                ? ($filename ?? 'File ready').' · '.$parsedRowCount.' '.\Illuminate\Support\Str::plural('subject', $parsedRowCount)
                : 'Choose .xlsx or .csv',
        ],
        [
            'number' => 2,
            'eyebrow' => 'Step 2',
            'title' => 'Match columns',
            'meta' => $isCurriculum && $detectedBanners
                ? 'Year & semester headings found'
                : ($hasFile ? count($headerOptions).' '.\Illuminate\Support\Str::plural('column', count($headerOptions)).' detected' : 'Verify spreadsheet columns'),
        ],
        [
            'number' => 3,
            'eyebrow' => 'Step 3',
            'title' => 'Review & import',
            'meta' => $hasFile && $parsedRowCount > 0
                ? ($blockedCount > 0
                    ? $readyCount.' ready · '.$blockedCount.' fix required'
                    : $readyCount.' ready to import')
                : 'Check rows & confirm',
        ],
    ];
@endphp

<x-filament-panels::page>
    <div class="tala-import-workspace">
        {{-- 3-Step Horizontal Stepper (No horizontal overflow) --}}
        <nav class="tala-import-stepper" aria-label="Spreadsheet import steps">
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

        {{-- STEP 1: UPLOAD FILE & IMPORT MODE --}}
        @if ($activeStep === 1)
            <section class="tala-import-card" aria-labelledby="import-step-1-heading">
                <header class="tala-import-card__header">
                    <div>
                        <h2 id="import-step-1-heading">Upload spreadsheet file</h2>
                        <p class="tala-import-card__subtitle">
                            {{ $isCurriculum
                                ? 'Upload a Servitech curriculum workbook (.xlsx) or flat spreadsheet (.csv) to populate this draft curriculum.'
                                : 'Upload an Excel (.xlsx) or CSV (.csv) list of subjects to add to the subject catalog.' }}
                        </p>
                    </div>
                </header>

                <div class="tala-import-card__body">
                    {{-- Existing Draft Subjects Guard (Add vs Replace) --}}
                    @if ($isCurriculum && $existingCount > 0)
                        <div class="tala-import-mode-card" role="region" aria-labelledby="draft-import-mode-title">
                            <div class="tala-import-mode-card__header">
                                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="tala-import-mode-card__icon" aria-hidden="true" />
                                <div>
                                    <h3 id="draft-import-mode-title">
                                        This draft already has {{ $existingCount }} {{ \Illuminate\Support\Str::plural('subject', $existingCount) }}
                                    </h3>
                                    <p>Choose how incoming subjects from your spreadsheet should be applied to this draft.</p>
                                </div>
                            </div>

                            <div class="tala-import-mode-options" role="radiogroup" aria-labelledby="draft-import-mode-title">
                                <label @class(['tala-import-mode-option', 'is-selected' => $importMode === 'add_only'])>
                                    <input
                                        type="radio"
                                        name="importMode"
                                        value="add_only"
                                        wire:model.live="importMode"
                                        class="tala-import-radio"
                                    >
                                    <span class="tala-import-mode-option__copy">
                                        <strong>Keep existing subjects &amp; add new ones</strong>
                                        <span>Subjects already placed in this draft are safely skipped.</span>
                                    </span>
                                </label>

                                <label @class(['tala-import-mode-option', 'is-danger', 'is-selected' => $importMode === 'replace_draft'])>
                                    <input
                                        type="radio"
                                        name="importMode"
                                        value="replace_draft"
                                        wire:model.live="importMode"
                                        class="tala-import-radio"
                                    >
                                    <span class="tala-import-mode-option__copy">
                                        <strong>Replace all {{ $existingCount }} {{ \Illuminate\Support\Str::plural('subject', $existingCount) }} in this draft</strong>
                                        <span>Clears current draft entries before importing. Active curricula are never affected.</span>
                                    </span>
                                </label>
                            </div>

                            @if ($importMode === 'replace_draft')
                                <label class="tala-import-replace-confirm">
                                    <input
                                        type="checkbox"
                                        wire:model.live="confirmReplaceDraft"
                                        class="tala-import-checkbox"
                                    >
                                    <span>
                                        I understand that importing will replace all {{ $existingCount }} existing {{ \Illuminate\Support\Str::plural('subject', $existingCount) }} currently in this draft.
                                    </span>
                                </label>
                            @endif
                        </div>
                    @endif

                    {{-- Dropzone / Uploaded File Summary --}}
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
                            id="tala-spreadsheet-file-input"
                            type="file"
                            accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
                            wire:model="uploadedFile"
                            class="tala-visually-hidden"
                        >

                        @if (! $hasFile)
                            <label
                                for="tala-spreadsheet-file-input"
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
                                    {{ $isCurriculum
                                        ? 'Supports multi-sheet Servitech workbooks with Year/Semester banners or flat spreadsheets up to 10 MB.'
                                        : 'Supports flat .xlsx or .csv subject lists up to 10 MB.' }}
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
                                            @if (count($sheets) > 1)
                                                {{ count($sheets) }} worksheets found ·
                                            @endif
                                            {{ $parsedRowCount }} subject {{ \Illuminate\Support\Str::plural('row', $parsedRowCount) }} detected
                                            @if ($isCurriculum && $detectedBanners)
                                                · Year &amp; Semester headings detected
                                            @endif
                                        </span>
                                    </div>
                                </div>
                                <div class="tala-import-file-card__actions">
                                    <label for="tala-spreadsheet-file-input" class="tala-import-file-replace-btn">
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
                            <span x-text="'Reading spreadsheet… ' + progress + '%'"></span>
                        </div>
                    </div>

                    {{-- Multi-sheet Worksheet Selector --}}
                    @if ($hasFile && count($sheets) > 1)
                        <div class="tala-import-worksheet-box">
                            <div class="tala-import-field">
                                <label for="tala-import-sheet-select" class="tala-import-field__label">
                                    Worksheet tab
                                </label>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select
                                        id="tala-import-sheet-select"
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
                                    Multiple tabs were found in this workbook. Confirm the worksheet you want to import.
                                </p>
                            </div>

                            @if ($mismatchWarning)
                                <x-tala-access-alert variant="warning" title="Check selected worksheet">
                                    <p>{{ $mismatchWarning }}</p>
                                </x-tala-access-alert>
                            @endif
                        </div>
                    @elseif ($hasFile && $mismatchWarning)
                        <x-tala-access-alert variant="warning" title="Check selected worksheet">
                            <p>{{ $mismatchWarning }}</p>
                        </x-tala-access-alert>
                    @endif

                    {{-- Inline Error Guard --}}
                    @if ($uploadError)
                        <x-tala-access-alert variant="danger" role="alert">
                            <p>{{ $uploadError }}</p>
                        </x-tala-access-alert>
                    @endif

                    {{-- Compact Need a Template? Bar --}}
                    <div class="tala-import-template-bar">
                        <div class="tala-import-template-bar__copy">
                            <strong>Need a template?</strong>
                            <span>Download a ready-to-fill spreadsheet with the expected columns.</span>
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
                    \App\Actions\AcademicSetup\CurriculumSpreadsheetParser::FieldCode => 'Matches existing catalog codes or creates a new subject.',
                    \App\Actions\AcademicSetup\CurriculumSpreadsheetParser::FieldTitle => 'Descriptive title of the subject.',
                    \App\Actions\AcademicSetup\CurriculumSpreadsheetParser::FieldCreditUnits => 'Total credit units (e.g. 3).',
                    \App\Actions\AcademicSetup\CurriculumSpreadsheetParser::FieldYearLevel => 'Curriculum year level (e.g. 1, 2, 3).',
                    \App\Actions\AcademicSetup\CurriculumSpreadsheetParser::FieldTermType => 'Semester or summer term placement.',
                    \App\Actions\AcademicSetup\CurriculumSpreadsheetParser::FieldLecHours => 'Weekly lecture contact hours.',
                    \App\Actions\AcademicSetup\CurriculumSpreadsheetParser::FieldLabHours => 'Weekly lab hours (or practicum total like 300 hours).',
                    \App\Actions\AcademicSetup\CurriculumSpreadsheetParser::FieldPrerequisites => 'Comma-separated prerequisite subject codes.',
                    \App\Actions\AcademicSetup\CurriculumSpreadsheetParser::FieldAcademicClassification => 'Regular, TESDA Competency, NSTP, PE, or Non-credit.',
                    \App\Actions\AcademicSetup\CurriculumSpreadsheetParser::FieldSchedulingTreatment => 'Weekly class or Arranged / Practicum.',
                    \App\Actions\AcademicSetup\CurriculumSpreadsheetParser::FieldDescription => 'Optional catalog description or notes.',
                ];

                $requiredFieldItems = [];
                $optionalFieldItems = [];

                foreach ($fieldLabels as $fieldKey => $fieldLabel) {
                    $isAlwaysRequired = in_array($fieldKey, [
                        \App\Actions\AcademicSetup\CurriculumSpreadsheetParser::FieldCode,
                        \App\Actions\AcademicSetup\CurriculumSpreadsheetParser::FieldTitle,
                        \App\Actions\AcademicSetup\CurriculumSpreadsheetParser::FieldCreditUnits,
                    ], true);
                    $isPlacementField = in_array($fieldKey, [
                        \App\Actions\AcademicSetup\CurriculumSpreadsheetParser::FieldYearLevel,
                        \App\Actions\AcademicSetup\CurriculumSpreadsheetParser::FieldTermType,
                    ], true);
                    $isRequired = $isAlwaysRequired || ($isPlacementField && ! $detectedBanners);
                    $selectedVal = (string) ($columnMap[$fieldKey] ?? 'ignore');
                    $isMapped = $selectedVal !== '' && $selectedVal !== 'ignore';
                    $item = [
                        'key' => $fieldKey,
                        'label' => $fieldLabel,
                        'isRequired' => $isRequired,
                        'isPlacementAuto' => $isPlacementField && $detectedBanners,
                        'isMapped' => $isMapped,
                        'error' => $mappingErrors[$fieldKey] ?? null,
                        'help' => ($isPlacementField && $detectedBanners)
                            ? 'Optional — placements come from Year and Semester headings in your sheet.'
                            : ($mappingHints[$fieldKey] ?? null),
                    ];

                    if ($isRequired) {
                        $requiredFieldItems[] = $item;
                    } else {
                        $optionalFieldItems[] = $item;
                    }
                }

                $requiredMappedCount = count(array_filter($requiredFieldItems, static fn (array $item): bool => $item['isMapped']));
                $optionalMappedCount = count(array_filter($optionalFieldItems, static fn (array $item): bool => $item['isMapped'] || $item['isPlacementAuto']));
            @endphp

            <section class="tala-import-card" aria-labelledby="import-step-2-heading">
                <header class="tala-import-card__header">
                    <div>
                        <h2 id="import-step-2-heading">Match spreadsheet columns</h2>
                        <p class="tala-import-card__subtitle">
                            Confirm how columns from <strong>{{ $sheetName ?? $filename }}</strong> map to TALA subject fields. Sample values from your file are shown inside each choice.
                        </p>
                    </div>
                    @if ($isCurriculum && $detectedBanners)
                        <x-filament::badge color="success" size="sm" icon="heroicon-m-check-circle">
                            Auto-detected from Year/Semester headings
                        </x-filament::badge>
                    @endif
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
                                <h3>Required fields</h3>
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
                                                <option value="ignore">— Ignore / Not in file —</option>
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

                        @if ($optionalFieldItems !== [])
                            <div class="tala-import-mapping-group">
                                <div class="tala-import-mapping-group__head">
                                    <h3>Optional fields</h3>
                                    <span class="tala-import-mapping-group__count tabular-nums">
                                        {{ $optionalMappedCount }} of {{ count($optionalFieldItems) }} mapped
                                    </span>
                                </div>

                                <div class="tala-import-mapping-grid">
                                    @foreach ($optionalFieldItems as $item)
                                        <div @class([
                                            'tala-import-mapping-card',
                                            'is-mapped' => ($item['isMapped'] || $item['isPlacementAuto']) && $item['error'] === null,
                                            'has-error' => $item['error'] !== null,
                                        ])>
                                            <div class="tala-import-mapping-card__head">
                                                <label for="col-map-{{ $item['key'] }}" class="tala-import-field__label">
                                                    {{ $item['label'] }}
                                                </label>
                                                @if ($item['isPlacementAuto'] && ! $item['isMapped'])
                                                    <span class="tala-import-pill-auto">Auto-detected</span>
                                                @elseif ($item['isMapped'])
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
                                                    <option value="ignore">— Ignore / Not in file —</option>
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
                        @endif
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
            <section class="tala-import-card" aria-labelledby="import-step-3-heading">
                <header class="tala-import-card__header">
                    <div>
                        <h2 id="import-step-3-heading">Review &amp; confirm import</h2>
                        <p class="tala-import-card__subtitle">
                            @if ($isCurriculum)
                                Review subjects from <strong>{{ $sheetName ?? $filename }}</strong> before placing them into this draft curriculum.
                            @else
                                Review subjects from <strong>{{ $sheetName ?? $filename }}</strong> before adding them to the subject catalog.
                            @endif
                        </p>
                    </div>
                </header>

                <div class="tala-import-card__body">
                    @include('filament.resources.curriculum-versions.partials.spreadsheet-import-preview', [
                        'interactiveFullPage' => true,
                        'isCurriculum' => $isCurriculum,
                        'sheetName' => $sheetName,
                        'detectedBanners' => $detectedBanners,
                        'summary' => $summary,
                        'rows' => $rows,
                        'placementGroups' => $placementGroups,
                        'reviewTab' => $reviewTab,
                        'trackedFixRowKeys' => $trackedFixRowKeys,
                        'yearOptions' => $yearOptions,
                        'semesterOptions' => $semesterOptions,
                    ])
                </div>
            </section>

            {{-- Sticky Bottom Action Bar --}}
            <footer class="tala-import-sticky-footer" aria-label="Import actions">
                <div class="tala-import-sticky-footer__summary">
                    <span class="tala-import-sticky-footer__counts">
                        <strong>{{ $readyCount }} {{ \Illuminate\Support\Str::plural('subject', $readyCount) }} ready to import</strong>
                        @if ($totalSkippedSummary > 0)
                            <span>· {{ $totalSkippedSummary }} skipped</span>
                        @endif
                    </span>

                    @if ($isCurriculum && $existingCount > 0 && $importMode === 'replace_draft')
                        <span class="tala-import-sticky-footer__replace-badge">
                            Will replace {{ $existingCount }} existing draft {{ \Illuminate\Support\Str::plural('subject', $existingCount) }}
                        </span>
                    @endif
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
                        $importButtonLabel = $blockedCount > 0 && $readyCount > 0
                            ? "Skip {$blockedCount} unresolved ". \Illuminate\Support\Str::plural('row', $blockedCount) ." & import {$readyCount} valid ". \Illuminate\Support\Str::plural('subject', $readyCount)
                            : "Import {$readyCount} ". \Illuminate\Support\Str::plural('subject', $readyCount);
                    @endphp

                    <x-filament::button
                        type="button"
                        color="primary"
                        icon="heroicon-m-arrow-up-tray"
                        wire:click="commitImport"
                        wire:loading.attr="disabled"
                        wire:target="commitImport"
                        :disabled="$readyCount === 0"
                    >
                        {{ $importButtonLabel }}
                    </x-filament::button>
                </div>
            </footer>
        @endif
    </div>
</x-filament-panels::page>
