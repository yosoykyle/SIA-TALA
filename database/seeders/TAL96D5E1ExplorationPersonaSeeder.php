<?php

namespace Database\Seeders;

use App\Actions\Applicants\AdmissionRequirementResolver;
use App\Actions\Graduation\GraduationEligibilitySnapshotService;
use App\Actions\SystemAdministration\TAL96D5E1ExplorationPersonaCatalog;
use App\Models\ApplicantIntake;
use App\Models\ChecklistItem;
use App\Models\CourseEnrollment;
use App\Models\CurriculumEntry;
use App\Models\DocumentEvidence;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\GraduationReviewBatch;
use App\Models\GraduationReviewMember;
use App\Models\GraduationSnapshot;
use App\Models\Hold;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Adds deterministic, test-only exploration personas to the verified MIDDLE fixture.
 *
 * This seeder has no application UI and never invokes CP-SAT or an external
 * provider. Its command guard limits execution to testing/MySQL/test_tala_db.
 */
final class TAL96D5E1ExplorationPersonaSeeder extends Seeder
{
    public function __construct(
        private readonly TAL96D5BAcceptanceStateSeeder $operationalStates,
        private readonly TAL96D5E1ExplorationPersonaCatalog $catalog,
        private readonly AdmissionRequirementResolver $requirements,
    ) {}

    public function run(): void
    {
        $this->operationalStates->run();

        $term = $this->middleTerm();
        $program = Program::query()->where('code', 'DBM')->sole();
        $registrar = User::query()->where('email', 'registrar.demo@example.test')->sole();

        $this->ensureStaffBoundaries();
        $this->ensureStudentVerificationBoundary();
        $this->ensurePriorTermStudentHistories($term, $registrar);
        $this->ensureApplicantPersonas($term, $program, $registrar);
    }

    private function ensureStaffBoundaries(): void
    {
        $unverified = $this->catalog->unverifiedStaff();
        $unverifiedUser = $this->ensureUser(
            email: $unverified['email'],
            firstName: 'Unverified',
            lastName: 'Registrar',
            status: User::StatusActive,
            verified: false,
        );
        $unverifiedUser->syncRoles([$unverified['role']]);

        $denied = $this->catalog->deniedLoginPersona();
        $deniedUser = $this->ensureUser(
            email: $denied['email'],
            firstName: 'Inactive',
            lastName: 'Staff',
            status: $denied['status'],
            verified: true,
        );
        $deniedUser->syncRoles([$denied['role']]);
    }

    private function ensureStudentVerificationBoundary(): void
    {
        $boundary = $this->catalog->unverifiedStudent();
        $student = User::query()->where('email', $boundary['email'])->sole();

        $student->forceFill(['email_verified_at' => null])->save();
    }

    private function ensureApplicantPersonas(Term $term, Program $program, User $registrar): void
    {
        foreach ($this->catalog->applicants() as $email => $definition) {
            $applicant = User::query()->where('email', $email)->first();

            if (! $applicant instanceof User) {
                $applicant = $this->ensureUser(
                    email: $email,
                    firstName: $this->applicantFirstName($email),
                    lastName: 'Applicant',
                    status: $definition['user_status'],
                    verified: true,
                );
            } else {
                $applicant->forceFill([
                    'status' => $definition['user_status'],
                    'email_verified_at' => '2025-12-01 08:00:00',
                ])->save();
            }

            $applicant->syncRoles(['applicant']);
            $intake = $this->ensureApplicantIntake(
                applicant: $applicant,
                term: $term,
                program: $program,
                definition: $definition,
                registrar: $registrar,
            );

            if (in_array($intake->status, [
                ApplicantIntake::StatusPending,
                ApplicantIntake::StatusActionRequired,
                ApplicantIntake::StatusForEvaluation,
                ApplicantIntake::StatusApproved,
            ], true)) {
                $this->ensureChecklistState($intake, $registrar);
            }

            if ($intake->status === ApplicantIntake::StatusWithdrawn) {
                $this->ensureWithdrawalAudit($intake, $applicant);
            }
        }
    }

