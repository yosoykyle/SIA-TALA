<?php

namespace App\Actions\Enrollment;

use App\Models\PersonalDataCorrectionRequest;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PersonalDataCorrectionService
{
    /**
     * Submit a personal data correction request.
     *
     * @throws ValidationException
     */
    public function submitRequest(StudentProfile $studentProfile, array $requestedChanges): PersonalDataCorrectionRequest
    {
        $allowedFields = ['first_name', 'middle_name', 'last_name', 'birthdate', 'lrn'];

        // 1. Validate that requestedChanges only contains supported keys
        $invalidKeys = array_diff(array_keys($requestedChanges), $allowedFields);
        if (! empty($invalidKeys)) {
            throw ValidationException::withMessages([
                'requested_changes' => 'Invalid fields requested for correction.',
            ]);
        }

        // 2. Validate that at least one change is present and different from current values
        $hasDifference = false;
        $user = $studentProfile->user;
        $applicantIntake = $user?->applicantIntake;

        foreach ($requestedChanges as $key => $newValue) {
            if ($key === 'first_name') {
                if (($user?->first_name) !== $newValue) {
                    $hasDifference = true;
                }
            } elseif ($key === 'middle_name') {
                if (($user?->middle_name) !== $newValue) {
                    $hasDifference = true;
                }
            } elseif ($key === 'last_name') {
                if (($user?->last_name) !== $newValue) {
                    $hasDifference = true;
                }
            } elseif ($key === 'lrn') {
                if ($studentProfile->lrn !== $newValue) {
                    $hasDifference = true;
                }
            } elseif ($key === 'birthdate') {
                $currentBirthdate = $applicantIntake?->birthdate;

                $currentBirthdateStr = null;
                if ($currentBirthdate instanceof \DateTimeInterface) {
                    $currentBirthdateStr = $currentBirthdate->format('Y-m-d');
                } elseif (is_string($currentBirthdate)) {
                    $currentBirthdateStr = date('Y-m-d', strtotime($currentBirthdate));
                }

                $newBirthdateStr = null;
                if ($newValue instanceof \DateTimeInterface) {
                    $newBirthdateStr = $newValue->format('Y-m-d');
                } elseif (is_string($newValue)) {
                    $newBirthdateStr = date('Y-m-d', strtotime($newValue));
                }

                if ($currentBirthdateStr !== $newBirthdateStr) {
                    $hasDifference = true;
                }
            }
        }

        if (empty($requestedChanges) || ! $hasDifference) {
            throw ValidationException::withMessages([
                'requested_changes' => 'At least one requested change must be different from current values.',
            ]);
        }

        // 3. Ensure student doesn't already have a pending correction request
        $pendingExists = PersonalDataCorrectionRequest::query()
            ->where('student_profile_id', $studentProfile->id)
            ->where('status', PersonalDataCorrectionRequest::STATUS_PENDING)
            ->exists();

        if ($pendingExists) {
            throw ValidationException::withMessages([
                'student_profile_id' => 'A pending correction request already exists for this student profile.',
            ]);
        }

        // 4. Save and return request
        return PersonalDataCorrectionRequest::query()->create([
            'student_profile_id' => $studentProfile->id,
            'status' => PersonalDataCorrectionRequest::STATUS_PENDING,
            'requested_changes' => $requestedChanges,
        ]);
    }

    /**
     * Resolve a personal data correction request.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function resolveRequest(
        PersonalDataCorrectionRequest $request,
        User $actor,
        string $action,
        ?string $rejectReason = null
    ): PersonalDataCorrectionRequest {
        // 1. Assert actor has role 'registrar'
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Unauthorized action. Only registrars can resolve requests.');
        }

        // 2. Assert request is pending
        if ($request->status !== PersonalDataCorrectionRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => 'Only pending requests can be resolved.',
            ]);
        }

        // 3. Validate action
        if (! in_array($action, ['approve', 'reject'], true)) {
            throw ValidationException::withMessages([
                'action' => 'Invalid resolution action.',
            ]);
        }

        // 4. Run in database transaction
        return DB::transaction(function () use ($request, $actor, $action, $rejectReason) {
            // Relock request for update
            $request = PersonalDataCorrectionRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($request->status !== PersonalDataCorrectionRequest::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'status' => 'Only pending requests can be resolved.',
                ]);
            }

            if ($action === 'reject') {
                $request->forceFill([
                    'status' => PersonalDataCorrectionRequest::STATUS_REJECTED,
                    'resolved_by' => $actor->id,
                    'resolved_at' => Carbon::now(),
                    'reject_reason' => $rejectReason,
                ])->save();
            } elseif ($action === 'approve') {
                $studentProfile = $request->studentProfile()->lockForUpdate()->firstOrFail();
                $user = $studentProfile->user()->lockForUpdate()->firstOrFail();
                $applicantIntake = $user->applicantIntake()->lockForUpdate()->first();

                // Populate old_values
                $currentBirthdate = $applicantIntake?->birthdate;
                $currentBirthdateStr = null;
                if ($currentBirthdate instanceof \DateTimeInterface) {
                    $currentBirthdateStr = $currentBirthdate->format('Y-m-d');
                } elseif (is_string($currentBirthdate)) {
                    $currentBirthdateStr = date('Y-m-d', strtotime($currentBirthdate));
                }

                $oldValues = [
                    'first_name' => $user->first_name,
                    'middle_name' => $user->middle_name,
                    'last_name' => $user->last_name,
                    'lrn' => $studentProfile->lrn,
                    'birthdate' => $currentBirthdateStr,
                ];

                $changes = $request->requested_changes;

                // Update User model
                $userUpdates = [];
                if (array_key_exists('first_name', $changes)) {
                    $userUpdates['first_name'] = $changes['first_name'];
                }
                if (array_key_exists('middle_name', $changes)) {
                    $userUpdates['middle_name'] = $changes['middle_name'];
                }
                if (array_key_exists('last_name', $changes)) {
                    $userUpdates['last_name'] = $changes['last_name'];
                }
                if (! empty($userUpdates)) {
                    $user->forceFill($userUpdates)->save();
                }

                // Update StudentProfile model
                if (array_key_exists('lrn', $changes)) {
                    $studentProfile->forceFill([
                        'lrn' => $changes['lrn'],
                    ])->save();
                }

                // Update ApplicantIntake model
                if ($applicantIntake) {
                    $intakeUpdates = [];
                    if (array_key_exists('birthdate', $changes)) {
                        $intakeUpdates['birthdate'] = $changes['birthdate'];
                    }
                    if (array_key_exists('lrn', $changes)) {
                        $intakeUpdates['lrn'] = $changes['lrn'];
                    }
                    if (! empty($intakeUpdates)) {
                        $applicantIntake->forceFill($intakeUpdates)->save();
                    }
                }

                // Update Request model
                $request->forceFill([
                    'status' => PersonalDataCorrectionRequest::STATUS_APPROVED,
                    'old_values' => $oldValues,
                    'resolved_by' => $actor->id,
                    'resolved_at' => Carbon::now(),
                ])->save();
            }

            return $request;
        });
    }
}
