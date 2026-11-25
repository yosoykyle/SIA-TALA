<?php

namespace App\Actions\StudentProfiles;

use App\Actions\AcademicSetup\CurriculumSpreadsheetParser;
use App\Models\Course;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\PriorAcademicCredit;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

class OngoingStudentSpreadsheetImportService
{
    public const StatusReady = 'ready';

    public const StatusAlreadyOnboarded = 'already_onboarded';

    public const StatusBlocked = 'blocked';

    public const StatusSkipped = 'skipped';

    public function __construct(
        private readonly OnboardExistingStudent $onboardStudent,
        private readonly RecordPriorAcademicCredits $priorCredits,
    ) {}

    /**
     * Validate parsed Sheet 1 (`Students`) and optional Sheet 2 (`Prior Subjects`) rows
     * against live DB state without writing any records.
     *
     * @param  list<array<string, mixed>>  $parsedStudents
     * @param  list<array<string, mixed>>  $parsedPriorSubjects
     * @param  array<string|int, array<string, mixed>>  $rowOverrides
     * @return array{
     *     summary: array{
     *         total: int,
     *         ready_new: int,
     *         already_onboarded: int,
     *         blocked_count: int,
     *         skipped_count: int,
     *         warnings_count: int,
     *         prior_subjects_total: int,
     *         prior_subjects_ready: int,
     *         prior_subjects_blocked: int
     *     },
     *     rows: list<array<string, mixed>>,
     *     prior_subjects: list<array<string, mixed>>
     * }
     */
    public function buildPreview(
        array $parsedStudents,
        array $parsedPriorSubjects = [],
        array $rowOverrides = [],
    ): array {
        $indexedOverrides = $this->normalizeOverrides($rowOverrides);

        $programs = Program::query()
            ->with(['curriculumVersions' => fn ($query) => $query
                ->whereIn('state', [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded])
                ->orderByRaw("CASE WHEN state = 'ACTIVE' THEN 0 ELSE 1 END")
                ->orderByDesc('id')])
            ->get();

        /** @var array<string, Program> $programsByCode */
        $programsByCode = [];
        foreach ($programs as $program) {
            $codeKey = strtoupper(trim((string) $program->code));
            if ($codeKey !== '') {
                $programsByCode[$codeKey] = $program;
            }
        }

        $terms = Term::query()->with('academicYear')->get();

        /** @var array<string, StudentProfile> $existingProfilesByNumber */
        $existingProfilesByNumber = [];
        /** @var array<string, StudentProfile> $existingProfilesByEmail */
        $existingProfilesByEmail = [];

        StudentProfile::query()
            ->with('user')
            ->get()
            ->each(function (StudentProfile $profile) use (&$existingProfilesByNumber, &$existingProfilesByEmail): void {
                $numKey = strtoupper(trim((string) $profile->student_number));
                if ($numKey !== '') {
                    $existingProfilesByNumber[$numKey] = $profile;
                }

                $profileEmail = strtolower(trim((string) ($profile->user?->email ?? $profile->email ?? '')));
                if ($profileEmail !== '') {
                    $existingProfilesByEmail[$profileEmail] = $profile;
                }
            });

        /** @var array<string, int> $existingUserIdsByEmail */
        $existingUserIdsByEmail = User::query()
            ->pluck('id', 'email')
            ->mapWithKeys(fn (int $id, string $email): array => [strtolower(trim($email)) => $id])
            ->all();

        $courses = Course::query()
            ->with(['specifications' => fn ($query) => $query
                ->orderByRaw("CASE WHEN state = 'ACTIVE' THEN 0 WHEN state = 'DRAFT' THEN 1 ELSE 2 END")
                ->orderByDesc('id')])
            ->get();

        /** @var array<string, Course> $coursesByDisplayCode */
        $coursesByDisplayCode = [];
        /** @var array<string, Course> $coursesByMatchCode */
        $coursesByMatchCode = [];
        foreach ($courses as $course) {
            $displayCode = CurriculumSpreadsheetParser::normalizeDisplayCode((string) $course->code);
            $matchCode = CurriculumSpreadsheetParser::normalizeMatchCode((string) $course->code);
            if ($displayCode !== '') {
                $coursesByDisplayCode[$displayCode] = $course;
            }
            if ($matchCode !== '') {
                $coursesByMatchCode[$matchCode] = $course;
            }
        }

        /** @var array<int, array<int, CurriculumEntry>> $curriculumEntriesBySpecByVersion */
        $curriculumEntriesBySpecByVersion = [];

        $seenBatchNumbers = [];
        $seenBatchEmails = [];
        $previewRows = [];
        $readyStudentsByNumber = [];

        $readyNewCount = 0;
        $alreadyOnboardedCount = 0;
        $blockedCount = 0;
        $skippedCount = 0;
        $warningsCount = 0;

        foreach ($parsedStudents as $index => $rawRow) {
            $sourceRow = (int) ($rawRow['source_row'] ?? ($index + 1));
            $rowKey = (string) ($rawRow['row_key'] ?? ('student_row_'.$sourceRow));
            $override = $indexedOverrides[$rowKey] ?? $indexedOverrides[(string) $sourceRow] ?? [];

            $row = $this->applyStudentOverride($rawRow, $override, $rowKey, $sourceRow);
            $isSkipped = (bool) $row['skip'];
            $studentNumber = (string) $row['student_number'];
            $studentNumberKey = strtoupper($studentNumber);
            $firstName = (string) $row['first_name'];
            $middleName = $row['middle_name'];
            $lastName = (string) $row['last_name'];
            $email = (string) $row['email'];
            $emailKey = strtolower($email);
            $programCode = (string) $row['program_code'];
            $curriculumCode = $row['curriculum_version_code'];
            $entryTermCode = $row['entry_term_code'];

            $errors = [];
            $warnings = [];
            $issueCategories = [];
            $editableIssueFields = [];

            $matchedExistingProfile = null;
            $alreadyOnboardedReason = null;

            if (! $isSkipped) {
                if ($studentNumberKey !== '' && isset($existingProfilesByNumber[$studentNumberKey])) {
                    $matchedExistingProfile = $existingProfilesByNumber[$studentNumberKey];
                    $alreadyOnboardedReason = "Student number {$studentNumber} already exists in TALA.";
                } elseif ($emailKey !== '' && isset($existingProfilesByEmail[$emailKey])) {
                    $matchedExistingProfile = $existingProfilesByEmail[$emailKey];
                    $alreadyOnboardedReason = "Email {$email} already belongs to an existing student profile.";
                } elseif ($emailKey !== '' && isset($existingUserIdsByEmail[$emailKey])) {
                    $alreadyOnboardedReason = "Email {$email} is already registered to an existing TALA account.";
                }
            }

            $program = null;
            $curriculumVersion = null;
            $entryTerm = null;
            $resolvedInlineCredits = [];

            if (! $isSkipped && $alreadyOnboardedReason === null) {
                if ($studentNumber === '') {
                    $errors[] = 'Missing student number.';
                    $issueCategories[] = 'Missing student number';
                    $editableIssueFields[] = OngoingStudentSpreadsheetParser::FieldStudentNumber;
                } elseif (strlen($studentNumber) > 64) {
                    $errors[] = 'Student number cannot exceed 64 characters.';
                    $issueCategories[] = 'Invalid student number';
                    $editableIssueFields[] = OngoingStudentSpreadsheetParser::FieldStudentNumber;
                } elseif (isset($seenBatchNumbers[$studentNumberKey])) {
                    $firstRow = $seenBatchNumbers[$studentNumberKey];
                    $errors[] = "Duplicate student number ({$studentNumber}) in this file (first seen on row {$firstRow}).";
                    $issueCategories[] = 'Duplicate in file';
                    $editableIssueFields[] = OngoingStudentSpreadsheetParser::FieldStudentNumber;
                } else {
                    $seenBatchNumbers[$studentNumberKey] = $sourceRow;
                }

                if ($firstName === '') {
                    $errors[] = 'Missing first name.';
                    $issueCategories[] = 'Missing name';
                    $editableIssueFields[] = OngoingStudentSpreadsheetParser::FieldFirstName;
                }

                if ($lastName === '') {
                    $errors[] = 'Missing last name.';
                    $issueCategories[] = 'Missing name';
                    $editableIssueFields[] = OngoingStudentSpreadsheetParser::FieldLastName;
                }

                if ($email === '') {
                    $errors[] = 'Missing email address.';
                    $issueCategories[] = 'Missing email';
                    $editableIssueFields[] = OngoingStudentSpreadsheetParser::FieldEmail;
                } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $errors[] = "Invalid email format ({$email}).";
                    $issueCategories[] = 'Invalid email';
                    $editableIssueFields[] = OngoingStudentSpreadsheetParser::FieldEmail;
                } elseif (isset($seenBatchEmails[$emailKey])) {
                    $firstRow = $seenBatchEmails[$emailKey];
                    $errors[] = "Duplicate email ({$email}) in this file (first seen on row {$firstRow}).";
                    $issueCategories[] = 'Duplicate in file';
                    $editableIssueFields[] = OngoingStudentSpreadsheetParser::FieldEmail;
                } else {
                    $seenBatchEmails[$emailKey] = $sourceRow;
                }

                if ($programCode === '') {
                    $errors[] = 'Missing program code.';
                    $issueCategories[] = 'Missing program';
                    $editableIssueFields[] = OngoingStudentSpreadsheetParser::FieldProgramCode;
                } else {
                    $program = $programsByCode[strtoupper($programCode)] ?? null;
                    if (! $program instanceof Program) {
                        $errors[] = "Program code \"{$programCode}\" was not found.";
                        $issueCategories[] = 'Unknown program';
                        $editableIssueFields[] = OngoingStudentSpreadsheetParser::FieldProgramCode;
                    }
                }

                if ($program instanceof Program) {
                    $curriculumResolution = $this->resolveCurriculumVersion($program, $curriculumCode);
                    $curriculumVersion = $curriculumResolution['curriculum'];

                    if ($curriculumResolution['error'] !== null) {
                        $errors[] = $curriculumResolution['error'];
                        $issueCategories[] = 'Curriculum issue';
                        $editableIssueFields[] = OngoingStudentSpreadsheetParser::FieldCurriculumVersionCode;
                    }
                }

                if ($entryTermCode !== null && $entryTermCode !== '') {
                    $entryTerm = $this->resolveTermFromSingleToken($terms, $entryTermCode);
                    if (! $entryTerm instanceof Term) {
                        $warnings[] = "Entry term \"{$entryTermCode}\" was not matched to a TALA term and will be left blank.";
                    }
                }

                if ($row['birth_date'] !== null && $row['birth_date'] !== '') {
                    $parsedDate = $this->validateBirthDate((string) $row['birth_date']);
                    if ($parsedDate === null) {
                        $errors[] = "Invalid birth date \"{$row['birth_date']}\". Use YYYY-MM-DD format.";
                        $issueCategories[] = 'Invalid birth date';
                        $editableIssueFields[] = OngoingStudentSpreadsheetParser::FieldBirthDate;
                    } else {
                        $row['birth_date'] = $parsedDate;
                    }
                }

                $inlineItems = is_array($row['inline_prior_credits'] ?? null)
                    ? $row['inline_prior_credits']
                    : OngoingStudentSpreadsheetParser::parseInlinePriorSubjects($row['prior_completed_subjects'] ?? null);

                foreach ($inlineItems as $inlineItem) {
                    $subjectCode = (string) ($inlineItem['subject_code'] ?? '');
                    $normSubjectCode = (string) ($inlineItem['normalized_subject_code'] ?? '');
                    $course = $coursesByDisplayCode[$subjectCode] ?? $coursesByMatchCode[$normSubjectCode] ?? null;

                    if (! $course instanceof Course) {
                        $errors[] = "Unknown subject code \"{$subjectCode}\" in Prior Completed Subjects.";
                        $issueCategories[] = 'Invalid prior subject';

                        continue;
                    }

                    $activeSpec = $this->resolveActiveCourseSpecification($course, $curriculumVersion?->id, $curriculumEntriesBySpecByVersion);
                    if (! $activeSpec instanceof CourseSpecification) {
                        $errors[] = "Subject \"{$course->code}\" has no active specification for Prior Completed Subjects.";
                        $issueCategories[] = 'Invalid prior subject';

                        continue;
                    }

                    $gradeCheck = $this->validateGradeAndStatus(
                        rawGrade: $inlineItem['final_grade'] ?? null,
                        rawStatus: null,
                    );

                    if ($gradeCheck['error'] !== null) {
                        $errors[] = "Invalid grade for {$course->code} in Prior Completed Subjects: {$gradeCheck['error']}";
                        $issueCategories[] = 'Invalid prior grade';

                        continue;
                    }

                    $resolvedInlineCredits[] = [
                        'course_specification_id' => (int) $activeSpec->id,
                        'subject_code' => (string) $course->code,
                        'academic_year_label' => 'Prior Institutional Record',
                        'term_label' => null,
                        'year_level' => null,
                        'final_grade' => $gradeCheck['final_grade'],
                        'result_status' => $gradeCheck['result_status'],
                    ];
                }
            }

            $warnings = array_values(array_unique($warnings));
            $errors = array_values(array_unique($errors));
            $issueCategories = array_values(array_unique($issueCategories));
            $editableIssueFields = array_values(array_unique($editableIssueFields));

            $issueBadge = null;

            if ($isSkipped) {
                $status = self::StatusSkipped;
                $statusLabel = 'Skipped';
                $skippedCount++;
            } elseif ($alreadyOnboardedReason !== null) {
                $status = self::StatusAlreadyOnboarded;
                $statusLabel = 'Already onboarded';
                $issueBadge = 'Already onboarded';
                $alreadyOnboardedCount++;
            } elseif ($errors !== []) {
                $status = self::StatusBlocked;
                $issueBadge = count($issueCategories) > 1
                    ? 'Multiple issues'
                    : ($issueCategories[0] ?? 'Fix required');
                $statusLabel = $issueBadge;
                $blockedCount++;
            } else {
                $status = self::StatusReady;
                $statusLabel = 'Ready to onboard';
                $readyNewCount++;

                $readyStudentsByNumber[$studentNumberKey] = [
                    'row_key' => $rowKey,
                    'student_number' => $studentNumber,
                    'curriculum_version_id' => $curriculumVersion?->id,
                ];
            }

            if (! $isSkipped && $alreadyOnboardedReason === null && $warnings !== []) {
                $warningsCount++;
            }

            $studentName = collect([$firstName, $middleName, $lastName])->filter()->implode(' ');

            $previewRows[] = [
                'row_key' => $rowKey,
                'source_row' => $sourceRow,
                'sheet' => 'Students',
                'student_number' => $studentNumber,
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'last_name' => $lastName,
                'student_name' => $studentName,
                'email' => $email,
                'program_code' => $program instanceof Program ? (string) $program->code : $programCode,
                'program_id' => $program?->id !== null ? (int) $program->id : null,
                'curriculum_version_code' => $curriculumVersion instanceof CurriculumVersion
                    ? (string) $curriculumVersion->version_code
                    : $curriculumCode,
                'curriculum_version_id' => $curriculumVersion?->id !== null ? (int) $curriculumVersion->id : null,
                'entry_term_code' => $entryTermCode,
                'entry_term_id' => $entryTerm?->id !== null ? (int) $entryTerm->id : null,
                'academic_standing' => $this->normalizeStandingLabel($row['academic_standing']),
                'lifecycle_status' => $this->normalizeLifecycleCode($row['lifecycle_status']),
                'birth_date' => $row['birth_date'],
                'phone' => $row['phone'],
                'address' => $row['address'],
                'prior_identifier' => $row['prior_identifier'],
                'financial_hold_note' => $row['financial_hold_note'],
                'prior_completed_subjects' => $row['prior_completed_subjects'],
                'inline_prior_credits' => $row['inline_prior_credits'] ?? [],
                'resolved_inline_prior_credits' => $resolvedInlineCredits,
                'matched_student_profile_id' => $matchedExistingProfile?->id !== null ? (int) $matchedExistingProfile->id : null,
                'already_onboarded_reason' => $alreadyOnboardedReason,
                'skip' => $isSkipped,
                'status' => $status,
                'status_label' => $statusLabel,
                'issue_badge' => $issueBadge,
                'issue_categories' => $issueCategories,
                'editable_issue_fields' => $editableIssueFields,
                'has_warnings' => $warnings !== [],
                'warnings' => $warnings,
                'errors' => $errors,
            ];
        }