    /**
     * @param  array{label:string,intake_status:string,admission_category:string,credential_basis:string,user_status:string}  $definition
     */
    private function ensureApplicantIntake(
        User $applicant,
        Term $term,
        Program $program,
        array $definition,
        User $registrar,
    ): ApplicantIntake {
        $submittedAt = in_array($definition['intake_status'], [
            ApplicantIntake::StatusPending,
            ApplicantIntake::StatusActionRequired,
            ApplicantIntake::StatusForEvaluation,
            ApplicantIntake::StatusApproved,
        ], true)
            ? CarbonImmutable::parse('2026-01-06 09:00:00', config('app.timezone'))
            : null;
        $reviewedAt = in_array($definition['intake_status'], [
            ApplicantIntake::StatusActionRequired,
            ApplicantIntake::StatusForEvaluation,
            ApplicantIntake::StatusApproved,
        ], true)
            ? CarbonImmutable::parse('2026-01-07 10:00:00', config('app.timezone'))
            : null;
        $approvedAt = $definition['intake_status'] === ApplicantIntake::StatusApproved
            ? CarbonImmutable::parse('2026-01-08 11:00:00', config('app.timezone'))
            : null;
        $archivedAt = $definition['intake_status'] === ApplicantIntake::StatusWithdrawn
            ? CarbonImmutable::parse('2026-01-07 10:00:00', config('app.timezone'))
            : null;
        $attributes = [
            'program_id' => $program->id,
            'admission_category' => $definition['admission_category'],
            'credential_basis' => $definition['credential_basis'],
            'modality_preference' => null,
            'first_name' => $applicant->first_name,
            'middle_name' => $applicant->middle_name,
            'last_name' => $applicant->last_name,
            'birth_date' => '2007-01-15',
            'gender' => 'FEMALE',
            'civil_status' => 'SINGLE',
            'birth_place' => 'Synthetic City, Laguna',
            'email' => $applicant->email,
            'phone' => '09171234567',
            'address_barangay' => 'Synthetic Barangay',
            'address_street' => '100 Exploration Street',
            'address_city' => 'Synthetic City',
            'address_province' => 'Laguna',
            'prior_school' => 'Synthetic Prior School',
            'guardian_name' => 'Synthetic Guardian',
            'guardian_phone' => '09179876543',
            'guardian_address' => '100 Exploration Street, Synthetic Barangay, Synthetic City, Laguna',
            'status' => $definition['intake_status'],
            'submitted_at' => $submittedAt,
            'reviewed_at' => $reviewedAt,
            'reviewed_by' => $reviewedAt === null ? null : $registrar->id,
            'approved_at' => $approvedAt,
            'approved_by' => $approvedAt === null ? null : $registrar->id,
            'archived_at' => $archivedAt,
        ];

        if ($definition['intake_status'] === ApplicantIntake::StatusWithdrawn) {
            return ApplicantIntake::query()->firstOrCreate(
                ['user_id' => $applicant->id, 'term_id' => $term->id],
                $attributes,
            );
        }

        return ApplicantIntake::query()->updateOrCreate(
            ['user_id' => $applicant->id, 'term_id' => $term->id],
            $attributes,
        );
    }

