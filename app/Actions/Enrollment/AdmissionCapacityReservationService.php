<?php

namespace App\Actions\Enrollment;

use App\Models\AdmissionCapacityPlan;
use App\Models\AdmissionCapacityReservation;
use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\StudentProfile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AdmissionCapacityReservationService
{
    /**
     * @throws ValidationException
     */
    public function secureForFinanceClearedEnrollment(
        Enrollment $enrollment,
        StudentProfile $studentProfile,
        ?Payment $payment,
        ?LedgerEntry $ledgerEntry,
        CarbonImmutable $securedAt,
    ): void {
        $plans = $this->matchingApprovedPlans($enrollment, $studentProfile, forUpdate: true);

        if ($plans->isEmpty()) {
            return;
        }

        foreach ($plans as $plan) {
            $existing = AdmissionCapacityReservation::query()
                ->where('admission_capacity_plan_id', $plan->id)
                ->where('enrollment_id', $enrollment->id)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof AdmissionCapacityReservation) {
                continue;
            }

            if ((int) $plan->reserved_count >= (int) $plan->capacity_limit) {
                throw ValidationException::withMessages([
                    'admission_capacity' => 'Admission capacity is already full for one or more matching capacity plans.',
                ]);
            }

            AdmissionCapacityReservation::query()->create([
                'admission_capacity_plan_id' => $plan->id,
                'enrollment_id' => $enrollment->id,
                'student_profile_id' => $studentProfile->id,
                'payment_id' => $payment?->id,
                'ledger_entry_id' => $ledgerEntry?->id,
                'status' => AdmissionCapacityReservation::StatusSecured,
                'secured_at' => $securedAt,
                'scope_snapshot' => $this->scopeSnapshot($plan, $enrollment, $studentProfile),
                'meta' => [
                    'source' => 'finance_clearance',
                ],
            ]);

            $plan->forceFill([
                'reserved_count' => (int) $plan->reserved_count + 1,
            ])->save();
        }
    }

    /**
     * @return Collection<int, AdmissionCapacityPlan>
     */
    public function matchingApprovedPlans(Enrollment $enrollment, StudentProfile $studentProfile, bool $forUpdate = false): Collection
    {
        $query = AdmissionCapacityPlan::query()
            ->where('term_id', $enrollment->term_id)
            ->where('status', AdmissionCapacityPlan::StatusApproved)
            ->where(function (Builder $builder): void {
                $builder->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', CarbonImmutable::now(config('app.timezone')));
            })
            ->where(function (Builder $builder): void {
                $builder->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', CarbonImmutable::now(config('app.timezone')));
            })
            ->where(function (Builder $builder) use ($studentProfile): void {
                $builder->whereNull('education_level')
                    ->orWhere('education_level', $studentProfile->education_level);
            })
            ->where(function (Builder $builder) use ($studentProfile): void {
                $builder->whereNull('program_id');

                if ($studentProfile->program_id !== null) {
                    $builder->orWhere('program_id', $studentProfile->program_id);
                }
            })
            ->where(function (Builder $builder) use ($enrollment): void {
                $builder->whereNull('year_level');

                if ($enrollment->year_level !== null) {
                    $builder->orWhere('year_level', $enrollment->year_level);
                }
            })
            ->where(function (Builder $builder) use ($enrollment): void {
                $builder->whereNull('delivery_setup');

                if ($enrollment->modality !== null) {
                    $builder->orWhere('delivery_setup', $enrollment->modality);
                }
            })
            ->orderByRaw('CASE scope_type WHEN ? THEN 1 WHEN ? THEN 2 WHEN ? THEN 3 WHEN ? THEN 4 WHEN ? THEN 5 ELSE 9 END', [
                AdmissionCapacityPlan::ScopeCampus,
                AdmissionCapacityPlan::ScopeEducationLevel,
                AdmissionCapacityPlan::ScopeProgram,
                AdmissionCapacityPlan::ScopeYearLevel,
                AdmissionCapacityPlan::ScopeDeliverySetup,
            ])
            ->orderBy('id');

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function scopeSnapshot(AdmissionCapacityPlan $plan, Enrollment $enrollment, StudentProfile $studentProfile): array
    {
        return [
            'plan_scope_type' => $plan->scope_type,
            'term_id' => $enrollment->term_id,
            'education_level' => $studentProfile->education_level,
            'program_id' => $studentProfile->program_id,
            'year_level' => $enrollment->year_level,
            'delivery_setup' => $enrollment->modality,
        ];
    }
}
