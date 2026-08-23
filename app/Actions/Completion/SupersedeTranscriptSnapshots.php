<?php

namespace App\Actions\Completion;

use App\Models\StudentProfile;
use App\Models\TranscriptIssuanceEvent;
use App\Models\TranscriptSnapshot;
use App\Models\User;

class SupersedeTranscriptSnapshots
{
    public function execute(StudentProfile $student, User $actor, string $authorityReference, string $reason): int
    {
        $count = 0;
        $snapshots = TranscriptSnapshot::query()
            ->whereHas('request', fn ($query) => $query->where('student_profile_id', $student->id))
            ->get();

        foreach ($snapshots as $snapshot) {
            if (TranscriptIssuanceEvent::query()
                ->where('transcript_snapshot_id', $snapshot->id)
                ->where('type', TranscriptIssuanceEvent::TypeSuperseded)
                ->exists()) {
                continue;
            }
            $predecessor = TranscriptIssuanceEvent::query()
                ->where('transcript_snapshot_id', $snapshot->id)->latest('id')->first();
            TranscriptIssuanceEvent::query()->create([
                'transcript_request_id' => $snapshot->transcript_request_id,
                'transcript_snapshot_id' => $snapshot->id,
                'predecessor_event_id' => $predecessor?->id,
                'type' => TranscriptIssuanceEvent::TypeSuperseded,
                'reference' => "{$snapshot->reference}-SUPERSEDED",
                'reason' => trim($reason),
                'authority_reference' => trim($authorityReference),
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);
            $count++;
        }

        return $count;
    }
}