    private function ensureChecklistState(ApplicantIntake $intake, User $registrar): void
    {
        $applicant = $intake->user;

        if (! $applicant instanceof User) {
            throw new RuntimeException('A TAL-96D5E1 applicant intake must belong to a User.');
        }

        $rejectionAssigned = false;

        foreach ($this->requirements->resolve($intake) as $policy) {
            $isResolved = in_array($intake->status, [
                ApplicantIntake::StatusForEvaluation,
                ApplicantIntake::StatusApproved,
            ], true);
            $isRejected = $intake->status === ApplicantIntake::StatusActionRequired
                && ! $rejectionAssigned
                && $policy->evidence_method === ChecklistItem::EvidenceMethodDigitalUpload;

            if ($isRejected) {
                $rejectionAssigned = true;
            }

            $status = match (true) {
                $isRejected => ChecklistItem::StatusRejected,
                $isResolved => ChecklistItem::StatusAccepted,
                $policy->evidence_method === ChecklistItem::EvidenceMethodDigitalUpload => ChecklistItem::StatusReceivedDigital,
                default => ChecklistItem::StatusPending,
            };
            $verification = match (true) {
                $isRejected => ChecklistItem::VerificationRejected,
                $isResolved => ChecklistItem::VerificationVerified,
                default => ChecklistItem::VerificationNotReviewed,
            };
            $reviewed = $isRejected || $isResolved;
            $item = ChecklistItem::query()->updateOrCreate(
                [
                    'applicant_intake_id' => $intake->id,
                    'requirement_type' => $policy->requirement_type,
                ],
                [
                    'owner_type' => ChecklistItem::OwnerApplicant,
                    'student_profile_id' => null,
                    'source_policy_id' => $policy->id,
                    'status' => $status,
                    'blocking_level' => $policy->blocking_level,
                    'evidence_method' => $policy->evidence_method,
                    'verification_status' => $verification,
                    'reviewed_by' => $reviewed ? $registrar->id : null,
                    'reviewed_at' => $reviewed
                        ? CarbonImmutable::parse('2026-01-07 10:00:00', config('app.timezone'))
                        : null,
                    'waiver_reason' => $isRejected
                        ? 'Upload a clear and complete replacement file.'
                        : null,
                ],
            );

            if ($policy->evidence_method === ChecklistItem::EvidenceMethodDigitalUpload) {
                $this->ensureDigitalEvidence($item, $applicant, $registrar, $isRejected, $isResolved);
            }
        }
    }

    private function ensureDigitalEvidence(
        ChecklistItem $item,
        User $applicant,
        User $registrar,
        bool $isRejected,
        bool $isResolved,
    ): void {
        $evidence = $item->documentEvidence()->latest('uploaded_at')->latest('id')->first();

        if (! $evidence instanceof DocumentEvidence) {
            $path = 'tal96d5e1-acceptance/'.$applicant->username.'/'.strtolower($item->requirement_type).'.pdf';
            $contents = "%PDF-1.4\n% TALA synthetic D5E1 exploration evidence\n%%EOF\n";
            Storage::disk('local')->put($path, $contents);
            $evidence = DocumentEvidence::query()->create([
                'checklist_item_id' => $item->id,
                'disk' => 'local',
                'path' => $path,
                'checksum' => hash('sha256', $contents),
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($contents),
                'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
                'status' => DocumentEvidence::StatusSubmitted,
                'uploaded_by' => $applicant->id,
                'uploaded_at' => CarbonImmutable::parse('2026-01-06 09:00:00', config('app.timezone')),
                'reviewed_by' => null,
                'reviewed_at' => null,
                'replaces_document_evidence_id' => null,
            ]);
        }

        $reviewed = $isRejected || $isResolved;
        $evidence->forceFill([
            'status' => match (true) {
                $isRejected => DocumentEvidence::StatusRejected,
                $isResolved => DocumentEvidence::StatusAccepted,
                default => DocumentEvidence::StatusSubmitted,
            },
            'reviewed_by' => $reviewed ? $registrar->id : null,
            'reviewed_at' => $reviewed
                ? CarbonImmutable::parse('2026-01-07 10:00:00', config('app.timezone'))
                : null,
        ])->save();
    }

