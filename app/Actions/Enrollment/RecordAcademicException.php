<?php

namespace App\Actions\Enrollment;

use App\Models\Enrollment;
use App\Models\EnrollmentException;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordAcademicException
{
    /** @return list<string> */
    public static function allowedTypes(): array
    {
        return [
            EnrollmentException::TypePrerequisite,
            EnrollmentException::TypeCorequisite,
            EnrollmentException::TypeBridging,
            EnrollmentException::TypeConflict,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(Enrollment $enrollment, array $data, User $actor, ?CarbonImmutable $recordedAt = null): EnrollmentException
    {
        if (! $actor->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAcademicHead, User::StaffRoleSystemSuperAdmin])) {
            throw new AuthorizationException('Only authorized Registrar or Academic Head staff may record approved academic exceptions.');
        }

        $recordedAt ??= CarbonImmutable::now();
        $exceptionType = (string) ($data['exception_type'] ?? '');

        if (! in_array($exceptionType, self::allowedTypes(), true)) {
            throw ValidationException::withMessages([
                'exception_type' => 'Record a typed academic exception; generic gate overrides and unit-load exceptions are not accepted here.',
            ]);
        }

        foreach (['target_term_offering_id', 'original_rule', 'authority', 'reason', 'evidence_reference', 'expires_at'] as $required) {
            if (blank($data[$required] ?? null)) {
                throw ValidationException::withMessages([
                    $required => 'This field is required to record the approved academic exception.',
                ]);
            }
        }

        return DB::transaction(function () use ($enrollment, $data, $actor, $recordedAt, $exceptionType): EnrollmentException {
            $lockedEnrollment = Enrollment::query()
                ->with('studentProfile')
                ->lockForUpdate()
                ->findOrFail($enrollment->id);
            $targetOffering = TermOffering::query()
                ->whereKey((int) $data['target_term_offering_id'])
                ->where('term_id', $lockedEnrollment->term_id)
                ->first();

            if (! $targetOffering instanceof TermOffering) {
                throw ValidationException::withMessages([
                    'target_term_offering_id' => 'The selected offering must belong to this enrollment term.',
                ]);
            }

            $expiresAt = CarbonImmutable::parse((string) $data['expires_at']);

            if ($expiresAt->lte($recordedAt)) {
                throw ValidationException::withMessages([
                    'expires_at' => 'The effective period must end in the future.',
                ]);
            }

            $originalRule = trim((string) $data['original_rule']);
            $scopeKey = strtolower($exceptionType).':'.$targetOffering->id.':'.sha1($originalRule);

            return EnrollmentException::query()->updateOrCreate(
                [
                    'enrollment_id' => $lockedEnrollment->id,
                    'exception_type' => $exceptionType,
                    'scope_key' => $scopeKey,
                ],
                [
                    'student_profile_id' => $lockedEnrollment->student_profile_id,
                    'term_id' => $lockedEnrollment->term_id,
                    'enrollment_gate_result_id' => null,
                    'target_term_offering_id' => $targetOffering->id,
                    'original_failed_result' => (string) ($data['original_failed_result'] ?? 'academic_progression_blocker'),
                    'original_rule' => $originalRule,
                    'expires_at' => $expiresAt,
                    'requested_values' => [
                        'exception_type' => $exceptionType,
                        'target_term_offering_id' => $targetOffering->id,
                        'original_rule' => $originalRule,
                    ],
                    'approved_values' => [
                        'authority' => trim((string) $data['authority']),
                        'recorded_by_user_id' => $actor->id,
                        'recorded_by_role' => $this->recordingRole($actor),
                        'approval_recorded_at' => $recordedAt->toDateTimeString(),
                    ],
                    'reason' => trim((string) $data['reason']),
                    'evidence_reference' => trim((string) $data['evidence_reference']),
                    'requested_by' => $actor->id,
                    'approved_by' => $actor->id,
                    'approved_at' => $recordedAt,
                    'state' => EnrollmentException::StateActive,
                ],
            );
        }, attempts: 3);
    }

    private function recordingRole(User $actor): string
    {
        return collect([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleSystemSuperAdmin,
        ])->first(fn (string $role): bool => $actor->hasRole($role)) ?? 'unknown';
    }
}
