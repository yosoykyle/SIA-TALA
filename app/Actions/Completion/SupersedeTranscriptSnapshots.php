<?php

namespace App\Actions\Completion;

use App\Models\StudentProfile;
use App\Models\TranscriptIssuanceEvent;
use App\Models\TranscriptRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SupersedeTranscriptSnapshots
{
    public function execute(StudentProfile $student, User $actor, string $authorityReference, string $reason): int
    {
        return DB::transaction(function () use ($student, $actor, $authorityReference, $reason): int {
            $count = 0;
            $requestIds = TranscriptRequest::query()
                ->where('student_profile_id', $student->id)
                ->orderBy('id')
                ->pluck('id');

            foreach ($requestIds as $requestId) {
                $request = TranscriptRequest::query()->lockForUpdate()->findOrFail($requestId);
                $snapshots = $request->snapshots()->lockForUpdate()->get();
                foreach ($snapshots as $snapshot) {
                    $hasSuperseded = TranscriptIssuanceEvent::query()
                        ->where('transcript_snapshot_id', $snapshot->id)
                        ->where('type', TranscriptIssuanceEvent::TypeSuperseded)
                        ->lockForUpdate()
                        ->exists();

                    if ($hasSuperseded) {
                        continue;
                    }

                    $predecessor = TranscriptIssuanceEvent::query()
                        ->where('transcript_snapshot_id', $snapshot->id)
                        ->latest('id')
                        ->lockForUpdate()
                        ->first();

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
            }

            return $count;
        }, attempts: 3);
    }
}
