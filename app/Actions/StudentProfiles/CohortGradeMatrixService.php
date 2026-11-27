<?php

namespace App\Actions\StudentProfiles;

use App\Actions\AcademicSetup\CurriculumSpreadsheetExportService;
use App\Actions\AcademicSetup\CurriculumSpreadsheetParser;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\PriorAcademicCredit;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use DateInterval;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\SheetInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CohortGradeMatrixService
{
    public const SheetMatrix = 'Grade Matrix';

    public const SheetLegend = 'Instructions & Legend';

    public function __construct(
        private readonly RecordPriorAcademicCredits $priorCredits,
    ) {}

    /**
     * Return chronological (Year Level · Term) blocks present in the selected curriculum.
     * Powers both Stage 1 "Prior Terms Completed" quick-credit dropdown and Stage 2B Grade Matrix selector.
     *
     * @return list<array{
     *     key: string,
     *     year_level: int,
     *     term_type: string,
     *     term_label: string,
     *     label: string,
     *     subject_count: int,
     *     cumulative_subject_count: int,
     *     total_units: float,
     *     cumulative_units: float
     * }>
     */
    public function availableTermsForCurriculum(int $curriculumVersionId): array
    {
        $entries = CurriculumEntry::query()
            ->with('courseSpecification')
            ->where('curriculum_version_id', $curriculumVersionId)
            ->orderBy('year_level')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->filter(fn (CurriculumEntry $entry): bool => $entry->courseSpecification instanceof CourseSpecification
                && $entry->courseSpecification->state !== CourseSpecification::StateDraft
                && is_numeric($entry->year_level));

        $grouped = [];
        foreach ($entries as $entry) {
            $yearLevel = (int) $entry->year_level;
            $termType = (string) ($entry->term_type ?: Term::TypeFirstSemester);
            $termOrder = $this->termTypeOrder($termType);
            $key = $yearLevel.'|'.$termType;

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'key' => $key,
                    'year_level' => $yearLevel,
                    'term_type' => $termType,
                    'term_order' => $termOrder,
                    'term_label' => $this->friendlyTermLabel($termType, $entry->term_label),
                    'spec_ids' => [],
                    'units' => 0.0,
                ];
            }

            $specId = (int) $entry->course_specification_id;
            if (! isset($grouped[$key]['spec_ids'][$specId])) {
                $grouped[$key]['spec_ids'][$specId] = true;
                $grouped[$key]['units'] += (float) ($entry->courseSpecification?->credit_units ?? 0);
            }
        }

        uasort($grouped, static fn (array $a, array $b): int => [$a['year_level'], $a['term_order']] <=> [$b['year_level'], $b['term_order']]);

        $result = [];
        $cumulativeSpecs = [];
        $cumulativeUnits = 0.0;

        foreach ($grouped as $item) {
            foreach (array_keys($item['spec_ids']) as $specId) {
                if (! isset($cumulativeSpecs[$specId])) {
                    $cumulativeSpecs[$specId] = true;
                }
            }
            $cumulativeUnits += $item['units'];

            $result[] = [
                'key' => $item['key'],
                'year_level' => $item['year_level'],
                'term_type' => $item['term_type'],
                'term_label' => $item['term_label'],
                'label' => "Year {$item['year_level']} · {$item['term_label']}",
                'subject_count' => count($item['spec_ids']),
                'cumulative_subject_count' => count($cumulativeSpecs),
                'total_units' => round($item['units'], 2),
                'cumulative_units' => round($cumulativeUnits, 2),
            ];
        }

        return $result;
    }

    /**
     * Build the query for non-archived students in a cohort (Program + Curriculum + optional Entry AY).
     *
     * @return Builder<StudentProfile>
     */
    public function cohortStudentsQuery(int $programId, int $curriculumVersionId, ?int $entryAcademicYearId = null): Builder
    {
        return StudentProfile::query()
            ->active()
            ->where('program_id', $programId)
            ->where('curriculum_version_id', $curriculumVersionId)
            ->when($entryAcademicYearId !== null, function (Builder $query) use ($entryAcademicYearId): void {
                $query->where(function (Builder $scoped) use ($entryAcademicYearId): void {
                    $scoped->where('entry_academic_year_id', $entryAcademicYearId)
                        ->orWhereHas('entryTerm', fn (Builder $termQuery): Builder => $termQuery->where('academic_year_id', $entryAcademicYearId));
                });
            })
            ->orderBy('student_number')
            ->orderBy('last_name')
            ->orderBy('first_name');
    }

    /**
     * Export a pre-filled Wide Cohort Grade Matrix `.xlsx` workbook for the chosen Program, Curriculum, and Prior Year/Term.
     */
    public function exportMatrix(
        Program $program,
        CurriculumVersion $curriculum,
        int $yearLevel,
        string $termType,
        ?int $entryAcademicYearId = null,
    ): string {
        $entries = $this->curriculumEntriesForTerm($curriculum->id, $yearLevel, $termType);

        $students = $this->cohortStudentsQuery($program->id, $curriculum->id, $entryAcademicYearId)
            ->with(['user'])
            ->get();

        $specIds = $entries->pluck('course_specification_id')->unique()->values()->all();
        $studentIds = $students->pluck('id')->all();

        /** @var array<int, array<int, PriorAcademicCredit>> $creditsByStudentAndSpec */
        $creditsByStudentAndSpec = [];
        if ($studentIds !== [] && $specIds !== []) {
            PriorAcademicCredit::query()
                ->active()
                ->whereIn('student_profile_id', $studentIds)
                ->whereIn('course_specification_id', $specIds)
                ->orderByDesc('id')
                ->get()
                ->each(function (PriorAcademicCredit $credit) use (&$creditsByStudentAndSpec): void {
                    $sid = (int) $credit->student_profile_id;
                    $specId = (int) $credit->course_specification_id;
                    if (! isset($creditsByStudentAndSpec[$sid][$specId])) {
                        $creditsByStudentAndSpec[$sid][$specId] = $credit;
                    }
                });
        }

        $termSlug = Str::slug("y{$yearLevel}-".$this->friendlyTermLabel($termType));
        $filename = Str::slug((string) $program->code.'-'.(string) $curriculum->version_code.'-'.$termSlug.'-grade-matrix').'.xlsx';
        $path = $this->prepareExportPath($filename);

        $headers = [
            'Student Number',
            'Student Name',
            'Status',
        ];
        foreach ($entries as $entry) {
            $code = (string) ($entry->courseSpecification?->course?->code ?? 'SUBJ-'.$entry->course_specification_id);
            $title = (string) ($entry->courseSpecification?->title ?? '');
            $headers[] = self::neutralize($title !== '' ? "{$code} — {$title}" : $code);
        }

        $matrixRows = [$headers];

        foreach ($students as $student) {
            $fullName = collect([$student->last_name.',', $student->first_name, $student->middle_name])
                ->filter()
                ->implode(' ');
            $fullName = trim($fullName, ', ');

            $row = [
                self::neutralize((string) $student->student_number),
                self::neutralize($fullName),
                self::neutralize((string) ($student->academic_standing ?: StudentProfile::StandingRegular)),
            ];

            foreach ($entries as $entry) {
                $existing = $creditsByStudentAndSpec[(int) $student->id][(int) $entry->course_specification_id] ?? null;
                $row[] = $existing instanceof PriorAcademicCredit
                    ? self::neutralize($existing->effectiveResultCode())
                    : '';
            }

            $matrixRows[] = $row;
        }

        $legendRows = $this->buildLegendRows($program, $curriculum, $yearLevel, $termType, $entries);

        $writer = new XlsxWriter;
        $writer->openToFile($path);

        try {
            $writer->getCurrentSheet()->setName(self::SheetMatrix);
            foreach ($matrixRows as $values) {
                $writer->addRow(Row::fromValues($values));
            }

            $legendSheet = $writer->addNewSheetAndMakeItCurrent();
            $legendSheet->setName(self::SheetLegend);
            foreach ($legendRows as $values) {
                $writer->addRow(Row::fromValues($values));
            }
        } finally {
            $writer->close();
        }

        return $path;
    }

    /**
     * Parse an uploaded Cohort Grade Matrix (`.xlsx` or `.csv`) and compute an exact dry-run diff
     * (`CREDIT -> 1.75`, new `5.00` / `INC` / `DRP` / `TC`, unchanged cells, and invalid cell errors).
     *
     * @return array{
     *     summary: array{
     *         students_scanned: int,
     *         students_with_changes: int,
     *         matched_subject_columns: int,
     *         unmatched_subject_columns: list<string>,
     *         changes_total: int,
     *         upgrades_count: int,
     *         new_credits_count: int,
     *         unchanged_count: int,
     *         error_count: int
     *     },
     *     changes: list<array<string, mixed>>,
     *     errors: list<array<string, mixed>>
     * }
     */
    public function previewMatrix(
        string $path,
        Program $program,
        CurriculumVersion $curriculum,
        int $yearLevel,
        string $termType,
        ?string $extension = null,
        ?string $academicYearLabel = null,
        ?string $sourceSchool = null,
    ): array {
        $rawRows = $this->readMatrixSheetRows($path, $extension);

        if ($rawRows === []) {
            return [
                'summary' => [
                    'students_scanned' => 0,
                    'students_with_changes' => 0,
                    'matched_subject_columns' => 0,
                    'unmatched_subject_columns' => [],
                    'changes_total' => 0,
                    'upgrades_count' => 0,
                    'new_credits_count' => 0,
                    'unchanged_count' => 0,
                    'error_count' => 1,
                ],
                'changes' => [],
                'errors' => [[
                    'source_row' => 1,
                    'student_number' => '',
                    'subject_code' => '',
                    'raw_value' => '',
                    'message' => 'The uploaded Grade Matrix file is empty.',
                ]],
            ];
        }

        // Locate header row containing Student Number.
        $headerOffset = 0;
        $studentNumberCol = null;

        foreach ($rawRows as $offset => $entry) {
            foreach ($entry['cells'] as $colIndex => $cellText) {
                $norm = $this->normalizeHeaderKey($cellText);
                if (in_array($norm, ['student number', 'student no', 'student id', 'id number'], true)) {
                    $headerOffset = $offset;
                    $studentNumberCol = (int) $colIndex;
                    break 2;
                }
            }
        }

        if ($studentNumberCol === null) {
            return [
                'summary' => [
                    'students_scanned' => 0,
                    'students_with_changes' => 0,
                    'matched_subject_columns' => 0,
                    'unmatched_subject_columns' => [],
                    'changes_total' => 0,
                    'upgrades_count' => 0,
                    'new_credits_count' => 0,
                    'unchanged_count' => 0,
                    'error_count' => 1,
                ],
                'changes' => [],
                'errors' => [[
                    'source_row' => 1,
                    'student_number' => '',
                    'subject_code' => '',
                    'raw_value' => '',
                    'message' => 'Could not find a "Student Number" column header. Download a fresh Cohort Grade Matrix template and try again.',
                ]],
            ];
        }

        $headerCells = $rawRows[$headerOffset]['cells'];

        // Build lookup of curriculum entries (term entries prioritized, then full curriculum fallback).
        $allCurriculumEntries = CurriculumEntry::query()
            ->with(['courseSpecification.course'])
            ->where('curriculum_version_id', $curriculum->id)
            ->orderBy('year_level')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->filter(fn (CurriculumEntry $entry): bool => $entry->courseSpecification instanceof CourseSpecification
                && $entry->courseSpecification->state !== CourseSpecification::StateDraft);

        /** @var array<string, CurriculumEntry> $entryByMatchCode */
        $entryByMatchCode = [];
        foreach ($allCurriculumEntries as $entry) {
            $courseCode = (string) ($entry->courseSpecification?->course?->code ?? '');
            if ($courseCode === '') {
                continue;
            }
            $matchKey = CurriculumSpreadsheetParser::normalizeMatchCode($courseCode);
            $isTargetPeriod = is_numeric($entry->year_level)
                && (int) $entry->year_level === $yearLevel
                && (string) $entry->term_type === $termType;

            if ($isTargetPeriod || ! isset($entryByMatchCode[$matchKey])) {
                $entryByMatchCode[$matchKey] = $entry;
            }
        }

        $metadataCols = ['student number', 'student no', 'student id', 'id number', 'student name', 'name', 'full name', 'status', 'standing', 'academic standing'];
        /** @var array<int, array{header: string, entry: CurriculumEntry, course_code: string, title: string}> $subjectColumns */
        $subjectColumns = [];
        $unmatchedSubjectColumns = [];

        foreach ($headerCells as $colIndex => $rawHeader) {
            $headerText = trim($rawHeader);
            if ($headerText === '' || (int) $colIndex === $studentNumberCol) {
                continue;
            }

            $normHeader = $this->normalizeHeaderKey($headerText);
            if (in_array($normHeader, $metadataCols, true)) {
                continue;
            }

            $extractedCode = $this->extractCourseCodeFromHeader($headerText);
            $matchKey = CurriculumSpreadsheetParser::normalizeMatchCode($extractedCode);
            $entry = $entryByMatchCode[$matchKey] ?? null;

            if ($entry instanceof CurriculumEntry) {
                $subjectColumns[(int) $colIndex] = [
                    'header' => $headerText,
                    'entry' => $entry,
                    'course_code' => (string) ($entry->courseSpecification?->course?->code ?? $extractedCode),
                    'title' => (string) ($entry->courseSpecification?->title ?? ''),
                ];
            } else {
                $unmatchedSubjectColumns[] = $headerText;
            }
        }

        // Load all non-archived StudentProfiles keyed by uppercase student number.
        /** @var array<string, StudentProfile> $profilesByNumber */
        $profilesByNumber = [];
        StudentProfile::query()
            ->active()
            ->get()
            ->each(function (StudentProfile $profile) use (&$profilesByNumber): void {
                $numKey = strtoupper(trim((string) $profile->student_number));
                if ($numKey !== '') {
                    $profilesByNumber[$numKey] = $profile;
                }
            });

        $matchedSpecIds = array_values(array_unique(array_map(
            fn (array $col): int => (int) $col['entry']->course_specification_id,
            $subjectColumns,
        )));

        $dataRows = array_slice($rawRows, $headerOffset + 1);
        $involvedProfileIds = [];
        foreach ($dataRows as $entry) {
            $numKey = strtoupper(trim((string) ($entry['cells'][$studentNumberCol] ?? '')));
            if ($numKey !== '' && isset($profilesByNumber[$numKey])) {
                $involvedProfileIds[] = (int) $profilesByNumber[$numKey]->id;
            }
        }
        $involvedProfileIds = array_values(array_unique($involvedProfileIds));

        /** @var array<int, array<int, PriorAcademicCredit>> $existingCredits */
        $existingCredits = [];
        if ($involvedProfileIds !== [] && $matchedSpecIds !== []) {
            PriorAcademicCredit::query()
                ->active()
                ->whereIn('student_profile_id', $involvedProfileIds)
                ->whereIn('course_specification_id', $matchedSpecIds)
                ->orderByDesc('id')
                ->get()
                ->each(function (PriorAcademicCredit $credit) use (&$existingCredits): void {
                    $sid = (int) $credit->student_profile_id;
                    $specId = (int) $credit->course_specification_id;
                    if (! isset($existingCredits[$sid][$specId])) {
                        $existingCredits[$sid][$specId] = $credit;
                    }
                });
        }

        $changes = [];
        $errors = [];
        $studentsScanned = 0;
        $studentsWithChanges = [];
        $upgradesCount = 0;
        $newCreditsCount = 0;
        $unchangedCount = 0;

        $resolvedAyLabel = filled($academicYearLabel) ? trim((string) $academicYearLabel) : 'Prior Institutional Record';
        $resolvedSourceSchool = filled($sourceSchool) ? trim((string) $sourceSchool) : null;
        $defaultTermLabel = $this->friendlyTermLabel($termType);

        foreach ($dataRows as $rowEntry) {
            $sourceRow = (int) $rowEntry['source_row'];
            $cells = $rowEntry['cells'];
            $studentNumber = trim((string) ($cells[$studentNumberCol] ?? ''));
            if ($studentNumber === '') {
                continue;
            }

            $studentsScanned++;
            $studentNumberKey = strtoupper($studentNumber);
            $profile = $profilesByNumber[$studentNumberKey] ?? null;

            if (! $profile instanceof StudentProfile) {
                $errors[] = [
                    'source_row' => $sourceRow,
                    'student_number' => $studentNumber,
                    'subject_code' => '',
                    'raw_value' => $studentNumber,
                    'message' => "Student number \"{$studentNumber}\" was not found in TALA Student Records.",
                ];

                continue;
            }

            $studentName = collect([$profile->first_name, $profile->middle_name, $profile->last_name])->filter()->implode(' ');

            foreach ($subjectColumns as $colIndex => $colMeta) {
                $rawCell = trim((string) ($cells[$colIndex] ?? ''));
                if ($rawCell === '') {
                    continue;
                }

                $parsedGrade = $this->parseMatrixCellToken($rawCell);
                if ($parsedGrade['error'] !== null) {
                    $errors[] = [
                        'source_row' => $sourceRow,
                        'student_number' => $studentNumber,
                        'student_name' => $studentName,
                        'subject_code' => $colMeta['course_code'],
                        'raw_value' => $rawCell,
                        'message' => $parsedGrade['error'],
                    ];

                    continue;
                }

                /** @var CurriculumEntry $entry */
                $entry = $colMeta['entry'];
                $specId = (int) $entry->course_specification_id;
                $existing = $existingCredits[(int) $profile->id][$specId] ?? null;

                $newGrade = $parsedGrade['final_grade'];
                $newStatus = $parsedGrade['result_status'];
                $isExternalTransfer = $parsedGrade['is_external_transfer'];

                if ($existing instanceof PriorAcademicCredit) {
                    $currentCode = $existing->effectiveResultCode();
                    if ($currentCode === $newGrade
                        && $existing->result_status === $newStatus
                        && $existing->isExternalTransfer() === $isExternalTransfer) {
                        $unchangedCount++;

                        continue;
                    }

                    $changeType = 'upgrade';
                    $upgradesCount++;
                } else {
                    $currentCode = null;
                    $changeType = 'create';
                    $newCreditsCount++;
                }

                $studentsWithChanges[(int) $profile->id] = true;

                $changes[] = [
                    'source_row' => $sourceRow,
                    'student_profile_id' => (int) $profile->id,
                    'student_number' => (string) $profile->student_number,
                    'student_name' => $studentName,
                    'existing_credit_id' => $existing?->id !== null ? (int) $existing->id : null,
                    'course_specification_id' => $specId,
                    'curriculum_entry_id' => (int) $entry->id,
                    'subject_code' => $colMeta['course_code'],
                    'subject_title' => $colMeta['title'],
                    'year_level' => is_numeric($entry->year_level) ? (int) $entry->year_level : $yearLevel,
                    'term_label' => filled($entry->term_label) ? (string) $entry->term_label : $defaultTermLabel,
                    'academic_year_label' => $existing?->academic_year_label ?: $resolvedAyLabel,
                    'source_school' => $isExternalTransfer
                        ? ($resolvedSourceSchool ?: ($existing?->source_school ?: 'External Transfer Institution'))
                        : ($resolvedSourceSchool ?: $existing?->source_school),
                    'current_grade' => $currentCode,
                    'final_grade' => $newGrade,
                    'result_status' => $newStatus,
                    'is_external_transfer' => $isExternalTransfer,
                    'change_type' => $changeType,
                ];
            }
        }

        return [
            'summary' => [
                'students_scanned' => $studentsScanned,
                'students_with_changes' => count($studentsWithChanges),
                'matched_subject_columns' => count($subjectColumns),
                'unmatched_subject_columns' => $unmatchedSubjectColumns,
                'changes_total' => count($changes),
                'upgrades_count' => $upgradesCount,
                'new_credits_count' => $newCreditsCount,
                'unchanged_count' => $unchangedCount,
                'error_count' => count($errors),
            ],
            'changes' => $changes,
            'errors' => $errors,
        ];
    }

    /**
     * Commit every validated Grade Matrix cell change via `RecordPriorAcademicCredits::upsertPriorCredit()`,
     * automatically refreshing CompletionReadinessProjection and superseding official TOR snapshots.
     *
     * @param  list<array<string, mixed>>  $changes
     * @return array{
     *     updated_cells: int,
     *     created_cells: int,
     *     students_affected: int,
     *     total_applied: int
     * }
     */
    public function commitMatrix(User $actor, array $changes): array
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only an authorized Registrar may commit prior grade matrix updates.');
        }

        if ($changes === []) {
            return [
                'updated_cells' => 0,
                'created_cells' => 0,
                'students_affected' => 0,
                'total_applied' => 0,
            ];
        }

        $profileIds = array_values(array_unique(array_map(
            fn (array $change): int => (int) ($change['student_profile_id'] ?? 0),
            $changes,
        )));

        $profiles = StudentProfile::query()
            ->whereIn('id', $profileIds)
            ->get()
            ->keyBy('id');

        $updatedCells = 0;
        $createdCells = 0;
        $studentsAffected = [];

        foreach ($changes as $change) {
            $profileId = (int) ($change['student_profile_id'] ?? 0);
            $profile = $profiles->get($profileId);
            if (! $profile instanceof StudentProfile) {
                continue;
            }

            $payload = [
                'id' => $change['existing_credit_id'] ?? null,
                'course_specification_id' => (int) $change['course_specification_id'],
                'curriculum_entry_id' => $change['curriculum_entry_id'] ?? null,
                'academic_year_label' => $change['academic_year_label'] ?? 'Prior Institutional Record',
                'term_label' => $change['term_label'] ?? null,
                'year_level' => $change['year_level'] ?? null,
                'final_grade' => (string) $change['final_grade'],
                'result_status' => (string) $change['result_status'],
                'is_external_transfer' => (bool) ($change['is_external_transfer'] ?? false),
                'source_school' => $change['source_school'] ?? null,
            ];

            $this->priorCredits->upsertPriorCredit($profile, $payload, $actor);

            $studentsAffected[$profileId] = true;
            if (($change['change_type'] ?? 'create') === 'upgrade' || filled($change['existing_credit_id'] ?? null)) {
                $updatedCells++;
            } else {
                $createdCells++;
            }
        }

        $totalApplied = $updatedCells + $createdCells;

        if ($totalApplied > 0) {
            activity('academic_records')
                ->causedBy($actor)
                ->event('cohort_grade_matrix_committed')
                ->withProperties([
                    'updated_cells' => $updatedCells,
                    'created_cells' => $createdCells,
                    'students_affected' => count($studentsAffected),
                ])
                ->log("Applied {$totalApplied} prior grade matrix cell(s) across ".count($studentsAffected).' student(s)');
        }

        return [
            'updated_cells' => $updatedCells,
            'created_cells' => $createdCells,
            'students_affected' => count($studentsAffected),
            'total_applied' => $totalApplied,
        ];
    }

    public function downloadAndDelete(string $path, ?string $downloadName = null): BinaryFileResponse
    {
        return app(CurriculumSpreadsheetExportService::class)->downloadAndDelete($path, $downloadName, 'xlsx');
    }

    /**
     * @return Collection<int, CurriculumEntry>
     */
    public function curriculumEntriesForTerm(int $curriculumVersionId, int $yearLevel, string $termType): Collection
    {
        return CurriculumEntry::query()
            ->with(['courseSpecification.course'])
            ->where('curriculum_version_id', $curriculumVersionId)
            ->where('year_level', (string) $yearLevel)
            ->where('term_type', $termType)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->filter(fn (CurriculumEntry $entry): bool => $entry->courseSpecification instanceof CourseSpecification
                && $entry->courseSpecification->state !== CourseSpecification::StateDraft)
            ->values();
    }

    /**
     * Parse a single Grade Matrix cell value (`1.00`-`5.00`, `INC`, `DRP`/`W`, `CREDIT`, or `TC`).
     *
     * @return array{final_grade: string, result_status: string, is_external_transfer: bool, error: ?string}
     */
    private function parseMatrixCellToken(string $raw): array
    {
        $clean = trim($raw);
        $upper = strtoupper($clean);

        if ($upper === 'TC') {
            return [
                'final_grade' => 'TC',
                'result_status' => PriorAcademicCredit::ResultCredited,
                'is_external_transfer' => true,
                'error' => null,
            ];
        }

        if (in_array($upper, ['CREDIT', 'CREDITED'], true)) {
            return [
                'final_grade' => 'CREDIT',
                'result_status' => PriorAcademicCredit::ResultCredited,
                'is_external_transfer' => false,
                'error' => null,
            ];
        }

        if ($upper === 'INC') {
            return [
                'final_grade' => 'INC',
                'result_status' => PriorAcademicCredit::ResultInc,
                'is_external_transfer' => false,
                'error' => null,
            ];
        }

        if (in_array($upper, ['DRP', 'W', 'DROPPED'], true)) {
            return [
                'final_grade' => 'DRP',
                'result_status' => PriorAcademicCredit::ResultDropped,
                'is_external_transfer' => false,
                'error' => null,
            ];
        }

        if (is_numeric($clean)) {
            $numeric = (float) $clean;
            if ($numeric < 1.00 || $numeric > 5.00) {
                return [
                    'final_grade' => '',
                    'result_status' => PriorAcademicCredit::ResultPassed,
                    'is_external_transfer' => false,
                    'error' => "Invalid grade \"{$raw}\": numeric grades must be between 1.00 and 5.00.",
                ];
            }

            $formatted = number_format($numeric, 2, '.', '');
            $status = $numeric <= 4.00
                ? PriorAcademicCredit::ResultPassed
                : PriorAcademicCredit::ResultFailed;

            return [
                'final_grade' => $formatted,
                'result_status' => $status,
                'is_external_transfer' => false,
                'error' => null,
            ];
        }

        return [
            'final_grade' => '',
            'result_status' => PriorAcademicCredit::ResultPassed,
            'is_external_transfer' => false,
            'error' => "Invalid grade value \"{$raw}\". Use 1.00–5.00, INC, DRP, CREDIT, or TC.",
        ];
    }

    /**
     * @param  Collection<int, CurriculumEntry>  $entries
     * @return list<list<float|int|string>>
     */
    private function buildLegendRows(
        Program $program,
        CurriculumVersion $curriculum,
        int $yearLevel,
        string $termType,
        Collection $entries,
    ): array {
        $rows = [
            ['Cohort Grade Matrix — Instructions & Accepted Values', '', ''],
            ['Program', self::neutralize("{$program->code} — {$program->name}"), ''],
            ['Curriculum Version', self::neutralize("{$curriculum->version_code} ({$curriculum->batchLabel()})"), ''],
            ['Period', "Year {$yearLevel} · ".$this->friendlyTermLabel($termType), $entries->count().' subject(s)'],
            ['', '', ''],
            ['Accepted Cell Value', 'Meaning & Transcript Treatment', 'SIA Cumulative GWA'],
            ['1.00 to 4.00', 'Passing Servitech pre-TALA numeric grade', 'Included in SIA GWA'],
            ['5.00', 'Failing Servitech pre-TALA numeric grade', 'Included in SIA GWA'],
            ['INC', 'Incomplete (can be settled later on the Student Profile)', 'Holds GWA until settled'],
            ['DRP', 'Dropped subject', 'Excluded from SIA GWA'],
            ['CREDIT', 'Servitech prior completion without numeric grade (satisfies prerequisites)', 'Excluded from SIA GWA'],
            ['TC', 'External Transfer Credit from another institution (satisfies prerequisites; shown as TC on TOR)', 'Excluded from SIA GWA'],
            ['(Blank)', 'Leave cell unchanged — blank cells will not delete existing prior grades', 'Unchanged'],
        ];

        return $rows;
    }

    /**
     * @return list<array{source_row: int, cells: list<string>}>
     */
    private function readMatrixSheetRows(string $path, ?string $extension = null): array
    {
        $reader = $this->createReader($path, $extension);
        $reader->open($path);

        try {
            $selectedSheet = null;
            $firstSheet = null;

            foreach ($reader->getSheetIterator() as $sheet) {
                /** @var SheetInterface $sheet */
                if ($firstSheet === null) {
                    $firstSheet = $sheet;
                }

                $normName = $this->normalizeHeaderKey($sheet->getName());
                if (in_array($normName, ['grade matrix', 'matrix', 'grades'], true)) {
                    $selectedSheet = $sheet;
                    break;
                }
            }

            $targetSheet = $selectedSheet ?? $firstSheet;
            if (! $targetSheet instanceof SheetInterface) {
                return [];
            }

            $rows = [];
            $counter = 0;

            foreach ($targetSheet->getRowIterator() as $rowIndex => $row) {
                $counter++;
                $cells = $this->rowToCells($row);
                if ($this->isRowEmpty($cells)) {
                    continue;
                }
                $rows[] = [
                    'source_row' => is_int($rowIndex) && $rowIndex > 0 ? $rowIndex : $counter,
                    'cells' => $cells,
                ];
            }

            return $rows;
        } finally {
            $reader->close();
        }
    }

    private function extractCourseCodeFromHeader(string $header): string
    {
        $clean = trim($header);
        foreach ([' — ', ' – ', ' - ', '—', '–'] as $separator) {
            if (str_contains($clean, $separator)) {
                [$left] = explode($separator, $clean, 2);
                $leftTrimmed = trim($left);
                if ($leftTrimmed !== '') {
                    return $leftTrimmed;
                }
            }
        }

        return $clean;
    }

    private function normalizeHeaderKey(string $text): string
    {
        $clean = strtolower(trim($text));
        $clean = preg_replace('/[^a-z0-9]+/', ' ', $clean) ?? $clean;

        return trim($clean);
    }

    private function termTypeOrder(string $termType): int
    {
        return match ($termType) {
            Term::TypeFirstSemester => 1,
            Term::TypeSecondSemester => 2,
            Term::TypeSpecialTerm => 3,
            default => 4,
        };
    }

    private function friendlyTermLabel(string $termType, ?string $rawLabel = null): string
    {
        return match ($termType) {
            Term::TypeFirstSemester => '1st Semester',
            Term::TypeSecondSemester => '2nd Semester',
            Term::TypeSpecialTerm => 'Summer / Special Term',
            default => filled($rawLabel) ? trim((string) $rawLabel) : 'Term',
        };
    }

    /**
     * @param  list<string>  $cells
     */
    private function isRowEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function rowToCells(Row $row): array
    {
        $numCells = $row->getNumCells();
        if ($numCells <= 0) {
            return [];
        }

        $cells = [];
        for ($col = 0; $col < $numCells; $col++) {
            $cells[] = $this->formatCellValue($row->getCellAtIndex($col));
        }

        while ($cells !== [] && trim((string) end($cells)) === '') {
            array_pop($cells);
        }

        return $cells;
    }

    private function formatCellValue(?Cell $cell): string
    {
        if (! $cell instanceof Cell || $cell instanceof Cell\EmptyCell) {
            return '';
        }

        if ($cell instanceof Cell\FormulaCell) {
            $computed = $cell->getComputedValue();
            if ($computed !== null && $computed !== '') {
                return $this->scalarToString($computed);
            }

            return trim((string) $cell->getValue());
        }

        return $this->scalarToString($cell->getValue());
    }

    private function scalarToString(null|bool|DateInterval|DateTimeInterface|float|int|string $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if ($value instanceof DateInterval) {
            return $value->format('%H:%I:%S');
        }

        return trim((string) $value);
    }

    private function createReader(string $path, ?string $extension = null): ReaderInterface
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("Spreadsheet file does not exist or is not readable: {$path}");
        }

        $resolvedExtension = strtolower(trim((string) ($extension ?: pathinfo($path, PATHINFO_EXTENSION))));

        if (! in_array($resolvedExtension, ['xlsx', 'csv'], true)) {
            $handle = @fopen($path, 'rb');
            $magic = is_resource($handle) ? (string) fread($handle, 4) : '';
            if (is_resource($handle)) {
                fclose($handle);
            }
            $resolvedExtension = str_starts_with($magic, "PK\x03\x04") ? 'xlsx' : 'csv';
        }

        return $resolvedExtension === 'xlsx' ? new XlsxReader : new CsvReader;
    }

    private static function neutralize(mixed $value): float|int|string
    {
        return CurriculumSpreadsheetExportService::neutralizeFormulaInjection($value);
    }

    private function prepareExportPath(string $filename): string
    {
        $exportDir = storage_path('app/private/exports/'.Str::ulid());
        File::ensureDirectoryExists($exportDir);

        return $exportDir.DIRECTORY_SEPARATOR.$filename;
    }
}
