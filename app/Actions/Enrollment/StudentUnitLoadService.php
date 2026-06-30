<?php

namespace App\Actions\Enrollment;

use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\EnrollmentException;
use App\Models\EnrollmentGateResult;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StudentUnitLoadService
{
    /** @return array<string, mixed> */
    public function evaluate(Enrollment $enrollment, float $requestedTotal, float $configuredCap, ?string $yearLevel = null): array
    {
        $enrollment->loadMissing(['studentProfile', 'term']);
        $normalLoad = $this->normalLoad($enrollment, $yearLevel);
        $exception = $this->activeException($enrollment);
        $approvedExcess = (float) data_get($exception?->approved_values, 'approved_excess', 0);
        $approvedLimit = min($configuredCap, $normalLoad + $approvedExcess);
        $otherFailedGates = EnrollmentGateResult::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('result', 'FAILED')
            ->where('gate_type', '!=', EnrollmentException::TypeUnitLoad)
            ->pluck('gate_type')
            ->values()
            ->all();

        return [
            'enrollment_id' => (int) $enrollment->id,
            'normal_load' => number_format($normalLoad, 2, '.', ''),
            'requested_total' => number_format($requestedTotal, 2, '.', ''),
            'configured_cap' => number_format($configuredCap, 2, '.', ''),
            'approved_excess' => number_format($approvedExcess, 2, '.', ''),
            'approved_limit' => number_format($approvedLimit, 2, '.', ''),
            'requires_exception' => $requestedTotal > $normalLoad,
            'has_active_exception' => $exception instanceof EnrollmentException,
            'unit_load_passes' => $requestedTotal <= $normalLoad || ($exception instanceof EnrollmentException && $requestedTotal <= $approvedLimit),
            'other_failed_gates' => $otherFailedGates,
            'all_gates_pass' => ($requestedTotal <= $normalLoad || ($exception instanceof EnrollmentException && $requestedTotal <= $approvedLimit)) && $otherFailedGates === [],
        ];
    }

    /** @param array<string,mixed> $data */
    public function approve(Enrollment $enrollment, array $data, User $actor): EnrollmentException
    {
        if (! $actor->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAcademicHead, User::StaffRoleSystemSuperAdmin])) {
            throw new AuthorizationException('Only authorized academic staff may approve a unit-load exception.');
        }

        foreach (['normal_limit', 'requested_total', 'configured_cap', 'authority', 'reason', 'evidence_reference'] as $required) {
            if (blank($data[$required] ?? null)) {
                throw new RuntimeException("Unit-load exception field [$required] is required.");
            }
        }

        $normal = (float) $data['normal_limit'];
        $requested = (float) $data['requested_total'];
        $cap = (float) $data['configured_cap'];

        if ($requested <= $normal || $requested > $cap) {
            throw new RuntimeException('Requested load must exceed the normal load and remain within the configured cap.');
        }

        return DB::transaction(function () use ($enrollment, $data, $actor, $normal, $requested, $cap): EnrollmentException {
            $locked = Enrollment::query()->lockForUpdate()->findOrFail($enrollment->id);

            return EnrollmentException::query()->updateOrCreate(
                [
                    'enrollment_id' => $locked->id,
                    'exception_type' => EnrollmentException::TypeUnitLoad,
                    'scope_key' => 'unit_load:'.$locked->term_id,
                ],
                [
                    'student_profile_id' => $locked->student_profile_id,
                    'term_id' => $locked->term_id,
                    'requested_values' => ['requested_total' => $requested],
                    'approved_values' => [
                        'normal_limit' => $normal,
                        'requested_total' => $requested,
                        'approved_excess' => $requested - $normal,
                        'configured_cap' => $cap,
                        'affected_term_offering_ids' => array_values($data['affected_term_offering_ids'] ?? []),
                        'authority' => (string) $data['authority'],
                    ],
                    'reason' => (string) $data['reason'],
                    'evidence_reference' => (string) $data['evidence_reference'],
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                    'expires_at' => $data['expires_at'] ?? null,
                    'state' => EnrollmentException::StateActive,
                ],
            );
        }, attempts: 3);
    }

    private function normalLoad(Enrollment $enrollment, ?string $yearLevel): float
    {
        $query = CurriculumEntry::query()
            ->where('curriculum_version_id', $enrollment->studentProfile->curriculum_version_id)
            ->where('term_type', $enrollment->term->type)
            ->when($yearLevel, fn ($query) => $query->where('year_level', $yearLevel));
        $normal = (float) $query
            ->join('course_specifications', 'curriculum_entries.course_specification_id', '=', 'course_specifications.id')
            ->sum('course_specifications.credit_units');

        return $normal > 0 ? $normal : (float) ($enrollment->term->default_max_units ?? 0);
    }

    private function activeException(Enrollment $enrollment): ?EnrollmentException
    {
        return EnrollmentException::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('student_profile_id', $enrollment->student_profile_id)
            ->where('term_id', $enrollment->term_id)
            ->where('exception_type', EnrollmentException::TypeUnitLoad)
            ->where('state', EnrollmentException::StateActive)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('approved_at')
            ->first();
    }
}
