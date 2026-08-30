<?php

namespace App\Actions\Completion;

use App\Models\TranscriptIssuanceEvent;
use App\Models\TranscriptRequest;
use App\Models\TranscriptSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class TranscriptLifecycleProjection
{
    public function statusForRequest(TranscriptRequest $request): string
    {
        $current = $this->currentSnapshot($request);

        if ($current instanceof TranscriptSnapshot) {
            return $this->statusForSnapshot($current);
        }

        $latest = $this->eventsForRequest($request)
            ->sortByDesc(fn (TranscriptIssuanceEvent $event): int => $event->id)
            ->first();

        return $latest instanceof TranscriptIssuanceEvent
            ? $latest->type
            : TranscriptRequest::StateOpen;
    }

    public function statusForSnapshot(TranscriptSnapshot $snapshot): string
    {
        $latest = $this->eventsForSnapshot($snapshot)
            ->sortByDesc(fn (TranscriptIssuanceEvent $event): int => $event->id)
            ->first();

        return $latest instanceof TranscriptIssuanceEvent ? $latest->type : $snapshot->status;
    }

    public function currentSnapshot(TranscriptRequest $request): ?TranscriptSnapshot
    {
        $snapshots = $this->snapshotsForRequest($request)
            ->filter(fn (TranscriptSnapshot $snapshot): bool => in_array(
                $this->statusForSnapshot($snapshot),
                [TranscriptIssuanceEvent::TypeIssued, TranscriptIssuanceEvent::TypeReplacement],
                true,
            ))
            ->values();

        if ($snapshots->count() > 1) {
            throw ValidationException::withMessages([
                'transcript' => 'This TOR request has competing current snapshots. Stop and reconcile its immutable event lineage before continuing.',
            ]);
        }

        $current = $snapshots->first();

        return $current instanceof TranscriptSnapshot ? $current : null;
    }

    /** @return Collection<int, TranscriptSnapshot> */
    private function snapshotsForRequest(TranscriptRequest $request): Collection
    {
        if ($request->relationLoaded('snapshots')) {
            return $request->snapshots;
        }

        return $request->snapshots()->with('events')->get();
    }

    /** @return Collection<int, TranscriptIssuanceEvent> */
    private function eventsForRequest(TranscriptRequest $request): Collection
    {
        if ($request->relationLoaded('events')) {
            return $request->events;
        }

        return $request->events()->get();
    }

    /** @return Collection<int, TranscriptIssuanceEvent> */
    private function eventsForSnapshot(TranscriptSnapshot $snapshot): Collection
    {
        if ($snapshot->relationLoaded('events')) {
            return $snapshot->events;
        }

        return $snapshot->events()->get();
    }
}
