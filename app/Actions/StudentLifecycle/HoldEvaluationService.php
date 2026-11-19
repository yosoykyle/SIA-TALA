<?php

namespace App\Actions\StudentLifecycle;

use App\Models\Enrollment;
use App\Models\FinancialAccommodation;
use App\Models\Hold;
use App\Models\StudentProfile;
use App\Models\Term;
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
        ?Term $term = null,
    ): Collection {
        $termId = $enrollment?->term_id ?? $term?->id;

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

        if ($termId !== null) {
            $query->where(function (Builder $query) use ($termId): void {
                $query->whereNull('term_id')
                    ->orWhere('term_id', $termId);
            });
        }

        if ($enrollment instanceof Enrollment) {
            $query->where(function (Builder $query) use ($enrollment): void {
                $query->whereNull('enrollment_id')
                    ->orWhere('enrollment_id', $enrollment->id);
            });
        } elseif ($term instanceof Term) {
            $query->whereNull('enrollment_id');
        }

        $holds = $query
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $accommodation = $this->activeAccommodation($studentProfile, $termId);

        return $holds
            ->reject(fn (Hold $hold): bool => $hold->hold_type === Hold::TypeFinancial
                && $this->accommodationAllows($accommodation, $hold->blocking_level, $termId))
            ->values();
    }

    /**
     * @param  list<string>  $blockingLevels
     */
    public function hasActiveBlockingHold(
        StudentProfile $studentProfile,
        array $blockingLevels,
        ?Enrollment $enrollment = null,
        ?Term $term = null,
    ): bool {
        return $this->activeBlockingHolds($studentProfile, $blockingLevels, $enrollment, $term)->isNotEmpty();
    }

    /**
     * @param  list<string>  $blockingLevels
     */
    public function mostRestrictiveActiveHold(
        StudentProfile $studentProfile,
        array $blockingLevels,
        ?Enrollment $enrollment = null,
        ?Term $term = null,
    ): ?Hold {
        $priority = array_flip([
            Hold::BlockingReactivation,
            Hold::BlockingRecordRelease,
            Hold::BlockingEnrollment,
            Hold::BlockingCorPrint,
            Hold::BlockingClearance,
            Hold::BlockingGraduationEligibility,
            Hold::BlockingAdvisoryOnly,
        ]);

        return $this->activeBlockingHolds($studentProfile, $blockingLevels, $enrollment, $term)
            ->sortBy(fn (Hold $hold): int => $priority[$hold->blocking_level] ?? PHP_INT_MAX)
            ->first();
    }

    public function formatBlockingMessage(Hold $hold): string
    {
        $reason = trim((string) $hold->reason);
        $resolution = trim((string) $hold->resolution_requirement);

        $message = $reason !== ''
            ? "Enrollment is blocked by an active hold: {$reason}"
            : 'Enrollment is blocked by an active hold.';

        if ($resolution !== '') {
            $message .= " Resolution required: {$resolution}";
        }

        return $message;
    }

    private function activeAccommodation(StudentProfile $studentProfile, ?int $termId): ?FinancialAccommodation
    {
        return FinancialAccommodation::query()
            ->where('student_profile_id', $studentProfile->id)
            ->whereIn('status', [FinancialAccommodation::StatusActive, 'active'])
            ->where('effective_from', '<=', today())
            ->where(fn (Builder $query) => $query->whereNull('expires_on')->orWhere('expires_on', '>=', today()))
            ->when($termId !== null, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('term_id', $termId)
                ->orWhere('allows_next_term_enrollment', true)))
            ->latest('id')
            ->first();
    }

    private function accommodationAllows(?FinancialAccommodation $accommodation, string $blockingLevel, ?int $termId): bool
    {
        if (! $accommodation instanceof FinancialAccommodation) {
            return false;
        }

        return match ($blockingLevel) {
            Hold::BlockingEnrollment => $termId !== null && (int) $accommodation->term_id === (int) $termId
                ? $accommodation->allows_finance_gate
                : $accommodation->allows_next_term_enrollment,
            Hold::BlockingReactivation => $accommodation->allows_reactivation,
            Hold::BlockingRecordRelease => $accommodation->allows_record_release,
            default => false,
        };
    }
}