    private function ensureWithdrawalAudit(ApplicantIntake $intake, User $applicant): void
    {
        $timestamp = CarbonImmutable::parse('2026-01-07 10:00:00', config('app.timezone'));

        DB::table('activity_log')->updateOrInsert(
            [
                'log_name' => 'applicant_intake',
                'subject_type' => ApplicantIntake::class,
                'subject_id' => $intake->id,
                'event' => 'applicant_intake_withdrawn',
            ],
            [
                'description' => 'Applicant withdrew their intake before completed staff review.',
                'causer_type' => User::class,
                'causer_id' => $applicant->id,
                'properties' => json_encode([
                    'status_before' => ApplicantIntake::StatusDraft,
                    'status_after' => ApplicantIntake::StatusWithdrawn,
                    'reason' => 'Plans changed before submission.',
                ], JSON_UNESCAPED_SLASHES),
                'created_at' => $timestamp->toDateTimeString(),
                'updated_at' => $timestamp->toDateTimeString(),
            ],
        );
    }

    private function ensurePriorTermStudentHistories(Term $currentTerm, User $registrar): void
    {
        $priorTerm = Term::query()
            ->where('type', Term::TypeFirstSemester)
            ->where('label', 'First Semester')
            ->where('state', Term::StateClosed)
            ->whereHas('academicYear', fn ($query) => $query->where('label', 'AY 2025-2026'))
            ->sole();
        $faculty = User::role(User::StaffRoleFaculty)->orderBy('id')->firstOrFail();
        $timestamp = CarbonImmutable::parse('2025-10-31 17:00:00', config('app.timezone'));
        $histories = [
            'student.demo@example.test' => [
                'course_indexes' => [0],
                'student_type' => 'regular',
                'category' => GradeRosterRow::CategoryPassing,
            ],
            'student.dbm-2a.002@example.test' => [
                'course_indexes' => [0],
                'student_type' => 'regular',
                'category' => GradeRosterRow::CategoryPassing,
            ],
            'student.dbm-3a.001@example.test' => [
                'course_indexes' => [0],
                'student_type' => 'regular',
                'category' => GradeRosterRow::CategoryPassing,
            ],
            'student.dbm-2a.001@example.test' => [
                'course_indexes' => [1],
                'student_type' => 'irregular',
                'category' => GradeRosterRow::CategoryFailed,
            ],
            'student.dit-1a.001@example.test' => [
                'course_indexes' => [0],
                'student_type' => 'regular',
                'category' => GradeRosterRow::CategoryFailed,
            ],
            'student.dit-1a.002@example.test' => [
                'course_indexes' => [1],
                'student_type' => 'irregular',
                'category' => GradeRosterRow::CategoryIncomplete,
            ],
            'student.dit-2a.001@example.test' => [
                'course_indexes' => [0],
                'student_type' => 'irregular',
                'category' => GradeRosterRow::CategoryFailed,
                'hold_type' => Hold::TypePrerequisite,
            ],
            'student.dthm-1a.001@example.test' => [
                'course_indexes' => [0, 1],
                'student_type' => 'irregular',
                'category' => GradeRosterRow::CategoryFailed,
                'hold_type' => Hold::TypeAcademicDeficit,
            ],
            'student.dthm-1a.002@example.test' => [
                'course_indexes' => [2],
                'student_type' => 'regular',
                'category' => GradeRosterRow::CategoryPassing,
                'graduation_status' => GraduationEligibilitySnapshotService::ResultReadyForRegistrarReview,
            ],
            'student.dthm-2a.001@example.test' => [
                'course_indexes' => [0],
                'student_type' => 'regular',
                'category' => GradeRosterRow::CategoryPassing,
                'graduation_status' => GraduationEligibilitySnapshotService::ResultComplete,
            ],
            'student.dthm-2a.002@example.test' => [
                'course_indexes' => [1],
                'student_type' => 'regular',
                'category' => GradeRosterRow::CategoryPending,
            ],
        ];

        foreach ($histories as $email => $history) {
            $profile = User::query()
                ->where('email', $email)
                ->sole()
                ->studentProfile()
                ->sole();
            $yearLevel = $this->yearLevelFromStudentNumber($profile->student_number);
            $entries = CurriculumEntry::query()
                ->whereBelongsTo($profile->curriculumVersion)
                ->where('year_level', $yearLevel)
                ->where('term_type', Term::TypeFirstSemester)
                ->with('courseSpecification.course')
                ->orderBy('sequence')
                ->get();

            foreach ($history['course_indexes'] as $courseIndex) {
                $entry = $entries->get($courseIndex);

                if (! $entry instanceof CurriculumEntry) {
                    throw new RuntimeException(
                        "Missing prior-term curriculum evidence for [{$profile->student_number}] course index [{$courseIndex}].",
                    );
                }

                $this->ensurePriorTermCourseOutcome(
                    profile: $profile,
                    priorTerm: $priorTerm,
                    entry: $entry,
                    courseIndex: $courseIndex,
                    studentType: $history['student_type'],
                    category: $history['category'],
                    faculty: $faculty,
                    registrar: $registrar,
                    timestamp: $timestamp,
                );
            }

            if (isset($history['hold_type'])) {
                $this->ensureAcademicHold(
                    profile: $profile,
                    priorTerm: $priorTerm,
                    holdType: $history['hold_type'],
                    registrar: $registrar,
                    timestamp: $timestamp,
                );
            }

            if (isset($history['graduation_status'])) {
                $this->ensureGraduationProjection(
                    profile: $profile,
                    currentTerm: $currentTerm,
                    resultStatus: $history['graduation_status'],
                    registrar: $registrar,
                    timestamp: $timestamp,
                );
            }
        }
    }

