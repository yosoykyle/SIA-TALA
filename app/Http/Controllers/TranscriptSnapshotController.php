<?php

namespace App\Http\Controllers;

use App\Models\OutputAccessLog;
use App\Models\TranscriptIssuanceEvent;
use App\Models\TranscriptSnapshot;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TranscriptSnapshotController extends Controller
{
    public function __invoke(Request $request, TranscriptSnapshot $snapshot): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
        ]), 403);

        $snapshot->loadMissing('request');
        $latestEvent = TranscriptIssuanceEvent::query()
            ->where('transcript_snapshot_id', $snapshot->id)
            ->latest('id')
            ->first();
        $content = [
            ...$snapshot->content,
            'request' => $snapshot->request,
            'status' => $latestEvent instanceof TranscriptIssuanceEvent ? $latestEvent->type : $snapshot->status,
            'document' => [
                ...data_get($snapshot->content, 'document', []),
                'reference' => $snapshot->reference,
                'template_version' => $snapshot->template_version,
                'generated_at' => data_get(
                    $snapshot->content,
                    'document.generated_at',
                    $snapshot->issued_at->copy()->timezone('Asia/Manila')->format('F j, Y g:i A').' Asia/Manila',
                ),
                'generation_reference' => data_get(
                    $snapshot->content,
                    'document.generation_reference',
                    'TALA-GEN-'.strtoupper(substr($snapshot->source_fingerprint, 0, 16)),
                ),
            ],
        ];
        $response = response()->view('outputs.tala-standard-tor', $content);
        OutputAccessLog::query()->create([
            'output_type' => 'TALA_STANDARD_TOR',
            'source_record_type' => TranscriptSnapshot::class,
            'source_record_id' => $snapshot->id,
            'student_profile_id' => $snapshot->request->student_profile_id,
            'actor_user_id' => $actor->id,
            'actor_role' => $actor->hasRole(User::StaffRoleRegistrar) ? User::StaffRoleRegistrar : User::StaffRoleAcademicHead,
            'action' => 'view',
            'copy_context' => 'official-transcript-history',
            'row_count' => collect($content['academic_years'])->flatten(1)->count(),
            'purpose' => 'View an immutable request-bound TALA Standard TOR snapshot.',
            'sensitivity' => 'restricted',
            'request_context' => ['transcript_snapshot_id' => $snapshot->id, 'reference' => $snapshot->reference],
            'status' => 'generated',
            'occurred_at' => now(),
        ]);

        return $response;
    }
}
