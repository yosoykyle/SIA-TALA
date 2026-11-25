<?php

namespace App\Actions\StudentProfiles;

use App\Actions\AcademicSetup\CurriculumSpreadsheetParser;
use Carbon\CarbonImmutable;
use DateInterval;
use DateTimeInterface;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\SheetInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

class OngoingStudentSpreadsheetParser
{
    public const FieldStudentNumber = 'student_number';

    public const FieldFirstName = 'first_name';

    public const FieldMiddleName = 'middle_name';

    public const FieldLastName = 'last_name';

    public const FieldEmail = 'email';

    public const FieldProgramCode = 'program_code';

    public const FieldCurriculumVersionCode = 'curriculum_version_code';

    public const FieldEntryTermCode = 'entry_term_code';

    public const FieldAcademicStanding = 'academic_standing';

    public const FieldLifecycleStatus = 'lifecycle_status';

    public const FieldBirthDate = 'birth_date';

    public const FieldPhone = 'phone';

    public const FieldAddress = 'address';

    public const FieldPriorIdentifier = 'prior_identifier';

    public const FieldFinancialHoldNote = 'financial_hold_note';

    public const FieldPriorCompletedSubjects = 'prior_completed_subjects';

    /**
     * Canonical field labels for Sheet 1 (`Students`) column mapping dropdowns.
     *
     * @return array<string, string>
     */
    public static function canonicalFieldLabels(): array
    {
        return [
            self::FieldStudentNumber => 'Student Number*',
            self::FieldFirstName => 'First Name*',
            self::FieldMiddleName => 'Middle Name',
            self::FieldLastName => 'Last Name*',
            self::FieldEmail => 'Email*',
            self::FieldProgramCode => 'Program Code*',
            self::FieldCurriculumVersionCode => 'Curriculum Version Code',
            self::FieldEntryTermCode => 'Entry Term Code',
            self::FieldAcademicStanding => 'Academic Standing',
            self::FieldLifecycleStatus => 'Lifecycle Status',
            self::FieldBirthDate => 'Birth Date',
            self::FieldPhone => 'Phone',
            self::FieldAddress => 'Address',
            self::FieldPriorIdentifier => 'LRN / Prior ID',
            self::FieldFinancialHoldNote => 'Financial Hold Note',
            self::FieldPriorCompletedSubjects => 'Prior Completed Subjects',
        ];
    }

    /**
     * Canonical required fields for Sheet 1 (`Students`).
     *
     * @return list<string>
     */
    public static function requiredFields(): array
    {
        return [
            self::FieldStudentNumber,
            self::FieldFirstName,
            self::FieldLastName,
            self::FieldEmail,
            self::FieldProgramCode,
        ];
    }

    /**
     * Header aliases used to auto-detect Sheet 1 headers and suggest column mappings.
     *
     * @return array<string, list<string>>
     */
    public static function fieldAliases(): array
    {
        return [
            self::FieldStudentNumber => [
                'Student Number',
                'Student No',
                'Student ID',
                'ID Number',
                'Stud No',
            ],
            self::FieldFirstName => [
                'First Name',
                'Given Name',
                'Fname',
            ],
            self::FieldMiddleName => [
                'Middle Name',
                'Middle Initial',
                'Mname',
            ],
            self::FieldLastName => [
                'Last Name',
                'Surname',
                'Family Name',
                'Lname',
            ],
            self::FieldEmail => [
                'Email',
                'Email Address',
                'Institutional Email',
                'E-mail',
            ],
            self::FieldProgramCode => [
                'Program Code',
                'Program',
                'Course',
                'Degree Program',
            ],
            self::FieldCurriculumVersionCode => [
                'Curriculum Version Code',
                'Curriculum Code',
                'Curriculum Version',
                'Curriculum',
            ],
            self::FieldEntryTermCode => [
                'Entry Term Code',
                'Entry Term',
                'Admission Term',
                'Starting Term',
            ],
            self::FieldAcademicStanding => [
                'Academic Standing',
                'Standing',
                'Student Type',
                'Classification',
            ],
            self::FieldLifecycleStatus => [
                'Lifecycle Status',
                'Enrollment Status',
                'Student Status',
                'Status',
            ],
            self::FieldBirthDate => [
                'Birth Date',
                'Birthdate',
                'Date of Birth',
                'DOB',
            ],
            self::FieldPhone => [
                'Phone',
                'Mobile Number',
                'Contact Number',
                'Phone Number',
            ],
            self::FieldAddress => [
                'Address',
                'Home Address',
                'Permanent Address',
            ],
            self::FieldPriorIdentifier => [
                'LRN / Prior ID',
                'LRN',
                'Prior Identifier',
                'Prior ID',
            ],
            self::FieldFinancialHoldNote => [
                'Financial Hold Note',
                'Financial Hold',
                'Balance Note',
                'Hold Note',
            ],
            self::FieldPriorCompletedSubjects => [
                'Prior Completed Subjects',
                'Completed Subjects',
                'Prior Subjects',
                'Credited Subjects',
            ],
        ];
    }

