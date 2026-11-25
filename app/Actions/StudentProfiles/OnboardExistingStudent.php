<?php

namespace App\Actions\StudentProfiles;

use App\Actions\Enrollment\AllocateStudentNumber;
use App\Models\AcademicDecision;
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
     * Atomically onboard an existing Servitech student (User + StudentProfile + optional Financial Hold + optional Prior Credits)
     * and optionally dispatch their passwordless activation email after commit.
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
     * @return array{profile: StudentProfile, user: User, activation_sent: bool, prior_credits_created: int}
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
        $sendActivationEmail = array_key_exists('send_activation_email', $data) ? (bool) $data['send_activation_email'] : true;

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
                'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
                'program_id' => ['required', 'integer', 'exists:programs,id'],
                'curriculum_version_id' => ['nullable', 'integer', 'exists:curriculum_versions,id'],
                'entry_term_id' => ['nullable', 'integer', 'exists:terms,id'],
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
        $curriculumId = $this->resolveCurriculumVersionId($program, $data['curriculum_version_id'] ?? null);

        $createdCreditsCount = 0;

        /** @var array{profile: StudentProfile, user: User} $created */
        $created = DB::transaction(function () use (
            $data,
            $actor,
            $normalizedEmail,
            $studentNumberInput,
            $standing,
            $lifecycle,
            $program,
            $curriculumId,
            &$createdCreditsCount,
        ): array {
            if ($studentNumberInput !== '') {
                $studentNumber = $studentNumberInput;
                $this->studentNumbers->syncSequenceForImportedNumber($studentNumber);
            } else {
                $entryYear = filled($data['entry_term_id'] ?? null)
                    ? (int) (Term::query()->find((int) $data['entry_term_id'])?->starts_on?->year ?? now('Asia/Manila')->year)
                    : (int) now('Asia/Manila')->year;
                $studentNumber = $this->studentNumbers->execute($entryYear);
            }

            $fullName = collect([
                trim((string) $data['first_name']),
                filled($data['middle_name'] ?? null) ? trim((string) $data['middle_name']) : null,
                trim((string) $data['last_name']),
            ])->filter()->implode(' ');

            $username = User::query()->where('username', $studentNumber)->exists() ? null : $studentNumber;

            $user = User::query()->create([
                'name' => $fullName,
                'email' => $normalizedEmail,
                'username' => $username,
                'password' => null,
                'status' => User::StatusActive,
                'email_verified_at' => null,
            ]);

            Role::findOrCreate('student', 'web');
            $user->assignRole('student');

            $profile = StudentProfile::query()->create([
                'user_id' => $user->id,
                'student_number' => $studentNumber,
                'entry_term_id' => filled($data['entry_term_id'] ?? null) ? (int) $data['entry_term_id'] : null,
                'first_name' => trim((string) $data['first_name']),
                'middle_name' => filled($data['middle_name'] ?? null) ? trim((string) $data['middle_name']) : null,
                'last_name' => trim((string) $data['last_name']),
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

            if ($standing === StudentProfile::StandingIrregular) {
                AcademicDecision::query()->create([
                    'student_profile_id' => $profile->id,
                    'term_id' => null,
                    'effect' => AcademicDecision::EffectAdvisingRequired,
                    'reason' => 'Onboarded as an irregular continuing student requiring individual subject advising.',
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
                    'student_message' => 'Please settle your prior balance with the Accounting Office before next-term enrollment.',
                    'resolution_requirement' => 'Accounting clearance required to release prior-balance enrollment hold.',
                    'created_by' => $actor->id,
                    'effective_at' => now(),
                ]);
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

            activity('student_onboarding')
                ->performedOn($profile)
                ->causedBy($actor)
                ->event('existing_student_onboarded')
                ->withProperties([
                    'student_number' => $profile->student_number,
                    'program_code' => $program->code,
                    'academic_standing' => $standing,
                    'prior_credits_created' => $createdCreditsCount,
                    'has_financial_hold' => $holdNote !== '',
                ])
                ->log('Existing student onboarded');

            return ['profile' => $profile->fresh(['user', 'program', 'curriculumVersion']), 'user' => $user];
        }, attempts: 3);

        $activationSent = false;
        if ($sendActivationEmail) {
            $activationSent = $this->activation->sendActivationEmail($created['profile'], $actor);
        }

        return [
            'profile' => $created['profile'],
            'user' => $created['user'],
            'activation_sent' => $activationSent,
            'prior_credits_created' => $createdCreditsCount,
        ];
    }

    private function resolveCurriculumVersionId(Program $program, mixed $requestedId): int
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
        $clean = strtolower(trim((string) ($raw ?? '')));

        return match ($clean) {
            '', 'regular' => StudentProfile::StandingRegular,
            'irregular' => StudentProfile::StandingIrregular,
            'probationary' => StudentProfile::StandingProbationary,
            'deficient' => StudentProfile::StandingDeficient,
            default => StudentProfile::StandingRegular,
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
            default => StudentProfile::LifecycleActive,
        };
    }
}
