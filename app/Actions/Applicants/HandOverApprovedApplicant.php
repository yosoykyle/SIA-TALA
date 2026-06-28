<?php

namespace App\Actions\Applicants;

use App\Models\ApplicantIntake;
use App\Models\ChecklistItem;
use App\Models\CurriculumVersion;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HandOverApprovedApplicant
{
    public function execute(
        ApplicantIntake $intake,
        User $actor,
        ?StudentProfile $confirmedExistingProfile = null,
    ): StudentProfile {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff may hand over an approved applicant.');
        }

        return DB::transaction(function () use ($intake, $actor, $confirmedExistingProfile): StudentProfile {
            $lockedIntake = ApplicantIntake::query()->lockForUpdate()->findOrFail($intake->id);

            if ($lockedIntake->handed_over_at !== null) {
                return $this->previouslyHandedOverProfile($lockedIntake, $confirmedExistingProfile);
            }

            if ($lockedIntake->status !== ApplicantIntake::StatusApproved || $lockedIntake->approved_at === null) {
                throw ValidationException::withMessages([
                    'status' => 'Only an approved applicant intake can be handed over.',
                ]);
            }

            $this->assertNoHandoverBlockers($lockedIntake);
            $curriculumVersion = $this->activeCurriculumFor($lockedIntake);
            $studentProfile = $confirmedExistingProfile instanceof StudentProfile
                ? $this->confirmExistingProfile($confirmedExistingProfile, $lockedIntake, $curriculumVersion)
                : $this->createProfile($lockedIntake, $curriculumVersion);

            $this->carryForwardRelevantChecklistItems($lockedIntake, $studentProfile);
            $this->transitionWorkspaceAccess($lockedIntake, $studentProfile);

            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $lockedIntake->forceFill([
                'handed_over_at' => $timestamp,
                'handed_over_by' => $actor->id,
            ])->save();

            return $studentProfile->refresh()->load('curriculumVersion');
        }, attempts: 3);
    }

    private function previouslyHandedOverProfile(
        ApplicantIntake $intake,
        ?StudentProfile $confirmedExistingProfile,
    ): StudentProfile {
        $createdProfile = StudentProfile::query()
            ->where('applicant_intake_id', $intake->id)
            ->first();

        if ($createdProfile instanceof StudentProfile) {
            return $createdProfile;
        }

        if ($confirmedExistingProfile instanceof StudentProfile) {
            return StudentProfile::query()->findOrFail($confirmedExistingProfile->id);
        }

        throw ValidationException::withMessages([
            'handover' => 'This intake was already handed over to a confirmed existing profile.',
        ]);
    }

    private function assertNoHandoverBlockers(ApplicantIntake $intake): void
    {
        $hasBlocker = $intake->checklistItems()
            ->where('blocking_level', ChecklistItem::BlockingHandover)
            ->get()
            ->contains(fn (ChecklistItem $item): bool => ! $item->isResolved());

        if ($hasBlocker) {
            throw ValidationException::withMessages([
                'checklist' => 'Handover is blocked by unresolved BLOCKS_HANDOVER checklist items.',
            ]);
        }
    }

    private function activeCurriculumFor(ApplicantIntake $intake): CurriculumVersion
    {
        $activeVersions = CurriculumVersion::query()
            ->where('program_id', $intake->program_id)
            ->where('state', CurriculumVersion::StateActive)
            ->lockForUpdate()
            ->get();

        if ($activeVersions->count() !== 1) {
            throw ValidationException::withMessages([
                'curriculum_version' => 'The selected program must have exactly one active curriculum version.',
            ]);
        }

        return $activeVersions->sole();
    }

    private function createProfile(ApplicantIntake $intake, CurriculumVersion $curriculumVersion): StudentProfile
    {
        return StudentProfile::query()->create([
            ...$this->profileAttributes($intake, $curriculumVersion),
            'user_id' => $intake->user_id,
            'applicant_intake_id' => $intake->id,
            'student_number' => $this->nextStudentNumber(),
            'lifecycle_status' => StudentProfile::LifecycleActive,
            'academic_standing' => StudentProfile::StandingGood,
        ]);
    }

    private function confirmExistingProfile(
        StudentProfile $profile,
        ApplicantIntake $intake,
        CurriculumVersion $curriculumVersion,
    ): StudentProfile {
        $lockedProfile = StudentProfile::query()->lockForUpdate()->findOrFail($profile->id);

        if ($lockedProfile->archived_at !== null || $lockedProfile->merged_into_id !== null) {
            throw ValidationException::withMessages([
                'student_profile' => 'The confirmed existing profile must be active and unmerged.',
            ]);
        }

        $lockedProfile->fill($this->profileAttributes($intake, $curriculumVersion));

        if ($lockedProfile->applicant_intake_id === null) {
            $lockedProfile->applicant_intake_id = $intake->id;
        }

        $lockedProfile->save();

        return $lockedProfile;
    }

    /** @return array<string, mixed> */
    private function profileAttributes(ApplicantIntake $intake, CurriculumVersion $curriculumVersion): array
    {
        return [
            'first_name' => $intake->first_name,
            'middle_name' => $intake->middle_name,
            'last_name' => $intake->last_name,
            'birth_date' => $intake->birth_date,
            'program_id' => $intake->program_id,
            'curriculum_version_id' => $curriculumVersion->id,
            'email' => $intake->email,
            'phone' => $intake->phone,
        ];
    }

    private function nextStudentNumber(): string
    {
        $prefix = 'SIA-'.now(config('app.timezone'))->year.'-';
        $lastNumber = StudentProfile::query()
            ->where('student_number', 'like', $prefix.'%')
            ->orderByDesc('student_number')
            ->lockForUpdate()
            ->value('student_number');
        $sequence = $lastNumber === null ? 1 : ((int) mb_substr($lastNumber, -4)) + 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    private function carryForwardRelevantChecklistItems(
        ApplicantIntake $intake,
        StudentProfile $studentProfile,
    ): void {
        $items = $intake->checklistItems()->get()
            ->filter(fn (ChecklistItem $item): bool => $item->remainsRelevantAfterHandover());

        foreach ($items as $item) {
            ChecklistItem::query()->updateOrCreate(
                [
                    'student_profile_id' => $studentProfile->id,
                    'source_policy_id' => $item->source_policy_id,
                ],
                [
                    'owner_type' => ChecklistItem::OwnerStudent,
                    'applicant_intake_id' => null,
                    'requirement_type' => $item->requirement_type,
                    'status' => $item->status,
                    'blocking_level' => $item->blocking_level,
                    'evidence_method' => $item->evidence_method,
                    'verification_status' => $item->verification_status,
                    'deadline' => $item->deadline,
                    'reviewed_by' => $item->reviewed_by,
                    'reviewed_at' => $item->reviewed_at,
                    'waiver_reason' => $item->waiver_reason,
                    'undertaking_terms' => $item->undertaking_terms,
                ],
            );
        }
    }

    private function transitionWorkspaceAccess(ApplicantIntake $intake, StudentProfile $studentProfile): void
    {
        $officialUser = $studentProfile->user()->lockForUpdate()->firstOrFail();
        $officialUser->forceFill([
            ...User::staffNamePayload($intake->first_name, $intake->middle_name, $intake->last_name),
            'status' => User::StatusActive,
        ])->save();
        $officialUser->syncRoles(['student']);

        if ($officialUser->id !== $intake->user_id) {
            User::query()->whereKey($intake->user_id)->lockForUpdate()->firstOrFail()->forceFill([
                'status' => User::StatusArchived,
                'archived_at' => now(config('app.timezone')),
            ])->save();
        }
    }
}