    private function ensurePriorTermCourseOutcome(
        StudentProfile $profile,
        Term $priorTerm,
        CurriculumEntry $entry,
        int $courseIndex,
        string $studentType,
        string $category,
        User $faculty,
        User $registrar,
        CarbonImmutable $timestamp,
    ): void {
        $specification = $entry->courseSpecification;
        $course = $specification?->course;

        if ($specification === null || $course === null) {
            throw new RuntimeException("Prior-term curriculum entry [{$entry->id}] is missing its course specification.");
        }

        $isPending = $category === GradeRosterRow::CategoryPending;
        $isOnline = str_starts_with($course->code, 'GE')
            || str_starts_with($course->code, 'PE')
            || str_starts_with($course->code, 'NSTP')
            || str_starts_with($course->code, 'NIHONGO');
        $offering = TermOffering::query()->firstOrCreate(
            [
                'term_id' => $priorTerm->id,
                'curriculum_entry_id' => $entry->id,
                'delivery_variant' => TermOffering::ArrangementNormalClass,
            ],
            [
                'category' => TermOffering::CategoryRegular,
                'special_reason' => null,
                'modality' => $isOnline
                    ? TermOffering::ModalityOnline
                    : TermOffering::ModalityFaceToFace,
                'expected_count' => 1,
                'room_type_override' => null,
                'same_faculty_override' => true,
                'state' => TermOffering::StateScheduled,
            ],
        );
        $section = Section::query()->firstOrCreate(
            [
                'term_offering_id' => $offering->id,
                'code' => sprintf('HIST-%s-%02d', $profile->student_number, $courseIndex + 1),
            ],
            [
                'capacity' => 30,
                'state' => Section::StateClosed,
            ],
        );
        $enrollment = Enrollment::query()->updateOrCreate(
            [
                'student_profile_id' => $profile->id,
                'term_id' => $priorTerm->id,
            ],
            [
                'status' => 'officially_enrolled',
                'student_type' => $studentType,
                'registered_at' => $timestamp->subMonths(4),
                'officially_enrolled_at' => $timestamp->subMonths(4)->addDay(),
                'status_reason' => 'TAL-96D5E1B1 source-derived prior-term history.',
            ],
        );
        $courseEnrollment = CourseEnrollment::query()->updateOrCreate(
            [
                'enrollment_id' => $enrollment->id,
                'term_offering_id' => $offering->id,
            ],
            [
                'status' => CourseEnrollment::StatusActive,
                'units_snapshot' => $specification->credit_units,
                'added_at' => $timestamp->subMonths(4)->addDay(),
                'status_reason' => 'TAL-96D5E1B1 source-derived prior-term history.',
            ],
        );
        $roster = GradeRoster::query()->updateOrCreate(
            [
                'term_offering_id' => $offering->id,
                'section_id' => $section->id,
            ],
            [
                'faculty_user_id' => $faculty->id,
                'state' => $isPending ? GradeRoster::StateSubmitted : GradeRoster::StateReleased,
                'grading_profile_snapshot' => config('grades.servitech_v1'),
                'submitted_by' => $faculty->id,
                'submitted_at' => $timestamp->subDays(2),
                'reviewed_by' => $isPending ? null : $registrar->id,
                'reviewed_at' => $isPending ? null : $timestamp->subDay(),
                'released_by' => $isPending ? null : $registrar->id,
                'released_at' => $isPending ? null : $timestamp,
                'return_reason' => null,
            ],
        );
        [$equivalent, $outcomeCode] = match ($category) {
            GradeRosterRow::CategoryPassing => [90, '1.75'],
            GradeRosterRow::CategoryFailed => [68, '5.00'],
            GradeRosterRow::CategoryIncomplete => [null, 'INC'],
            default => [null, null],
        };
        $row = GradeRosterRow::query()->updateOrCreate(
            ['course_enrollment_id' => $courseEnrollment->id],
            [
                'grade_roster_id' => $roster->id,
                'prelim_equivalent' => $equivalent,
                'midterm_equivalent' => $equivalent,
                'final_equivalent' => $equivalent,
                'computed_average' => $equivalent,
                'current_outcome_code' => $outcomeCode,
                'current_outcome_category' => $category,
                'released_at' => $isPending ? null : $timestamp,
            ],
        );

        if (! $isPending) {
            GradeOutcomeEvent::query()->updateOrCreate(
                [
                    'grade_roster_row_id' => $row->id,
                    'event_type' => GradeOutcomeEvent::TypeInitialRelease,
                    'evidence_reference' => "TAL-96D5E1B1-{$profile->student_number}-{$course->code}",
                ],
                [
                    'previous_value' => null,
                    'new_value' => $equivalent,
                    'previous_category' => null,
                    'new_category' => $category,
                    'deadline' => $category === GradeRosterRow::CategoryIncomplete
                        ? $timestamp->addMonths(1)->toDateString()
                        : null,
                    'authority' => 'Registrar-confirmed release of Faculty-owned grade evidence.',
                    'reason' => 'TAL-96D5E1B1 source-derived prior-term persona evidence.',
                    'recorded_by' => $registrar->id,
                ],
            );
        }
    }

