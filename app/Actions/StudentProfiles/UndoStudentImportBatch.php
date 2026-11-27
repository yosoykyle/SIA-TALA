<?php

namespace App\Actions\StudentProfiles;

use App\Actions\Academics\IrregularStudentReasons;
use App\Models\AcademicDecision;
use App\Models\ChecklistItem;
use App\Models\CompletionReadinessVersion;
use App\Models\DegreeConferral;
use App\Models\Enrollment;
use App\Models\GraduationApplication;
use App\Models\GraduationReviewMember;
use App\Models\Hold;
use App\Models\OperationalEvent;
use App\Models\PriorAcademicCredit;
use App\Models\StudentImportBatch;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\StudentProfileEvent;
use App\Models\TranscriptRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UndoStudentImportBatch
{
    /**
     * Evaluate whether an import batch is completely untouched and eligible for safe 1-click undo.
     *
     * @return array{allowed: bool, reason: ?string, student_count: int}
     */
    public function canUndo(StudentImportBatch $batch): array
    {
        if ($batch->isReverted()) {
            return [
                'allowed' => false,
                'reason' => 'This import batch has already been reverted.',
                'student_count' => 0,
            ];
        }

        $profiles = StudentProfile::query()
            ->with('user')
            ->where('import_batch_id', $batch->id)
            ->get();

        $blocker = $this->firstUndoBlocker($batch, $profiles);

        return [
            'allowed' => $blocker === null,
            'reason' => $blocker,
            'student_count' => $profiles->count(),
        ];
    }

    /**
     * Safely revert an untouched import batch, deleting created profiles, prior credits, holds,
     * academic decisions, and newly-created student users while preserving pre-existing linked users.
     *
     * @return array{reverted_profiles: int, deleted_users: int, preserved_users: int}
     */
    public function execute(StudentImportBatch $batch, User $actor): array
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only an authorized Registrar may revert a student import batch.');
        }

        $summary = DB::transaction(function () use ($batch, $actor): array {
            $lockedBatch = StudentImportBatch::query()
                ->lockForUpdate()
                ->findOrFail($batch->id);

            if ($lockedBatch->isReverted()) {
                throw ValidationException::withMessages([
                    'batch' => 'This import batch has already been reverted.',
                ]);
            }

            $profiles = StudentProfile::query()
                ->with('user')
                ->where('import_batch_id', $lockedBatch->id)
                ->lockForUpdate()
                ->get();

            $blocker = $this->firstUndoBlocker($lockedBatch, $profiles);
            if ($blocker !== null) {
                throw ValidationException::withMessages([
                    'batch' => $blocker,
                ]);
            }

            $revertedProfiles = 0;
            $deletedUsers = 0;
            $preservedUsers = 0;

            foreach ($profiles as $profile) {
                $user = $profile->user;
                $isPreExistingUser = $user instanceof User && $this->isPreExistingLinkedUser($user, $lockedBatch, $profile);

                PriorAcademicCredit::query()->where('student_profile_id', $profile->id)->delete();
                Hold::query()->where('student_profile_id', $profile->id)->delete();
                AcademicDecision::query()->where('student_profile_id', $profile->id)->delete();
                CompletionReadinessVersion::query()->where('student_profile_id', $profile->id)->delete();
                ChecklistItem::query()->where('student_profile_id', $profile->id)->delete();
                StudentProfileEvent::query()->where('student_profile_id', $profile->id)->delete();
                OperationalEvent::query()
                    ->where('related_record_type', StudentProfile::class)
                    ->where('related_record_id', $profile->id)
                    ->delete();

                $profile->delete();
                $revertedProfiles++;

                if (! $user instanceof User) {
                    continue;
                }

                if ($isPreExistingUser) {
                    if (! StudentProfile::query()->where('user_id', $user->id)->exists()) {
                        $user->removeRole('student');
                    }
                    $preservedUsers++;
                } else {
                    if (filled($user->email)) {
                        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                    }
                    OperationalEvent::query()->where('user_id', $user->id)->delete();
                    $user->syncRoles([]);
                    $user->delete();
                    $deletedUsers++;
                }
            }

            $lockedBatch->forceFill([
                'reverted_at' => now(),
                'reverted_by' => $actor->id,
            ])->save();

            activity('student_onboarding')
                ->performedOn($lockedBatch)
                ->causedBy($actor)
                ->event('student_import_batch_reverted')
                ->withProperties([
                    'import_batch_id' => $lockedBatch->id,
                    'reverted_profiles' => $revertedProfiles,
                    'deleted_users' => $deletedUsers,
                    'preserved_users' => $preservedUsers,
                ])
                ->log("Reverted student import batch #{$lockedBatch->id} ({$revertedProfiles} profile(s))");

            return [
                'reverted_profiles' => $revertedProfiles,
                'deleted_users' => $deletedUsers,
                'preserved_users' => $preservedUsers,
            ];
        }, attempts: 3);

        Cache::forget(IrregularStudentReasons::ReviewCountCacheKey);

        return $summary;
    }

    /**
     * @param  Collection<int, StudentProfile>  $profiles
     */
    private function firstUndoBlocker(StudentImportBatch $batch, Collection $profiles): ?string
    {
        if ($profiles->isEmpty()) {
            return null;
        }

        $profileIds = $profiles->pluck('id')->all();

        foreach ($profiles as $profile) {
            $user = $profile->user;
            if (! $user instanceof User) {
                continue;
            }

            $isPreExisting = $this->isPreExistingLinkedUser($user, $batch, $profile);

            if ($isPreExisting) {
                if ($user->last_successful_sign_in_at !== null
                    && $batch->created_at !== null
                    && $user->last_successful_sign_in_at->gte($batch->created_at)) {
                    return 'This import batch cannot be undone because a linked student has already signed in after import.';
                }
            } else {
                if ($user->last_successful_sign_in_at !== null) {
                    return 'This import batch cannot be undone because one or more students have already signed in.';
                }

                if (filled($user->password) || $user->email_verified_at !== null) {
                    return 'This import batch cannot be undone because one or more students have already set a password or activated their account.';
                }
            }
        }

        if (Enrollment::query()->whereIn('student_profile_id', $profileIds)->exists()) {
            return 'This import batch cannot be undone because one or more students already have an enrollment record.';
        }

        if (TranscriptRequest::query()->whereIn('student_profile_id', $profileIds)->exists()
            || DegreeConferral::query()->whereIn('student_profile_id', $profileIds)->exists()
            || GraduationApplication::query()->whereIn('student_profile_id', $profileIds)->exists()
            || GraduationReviewMember::query()->whereIn('student_profile_id', $profileIds)->exists()
            || StudentLifecycleChange::query()->whereIn('student_profile_id', $profileIds)->exists()) {
            return 'This import batch cannot be undone because one or more students already have downstream academic or graduation records.';
        }

        return null;
    }

    private function isPreExistingLinkedUser(User $user, StudentImportBatch $batch, StudentProfile $profile): bool
    {
        if ($user->isStaffCapable()
            || $user->hasAssignedRole('applicant')
            || $user->applicantIntakes()->exists()
            || $user->admissionApplications()->exists()) {
            return true;
        }

        if (StudentProfile::query()->where('user_id', $user->id)->whereKeyNot($profile->id)->exists()) {
            return true;
        }

        if ($user->created_at !== null && $batch->created_at !== null && $user->created_at->lt($batch->created_at->copy()->subSeconds(5))) {
            return true;
        }

        return false;
    }
}
