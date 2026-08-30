<?php

namespace App\Actions\Completion;

use App\Models\OutputAccessLog;
use App\Models\TranscriptIssuanceEvent;
use App\Models\TranscriptRequest;
use App\Models\TranscriptSnapshot;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IssueTranscript
{
    public function __construct(
        private readonly TranscriptPreview $preview,
        private readonly TranscriptPreviewConfirmation $confirmations,
        private readonly TranscriptLifecycleProjection $lifecycle,
    ) {}

    public function execute(
        TranscriptRequest $request,
        User $actor,
        string $authorityReference,
        ?string $previewConfirmation = null,
    ): TranscriptSnapshot {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff may issue the official TOR.');
        }
        if (blank(trim($authorityReference))) {
            throw ValidationException::withMessages(['issuance' => 'Issuance authority is required.']);
        }

        return DB::transaction(function () use ($request, $actor, $authorityReference, $previewConfirmation): TranscriptSnapshot {
            $locked = TranscriptRequest::query()
                ->with(['snapshots.events', 'events', 'clearances'])
                ->lockForUpdate()
                ->findOrFail($request->id);
            $confirmation = $previewConfirmation
                ?? $this->confirmations->latestFor($actor, $locked, TranscriptPreviewConfirmation::OperationIssue);
            if (! is_string($confirmation)) {
                throw ValidationException::withMessages(['preview' => 'Preview the current TOR and review it before confirming issuance.']);
            }
            $completed = $this->confirmations->completedSnapshot(
                $confirmation,
                $actor,
                $locked,
                TranscriptPreviewConfirmation::OperationIssue,
            );
            if ($completed instanceof TranscriptSnapshot) {
                $issuedEvent = $completed->events()->where('type', TranscriptIssuanceEvent::TypeIssued)->sole();
                if ($issuedEvent->authority_reference === trim($authorityReference)) {
                    return $completed;
                }

                throw ValidationException::withMessages(['issuance' => 'This completed preview confirmation belongs to a different issuance authority.']);
            }
            if ($this->lifecycle->currentSnapshot($locked) instanceof TranscriptSnapshot) {
                throw ValidationException::withMessages(['issuance' => 'This request already has a current TOR. Use the authorized void and replacement lifecycle.']);
            }
            $content = $this->preview->forRequest($locked, TranscriptSnapshot::StatusIssued);
            $version = ((int) TranscriptSnapshot::query()->where('transcript_request_id', $locked->id)->max('version')) + 1;
            $reference = sprintf('TOR-%s-%06d-V%d', now()->format('Y'), $locked->id, $version);
            $this->confirmations->validate(
                $confirmation,
                $locked,
                $actor,
                TranscriptPreviewConfirmation::OperationIssue,
                $content['source_fingerprint'],
                $reference,
            );
            $issuedAt = now();
            $content['document'] = [
                'reference' => $reference,
                'template_version' => $locked->template_version,
                'generated_at' => $issuedAt->copy()->timezone('Asia/Manila')->format('F j, Y g:i A').' Asia/Manila',
                'generation_reference' => 'TALA-GEN-'.strtoupper(substr($content['source_fingerprint'], 0, 16)),
            ];
            view('outputs.tala-standard-tor', $content)->render();
            $snapshot = TranscriptSnapshot::query()->create([
                'transcript_request_id' => $locked->id,
                'degree_conferral_id' => $locked->degree_conferral_id,
                'version' => $version,
                'reference' => $reference,
                'template_version' => $locked->template_version,
                'source_fingerprint' => $content['source_fingerprint'],
                'content' => collect($content)->except(['request'])->all(),
                'status' => TranscriptSnapshot::StatusIssued,
                'issued_by' => $actor->id,
                'issued_at' => $issuedAt,
            ]);
            TranscriptIssuanceEvent::query()->create([
                'transcript_request_id' => $locked->id,
                'transcript_snapshot_id' => $snapshot->id,
                'type' => TranscriptIssuanceEvent::TypeIssued,
                'reference' => "{$reference}-ISSUED",
                'authority_reference' => trim($authorityReference),
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);
            OutputAccessLog::query()->create([
                'output_type' => 'TALA_STANDARD_TOR',
                'source_record_type' => TranscriptSnapshot::class,
                'source_record_id' => $snapshot->id,
                'student_profile_id' => $locked->student_profile_id,
                'actor_user_id' => $actor->id,
                'actor_role' => User::StaffRoleRegistrar,
                'action' => 'issued',
                'copy_context' => 'official-transcript',
                'row_count' => collect($content['academic_years'])->flatten(1)->count(),
                'purpose' => 'Issue the exact request-bound TALA Standard TOR.',
                'sensitivity' => 'restricted',
                'request_context' => ['transcript_request_id' => $locked->id, 'reference' => $reference],
                'status' => 'completed',
                'occurred_at' => now(),
            ]);
            $locked->update(['state' => TranscriptRequest::StateIssued]);
            $this->confirmations->complete($confirmation, $snapshot);

            return $snapshot;
        }, attempts: 3);
    }
}