    private function ensureAcademicHold(
        StudentProfile $profile,
        Term $priorTerm,
        string $holdType,
        User $registrar,
        CarbonImmutable $timestamp,
    ): void {
        Hold::query()->updateOrCreate(
            [
                'student_profile_id' => $profile->id,
                'term_id' => $priorTerm->id,
                'hold_type' => $holdType,
                'reason' => 'TAL-96D5E1B1 source-derived academic-state evidence.',
            ],
            [
                'enrollment_id' => Enrollment::query()
                    ->whereBelongsTo($profile)
                    ->whereBelongsTo($priorTerm)
                    ->value('id'),
                'blocking_level' => Hold::BlockingEnrollment,
                'status' => Hold::StatusActive,
                'staff_only_reason' => 'Synthetic acceptance evidence only.',
                'student_message' => $holdType === Hold::TypePrerequisite
                    ? 'A prerequisite course must be completed before enrollment can continue.'
                    : 'Meet the Academic Head or Registrar to review the failed prior-term courses.',
                'created_by' => $registrar->id,
                'effective_at' => $timestamp,
                'resolution_requirement' => 'Complete the documented academic review.',
            ],
        );
    }

    private function ensureGraduationProjection(
        StudentProfile $profile,
        Term $currentTerm,
        string $resultStatus,
        User $registrar,
        CarbonImmutable $timestamp,
    ): void {
        $batch = GraduationReviewBatch::query()->firstOrCreate(
            [
                'term_id' => $currentTerm->id,
                'name' => 'TAL-96D5E1B1 Source-Derived Review',
            ],
            [
                'academic_year_id' => $currentTerm->academic_year_id,
                'state' => GraduationReviewBatch::StateOpen,
                'created_by' => $registrar->id,
                'filter_summary' => ['purpose' => 'TAL-96D5E1B1 source-derived persona evidence'],
            ],
        );
        $member = GraduationReviewMember::query()->firstOrCreate(
            [
                'graduation_review_batch_id' => $batch->id,
                'student_profile_id' => $profile->id,
            ],
            [
                'added_by' => $registrar->id,
                'added_at' => $timestamp,
                'is_active' => true,
            ],
        );
        $remainingRequirements = $resultStatus === GraduationEligibilitySnapshotService::ResultComplete
            ? []
            : ['Complete the current-term curriculum requirements.'];

        GraduationSnapshot::query()->updateOrCreate(
            [
                'graduation_review_member_id' => $member->id,
                'version' => 1,
            ],
            [
                'result_status' => $resultStatus,
                'evaluation_snapshot' => [
                    'student_projection' => [
                        'result_status' => $resultStatus,
                        'remaining_requirements' => $remainingRequirements,
                        'pending_grade_blockers' => [],
                        'inc_blockers' => [],
                        'hold_or_clearance_labels' => [],
                        'required_action' => $remainingRequirements === []
                            ? 'Await the Registrar finalization.'
                            : 'Complete the listed requirements before final review.',
                        'office_to_contact' => 'Registrar Office',
                    ],
                    'fixture_authority' => 'TAL-96D5E1B1 source-derived synthetic evidence',
                ],
                'generated_by' => $registrar->id,
                'generated_at' => $timestamp,
                'made_visible_by' => $registrar->id,
                'made_visible_at' => $timestamp,
                'visibility_reason' => 'TAL-96D5E1B1 exploration projection.',
            ],
        );
    }

