<?php

namespace App\Actions\StudentLifecycle;

use App\Models\Enrollment;
use App\Models\FinancialAccommodation;
use App\Models\Hold;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class HoldEvaluationService
{
    /**
     * @param  list<string>  $blockingLevels
     * @return Collection<int, Hold>
     */
    public function activeBlockingHolds(
        StudentProfile $studentProfile,
        array $blockingLevels,
        ?Enrollment $enrollment = null,
    ): Collection {
        $query = Hold::query()
            ->where('student_profile_id', $studentProfile->id)
            ->where('status', Hold::StatusActive)
            ->whereIn('blocking_level', $blockingLevels)
            ->where(function (Builder $query): void {
                $query->whereNull('effective_at')
                    ->orWhere('effective_at', '<=', now());
            })
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });

        if ($enrollment instanceof Enrollment) {
            $query->where(function (Builder $query) use ($enrollment): void {
                $query->whereNull('term_id')
                    ->orWhere('term_id', $enrollment->term_id);
            })->where(function (Builder $query) use ($enrollment): void {
                $query->whereNull('enrollment_id')
                    ->orWhere('enrollment_id', $enrollment->id);
            });
        }

        $holds = $query
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $accommodation = $this->activeAccommodation($studentProfile, $enrollment);

        return $holds
            ->reject(fn (Hold $hold): bool => $hold->hold_type === Hold::TypeFinancial
                && $this->accommodationAllows($accommodation, $hold->blocking_level, $enrollment))
            ->values();
    }

    /**
     * @param  list<string>  $blockingLevels
     */
    public function hasActiveBlockingHold(
        StudentProfile $studentProfile,
        array $blockingLevels,
        ?Enrollment $enrollment = null,
    ): bool {
        return $this->activeBlockingHolds($studentProfile, $blockingLevels, $enrollment)->isNotEmpty();
    }

    public function mostRestrictiveActiveHold(StudentProfile $studentProfile, array $blockingLevels, ?Enrollment $enrollment = null): ?Hold
    {
        $priority = array_flip([
            Hold::BlockingReactivation,
            Hold::BlockingRecordRelease,
            Hold::BlockingEnrollment,
            Hold::BlockingCorPrint,
            Hold::BlockingClearance,
            Hold::BlockingGraduationEligibility,
            Hold::BlockingAdvisoryOnly,
        ]);

        return $this->activeBlockingHolds($studentProfile, $blockingLevels, $enrollment)
            ->sortBy(fn (Hold $hold): int => $priority[$hold->blocking_level] ?? PHP_INT_MAX)
            ->first();
    }

    private function activeAccommodation(StudentProfile $studentProfile, ?Enrollment $enrollment): ?FinancialAccommodation
    {
        return FinancialAccommodation::query()
            ->where('student_profile_id', $studentProfile->id)
            ->whereIn('status', [FinancialAccommodation::StatusActive, 'active'])
            ->where('effective_from', '<=', today())
            ->where(fn (Builder $query) => $query->whereNull('expires_on')->orWhere('expires_on', '>=', today()))
            ->when($enrollment, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('term_id', $enrollment->term_id)
                ->orWhere('allows_next_term_enrollment', true)))
            ->latest('id')
            ->first();
    }

    private function accommodationAllows(?FinancialAccommodation $accommodation, string $blockingLevel, ?Enrollment $enrollment): bool
    {
        if (! $accommodation instanceof FinancialAccommodation) {
            return false;
        }

        return match ($blockingLevel) {
            Hold::BlockingEnrollment => (int) $accommodation->term_id === (int) $enrollment?->term_id
                ? $accommodation->allows_finance_gate
                : $accommodation->allows_next_term_enrollment,
            Hold::BlockingReactivation => $accommodation->allows_reactivation,
            Hold::BlockingRecordRelease => $accommodation->allows_record_release,
            default => false,
        };
    }
}
