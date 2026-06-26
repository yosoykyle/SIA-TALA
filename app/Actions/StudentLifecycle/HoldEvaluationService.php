<?php

namespace App\Actions\StudentLifecycle;

use App\Models\Enrollment;
use App\Models\Hold;
use App\Models\PromissoryNote;
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

        if ($this->hasActivePromissoryNote($studentProfile, $enrollment)) {
            $query->where(function (Builder $query): void {
                $query->where('hold_type', '!=', Hold::TypeFinancial)
                    ->orWhereNotIn('blocking_level', [
                        Hold::BlockingEnrollment,
                        Hold::BlockingRecordRelease,
                    ]);
            });
        }

        return $query
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
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

    private function hasActivePromissoryNote(StudentProfile $studentProfile, ?Enrollment $enrollment): bool
    {
        return PromissoryNote::query()
            ->where('student_profile_id', $studentProfile->id)
            ->when($enrollment instanceof Enrollment, fn (Builder $query) => $query
                ->where(function (Builder $query) use ($enrollment): void {
                    $query->whereNull('term_id')
                        ->orWhere('term_id', $enrollment->term_id);
                })
                ->where(function (Builder $query) use ($enrollment): void {
                    $query->whereNull('enrollment_id')
                        ->orWhere('enrollment_id', $enrollment->id);
                }))
            ->whereIn('status', [PromissoryNote::StatusApproved, 'active'])
            ->whereNull('expired_at')
            ->exists();
    }
}
