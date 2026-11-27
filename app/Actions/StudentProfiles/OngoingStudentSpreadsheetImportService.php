<?php

namespace App\Actions\StudentProfiles;

use App\Models\AcademicYear;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\Hold;
use App\Models\Program;
use App\Models\StudentImportBatch;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class OngoingStudentSpreadsheetImportService
{
    public const StatusReady = 'ready';

    public const StatusUpdateUnactivated = 'update_unactivated';

    public const StatusAlreadyOnboarded = 'already_onboarded';

    public const StatusBlocked = 'blocked';

    public const StatusSkipped = 'skipped';

    public function __construct(
        private readonly OnboardExistingStudent $onboardStudent,
        private readonly RecordPriorAcademicCredits $priorCredits,
        private readonly StudentAccountActivation $activation,
    ) {}

    /**
     * Validate parsed Cohort Roster (`Students`) rows against live DB state and selected cohort scope
     * without writing any records.
     *
     * @param  list<array<string, mixed>>  $parsedStudents
     * @param  array<string|int, array<string, mixed>>  $rowOverrides
     * @param  array{
     *     program_id?: int|null,
     *     curriculum_version_id?: int|null,
     *     entry_academic_year_id?: int|null,
     *     completed_through_year_level?: int|null,
     *     completed_through_term_type?: string|null,
     *     filename?: string|null
     * }  $cohortScope
     * @return array{
     *     summary: array{
     *         total: int,
     *         ready_new: int,
     *         update_unactivated: int,
     *         already_onboarded: int,
     *         blocked_count: int,
     *         skipped_count: int,
     *         warnings_count: int,
     *         applicant_collisions_blocked: int,
     *         applicant_links_opted_in: int,
     *         estimated_prior_credits: int
     *     },
     *     rows: list<array<string, mixed>>
     * }
     */
    public function buildPreview(
        array $parsedStudents,
        array $rowOverrides = [],
        array $cohortScope = [],
    ): array {
        $indexedOverrides = $this->normalizeOverrides($rowOverrides);

        $programs = Program::query()
            ->with(['curriculumVersions' => fn ($query) => $query
                ->whereIn('state', [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded])
                ->orderByRaw("CASE WHEN state = 'ACTIVE' THEN 0 ELSE 1 END")
                ->orderByDesc('id')])
            ->get();

        /** @var array<int, Program> $programsById */
        $programsById = [];
        /** @var array<string, Program> $programsByCode */
        $programsByCode = [];
        foreach ($programs as $program) {
            $programsById[(int) $program->id] = $program;
            $codeKey = strtoupper(trim((string) $program->code));
            if ($codeKey !== '') {
                $programsByCode[$codeKey] = $program;
            }
        }

        $scopeProgramId = filled($cohortScope['program_id'] ?? null) ? (int) $cohortScope['program_id'] : null;
        $scopeProgram = $scopeProgramId !== null ? ($programsById[$scopeProgramId] ?? null) : null;
        $scopeCurriculumId = filled($cohortScope['curriculum_version_id'] ?? null) ? (int) $cohortScope['curriculum_version_id'] : null;
        $scopeEntryAcademicYearId = filled($cohortScope['entry_academic_year_id'] ?? null) ? (int) $cohortScope['entry_academic_year_id'] : null;
        $scopeCompletedYear = filled($cohortScope['completed_through_year_level'] ?? null) ? (int) $cohortScope['completed_through_year_level'] : null;
        $scopeCompletedTermType = filled($cohortScope['completed_through_term_type'] ?? null) ? trim((string) $cohortScope['completed_through_term_type']) : null;

        $terms = Term::query()->with('academicYear')->get();

        $officiallyEnrolledProfileIds = Enrollment::query()
            ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled)
            ->whereNotNull('student_profile_id')
            ->distinct()
            ->pluck('student_profile_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->flip()
            ->all();

        /** @var array<string, StudentProfile> $existingProfilesByNumber */
        $existingProfilesByNumber = [];
        /** @var array<string, StudentProfile> $existingProfilesByEmail */
        $existingProfilesByEmail = [];
        /** @var array<string, list<StudentProfile>> $existingProfilesByNameDob */
        $existingProfilesByNameDob = [];
        /** @var array<string, list<StudentProfile>> $existingProfilesByLrn */
        $existingProfilesByLrn = [];

        StudentProfile::query()
            ->with('user')
            ->whereNull('archived_at')
            ->whereNull('merged_into_id')
            ->get()
            ->each(function (StudentProfile $profile) use (
                &$existingProfilesByNumber,
                &$existingProfilesByEmail,
                &$existingProfilesByNameDob,
                &$existingProfilesByLrn,
            ): void {
                $numKey = strtoupper(trim((string) $profile->student_number));
                if ($numKey !== '') {
                    $existingProfilesByNumber[$numKey] = $profile;
                }

                $profileEmail = strtolower(trim((string) ($profile->user?->email ?? $profile->email ?? '')));
                if ($profileEmail !== '') {
                    $existingProfilesByEmail[$profileEmail] = $profile;
                }

                $nameDobKey = $this->nameDobKey(
                    (string) $profile->first_name,
                    (string) $profile->last_name,
                    $profile->birth_date?->format('Y-m-d'),
                );
                if ($nameDobKey !== null) {
                    $existingProfilesByNameDob[$nameDobKey][] = $profile;
                }

                $lrnKey = $this->normalizeIdentifierKey($profile->prior_identifier);
                if ($lrnKey !== null) {
                    $existingProfilesByLrn[$lrnKey][] = $profile;
                }
            });

        /** @var array<string, User> $existingUsersByEmail */
        $existingUsersByEmail = [];
        User::query()
            ->with(['roles', 'studentProfile'])
            ->get()
            ->each(function (User $user) use (&$existingUsersByEmail): void {
                $emailKey = strtolower(trim((string) $user->email));
                if ($emailKey !== '') {
                    $existingUsersByEmail[$emailKey] = $user;
                }
            });

        /** @var array<string, int> $quickCreditCountCache */
        $quickCreditCountCache = [];

        $seenBatchNumbers = [];
        $seenBatchEmails = [];
        $seenBatchNameDob = [];
        $seenBatchLrn = [];
        $previewRows = [];

        $readyNewCount = 0;
        $updateUnactivatedCount = 0;
        $alreadyOnboardedCount = 0;
        $blockedCount = 0;
        $skippedCount = 0;
        $warningsCount = 0;
        $applicantCollisionsBlocked = 0;
        $applicantLinksOptedIn = 0;
        $estimatedPriorCredits = 0;

        foreach ($parsedStudents as $index => $rawRow) {
            $sourceRow = (int) ($rawRow['source_row'] ?? ($index + 1));
            $rowKey = (string) ($rawRow['row_key'] ?? ('student_row_'.$sourceRow));
            $override = $indexedOverrides[$rowKey] ?? $indexedOverrides[(string) $sourceRow] ?? [];

            $row = $this->applyStudentOverride($rawRow, $override, $rowKey, $sourceRow);
            $isSkipped = (bool) $row['skip'];
            $linkExistingApplicant = (bool) $row['link_existing_applicant'];
            $studentNumber = (string) $row['student_number'];
            $studentNumberKey = strtoupper($studentNumber);
            $firstName = (string) $row['first_name'];
            $middleName = $row['middle_name'];
            $lastName = (string) $row['last_name'];
            $email = (string) $row['email'];
            $emailKey = strtolower($email);
            $rowProgramCode = $row['program_code'];
            $rowCurriculumCode = $row['curriculum_version_code'];
            $entryTermCode = $row['entry_term_code'];

            $errors = [];
            $fieldErrors = [];
            $warnings = [];
            $issueCategories = [];
            $editableIssueFields = [];

            $addFieldError = function (string $field, string $message, string $category) use (
                &$errors,
                &$fieldErrors,
                &$issueCategories,
                &$editableIssueFields,
            ): void {
                $errors[] = $message;
                $fieldErrors[$field][] = $message;
                $issueCategories[] = $category;
                $editableIssueFields[] = $field;
            };

            $matchedExistingProfile = null;
            $isUnactivatedUpsert = false;
            $alreadyOnboardedReason = null;
            $isApplicantCollision = false;
            $applicantUserId = null;
            $hasDuplicateWarning = false;

            if (! $isSkipped && $studentNumberKey !== '' && isset($existingProfilesByNumber[$studentNumberKey])) {
                $matchedExistingProfile = $existingProfilesByNumber[$studentNumberKey];
                $matchedUser = $matchedExistingProfile->user;
                $hasSignedIn = $matchedUser instanceof User && $matchedUser->last_successful_sign_in_at !== null;
                $hasOfficialEnrollment = isset($officiallyEnrolledProfileIds[(int) $matchedExistingProfile->id]);

                if (! $hasSignedIn && ! $hasOfficialEnrollment) {
                    $isUnactivatedUpsert = true;
                } else {
                    $alreadyOnboardedReason = $hasOfficialEnrollment
                        ? "Student number {$studentNumber} already has an official enrollment in TALA and will be left unchanged."
                        : "Student number {$studentNumber} has already signed in to Student Hub and will be left unchanged.";
                }
            }

            $program = null;
            $curriculumVersion = null;
            $entryTerm = null;
            $resolvedEntryAcademicYearId = $scopeEntryAcademicYearId;
            $normalizedStanding = StudentProfile::StandingRegular;
            $normalizedLifecycle = StudentProfile::LifecycleActive;
            $priorCreditsToApply = 0;

            if (! $isSkipped && $alreadyOnboardedReason === null) {
                if ($studentNumber === '') {
                    $addFieldError(OngoingStudentSpreadsheetParser::FieldStudentNumber, 'Missing student number.', 'Missing student number');
                } elseif (strlen($studentNumber) > 64) {
                    $addFieldError(OngoingStudentSpreadsheetParser::FieldStudentNumber, 'Student number cannot exceed 64 characters.', 'Invalid student number');
                } elseif (isset($seenBatchNumbers[$studentNumberKey])) {
                    $firstRow = $seenBatchNumbers[$studentNumberKey];
                    $addFieldError(OngoingStudentSpreadsheetParser::FieldStudentNumber, "Duplicate student number ({$studentNumber}) in this file (first seen on row {$firstRow}).", 'Duplicate in file');
                } else {
                    $seenBatchNumbers[$studentNumberKey] = $sourceRow;
                }

                if ($firstName === '') {
                    $addFieldError(OngoingStudentSpreadsheetParser::FieldFirstName, 'Missing first name.', 'Missing name');
                }

                if ($lastName === '') {
                    $addFieldError(OngoingStudentSpreadsheetParser::FieldLastName, 'Missing last name.', 'Missing name');
                }

                if ($email === '') {
                    $addFieldError(OngoingStudentSpreadsheetParser::FieldEmail, 'Missing email address.', 'Missing email');
                } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $addFieldError(OngoingStudentSpreadsheetParser::FieldEmail, "Invalid email format ({$email}).", 'Invalid email');
                } elseif (isset($seenBatchEmails[$emailKey])) {
                    $firstRow = $seenBatchEmails[$emailKey];
                    $addFieldError(OngoingStudentSpreadsheetParser::FieldEmail, "Duplicate email ({$email}) in this file (first seen on row {$firstRow}).", 'Duplicate in file');
                } else {
                    $seenBatchEmails[$emailKey] = $sourceRow;

                    $existingUser = $existingUsersByEmail[$emailKey] ?? null;
                    if ($existingUser instanceof User) {
                        $belongsToMatchedUnactivated = $isUnactivatedUpsert
                            && $matchedExistingProfile instanceof StudentProfile
                            && (int) $matchedExistingProfile->user_id === (int) $existingUser->id;

                        if (! $belongsToMatchedUnactivated) {
                            $otherProfile = $existingUser->studentProfile ?? ($existingProfilesByEmail[$emailKey] ?? null);
                            if ($otherProfile instanceof StudentProfile) {
                                $addFieldError(
                                    OngoingStudentSpreadsheetParser::FieldEmail,
                                    "Email {$email} is already linked to student {$otherProfile->student_number}.",
                                    'Email in use',
                                );
                            } elseif ($existingUser->isStaffCapable()) {
                                $addFieldError(
                                    OngoingStudentSpreadsheetParser::FieldEmail,
                                    "Email {$email} belongs to a staff account and cannot be used for a student.",
                                    'Staff email collision',
                                );
                            } elseif ($existingUser->hasAssignedRole('applicant')) {
                                $isApplicantCollision = true;
                                $applicantUserId = (int) $existingUser->id;

                                if (! $linkExistingApplicant) {
                                    $addFieldError(
                                        OngoingStudentSpreadsheetParser::FieldEmail,
                                        "Email {$email} belongs to an existing applicant account. Enable \"Link existing applicant account & archive draft intake\" or enter a different email.",
                                        'Existing applicant account',
                                    );
                                }
                            } else {
                                $addFieldError(
                                    OngoingStudentSpreadsheetParser::FieldEmail,
                                    "Email {$email} is already registered to another TALA account.",
                                    'Email in use',
                                );
                            }
                        }
                    }
                }

                // Resolve Program (row override or cohort scope).
                if ($rowProgramCode !== null && $rowProgramCode !== '') {
                    $program = $programsByCode[strtoupper($rowProgramCode)] ?? null;
                    if (! $program instanceof Program) {
                        $addFieldError(
                            OngoingStudentSpreadsheetParser::FieldProgramCode,
                            "Program code \"{$rowProgramCode}\" was not found.",
                            'Unknown program',
                        );
                    }
                } elseif ($scopeProgram instanceof Program) {
                    $program = $scopeProgram;
                } else {
                    $addFieldError(
                        OngoingStudentSpreadsheetParser::FieldProgramCode,
                        'Select a Program in Step 1 or provide a Program Code column.',
                        'Missing program',
                    );
                }

                if ($entryTermCode !== null && $entryTermCode !== '') {
                    $entryTerm = $this->resolveTermFromSingleToken($terms, $entryTermCode);
                    if (! $entryTerm instanceof Term) {
                        $warnings[] = "Entry term \"{$entryTermCode}\" was not matched to a TALA term and will be left blank.";
                    } elseif ($resolvedEntryAcademicYearId === null && $entryTerm->academic_year_id !== null) {
                        $resolvedEntryAcademicYearId = (int) $entryTerm->academic_year_id;
                    }
                }

                // Resolve Curriculum Version (row override -> cohort scope -> batch AY -> single active).
                if ($program instanceof Program) {
                    $preferredCurriculumId = ($scopeProgram instanceof Program && (int) $program->id === (int) $scopeProgram->id)
                        ? $scopeCurriculumId
                        : null;

                    $curriculumResolution = $this->resolveCurriculumVersion(
                        program: $program,
                        curriculumCode: $rowCurriculumCode,
                        preferredCurriculumId: $preferredCurriculumId,
                        entryAcademicYearId: $resolvedEntryAcademicYearId,
                    );
                    $curriculumVersion = $curriculumResolution['curriculum'];

                    if ($curriculumResolution['error'] !== null) {
                        $addFieldError(
                            OngoingStudentSpreadsheetParser::FieldCurriculumVersionCode,
                            $curriculumResolution['error'],
                            'Curriculum issue',
                        );
                    }
                }

                // Strict Academic Standing validation (no silent coercion).
                $standingCheck = $this->validateStanding($row['academic_standing']);
                if ($standingCheck['error'] !== null) {
                    $addFieldError(
                        OngoingStudentSpreadsheetParser::FieldAcademicStanding,
                        $standingCheck['error'],
                        'Invalid standing',
                    );
                    $normalizedStanding = (string) ($row['academic_standing'] ?? '');
                } else {
                    $normalizedStanding = $standingCheck['value'];
                }

                // Strict Lifecycle Status validation (no silent coercion).
                $lifecycleCheck = $this->validateLifecycleStatus($row['lifecycle_status']);
                if ($lifecycleCheck['error'] !== null) {
                    $addFieldError(
                        OngoingStudentSpreadsheetParser::FieldLifecycleStatus,
                        $lifecycleCheck['error'],
                        'Invalid lifecycle status',
                    );
                    $normalizedLifecycle = (string) ($row['lifecycle_status'] ?? '');
                } else {
                    $normalizedLifecycle = $lifecycleCheck['value'];
                }

                if ($row['birth_date'] !== null && $row['birth_date'] !== '') {
                    $parsedDate = $this->validateBirthDate((string) $row['birth_date']);
                    if ($parsedDate === null) {
                        $addFieldError(
                            OngoingStudentSpreadsheetParser::FieldBirthDate,
                            "Invalid birth date \"{$row['birth_date']}\". Use YYYY-MM-DD format.",
                            'Invalid birth date',
                        );
                    } else {
                        $row['birth_date'] = $parsedDate;
                    }
                }

                if ($row['phone'] !== null && strlen((string) $row['phone']) > 64) {
                    $addFieldError(
                        OngoingStudentSpreadsheetParser::FieldPhone,
                        'Phone number cannot exceed 64 characters.',
                        'Invalid phone',
                    );
                }

                if ($row['prior_identifier'] !== null && strlen((string) $row['prior_identifier']) > 255) {
                    $addFieldError(
                        OngoingStudentSpreadsheetParser::FieldPriorIdentifier,
                        'LRN / Prior ID cannot exceed 255 characters.',
                        'Invalid LRN',
                    );
                }

                if ($row['financial_hold_note'] !== null && strlen((string) $row['financial_hold_note']) > 1000) {
                    $addFieldError(
                        OngoingStudentSpreadsheetParser::FieldFinancialHoldNote,
                        'Financial hold note cannot exceed 1,000 characters.',
                        'Invalid hold note',
                    );
                }

                // Soft duplicate warnings: Name + Birth Date or LRN matching another existing profile or earlier row.
                $nameDobKey = $this->nameDobKey($firstName, $lastName, $row['birth_date']);
                if ($nameDobKey !== null) {
                    foreach ($existingProfilesByNameDob[$nameDobKey] ?? [] as $dupProfile) {
                        if ($matchedExistingProfile instanceof StudentProfile && (int) $dupProfile->id === (int) $matchedExistingProfile->id) {
                            continue;
                        }
                        $dupName = trim("{$dupProfile->first_name} {$dupProfile->last_name}");
                        $warnings[] = "Possible duplicate: matches existing student {$dupProfile->student_number} ({$dupName}) by name and birth date.";
                        $hasDuplicateWarning = true;
                        break;
                    }

                    if (isset($seenBatchNameDob[$nameDobKey])) {
                        $warnings[] = "Possible duplicate: matches row #{$seenBatchNameDob[$nameDobKey]} in this file by name and birth date.";
                        $hasDuplicateWarning = true;
                    } else {
                        $seenBatchNameDob[$nameDobKey] = $sourceRow;
                    }
                }

                $lrnKey = $this->normalizeIdentifierKey($row['prior_identifier']);
                if ($lrnKey !== null) {
                    foreach ($existingProfilesByLrn[$lrnKey] ?? [] as $dupProfile) {
                        if ($matchedExistingProfile instanceof StudentProfile && (int) $dupProfile->id === (int) $matchedExistingProfile->id) {
                            continue;
                        }
                        $warnings[] = "Possible duplicate: LRN matches existing student {$dupProfile->student_number}.";
                        $hasDuplicateWarning = true;
                        break;
                    }

                    if (isset($seenBatchLrn[$lrnKey])) {
                        $warnings[] = "Possible duplicate: LRN matches row #{$seenBatchLrn[$lrnKey]} in this file.";
                        $hasDuplicateWarning = true;
                    } else {
                        $seenBatchLrn[$lrnKey] = $sourceRow;
                    }
                }

                // Regular Cohort Quick-Credit estimate.
                if ($curriculumVersion instanceof CurriculumVersion
                    && $scopeCompletedYear !== null
                    && $scopeCompletedTermType !== null) {
                    if ($normalizedStanding === StudentProfile::StandingRegular) {
                        $cacheKey = $curriculumVersion->id.'|'.$scopeCompletedYear.'|'.$scopeCompletedTermType;
                        if (! array_key_exists($cacheKey, $quickCreditCountCache)) {
                            $quickCreditCountCache[$cacheKey] = $this->countCurriculumEntriesThrough(
                                curriculumVersionId: (int) $curriculumVersion->id,
                                yearLevel: $scopeCompletedYear,
                                termType: $scopeCompletedTermType,
                            );
                        }
                        $priorCreditsToApply = $quickCreditCountCache[$cacheKey];
                    } else {
                        $warnings[] = "Standing is {$normalizedStanding}: cohort quick-credit will not be auto-applied (routed to individual advising).";
                    }
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
                $statusLabel = 'Already active';
                $issueBadge = 'Already active';
                $alreadyOnboardedCount++;
            } elseif ($errors !== []) {
                $status = self::StatusBlocked;
                $issueBadge = count($issueCategories) > 1
                    ? 'Multiple issues'
                    : ($issueCategories[0] ?? 'Fix required');
                $statusLabel = $isApplicantCollision && ! $linkExistingApplicant && count($issueCategories) === 1
                    ? 'Blocked — Existing applicant account'
                    : $issueBadge;
                $blockedCount++;
                if ($isApplicantCollision && ! $linkExistingApplicant) {
                    $applicantCollisionsBlocked++;
                }
            } elseif ($isUnactivatedUpsert) {
                $status = self::StatusUpdateUnactivated;
                $statusLabel = 'Update unactivated profile';
                $updateUnactivatedCount++;
                $estimatedPriorCredits += $priorCreditsToApply;
            } else {
                $status = self::StatusReady;
                $statusLabel = $isApplicantCollision && $linkExistingApplicant
                    ? 'Ready (link applicant)'
                    : 'Ready to onboard';
                $readyNewCount++;
                $estimatedPriorCredits += $priorCreditsToApply;
                if ($isApplicantCollision && $linkExistingApplicant) {
                    $applicantLinksOptedIn++;
                }
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
                'program_code' => $program instanceof Program ? (string) $program->code : ($rowProgramCode ?? ''),
                'program_id' => $program?->id !== null ? (int) $program->id : null,
                'curriculum_version_code' => $curriculumVersion instanceof CurriculumVersion
                    ? (string) $curriculumVersion->version_code
                    : $rowCurriculumCode,
                'curriculum_version_id' => $curriculumVersion?->id !== null ? (int) $curriculumVersion->id : null,
                'entry_term_code' => $entryTermCode,
                'entry_term_id' => $entryTerm?->id !== null ? (int) $entryTerm->id : null,
                'entry_academic_year_id' => $resolvedEntryAcademicYearId,
                'completed_through_year_level' => ($normalizedStanding === StudentProfile::StandingRegular) ? $scopeCompletedYear : null,
                'completed_through_term_type' => ($normalizedStanding === StudentProfile::StandingRegular) ? $scopeCompletedTermType : null,
                'prior_credits_to_apply' => $priorCreditsToApply,
                'academic_standing' => $normalizedStanding,
                'raw_academic_standing' => $row['academic_standing'],
                'lifecycle_status' => $normalizedLifecycle,
                'raw_lifecycle_status' => $row['lifecycle_status'],
                'birth_date' => $row['birth_date'],
                'phone' => $row['phone'],
                'address' => $row['address'],
                'prior_identifier' => $row['prior_identifier'],
                'financial_hold_note' => $row['financial_hold_note'],
                'matched_student_profile_id' => $matchedExistingProfile?->id !== null ? (int) $matchedExistingProfile->id : null,
                'is_unactivated_upsert' => $isUnactivatedUpsert,
                'is_applicant_collision' => $isApplicantCollision,
                'applicant_user_id' => $applicantUserId,
                'link_existing_applicant' => $linkExistingApplicant,
                'has_duplicate_warning' => $hasDuplicateWarning,
                'already_onboarded_reason' => $alreadyOnboardedReason,
                'skip' => $isSkipped,
                'status' => $status,
                'status_label' => $statusLabel,
                'issue_badge' => $issueBadge,
                'issue_categories' => $issueCategories,
                'editable_issue_fields' => $editableIssueFields,
                'field_errors' => $fieldErrors,
                'has_warnings' => $warnings !== [],
                'warnings' => $warnings,
                'errors' => $errors,
            ];
        }

        return [
            'summary' => [
                'total' => count($previewRows),
                'ready_new' => $readyNewCount,
                'update_unactivated' => $updateUnactivatedCount,
                'already_onboarded' => $alreadyOnboardedCount,
                'blocked_count' => $blockedCount,
                'skipped_count' => $skippedCount,
                'warnings_count' => $warningsCount,
                'applicant_collisions_blocked' => $applicantCollisionsBlocked,
                'applicant_links_opted_in' => $applicantLinksOptedIn,
                'estimated_prior_credits' => $estimatedPriorCredits,
            ],
            'rows' => $previewRows,
        ];
    }

    /**
     * Commit validated cohort roster onboarding rows (new profiles + safe unactivated profile updates),
     * recording a `StudentImportBatch` for 1-click undo when new profiles are created.
     *
     * @param  array<int|string, mixed>  $previewRows
     * @param  array{
     *     program_id?: int|null,
     *     curriculum_version_id?: int|null,
     *     entry_academic_year_id?: int|null,
     *     completed_through_year_level?: int|null,
     *     completed_through_term_type?: string|null,
     *     filename?: string|null
     * }  $cohortScope
     * @return array{
     *     import_batch_id: int|null,
     *     created_students: int,
     *     updated_students: int,
     *     linked_existing_applicants: int,
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
        bool $sendActivationEmails = false,
        bool $skipBlocked = true,
        array $cohortScope = [],
    ): array {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only an authorized Registrar may import ongoing students.');
        }

        if (isset($previewRows['rows']) && is_array($previewRows['rows'])) {
            $previewRows = $previewRows['rows'];
        }

        $skippedRows = [];
        $readyNewStudents = [];
        $readyUpdateStudents = [];

        foreach ($previewRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $status = (string) ($row['status'] ?? '');

            if ($status === self::StatusAlreadyOnboarded) {
                $skippedRows[] = [
                    ...$row,
                    'sheet' => 'Students',
                    'skip_reason' => (string) ($row['already_onboarded_reason'] ?? 'Already active in TALA.'),
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

            if ($status === self::StatusUpdateUnactivated || ! empty($row['is_unactivated_upsert'])) {
                $readyUpdateStudents[] = $row;
            } else {
                $readyNewStudents[] = $row;
            }
        }

        $batch = null;
        if ($readyNewStudents !== []) {
            $firstReady = $readyNewStudents[0];
            $batchProgramId = (int) ($cohortScope['program_id'] ?? $firstReady['program_id'] ?? 0);
            $batchCurriculumId = (int) ($cohortScope['curriculum_version_id'] ?? $firstReady['curriculum_version_id'] ?? 0);

            if ($batchProgramId > 0 && $batchCurriculumId > 0) {
                $batch = StudentImportBatch::query()->create([
                    'program_id' => $batchProgramId,
                    'curriculum_version_id' => $batchCurriculumId,
                    'entry_academic_year_id' => filled($cohortScope['entry_academic_year_id'] ?? null)
                        ? (int) $cohortScope['entry_academic_year_id']
                        : (filled($firstReady['entry_academic_year_id'] ?? null) ? (int) $firstReady['entry_academic_year_id'] : null),
                    'completed_through_year_level' => filled($cohortScope['completed_through_year_level'] ?? null)
                        ? (int) $cohortScope['completed_through_year_level']
                        : null,
                    'completed_through_term_type' => filled($cohortScope['completed_through_term_type'] ?? null)
                        ? (string) $cohortScope['completed_through_term_type']
                        : null,
                    'imported_by' => $actor->id,
                    'student_count' => 0,
                    'filename' => filled($cohortScope['filename'] ?? null) ? (string) $cohortScope['filename'] : null,
                ]);
            }
        }

        $createdStudents = 0;
        $updatedStudents = 0;
        $linkedExistingApplicants = 0;
        $skippedExistingStudents = count(array_filter(
            $skippedRows,
            fn (array $row): bool => ($row['status'] ?? null) === self::StatusAlreadyOnboarded,
        ));
        $priorCreditsRecorded = 0;
        $activationEmailsSent = 0;
        $activationEmailsFailed = 0;

        foreach ($readyNewStudents as $studentRow) {
            $studentNumber = trim((string) ($studentRow['student_number'] ?? ''));
            $email = strtolower(trim((string) ($studentRow['email'] ?? '')));
            $linkApplicant = (bool) ($studentRow['link_existing_applicant'] ?? false);

            $existingByNumber = $studentNumber !== ''
                ? StudentProfile::query()->where('student_number', $studentNumber)->first()
                : null;
            $existingUserByEmail = $email !== ''
                ? User::query()->with(['roles', 'studentProfile'])->whereRaw('LOWER(email) = ?', [$email])->first()
                : null;

            $isAllowedApplicantLink = $existingUserByEmail instanceof User
                && $linkApplicant
                && ! $existingUserByEmail->studentProfile instanceof StudentProfile
                && ! $existingUserByEmail->isStaffCapable()
                && $existingUserByEmail->hasAssignedRole('applicant');

            if ($existingByNumber instanceof StudentProfile || ($existingUserByEmail instanceof User && ! $isAllowedApplicantLink)) {
                $skippedExistingStudents++;
                $skippedRows[] = [
                    ...$studentRow,
                    'sheet' => 'Students',
                    'status' => self::StatusAlreadyOnboarded,
                    'skip_reason' => $existingByNumber instanceof StudentProfile
                        ? "Student number {$studentNumber} already exists in TALA."
                        : "Email {$email} is already registered in TALA.",
                ];

                continue;
            }

            $standing = (string) ($studentRow['academic_standing'] ?? StudentProfile::StandingRegular);
            $applyQuickCredit = $standing === StudentProfile::StandingRegular
                && filled($studentRow['completed_through_year_level'] ?? null)
                && filled($studentRow['completed_through_term_type'] ?? null);

            $ayLabel = $this->resolveAcademicYearLabel($studentRow['entry_academic_year_id'] ?? null);

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
                    'entry_academic_year_id' => $studentRow['entry_academic_year_id'] ?? null,
                    'import_batch_id' => $batch?->id,
                    'completed_through_year_level' => $applyQuickCredit ? (int) $studentRow['completed_through_year_level'] : null,
                    'completed_through_term_type' => $applyQuickCredit ? (string) $studentRow['completed_through_term_type'] : null,
                    'prior_academic_year_label' => $ayLabel,
                    'link_existing_applicant' => $linkApplicant,
                    'defer_prior_credits' => ! $applyQuickCredit,
                    'academic_standing' => $standing,
                    'lifecycle_status' => $studentRow['lifecycle_status'] ?? StudentProfile::LifecycleActive,
                    'birth_date' => $studentRow['birth_date'] ?? null,
                    'phone' => $studentRow['phone'] ?? null,
                    'address' => $studentRow['address'] ?? null,
                    'prior_identifier' => $studentRow['prior_identifier'] ?? null,
                    'financial_hold_note' => $studentRow['financial_hold_note'] ?? null,
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

            if (! empty($result['linked_existing_applicant'])) {
                $linkedExistingApplicants++;
            }

            if ($sendActivationEmails) {
                if ($result['activation_sent']) {
                    $activationEmailsSent++;
                } else {
                    $activationEmailsFailed++;
                }
            }
        }

        foreach ($readyUpdateStudents as $studentRow) {
            $updateResult = $this->upsertUnactivatedProfile($studentRow, $actor, $sendActivationEmails);

            if ($updateResult['status'] === 'updated') {
                $updatedStudents++;
                $priorCreditsRecorded += (int) $updateResult['prior_credits_created'];
                if ($sendActivationEmails) {
                    if ($updateResult['activation_sent']) {
                        $activationEmailsSent++;
                    } elseif ($updateResult['activation_attempted']) {
                        $activationEmailsFailed++;
                    }
                }
            } elseif ($updateResult['status'] === 'already_active') {
                $skippedExistingStudents++;
                $skippedRows[] = [
                    ...$studentRow,
                    'sheet' => 'Students',
                    'status' => self::StatusAlreadyOnboarded,
                    'skip_reason' => $updateResult['reason'],
                ];
            } else {
                if (! $skipBlocked) {
                    throw ValidationException::withMessages([
                        'rows' => $updateResult['reason'],
                    ]);
                }

                $skippedRows[] = [
                    ...$studentRow,
                    'sheet' => 'Students',
                    'status' => self::StatusBlocked,
                    'skip_reason' => $updateResult['reason'],
                ];
            }
        }

        if ($batch instanceof StudentImportBatch) {
            if ($createdStudents > 0) {
                $batch->forceFill(['student_count' => $createdStudents])->save();
            } else {
                $batch->delete();
                $batch = null;
            }
        }

        activity('student_onboarding')
            ->causedBy($actor)
            ->event('ongoing_students_spreadsheet_imported')
            ->withProperties([
                'import_batch_id' => $batch?->id,
                'created_students' => $createdStudents,
                'updated_students' => $updatedStudents,
                'linked_existing_applicants' => $linkedExistingApplicants,
                'skipped_existing_students' => $skippedExistingStudents,
                'prior_credits_recorded' => $priorCreditsRecorded,
                'activation_emails_sent' => $activationEmailsSent,
                'activation_emails_failed' => $activationEmailsFailed,
                'skipped_rows_count' => count($skippedRows),
            ])
            ->log("Imported {$createdStudents} and updated {$updatedStudents} student(s) from cohort roster");

        return [
            'import_batch_id' => $batch?->id,
            'created_students' => $createdStudents,
            'updated_students' => $updatedStudents,
            'linked_existing_applicants' => $linkedExistingApplicants,
            'skipped_existing_students' => $skippedExistingStudents,
            'prior_credits_recorded' => $priorCreditsRecorded,
            'activation_emails_sent' => $activationEmailsSent,
            'activation_emails_failed' => $activationEmailsFailed,
            'skipped_rows' => $skippedRows,
        ];
    }

    /**
     * Safely update an existing student profile whose user has never signed in and has no official enrollment.
     *
     * @param  array<string, mixed>  $studentRow
     * @return array{status: string, reason: string, prior_credits_created: int, activation_attempted: bool, activation_sent: bool}
     */
    private function upsertUnactivatedProfile(array $studentRow, User $actor, bool $sendActivationEmails): array
    {
        $studentNumber = trim((string) ($studentRow['student_number'] ?? ''));
        $email = strtolower(trim((string) ($studentRow['email'] ?? '')));

        $profile = StudentProfile::query()
            ->with('user')
            ->where('student_number', $studentNumber)
            ->first();

        if (! $profile instanceof StudentProfile) {
            return [
                'status' => 'blocked',
                'reason' => "Unactivated student profile {$studentNumber} could not be found.",
                'prior_credits_created' => 0,
                'activation_attempted' => false,
                'activation_sent' => false,
            ];
        }

        $user = $profile->user;
        $hasSignedIn = $user instanceof User && $user->last_successful_sign_in_at !== null;
        $hasOfficialEnrollment = Enrollment::query()
            ->where('student_profile_id', $profile->id)
            ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled)
            ->exists();

        if ($hasSignedIn || $hasOfficialEnrollment) {
            return [
                'status' => 'already_active',
                'reason' => "Student {$studentNumber} is already active or officially enrolled and was left unchanged.",
                'prior_credits_created' => 0,
                'activation_attempted' => false,
                'activation_sent' => false,
            ];
        }

        if ($email !== '') {
            $emailOwner = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->when($user instanceof User, fn ($query) => $query->whereKeyNot($user->id))
                ->first();

            if ($emailOwner instanceof User) {
                return [
                    'status' => 'blocked',
                    'reason' => "Email {$email} is already registered to another TALA account.",
                    'prior_credits_created' => 0,
                    'activation_attempted' => false,
                    'activation_sent' => false,
                ];
            }
        }

        $standing = (string) ($studentRow['academic_standing'] ?? $profile->academic_standing ?? StudentProfile::StandingRegular);
        $lifecycle = (string) ($studentRow['lifecycle_status'] ?? $profile->lifecycle_status ?? StudentProfile::LifecycleActive);
        $priorCreditsCreated = 0;

        DB::transaction(function () use (
            $profile,
            $user,
            $studentRow,
            $email,
            $standing,
            $lifecycle,
            $actor,
            &$priorCreditsCreated,
        ): void {
            $lockedProfile = StudentProfile::query()->lockForUpdate()->findOrFail($profile->id);

            $namePayload = User::staffNamePayload(
                firstName: trim((string) $studentRow['first_name']),
                middleName: filled($studentRow['middle_name'] ?? null) ? trim((string) $studentRow['middle_name']) : null,
                lastName: trim((string) $studentRow['last_name']),
            );

            if ($user instanceof User) {
                $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
                $previousEmail = strtolower(trim((string) $lockedUser->email));
                $emailChanged = $email !== '' && $email !== $previousEmail;

                $userUpdates = [...$namePayload];
                if ($emailChanged) {
                    $userUpdates['email'] = $email;
                    $userUpdates['email_verified_at'] = null;
                }

                $lockedUser->forceFill($userUpdates)->save();

                if ($emailChanged) {
                    DB::table('password_reset_tokens')
                        ->whereIn('email', array_values(array_unique([$previousEmail, $email])))
                        ->delete();
                }
            }

            $profileUpdates = [
                'first_name' => $namePayload['first_name'],
                'middle_name' => $namePayload['middle_name'],
                'last_name' => $namePayload['last_name'],
                'email' => $email !== '' ? $email : $lockedProfile->email,
                'academic_standing' => $standing,
                'lifecycle_status' => $lifecycle,
            ];

            if (filled($studentRow['program_id'] ?? null)) {
                $profileUpdates['program_id'] = (int) $studentRow['program_id'];
            }
            if (filled($studentRow['curriculum_version_id'] ?? null)) {
                $profileUpdates['curriculum_version_id'] = (int) $studentRow['curriculum_version_id'];
            }
            if (filled($studentRow['entry_academic_year_id'] ?? null)) {
                $profileUpdates['entry_academic_year_id'] = (int) $studentRow['entry_academic_year_id'];
            }
            if (filled($studentRow['entry_term_id'] ?? null)) {
                $profileUpdates['entry_term_id'] = (int) $studentRow['entry_term_id'];
            }
            if (filled($studentRow['birth_date'] ?? null)) {
                $profileUpdates['birth_date'] = $studentRow['birth_date'];
            }
            if (filled($studentRow['phone'] ?? null)) {
                $profileUpdates['phone'] = $studentRow['phone'];
            }
            if (filled($studentRow['address'] ?? null)) {
                $profileUpdates['address'] = $studentRow['address'];
            }
            if (filled($studentRow['prior_identifier'] ?? null)) {
                $profileUpdates['prior_identifier'] = $studentRow['prior_identifier'];
            }

            $lockedProfile->forceFill($profileUpdates)->save();

            if ($standing === StudentProfile::StandingRegular
                && filled($studentRow['completed_through_year_level'] ?? null)
                && filled($studentRow['completed_through_term_type'] ?? null)) {
                $ayLabel = $this->resolveAcademicYearLabel($lockedProfile->entry_academic_year_id);
                $creditResult = $this->priorCredits->applyCompletedThroughTerm(
                    profile: $lockedProfile,
                    yearLevel: (int) $studentRow['completed_through_year_level'],
                    termType: (string) $studentRow['completed_through_term_type'],
                    actor: $actor,
                    academicYearLabel: $ayLabel,
                );
                $priorCreditsCreated += (int) $creditResult['created'];
            }

            $holdNote = trim((string) ($studentRow['financial_hold_note'] ?? ''));
            if ($holdNote !== '') {
                $hasSameEnrollmentHold = Hold::query()
                    ->where('student_profile_id', $lockedProfile->id)
                    ->where('hold_type', Hold::TypeFinancial)
                    ->where('blocking_level', Hold::BlockingEnrollment)
                    ->where('status', Hold::StatusActive)
                    ->where('reason', $holdNote)
                    ->exists();

                if (! $hasSameEnrollmentHold) {
                    Hold::query()->create([
                        'student_profile_id' => $lockedProfile->id,
                        'hold_type' => Hold::TypeFinancial,
                        'blocking_level' => Hold::BlockingEnrollment,
                        'status' => Hold::StatusActive,
                        'reason' => $holdNote,
                        'student_message' => 'Please settle your prior balance with the Accounting Office before next-term enrollment or official record release.',
                        'resolution_requirement' => 'Accounting clearance required to release prior-balance enrollment hold.',
                        'created_by' => $actor->id,
                        'effective_at' => now(),
                    ]);
                }

                $hasSameRecordHold = Hold::query()
                    ->where('student_profile_id', $lockedProfile->id)
                    ->where('hold_type', Hold::TypeFinancial)
                    ->where('blocking_level', Hold::BlockingRecordRelease)
                    ->where('status', Hold::StatusActive)
                    ->where('reason', $holdNote)
                    ->exists();

                if (! $hasSameRecordHold) {
                    Hold::query()->create([
                        'student_profile_id' => $lockedProfile->id,
                        'hold_type' => Hold::TypeFinancial,
                        'blocking_level' => Hold::BlockingRecordRelease,
                        'status' => Hold::StatusActive,
                        'reason' => $holdNote,
                        'student_message' => 'Official transcript and graduation clearance remain on hold until your prior balance is settled with the Accounting Office.',
                        'resolution_requirement' => 'Accounting clearance required to release prior-balance transcript and graduation hold.',
                        'created_by' => $actor->id,
                        'effective_at' => now(),
                    ]);
                }
            }

            activity('student_onboarding')
                ->performedOn($lockedProfile)
                ->causedBy($actor)
                ->event('unactivated_student_profile_upserted')
                ->withProperties([
                    'student_number' => $lockedProfile->student_number,
                    'academic_standing' => $standing,
                    'prior_credits_created' => $priorCreditsCreated,
                ])
                ->log('Updated unactivated student profile from cohort roster import');
        }, attempts: 3);

        $activationAttempted = false;
        $activationSent = false;
        $refreshed = $profile->fresh(['user']);
        if ($sendActivationEmails && $refreshed instanceof StudentProfile && StudentAccountActivation::canResendActivation($refreshed)) {
            $activationAttempted = true;
            $activationSent = $this->activation->sendActivationEmail($refreshed, $actor);
        }

        return [
            'status' => 'updated',
            'reason' => '',
            'prior_credits_created' => $priorCreditsCreated,
            'activation_attempted' => $activationAttempted,
            'activation_sent' => $activationSent,
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

        $programOverride = $nullableText($pick('program_code'));

        return [
            'row_key' => $rowKey,
            'source_row' => $sourceRow,
            'student_number' => $cleanText($pick('student_number', '')),
            'first_name' => $cleanText($pick('first_name', '')),
            'middle_name' => $nullableText($pick('middle_name')),
            'last_name' => $cleanText($pick('last_name', '')),
            'email' => strtolower($cleanText($pick('email', ''))),
            'program_code' => $programOverride !== null ? strtoupper($programOverride) : null,
            'curriculum_version_code' => $nullableText($pick('curriculum_version_code')),
            'entry_term_code' => $nullableText($pick('entry_term_code')),
            'academic_standing' => $nullableText($pick('academic_standing')),
            'lifecycle_status' => $nullableText($pick('lifecycle_status')),
            'birth_date' => $nullableText($pick('birth_date')),
            'phone' => $nullableText($pick('phone')),
            'address' => filled($pick('address')) ? trim((string) $pick('address')) : null,
            'prior_identifier' => $nullableText($pick('prior_identifier')),
            'financial_hold_note' => filled($pick('financial_hold_note')) ? trim((string) $pick('financial_hold_note')) : null,
            'link_existing_applicant' => array_key_exists('link_existing_applicant', $override)
                ? (bool) $override['link_existing_applicant']
                : (bool) ($rawRow['link_existing_applicant'] ?? false),
            'skip' => array_key_exists('skip', $override)
                ? (bool) $override['skip']
                : (bool) ($rawRow['skip'] ?? false),
        ];
    }

    /**
     * @return array{curriculum: ?CurriculumVersion, error: ?string}
     */
    private function resolveCurriculumVersion(
        Program $program,
        ?string $curriculumCode,
        ?int $preferredCurriculumId = null,
        ?int $entryAcademicYearId = null,
    ): array {
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

        if ($preferredCurriculumId !== null) {
            $matchedPreferred = $versions->firstWhere('id', $preferredCurriculumId);
            if ($matchedPreferred instanceof CurriculumVersion) {
                return ['curriculum' => $matchedPreferred, 'error' => null];
            }
        }

        if ($entryAcademicYearId !== null) {
            $batchCurriculum = $program->curriculumForAcademicYear($entryAcademicYearId);
            if ($batchCurriculum instanceof CurriculumVersion) {
                return ['curriculum' => $batchCurriculum, 'error' => null];
            }
        }

        $active = $versions->where('state', CurriculumVersion::StateActive)->values();

        if ($active->count() === 1) {
            return ['curriculum' => $active->first(), 'error' => null];
        }

        if ($versions->count() === 1) {
            return ['curriculum' => $versions->first(), 'error' => null];
        }

        if ($active->isEmpty()) {
            return [
                'curriculum' => null,
                'error' => "Program {$program->code} has no active curriculum version yet. Select a Curriculum Version in Step 1.",
            ];
        }

        return [
            'curriculum' => null,
            'error' => "Program {$program->code} has multiple active curriculum versions. Select which Curriculum Version applies in Step 1.",
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

    private function stripAyPrefix(string $text): string
    {
        return trim((string) preg_replace('/^(?:a\.?y\.?|s\.?y\.?)\s*/i', '', $text));
    }

    private function countCurriculumEntriesThrough(int $curriculumVersionId, int $yearLevel, string $termType): int
    {
        $targetOrder = match ($termType) {
            Term::TypeFirstSemester => 1,
            Term::TypeSecondSemester => 2,
            Term::TypeSpecialTerm => 3,
            default => null,
        };

        if ($targetOrder === null) {
            return 0;
        }

        return CurriculumEntry::query()
            ->with('courseSpecification')
            ->where('curriculum_version_id', $curriculumVersionId)
            ->get()
            ->filter(function (CurriculumEntry $entry) use ($yearLevel, $targetOrder): bool {
                $spec = $entry->courseSpecification;
                if (! $spec instanceof CourseSpecification || $spec->state === CourseSpecification::StateDraft) {
                    return false;
                }

                $entryYear = is_numeric($entry->year_level) ? (int) $entry->year_level : null;
                if ($entryYear === null) {
                    return false;
                }

                $entryOrder = match ($entry->term_type) {
                    Term::TypeFirstSemester => 1,
                    Term::TypeSecondSemester => 2,
                    Term::TypeSpecialTerm => 3,
                    default => 1,
                };

                return $entryYear < $yearLevel || ($entryYear === $yearLevel && $entryOrder <= $targetOrder);
            })
            ->pluck('course_specification_id')
            ->unique()
            ->count();
    }

    private function resolveAcademicYearLabel(?int $entryAcademicYearId): ?string
    {
        if ($entryAcademicYearId === null) {
            return null;
        }

        $ay = AcademicYear::query()->find($entryAcademicYearId);

        return $ay instanceof AcademicYear && filled($ay->label) ? (string) $ay->label : null;
    }

    /**
     * Strict Academic Standing validator — rejects unknown strings instead of silently coercing to Regular.
     *
     * @return array{value: string, error: ?string}
     */
    private function validateStanding(?string $raw): array
    {
        $trimmed = trim((string) ($raw ?? ''));
        if ($trimmed === '') {
            return ['value' => StudentProfile::StandingRegular, 'error' => null];
        }

        $clean = strtolower(str_replace(['_', '-'], ' ', $trimmed));
        $clean = preg_replace('/\s+/', ' ', $clean) ?? '';

        $resolved = match ($clean) {
            'regular' => StudentProfile::StandingRegular,
            'irregular' => StudentProfile::StandingIrregular,
            'probationary' => StudentProfile::StandingProbationary,
            'deficient' => StudentProfile::StandingDeficient,
            'blocked by prerequisite' => StudentProfile::StandingBlockedByPrerequisite,
            'must repeat year', 'must repeat year level' => StudentProfile::StandingMustRepeatYear,
            'completion candidate' => StudentProfile::StandingCompletionCandidate,
            'graduation candidate' => StudentProfile::StandingGraduationCandidate,
            'not yet evaluated' => StudentProfile::StandingNotYetEvaluated,
            default => null,
        };

        if ($resolved === null) {
            return [
                'value' => $trimmed,
                'error' => "Unknown academic standing \"{$trimmed}\". Choose Regular, Irregular, Probationary, Deficient, Completion Candidate, or Graduation Candidate.",
            ];
        }

        return ['value' => $resolved, 'error' => null];
    }

    /**
     * Strict Lifecycle Status validator — rejects unknown strings instead of silently coercing to ACTIVE.
     *
     * @return array{value: string, error: ?string}
     */
    private function validateLifecycleStatus(?string $raw): array
    {
        $trimmed = trim((string) ($raw ?? ''));
        if ($trimmed === '') {
            return ['value' => StudentProfile::LifecycleActive, 'error' => null];
        }

        $clean = strtoupper(str_replace([' ', '-'], '_', $trimmed));
        $clean = preg_replace('/_+/', '_', $clean) ?? '';

        $resolved = match ($clean) {
            'ACTIVE' => StudentProfile::LifecycleActive,
            'LEAVE_OF_ABSENCE', 'LOA' => StudentProfile::LifecycleLeaveOfAbsence,
            'INACTIVE' => StudentProfile::LifecycleInactive,
            'WITHDRAWN' => StudentProfile::LifecycleWithdrawn,
            'TRANSFERRED_OUT' => StudentProfile::LifecycleTransferredOut,
            'COMPLETED' => StudentProfile::LifecycleCompleted,
            default => null,
        };

        if ($resolved === null) {
            return [
                'value' => $trimmed,
                'error' => "Unknown lifecycle status \"{$trimmed}\". Choose ACTIVE, LEAVE_OF_ABSENCE, INACTIVE, WITHDRAWN, TRANSFERRED_OUT, or COMPLETED.",
            ];
        }

        return ['value' => $resolved, 'error' => null];
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

    private function nameDobKey(string $firstName, string $lastName, ?string $birthDate): ?string
    {
        $f = strtolower(trim((string) preg_replace('/\s+/', ' ', $firstName)));
        $l = strtolower(trim((string) preg_replace('/\s+/', ' ', $lastName)));
        $d = trim((string) ($birthDate ?? ''));

        if ($f === '' || $l === '' || $d === '') {
            return null;
        }

        return $f.'|'.$l.'|'.$d;
    }

    private function normalizeIdentifierKey(?string $value): ?string
    {
        $clean = strtoupper(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '', (string) ($value ?? ''))));

        return $clean !== '' ? $clean : null;
    }
}
