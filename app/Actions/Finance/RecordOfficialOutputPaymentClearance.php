<?php

namespace App\Actions\Finance;

use App\Models\OfficialOutputPaymentClearance;
use App\Models\TermAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordOfficialOutputPaymentClearance
{
    public function execute(TermAccount $account, User $actor, string $requestReference, string $state, string $authorityReference, string $safeReason): OfficialOutputPaymentClearance
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may decide request-specific financial clearance.');
        }
        if (! in_array($state, [OfficialOutputPaymentClearance::StateCleared, OfficialOutputPaymentClearance::StateNotCleared, OfficialOutputPaymentClearance::StateWithdrawn], true)
            || blank($requestReference) || blank($authorityReference) || blank($safeReason)) {
            throw ValidationException::withMessages(['clearance' => 'A named output request, decision, authority, and safe reason are required.']);
        }

        return DB::transaction(function () use ($account, $actor, $requestReference, $state, $authorityReference, $safeReason): OfficialOutputPaymentClearance {
            TermAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $previous = OfficialOutputPaymentClearance::query()
                ->where('output_request_reference', $requestReference)->latest('version')->lockForUpdate()->first();

            return OfficialOutputPaymentClearance::query()->create([
                'term_account_id' => $account->id, 'output_request_reference' => trim($requestReference),
                'version' => ((int) $previous?->version) + 1, 'supersedes_clearance_id' => $previous?->id,
                'state' => $state, 'authority_reference' => trim($authorityReference), 'safe_reason' => trim($safeReason),
                'decided_by' => $actor->id, 'decided_at' => now(),
            ]);
        }, attempts: 3);
    }
}