    private function yearLevelFromStudentNumber(string $studentNumber): string
    {
        if (! preg_match('/^[A-Z]+-(\d)A-\d{3}$/', $studentNumber, $matches)) {
            throw new RuntimeException("Student number [{$studentNumber}] does not identify a fixture year level.");
        }

        return match ((int) $matches[1]) {
            1 => 'First Year',
            2 => 'Second Year',
            3 => 'Third Year',
            default => throw new RuntimeException("Unsupported fixture year in student number [{$studentNumber}]."),
        };
    }

    private function ensureUser(
        string $email,
        string $firstName,
        string $lastName,
        string $status,
        bool $verified,
    ): User {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'first_name' => $firstName,
                'middle_name' => null,
                'last_name' => $lastName,
                'username' => str($email)->before('@')->replace('.', '-')->toString(),
                'password' => 'password',
                'status' => $status,
            ],
        );
        $user->forceFill([
            'status' => $status,
            'email_verified_at' => $verified ? '2025-12-01 08:00:00' : null,
        ])->save();

        return $user;
    }

    private function applicantFirstName(string $email): string
    {
        return str($email)
            ->before('@')
            ->after('applicant.')
            ->before('.demo')
            ->replace('-', ' ')
            ->headline()
            ->toString();
    }

    private function middleTerm(): Term
    {
        return Term::query()
            ->where('label', 'Second Semester')
            ->whereHas('academicYear', fn ($query) => $query->where('label', 'AY 2025-2026'))
            ->sole();
    }
}
