<?php

namespace App\Actions\Completion;

use App\Models\TranscriptIssuanceEvent;
use App\Models\TranscriptSnapshot;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoidTranscript
{
    public function execute(TranscriptSnapshot $snapshot, User $actor, string $authorityReference, string $reason): TranscriptIssuanceEvent
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff may void an issued TOR.');
        }
        if (blank(trim($authorityReference)) || blank(trim($reason))) {
            throw ValidationException::withMessages(['void' => 'Void authority and reason are required.']);
        }

        return DB::transaction(function () use ($snapshot, $actor, $authorityReference, $reason): TranscriptIssuanceEvent {
            $locked = TranscriptSnapshot::query()->lockForUpdate()->findOrFail($snapshot->id);
            $existing = TranscriptIssuanceEvent::query()
                ->where('transcript_snapshot_id', $locked->id)
                ->where('type', TranscriptIssuanceEvent::TypeVoided)
                ->first();
            if ($existing instanceof TranscriptIssuanceEvent) {
                return $existing;
            }
            $predecessor = TranscriptIssuanceEvent::query()
                ->where('transcript_snapshot_id', $locked->id)->latest('id')->first();

            return TranscriptIssuanceEvent::query()->create([
                'transcript_request_id' => $locked->transcript_request_id,
                'transcript_snapshot_id' => $locked->id,
                'predecessor_event_id' => $predecessor?->id,
                'type' => TranscriptIssuanceEvent::TypeVoided,
                'reference' => "{$locked->reference}-VOID",
                'reason' => trim($reason),
                'authority_reference' => trim($authorityReference),
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);
        }, attempts: 3);
    }
}
