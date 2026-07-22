<?php

namespace App\Actions\Applicants;

use App\Models\AdmissionRequirementPolicy;
use App\Models\ApplicantIntake;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AdmissionRequirementResolver
{
    /**
     * @return Collection<int, AdmissionRequirementPolicy>
     */
    public function resolve(ApplicantIntake $intake): Collection
    {
        return $this->resolveFor($intake->admission_category, $intake->credential_basis);
    }

    /**
     * @return Collection<int, AdmissionRequirementPolicy>
     */
    public function resolveFor(string $admissionCategory, string $credentialBasis, bool $failWhenEmpty = true): Collection
    {
        $effectiveOn = CarbonImmutable::now(config('app.timezone'))->toDateString();

        $policies = AdmissionRequirementPolicy::query()
            ->where('admission_category', $admissionCategory)
            ->where('credential_basis', $credentialBasis)
            ->where('state', AdmissionRequirementPolicy::StateActive)
            ->whereDate('effective_from', '<=', $effectiveOn)
            ->where(function (Builder $query) use ($effectiveOn): void {
                $query->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $effectiveOn);
            })
            ->orderBy('requirement_type')
            ->orderBy('id')
            ->get();

        if ($failWhenEmpty && $policies->isEmpty()) {
            throw ValidationException::withMessages([
                'admission_requirement_policy' => 'No effective admission requirement policy matches this intake.',
            ]);
        }

        return $policies;
    }
}
