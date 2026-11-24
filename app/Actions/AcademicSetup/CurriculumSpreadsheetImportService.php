<?php

namespace App\Actions\AcademicSetup;

use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\ExternalCompetencyRequirement;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;

class CurriculumSpreadsheetImportService
{
    public const ImportModeAddOnly = 'add_only';

    public const ImportModeReplaceDraft = 'replace_draft';

    public const StatusReadyNew = 'ready_new';

    public const StatusReadyExisting = 'ready_existing';

    public const StatusAlreadyInDraft = 'already_in_draft';

    public const StatusBlocked = 'blocked';

    public const StatusSkipped = 'skipped';

    public function __construct(
        private readonly CurriculumWorkbenchService $workbench,
    ) {}

    /**
     * Build a validation preview from parsed spreadsheet rows, applying any inline row overrides.
     *
     * @param  list<array<string, mixed>>  $parsedRows
     * @param  array<string|int, array<string, mixed>>  $rowOverrides
     * @return array{
     *     rows: list<array{
     *         row_key: string,
     *         source_row: int,
     *         code: string,
     *         normalized_code: string,
     *         title: string,
     *         credit_units: float|null,
     *         units_inferred: bool,
     *         lec_hours: float,
     *         lab_hours: float,
     *         practicum_hours: float|null,
     *         year_level: int|null,
     *         term_type: string|null,
     *         placement_label: string,
     *         raw_prerequisites: list<string>,
     *         prerequisites_text: string,
     *         resolved_prerequisites: list<array{code: string, normalized_code: string, course_id: int|null, source: string}>,
     *         academic_classification: string,
     *         academic_classification_label: string,
     *         scheduling_treatment: string,
     *         scheduling_treatment_label: string,
     *         description: string|null,
     *         matched_course_id: int|null,
     *         matched_course_code: string|null,
     *         matched_specification_id: int|null,
     *         skip: bool,
     *         status: string,
     *         status_label: string,
     *         issue_badge: string|null,
     *         issue_categories: list<string>,
     *         editable_issue_fields: list<string>,
     *         already_in_draft_placement: string|null,
     *         already_in_draft_reason: string|null,
     *         has_warnings: bool,
     *         warnings: list<string>,
     *         errors: list<string>
     *     }>,
     *     summary: array{
     *         total: int,
     *         ready_new: int,
     *         ready_existing: int,
     *         ready_count: int,
     *         already_in_draft_count: int,
     *         existing_draft_entry_count: int,
     *         warnings_count: int,
     *         blocked_count: int,
     *         skipped_count: int,
     *         import_mode: string
     *     }
     * }
     */
    public function buildPreview(
        array $parsedRows,
        ?CurriculumVersion $curriculumVersion = null,
        array $rowOverrides = [],
        string $importMode = self::ImportModeAddOnly,
    ): array {
        $normalizedImportMode = $importMode === self::ImportModeReplaceDraft
            ? self::ImportModeReplaceDraft
            : self::ImportModeAddOnly;
        $indexedOverrides = $this->normalizeOverrides($rowOverrides);

        $existingCourses = Course::query()
            ->with(['specifications' => fn ($query) => $query
                ->with(['components', 'requirements'])
                ->orderByRaw("CASE WHEN state = 'ACTIVE' THEN 0 WHEN state = 'DRAFT' THEN 1 ELSE 2 END")
                ->orderByDesc('id')])
            ->get();

        /** @var array<string, Course> $exactCourseMap */
        $exactCourseMap = [];
        /** @var array<string, Course> $normalizedCourseMap */
        $normalizedCourseMap = [];

        foreach ($existingCourses as $course) {
            $displayCode = CurriculumSpreadsheetParser::normalizeDisplayCode((string) $course->code);
            $matchCode = CurriculumSpreadsheetParser::normalizeMatchCode((string) $course->code);

            if ($displayCode !== '') {
                $exactCourseMap[$displayCode] = $course;
            }

            if ($matchCode !== '') {
                $normalizedCourseMap[$matchCode] = $course;
            }
        }

        $yearLevelLimit = 8;
        $existingDraftEntryCount = 0;
        /** @var array<string, array{code: string, year_level: string, term_label: string}> $existingCurriculumByMatchCode */
        $existingCurriculumByMatchCode = [];
        /** @var array<int, array{code: string, year_level: string, term_label: string}> $existingCurriculumByCourseId */
        $existingCurriculumByCourseId = [];

        if ($curriculumVersion instanceof CurriculumVersion) {
            $curriculumVersion->loadMissing(['program', 'entries.courseSpecification.course']);
            $yearLevelLimit = $this->workbench->yearLevelLimit($curriculumVersion);
            $existingDraftEntryCount = $curriculumVersion->entries->count();

            foreach ($curriculumVersion->entries as $entry) {
                $entryCourse = $entry->courseSpecification?->course;

                if (! $entryCourse instanceof Course) {
                    continue;
                }

                $placementMeta = [
                    'code' => (string) $entryCourse->code,
                    'year_level' => (string) $entry->year_level,
                    'term_label' => (string) $entry->term_label,
                ];

                $existingCurriculumByCourseId[(int) $entryCourse->id] = $placementMeta;
                $matchCode = CurriculumSpreadsheetParser::normalizeMatchCode((string) $entryCourse->code);

                if ($matchCode !== '') {
                    $existingCurriculumByMatchCode[$matchCode] = $placementMeta;
                }
            }
        }

        // First pass: apply overrides, check row-level invariants & duplicates, and collect valid batch codes.
        $workingRows = [];
        /** @var array<string, int> $seenBatchCodes */
        $seenBatchCodes = [];
        /** @var array<string, string> $batchCodeDisplayMap */
        $batchCodeDisplayMap = [];

        foreach ($parsedRows as $index => $rawRow) {
            $sourceRow = (int) ($rawRow['source_row'] ?? ($index + 1));
            $rowKey = (string) ($rawRow['row_key'] ?? ('row_'.$sourceRow));
            $override = $indexedOverrides[$rowKey] ?? $indexedOverrides[(string) $sourceRow] ?? [];

            $row = $this->applyRowOverride($rawRow, $override, $rowKey, $sourceRow);
            $isSkipped = (bool) ($row['skip'] ?? false);

            $code = $row['code'];
            $normalizedCode = $row['normalized_code'];
            $title = $row['title'];
            $creditUnits = $row['credit_units'];
            $yearLevel = $row['year_level'];
            $termType = $row['term_type'];
            $academicClassification = $row['academic_classification'];
            $schedulingTreatment = $row['scheduling_treatment'];

            $matchedCourse = null;

            if ($code !== '' || $normalizedCode !== '') {
                $matchedCourse = $exactCourseMap[$code] ?? $normalizedCourseMap[$normalizedCode] ?? null;
            }

            $matchedSpecification = $matchedCourse instanceof Course
                ? $this->selectAssignableSpecification($matchedCourse)
                : null;

            $errors = [];
            $warnings = array_values((array) ($row['parser_warnings'] ?? []));
            $issueCategories = [];
            $editableIssueFields = [];
            $isAlreadyInDraft = false;
            $existingPlacement = null;

            if ($curriculumVersion instanceof CurriculumVersion) {
                if ($matchedCourse instanceof Course && isset($existingCurriculumByCourseId[(int) $matchedCourse->id])) {
                    $existingPlacement = $existingCurriculumByCourseId[(int) $matchedCourse->id];
                } elseif ($normalizedCode !== '' && isset($existingCurriculumByMatchCode[$normalizedCode])) {
                    $existingPlacement = $existingCurriculumByMatchCode[$normalizedCode];
                }
            }

            if (! $isSkipped) {
                if ($normalizedImportMode === self::ImportModeAddOnly && $existingPlacement !== null) {
                    $isAlreadyInDraft = true;

                    if ($normalizedCode !== '') {
                        $seenBatchCodes[$normalizedCode] ??= $sourceRow;
                        $batchCodeDisplayMap[$normalizedCode] ??= $code;
                    }
                } else {
                    if ($code === '' || $normalizedCode === '') {
                        $errors[] = 'Missing subject code.';
                        $issueCategories[] = 'Missing code';
                        $editableIssueFields[] = 'code';
                    } elseif (strlen($code) > 64 || preg_match('/^[A-Z0-9][A-Z0-9 ._\/-]*$/', $code) !== 1) {
                        $errors[] = 'Subject code contains invalid characters.';
                        $issueCategories[] = 'Missing code';
                        $editableIssueFields[] = 'code';
                    }

                    if ($title === '') {
                        $errors[] = 'Missing subject name.';
                        $issueCategories[] = 'Missing title';
                        $editableIssueFields[] = 'title';
                    }

                    $isNonCredit = $academicClassification === CourseSpecification::AcademicClassificationNonCredit;

                    if ($creditUnits === null) {
                        $errors[] = 'Missing units.';
                        $issueCategories[] = 'Missing units';
                        $editableIssueFields[] = 'credit_units';
                    } elseif ($isNonCredit ? $creditUnits < 0.0 : $creditUnits <= 0.0) {
                        $errors[] = 'Units must be greater than zero.';
                        $issueCategories[] = 'Missing units';
                        $editableIssueFields[] = 'credit_units';
                    } elseif ($creditUnits > 30.0) {
                        $errors[] = 'Units cannot exceed 30.';
                        $issueCategories[] = 'Missing units';
                        $editableIssueFields[] = 'credit_units';
                    }

                    if ($curriculumVersion instanceof CurriculumVersion) {
                        if ($yearLevel === null) {
                            $errors[] = 'Missing year level.';
                            $issueCategories[] = 'Missing year or semester';
                            $editableIssueFields[] = 'year_level';
                        } elseif ($yearLevel > $yearLevelLimit) {
                            $errors[] = "Year {$yearLevel} exceeds this program's {$yearLevelLimit}-year length. Change the year or extend the study plan first.";
                            $issueCategories[] = 'Exceeds program years';
                            $editableIssueFields[] = 'year_level';
                        } elseif ($yearLevel < 1) {
                            $errors[] = "Year level must be between 1 and {$yearLevelLimit}.";
                            $issueCategories[] = 'Missing year or semester';
                            $editableIssueFields[] = 'year_level';
                        }

                        if ($termType === null || ! array_key_exists($termType, Term::typeOptions())) {
                            $errors[] = 'Missing or invalid semester.';
                            $issueCategories[] = 'Missing year or semester';
                            $editableIssueFields[] = 'term_type';
                        }
                    }

                    if ($normalizedCode !== '') {
                        if (isset($seenBatchCodes[$normalizedCode])) {
                            $firstSeenRow = $seenBatchCodes[$normalizedCode];
                            $errors[] = "Duplicate subject code ({$code}) in this file (first seen on row {$firstSeenRow}).";
                            $issueCategories[] = 'Duplicate in file';
                            $editableIssueFields[] = 'code';
                        } else {
                            $seenBatchCodes[$normalizedCode] = $sourceRow;
                            $batchCodeDisplayMap[$normalizedCode] = $code;
                        }
                    }

                    if ($matchedCourse instanceof Course && $matchedSpecification instanceof CourseSpecification) {
                        $incomingTitleNorm = strtolower(trim((string) (preg_replace('/\s+/', ' ', $title) ?? $title)));
                        $catalogTitleNorm = strtolower(trim((string) (preg_replace('/\s+/', ' ', (string) $matchedSpecification->title) ?? (string) $matchedSpecification->title)));
                        $titleDiffers = $incomingTitleNorm !== '' && $catalogTitleNorm !== '' && $incomingTitleNorm !== $catalogTitleNorm;
                        $unitsDiffers = $creditUnits !== null && abs((float) $creditUnits - (float) $matchedSpecification->credit_units) > 0.009;

                        if ($titleDiffers || $unitsDiffers) {
                            $catalogUnits = $this->formatUnitNumber((float) $matchedSpecification->credit_units);
                            $warnings[] = "Uses existing catalog subject {$matchedCourse->code} ({$catalogUnits} units). The active catalog stays unchanged.";
                        }
                    }
                }
            }

            $workingRows[] = [
                ...$row,
                'matched_course' => $matchedCourse,
                'matched_specification' => $matchedSpecification,
                'errors' => $errors,
                'warnings' => $warnings,
                'issue_categories' => array_values(array_unique($issueCategories)),
                'editable_issue_fields' => array_values(array_unique($editableIssueFields)),
                'is_already_in_draft' => $isAlreadyInDraft,
                'existing_placement' => $existingPlacement,
                'skip' => $isSkipped,
            ];
        }

        // Second pass: resolve prerequisites across catalog and batch, finalize status & summary counts.
        $previewRows = [];
        $readyNewCount = 0;
        $readyExistingCount = 0;
        $alreadyInDraftCount = 0;
        $warningsCount = 0;
        $blockedCount = 0;
        $skippedCount = 0;

        foreach ($workingRows as $item) {
            /** @var Course|null $matchedCourse */
            $matchedCourse = $item['matched_course'];
            /** @var CourseSpecification|null $matchedSpec */
            $matchedSpec = $item['matched_specification'];
            /** @var list<string> $errors */
            $errors = $item['errors'];
            /** @var list<string> $warnings */
            $warnings = $item['warnings'];
            /** @var list<string> $issueCategories */
            $issueCategories = $item['issue_categories'];
            /** @var list<string> $editableIssueFields */
            $editableIssueFields = $item['editable_issue_fields'];
            $isAlreadyInDraft = (bool) $item['is_already_in_draft'];
            /** @var array{code: string, year_level: string, term_label: string}|null $existingPlacement */
            $existingPlacement = $item['existing_placement'];
            $isSkipped = (bool) $item['skip'];
            $normalizedCode = (string) $item['normalized_code'];

            $resolvedPrerequisites = [];
            $seenPrereqNorms = [];

            foreach ($item['raw_prerequisites'] as $prereqToken) {
                $prereqNorm = CurriculumSpreadsheetParser::normalizeMatchCode((string) $prereqToken);
                $prereqDisplay = CurriculumSpreadsheetParser::normalizeDisplayCode((string) $prereqToken);

                if ($prereqNorm === '') {
                    continue;
                }

                if ($normalizedCode !== '' && $prereqNorm === $normalizedCode) {
                    if (! $isSkipped && ! $isAlreadyInDraft) {
                        $warnings[] = 'This subject listed itself as a prerequisite, so that link was ignored.';
                    }

                    continue;
                }

                if (isset($seenPrereqNorms[$prereqNorm])) {
                    continue;
                }

                $catalogPrereq = $exactCourseMap[$prereqDisplay] ?? $normalizedCourseMap[$prereqNorm] ?? null;

                if ($catalogPrereq instanceof Course) {
                    $resolvedPrerequisites[] = [
                        'code' => (string) $catalogPrereq->code,
                        'normalized_code' => $prereqNorm,
                        'course_id' => (int) $catalogPrereq->id,
                        'source' => 'catalog',
                    ];
                    $seenPrereqNorms[$prereqNorm] = true;

                    continue;
                }

                if (isset($batchCodeDisplayMap[$prereqNorm])) {
                    $resolvedPrerequisites[] = [
                        'code' => $batchCodeDisplayMap[$prereqNorm],
                        'normalized_code' => $prereqNorm,
                        'course_id' => null,
                        'source' => 'batch',
                    ];
                    $seenPrereqNorms[$prereqNorm] = true;

                    continue;
                }

                if (! $isSkipped && ! $isAlreadyInDraft) {
                    $warnings[] = "Prerequisite note \"{$prereqToken}\" isn't a subject code, so it wasn't linked. You can link subject prerequisites later in the draft.";
                }
            }

            $warnings = array_values(array_unique($warnings));
            $errors = array_values(array_unique($errors));

            $issueBadge = null;
            $alreadyInDraftPlacement = $existingPlacement !== null
                ? "Year {$existingPlacement['year_level']} · {$existingPlacement['term_label']}"
                : null;
            $alreadyInDraftReason = $alreadyInDraftPlacement !== null
                ? "Already in this draft ({$alreadyInDraftPlacement})."
                : null;

            if ($isSkipped) {
                $status = self::StatusSkipped;
                $statusLabel = 'Skipped';
                $skippedCount++;
            } elseif ($isAlreadyInDraft) {
                $status = self::StatusAlreadyInDraft;
                $statusLabel = 'Already in draft';
                $issueBadge = 'Already in draft';
                $isSkipped = true;
                $alreadyInDraftCount++;
            } elseif ($errors !== []) {
                $status = self::StatusBlocked;
                $issueBadge = count($issueCategories) > 1
                    ? 'Multiple issues'
                    : ($issueCategories[0] ?? 'Multiple issues');
                $statusLabel = $issueBadge;
                $blockedCount++;
            } elseif ($matchedCourse instanceof Course) {
                $status = self::StatusReadyExisting;
                $statusLabel = 'Existing matched';
                $readyExistingCount++;
            } else {
                $status = self::StatusReadyNew;
                $statusLabel = 'New subject';
                $readyNewCount++;
            }

            if (! $isSkipped && $warnings !== []) {
                $warningsCount++;
            }

            $yearLevel = $item['year_level'];
            $termType = $item['term_type'];
            $placementLabel = $this->formatPlacementLabel($yearLevel, $termType);

            $academicClassification = (string) $item['academic_classification'];
            $schedulingTreatment = (string) $item['scheduling_treatment'];

            $previewRows[] = [
                'row_key' => (string) $item['row_key'],
                'source_row' => (int) $item['source_row'],
                'code' => (string) $item['code'],
                'normalized_code' => $normalizedCode,
                'title' => (string) $item['title'],
                'credit_units' => $item['credit_units'],
                'units_inferred' => (bool) $item['units_inferred'],
                'lec_hours' => (float) $item['lec_hours'],
                'lab_hours' => (float) $item['lab_hours'],
                'practicum_hours' => $item['practicum_hours'],
                'year_level' => $yearLevel,
                'term_type' => $termType,
                'placement_label' => $placementLabel,
                'raw_prerequisites' => $item['raw_prerequisites'],
                'prerequisites_text' => (string) $item['prerequisites_text'],
                'resolved_prerequisites' => $resolvedPrerequisites,
                'academic_classification' => $academicClassification,
                'academic_classification_label' => CourseSpecification::shortClassificationLabel($academicClassification),
                'scheduling_treatment' => $schedulingTreatment,
                'scheduling_treatment_label' => $schedulingTreatment === CourseSpecification::SchedulingExternallyArranged
                    ? 'Arranged / Practicum'
                    : 'Weekly class',
                'description' => $item['description'],
                'matched_course_id' => $matchedCourse?->id !== null ? (int) $matchedCourse->id : null,
                'matched_course_code' => $matchedCourse?->code !== null ? (string) $matchedCourse->code : null,
                'matched_specification_id' => $matchedSpec?->id !== null ? (int) $matchedSpec->id : null,
                'skip' => $isSkipped,
                'status' => $status,
                'status_label' => $statusLabel,
                'issue_badge' => $issueBadge,
                'issue_categories' => $issueCategories,
                'editable_issue_fields' => $editableIssueFields,
                'already_in_draft_placement' => $alreadyInDraftPlacement,
                'already_in_draft_reason' => $alreadyInDraftReason,
                'has_warnings' => $warnings !== [],
                'warnings' => $warnings,
                'errors' => $errors,
            ];
        }

        return [
            'rows' => $previewRows,
            'summary' => [
                'total' => count($previewRows),
                'ready_new' => $readyNewCount,
                'ready_existing' => $readyExistingCount,
                'ready_count' => $readyNewCount + $readyExistingCount,
                'already_in_draft_count' => $alreadyInDraftCount,
                'existing_draft_entry_count' => $existingDraftEntryCount,
                'warnings_count' => $warningsCount,
                'blocked_count' => $blockedCount,
                'skipped_count' => $skippedCount,
                'import_mode' => $normalizedImportMode,
            ],
        ];
    }