        // Validate Sheet 2 (`Prior Subjects`) rows against ready Sheet 1 rows OR existing DB profiles.
        $previewPriorSubjects = [];
        $priorReadyCount = 0;
        $priorBlockedCount = 0;

        foreach ($parsedPriorSubjects as $index => $priorRow) {
            $sourceRow = (int) ($priorRow['source_row'] ?? ($index + 1));
            $rowKey = (string) ($priorRow['row_key'] ?? ('prior_row_'.$sourceRow));
            $studentNumber = trim((string) ($priorRow['student_number'] ?? ''));
            $studentNumberKey = strtoupper($studentNumber);
            $rawSubjectCode = trim((string) ($priorRow['subject_code'] ?? ''));
            $displaySubjectCode = $rawSubjectCode !== '' ? CurriculumSpreadsheetParser::normalizeDisplayCode($rawSubjectCode) : '';
            $matchSubjectCode = $rawSubjectCode !== '' ? CurriculumSpreadsheetParser::normalizeMatchCode($rawSubjectCode) : '';

            $errors = [];
            $warnings = [];
            $targetType = null; // 'batch' or 'existing'
            $targetProfileId = null;
            $targetCurriculumVersionId = null;

            if ($studentNumber === '') {
                $errors[] = 'Missing student number.';
            } elseif (isset($readyStudentsByNumber[$studentNumberKey])) {
                $targetType = 'batch';
                $targetCurriculumVersionId = $readyStudentsByNumber[$studentNumberKey]['curriculum_version_id'];
            } elseif (isset($existingProfilesByNumber[$studentNumberKey])) {
                $existingProfile = $existingProfilesByNumber[$studentNumberKey];
                $targetType = 'existing';
                $targetProfileId = (int) $existingProfile->id;
                $targetCurriculumVersionId = $existingProfile->curriculum_version_id !== null
                    ? (int) $existingProfile->curriculum_version_id
                    : null;
            } else {
                $errors[] = "Unknown student number \"{$studentNumber}\": not found in ready Sheet 1 rows or existing student profiles.";
            }

            $course = null;
            $courseSpec = null;

            if ($displaySubjectCode === '' || $matchSubjectCode === '') {
                $errors[] = 'Missing subject code.';
            } else {
                $course = $coursesByDisplayCode[$displaySubjectCode] ?? $coursesByMatchCode[$matchSubjectCode] ?? null;

                if (! $course instanceof Course) {
                    $errors[] = "Unknown subject code \"{$displaySubjectCode}\".";
                } else {
                    $courseSpec = $this->resolveActiveCourseSpecification($course, $targetCurriculumVersionId, $curriculumEntriesBySpecByVersion);
                    if (! $courseSpec instanceof CourseSpecification) {
                        $errors[] = "Subject \"{$course->code}\" has no active specification.";
                    }
                }
            }

            $yearLevel = $priorRow['year_level'] ?? null;
            if ($yearLevel !== null && $yearLevel !== '') {
                $parsedYear = CurriculumSpreadsheetParser::parseYearLevel($yearLevel);
                if ($parsedYear === null) {
                    $errors[] = 'Year level must be between 1 and 8.';
                    $yearLevel = null;
                } else {
                    $yearLevel = $parsedYear;
                }
            } elseif (filled($priorRow['raw_year_level'] ?? null)) {
                $errors[] = "Invalid year level \"{$priorRow['raw_year_level']}\".";
                $yearLevel = null;
            } else {
                $yearLevel = null;
            }

            $gradeCheck = $this->validateGradeAndStatus(
                rawGrade: $priorRow['final_grade'] ?? null,
                rawStatus: $priorRow['result_status'] ?? null,
            );
            if ($gradeCheck['error'] !== null) {
                $errors[] = $gradeCheck['error'];
            }

            $ayInput = filled($priorRow['academic_year'] ?? null) ? trim((string) $priorRow['academic_year']) : null;
            $termInput = filled($priorRow['term'] ?? null) ? trim((string) $priorRow['term']) : null;
            $matchedTerm = $this->resolveTermFromYearAndLabel($terms, $ayInput, $termInput);

            $errors = array_values(array_unique($errors));

            if ($errors !== []) {
                $status = self::StatusBlocked;
                $statusLabel = 'Blocked';
                $priorBlockedCount++;
            } else {
                $status = self::StatusReady;
                $statusLabel = 'Ready';
                $priorReadyCount++;
            }

            $previewPriorSubjects[] = [
                'row_key' => $rowKey,
                'source_row' => $sourceRow,
                'sheet' => 'Prior Subjects',
                'student_number' => $studentNumber,
                'subject_code' => $course instanceof Course ? (string) $course->code : $displaySubjectCode,
                'normalized_subject_code' => $matchSubjectCode,
                'course_specification_id' => $courseSpec?->id !== null ? (int) $courseSpec->id : null,
                'target_type' => $targetType,
                'student_profile_id' => $targetProfileId,
                'term_id' => $matchedTerm?->id !== null ? (int) $matchedTerm->id : null,
                'academic_year_label' => $ayInput ?? ($matchedTerm?->academicYear?->label ? (string) $matchedTerm->academicYear->label : 'Prior Institutional Record'),
                'term_label' => $termInput ?? ($matchedTerm instanceof Term ? (string) $matchedTerm->label : null),
                'year_level' => $yearLevel,
                'final_grade' => $gradeCheck['final_grade'],
                'result_status' => $gradeCheck['result_status'],
                'source_school' => filled($priorRow['source_school'] ?? null) ? trim((string) $priorRow['source_school']) : null,
                'status' => $status,
                'status_label' => $statusLabel,
                'warnings' => $warnings,
                'errors' => $errors,
            ];
        }

