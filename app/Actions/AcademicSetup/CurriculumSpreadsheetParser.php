<?php

namespace App\Actions\AcademicSetup;

use App\Models\CourseSpecification;
use App\Models\Program;
use App\Models\Term;
use DateInterval;
use DateTimeInterface;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\SheetInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

class CurriculumSpreadsheetParser
{
    public const FieldCode = 'code';

    public const FieldTitle = 'title';

    public const FieldCreditUnits = 'credit_units';

    public const FieldLecHours = 'lec_hours';

    public const FieldLabHours = 'lab_hours';

    public const FieldYearLevel = 'year_level';

    public const FieldTermType = 'term_type';

    public const FieldPrerequisites = 'prerequisites';

    public const FieldAcademicClassification = 'academic_classification';

    public const FieldSchedulingTreatment = 'scheduling_treatment';

    public const FieldDescription = 'description';

    /**
     * Canonical field labels for column mapping dropdowns.
     *
     * @return array<string, string>
     */
    public static function canonicalFieldLabels(bool $includePlacement = true): array
    {
        $fields = [
            self::FieldCode => 'Subject Code',
            self::FieldTitle => 'Subject Name',
            self::FieldCreditUnits => 'Units',
            self::FieldLecHours => 'Lec Hours/Week',
            self::FieldLabHours => 'Lab Hours/Week',
        ];

        if ($includePlacement) {
            $fields[self::FieldYearLevel] = 'Year Level';
            $fields[self::FieldTermType] = 'Semester';
        }

        return $fields + [
            self::FieldPrerequisites => 'Prerequisites',
            self::FieldAcademicClassification => 'Subject Type',
            self::FieldSchedulingTreatment => 'Schedule Type',
            self::FieldDescription => 'Description',
        ];
    }

    /**
     * Header aliases used to auto-detect the header row and suggest column mappings.
     *
     * @return array<string, list<string>>
     */
    public static function fieldAliases(): array
    {
        return [
            self::FieldCode => [
                'Subject Code',
                'Code',
                'Course Code',
                'Subj Code',
                'Subj. Code',
            ],
            self::FieldTitle => [
                'Subject Description',
                'Subject Name',
                'Subject Title',
                'Descriptive Title',
                'Course Title',
                'Course Name',
                'Title',
            ],
            self::FieldCreditUnits => [
                'Total No. of Units',
                'Total Units',
                'Units',
                'Credit Units',
                'Unit',
                'No. of Units',
            ],
            self::FieldLecHours => [
                'Lec Units/No. of Hours Per Week',
                'Lec Hours/Week',
                'Lec Units',
                'Lec Hours',
                'Lecture Hours',
                'Lec',
                'Lecture',
            ],
            self::FieldLabHours => [
                'Lab Units/No. of Hours Per Week',
                'Lab Hours/Week',
                'Lab Units',
                'Lab Hours',
                'Laboratory Hours',
                'Lab',
                'Laboratory',
            ],
            self::FieldYearLevel => [
                'Year Level',
                'Year',
                'Yr',
                'Yr Level',
            ],
            self::FieldTermType => [
                'Semester',
                'Term',
                'Academic Period',
                'Sem',
            ],
            self::FieldPrerequisites => [
                'Pre-Requisite',
                'Pre-requisite',
                'Prerequisite',
                'Prerequisites',
                'Pre-Requisites',
                'Prereq',
                'Pre-req',
            ],
            self::FieldAcademicClassification => [
                'Subject Type',
                'Classification',
                'Type',
                'Resultant NC',
                'Resultant',
            ],
            self::FieldSchedulingTreatment => [
                'Schedule Type',
                'Scheduling Treatment',
            ],
            self::FieldDescription => [
                'Description',
                'Course Description',
                'Notes',
            ],
        ];
    }