    /**
     * Commit validated preview rows into the Subject Catalog and optional editable Draft CurriculumVersion.
     *
     * @param  array<int|string, mixed>  $previewRows
     * @return array{
     *     created_subjects: int,
     *     linked_existing: int,
     *     placed_entries: int,
     *     replaced_existing_entries: int,
     *     import_mode: string,
     *     linked_prerequisites: int,
     *     skipped_rows: list<array<string, mixed>>
     * }
     */
    public function commitImport(
        User $actor,
        array $previewRows,
        ?CurriculumVersion $curriculumVersion = null,
        bool $skipBlocked = true,
        string $importMode = self::ImportModeAddOnly,
    ): array {
        $normalizedImportMode = $importMode === self::ImportModeReplaceDraft
            ? self::ImportModeReplaceDraft
            : self::ImportModeAddOnly;

        if (isset($previewRows['rows']) && is_array($previewRows['rows'])) {
            $previewRows = $previewRows['rows'];
        }

        Gate::forUser($actor)->authorize('create', Course::class);
        Gate::forUser($actor)->authorize('create', CourseSpecification::class);

        if ($curriculumVersion instanceof CurriculumVersion) {
            if (! $curriculumVersion->isEditableDraft()) {
                Gate::forUser($actor)->authorize('update', $curriculumVersion);
            }

            Gate::forUser($actor)->authorize('update', $curriculumVersion);
        }

        return DB::transaction(function () use ($actor, $previewRows, $curriculumVersion, $skipBlocked, $normalizedImportMode): array {
            $lockedCurriculum = null;

            if ($curriculumVersion instanceof CurriculumVersion) {
                $lockedCurriculum = CurriculumVersion::query()
                    ->with('program')
                    ->lockForUpdate()
                    ->findOrFail($curriculumVersion->id);

                Gate::forUser($actor)->authorize('update', $lockedCurriculum);

                if (! $lockedCurriculum->isEditableDraft()) {
                    throw ValidationException::withMessages([
                        'curriculum_version' => 'Only an editable draft curriculum can accept spreadsheet imports.',
                    ]);
                }
            }

            $revalidated = $this->buildPreview(
                parsedRows: array_values($previewRows),
                curriculumVersion: $lockedCurriculum,
                rowOverrides: [],
                importMode: $normalizedImportMode,
            );
            $rows = $revalidated['rows'];

            $skippedRows = [];
            $validRows = [];

            foreach ($rows as $row) {
                if ($row['status'] === self::StatusAlreadyInDraft) {
                    $skippedRows[] = [
                        ...$row,
                        'skip_reason' => (string) ($row['already_in_draft_reason'] ?? 'Already in draft.'),
                    ];

                    continue;
                }

                if ($row['status'] === self::StatusSkipped || $row['skip'] === true) {
                    $skippedRows[] = [
                        ...$row,
                        'skip_reason' => 'Skipped by user.',
                    ];

                    continue;
                }

                if ($row['status'] === self::StatusBlocked || $row['errors'] !== []) {
                    if (! $skipBlocked) {
                        throw ValidationException::withMessages([
                            'rows' => 'Resolve or skip all rows with errors before importing: '.implode(' ', $row['errors']),
                        ]);
                    }

                    $skippedRows[] = [
                        ...$row,
                        'skip_reason' => implode(' ', $row['errors']),
                    ];

                    continue;
                }

                $validRows[] = $row;
            }

            $replacedExistingEntries = 0;

            if ($normalizedImportMode === self::ImportModeReplaceDraft && $lockedCurriculum instanceof CurriculumVersion) {
                if ($validRows === []) {
                    throw ValidationException::withMessages([
                        'rows' => 'At least one valid subject row is required before replacing existing draft subjects.',
                    ]);
                }

                $existingEntryIds = $lockedCurriculum->entries()
                    ->lockForUpdate()
                    ->pluck('id')
                    ->all();

                $replacedExistingEntries = count($existingEntryIds);

                if ($existingEntryIds !== []) {
                    ExternalCompetencyRequirement::query()
                        ->whereIn('curriculum_entry_id', $existingEntryIds)
                        ->update(['curriculum_entry_id' => null]);

                    CurriculumEntry::query()
                        ->whereIn('id', $existingEntryIds)
                        ->delete();
                }

                $lockedCurriculum->unsetRelation('entries');
            }

            $existingCourses = Course::query()
                ->with(['specifications' => fn ($query) => $query
                    ->with(['components', 'requirements'])
                    ->orderByRaw("CASE WHEN state = 'ACTIVE' THEN 0 WHEN state = 'DRAFT' THEN 1 ELSE 2 END")
                    ->orderByDesc('id')])
                ->lockForUpdate()
                ->get();

            /** @var array<string, Course> $coursesByExactCode */
            $coursesByExactCode = [];
            /** @var array<string, Course> $coursesByMatchCode */
            $coursesByMatchCode = [];

            foreach ($existingCourses as $course) {
                $displayCode = CurriculumSpreadsheetParser::normalizeDisplayCode((string) $course->code);
                $matchCode = CurriculumSpreadsheetParser::normalizeMatchCode((string) $course->code);

                if ($displayCode !== '') {
                    $coursesByExactCode[$displayCode] = $course;
                }

                if ($matchCode !== '') {
                    $coursesByMatchCode[$matchCode] = $course;
                }
            }

            $createdSubjects = 0;
            $linkedExisting = 0;
            $placedEntries = 0;
            $linkedPrerequisites = 0;

            /** @var array<string, array{specification: CourseSpecification, course: Course, row: array<string, mixed>}> $newlyCreatedSpecs */
            $newlyCreatedSpecs = [];
            /** @var array<string, CourseSpecification> $resolvedSpecsByRowKey */
            $resolvedSpecsByRowKey = [];

            // Pass 1: Create new courses/specifications or link existing catalog subjects without overwriting existing Active/Draft specifications.
            foreach ($validRows as $row) {
                $rowKey = (string) $row['row_key'];
                $code = (string) $row['code'];
                $normalizedCode = (string) $row['normalized_code'];

                $existingCourse = $coursesByExactCode[$code] ?? $coursesByMatchCode[$normalizedCode] ?? null;

                if ($existingCourse instanceof Course) {
                    $existingSpec = $this->selectAssignableSpecification($existingCourse);

                    if ($existingSpec instanceof CourseSpecification) {
                        $resolvedSpecsByRowKey[$rowKey] = $existingSpec;
                        $linkedExisting++;

                        continue;
                    }

                    // Course exists but had only RETIRED or zero specifications; create a fresh Draft revision.
                    $newSpec = $this->createDraftSpecificationForRow($existingCourse, $row, $lockedCurriculum);
                    $resolvedSpecsByRowKey[$rowKey] = $newSpec;
                    $newlyCreatedSpecs[$rowKey] = [
                        'specification' => $newSpec,
                        'course' => $existingCourse,
                        'row' => $row,
                    ];
                    $linkedExisting++;

                    continue;
                }

                $newCourse = Course::query()->create([
                    'code' => $code,
                    'state' => Course::StateActive,
                ]);

                $newSpec = $this->createDraftSpecificationForRow($newCourse, $row, $lockedCurriculum);

                $coursesByExactCode[$code] = $newCourse;
                $coursesByMatchCode[$normalizedCode] = $newCourse;
                $resolvedSpecsByRowKey[$rowKey] = $newSpec;
                $newlyCreatedSpecs[$rowKey] = [
                    'specification' => $newSpec,
                    'course' => $newCourse,
                    'row' => $row,
                ];
                $createdSubjects++;
            }

            // Pass 2: Link resolved prerequisites on newly created specifications (never self-linking or duplicating).
            foreach ($newlyCreatedSpecs as $item) {
                $specification = $item['specification'];
                $course = $item['course'];
                $row = $item['row'];

                $linkedCourseIds = [];
                $sequence = 1;

                foreach ($row['raw_prerequisites'] ?? [] as $prereqToken) {
                    $prereqNorm = CurriculumSpreadsheetParser::normalizeMatchCode((string) $prereqToken);
                    $prereqDisplay = CurriculumSpreadsheetParser::normalizeDisplayCode((string) $prereqToken);

                    if ($prereqNorm === '' || $prereqNorm === $row['normalized_code']) {
                        continue;
                    }

                    $relatedCourse = $coursesByExactCode[$prereqDisplay] ?? $coursesByMatchCode[$prereqNorm] ?? null;

                    if (! $relatedCourse instanceof Course || (int) $relatedCourse->id === (int) $course->id) {
                        continue;
                    }

                    if (isset($linkedCourseIds[(int) $relatedCourse->id])) {
                        continue;
                    }

                    $specification->requirements()->create([
                        'rule_type' => CourseRequirement::TypePrerequisite,
                        'group_key' => CourseRequirement::TypePrerequisite.'-'.$relatedCourse->id,
                        'related_course_id' => $relatedCourse->id,
                        'state' => CourseRequirement::StateActive,
                        'sequence' => $sequence++,
                    ]);

                    $linkedCourseIds[(int) $relatedCourse->id] = true;
                    $linkedPrerequisites++;
                }
            }

            // Pass 3: Place entries into the editable Draft curriculum if importing into a curriculum.
            if ($lockedCurriculum instanceof CurriculumVersion) {
                foreach ($validRows as $row) {
                    $rowKey = (string) $row['row_key'];
                    $specification = $resolvedSpecsByRowKey[$rowKey] ?? null;

                    if (! $specification instanceof CourseSpecification) {
                        continue;
                    }

                    $this->workbench->createEntry($actor, $lockedCurriculum, [
                        'course_specification_id' => $specification->id,
                        'year_level' => (int) $row['year_level'],
                        'term_type' => (string) $row['term_type'],
                        'requirement_group' => CurriculumEntry::RequirementGroupRequired,
                    ]);

                    $placedEntries++;
                }

                activity()
                    ->performedOn($lockedCurriculum)
                    ->causedBy($actor)
                    ->event('curriculum_spreadsheet_imported')
                    ->withProperties([
                        'curriculum_version_id' => $lockedCurriculum->id,
                        'import_mode' => $normalizedImportMode,
                        'replaced_existing_entries' => $replacedExistingEntries,
                        'created_subjects' => $createdSubjects,
                        'linked_existing' => $linkedExisting,
                        'placed_entries' => $placedEntries,
                        'linked_prerequisites' => $linkedPrerequisites,
                        'skipped_count' => count($skippedRows),
                    ])
                    ->log('Curriculum subjects imported from spreadsheet');
            } else {
                activity()
                    ->causedBy($actor)
                    ->event('catalog_spreadsheet_imported')
                    ->withProperties([
                        'import_mode' => $normalizedImportMode,
                        'replaced_existing_entries' => 0,
                        'created_subjects' => $createdSubjects,
                        'linked_existing' => $linkedExisting,
                        'linked_prerequisites' => $linkedPrerequisites,
                        'skipped_count' => count($skippedRows),
                    ])
                    ->log('Catalog subjects imported from spreadsheet');
            }

            return [
                'created_subjects' => $createdSubjects,
                'linked_existing' => $linkedExisting,
                'placed_entries' => $placedEntries,
                'replaced_existing_entries' => $replacedExistingEntries,
                'import_mode' => $normalizedImportMode,
                'linked_prerequisites' => $linkedPrerequisites,
                'skipped_rows' => $skippedRows,
            ];
        });
    }

