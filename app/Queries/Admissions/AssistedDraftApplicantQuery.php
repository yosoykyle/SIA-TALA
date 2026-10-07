<?php

namespace App\Queries\Admissions;

use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AssistedDraftApplicantQuery
{
    /** @return Builder<User> */
    public function eligible(?int $sourceApplicationId = null, ?int $cycleId = null, bool $includeClosedDrafts = false): Builder
    {
        $users = (new User)->getTable();
        $applications = (new AdmissionApplication)->getTable();
        $cycles = (new AdmissionCycle)->getTable();
        $latestEditable = AdmissionApplication::query()->canonical()
            ->select('id')->whereColumn('user_id', "{$users}.id")
            ->whereIn('application_state', [AdmissionApplication::StateDraft, AdmissionApplication::StateActionNeeded])
            ->latest('id')->limit(1);
        $unusedOpenCycle = AdmissionCycle::query()
            ->select("{$cycles}.id")
            ->where('state', AdmissionCycle::StatePublished)
            ->where('opens_at', '<=', now())->where('closes_at', '>', now())
            ->when($cycleId !== null, fn (Builder $query): Builder => $query->whereKey($cycleId))
            ->whereNotExists(AdmissionApplication::query()->canonical()
                ->select('id')->whereColumn('user_id', "{$users}.id")
                ->whereColumn('admission_cycle_id', "{$cycles}.id"));

        return User::query()
            ->where('status', User::StatusActive)->whereNotNull('email_verified_at')
            ->whereHas('roles', fn (Builder $query): Builder => $query->where('name', 'applicant'))
            ->where(function (Builder $query) use ($sourceApplicationId, $cycleId, $includeClosedDrafts, $latestEditable, $unusedOpenCycle, $applications): void {
                $query->whereHas('admissionApplications', fn (Builder $application): Builder => $application
                    ->canonical()->where('application_state', AdmissionApplication::StateDraft)
                    ->whereNull('current_submission_version_id')
                    ->when(! $includeClosedDrafts, fn (Builder $query): Builder => $query
                        ->whereHas('admissionCycle', fn (Builder $cycle): Builder => $cycle
                            ->where('state', AdmissionCycle::StatePublished)
                            ->where('opens_at', '<=', now())->where('closes_at', '>', now())))
                    ->when($cycleId !== null, fn (Builder $query): Builder => $query->where('admission_cycle_id', $cycleId))
                    ->when($sourceApplicationId !== null,
                        fn (Builder $query): Builder => $query->whereKey($sourceApplicationId),
                        fn (Builder $query): Builder => $query->where("{$applications}.id", '=', $latestEditable)));

                if ($sourceApplicationId === null) {
                    $query->orWhere(fn (Builder $query): Builder => $query
                        ->whereDoesntHave('admissionApplications', fn (Builder $application): Builder => $application
                            ->canonical()->whereIn('application_state', [AdmissionApplication::StateDraft, AdmissionApplication::StateActionNeeded]))
                        ->whereExists($unusedOpenCycle));
                }
            });
    }
}