    /**
     * Header aliases used to auto-map Sheet 2 (`Prior Subjects`).
     *
     * @return array<string, list<string>>
     */
    public static function priorSubjectsFieldAliases(): array
    {
        return [
            'student_number' => [
                'Student Number',
                'Student No',
                'Student ID',
                'ID Number',
            ],
            'subject_code' => [
                'Subject Code',
                'Course Code',
                'Code',
                'Subj Code',
            ],
            'year_level' => [
                'Year Level',
                'Year',
                'Yr Level',
            ],
            'academic_year' => [
                'Academic Year',
                'AY',
                'School Year',
                'SY',
            ],
            'term' => [
                'Term',
                'Semester',
                'Academic Period',
                'Term Label',
            ],
            'final_grade' => [
                'Final Grade',
                'Grade',
                'Rating',
            ],
            'result_status' => [
                'Result Status',
                'Status',
                'Remarks',
                'Result',
            ],
            'source_school' => [
                'Source School',
                'Previous School',
                'School',
                'Institution',
            ],
        ];
    }

    /**
     * Inspect an uploaded `.xlsx` or `.csv` workbook and identify its sheets,
     * default Students sheet (ignoring `Reference Codes`), and optional `Prior Subjects` sheet.
     *
     * @return array{
     *     sheets: list<array{index: int, name: string, row_count: int}>,
     *     students_sheet_index: int,
     *     prior_subjects_sheet_index: int|null
     * }
     */
    public function inspectWorkbook(string $path, ?string $extension = null): array
    {
        $reader = $this->createReader($path, $extension);
        $reader->open($path);

        try {
            $sheets = [];
            $studentsSheetIndex = null;
            $firstNonReferenceNonEmptyIndex = null;
            $priorSubjectsSheetIndex = null;

            foreach ($reader->getSheetIterator() as $sheet) {
                /** @var SheetInterface $sheet */
                $index = $sheet->getIndex();
                $name = trim($sheet->getName());

                if ($name === '') {
                    $name = 'Sheet '.($index + 1);
                }

                $rowCount = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    if (! $this->isRowEmpty($this->rowToCells($row))) {
                        $rowCount++;
                    }
                }

                $sheets[] = [
                    'index' => $index,
                    'name' => $name,
                    'row_count' => $rowCount,
                ];

                $normName = $this->normalizeAliasKey($name);

                if ($this->isReferenceSheetName($normName)) {
                    continue;
                }

                if ($priorSubjectsSheetIndex === null && $this->isPriorSubjectsSheetName($normName)) {
                    $priorSubjectsSheetIndex = $index;

                    continue;
                }

                if ($studentsSheetIndex === null && $this->isStudentsSheetName($normName)) {
                    $studentsSheetIndex = $index;
                }

                if ($firstNonReferenceNonEmptyIndex === null && $rowCount > 0) {
                    $firstNonReferenceNonEmptyIndex = $index;
                }
            }

            if ($studentsSheetIndex === null) {
                $studentsSheetIndex = $firstNonReferenceNonEmptyIndex ?? ($sheets[0]['index'] ?? 0);
            }

            return [
                'sheets' => $sheets,
                'students_sheet_index' => $studentsSheetIndex,
                'prior_subjects_sheet_index' => $priorSubjectsSheetIndex,
            ];
        } finally {
            $reader->close();
        }
    }

    /**
     * Extract column headers, sample options, auto-suggested mapping, and raw non-empty rows for a sheet.
     *
     * @return array{
     *     sheet_index: int,
     *     sheet_name: string,
     *     header_row_index: int,
     *     headers: array<int, string>,
     *     header_options: array<int|string, string>,
     *     suggested_mapping: array<string, int|null>,
     *     raw_rows: list<array{source_row: int, cells: array<int, string>}>
     * }
     */
    public function extractSheet(string $path, int $sheetIndex = 0, ?string $extension = null): array
    {
        $sheetData = $this->readSheetRows($path, $sheetIndex, $extension);
        $rawSheetRows = $sheetData['rows'];
        $headerMeta = $this->locateHeaderRow($rawSheetRows, self::fieldAliases());

        $headers = [];
        foreach ($headerMeta['cells'] as $colIndex => $rawHeader) {
            $clean = $this->cleanHeaderText($rawHeader);
            if ($clean !== '') {
                $headers[(int) $colIndex] = $clean;
            }
        }

        $suggestedMapping = $this->guessColumnMapping($headers, self::fieldAliases());

        $rawRows = [];
        $firstSampleByCol = [];

        foreach ($rawSheetRows as $offset => $entry) {
            if ($offset <= $headerMeta['offset']) {
                continue;
            }

            $cells = $entry['cells'];
            if ($this->isRowEmpty($cells)) {
                continue;
            }

            $rowCells = [];
            foreach ($headers as $colIndex => $headerName) {
                $val = trim((string) ($cells[$colIndex] ?? ''));
                $rowCells[$colIndex] = $val;

                if ($val !== '' && ! isset($firstSampleByCol[$colIndex])) {
                    $firstSampleByCol[$colIndex] = $val;
                }
            }

            $rawRows[] = [
                'source_row' => $entry['source_row'],
                'cells' => $rowCells,
            ];
        }

        $headerOptions = [];
        foreach ($headers as $colIndex => $headerName) {
            $sample = $firstSampleByCol[$colIndex] ?? null;
            $headerOptions[(string) $colIndex] = $sample !== null
                ? $headerName.' (e.g. '.$sample.')'
                : $headerName;
        }

        return [
            'sheet_index' => $sheetData['sheet_index'],
            'sheet_name' => $sheetData['sheet_name'],
            'header_row_index' => $headerMeta['source_row'],
            'headers' => $headers,
            'header_options' => $headerOptions,
            'suggested_mapping' => $suggestedMapping,
            'raw_rows' => $rawRows,
        ];
    }

    /**
     * Parse Sheet 1 (`Students`) rows using the selected or suggested column map.
     *
     * @param  array<string, int|string|null>  $columnMap
     * @return list<array<string, mixed>>
     */
    public function parseStudentRows(
        string $path,
        int $sheetIndex = 0,
        array $columnMap = [],
        ?string $extension = null,
    ): array {
        $extracted = $this->extractSheet($path, $sheetIndex, $extension);
        $normalizedMap = $columnMap === []
            ? $extracted['suggested_mapping']
            : $this->normalizeColumnMap($columnMap, array_keys(self::fieldAliases()));

        $parsed = [];

        foreach ($extracted['raw_rows'] as $entry) {
            $sourceRow = (int) $entry['source_row'];
            $cells = $entry['cells'];

            $studentNumber = $this->cleanSingleLine($this->cellAt($cells, $normalizedMap[self::FieldStudentNumber] ?? null));
            $firstName = $this->cleanSingleLine($this->cellAt($cells, $normalizedMap[self::FieldFirstName] ?? null));
            $middleName = $this->cleanSingleLine($this->cellAt($cells, $normalizedMap[self::FieldMiddleName] ?? null));
            $lastName = $this->cleanSingleLine($this->cellAt($cells, $normalizedMap[self::FieldLastName] ?? null));
            $email = strtolower($this->cleanSingleLine($this->cellAt($cells, $normalizedMap[self::FieldEmail] ?? null)));
            $programCode = strtoupper($this->cleanSingleLine($this->cellAt($cells, $normalizedMap[self::FieldProgramCode] ?? null)));
            $curriculumVersionCode = $this->cleanSingleLine($this->cellAt($cells, $normalizedMap[self::FieldCurriculumVersionCode] ?? null));
            $entryTermCode = $this->cleanSingleLine($this->cellAt($cells, $normalizedMap[self::FieldEntryTermCode] ?? null));
            $academicStanding = $this->cleanSingleLine($this->cellAt($cells, $normalizedMap[self::FieldAcademicStanding] ?? null));
            $lifecycleStatus = $this->cleanSingleLine($this->cellAt($cells, $normalizedMap[self::FieldLifecycleStatus] ?? null));
            $birthDate = $this->normalizeDateString($this->cleanSingleLine($this->cellAt($cells, $normalizedMap[self::FieldBirthDate] ?? null)));
            $phone = $this->cleanSingleLine($this->cellAt($cells, $normalizedMap[self::FieldPhone] ?? null));
            $address = trim($this->stripInvisibleChars($this->cellAt($cells, $normalizedMap[self::FieldAddress] ?? null)));
            $priorIdentifier = $this->cleanSingleLine($this->cellAt($cells, $normalizedMap[self::FieldPriorIdentifier] ?? null));
            $financialHoldNote = trim($this->stripInvisibleChars($this->cellAt($cells, $normalizedMap[self::FieldFinancialHoldNote] ?? null)));
            $priorSubjectsRaw = trim($this->stripInvisibleChars($this->cellAt($cells, $normalizedMap[self::FieldPriorCompletedSubjects] ?? null)));

            $allEmpty = $studentNumber === ''
                && $firstName === ''
                && $middleName === ''
                && $lastName === ''
                && $email === ''
                && $programCode === ''
                && $curriculumVersionCode === ''
                && $entryTermCode === ''
                && $academicStanding === ''
                && $lifecycleStatus === ''
                && $birthDate === ''
                && $phone === ''
                && $address === ''
                && $priorIdentifier === ''
                && $financialHoldNote === ''
                && $priorSubjectsRaw === '';

            if ($allEmpty) {
                continue;
            }

            $parsed[] = [
                'row_key' => 'student_row_'.$sourceRow,
                'source_row' => $sourceRow,
                self::FieldStudentNumber => $studentNumber,
                self::FieldFirstName => $firstName,
                self::FieldMiddleName => $middleName !== '' ? $middleName : null,
                self::FieldLastName => $lastName,
                self::FieldEmail => $email,
                self::FieldProgramCode => $programCode,
                self::FieldCurriculumVersionCode => $curriculumVersionCode !== '' ? $curriculumVersionCode : null,
                self::FieldEntryTermCode => $entryTermCode !== '' ? $entryTermCode : null,
                self::FieldAcademicStanding => $academicStanding !== '' ? $academicStanding : null,
                self::FieldLifecycleStatus => $lifecycleStatus !== '' ? $lifecycleStatus : null,
                self::FieldBirthDate => $birthDate !== '' ? $birthDate : null,
                self::FieldPhone => $phone !== '' ? $phone : null,
                self::FieldAddress => $address !== '' ? $address : null,
                self::FieldPriorIdentifier => $priorIdentifier !== '' ? $priorIdentifier : null,
                self::FieldFinancialHoldNote => $financialHoldNote !== '' ? $financialHoldNote : null,
                self::FieldPriorCompletedSubjects => $priorSubjectsRaw !== '' ? $priorSubjectsRaw : null,
                'inline_prior_credits' => self::parseInlinePriorSubjects($priorSubjectsRaw),
            ];
        }

        return $parsed;
    }

    /**
     * Auto-map headers and parse Sheet 2 (`Prior Subjects`) rows.
     *
     * @return list<array<string, mixed>>
     */
    public function parsePriorSubjectsSheet(string $path, int $sheetIndex = 1, ?string $extension = null): array
    {
        $sheetData = $this->readSheetRows($path, $sheetIndex, $extension);
        $rawSheetRows = $sheetData['rows'];
        $aliases = self::priorSubjectsFieldAliases();
        $headerMeta = $this->locateHeaderRow($rawSheetRows, $aliases);

        $headers = [];
        foreach ($headerMeta['cells'] as $colIndex => $rawHeader) {
            $clean = $this->cleanHeaderText($rawHeader);
            if ($clean !== '') {
                $headers[(int) $colIndex] = $clean;
            }
        }

        $columnMap = $this->guessColumnMapping($headers, $aliases);
        $parsed = [];

        foreach ($rawSheetRows as $offset => $entry) {
            if ($offset <= $headerMeta['offset']) {
                continue;
            }

            $cells = $entry['cells'];
            if ($this->isRowEmpty($cells)) {
                continue;
            }

            $sourceRow = (int) $entry['source_row'];
            $studentNumber = $this->cleanSingleLine($this->cellAt($cells, $columnMap['student_number'] ?? null));
            $rawSubjectCode = $this->cleanSingleLine($this->cellAt($cells, $columnMap['subject_code'] ?? null));
            $rawYearLevel = $this->cleanSingleLine($this->cellAt($cells, $columnMap['year_level'] ?? null));
            $academicYear = $this->cleanSingleLine($this->cellAt($cells, $columnMap['academic_year'] ?? null));
            $term = $this->cleanSingleLine($this->cellAt($cells, $columnMap['term'] ?? null));
            $finalGrade = $this->cleanSingleLine($this->cellAt($cells, $columnMap['final_grade'] ?? null));
            $resultStatus = strtolower($this->cleanSingleLine($this->cellAt($cells, $columnMap['result_status'] ?? null)));
            $sourceSchool = $this->cleanSingleLine($this->cellAt($cells, $columnMap['source_school'] ?? null));

            if ($studentNumber === ''
                && $rawSubjectCode === ''
                && $rawYearLevel === ''
                && $academicYear === ''
                && $term === ''
                && $finalGrade === ''
                && $resultStatus === ''
                && $sourceSchool === '') {
                continue;
            }

            $subjectCode = $rawSubjectCode !== '' ? CurriculumSpreadsheetParser::normalizeDisplayCode($rawSubjectCode) : '';
            $normalizedSubjectCode = $rawSubjectCode !== '' ? CurriculumSpreadsheetParser::normalizeMatchCode($rawSubjectCode) : '';
            $yearLevel = $rawYearLevel !== '' ? CurriculumSpreadsheetParser::parseYearLevel($rawYearLevel) : null;

            $parsed[] = [
                'row_key' => 'prior_row_'.$sourceRow,
                'source_row' => $sourceRow,
                'student_number' => $studentNumber,
                'subject_code' => $subjectCode,
                'normalized_subject_code' => $normalizedSubjectCode,
                'raw_year_level' => $rawYearLevel !== '' ? $rawYearLevel : null,
                'year_level' => $yearLevel,
                'academic_year' => $academicYear !== '' ? $academicYear : null,
                'term' => $term !== '' ? $term : null,
                'final_grade' => $finalGrade !== '' ? $finalGrade : null,
                'result_status' => $resultStatus !== '' ? $resultStatus : null,
                'source_school' => $sourceSchool !== '' ? $sourceSchool : null,
            ];
        }

        return $parsed;
    }

    /**
     * Parse semicolon-delimited inline prior subjects (`GE 1; IT 101:1.75`).
     *
     * @return list<array{subject_code: string, normalized_subject_code: string, final_grade: string|null, raw: string}>
     */
    public static function parseInlinePriorSubjects(?string $raw): array
    {
        $trimmed = trim((string) ($raw ?? ''));
        if ($trimmed === '') {
            return [];
        }

        $segments = preg_split('/\s*[;\r\n]+\s*/', $trimmed) ?: [$trimmed];
        $items = [];

        foreach ($segments as $segment) {
            $token = trim((string) preg_replace('/\s+/', ' ', $segment));
            if ($token === '') {
                continue;
            }

            $codePart = $token;
            $gradePart = null;

            if (str_contains($token, ':')) {
                [$left, $right] = explode(':', $token, 2);
                $codePart = trim($left);
                $rightClean = trim($right);
                $gradePart = $rightClean !== '' ? $rightClean : null;
            }

            if ($codePart === '') {
                continue;
            }

            $items[] = [
                'subject_code' => CurriculumSpreadsheetParser::normalizeDisplayCode($codePart),
                'normalized_subject_code' => CurriculumSpreadsheetParser::normalizeMatchCode($codePart),
                'final_grade' => $gradePart,
                'raw' => $token,
            ];
        }

        return $items;
    }

    /**
     * @return array{sheet_index: int, sheet_name: string, rows: list<array{source_row: int, cells: list<string>}>}
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
            $counter = 0;

            foreach ($selectedSheet->getRowIterator() as $rowIndex => $row) {
                $counter++;
                $sourceRow = is_int($rowIndex) && $rowIndex > 0 ? $rowIndex : $counter;
                $rows[] = [
                    'source_row' => $sourceRow,
                    'cells' => $this->rowToCells($row),
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
     * @param  list<array{source_row: int, cells: list<string>}>  $rawRows
     * @param  array<string, list<string>>  $aliases
     * @return array{offset: int, source_row: int, cells: list<string>}
     */
    private function locateHeaderRow(array $rawRows, array $aliases): array
    {
        $bestOffset = 0;
        $bestScore = -1;
        $firstMultiColumnOffset = null;

        foreach ($rawRows as $offset => $entry) {
            $cells = $entry['cells'];
            if ($this->isRowEmpty($cells)) {
                continue;
            }

            $nonEmpty = count(array_filter($cells, fn (string $c): bool => trim($c) !== ''));
            if ($nonEmpty >= 2 && $firstMultiColumnOffset === null) {
                $firstMultiColumnOffset = $offset;
            }

            $headers = [];
            foreach ($cells as $colIndex => $cell) {
                $clean = $this->cleanHeaderText($cell);
                if ($clean !== '') {
                    $headers[(int) $colIndex] = $clean;
                }
            }

            if (count($headers) < 2) {
                continue;
            }

            $mapping = $this->guessColumnMapping($headers, $aliases);
            $matchedCount = count(array_filter($mapping, fn (?int $idx): bool => $idx !== null));

            if ($matchedCount > $bestScore && $matchedCount >= 2) {
                $bestScore = $matchedCount;
                $bestOffset = $offset;

                if ($matchedCount >= 4) {
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
     * @param  array<int, string>  $headers
     * @param  array<string, list<string>>  $aliasesByField
     * @return array<string, int|null>
     */
    private function guessColumnMapping(array $headers, array $aliasesByField): array
    {
        $mapping = array_fill_keys(array_keys($aliasesByField), null);
        $claimed = [];
        $normalizedHeaders = [];

        foreach ($headers as $colIndex => $headerName) {
            $normalizedHeaders[$colIndex] = $this->normalizeAliasKey($headerName);
        }

        foreach ($aliasesByField as $field => $aliases) {
            $normalizedAliases = array_map(fn (string $alias): string => $this->normalizeAliasKey($alias), $aliases);

            foreach ($normalizedAliases as $targetAlias) {
                foreach ($normalizedHeaders as $colIndex => $headerNorm) {
                    if (isset($claimed[$colIndex])) {
                        continue;
                    }

                    if ($headerNorm === $targetAlias) {
                        $mapping[$field] = $colIndex;
                        $claimed[$colIndex] = true;

                        break 2;
                    }
                }
            }
        }

        return $mapping;
    }

    /**
     * @param  array<string, int|string|null>  $columnMap
     * @param  list<string>  $allowedFields
     * @return array<string, int|null>
     */
    private function normalizeColumnMap(array $columnMap, array $allowedFields): array
    {
        $normalized = array_fill_keys($allowedFields, null);

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

    private function isReferenceSheetName(string $normalizedName): bool
    {
        return in_array($normalizedName, ['reference codes', 'reference', 'ref codes', 'codes'], true);
    }

    private function isPriorSubjectsSheetName(string $normalizedName): bool
    {
        return in_array($normalizedName, [
            'prior subjects',
            'prior academic credits',
            'prior credits',
            'prior grades',
            'completed subjects',
        ], true);
    }

    private function isStudentsSheetName(string $normalizedName): bool
    {
        return in_array($normalizedName, ['students', 'student', 'ongoing students'], true);
    }

    private function normalizeAliasKey(string $text): string
    {
        $cleaned = strtolower($this->cleanHeaderText($text));
        $cleaned = preg_replace('/[^a-z0-9]+/', ' ', $cleaned) ?? $cleaned;

        return trim($cleaned);
    }

    private function cleanHeaderText(string $text): string
    {
        $cleaned = $this->stripInvisibleChars($text);
        $cleaned = preg_replace('/\s+/', ' ', trim($cleaned)) ?? trim($cleaned);

        return trim($cleaned, " \t\n\r\0\x0B*");
    }

    private function cleanSingleLine(string $text): string
    {
        $cleaned = $this->stripInvisibleChars($text);

        return (string) (preg_replace('/\s+/', ' ', trim($cleaned)) ?? trim($cleaned));
    }

    private function stripInvisibleChars(string $text): string
    {
        return preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text) ?? $text;
    }

    private function normalizeDateString(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            return $raw;
        }

        try {
            return CarbonImmutable::parse($raw)->format('Y-m-d');
        } catch (Throwable) {
            return $raw;
        }
    }

    /**
     * @param  array<int, string>  $cells
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
}
