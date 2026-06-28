<?php

namespace App\Actions\Enrollment;

use App\Models\ApplicantIntake;
use App\Models\ChecklistItem;
use App\Models\DocumentUpload;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class StudentEnrollmentService
{
    public function __construct(
        private readonly EnrollmentSectioningService $sectioningService,
        private readonly DecimalMoney $money,
    ) {}

    /**
     * @param  array{section_id?:int|null, section_delivery_group_id?:int|null, is_late_enrollment?:bool|null}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function startFromApprovedApplicant(
        ApplicantIntake $intake,
        User $actor,
        array $data = [],
        ?CarbonImmutable $startedAt = null,
    ): Enrollment {
        $this->authorizeApplicantEnrollment($actor);

        $validated = $this->validateAssignmentPayload($data);
        $timestamp = $startedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($intake, $actor, $validated, $timestamp): Enrollment {
            $lockedIntake = ApplicantIntake::query()
                ->with(['term', 'user'])
                ->lockForUpdate()
                ->findOrFail($intake->id);

            $this->assertApprovedForEnrollment($lockedIntake);

            $unresolved = $lockedIntake->checklistItems()
                ->where('blocking_level', 'blocks_handover')
                ->get()
                ->filter(fn (ChecklistItem $item) => ! $item->isResolved());

            if ($unresolved->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'checklist' => 'Handover is blocked by unresolved checklist items.',
                ]);
            }

            $studentProfile = $this->profileForApprovedApplicant($lockedIntake, $timestamp);
            $this->linkApplicantDocuments($lockedIntake, $studentProfile);

            $lockedIntake->checklistItems()->update([
                'owner_type' => StudentProfile::class,
                'owner_id' => $studentProfile->id,
            ]);

            $enrollment = Enrollment::query()
                ->where('student_profile_id', $studentProfile->id)
                ->where('term_id', $lockedIntake->term_id)
                ->lockForUpdate()
                ->first();

            if (! $enrollment instanceof Enrollment) {
                $enrollment = Enrollment::query()->create([
                    'student_profile_id' => $studentProfile->id,
                    'term_id' => $lockedIntake->term_id,
                    'status' => 'pending_payment',
                    'student_type' => $lockedIntake->applicant_type,
                    'year_level' => $lockedIntake->year_level,
                    'modality' => $lockedIntake->preferred_modality,
                    'lis_status' => 'not_encoded',
                    'is_late_enrollment' => (bool) ($validated['is_late_enrollment'] ?? false),
                ]);

                $this->recordActivity(
                    enrollment: $enrollment,
                    event: 'student_enrollment_started_from_applicant',
                    causer: $actor,
                    properties: [
                        'applicant_intake_id' => $lockedIntake->id,
                        'student_profile_id' => $studentProfile->id,
                        'status_after' => 'pending_payment',
                    ],
                    timestamp: $timestamp,
                );
            }

            return $this->assignIfRequested($enrollment, $validated, $actor)
                ->refresh()
                ->load(['studentProfile.user', 'term', 'section', 'sectionDeliveryGroup']);
        }, attempts: 3);
    }

    /**
     * @param  array{section_id?:int|null, section_delivery_group_id?:int|null, is_late_enrollment?:bool|null}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function startRegularEnrollment(
        StudentProfile $studentProfile,
        Term $term,
        User $actor,
        array $data = [],
        ?CarbonImmutable $startedAt = null,
    ): Enrollment {
        $this->authorizeRegularEnrollment($actor);

        $validated = $this->validateAssignmentPayload($data);
        $timestamp = $startedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($studentProfile, $term, $actor, $validated, $timestamp): Enrollment {
            $lockedProfile = StudentProfile::query()
                ->with('user')
                ->lockForUpdate()
                ->findOrFail($studentProfile->id);

            $this->assertNoOutstandingBalance($lockedProfile);

            $lockedTerm = Term::query()
                ->lockForUpdate()
                ->findOrFail($term->id);

            $enrollment = Enrollment::query()
                ->where('student_profile_id', $lockedProfile->id)
                ->where('term_id', $lockedTerm->id)
                ->lockForUpdate()
                ->first();

            if (! $enrollment instanceof Enrollment) {
                $enrollment = Enrollment::query()->create([
                    'student_profile_id' => $lockedProfile->id,
                    'term_id' => $lockedTerm->id,
                    'status' => 'pending_payment',
                    'student_type' => $this->studentTypeFor($lockedProfile),
                    'year_level' => $lockedProfile->year_level,
                    'modality' => $lockedProfile->modality,
                    'lis_status' => 'not_encoded',
                    'is_late_enrollment' => (bool) ($validated['is_late_enrollment'] ?? false),
                ]);

                $this->recordActivity(
                    enrollment: $enrollment,
                    event: 'regular_student_enrollment_started',
                    causer: $actor,
                    properties: [
                        'student_profile_id' => $lockedProfile->id,
                        'status_after' => 'pending_payment',
                    ],
                    timestamp: $timestamp,
                );
            }

            $assignment = $validated;

            if (! array_key_exists('section_id', $assignment) && ! array_key_exists('section_delivery_group_id', $assignment)) {
                $group = $this->sectioningService->rankedCompatibleGroups($enrollment)->first();

                if (! $group instanceof SectionDeliveryGroup) {
                    throw ValidationException::withMessages([
                        'section_id' => 'No compatible section delivery group is available for this regular enrollment.',
                    ]);
                }

                $assignment['section_id'] = $group->section_id;
                $assignment['section_delivery_group_id'] = $group->id;
            }

            return $this->assignIfRequested($enrollment, $assignment, $actor)
                ->refresh()
                ->load(['studentProfile.user', 'term', 'section', 'sectionDeliveryGroup']);
        }, attempts: 3);
    }

    /**
     * @throws ValidationException
     */
    public function completeFinanceClearedHandover(
        Enrollment $enrollment,
        ?User $actor = null,
        ?CarbonImmutable $clearedAt = null,
    ): Enrollment {
        $timestamp = $clearedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($enrollment, $actor, $timestamp): Enrollment {
            $lockedEnrollment = Enrollment::query()
                ->with(['studentProfile.user'])
                ->lockForUpdate()
                ->findOrFail($enrollment->id);

            if (! in_array($lockedEnrollment->status, ['pre_enrolled', 'officially_enrolled'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Finance-cleared handover requires a Pre-Enrolled or Officially Enrolled enrollment.',
                ]);
            }

            $studentProfile = StudentProfile::query()
                ->lockForUpdate()
                ->findOrFail($lockedEnrollment->student_profile_id);
            $user = User::query()
                ->lockForUpdate()
                ->findOrFail($studentProfile->user_id);

            $intake = ApplicantIntake::query()
                ->where('user_id', $studentProfile->user_id)
                ->first();

            $unresolvedProfileItems = $studentProfile->checklistItems()
                ->where('blocking_level', 'blocks_handover')
                ->get()
                ->filter(fn (ChecklistItem $item) => ! $item->isResolved());

            $unresolvedIntakeItems = collect();
            if ($intake) {
                $unresolvedIntakeItems = $intake->checklistItems()
                    ->where('blocking_level', 'blocks_handover')
                    ->get()
                    ->filter(fn (ChecklistItem $item) => ! $item->isResolved());
            }

            if ($unresolvedProfileItems->isNotEmpty() || $unresolvedIntakeItems->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'checklist' => 'Handover is blocked by unresolved checklist items.',
                ]);
            }

            $alreadyHandedOver = $user->status === User::StatusActive
                && $user->username === $studentProfile->student_id
                && $user->hasRole('student')
                && ! $user->hasRole('applicant');

            $user->forceFill([
                'status' => User::StatusActive,
                'username' => $studentProfile->student_id,
            ])->save();

            Role::findOrCreate('student', 'web');
            Role::findOrCreate('applicant', 'web');

            if ($user->hasRole('applicant')) {
                $user->removeRole('applicant');
            }

            if (! $user->hasRole('student')) {
                $user->assignRole('student');
            }

            if (! $alreadyHandedOver) {
                $this->recordActivity(
                    enrollment: $lockedEnrollment,
                    event: 'student_account_handover_completed',
                    causer: $actor,
                    properties: [
                        'student_profile_id' => $studentProfile->id,
                        'user_id' => $user->id,
                        'status_after' => User::StatusActive,
                    ],
                    timestamp: $timestamp,
                );
            }

            return $lockedEnrollment->refresh()->load(['studentProfile.user', 'term', 'section', 'sectionDeliveryGroup']);
        }, attempts: 3);
    }

    /**
     * @return array{ready:bool, blockers:list<string>}
     */
    public function corReadiness(Enrollment $enrollment): array
    {
        $enrollment->loadMissing(['studentProfile.user']);
        $blockers = [];

        if (! in_array($enrollment->status, ['pre_enrolled', 'officially_enrolled'], true)) {
            $blockers[] = 'finance_not_cleared';
        }

        if ($enrollment->studentProfile?->user?->status !== User::StatusActive) {
            $blockers[] = 'account_not_active';
        }

        if (! $enrollment->studentProfile?->user?->hasRole('student')) {
            $blockers[] = 'student_role_missing';
        }

        if ($enrollment->section_id === null) {
            $blockers[] = 'section_not_assigned';
        }

        if ($enrollment->section_delivery_group_id === null) {
            $blockers[] = 'delivery_group_not_assigned';
        }

        return [
            'ready' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{section_id?:int|null, section_delivery_group_id?:int|null, is_late_enrollment?:bool|null}
     */
    private function validateAssignmentPayload(array $data): array
    {
        $validator = Validator::make($data, [
            'section_id' => ['nullable', 'integer', Rule::exists((new Section)->getTable(), 'id')],
            'section_delivery_group_id' => ['nullable', 'integer', Rule::exists((new SectionDeliveryGroup)->getTable(), 'id')],
            'is_late_enrollment' => ['nullable', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($data): void {
            $hasSection = array_key_exists('section_id', $data) && filled($data['section_id']);
            $hasGroup = array_key_exists('section_delivery_group_id', $data) && filled($data['section_delivery_group_id']);

            if ($hasSection xor $hasGroup) {
                $validator->errors()->add('section_delivery_group_id', 'Section and delivery group must be assigned together.');
            }
        });

        /** @var array{section_id?:int|null, section_delivery_group_id?:int|null, is_late_enrollment?:bool|null} $validated */
        $validated = $validator->validate();

        return $validated;
    }

    /**
     * @param  array{section_id?:int|null, section_delivery_group_id?:int|null, is_late_enrollment?:bool|null}  $assignment
     */
    private function assignIfRequested(Enrollment $enrollment, array $assignment, User $actor): Enrollment
    {
        if (! filled($assignment['section_id'] ?? null) || ! filled($assignment['section_delivery_group_id'] ?? null)) {
            return $enrollment;
        }

        $section = Section::query()->findOrFail((int) $assignment['section_id']);
        $group = SectionDeliveryGroup::query()->findOrFail((int) $assignment['section_delivery_group_id']);

        return $this->sectioningService->assign($enrollment, $section, $group, $actor);
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeApplicantEnrollment(User $actor): void
    {
        if ($this->canAny($actor, ['approve-documents', 'evaluate-transferees', 'manage-sections'])) {
            return;
        }

        throw new AuthorizationException('Only authorized Registrar staff can start approved applicant enrollment.');
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeRegularEnrollment(User $actor): void
    {
        if ($this->canAny($actor, ['manage-sections', 'manage-schedules'])) {
            return;
        }

        throw new AuthorizationException('Only authorized Registrar staff can start regular enrollment before Student Hub UI is enabled.');
    }

    /**
     * @param  list<string>  $permissions
     */
    private function canAny(User $actor, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($actor->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws ValidationException
     */
    private function assertApprovedForEnrollment(ApplicantIntake $intake): void
    {
        if ($intake->term_id === null) {
            throw ValidationException::withMessages([
                'term_id' => 'An approved applicant must have an enrollment term before handover can start.',
            ]);
        }

        if ($intake->status !== ApplicantIntake::StatusApproved || $intake->user?->status !== User::StatusApplicantApproved) {
            throw ValidationException::withMessages([
                'status' => 'Only approved applicants can be moved into enrollment.',
            ]);
        }
    }

    private function profileForApprovedApplicant(ApplicantIntake $intake, CarbonImmutable $timestamp): StudentProfile
    {
        $existing = StudentProfile::query()
            ->where('lrn', $intake->lrn)
            ->whereHas('user', function ($query) use ($intake) {
                $query->whereRaw('LOWER(first_name) = ?', [strtolower($intake->user->first_name ?? '')])
                    ->whereRaw('LOWER(last_name) = ?', [strtolower($intake->user->last_name ?? '')])
                    ->whereHas('applicantIntake', function ($subQuery) use ($intake) {
                        $subQuery->whereDate('birthdate', $intake->birthdate);
                    });
            })
            ->lockForUpdate()
            ->first();

        if ($existing instanceof StudentProfile) {
            if ($existing->user_id !== $intake->user_id) {
                $existing->user_id = $intake->user_id;
                $existing->save();
            }

            return $existing;
        }

        return StudentProfile::query()->create([
            'user_id' => $intake->user_id,
            'student_id' => $this->studentIdFor($intake),
            'lrn' => $intake->lrn,
            'program_id' => $intake->program_id,
            'year_level' => $intake->year_level,
            'operational_status' => 'Active',
            'modality' => $intake->preferred_modality,
            'current_balance' => '0.00',
            'hard_copy_received' => false,
            'last_status_changed_at' => $timestamp,
        ]);
    }

    private function studentIdFor(ApplicantIntake $intake): string
    {
        $year = $intake->term?->term_start_date?->format('Y')
            ?? CarbonImmutable::now(config('app.timezone'))->format('Y');

        $prefix = "SIA-{$year}-";

        $existingIds = StudentProfile::query()
            ->where('student_id', 'like', "{$prefix}%")
            ->pluck('student_id');

        $maxSequence = 0;
        foreach ($existingIds as $id) {
            $parts = explode('-', $id);
            if (count($parts) === 3 && $parts[0] === 'SIA' && $parts[1] === $year) {
                $seq = (int) $parts[2];
                if ($seq > $maxSequence) {
                    $maxSequence = $seq;
                }
            }
        }

        $nextSequence = $maxSequence + 1;

        return sprintf('SIA-%s-%04d', $year, $nextSequence);
    }

    private function linkApplicantDocuments(ApplicantIntake $intake, StudentProfile $studentProfile): void
    {
        DocumentUpload::query()
            ->where('applicant_intake_id', $intake->id)
            ->whereNull('student_profile_id')
            ->update([
                'student_profile_id' => $studentProfile->id,
            ]);
    }

    /**
     * @throws ValidationException
     */
    private function assertNoOutstandingBalance(StudentProfile $studentProfile): void
    {
        if (! $this->money->isZeroOrNegative((string) $studentProfile->current_balance)) {
            throw ValidationException::withMessages([
                'current_balance' => 'Outstanding balances must be cleared before regular enrollment can start.',
            ]);
        }
    }

    private function studentTypeFor(StudentProfile $studentProfile): string
    {
        $status = str((string) $studentProfile->operational_status)->lower()->squish()->toString();
        $userStatus = $studentProfile->user?->status;

        if (in_array($status, ['inactive', 'archived'], true) || in_array($userStatus, [User::StatusInactive, User::StatusArchived], true)) {
            return 'returnee';
        }

        return 'regular';
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function recordActivity(
        Enrollment $enrollment,
        string $event,
        ?User $causer,
        array $properties,
        CarbonImmutable $timestamp,
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'student_enrollment',
            'description' => 'Student enrollment lifecycle transition.',
            'subject_type' => Enrollment::class,
            'subject_id' => $enrollment->id,
            'event' => $event,
            'causer_type' => $causer instanceof User ? User::class : null,
            'causer_id' => $causer?->id,
            'properties' => json_encode($properties, JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }
}
