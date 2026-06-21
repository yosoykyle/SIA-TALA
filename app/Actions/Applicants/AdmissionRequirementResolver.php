<?php

namespace App\Actions\Applicants;

use App\Models\AdmissionOffering;
use App\Models\AdmissionRequirementPolicy;
use App\Models\ApplicantIntake;
use App\Models\DocumentRequirementItem;
use App\Models\Term;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AdmissionRequirementResolver
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function resolve(array $data, Term $term): AdmissionRequirementResolution
    {
        $offering = $this->resolveOffering($data, $term);
        $policy = $this->resolvePolicy($offering);
        $items = $this->resolveItems($policy);

        return new AdmissionRequirementResolution($offering, $policy, $items);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function resolveOffering(array $data, Term $term): AdmissionOffering
    {
        $entryRoute = $this->entryRouteFor((string) $data['applicant_type']);
        $priorCredential = (string) ($data['prior_credential_pathway'] ?? AdmissionOffering::PriorCredentialRegular);
        $yearLevel = (string) $data['year_level'];
        $programId = (int) $data['program_id'];

        $offerings = AdmissionOffering::query()
            ->where('term_id', $term->id)
            ->where('entry_route', $entryRoute)
            ->where('status', AdmissionOffering::StatusPublished)
            ->where(function (Builder $query) use ($priorCredential): void {
                $query->where('prior_credential_pathway', $priorCredential)
                    ->orWhereNull('prior_credential_pathway');
            })
            ->whereNull('citizenship_compliance_profile')
            ->where(function (Builder $query) use ($programId): void {
                $query->where('program_id', $programId)
                    ->orWhereNull('program_id');
            })
            ->where(function (Builder $query) use ($yearLevel): void {
                $query->where('year_level', $yearLevel)
                    ->orWhereNull('year_level');
            })
            ->get();

        $ranked = $offerings
            ->map(fn (AdmissionOffering $offering): array => [
                'offering' => $offering,
                'score' => $this->specificityScore($offering, $programId, $yearLevel, $priorCredential),
            ])
            ->sortByDesc('score')
            ->values();

        if ($ranked->isEmpty()) {
            throw ValidationException::withMessages([
                'admission_offering' => 'No published admission offering matches this applicant scope.',
            ]);
        }

        $bestScore = $ranked->first()['score'];
        $matches = $ranked->filter(fn (array $candidate): bool => $candidate['score'] === $bestScore);

        if ($matches->count() > 1) {
            throw ValidationException::withMessages([
                'admission_offering' => 'Multiple published admission offerings match this applicant scope.',
            ]);
        }

        return $ranked->first()['offering'];
    }

    /**
     * @throws ValidationException
     */
    private function resolvePolicy(AdmissionOffering $offering): AdmissionRequirementPolicy
    {
        $timestamp = CarbonImmutable::now(config('app.timezone'));

        $policy = $offering->requirementPolicies()
            ->where('status', AdmissionRequirementPolicy::StatusActive)
            ->where(function (Builder $query) use ($timestamp): void {
                $query->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', $timestamp);
            })
            ->where(function (Builder $query) use ($timestamp): void {
                $query->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', $timestamp);
            })
            ->orderByDesc('version')
            ->first();

        if (! $policy instanceof AdmissionRequirementPolicy) {
            throw ValidationException::withMessages([
                'admission_requirement_policy' => 'The matching admission offering has no active requirement policy.',
            ]);
        }

        return $policy;
    }

    /**
     * @return Collection<int, DocumentRequirementItem>
     *
     * @throws ValidationException
     */
    private function resolveItems(AdmissionRequirementPolicy $policy): Collection
    {
        $items = $policy->documentRequirementItems()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'document_requirement_items' => 'The active admission requirement policy has no document items.',
            ]);
        }

        if (! $items->contains(fn (DocumentRequirementItem $item): bool => $item->gate_type === DocumentRequirementItem::GateTypeAdmission)) {
            throw ValidationException::withMessages([
                'document_requirement_items' => 'The active admission requirement policy must include at least one admission-gate item.',
            ]);
        }

        return $items;
    }

    private function entryRouteFor(string $applicantType): string
    {
        return match ($applicantType) {
            ApplicantIntake::ApplicantTypeTransferee => AdmissionOffering::EntryRouteTransfer,
            ApplicantIntake::ApplicantTypeReturnee => AdmissionOffering::EntryRouteReturning,
            default => AdmissionOffering::EntryRouteRegular,
        };
    }

    private function specificityScore(
        AdmissionOffering $offering,
        int $programId,
        string $yearLevel,
        string $priorCredential,
    ): int {
        $score = 0;

        if ((int) $offering->program_id === $programId) {
            $score += 4;
        }

        if ($offering->year_level === $yearLevel) {
            $score += 2;
        }

        if ($offering->prior_credential_pathway === $priorCredential) {
            $score += 1;
        }

        return $score;
    }
}
