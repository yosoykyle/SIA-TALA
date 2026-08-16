<?php

namespace App\Actions\Applicants;

use App\Models\AdmissionCycle;
use App\Models\Term;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AdmissionWindowService
{
    public function currentCycle(?CarbonImmutable $at = null): ?AdmissionCycle
    {
        return $this->openWindowQuery($at)
            ->with(['term', 'programs'])
            ->orderBy('closes_at')
            ->first();
    }

    public function nextPublishedCycle(?CarbonImmutable $at = null): ?AdmissionCycle
    {
        $evaluatedAt = $at ?? CarbonImmutable::now();

        return AdmissionCycle::query()
            ->where('state', AdmissionCycle::StatePublished)
            ->where('opens_at', '>', $evaluatedAt)
            ->with(['term', 'programs'])
            ->orderBy('opens_at')
            ->first();
    }

    public function hasOpenAdmissionsWindow(?CarbonImmutable $at = null): bool
    {
        return $this->openWindowQuery($at)->exists();
    }

    public function isAdmissionsWindowOpenForTerm(int $termId, ?CarbonImmutable $at = null): bool
    {
        return $this->openWindowQuery($at)
            ->where('term_id', $termId)
            ->exists();
    }

    public function admissionsCycle(int $termId, ?CarbonImmutable $at = null): AdmissionCycle
    {
        $window = $this->openWindowQuery($at)
            ->where('term_id', $termId)
            ->orderBy('opens_at')
            ->first();

        if ($window instanceof AdmissionCycle) {
            return $window;
        }

        throw ValidationException::withMessages([
            'admission_cycle' => 'Applications are currently closed for the selected admission term.',
        ]);
    }

    public function admissionsWindow(int $termId, ?CarbonImmutable $at = null): AdmissionCycle
    {
        return $this->admissionsCycle($termId, $at);
    }

    /**
     * @return Collection<int, int>
     */
    public function openTermIds(?CarbonImmutable $at = null): Collection
    {
        return $this->openWindowQuery($at)
            ->orderBy('term_id')
            ->pluck('term_id')
            ->map(fn (mixed $termId): int => (int) $termId)
            ->unique()
            ->values();
    }

    /**
     * @return Builder<AdmissionCycle>
     */
    private function openWindowQuery(?CarbonImmutable $at = null): Builder
    {
        $evaluatedAt = $at ?? CarbonImmutable::now();

        return AdmissionCycle::query()
            ->where('state', AdmissionCycle::StatePublished)
            ->where('opens_at', '<=', $evaluatedAt)
            ->where('closes_at', '>', $evaluatedAt)
            ->whereHas('term', fn (Builder $query): Builder => $query
                ->where('state', Term::StateActive));
    }
}