        return [
            'summary' => [
                'total' => count($previewRows),
                'ready_new' => $readyNewCount,
                'already_onboarded' => $alreadyOnboardedCount,
                'blocked_count' => $blockedCount,
                'skipped_count' => $skippedCount,
                'warnings_count' => $warningsCount,
                'prior_subjects_total' => count($previewPriorSubjects),
                'prior_subjects_ready' => $priorReadyCount,
                'prior_subjects_blocked' => $priorBlockedCount,
            ],
            'rows' => $previewRows,
            'prior_subjects' => $previewPriorSubjects,
        ];
    }

    /**
     * Commit validated student onboarding rows and prior academic credit rows.
     *
     * @param  array<int|string, mixed>  $previewRows
     * @param  list<array<string, mixed>>  $previewPriorSubjects
     * @return array{
     *     created_students: int,
     *     skipped_existing_students: int,
     *     prior_credits_recorded: int,
     *     activation_emails_sent: int,
     *     activation_emails_failed: int,
     *     skipped_rows: list<array<string, mixed>>
     * }
     */
    public function commitImport(
        User $actor,
        array $previewRows,
        array $previewPriorSubjects = [],
        bool $sendActivationEmails = true,
        bool $skipBlocked = true,
    ): array {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only an authorized Registrar may import ongoing students.');
        }

        if (isset($previewRows['rows']) && is_array($previewRows['rows'])) {
            if ($previewPriorSubjects === [] && isset($previewRows['prior_subjects']) && is_array($previewRows['prior_subjects'])) {
                $previewPriorSubjects = $previewRows['prior_subjects'];
            }
            $previewRows = $previewRows['rows'];
        }

        $skippedRows = [];
        $readyStudents = [];

        foreach ($previewRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $status = (string) ($row['status'] ?? '');

            if ($status === self::StatusAlreadyOnboarded) {
                $skippedRows[] = [
                    ...$row,
                    'sheet' => 'Students',
                    'skip_reason' => (string) ($row['already_onboarded_reason'] ?? 'Already onboarded in TALA.'),
                ];

                continue;
            }

            if ($status === self::StatusSkipped || ($row['skip'] ?? false) === true) {
                $skippedRows[] = [
                    ...$row,
                    'sheet' => 'Students',
                    'skip_reason' => 'Skipped by user.',
                ];

                continue;
            }

            if ($status === self::StatusBlocked || ! empty($row['errors'])) {
                if (! $skipBlocked) {
                    throw ValidationException::withMessages([
                        'rows' => 'Resolve or skip all blocked student rows before importing: '.implode(' ', (array) ($row['errors'] ?? [])),
                    ]);
                }

                $skippedRows[] = [
                    ...$row,
                    'sheet' => 'Students',
                    'skip_reason' => implode(' ', (array) ($row['errors'] ?? [])) ?: 'Blocked',
                ];

                continue;
            }

            $readyStudents[] = $row;
        }

        /** @var array<string, list<array<string, mixed>>> $batchPriorByStudentNumber */
        $batchPriorByStudentNumber = [];
        /** @var array<int, list<array<string, mixed>>> $existingPriorByProfileId */
        $existingPriorByProfileId = [];

        foreach ($previewPriorSubjects as $priorRow) {
            if (! is_array($priorRow)) {
                continue;
            }

            $priorStatus = (string) ($priorRow['status'] ?? '');
            if ($priorStatus === self::StatusBlocked || ! empty($priorRow['errors'])) {
                if (! $skipBlocked) {
                    throw ValidationException::withMessages([
                        'prior_subjects' => 'Resolve or skip all blocked prior subject rows before importing: '.implode(' ', (array) ($priorRow['errors'] ?? [])),
                    ]);
                }

                $skippedRows[] = [
                    ...$priorRow,
                    'sheet' => 'Prior Subjects',
                    'skip_reason' => implode(' ', (array) ($priorRow['errors'] ?? [])) ?: 'Blocked',
                ];

                continue;
            }

            if ($priorStatus === self::StatusSkipped) {
                $skippedRows[] = [
                    ...$priorRow,
                    'sheet' => 'Prior Subjects',
                    'skip_reason' => 'Skipped by user.',
                ];

                continue;
            }

            $specId = isset($priorRow['course_specification_id']) ? (int) $priorRow['course_specification_id'] : 0;
            if ($specId <= 0) {
                continue;
            }

            $creditItem = [
                'course_specification_id' => $specId,
                'term_id' => $priorRow['term_id'] ?? null,
                'academic_year_label' => $priorRow['academic_year_label'] ?? null,
                'term_label' => $priorRow['term_label'] ?? null,
                'year_level' => $priorRow['year_level'] ?? null,
                'final_grade' => $priorRow['final_grade'] ?? null,
                'result_status' => $priorRow['result_status'] ?? null,
                'source_school' => $priorRow['source_school'] ?? null,
            ];

            if (($priorRow['target_type'] ?? null) === 'existing' && filled($priorRow['student_profile_id'] ?? null)) {
                $profileId = (int) $priorRow['student_profile_id'];
                $existingPriorByProfileId[$profileId][] = $creditItem;
            } else {
                $numKey = strtoupper(trim((string) ($priorRow['student_number'] ?? '')));
                if ($numKey !== '') {
                    $batchPriorByStudentNumber[$numKey][] = $creditItem;
                }
            }
        }

        $createdStudents = 0;
        $skippedExistingStudents = count(array_filter(
            $skippedRows,
            fn (array $row): bool => ($row['status'] ?? null) === self::StatusAlreadyOnboarded,
        ));
        $priorCreditsRecorded = 0;
        $activationEmailsSent = 0;
        $activationEmailsFailed = 0;

        foreach ($readyStudents as $studentRow) {
            $studentNumber = trim((string) ($studentRow['student_number'] ?? ''));
            $studentNumberKey = strtoupper($studentNumber);
            $email = strtolower(trim((string) ($studentRow['email'] ?? '')));

            // Re-verify Decision 2 idempotency right before write.
            $existingByNumber = $studentNumber !== ''
                ? StudentProfile::query()->where('student_number', $studentNumber)->first()
                : null;
            $existingUserByEmail = $email !== ''
                ? User::query()->whereRaw('LOWER(email) = ?', [$email])->first()
                : null;

            if ($existingByNumber instanceof StudentProfile || $existingUserByEmail instanceof User) {
                $skippedExistingStudents++;
                $skippedRows[] = [
                    ...$studentRow,
                    'sheet' => 'Students',
                    'status' => self::StatusAlreadyOnboarded,
                    'skip_reason' => $existingByNumber instanceof StudentProfile
                        ? "Student number {$studentNumber} already exists in TALA."
                        : "Email {$email} is already registered in TALA.",
                ];

                if ($existingByNumber instanceof StudentProfile && isset($batchPriorByStudentNumber[$studentNumberKey])) {
                    $existingPriorByProfileId[$existingByNumber->id] = array_merge(
                        $existingPriorByProfileId[$existingByNumber->id] ?? [],
                        $batchPriorByStudentNumber[$studentNumberKey],
                    );
                    unset($batchPriorByStudentNumber[$studentNumberKey]);
                }

                continue;
            }

            $inlineCredits = is_array($studentRow['resolved_inline_prior_credits'] ?? null)
                ? $studentRow['resolved_inline_prior_credits']
                : [];
            $sheetTwoCredits = $batchPriorByStudentNumber[$studentNumberKey] ?? [];
            $combinedCredits = array_values(array_merge($inlineCredits, $sheetTwoCredits));

            try {
                $result = $this->onboardStudent->execute([
                    'student_number' => $studentNumber,
                    'first_name' => $studentRow['first_name'],
                    'middle_name' => $studentRow['middle_name'] ?? null,
                    'last_name' => $studentRow['last_name'],
                    'email' => $email,
                    'program_id' => (int) $studentRow['program_id'],
                    'curriculum_version_id' => $studentRow['curriculum_version_id'] ?? null,
                    'entry_term_id' => $studentRow['entry_term_id'] ?? null,
                    'academic_standing' => $studentRow['academic_standing'] ?? StudentProfile::StandingRegular,
                    'lifecycle_status' => $studentRow['lifecycle_status'] ?? StudentProfile::LifecycleActive,
                    'birth_date' => $studentRow['birth_date'] ?? null,
                    'phone' => $studentRow['phone'] ?? null,
                    'address' => $studentRow['address'] ?? null,
                    'prior_identifier' => $studentRow['prior_identifier'] ?? null,
                    'financial_hold_note' => $studentRow['financial_hold_note'] ?? null,
                    'prior_credits' => $combinedCredits,
                    'send_activation_email' => $sendActivationEmails,
                ], $actor);
            } catch (ValidationException $exception) {
                if (! $skipBlocked) {
                    throw $exception;
                }

                $skippedRows[] = [
                    ...$studentRow,
                    'sheet' => 'Students',
                    'status' => self::StatusBlocked,
                    'skip_reason' => collect($exception->errors())->flatten()->implode(' '),
                ];

                continue;
            }

            $createdStudents++;
            $priorCreditsRecorded += (int) $result['prior_credits_created'];

            if ($sendActivationEmails) {
                if ($result['activation_sent']) {
                    $activationEmailsSent++;
                } else {
                    $activationEmailsFailed++;
                }
            }
        }

        if ($existingPriorByProfileId !== []) {
            $profiles = StudentProfile::query()
                ->whereIn('id', array_keys($existingPriorByProfileId))
                ->get()
                ->keyBy('id');

            foreach ($existingPriorByProfileId as $profileId => $creditItems) {
                $profile = $profiles->get($profileId);
                if (! $profile instanceof StudentProfile) {
                    continue;
                }

                $appendResult = $this->priorCredits->appendCredits($profile, $creditItems, $actor);
                $priorCreditsRecorded += (int) $appendResult['created'];
            }
        }

        activity('student_onboarding')
            ->causedBy($actor)
            ->event('ongoing_students_spreadsheet_imported')
            ->withProperties([
                'created_students' => $createdStudents,
                'skipped_existing_students' => $skippedExistingStudents,
                'prior_credits_recorded' => $priorCreditsRecorded,
                'activation_emails_sent' => $activationEmailsSent,
                'activation_emails_failed' => $activationEmailsFailed,
                'skipped_rows_count' => count($skippedRows),
            ])
            ->log("Imported {$createdStudents} ongoing student(s) from spreadsheet");

        return [
            'created_students' => $createdStudents,
            'skipped_existing_students' => $skippedExistingStudents,
            'prior_credits_recorded' => $priorCreditsRecorded,
            'activation_emails_sent' => $activationEmailsSent,
            'activation_emails_failed' => $activationEmailsFailed,
            'skipped_rows' => $skippedRows,
        ];
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
    private function applyStudentOverride(array $rawRow, array $override, string $rowKey, int $sourceRow): array
    {
        $pick = function (string $field, mixed $default = null) use ($rawRow, $override): mixed {
            if (array_key_exists($field, $override)) {
                return $override[$field];
            }

            return $rawRow[$field] ?? $default;
        };

        $cleanText = fn (mixed $value): string => trim((string) (preg_replace('/\s+/', ' ', (string) ($value ?? '')) ?? ''));
        $nullableText = function (mixed $value) use ($cleanText): ?string {
            $cleaned = $cleanText($value);

            return $cleaned !== '' ? $cleaned : null;
        };

        $priorRaw = array_key_exists('prior_completed_subjects', $override)
            ? $nullableText($override['prior_completed_subjects'])
            : $nullableText($rawRow['prior_completed_subjects'] ?? null);

        $inlineCredits = array_key_exists('prior_completed_subjects', $override)
            ? OngoingStudentSpreadsheetParser::parseInlinePriorSubjects($priorRaw)
            : ($rawRow['inline_prior_credits'] ?? OngoingStudentSpreadsheetParser::parseInlinePriorSubjects($priorRaw));

        return [
            'row_key' => $rowKey,
            'source_row' => $sourceRow,
            'student_number' => $cleanText($pick('student_number', '')),
            'first_name' => $cleanText($pick('first_name', '')),
            'middle_name' => $nullableText($pick('middle_name')),
            'last_name' => $cleanText($pick('last_name', '')),
            'email' => strtolower($cleanText($pick('email', ''))),
            'program_code' => strtoupper($cleanText($pick('program_code', ''))),
            'curriculum_version_code' => $nullableText($pick('curriculum_version_code')),
            'entry_term_code' => $nullableText($pick('entry_term_code')),
            'academic_standing' => $nullableText($pick('academic_standing')),
            'lifecycle_status' => $nullableText($pick('lifecycle_status')),
            'birth_date' => $nullableText($pick('birth_date')),
            'phone' => $nullableText($pick('phone')),
            'address' => filled($pick('address')) ? trim((string) $pick('address')) : null,
            'prior_identifier' => $nullableText($pick('prior_identifier')),
            'financial_hold_note' => filled($pick('financial_hold_note')) ? trim((string) $pick('financial_hold_note')) : null,
            'prior_completed_subjects' => $priorRaw,
            'inline_prior_credits' => $inlineCredits,
            'skip' => array_key_exists('skip', $override)
                ? (bool) $override['skip']
                : (bool) ($rawRow['skip'] ?? false),
        ];
    }

    /**
     * @return array{curriculum: ?CurriculumVersion, error: ?string}
     */
    private function resolveCurriculumVersion(Program $program, ?string $curriculumCode): array
    {
        $versions = $program->relationLoaded('curriculumVersions')
            ? $program->curriculumVersions
            : $program->curriculumVersions()
                ->whereIn('state', [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded])
                ->orderByRaw("CASE WHEN state = 'ACTIVE' THEN 0 ELSE 1 END")
                ->orderByDesc('id')
                ->get();

        if ($curriculumCode !== null && trim($curriculumCode) !== '') {
            $target = strtolower(trim($curriculumCode));

            $matched = $versions->first(
                fn (CurriculumVersion $cv): bool => strtolower(trim((string) $cv->version_code)) === $target
                    || strtolower(trim((string) $cv->name)) === $target,
            );

            if ($matched instanceof CurriculumVersion) {
                return ['curriculum' => $matched, 'error' => null];
            }

            return [
                'curriculum' => null,
                'error' => "Curriculum version \"{$curriculumCode}\" was not found (or is not Active/Retired) for program {$program->code}.",
            ];
        }

        $active = $versions->where('state', CurriculumVersion::StateActive)->values();

        if ($active->count() === 1) {
            return ['curriculum' => $active->first(), 'error' => null];
        }

        if ($active->isEmpty()) {
            return [
                'curriculum' => null,
                'error' => "Program {$program->code} has no active curriculum version yet. Specify a Curriculum Version Code.",
            ];
        }

        return [
            'curriculum' => null,
            'error' => "Program {$program->code} has multiple active curriculum versions. Specify which Curriculum Version Code to assign.",
        ];
    }

    /**
     * @param  Collection<int, Term>  $terms
     */
    private function resolveTermFromSingleToken(Collection $terms, string $rawToken): ?Term
    {
        $clean = strtolower(trim((string) preg_replace('/\s+/', ' ', $rawToken)));
        if ($clean === '') {
            return null;
        }

        $normalizedNoAy = $this->stripAyPrefix($clean);

        foreach ($terms as $term) {
            if ((string) $term->id === $clean) {
                return $term;
            }

            $ayLabel = strtolower(trim((string) ($term->academicYear?->label ?? '')));
            $termLabel = strtolower(trim((string) $term->label));

            $candidates = array_filter([
                $ayLabel !== '' ? "{$ayLabel} - {$termLabel}" : null,
                $ayLabel !== '' ? "{$ayLabel} | {$termLabel}" : null,
                $ayLabel !== '' ? "{$ayLabel} {$termLabel}" : null,
                $ayLabel !== '' ? $this->stripAyPrefix("{$ayLabel} - {$termLabel}") : null,
                $ayLabel !== '' ? $this->stripAyPrefix("{$ayLabel} {$termLabel}") : null,
            ]);

            foreach ($candidates as $candidate) {
                if ($clean === $candidate || $normalizedNoAy === $candidate) {
                    return $term;
                }
            }
        }

        $labelMatches = $terms->filter(
            fn (Term $term): bool => strtolower(trim((string) $term->label)) === $clean,
        );

        return $labelMatches->count() === 1 ? $labelMatches->first() : null;
    }

    /**
     * @param  Collection<int, Term>  $terms
     */
    private function resolveTermFromYearAndLabel(Collection $terms, ?string $academicYear, ?string $termLabel): ?Term
    {
        if ($academicYear === null && $termLabel === null) {
            return null;
        }

        if ($academicYear === null && $termLabel !== null) {
            return $this->resolveTermFromSingleToken($terms, $termLabel);
        }

        $ayNorm = $this->stripAyPrefix(strtolower(trim((string) $academicYear)));
        $termClean = strtolower(trim((string) ($termLabel ?? '')));
        $parsedTermType = $termClean !== '' ? CurriculumSpreadsheetParser::parseTermType($termClean) : null;

        $matches = $terms->filter(function (Term $term) use ($ayNorm, $termClean, $parsedTermType): bool {
            $modelAy = $this->stripAyPrefix(strtolower(trim((string) ($term->academicYear?->label ?? ''))));
            if ($modelAy === '' || $modelAy !== $ayNorm) {
                return false;
            }

            if ($termClean === '') {
                return false;
            }

            if (strtolower(trim((string) $term->label)) === $termClean) {
                return true;
            }

            return $parsedTermType !== null && $term->type === $parsedTermType;
        });

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function stripAyPrefix(string $text): string
    {
        return trim((string) preg_replace('/^(?:a\.?y\.?|s\.?y\.?)\s*/i', '', $text));
    }

    /**
     * @param  array<int, array<int, CurriculumEntry>>  $curriculumEntriesBySpecByVersion
     */
    private function resolveActiveCourseSpecification(
        Course $course,
        ?int $curriculumVersionId,
        array &$curriculumEntriesBySpecByVersion,
    ): ?CourseSpecification {
        $specs = $course->relationLoaded('specifications')
            ? $course->specifications
            : $course->specifications()->get();

        if ($curriculumVersionId !== null) {
            if (! isset($curriculumEntriesBySpecByVersion[$curriculumVersionId])) {
                $curriculumEntriesBySpecByVersion[$curriculumVersionId] = CurriculumEntry::query()
                    ->with('courseSpecification')
                    ->where('curriculum_version_id', $curriculumVersionId)
                    ->get()
                    ->keyBy('course_specification_id')
                    ->all();
            }

            foreach ($specs as $spec) {
                if (isset($curriculumEntriesBySpecByVersion[$curriculumVersionId][(int) $spec->id])
                    && in_array($spec->state, [CourseSpecification::StateActive, CourseSpecification::StateDraft], true)) {
                    return $spec;
                }
            }
        }

        return $specs->firstWhere('state', CourseSpecification::StateActive);
    }

    /**
     * Validate grade (`1.00`-`5.00` or `CREDIT`/`TC`/`INC`/`DRP`/`W`) and result status.
     *
     * @return array{final_grade: ?string, result_status: string, error: ?string}
     */
    private function validateGradeAndStatus(?string $rawGrade, ?string $rawStatus): array
    {
        $grade = trim((string) ($rawGrade ?? ''));
        $status = strtolower(trim((string) ($rawStatus ?? '')));

        $allowedStatuses = PriorAcademicCredit::resultStatuses();
        if ($status !== '' && ! in_array($status, $allowedStatuses, true)) {
            return [
                'final_grade' => null,
                'result_status' => PriorAcademicCredit::ResultPassed,
                'error' => "Invalid result status \"{$rawStatus}\". Allowed: ".implode(', ', $allowedStatuses).'.',
            ];
        }

        $normalizedGrade = null;

        if ($grade !== '') {
            $upperGrade = strtoupper($grade);

            if (in_array($upperGrade, ['CREDIT', 'TC', 'INC', 'DRP', 'W'], true)) {
                $normalizedGrade = $upperGrade;
            } elseif (is_numeric($grade)) {
                $numeric = (float) $grade;
                if ($numeric < 1.00 || $numeric > 5.00) {
                    return [
                        'final_grade' => null,
                        'result_status' => PriorAcademicCredit::ResultPassed,
                        'error' => "Final grade \"{$grade}\" must be between 1.00 and 5.00, or CREDIT/TC/INC/DRP/W.",
                    ];
                }
                $normalizedGrade = number_format($numeric, 2, '.', '');
            } else {
                return [
                    'final_grade' => null,
                    'result_status' => PriorAcademicCredit::ResultPassed,
                    'error' => "Invalid final grade \"{$grade}\". Use 1.00-5.00 or CREDIT/TC/INC/DRP/W.",
                ];
            }
        }

        if ($status === '') {
            if ($normalizedGrade === null) {
                $status = PriorAcademicCredit::ResultCredited;
                $normalizedGrade = 'CREDIT';
            } elseif (in_array($normalizedGrade, ['CREDIT', 'TC'], true)) {
                $status = PriorAcademicCredit::ResultCredited;
            } elseif ($normalizedGrade === 'INC') {
                $status = PriorAcademicCredit::ResultInc;
            } elseif (in_array($normalizedGrade, ['DRP', 'W'], true)) {
                $status = PriorAcademicCredit::ResultDropped;
            } elseif (is_numeric($normalizedGrade)) {
                $status = (float) $normalizedGrade <= 4.00
                    ? PriorAcademicCredit::ResultPassed
                    : PriorAcademicCredit::ResultFailed;
            }
        } elseif ($normalizedGrade === null) {
            $normalizedGrade = match ($status) {
                PriorAcademicCredit::ResultCredited => 'CREDIT',
                PriorAcademicCredit::ResultInc => 'INC',
                PriorAcademicCredit::ResultDropped => 'DRP',
                PriorAcademicCredit::ResultFailed => '5.00',
                default => 'CREDIT',
            };
        }

        return [
            'final_grade' => $normalizedGrade,
            'result_status' => $status,
            'error' => null,
        ];
    }

    private function validateBirthDate(string $raw): ?string
    {
        $clean = trim($raw);
        if ($clean === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::parse($clean);

            return $date->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeStandingLabel(?string $raw): string
    {
        $clean = strtolower(trim((string) ($raw ?? '')));

        return match ($clean) {
            '', 'regular' => StudentProfile::StandingRegular,
            'irregular' => StudentProfile::StandingIrregular,
            'probationary' => StudentProfile::StandingProbationary,
            'deficient' => StudentProfile::StandingDeficient,
            default => StudentProfile::StandingRegular,
        };
    }

    private function normalizeLifecycleCode(?string $raw): string
    {
        $clean = strtoupper(str_replace([' ', '-'], '_', trim((string) ($raw ?? ''))));

        return match ($clean) {
            '', 'ACTIVE' => StudentProfile::LifecycleActive,
            'LEAVE_OF_ABSENCE', 'LOA' => StudentProfile::LifecycleLeaveOfAbsence,
            'INACTIVE' => StudentProfile::LifecycleInactive,
            'WITHDRAWN' => StudentProfile::LifecycleWithdrawn,
            'TRANSFERRED_OUT' => StudentProfile::LifecycleTransferredOut,
            default => StudentProfile::LifecycleActive,
        };
    }
}
