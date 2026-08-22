<?php

namespace App\Actions\Finance;

use App\Models\ApprovedCoverage;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReverseApprovedCoverage
{
    public function __construct(private readonly TermAccountProjection $projection) {}

    public function execute(ApprovedCoverage $coverage, User $actor, string $authorityReference, string $safeReason): ApprovedCoverage
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may reverse Approved Coverage.');
        }

        return DB::transaction(function () use ($coverage, $actor, $authorityReference, $safeReason): ApprovedCoverage {
            $locked = ApprovedCoverage::query()->whereKey($coverage->id)->lockForUpdate()->firstOrFail();
            if ($locked->state !== ApprovedCoverage::StateApplied || blank($authorityReference) || blank($safeReason)) {
                throw ValidationException::withMessages(['coverage' => 'Only active coverage may be reversed with attributable authority and a safe reason.']);
            }
            $locked->update([
                'state' => ApprovedCoverage::StateReversed,
                'reversed_by' => $actor->id,
                'reversed_at' => now(),
                'reversal_reason' => trim($safeReason),
                'reversal_authority_reference' => trim($authorityReference),
            ]);
            $position = $this->projection->forAccount($locked->termAccount);
            $locked->termAccount->update(['state' => $position['state'] === 'Cleared' ? 'Cleared' : 'Open']);

            return $locked->refresh();
        }, attempts: 3);
    }
}