    /**
     * Inspect an uploaded `.xlsx` or `.csv` file and return its worksheet list and default sheet index.
     *
     * @return array{
     *     sheets: list<array{index: int, name: string, row_count: int}>,
     *     default_sheet_index: int
     * }
     */
    public function inspectWorkbook(string $path, ?string $extension = null): array
    {
        $reader = $this->createReader($path, $extension);
        $reader->open($path);

        try {
            $sheets = [];
            $defaultSheetIndex = 0;
            $foundNonEmpty = false;
            $fallbackIndex = 0;

            foreach ($reader->getSheetIterator() as $sheet) {
                /** @var SheetInterface $sheet */
                $index = $sheet->getIndex();
                $name = trim($sheet->getName());

                if ($name === '') {
                    $name = 'Sheet '.($index + 1);
                }

                $rowCount = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $cells = $this->rowToCells($row);

                    if (! $this->isRowEmpty($cells)) {
                        $rowCount++;
                    }
                }

                $sheets[] = [
                    'index' => $index,
                    'name' => $name,
                    'row_count' => $rowCount,
                ];

                if (! $foundNonEmpty && $rowCount > 0) {
                    $defaultSheetIndex = $index;
                    $foundNonEmpty = true;
                }

                if ($fallbackIndex === 0) {
                    $fallbackIndex = $index;
                }
            }

            if (! $foundNonEmpty && $sheets !== []) {
                $defaultSheetIndex = $sheets[0]['index'];
            }

            return [
                'sheets' => $sheets,
                'default_sheet_index' => $defaultSheetIndex,
            ];
        } finally {
            $reader->close();
        }
    }

    /**
     * Extract header columns, up to 3 sample values per header, auto-suggested column mapping,
     * and whether section banners (e.g. `First Year - First Semester`) were detected.
     *
     * @return array{
     *     sheet_index: int,
     *     sheet_name: string,
     *     header_row_index: int,
     *     headers: list<array{index: int, name: string, label: string, samples: list<string>, sample_values: list<string>}>,
     *     header_options: array<int, string>,
     *     sample_rows: array<int, list<string>>,
     *     sample_data_rows: list<array<int, string>>,
     *     suggested_mapping: array<string, int|null>,
     *     detected_banners: bool
     * }
     */
    public function extractSheet(string $path, int $sheetIndex = 0, ?string $extension = null): array
    {
        $sheetData = $this->readSheetRows($path, $sheetIndex, $extension);
        $rawRows = $sheetData['rows'];
        $detectedBanners = false;

        foreach ($rawRows as $entry) {
            if ($this->detectSectionBanner($entry['cells']) !== null) {
                $detectedBanners = true;

                break;
            }
        }

        $headerMeta = $this->locatePrimaryHeaderRow($rawRows);
        $headerRowOffset = $headerMeta['offset'];
        $headerRowNumber = $headerMeta['source_row'];
        $headerCells = $headerMeta['cells'];

        $headers = [];
        $headerIndices = [];

        foreach ($headerCells as $colIndex => $rawHeader) {
            $cleanHeader = $this->cleanHeaderText($rawHeader);

            if ($cleanHeader === '') {
                continue;
            }

            $headers[] = [
                'index' => $colIndex,
                'name' => $cleanHeader,
                'label' => $cleanHeader,
                'samples' => [],
                'sample_values' => [],
            ];
            $headerIndices[] = $colIndex;
        }

        $suggestedMapping = $this->guessColumnMapping($headers);

        /** @var array<int, list<string>> $samplesByCol */
        $samplesByCol = array_fill_keys($headerIndices, []);
        /** @var list<array<int, string>> $sampleDataRows */
        $sampleDataRows = [];
        $inAssessmentSection = false;

        foreach ($rawRows as $offset => $entry) {
            if ($offset <= $headerRowOffset) {
                continue;
            }

            $cells = $entry['cells'];

            if ($this->isRowEmpty($cells)) {
                continue;
            }

            if ($this->detectSectionBanner($cells) !== null) {
                $inAssessmentSection = false;

                continue;
            }

            if ($this->isRepeatedHeaderRow($cells, $suggestedMapping)) {
                $inAssessmentSection = false;

                continue;
            }

            if ($this->startsAssessmentOrFooterSection($cells)) {
                $inAssessmentSection = true;

                continue;
            }

            if ($inAssessmentSection || $this->shouldSkipNonSubjectRow($cells, $suggestedMapping)) {
                continue;
            }

            $rowSlice = [];

            foreach ($headerIndices as $colIndex) {
                $val = trim((string) ($cells[$colIndex] ?? ''));
                $rowSlice[$colIndex] = $val;

                if ($val !== '' && count($samplesByCol[$colIndex]) < 3) {
                    $samplesByCol[$colIndex][] = $val;
                }
            }

            if (count($sampleDataRows) < 3) {
                $sampleDataRows[] = $rowSlice;
            }
        }

        $headerOptions = [];

        foreach ($headers as $idx => $header) {
            $colIndex = $header['index'];
            $samples = $samplesByCol[$colIndex] ?? [];
            $headers[$idx]['samples'] = $samples;
            $headers[$idx]['sample_values'] = $samples;

            $sampleSuffix = $samples !== []
                ? ' (e.g. '.implode(', ', array_slice($samples, 0, 2)).')'
                : '';
            $headerOptions[$colIndex] = $header['name'].$sampleSuffix;
        }

        return [
            'sheet_index' => $sheetData['sheet_index'],
            'sheet_name' => $sheetData['sheet_name'],
            'header_row_index' => $headerRowNumber,
            'headers' => $headers,
            'header_options' => $headerOptions,
            'sample_rows' => $samplesByCol,
            'sample_data_rows' => $sampleDataRows,
            'suggested_mapping' => $suggestedMapping,
            'detected_banners' => $detectedBanners,
        ];
    }

    /**
     * Parse subject rows from the selected worksheet using the given column map.
     *
     * @param  array<string, int|string|null>  $columnMap
     * @return list<array{
     *     row_key: string,
     *     source_row: int,
     *     code: string,
     *     normalized_code: string,
     *     title: string,
     *     credit_units: float|null,
     *     units_inferred: bool,
     *     lec_hours: float,
     *     lab_hours: float,
     *     practicum_hours: float|null,
     *     year_level: int|null,
     *     term_type: string|null,
     *     raw_prerequisites: list<string>,
     *     prerequisites_text: string,
     *     academic_classification: string,
     *     scheduling_treatment: string,
     *     description: string|null,
     *     tesda_qualification_note: string|null,
     *     parser_warnings: list<string>
     * }>
     */
    public function parseRows(string $path, int $sheetIndex = 0, array $columnMap = [], ?string $extension = null): array
    {
        $sheetData = $this->readSheetRows($path, $sheetIndex, $extension);
        $rawRows = $sheetData['rows'];
        $headerMeta = $this->locatePrimaryHeaderRow($rawRows);
        $headerRowOffset = $headerMeta['offset'];

        if ($columnMap === []) {
            $extracted = $this->extractSheet($path, $sheetIndex, $extension);
            $normalizedMap = $extracted['suggested_mapping'];
        } else {
            $normalizedMap = $this->normalizeColumnMap($columnMap);
        }

        $currentYearLevel = null;
        $currentTermType = null;
        $inAssessmentSection = false;
        $parsedRows = [];
        /** @var list<int> $currentSectionRowIndices */
        $currentSectionRowIndices = [];

        foreach ($rawRows as $offset => $entry) {
            $cells = $entry['cells'];
            $sourceRow = $entry['source_row'];

            if ($this->isRowEmpty($cells)) {
                continue;
            }

            $banner = $this->detectSectionBanner($cells);

            if ($banner !== null) {
                $this->finalizeSectionSummerPracticumUnits($parsedRows, $currentSectionRowIndices, null);
                $currentSectionRowIndices = [];
                $currentYearLevel = $banner['year_level'];
                $currentTermType = $banner['term_type'];
                $inAssessmentSection = false;

                continue;
            }

            if ($offset <= $headerRowOffset) {
                continue;
            }

            if ($this->isRepeatedHeaderRow($cells, $normalizedMap)) {
                $inAssessmentSection = false;

                continue;
            }

            if ($this->startsAssessmentOrFooterSection($cells)) {
                $this->finalizeSectionSummerPracticumUnits($parsedRows, $currentSectionRowIndices, null);
                $currentSectionRowIndices = [];
                $inAssessmentSection = true;

                continue;
            }

            if ($this->isTotalUnitsRow($cells, $normalizedMap)) {
                $footerTotalUnits = $this->extractTotalUnitsFromFooterRow($cells, $normalizedMap);
                $this->finalizeSectionSummerPracticumUnits($parsedRows, $currentSectionRowIndices, $footerTotalUnits);
                $currentSectionRowIndices = [];

                continue;
            }

            if ($inAssessmentSection || $this->shouldSkipNonSubjectRow($cells, $normalizedMap)) {
                continue;
            }

            $rawCode = $this->cellAt($cells, $normalizedMap[self::FieldCode] ?? null);
            $rawTitle = $this->cellAt($cells, $normalizedMap[self::FieldTitle] ?? null);

            if ($rawCode === '' && $rawTitle === '') {
                continue;
            }

            $code = self::normalizeDisplayCode($rawCode);
            $normalizedCode = self::normalizeMatchCode($rawCode);
            $title = $this->cleanSingleLineText($rawTitle);

            $rawUnits = $this->cellAt($cells, $normalizedMap[self::FieldCreditUnits] ?? null);
            $rawLec = $this->cellAt($cells, $normalizedMap[self::FieldLecHours] ?? null);
            $rawLab = $this->cellAt($cells, $normalizedMap[self::FieldLabHours] ?? null);
            $rawYear = $this->cellAt($cells, $normalizedMap[self::FieldYearLevel] ?? null);
            $rawTerm = $this->cellAt($cells, $normalizedMap[self::FieldTermType] ?? null);
            $rawPrereq = $this->cellAt($cells, $normalizedMap[self::FieldPrerequisites] ?? null);
            $rawClassification = $this->cellAt($cells, $normalizedMap[self::FieldAcademicClassification] ?? null);
            $rawScheduling = $this->cellAt($cells, $normalizedMap[self::FieldSchedulingTreatment] ?? null);
            $rawDescription = $this->cellAt($cells, $normalizedMap[self::FieldDescription] ?? null);

            $warnings = [];
            $practicumHours = null;

            $lecResult = $this->parseHoursCell($rawLec);
            $labResult = $this->parseHoursCell($rawLab);

            $lecHours = $lecResult['hours'];
            $labHours = $labResult['hours'];

            if ($labResult['practicum_hours'] !== null) {
                $practicumHours = $labResult['practicum_hours'];
            } elseif ($lecResult['practicum_hours'] !== null) {
                $practicumHours = $lecResult['practicum_hours'];
            } elseif (preg_match('/\b(\d{2,4})\s*(?:hours?|hrs?)\b/i', $title, $titleHoursMatch) === 1 && (float) $titleHoursMatch[1] >= 40.0) {
                $practicumHours = (float) $titleHoursMatch[1];
            }

            $schedulingTreatment = $this->resolveSchedulingTreatment(
                rawScheduling: $rawScheduling,
                code: $code,
                title: $title,
                practicumHours: $practicumHours,
            );

            if ($schedulingTreatment === CourseSpecification::SchedulingExternallyArranged) {
                $lecHours = 0.0;
                $labHours = 0.0;
            }

            $classificationResult = $this->resolveAcademicClassification(
                rawClassification: $rawClassification,
                code: $code,
                title: $title,
            );
            $academicClassification = $classificationResult['classification'];
            $tesdaQualificationNote = $classificationResult['tesda_note'];

            $creditUnits = $this->parseNumericUnits($rawUnits);
            $unitsInferred = false;

            if ($creditUnits === null) {
                if ($schedulingTreatment !== CourseSpecification::SchedulingExternallyArranged && $lecHours > 0.0 && $labHours <= 0.0) {
                    $creditUnits = $lecHours;
                    $unitsInferred = true;
                    $warnings[] = 'Units inferred from lecture hours — verify before importing.';
                }
            }

            $yearLevel = $rawYear !== '' ? self::parseYearLevel($rawYear) : $currentYearLevel;
            $termType = $rawTerm !== '' ? self::parseTermType($rawTerm) : $currentTermType;

            $rawPrerequisites = self::parsePrerequisitesCell($rawPrereq);
            $description = $rawDescription !== '' ? trim($rawDescription) : null;

            $newIndex = count($parsedRows);
            $parsedRows[] = [
                'row_key' => 'row_'.$sourceRow,
                'source_row' => $sourceRow,
                'code' => $code,
                'normalized_code' => $normalizedCode,
                'title' => $title,
                'credit_units' => $creditUnits,
                'units_inferred' => $unitsInferred,
                'lec_hours' => $lecHours,
                'lab_hours' => $labHours,
                'practicum_hours' => $practicumHours,
                'year_level' => $yearLevel,
                'term_type' => $termType,
                'raw_prerequisites' => $rawPrerequisites,
                'prerequisites_text' => implode(', ', $rawPrerequisites),
                'academic_classification' => $academicClassification,
                'scheduling_treatment' => $schedulingTreatment,
                'description' => $description,
                'tesda_qualification_note' => $tesdaQualificationNote,
                'parser_warnings' => $warnings,
                '_raw_plain_lab_units' => $this->parsePlainSmallUnitNumber($rawLab),
            ];
            $currentSectionRowIndices[] = $newIndex;
        }

        $this->finalizeSectionSummerPracticumUnits($parsedRows, $currentSectionRowIndices, null);

        foreach ($parsedRows as $idx => $parsedRow) {
            unset($parsedRows[$idx]['_raw_plain_lab_units']);
        }

        return $parsedRows;
    }

    /**
     * Suggest the best matching worksheet tab for a target Program (matching program codes and common Diploma/BS aliases).
     *
     * @param  array<int|string, array{index?: int, name?: string, row_count?: int}|string>  $sheets
     * @return array{
     *     sheet_index: int,
     *     matched: bool,
     *     mismatch_warning: ?string
     * }
     */
    public static function suggestSheetForProgram(array $sheets, ?Program $program): array
    {
        $normalizedSheets = [];
        $defaultIndex = 0;
        $defaultName = '';
        $foundNonEmpty = false;

        foreach ($sheets as $key => $sheet) {
            if (is_array($sheet)) {
                $idx = isset($sheet['index']) ? (int) $sheet['index'] : (int) $key;
                $name = trim((string) ($sheet['name'] ?? ('Sheet '.($idx + 1))));
                $rowCount = isset($sheet['row_count']) ? (int) $sheet['row_count'] : 1;
            } else {
                $idx = (int) $key;
                $name = trim((string) $sheet);
                $rowCount = 1;
            }

            $normalizedSheets[] = [
                'index' => $idx,
                'name' => $name,
                'row_count' => $rowCount,
            ];

            if (! $foundNonEmpty && $rowCount > 0) {
                $defaultIndex = $idx;
                $defaultName = $name;
                $foundNonEmpty = true;
            }
        }

        if (! $foundNonEmpty && $normalizedSheets !== []) {
            $defaultIndex = $normalizedSheets[0]['index'];
            $defaultName = $normalizedSheets[0]['name'];
        }

        if (! $program instanceof Program || $normalizedSheets === []) {
            return [
                'sheet_index' => $defaultIndex,
                'matched' => false,
                'mismatch_warning' => null,
            ];
        }

        $bestIndex = $defaultIndex;
        $bestScore = 0;

        foreach ($normalizedSheets as $sheet) {
            $score = self::scoreSheetAgainstProgram($sheet['name'], $program);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = $sheet['index'];
            }
        }

        if ($bestScore > 0) {
            return [
                'sheet_index' => $bestIndex,
                'matched' => true,
                'mismatch_warning' => null,
            ];
        }

        return [
            'sheet_index' => $defaultIndex,
            'matched' => false,
            'mismatch_warning' => self::sheetMismatchWarning($defaultName, $program),
        ];
    }

    /**
     * Return a calm Registrar warning when the selected worksheet tab looks like a different program than the target curriculum's program.
     */
    public static function sheetMismatchWarning(string $sheetName, ?Program $program): ?string
    {
        $trimmedSheet = trim($sheetName);
        $programCode = strtoupper(trim((string) ($program?->code ?? '')));

        if ($trimmedSheet === '' || ! $program instanceof Program || $programCode === '') {
            return null;
        }

        if (self::isGenericWorksheetName($trimmedSheet)) {
            return null;
        }

        if (self::scoreSheetAgainstProgram($trimmedSheet, $program) > 0) {
            return null;
        }

        $sheetFamily = self::resolveProgramFamily($trimmedSheet, $trimmedSheet);
        $looksLikeProgramTab = $sheetFamily !== null
            || preg_match('/^(?:BS|B\.S\.|D|BA|AB|ACT)[A-Z]{1,6}(?:[\s_-].*)?$/i', $trimmedSheet) === 1;

        if (! $looksLikeProgramTab) {
            return null;
        }

        return "Worksheet '{$trimmedSheet}' looks like a different program than {$programCode}. Double-check you selected the right tab.";
    }

    /**
     * Normalize a subject code for display (uppercase, collapse multiple internal spaces, preserve single space).
     */
    public static function normalizeDisplayCode(string $code): string
    {
        $cleaned = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $code) ?? $code;
        $cleaned = preg_replace('/\s+/', ' ', trim($cleaned)) ?? trim($cleaned);

        return strtoupper($cleaned);
    }

    /**
     * Normalize a subject code for catalog and prerequisite matching (uppercase, all whitespace stripped).
     */
    public static function normalizeMatchCode(string $code): string
    {
        $cleaned = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $code) ?? $code;

        return (string) preg_replace('/\s+/', '', strtoupper(trim($cleaned)));
    }

    /**
     * Parse a Year Level value from a spreadsheet cell or banner token.
     */
    public static function parseYearLevel(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 1 && $value <= 8 ? $value : null;
        }

        $text = strtolower(trim((string) $value));

        if ($text === '') {
            return null;
        }

        if (ctype_digit($text)) {
            $num = (int) $text;

            return $num >= 1 && $num <= 8 ? $num : null;
        }

        $patterns = [
            1 => '/\b(?:first|1st)\b|\b(?:year|yr)\s*(?:1|i|one)\b|^(?:1|i)$/',
            2 => '/\b(?:second|2nd)\b|\b(?:year|yr)\s*(?:2|ii|two)\b|^(?:2|ii)$/',
            3 => '/\b(?:third|3rd)\b|\b(?:year|yr)\s*(?:3|iii|three)\b|^(?:3|iii)$/',
            4 => '/\b(?:fourth|4th)\b|\b(?:year|yr)\s*(?:4|iv|four)\b|^(?:4|iv)$/',
            5 => '/\b(?:fifth|5th)\b|\b(?:year|yr)\s*(?:5|v|five)\b|^(?:5|v)$/',
            6 => '/\b(?:sixth|6th)\b|\b(?:year|yr)\s*(?:6|vi|six)\b|^(?:6|vi)$/',
        ];

        foreach ($patterns as $year => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $year;
            }
        }

        return null;
    }

    /**
     * Parse a Semester / Term Type value into a canonical Term::Type* constant.
     */
    public static function parseTermType(mixed $value): ?string
    {
        $text = strtoupper(trim((string) $value));

        if ($text === '') {
            return null;
        }

        if ($text === Term::TypeFirstSemester || $text === '1') {
            return Term::TypeFirstSemester;
        }

        if ($text === Term::TypeSecondSemester || $text === '2') {
            return Term::TypeSecondSemester;
        }

        if ($text === Term::TypeSpecialTerm || $text === 'SPECIAL_TERM' || $text === '3') {
            return Term::TypeSpecialTerm;
        }

        if (preg_match('/\b(?:FIRST|1ST)\s*(?:SEMESTER|SEM|TERM)\b|\b(?:SEMESTER|SEM)\s*(?:1|I)\b/i', $text) === 1) {
            return Term::TypeFirstSemester;
        }

        if (preg_match('/\b(?:SECOND|2ND)\s*(?:SEMESTER|SEM|TERM)\b|\b(?:SEMESTER|SEM)\s*(?:2|II)\b/i', $text) === 1) {
            return Term::TypeSecondSemester;
        }

        if (preg_match('/\b(?:SUMMER(?:\s+TERM|\s+SEMESTER)?|SPECIAL\s+TERM|MIDYEAR(?:\s+TERM)?)\b/i', $text) === 1) {
            return Term::TypeSpecialTerm;
        }

        return null;
    }

    /**
     * Split a Prerequisite cell into individual raw prerequisite tokens, ignoring "None" / "N/A" / "-".
     *
     * @return list<string>
     */
    public static function parsePrerequisitesCell(string $raw): array
    {
        $trimmed = trim($raw);

        if ($trimmed === '' || preg_match('/^(?:none|n\/a|na|nil|-+|\.+)$/i', $trimmed) === 1) {
            return [];
        }

        $parts = preg_split('/\s*(?:,|;|\/|&|\band\b)\s*/i', $trimmed) ?: [$trimmed];
        $tokens = [];

        foreach ($parts as $part) {
            $clean = trim((string) (preg_replace('/\s+/', ' ', $part) ?? $part));

            if ($clean === '' || preg_match('/^(?:none|n\/a|na|nil|-+|\.+)$/i', $clean) === 1) {
                continue;
            }

            $tokens[] = $clean;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Detect whether a row is a Year/Semester section banner such as:
     * `First Year - First Semester`, `2nd Year - SUMMER TERM`, `Third Year-Second Semester`, `1st Year - 2nd Sem`, `Year 1 - Summer`.
     *
     * @param  list<string>  $cells
     * @return array{year_level: int, term_type: string, label: string}|null
     */
    public function detectSectionBanner(array $cells): ?array
    {
        $nonEmpty = [];

        foreach ($cells as $cell) {
            $trimmed = trim($cell);

            if ($trimmed !== '') {
                $nonEmpty[] = $trimmed;
            }
        }

        if ($nonEmpty === [] || count($nonEmpty) > 2) {
            return null;
        }

        $combined = implode(' - ', $nonEmpty);

        $yearRegex = '/\b(?:(first|1st|second|2nd|third|3rd|fourth|4th|fifth|5th)\s+year|year\s*(1|2|3|4|5|i|ii|iii|iv|v))\b/i';
        $termRegex = '/\b(?:(first|1st|second|2nd)\s+(?:semester|sem|term)|(?:semester|sem)\s*(1|2|i|ii)|(summer(?:\s+term|\s+semester)?|special\s+term|midyear(?:\s+term)?))\b/i';

        if (preg_match($yearRegex, $combined, $yearMatch) !== 1 || preg_match($termRegex, $combined, $termMatch) !== 1) {
            return null;
        }

        $remainder = preg_replace($yearRegex, '', $combined) ?? $combined;
        $remainder = preg_replace($termRegex, '', $remainder) ?? $remainder;
        $remainder = preg_replace('/\b(?:A\.?Y\.?|S\.?Y\.?)\s*\d{4}\s*[-–]\s*\d{2,4}\b/i', '', $remainder) ?? $remainder;
        $remainder = trim((string) preg_replace('/[\s\-–—·:|\/(),.]+/', '', $remainder));

        if ($remainder !== '') {
            return null;
        }

        $yearLevel = self::parseYearLevel($yearMatch[0]);
        $termType = self::parseTermType($termMatch[0]);

        if ($yearLevel === null || $termType === null) {
            return null;
        }

        return [
            'year_level' => $yearLevel,
            'term_type' => $termType,
            'label' => $combined,
        ];
    }

    /**
     * Read all rows of the requested worksheet index into memory, stripping trailing empty cells.
     *
     * @return array{
     *     sheet_index: int,
     *     sheet_name: string,
     *     rows: list<array{source_row: int, cells: list<string>}>
     * }
     */
    private function readSheetRows(string $path, int $sheetIndex, ?string $extension = null): array
    {
        $reader = $this->createReader($path, $extension);
        $reader->open($path);

        try {
            $targetSheet = null;
            $firstSheet = null;

            foreach ($reader->getSheetIterator() as $sheet) {
                /** @var SheetInterface $sheet */
                if ($firstSheet === null) {
                    $firstSheet = $sheet;
                }

                if ($sheet->getIndex() === $sheetIndex) {
                    $targetSheet = $sheet;

                    break;
                }
            }

            $selectedSheet = $targetSheet ?? $firstSheet;

            if (! $selectedSheet instanceof SheetInterface) {
                return [
                    'sheet_index' => 0,
                    'sheet_name' => 'Sheet 1',
                    'rows' => [],
                ];
            }

            $rows = [];
            $rowCounter = 0;

            foreach ($selectedSheet->getRowIterator() as $rowIndex => $row) {
                $rowCounter++;
                $sourceRow = is_int($rowIndex) && $rowIndex > 0 ? $rowIndex : $rowCounter;
                $cells = $this->rowToCells($row);

                $rows[] = [
                    'source_row' => $sourceRow,
                    'cells' => $cells,
                ];
            }

            $sheetName = trim($selectedSheet->getName());

            return [
                'sheet_index' => $selectedSheet->getIndex(),
                'sheet_name' => $sheetName !== '' ? $sheetName : 'Sheet '.($selectedSheet->getIndex() + 1),
                'rows' => $rows,
            ];
        } finally {
            $reader->close();
        }
    }

    /**
     * Locate the primary column header row in the sheet.
     *
     * @param  list<array{source_row: int, cells: list<string>}>  $rawRows
     * @return array{offset: int, source_row: int, cells: list<string>}
     */
    private function locatePrimaryHeaderRow(array $rawRows): array
    {
        $bestOffset = 0;
        $bestScore = -1;
        $firstMultiColumnOffset = null;

        foreach ($rawRows as $offset => $entry) {
            $cells = $entry['cells'];

            if ($this->isRowEmpty($cells) || $this->detectSectionBanner($cells) !== null) {
                continue;
            }

            $nonEmptyCount = count(array_filter($cells, fn (string $c): bool => trim($c) !== ''));

            if ($nonEmptyCount >= 2 && $firstMultiColumnOffset === null && ! $this->isSchoolOrTitleBannerRow($cells)) {
                $firstMultiColumnOffset = $offset;
            }

            $score = $this->scoreHeaderCandidate($cells);

            if ($score > $bestScore && $score >= 2) {
                $bestScore = $score;
                $bestOffset = $offset;

                if ($score >= 3) {
                    break;
                }
            }
        }

        if ($bestScore < 2 && $firstMultiColumnOffset !== null) {
            $bestOffset = $firstMultiColumnOffset;
        }

        if (! isset($rawRows[$bestOffset])) {
            return [
                'offset' => 0,
                'source_row' => 1,
                'cells' => [],
            ];
        }

        return [
            'offset' => $bestOffset,
            'source_row' => $rawRows[$bestOffset]['source_row'],
            'cells' => $rawRows[$bestOffset]['cells'],
        ];
    }

    /**
     * Score how many distinct canonical fields a row's cells match as column headers.
     *
     * @param  list<string>  $cells
     */
    private function scoreHeaderCandidate(array $cells): int
    {
        $headers = [];

        foreach ($cells as $colIndex => $cell) {
            $clean = $this->cleanHeaderText($cell);

            if ($clean !== '') {
                $headers[] = [
                    'index' => $colIndex,
                    'name' => $clean,
                ];
            }
        }

        if (count($headers) < 2) {
            return 0;
        }

        $mapping = $this->guessColumnMapping($headers);
        $matchedFields = array_filter($mapping, fn (?int $idx): bool => $idx !== null);

        if (isset($matchedFields[self::FieldCode]) && count($matchedFields) >= 2) {
            return count($matchedFields) + 1;
        }

        return count($matchedFields);
    }

    /**
     * Auto-guess canonical field => column index mapping from extracted headers.
     *
     * @param  list<array{index: int, name: string, label?: string, samples?: list<string>, sample_values?: list<string>}>  $headers
     * @return array<string, int|null>
     */
    private function guessColumnMapping(array $headers): array
    {
        $aliasesByField = self::fieldAliases();
        $mapping = array_fill_keys(array_keys($aliasesByField), null);
        $claimedColumns = [];

        $normalizedHeaders = [];

        foreach ($headers as $header) {
            $colIndex = $header['index'];
            $normalizedHeaders[$colIndex] = $this->normalizeAliasKey($header['name']);
        }

        foreach ($aliasesByField as $field => $aliases) {
            $normalizedAliases = array_map(fn (string $alias): string => $this->normalizeAliasKey($alias), $aliases);

            // Pass 1: exact normalized alias match
            foreach ($normalizedAliases as $targetAlias) {
                foreach ($normalizedHeaders as $colIndex => $headerNorm) {
                    if (isset($claimedColumns[$colIndex])) {
                        continue;
                    }

                    if ($field === self::FieldDescription && $headerNorm === $this->normalizeAliasKey('Subject Description')) {
                        continue;
                    }

                    if ($headerNorm === $targetAlias) {
                        $mapping[$field] = $colIndex;
                        $claimedColumns[$colIndex] = true;

                        break 2;
                    }
                }
            }
        }

        return $mapping;
    }

    private function normalizeAliasKey(string $text): string
    {
        $cleaned = strtolower($this->cleanHeaderText($text));
        $cleaned = rtrim($cleaned, ' *');
        $cleaned = str_replace(['pre-requisite', 'pre-req'], ['prerequisite', 'prereq'], $cleaned);
        $cleaned = preg_replace('/[^a-z0-9]+/', ' ', $cleaned) ?? $cleaned;

        return trim($cleaned);
    }

    private function cleanHeaderText(string $text): string
    {
        $cleaned = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text) ?? $text;
        $cleaned = preg_replace('/\s+/', ' ', trim($cleaned)) ?? trim($cleaned);

        return trim($cleaned, " \t\n\r\0\x0B*");
    }

    private function cleanSingleLineText(string $text): string
    {
        $cleaned = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text) ?? $text;

        return (string) (preg_replace('/\s+/', ' ', trim($cleaned)) ?? trim($cleaned));
    }

    /**
     * @param  array<string, int|string|null>  $columnMap
     * @return array<string, int|null>
     */
    private function normalizeColumnMap(array $columnMap): array
    {
        $normalized = array_fill_keys(array_keys(self::fieldAliases()), null);

        foreach ($columnMap as $field => $colIndex) {
            if (! array_key_exists($field, $normalized)) {
                continue;
            }

            if ($colIndex === null || $colIndex === '' || $colIndex === 'ignore' || $colIndex === 'none') {
                $normalized[$field] = null;

                continue;
            }

            if (is_int($colIndex) && $colIndex >= 0) {
                $normalized[$field] = $colIndex;
            } elseif (is_string($colIndex) && ctype_digit($colIndex)) {
                $normalized[$field] = (int) $colIndex;
            }
        }

        return $normalized;
    }

    /**
     * @param  list<string>  $cells
     * @param  array<string, int|null>  $columnMap
     */
    private function isRepeatedHeaderRow(array $cells, array $columnMap): bool
    {
        $codeCol = $columnMap[self::FieldCode] ?? 0;
        $codeVal = $this->normalizeAliasKey($cells[$codeCol] ?? '');

        if (in_array($codeVal, ['subject code', 'code', 'course code', 'subj code'], true)) {
            return true;
        }

        return $this->scoreHeaderCandidate($cells) >= 3;
    }

    /**
     * @param  list<string>  $cells
     */
    private function isSchoolOrTitleBannerRow(array $cells): bool
    {
        $nonEmpty = array_values(array_filter(array_map('trim', $cells), fn (string $c): bool => $c !== ''));

        if ($nonEmpty === []) {
            return true;
        }

        $joined = strtoupper(implode(' ', $nonEmpty));

        return preg_match('/\b(?:SERVITECH|PROGRAM\s+CURRICULUM|CURRICULUM\s+CHECKLIST|DIPLOMA\s+IN|BACHELOR\s+OF|EFFECTIVE\s+A\.?Y\.?|EFFECTIVE\s+S\.?Y\.?)\b/i', $joined) === 1;
    }

    /**
     * @param  list<string>  $cells
     */
    private function startsAssessmentOrFooterSection(array $cells): bool
    {
        foreach ($cells as $cell) {
            $trimmed = trim($cell);

            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/^(?:ASSESSMENT\s+EVALUATION|INSTITUTIONAL\s+ASSESSMENT|TESDA\s+ASSESSMENT\s+EVALUATION)\b/i', $trimmed) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect whether a row is a term summary `TOTAL UNITS` / `SUB-TOTAL` row.
     *
     * @param  list<string>  $cells
     * @param  array<string, int|null>  $columnMap
     */
    private function isTotalUnitsRow(array $cells, array $columnMap): bool
    {
        foreach ($cells as $cell) {
            $trimmed = trim($cell);

            if ($trimmed !== '' && preg_match('/\b(?:TOTAL\s+UNITS|TOTAL\s+NO\.?\s+OF\s+UNITS|SUB-?TOTAL\s+UNITS)\b/i', $trimmed) === 1) {
                return true;
            }
        }

        $col0 = trim($cells[0] ?? '');
        $col1 = trim($cells[1] ?? '');
        $codeCell = $this->cellAt($cells, $columnMap[self::FieldCode] ?? 0);
        $titleCell = $this->cellAt($cells, $columnMap[self::FieldTitle] ?? 1);

        foreach ([$col0, $col1, $codeCell, $titleCell] as $candidate) {
            if ($candidate !== '' && preg_match('/^(?:TOTAL|TOTAL\s+UNITS|SUB-?TOTAL)$/i', $candidate) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract a single numeric unit total (1..12) from a `TOTAL UNITS` footer row.
     *
     * @param  list<string>  $cells
     * @param  array<string, int|null>  $columnMap
     */
    private function extractTotalUnitsFromFooterRow(array $cells, array $columnMap): ?float
    {
        $mappedUnitsCell = $this->cellAt($cells, $columnMap[self::FieldCreditUnits] ?? null);
        $mappedValue = $this->parsePlainSmallUnitNumber($mappedUnitsCell);

        if ($mappedValue !== null) {
            return $mappedValue;
        }

        $candidates = [];

        foreach ($cells as $cell) {
            $value = $this->parsePlainSmallUnitNumber($cell);

            if ($value !== null) {
                $candidates[(string) $value] = $value;
            }
        }

        if (count($candidates) === 1) {
            return array_values($candidates)[0];
        }

        return null;
    }

    /**
     * If a Summer Term section contains a single externally-arranged practicum row with blank `credit_units`,
     * infer its units from either its plain numeric `lab_hours` cell (1..12) or the section's `TOTAL UNITS` row (1..12).
     *
     * @param  list<array<string, mixed>>  $parsedRows
     * @param  list<int>  $sectionRowIndices
     */
    private function finalizeSectionSummerPracticumUnits(
        array &$parsedRows,
        array $sectionRowIndices,
        ?float $footerTotalUnits,
    ): void {
        if (count($sectionRowIndices) !== 1) {
            return;
        }

        $targetIndex = $sectionRowIndices[0];

        if (! isset($parsedRows[$targetIndex])) {
            return;
        }

        $row = $parsedRows[$targetIndex];

        if ($row['credit_units'] !== null
            || $row['term_type'] !== Term::TypeSpecialTerm
            || $row['scheduling_treatment'] !== CourseSpecification::SchedulingExternallyArranged) {
            return;
        }

        $plainLabUnits = isset($row['_raw_plain_lab_units']) && is_float($row['_raw_plain_lab_units'])
            ? $row['_raw_plain_lab_units']
            : null;
        $inferredUnits = $plainLabUnits ?? $footerTotalUnits;

        if ($inferredUnits === null || $inferredUnits < 1.0 || $inferredUnits > 12.0) {
            return;
        }

        $rounded = round($inferredUnits, 2);
        $formatted = $this->formatUnitNumber($rounded);

        $parsedRows[$targetIndex]['credit_units'] = $rounded;
        $parsedRows[$targetIndex]['units_inferred'] = true;
        $parsedRows[$targetIndex]['parser_warnings'][] = "Units set to {$formatted} from the Summer term total — please double-check before importing.";
    }

    /**
     * Parse a plain numeric value in the range 1..12 (rejecting strings with "hours"/"hrs" or values > 12).
     */
    private function parsePlainSmallUnitNumber(string $raw): ?float
    {
        $trimmed = trim($raw);

        if ($trimmed === '' || preg_match('/^\d+(?:\.\d+)?$/', $trimmed) !== 1) {
            return null;
        }

        $value = round((float) $trimmed, 2);

        return $value >= 1.0 && $value <= 12.0 ? $value : null;
    }

    private function formatUnitNumber(float $units): string
    {
        return floor($units) === $units
            ? (string) (int) $units
            : rtrim(rtrim(number_format($units, 2, '.', ''), '0'), '.');
    }

    private static function scoreSheetAgainstProgram(string $sheetName, Program $program): int
    {
        $cleanSheet = strtoupper(trim($sheetName));
        $programCode = strtoupper(trim((string) $program->code));
        $programName = strtoupper(trim((string) $program->name));

        if ($cleanSheet === '' || $programCode === '') {
            return 0;
        }

        $baseProgramCode = (string) (preg_replace('/[\s_-]+.*$/', '', $programCode) ?? $programCode);
        $baseSheetCode = (string) (preg_replace('/[\s_-]+.*$/', '', $cleanSheet) ?? $cleanSheet);

        if ($cleanSheet === $programCode || $baseSheetCode === $baseProgramCode) {
            return 100;
        }

        if ($baseProgramCode !== '' && preg_match('/\b'.preg_quote($baseProgramCode, '/').'\b/', $cleanSheet) === 1) {
            return 95;
        }

        if ($baseSheetCode !== '' && strlen($baseSheetCode) >= 2 && preg_match('/\b'.preg_quote($baseSheetCode, '/').'\b/', $programCode) === 1) {
            return 90;
        }

        $programFamily = self::resolveProgramFamily($programCode, $programName);
        $sheetFamily = self::resolveProgramFamily($cleanSheet, $cleanSheet);

        if ($programFamily !== null && $sheetFamily !== null && $programFamily === $sheetFamily) {
            return 80;
        }

        return 0;
    }

    private static function resolveProgramFamily(string $code, string $name): ?string
    {
        $upperCode = strtoupper(trim($code));
        $upperName = strtoupper(trim($name));
        $baseCode = (string) (preg_replace('/[\s_-]+.*$/', '', $upperCode) ?? $upperCode);

        if (in_array($baseCode, ['BSIT', 'DIT', 'ACT', 'BSCS', 'BSIS', 'DCS', 'IT', 'CS', 'IS'], true)
            || preg_match('/\b(?:BSIT|DIT|BSCS|BSIS|INFORMATION\s+TECHNOLOGY|COMPUTER\s+SCIENCE|INFORMATION\s+SYSTEMS)\b/', $upperCode.' '.$upperName) === 1) {
            return 'IT';
        }

        if (in_array($baseCode, ['BSHM', 'DHM', 'BSTM', 'DTHM', 'DTM', 'HM', 'HRM', 'BSHRM'], true)
            || preg_match('/\b(?:BSHM|DHM|BSTM|DTHM|DTM|BSHRM|HOSPITALITY|TOURISM|HOTEL|CULINARY)\b/', $upperCode.' '.$upperName) === 1) {
            return 'HM';
        }

        if (in_array($baseCode, ['BSBA', 'DBM', 'BSOA', 'DOA', 'DOMT', 'BSA', 'BSAIS', 'BSMA', 'BSENTREP', 'BM', 'BA'], true)
            || preg_match('/\b(?:BSBA|DBM|BSOA|DOA|DOMT|BUSINESS|OFFICE\s+ADMINISTRATION|ENTREPRENEURSHIP|ACCOUNTANCY|MARKETING)\b/', $upperCode.' '.$upperName) === 1) {
            return 'BM';
        }

        return null;
    }

    private static function isGenericWorksheetName(string $sheetName): bool
    {
        $clean = strtoupper(trim($sheetName));

        return preg_match('/^(?:SHEET\s*\d*|CURRICULUM|SUBJECTS?|COURSES?|TEMPLATE|IMPORT|EXPORT|DATA|STUDY\s+PLAN|CHECKLIST)$/i', $clean) === 1;
    }

    /**
     * Detect non-subject rows to skip automatically:
     * - `TOTAL UNITS` / `SUB-TOTAL` rows (whether in col 0, col 1, or mapped code/title)
     * - `=SUM(...)` formula rows
     * - `BRIDGING SUBJECT` rows
     * - School title / address / `PROGRAM CURRICULUM` banners
     * - Signature footers (`Prepared By:`, `Evaluated by:`, `Approved By:`, `Noted By:`)
     *
     * @param  list<string>  $cells
     * @param  array<string, int|null>  $columnMap
     */
    private function shouldSkipNonSubjectRow(array $cells, array $columnMap): bool
    {
        foreach ($cells as $cell) {
            $trimmed = trim($cell);

            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/^=\s*SUM\s*\(/i', $trimmed) === 1) {
                return true;
            }

            if (preg_match('/\b(?:TOTAL\s+UNITS|TOTAL\s+NO\.?\s+OF\s+UNITS|SUB-?TOTAL\s+UNITS|BRIDGING\s+SUBJECTS?|ASSESSMENT\s+EVALUATION)\b/i', $trimmed) === 1) {
                return true;
            }

            if (preg_match('/^(?:PREPARED\s+BY|EVALUATED\s+BY|APPROVED\s+BY|NOTED\s+BY|CHECKED\s+BY|RECOMMENDING\s+APPROVAL)\s*:?/i', $trimmed) === 1) {
                return true;
            }
        }

        $col0 = trim($cells[0] ?? '');
        $col1 = trim($cells[1] ?? '');
        $codeCell = $this->cellAt($cells, $columnMap[self::FieldCode] ?? 0);
        $titleCell = $this->cellAt($cells, $columnMap[self::FieldTitle] ?? 1);

        foreach ([$col0, $col1, $codeCell, $titleCell] as $candidate) {
            if ($candidate !== '' && preg_match('/^(?:TOTAL|TOTAL\s+UNITS|SUB-?TOTAL|PROGRAM\s+CURRICULUM|SERVITECH\s+INSTITUTE.*)$/i', $candidate) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse a Lecture or Laboratory hours cell, detecting practicum hour strings such as `300 hours` or `600 hrs`.
     *
     * @return array{hours: float, practicum_hours: float|null}
     */
    private function parseHoursCell(string $raw): array
    {
        $trimmed = trim($raw);

        if ($trimmed === '' || $trimmed === '-') {
            return [
                'hours' => 0.0,
                'practicum_hours' => null,
            ];
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*(?:hours?|hrs?)\b/i', $trimmed, $matches) === 1) {
            $value = (float) $matches[1];

            if ($value >= 40.0) {
                return [
                    'hours' => 0.0,
                    'practicum_hours' => $value,
                ];
            }

            return [
                'hours' => $value,
                'practicum_hours' => null,
            ];
        }

        if (is_numeric($trimmed)) {
            $value = (float) $trimmed;

            if ($value >= 40.0) {
                return [
                    'hours' => 0.0,
                    'practicum_hours' => $value,
                ];
            }

            return [
                'hours' => max(0.0, $value),
                'practicum_hours' => null,
            ];
        }

        return [
            'hours' => 0.0,
            'practicum_hours' => null,
        ];
    }

    private function parseNumericUnits(string $raw): ?float
    {
        $trimmed = trim($raw);

        if ($trimmed === '' || $trimmed === '-') {
            return null;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*(?:units?|u)?$/i', $trimmed, $matches) === 1) {
            return round((float) $matches[1], 2);
        }

        return null;
    }

    private function resolveSchedulingTreatment(
        string $rawScheduling,
        string $code,
        string $title,
        ?float $practicumHours,
    ): string {
        $trimmed = trim($rawScheduling);

        if ($trimmed !== '') {
            if (preg_match('/\b(?:EXTERNALLY[\s_]*ARRANGED|ARRANGED|FLEXIBLE|OJT|PRACTICUM)\b/i', $trimmed) === 1) {
                return CourseSpecification::SchedulingExternallyArranged;
            }

            if (preg_match('/\b(?:RECURRING|WEEKLY)\b/i', $trimmed) === 1) {
                return CourseSpecification::SchedulingRecurring;
            }
        }

        if ($practicumHours !== null) {
            return CourseSpecification::SchedulingExternallyArranged;
        }

        if (preg_match('/\b(?:OJT|ON-THE-JOB|ON\s+THE\s+JOB|PRACTICUM|INTERNSHIP|WORK[\s-]+RELATED\s+TRAINING|SUPERVISED\s+WORK|INDUSTRY\s+IMMERSION)\b/i', $title) === 1
            || preg_match('/(?:PRAC|OJT)\d*\b/i', $code) === 1) {
            return CourseSpecification::SchedulingExternallyArranged;
        }

        return CourseSpecification::SchedulingRecurring;
    }

    /**
     * Resolve `academic_classification` from an explicit Subject Type / Resultant NC cell + code/title heuristics,
     * recognizing genuine TESDA NC qualifications while ignoring junk notes (`x`, `TAKEN`, `MR. ...`, `BLENDED ...`, `54HRS`).
     *
     * @return array{classification: string, tesda_note: string|null}
     */
    private function resolveAcademicClassification(string $rawClassification, string $code, string $title): array
    {
        $trimmed = trim($rawClassification);
        $tesdaNote = null;

        if ($trimmed !== '') {
            $upper = strtoupper($trimmed);

            if ($upper === CourseSpecification::AcademicClassificationTesdaCompetency
                || preg_match('/\b(?:TESDA|TVET)\b/i', $trimmed) === 1
                || preg_match('/\bNC[\s-]*(?:I|II|III|IV|1|2|3|4)\b/i', $trimmed) === 1) {
                return [
                    'classification' => CourseSpecification::AcademicClassificationTesdaCompetency,
                    'tesda_note' => $trimmed,
                ];
            }

            if ($upper === CourseSpecification::AcademicClassificationNstp
                || preg_match('/\b(?:NSTP|CWTS|LTS|ROTC)\b/i', $trimmed) === 1) {
                return [
                    'classification' => CourseSpecification::AcademicClassificationNstp,
                    'tesda_note' => null,
                ];
            }

            if ($upper === CourseSpecification::AcademicClassificationPe
                || preg_match('/\b(?:PHYSICAL\s+EDUCATION|PATHFIT|P\.?E\.?)\b/i', $trimmed) === 1) {
                return [
                    'classification' => CourseSpecification::AcademicClassificationPe,
                    'tesda_note' => null,
                ];
            }

            if ($upper === CourseSpecification::AcademicClassificationNonCredit
                || preg_match('/\b(?:NON[\s_-]*CREDIT|BRIDGING|REMEDIAL)\b/i', $trimmed) === 1) {
                return [
                    'classification' => CourseSpecification::AcademicClassificationNonCredit,
                    'tesda_note' => null,
                ];
            }

            if ($upper === CourseSpecification::AcademicClassificationOrdinary
                || preg_match('/^(?:REGULAR|ORDINARY|REGULAR\s+ACADEMIC\s+SUBJECT)/i', $trimmed) === 1) {
                return [
                    'classification' => CourseSpecification::AcademicClassificationOrdinary,
                    'tesda_note' => null,
                ];
            }
        }

        $identity = strtoupper(trim($code.' '.$title));

        if (str_contains($identity, 'NSTP') || str_contains($identity, 'CWTS') || str_contains($identity, 'LTS') || str_contains($identity, 'ROTC')) {
            return [
                'classification' => CourseSpecification::AcademicClassificationNstp,
                'tesda_note' => null,
            ];
        }

        if (str_contains($identity, 'PHYSICAL EDUCATION')
            || preg_match('/(?:^|\s)(?:PE|PATHFIT|P\.E\.)(?:\d|\s|$)/', $identity) === 1
            || preg_match('/^PE\d/', strtoupper(trim($code))) === 1) {
            return [
                'classification' => CourseSpecification::AcademicClassificationPe,
                'tesda_note' => null,
            ];
        }

        if (preg_match('/\b(?:NON-CREDIT|NON\s+CREDIT)\b/', $identity) === 1) {
            return [
                'classification' => CourseSpecification::AcademicClassificationNonCredit,
                'tesda_note' => null,
            ];
        }

        if (preg_match('/\b(?:TESDA|TVET)\b/', $identity) === 1
            || preg_match('/\bNC[\s-]*(?:I|II|III|IV)\b/', $identity) === 1) {
            return [
                'classification' => CourseSpecification::AcademicClassificationTesdaCompetency,
                'tesda_note' => $tesdaNote,
            ];
        }

        return [
            'classification' => CourseSpecification::AcademicClassificationOrdinary,
            'tesda_note' => null,
        ];
    }

    /**
     * @param  list<string>  $cells
     */
    private function cellAt(array $cells, ?int $index): string
    {
        if ($index === null || $index < 0 || ! array_key_exists($index, $cells)) {
            return '';
        }

        return trim($cells[$index]);
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
     * Convert an OpenSpout Row into a dense zero-based list of string cell values with trailing empty cells stripped.
     *
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
            $cell = $row->getCellAtIndex($col);
            $cells[] = $this->formatCellValue($cell);
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
            $formula = trim((string) $cell->getValue());

            if (preg_match('/^=\s*SUM\b/i', $formula) === 1) {
                return $formula;
            }

            $computed = $cell->getComputedValue();

            if ($computed !== null && $computed !== '') {
                return $this->scalarToString($computed);
            }

            return $formula;
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

        return $resolvedExtension === 'xlsx'
            ? new XlsxReader
            : new CsvReader;
    }
}
