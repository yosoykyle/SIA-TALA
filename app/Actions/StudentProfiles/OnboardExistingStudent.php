<?php

namespace App\Actions\StudentProfiles;

use App\Actions\Enrollment\AllocateStudentNumber;
use App\Models\AcademicDecision;
use App\Models\AcademicYear;
use App\Models\AdmissionApplication;
use App\Models\ApplicantIntake;
use App\Models\CurriculumVersion;
use App\Models\Hold;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class OnboardExistingStudent
{
    public function __construct(
        private readonly AllocateStudentNumber $studentNumbers,
        private readonly RecordPriorAcademicCredits $priorCredits,
        private readonly StudentAccountActivation $activation,
    ) {}

    /**
     * Atomically onboard an existing Servitech student (User + StudentProfile + optional Financial Holds + optional Prior Credits)
     * and optionally dispatch their student activation email after commit.
     *
     * @param  array{
     *     student_number?: string|null,
     *     first_name: string,
     *     middle_name?: string|null,
     *     last_name: string,
     *     email: string,
     *     program_id: int,
     *     curriculum_version_id?: int|null,
     *     entry_term_id?: int|null,
     *     entry_academic_year_id?: int|null,
     *     import_batch_id?: int|null,
     *     completed_through_year_level?: int|null,
     *     completed_through_term_type?: string|null,
     *     link_existing_applicant?: bool,
     *     defer_prior_credits?: bool,
     *     academic_standing?: string|null,
     *     lifecycle_status?: string|null,
     *     birth_date?: string|null,
     *     phone?: string|null,
     *     address?: string|null,
     *     prior_identifier?: string|null,
     *     financial_hold_note?: string|null,
     *     prior_credits?: list<array<string, mixed>>,
     *     prior_curriculum_entry_ids?: list<int>,
     *     prior_academic_year_label?: string|null,
     *     send_activation_email?: bool
     * }  $data
     * @return array{profile: StudentProfile, user: User, activation_sent: bool, prior_credits_created: int, linked_existing_applicant: bool}
     */
    public function execute(array $data, User $actor): array
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only an authorized Registrar may onboard existing students.');
        }

        $normalizedEmail = strtolower(trim((string) ($data['email'] ?? '')));
        $studentNumberInput = trim((string) ($data['student_number'] ?? ''));
        $standing = $this->normalizeStanding($data['academic_standing'] ?? null);
        $lifecycle = $this->normalizeLifecycleStatus($data['lifecycle_status'] ?? null);
        $sendActivationEmail = array_key_exists('send_activation_email', $data) ? (bool) $data['send_activation_email'] : false;
        $linkExistingApplicant = (bool) ($data['link_existing_applicant'] ?? false);
        $deferPriorCredits = (bool) ($data['defer_prior_credits'] ?? false);

        $completedThroughYearLevel = filled($data['completed_through_year_level'] ?? null)
            ? (int) $data['completed_through_year_level']
            : null;
        $completedThroughTermType = filled($data['completed_through_term_type'] ?? null)
            ? trim((string) $data['completed_through_term_type'])
            : null;

        if (($completedThroughYearLevel !== null) !== ($completedThroughTermType !== null)) {
            throw ValidationException::withMessages([
                'completed_through_term_type' => 'Specify both the completed-through year level and term type together.',
            ]);
        }

        $linkingApplicantUser = null;
        if ($normalizedEmail !== '') {
            $existingUser = User::query()->where('email', $normalizedEmail)->first();
            if ($existingUser instanceof User) {
                if ($existingUser->studentProfile()->exists()) {
                    throw ValidationException::withMessages([
                        'email' => 'This email address is already linked to an existing student profile.',
                    ]);
                }

                if ($existingUser->isStaffCapable()) {
                    throw ValidationException::withMessages([
                        'email' => 'This email address belongs to a staff account and cannot be onboarded as a student.',
                    ]);
                }

                if ($existingUser->hasAssignedRole('applicant')) {
                    if (! $linkExistingApplicant) {
                        throw ValidationException::withMessages([
                            'email' => 'This email address belongs to an existing applicant account. Opt in to link the existing applicant account and archive their draft intake.',
                        ]);
                    }

                    $linkingApplicantUser = $existingUser;
                } else {
                    throw ValidationException::withMessages([
                        'email' => 'This email address is already registered to an existing TALA account.',
                    ]);
                }
            }
        }

        $emailRules = ['required', 'string', 'email:rfc', 'max:255'];
        if (! $linkingApplicantUser instanceof User) {
            $emailRules[] = Rule::unique('users', 'email');
        }

        Validator::make(
            [
                'student_number' => $studentNumberInput !== '' ? $studentNumberInput : null,
                'first_name' => trim((string) ($data['first_name'] ?? '')),
                'middle_name' => filled($data['middle_name'] ?? null) ? trim((string) $data['middle_name']) : null,
                'last_name' => trim((string) ($data['last_name'] ?? '')),
                'email' => $normalizedEmail,
                'program_id' => $data['program_id'] ?? null,
                'curriculum_version_id' => $data['curriculum_version_id'] ?? null,
                'entry_term_id' => $data['entry_term_id'] ?? null,
                'entry_academic_year_id' => $data['entry_academic_year_id'] ?? null,
                'import_batch_id' => $data['import_batch_id'] ?? null,
                'completed_through_year_level' => $completedThroughYearLevel,
                'completed_through_term_type' => $completedThroughTermType,
                'birth_date' => filled($data['birth_date'] ?? null) ? $data['birth_date'] : null,
                'phone' => filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null,
                'address' => filled($data['address'] ?? null) ? trim((string) $data['address']) : null,
                'prior_identifier' => filled($data['prior_identifier'] ?? null) ? trim((string) $data['prior_identifier']) : null,
                'financial_hold_note' => filled($data['financial_hold_note'] ?? null) ? trim((string) $data['financial_hold_note']) : null,
            ],
            [
                'student_number' => ['nullable', 'string', 'max:64', Rule::unique('student_profiles', 'student_number')],
                'first_name' => ['required', 'string', 'max:255'],
                'middle_name' => ['nullable', 'string', 'max:255'],
                'last_name' => ['required', 'string', 'max:255'],
                'email' => $emailRules,
                'program_id' => ['required', 'integer', 'exists:programs,id'],
                'curriculum_version_id' => ['nullable', 'integer', 'exists:curriculum_versions,id'],
                'entry_term_id' => ['nullable', 'integer', 'exists:terms,id'],
                'entry_academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
                'import_batch_id' => ['nullable', 'integer', 'exists:student_import_batches,id'],
                'completed_through_year_level' => ['nullable', 'integer', 'min:1', 'max:6'],
                'completed_through_term_type' => ['nullable', 'string', Rule::in([
                    Term::TypeFirstSemester,
                    Term::TypeSecondSemester,
                    Term::TypeSpecialTerm,
                ])],
                'birth_date' => ['nullable', 'date'],
                'phone' => ['nullable', 'string', 'max:64'],
                'address' => ['nullable', 'string', 'max:2000'],
                'prior_identifier' => ['nullable', 'string', 'max:255'],
                'financial_hold_note' => ['nullable', 'string', 'max:1000'],
            ],
            [
                'student_number.unique' => 'This Student Number is already assigned to an existing student profile.',
                'email.unique' => 'This email address is already registered to an existing TALA account.',
            ],
        )->validate();

        $program = Program::query()->findOrFail((int) $data['program_id']);
        $entryTerm = filled($data['entry_term_id'] ?? null)
            ? Term::query()->with('academicYear')->find((int) $data['entry_term_id'])
            : null;
        $entryAcademicYearId = filled($data['entry_academic_year_id'] ?? null)
            ? (int) $data['entry_academic_year_id']
            : ($entryTerm?->academic_year_id !== null ? (int) $entryTerm->academic_year_id : null);
        $importBatchId = filled($data['import_batch_id'] ?? null)
            ? (int) $data['import_batch_id']
            : null;

        $curriculumId = $this->resolveCurriculumVersionId(
            program: $program,
            requestedId: $data['curriculum_version_id'] ?? null,
            entryAcademicYearId: $entryAcademicYearId,
        );

        $createdCreditsCount = 0;

        /** @var array{profile: StudentProfile, user: User, linked_existing_applicant: bool} $created */
        $created = DB::transaction(function () use (
            $data,
            $actor,
            $normalizedEmail,
            $studentNumberInput,
            $standing,
            $lifecycle,
            $program,
            $entryTerm,
            $entryAcademicYearId,
            $importBatchId,
            $curriculumId,
            $completedThroughYearLevel,
            $completedThroughTermType,
            $deferPriorCredits,
            $linkingApplicantUser,
            &$createdCreditsCount,
        ): array {
            if ($studentNumberInput !== '') {
                $studentNumber = $studentNumberInput;
                $this->studentNumbers->syncSequenceForImportedNumber($studentNumber);
            } else {
                $entryYear = $entryTerm?->starts_on?->year;
                if ($entryYear === null && $entryAcademicYearId !== null) {
                    $ay = AcademicYear::query()->find($entryAcademicYearId);
                    $entryYear = $ay instanceof AcademicYear ? CurriculumVersion::extractAcademicYearStart($ay) : null;
                }
                $studentNumber = $this->studentNumbers->execute((int) ($entryYear ?? now('Asia/Manila')->year));
            }

            $namePayload = User::staffNamePayload(
                firstName: trim((string) $data['first_name']),
                middleName: filled($data['middle_name'] ?? null) ? trim((string) $data['middle_name']) : null,
                lastName: trim((string) $data['last_name']),
            );

            $linkedExistingApplicant = false;

            if ($linkingApplicantUser instanceof User) {
                $user = User::query()->lockForUpdate()->findOrFail($linkingApplicantUser->id);

                if ($user->studentProfile()->exists()) {
                    throw ValidationException::withMessages([
                        'email' => 'This email address is already linked to an existing student profile.',
                    ]);
                }

                $userUpdates = [
                    ...$namePayload,
                    'status' => User::StatusActive,
                ];
                if (blank($user->username) && ! User::query()->where('username', $studentNumber)->whereKeyNot($user->id)->exists()) {
                    $userUpdates['username'] = $studentNumber;
                }

                $user->forceFill($userUpdates)->save();

                Role::findOrCreate('student', 'web');
                $user->assignRole('student');

                $openIntakes = ApplicantIntake::query()
                    ->where('user_id', $user->id)
                    ->where('status', '!=', ApplicantIntake::StatusWithdrawn)
                    ->whereNull('handed_over_at')
                    ->lockForUpdate()
                    ->get();

                foreach ($openIntakes as $intake) {
                    $intakeAttributes = [
                        'status' => ApplicantIntake::StatusWithdrawn,
                        'archived_at' => now(),
                    ];
                    if ($intake->getAttribute('application_state') !== null) {
                        $intakeAttributes['application_state'] = AdmissionApplication::StateWithdrawn;
                    }
                    $intake->forceFill($intakeAttributes)->save();
                }

                $linkedExistingApplicant = true;
            } else {
                $username = User::query()->where('username', $studentNumber)->exists() ? null : $studentNumber;

                $user = User::query()->create([
                    ...$namePayload,
                    'email' => $normalizedEmail,
                    'username' => $username,
                    'password' => null,
                    'status' => User::StatusActive,
                    'email_verified_at' => null,
                ]);

                Role::findOrCreate('student', 'web');
                $user->assignRole('student');
            }

            $profile = StudentProfile::query()->create([
                'user_id' => $user->id,
                'student_number' => $studentNumber,
                'entry_term_id' => $entryTerm?->id,
                'entry_academic_year_id' => $entryAcademicYearId,
                'import_batch_id' => $importBatchId,
                'first_name' => $namePayload['first_name'],
                'middle_name' => $namePayload['middle_name'],
                'last_name' => $namePayload['last_name'],
                'birth_date' => filled($data['birth_date'] ?? null) ? $data['birth_date'] : null,
                'prior_identifier' => filled($data['prior_identifier'] ?? null) ? trim((string) $data['prior_identifier']) : null,
                'program_id' => $program->id,
                'curriculum_version_id' => $curriculumId,
                'lifecycle_status' => $lifecycle,
                'academic_standing' => $standing,
                'email' => $normalizedEmail,
                'phone' => filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null,
                'address' => filled($data['address'] ?? null) ? trim((string) $data['address']) : null,
            ]);

            if ($completedThroughYearLevel !== null && $completedThroughTermType !== null) {
                $cohortCredits = $this->priorCredits->applyCompletedThroughTerm(
                    profile: $profile,
                    yearLevel: $completedThroughYearLevel,
                    termType: $completedThroughTermType,
                    actor: $actor,
                    academicYearLabel: $data['prior_academic_year_label'] ?? null,
                );
                $createdCreditsCount += $cohortCredits['created'];
            }

            if (! empty($data['prior_credits']) && is_array($data['prior_credits'])) {
                $creditResult = $this->priorCredits->appendCredits($profile, $data['prior_credits'], $actor);
                $createdCreditsCount += $creditResult['created'];
            }

            if (! empty($data['prior_curriculum_entry_ids']) && is_array($data['prior_curriculum_entry_ids'])) {
                $syncResult = $this->priorCredits->syncChecklistCredits(
                    profile: $profile,
                    selectedEntryIds: $data['prior_curriculum_entry_ids'],
                    actor: $actor,
                    academicYearLabel: $data['prior_academic_year_label'] ?? null,
                );
                $createdCreditsCount += $syncResult['added'];
            }

            $standingRequiresAdvising = in_array($standing, [
                StudentProfile::StandingIrregular,
                StudentProfile::StandingProbationary,
                StudentProfile::StandingDeficient,
                StudentProfile::StandingBlockedByPrerequisite,
            ], true);

            $uncreditedContinuingCohort = $createdCreditsCount === 0
                && $completedThroughYearLevel === null
                && ($deferPriorCredits || $entryAcademicYearId !== null || $importBatchId !== null);

            if ($standingRequiresAdvising || $uncreditedContinuingCohort) {
                $advisingReason = $standingRequiresAdvising
                    ? "Onboarded with {$standing} standing requiring individual Registrar subject advising."
                    : 'Onboarded continuing cohort student has no prior subjects credited yet; individual Registrar advising is required before standard registration.';

                AcademicDecision::query()->create([
                    'student_profile_id' => $profile->id,
                    'term_id' => null,
                    'effect' => AcademicDecision::EffectAdvisingRequired,
                    'reason' => $advisingReason,
                    'effective_from' => today('Asia/Manila'),
                    'state' => AcademicDecision::StateActive,
                    'recorded_by' => $actor->id,
                    'recorded_at' => now(),
                ]);
            }

            $holdNote = trim((string) ($data['financial_hold_note'] ?? ''));
            if ($holdNote !== '') {
                Hold::query()->create([
                    'student_profile_id' => $profile->id,
                    'hold_type' => Hold::TypeFinancial,
                    'blocking_level' => Hold::BlockingEnrollment,
                    'status' => Hold::StatusActive,
                    'reason' => $holdNote,
                    'student_message' => 'Please settle your prior balance with the Accounting Office before next-term enrollment or official record release.',
                    'resolution_requirement' => 'Accounting clearance required to release prior-balance enrollment hold.',
                    'created_by' => $actor->id,
                    'effective_at' => now(),
                ]);

                Hold::query()->create([
                    'student_profile_id' => $profile->id,
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

            activity('student_onboarding')
                ->performedOn($profile)
                ->causedBy($actor)
                ->event('existing_student_onboarded')
                ->withProperties([
                    'student_number' => $profile->student_number,
                    'program_code' => $program->code,
                    'academic_standing' => $standing,
                    'entry_academic_year_id' => $entryAcademicYearId,
                    'import_batch_id' => $importBatchId,
                    'linked_existing_applicant' => $linkedExistingApplicant,
                    'prior_credits_created' => $createdCreditsCount,
                    'has_financial_hold' => $holdNote !== '',
                ])
                ->log('Existing student onboarded');

            return [
                'profile' => $profile->fresh(['user', 'program', 'curriculumVersion', 'entryAcademicYear', 'importBatch']),
                'user' => $user->fresh(),
                'linked_existing_applicant' => $linkedExistingApplicant,
            ];
        }, attempts: 3);

        $activationSent = false;
        if ($sendActivationEmail && StudentAccountActivation::canResendActivation($created['profile'])) {
            $activationSent = $this->activation->sendActivationEmail($created['profile'], $actor);
        }

        return [
            'profile' => $created['profile'],
            'user' => $created['user'],
            'activation_sent' => $activationSent,
            'prior_credits_created' => $createdCreditsCount,
            'linked_existing_applicant' => $created['linked_existing_applicant'],
        ];
    }

    private function resolveCurriculumVersionId(Program $program, mixed $requestedId, ?int $entryAcademicYearId = null): int
    {
        if (filled($requestedId)) {
            $curriculum = CurriculumVersion::query()
                ->where('program_id', $program->id)
                ->whereIn('state', [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded])
                ->find((int) $requestedId);

            if (! $curriculum instanceof CurriculumVersion) {
                throw ValidationException::withMessages([
                    'curriculum_version_id' => 'Choose an Active or Superseded curriculum version belonging to '.$program->code.'.',
                ]);
            }

            return (int) $curriculum->id;
        }

        if ($entryAcademicYearId !== null) {
            $batchCurriculum = $program->curriculumForAcademicYear($entryAcademicYearId);
            if ($batchCurriculum instanceof CurriculumVersion) {
                return (int) $batchCurriculum->id;
            }
        }

        $active = CurriculumVersion::query()
            ->where('program_id', $program->id)
            ->where('state', CurriculumVersion::StateActive)
            ->orderByDesc('id')
            ->get();

        if ($active->count() === 1) {
            return (int) $active->sole()->id;
        }

        throw ValidationException::withMessages([
            'curriculum_version_id' => $active->isEmpty()
                ? "Program {$program->code} has no active curriculum version yet. Specify a valid curriculum version code."
                : "Program {$program->code} has multiple active curriculum versions. Specify which curriculum version to assign.",
        ]);
    }

    private function normalizeStanding(?string $raw): string
    {
        $clean = strtolower(str_replace(['_', '-'], ' ', trim((string) ($raw ?? ''))));
        $clean = preg_replace('/\s+/', ' ', $clean) ?? '';

        return match ($clean) {
            '', 'regular' => StudentProfile::StandingRegular,
            'irregular' => StudentProfile::StandingIrregular,
            'probationary' => StudentProfile::StandingProbationary,
            'deficient' => StudentProfile::StandingDeficient,
            'blocked by prerequisite' => StudentProfile::StandingBlockedByPrerequisite,
            'must repeat year', 'must repeat year level' => StudentProfile::StandingMustRepeatYear,
            'completion candidate' => StudentProfile::StandingCompletionCandidate,
            'graduation candidate' => StudentProfile::StandingGraduationCandidate,
            'not yet evaluated' => StudentProfile::StandingNotYetEvaluated,
            default => throw ValidationException::withMessages([
                'academic_standing' => "Unknown academic standing '{$raw}'.",
            ]),
        };
    }

    private function normalizeLifecycleStatus(?string $raw): string
    {
        $clean = strtoupper(str_replace([' ', '-'], '_', trim((string) ($raw ?? ''))));

        return match ($clean) {
            '', 'ACTIVE' => StudentProfile::LifecycleActive,
            'LEAVE_OF_ABSENCE', 'LOA' => StudentProfile::LifecycleLeaveOfAbsence,
            'INACTIVE' => StudentProfile::LifecycleInactive,
            'WITHDRAWN' => StudentProfile::LifecycleWithdrawn,
            'TRANSFERRED_OUT' => StudentProfile::LifecycleTransferredOut,
            'COMPLETED' => StudentProfile::LifecycleCompleted,
            default => throw ValidationException::withMessages([
                'lifecycle_status' => "Unknown lifecycle status '{$raw}'.",
            ]),
        };
    }
}
