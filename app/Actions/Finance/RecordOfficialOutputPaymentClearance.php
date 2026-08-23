<?php

namespace App\Actions\Finance;

use App\Models\OfficialOutputPaymentClearance;
use App\Models\TranscriptRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordOfficialOutputPaymentClearance
{
    public function execute(TranscriptRequest $request, User $actor, string $state, string $authorityReference, string $safeReason, ?string $requiredAmount = null): OfficialOutputPaymentClearance
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may decide request-specific financial clearance.');
        }
        if (! in_array($state, [OfficialOutputPaymentClearance::StateCleared, OfficialOutputPaymentClearance::StateNotRequired], true)
            || blank($authorityReference) || blank($safeReason)) {
            throw ValidationException::withMessages(['clearance' => 'A Cleared or authority-backed NotRequired decision, authority, and safe reason are required.']);
        }
        if ($requiredAmount !== null && (! is_numeric($requiredAmount) || (float) $requiredAmount < 0)) {
            throw ValidationException::withMessages(['required_amount' => 'The request-specific required amount must be zero or greater.']);
        }

        return DB::transaction(function () use ($request, $actor, $state, $authorityReference, $safeReason, $requiredAmount): OfficialOutputPaymentClearance {
            $lockedRequest = TranscriptRequest::query()->lockForUpdate()->findOrFail($request->id);
            $previous = OfficialOutputPaymentClearance::query()
                ->where('transcript_request_id', $lockedRequest->id)->latest('version')->lockForUpdate()->first();

            return OfficialOutputPaymentClearance::query()->create([
                'term_account_id' => null,
                'transcript_request_id' => $lockedRequest->id,
                'output_request_reference' => $lockedRequest->external_request_reference,
                'version' => ((int) $previous?->version) + 1, 'supersedes_clearance_id' => $previous?->id,
                'state' => $state,
                'required_amount' => $requiredAmount,
                'authority_reference' => trim($authorityReference), 'safe_reason' => trim($safeReason),
                'decided_by' => $actor->id, 'decided_at' => now(),
            ]);
        }, attempts: 3);
    }
}
