<?php

namespace App\Actions\Completion;

use App\Models\OutputAccessLog;
use App\Models\TranscriptIssuanceEvent;
use App\Models\TranscriptSnapshot;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReplaceTranscript
{
    public function __construct(private readonly TranscriptPreview $preview) {}

    public function execute(TranscriptSnapshot $predecessor, User $actor, string $authorityReference, string $reason): TranscriptSnapshot
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff may replace an issued TOR.');
        }
        if (blank(trim($authorityReference)) || blank(trim($reason))) {
            throw ValidationException::withMessages(['replacement' => 'Replacement authority and reason are required.']);
        }

        return DB::transaction(function () use ($predecessor, $actor, $authorityReference, $reason): TranscriptSnapshot {
            $locked = TranscriptSnapshot::query()->with('request')->lockForUpdate()->findOrFail($predecessor->id);
            $voidEvent = TranscriptIssuanceEvent::query()
                ->where('transcript_snapshot_id', $locked->id)
                ->where('type', TranscriptIssuanceEvent::TypeVoided)
                ->latest('id')
                ->first();
            if (! $voidEvent instanceof TranscriptIssuanceEvent) {
                throw ValidationException::withMessages(['replacement' => 'Void the predecessor TOR before recording its replacement.']);
            }
            $existingEvent = TranscriptIssuanceEvent::query()
                ->where('predecessor_event_id', $voidEvent->id)
                ->where('type', TranscriptIssuanceEvent::TypeReplacement)->first();
            if ($existingEvent?->transcript_snapshot_id) {
                return TranscriptSnapshot::query()->findOrFail($existingEvent->transcript_snapshot_id);
            }
            $content = $this->preview->forRequest($locked->request, TranscriptIssuanceEvent::TypeReplacement);
            $version = ((int) TranscriptSnapshot::query()->where('transcript_request_id', $locked->transcript_request_id)->max('version')) + 1;
            $reference = sprintf('TOR-%s-%06d-V%d', now()->format('Y'), $locked->transcript_request_id, $version);
            $issuedAt = now();
            $content['document'] = [
                'reference' => $reference,
                'template_version' => $locked->template_version,
                'generated_at' => $issuedAt->copy()->timezone('Asia/Manila')->format('F j, Y g:i A').' Asia/Manila',
                'generation_reference' => 'TALA-GEN-'.strtoupper(substr($content['source_fingerprint'], 0, 16)),
            ];
            view('outputs.tala-standard-tor', $content)->render();
            $replacement = TranscriptSnapshot::query()->create([
                'transcript_request_id' => $locked->transcript_request_id,
                'degree_conferral_id' => $locked->degree_conferral_id,
                'version' => $version,
                'supersedes_snapshot_id' => $locked->id,
                'reference' => $reference,
                'template_version' => $locked->template_version,
                'source_fingerprint' => $content['source_fingerprint'],
                'content' => collect($content)->except(['request'])->all(),
                'status' => TranscriptIssuanceEvent::TypeReplacement,
                'issued_by' => $actor->id,
                'issued_at' => $issuedAt,
            ]);
            TranscriptIssuanceEvent::query()->create([
                'transcript_request_id' => $locked->transcript_request_id,
                'transcript_snapshot_id' => $replacement->id,
                'predecessor_event_id' => $voidEvent->id,
                'type' => TranscriptIssuanceEvent::TypeReplacement,
                'reference' => "{$reference}-REPLACEMENT",
                'reason' => trim($reason),
                'authority_reference' => trim($authorityReference),
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);
            OutputAccessLog::query()->create([
                'output_type' => 'TALA_STANDARD_TOR', 'source_record_type' => TranscriptSnapshot::class,
                'source_record_id' => $replacement->id, 'student_profile_id' => $locked->request->student_profile_id,
                'actor_user_id' => $actor->id, 'actor_role' => User::StaffRoleRegistrar,
                'action' => 'replacement', 'copy_context' => 'official-transcript',
                'row_count' => collect($content['academic_years'])->flatten(1)->count(),
                'purpose' => 'Replace a voided or erroneous TOR without overwriting history.',
                'sensitivity' => 'restricted', 'request_context' => ['predecessor_snapshot_id' => $locked->id],
                'status' => 'completed', 'occurred_at' => now(),
            ]);

            return $replacement;
        }, attempts: 3);
    }
}