    /**
     * Export skipped/blocked rows to a formula-safe temporary CSV file and return its absolute file path.
     *
     * @param  list<array<string, mixed>>  $skippedRows
     */
    public function exportSkippedRowsCsv(array $skippedRows): string
    {
        $exportDir = storage_path('app/private/exports/'.Str::ulid());
        File::ensureDirectoryExists($exportDir);

        $filePath = $exportDir.DIRECTORY_SEPARATOR.'skipped-import-rows-'.now()->format('Y-m-d').'.csv';

        $writer = new CsvWriter;
        $writer->openToFile($filePath);

        try {
            $writer->addRow(Row::fromValues([
                'Row',
                'Subject Code',
                'Subject Name',
                'Units',
                'Year Level',
                'Semester',
                'Reason',
            ]));

            foreach ($skippedRows as $row) {
                $reason = (string) ($row['skip_reason'] ?? implode(' ', (array) ($row['errors'] ?? [])));

                if ($reason === '') {
                    $reason = 'Skipped';
                }

                $termLabel = isset($row['term_type']) && is_string($row['term_type'])
                    ? (Term::typeOptions()[$row['term_type']] ?? $row['term_type'])
                    : '';

                $writer->addRow(Row::fromValues([
                    (int) ($row['source_row'] ?? 0),
                    CurriculumSpreadsheetExportService::neutralizeFormulaInjection((string) ($row['code'] ?? '')),
                    CurriculumSpreadsheetExportService::neutralizeFormulaInjection((string) ($row['title'] ?? '')),
                    $row['credit_units'] !== null && $row['credit_units'] !== '' ? (float) $row['credit_units'] : '',
                    $row['year_level'] !== null && $row['year_level'] !== '' ? (int) $row['year_level'] : '',
                    CurriculumSpreadsheetExportService::neutralizeFormulaInjection((string) $termLabel),
                    CurriculumSpreadsheetExportService::neutralizeFormulaInjection($reason),
                ]));
            }
        } finally {
            $writer->close();
        }

        return $filePath;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function createDraftSpecificationForRow(
        Course $course,
        array $row,
        ?CurriculumVersion $curriculumVersion,
    ): CourseSpecification {
        $schedulingTreatment = (string) ($row['scheduling_treatment'] ?? CourseSpecification::SchedulingRecurring);
        $academicClassification = (string) ($row['academic_classification'] ?? CourseSpecification::AcademicClassificationOrdinary);
        $creditUnits = round((float) ($row['credit_units'] ?? 0.0), 2);

        $specification = $course->specifications()->create([
            'revision_code' => 'DRAFT-'.Str::ulid(),
            'title' => trim((string) $row['title']),
            'description' => filled($row['description'] ?? null) ? trim((string) $row['description']) : null,
            'credit_units' => $creditUnits,
            'grading_profile_key' => CourseSpecification::GradingProfileServitechV1,
            'grading_profile_version' => 1,
            'academic_classification' => $academicClassification,
            'scheduling_treatment' => $schedulingTreatment,
            'allowed_modalities' => [
                CourseSpecification::ModalityFaceToFace,
                CourseSpecification::ModalityOnline,
            ],
            'same_faculty_default' => true,
            'state' => CourseSpecification::StateDraft,
        ]);

        if ($schedulingTreatment === CourseSpecification::SchedulingRecurring) {
            $lecHours = (float) ($row['lec_hours'] ?? 0.0);
            $labHours = (float) ($row['lab_hours'] ?? 0.0);

            if ($lecHours <= 0.0 && $labHours <= 0.0) {
                $lecHours = max(1.0, min(6.0, $creditUnits > 0.0 ? $creditUnits : 3.0));
            }

            $labRoomType = $this->resolveDefaultLabRoomType((string) $course->code, $curriculumVersion);
            $sequence = 1;

            if ($lecHours > 0.0) {
                foreach ($this->buildComponentsForHours(CourseComponent::TypeLecture, $lecHours, CourseComponent::RoomTypeLectureRoom, $sequence) as $componentData) {
                    $specification->components()->create($componentData);
                    $sequence++;
                }
            }

            if ($labHours > 0.0) {
                foreach ($this->buildComponentsForHours(CourseComponent::TypeLaboratory, $labHours, $labRoomType, $sequence) as $componentData) {
                    $specification->components()->create($componentData);
                    $sequence++;
                }
            }
        }

        return $specification;
    }

    /**
     * Build one or more validated CourseComponent attribute arrays whose weekly_contact_hours match their meeting_pattern.
     *
     * @return list<array<string, mixed>>
     */
    private function buildComponentsForHours(
        string $componentType,
        float $hours,
        string $roomType,
        int $startingSequence,
    ): array {
        $clampedTotal = max(1.0, min(12.0, $hours));

        if ($clampedTotal <= 6.0) {
            $pattern = CourseComponent::defaultMeetingPatternForHours($clampedTotal, $roomType);
            $patternMinutes = CourseComponent::meetingPatternMinutes($pattern) ?? (int) round($clampedTotal * 60);

            return [[
                'component_type' => $componentType,
                'weekly_contact_hours' => round($patternMinutes / 60, 2),
                'meeting_pattern' => $pattern,
                'room_type_default' => $roomType,
                'required_room_feature_keys' => [],
                'modality_restriction' => null,
                'requires_consecutive_block' => false,
                'same_faculty' => true,
                'sequence' => $startingSequence,
            ]];
        }

        $remaining = $clampedTotal;
        $components = [];
        $seq = $startingSequence;

        while ($remaining > 0.001) {
            $chunk = min(6.0, $remaining);
            $pattern = CourseComponent::defaultMeetingPatternForHours($chunk, $roomType);
            $patternMinutes = CourseComponent::meetingPatternMinutes($pattern) ?? (int) round($chunk * 60);

            $components[] = [
                'component_type' => $componentType,
                'weekly_contact_hours' => round($patternMinutes / 60, 2),
                'meeting_pattern' => $pattern,
                'room_type_default' => $roomType,
                'required_room_feature_keys' => [],
                'modality_restriction' => null,
                'requires_consecutive_block' => false,
                'same_faculty' => true,
                'sequence' => $seq++,
            ];

            $remaining = round($remaining - $chunk, 2);
        }

        return $components;
    }

    private function resolveDefaultLabRoomType(string $courseCode, ?CurriculumVersion $curriculumVersion): string
    {
        $programCode = strtoupper(trim((string) ($curriculumVersion?->program?->code ?? '')));
        $programName = strtoupper(trim((string) ($curriculumVersion?->program?->name ?? '')));

        if (preg_match('/\b(?:DIT|BSIT|BSCS|BSIS|IT|CS|IS)\b/', $programCode) === 1
            || str_contains($programName, 'INFORMATION TECHNOLOGY')
            || str_contains($programName, 'COMPUTER')
            || preg_match('/^(?:CC|IT|DIT|CS|IS|COMP|PROG|WEB|NET|SYS)\b/i', trim($courseCode)) === 1) {
            return CourseComponent::RoomTypeComputerLaboratory;
        }

        return CourseComponent::RoomTypeLaboratory;
    }

    private function selectAssignableSpecification(Course $course): ?CourseSpecification
    {
        $specifications = $course->relationLoaded('specifications')
            ? $course->specifications
            : $course->specifications()
                ->orderByRaw("CASE WHEN state = 'ACTIVE' THEN 0 WHEN state = 'DRAFT' THEN 1 ELSE 2 END")
                ->orderByDesc('id')
                ->get();

        return $specifications
            ->first(fn (CourseSpecification $spec): bool => in_array($spec->state, [
                CourseSpecification::StateActive,
                CourseSpecification::StateDraft,
            ], true));
    }

    /**
     * @param  array<string|int, array<string, mixed>>  $rowOverrides
     * @return array<string, array<string, mixed>>
     */
    private function normalizeOverrides(array $rowOverrides): array
    {
        $indexed = [];

        foreach ($rowOverrides as $key => $override) {
            if (! is_array($override)) {
                continue;
            }

            $resolvedKey = isset($override['row_key']) && is_string($override['row_key']) && $override['row_key'] !== ''
                ? $override['row_key']
                : (string) $key;

            $indexed[$resolvedKey] = $override;
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>  $rawRow
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function applyRowOverride(array $rawRow, array $override, string $rowKey, int $sourceRow): array
    {
        $codeInput = array_key_exists('code', $override)
            ? (string) ($override['code'] ?? '')
            : (string) ($rawRow['code'] ?? '');
        $code = CurriculumSpreadsheetParser::normalizeDisplayCode($codeInput);
        $normalizedCode = CurriculumSpreadsheetParser::normalizeMatchCode($codeInput);

        $titleInput = array_key_exists('title', $override)
            ? (string) ($override['title'] ?? '')
            : (string) ($rawRow['title'] ?? '');
        $title = trim((string) (preg_replace('/\s+/', ' ', $titleInput) ?? $titleInput));

        $creditUnits = $rawRow['credit_units'] ?? null;
        $unitsInferred = (bool) ($rawRow['units_inferred'] ?? false);
        $parserWarnings = array_values((array) ($rawRow['parser_warnings'] ?? []));

        if (array_key_exists('credit_units', $override)) {
            $overrideUnits = $override['credit_units'];

            if ($overrideUnits === null || $overrideUnits === '') {
                $creditUnits = null;
            } elseif (is_numeric($overrideUnits)) {
                $creditUnits = round((float) $overrideUnits, 2);
                $unitsInferred = false;
                $parserWarnings = array_values(array_filter(
                    $parserWarnings,
                    fn (string $w): bool => ! str_contains($w, 'Units inferred') && ! str_contains($w, 'Units set to'),
                ));
            }
        } elseif ($creditUnits !== null && $creditUnits !== '' && is_numeric($creditUnits)) {
            $creditUnits = round((float) $creditUnits, 2);
        } else {
            $creditUnits = null;
        }

        $lecHours = array_key_exists('lec_hours', $override) && is_numeric($override['lec_hours'])
            ? max(0.0, (float) $override['lec_hours'])
            : max(0.0, (float) ($rawRow['lec_hours'] ?? 0.0));

        $labHours = array_key_exists('lab_hours', $override) && is_numeric($override['lab_hours'])
            ? max(0.0, (float) $override['lab_hours'])
            : max(0.0, (float) ($rawRow['lab_hours'] ?? 0.0));

        $yearLevel = $rawRow['year_level'] ?? null;

        if (array_key_exists('year_level', $override)) {
            $yearLevel = $override['year_level'] !== null && $override['year_level'] !== ''
                ? CurriculumSpreadsheetParser::parseYearLevel($override['year_level'])
                : null;
        } elseif ($yearLevel !== null && $yearLevel !== '') {
            $yearLevel = CurriculumSpreadsheetParser::parseYearLevel($yearLevel);
        } else {
            $yearLevel = null;
        }

        $termType = $rawRow['term_type'] ?? null;

        if (array_key_exists('term_type', $override)) {
            $termType = $override['term_type'] !== null && $override['term_type'] !== ''
                ? CurriculumSpreadsheetParser::parseTermType($override['term_type'])
                : null;
        } elseif ($termType !== null && $termType !== '') {
            $termType = CurriculumSpreadsheetParser::parseTermType($termType);
        } else {
            $termType = null;
        }

        $rawPrerequisites = $rawRow['raw_prerequisites'] ?? null;

        if (array_key_exists('prerequisites', $override) || array_key_exists('prerequisites_text', $override)) {
            $prereqStr = (string) ($override['prerequisites'] ?? $override['prerequisites_text'] ?? '');
            $rawPrerequisites = CurriculumSpreadsheetParser::parsePrerequisitesCell($prereqStr);
        } elseif (! is_array($rawPrerequisites)) {
            $rawPrerequisites = CurriculumSpreadsheetParser::parsePrerequisitesCell((string) ($rawRow['prerequisites_text'] ?? ''));
        }

        $academicClassification = (string) ($override['academic_classification']
            ?? $rawRow['academic_classification']
            ?? CourseSpecification::AcademicClassificationOrdinary);

        if (! array_key_exists($academicClassification, CourseSpecification::academicClassificationOptions())) {
            $academicClassification = CourseSpecification::AcademicClassificationOrdinary;
        }

        $schedulingTreatment = (string) ($override['scheduling_treatment']
            ?? $rawRow['scheduling_treatment']
            ?? CourseSpecification::SchedulingRecurring);

        if (! array_key_exists($schedulingTreatment, CourseSpecification::schedulingTreatmentOptions())) {
            $schedulingTreatment = CourseSpecification::SchedulingRecurring;
        }

        $description = array_key_exists('description', $override)
            ? (filled($override['description']) ? trim((string) $override['description']) : null)
            : (filled($rawRow['description'] ?? null) ? trim((string) $rawRow['description']) : null);

        $wasAutoSkippedAsInDraft = ($rawRow['status'] ?? null) === self::StatusAlreadyInDraft;
        $skip = array_key_exists('skip', $override)
            ? (bool) $override['skip']
            : ($wasAutoSkippedAsInDraft ? false : (bool) ($rawRow['skip'] ?? false));

        return [
            'row_key' => $rowKey,
            'source_row' => $sourceRow,
            'code' => $code,
            'normalized_code' => $normalizedCode,
            'title' => $title,
            'credit_units' => $creditUnits,
            'units_inferred' => $unitsInferred,
            'lec_hours' => $lecHours,
            'lab_hours' => $labHours,
            'practicum_hours' => isset($rawRow['practicum_hours']) && is_numeric($rawRow['practicum_hours'])
                ? (float) $rawRow['practicum_hours']
                : null,
            'year_level' => $yearLevel,
            'term_type' => $termType,
            'raw_prerequisites' => array_values($rawPrerequisites),
            'prerequisites_text' => implode(', ', $rawPrerequisites),
            'academic_classification' => $academicClassification,
            'scheduling_treatment' => $schedulingTreatment,
            'description' => $description,
            'parser_warnings' => $parserWarnings,
            'skip' => $skip,
        ];
    }

    private function formatPlacementLabel(?int $yearLevel, ?string $termType): string
    {
        if ($yearLevel === null && $termType === null) {
            return '—';
        }

        $yearPart = $yearLevel !== null ? "Year {$yearLevel}" : 'Year ?';
        $termPart = match ($termType) {
            Term::TypeFirstSemester => '1st Sem',
            Term::TypeSecondSemester => '2nd Sem',
            Term::TypeSpecialTerm => 'Summer',
            default => 'Term ?',
        };

        return "{$yearPart} · {$termPart}";
    }

    private function formatUnitNumber(float $units): string
    {
        return floor($units) === $units
            ? (string) (int) $units
            : rtrim(rtrim(number_format($units, 2, '.', ''), '0'), '.');
    }
}
