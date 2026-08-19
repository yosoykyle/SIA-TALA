<?php

namespace App\Actions\Calendar;

use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ActivateTermCalendarPackage
{
    public function __construct(private readonly TermCalendarPackageReadinessService $readiness) {}

    public function execute(TermCalendarPackage $package, User $actor): TermCalendarPackage
    {
        Gate::forUser($actor)->authorize('update', $package->term);

        return DB::transaction(function () use ($package, $actor): TermCalendarPackage {
            Term::query()->whereKey($package->term_id)->lockForUpdate()->firstOrFail();
            $packages = TermCalendarPackage::query()
                ->where('term_id', $package->term_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $locked = $packages->firstWhere('id', $package->id);

            if (! $locked instanceof TermCalendarPackage) {
                abort(404);
            }

            Gate::forUser($actor)->authorize('update', $locked->term);

            if ($locked->state !== TermCalendarPackage::StateDraft) {
                throw ValidationException::withMessages(['state' => 'Only a Draft Term Calendar Package can be activated.']);
            }

            $readiness = $this->readiness->for($locked);

            if (! $readiness['ready']) {
                throw ValidationException::withMessages([
                    'readiness' => collect($readiness['blockers'])->pluck('reason')->all(),
                ]);
            }

            foreach ($packages->where('state', TermCalendarPackage::StateActive) as $active) {
                $active->forceFill(['state' => TermCalendarPackage::StateClosed, 'closed_at' => now()])->save();
            }

            $locked->forceFill(['state' => TermCalendarPackage::StateActive, 'recorded_by' => $actor->id, 'activated_at' => now()])->save();
            $locked->term()->update(['state' => Term::StateActive]);

            return $locked->fresh(['windows', 'teachingGridRows', 'datedExceptions']);
        }, attempts: 5);
    }
}
