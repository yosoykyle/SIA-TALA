<?php

namespace App\Actions\Scheduling;

use App\Models\FacultyAvailabilityDeclaration;
use App\Models\ScheduleGenerationRun;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordFacultyAvailabilityDeclaration
{
    /**
     * @param  list<array{day_of_week: int, starts_at: string, ends_at: string}>  $hardUnavailability
     */
    public function execute(
        Term $term,
        User $faculty,
        User $actor,
        string $declaration,
        array $hardUnavailability,
        ?string $correctionReason = null,
    ): FacultyAvailabilityDeclaration {
        if (! $actor->is($faculty) && ! $actor->hasRole(User::StaffRoleRegistrar)) {
            abort(403);
        }

        return DB::transaction(function () use ($term, $faculty, $declaration, $hardUnavailability, $correctionReason): FacultyAvailabilityDeclaration {
            Term::query()->whereKey($term)->lockForUpdate()->firstOrFail();
            $prior = FacultyAvailabilityDeclaration::query()
                ->where('term_id', $term->id)
                ->where('faculty_user_id', $faculty->id)
                ->latest('version')
                ->lockForUpdate()
                ->first();

            if ($prior instanceof FacultyAvailabilityDeclaration && blank($correctionReason)) {
                throw ValidationException::withMessages(['correction_reason' => 'A reason is required for a later Faculty availability declaration.']);
            }

            foreach ($hardUnavailability as $interval) {
                if ($interval['day_of_week'] < 1 || $interval['day_of_week'] > 7
                    || $interval['starts_at'] >= $interval['ends_at']) {
                    throw ValidationException::withMessages(['hard_unavailability' => 'Every unavailable interval needs a valid day and increasing time bounds.']);
                }
            }

            $nextVersion = $prior instanceof FacultyAvailabilityDeclaration
                ? ((int) $prior->version) + 1
                : 1;

            $record = FacultyAvailabilityDeclaration::query()->create([
                'term_id' => $term->id,
                'faculty_user_id' => $faculty->id,
                'version' => $nextVersion,
                'declaration' => $declaration,
                'hard_unavailability' => $hardUnavailability,
                'correction_reason' => $correctionReason,
                'declared_at' => now(),
            ]);

            if ($prior instanceof FacultyAvailabilityDeclaration) {
                ScheduleGenerationRun::query()
                    ->where('term_id', $term->id)
                    ->whereIn('status', [ScheduleGenerationRun::StatusQueued, ScheduleGenerationRun::StatusUnderReview])
                    ->update(['candidate_state' => 'Stale', 'updated_at' => now()]);
            }

            return $record;
        }, attempts: 5);
    }
}
