<?php

namespace App\Http\Controllers;

use App\Actions\Completion\TranscriptPreview;
use App\Actions\Completion\TranscriptPreviewConfirmation;
use App\Models\OutputAccessLog;
use App\Models\TranscriptRequest;
use App\Models\TranscriptSnapshot;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class TranscriptPreviewController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __construct(
        private readonly TranscriptPreview $preview,
        private readonly TranscriptPreviewConfirmation $confirmations,
    ) {}

    public function __invoke(Request $request, TranscriptRequest $transcriptRequest): Response
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole(User::StaffRoleRegistrar)) {
            $this->recordOutcome($transcriptRequest, $actor, 'denied', 'denied');
            abort(403);
        }

        try {
            return $this->renderPreview($request, $transcriptRequest, $actor);
        } catch (Throwable $exception) {
            $this->recordOutcome($transcriptRequest, $actor, 'preview_failed', 'failed');
            throw $exception;
        }
    }

    private function renderPreview(Request $request, TranscriptRequest $transcriptRequest, User $actor): Response
    {

        $operation = $request->string('operation')->value() ?: TranscriptPreviewConfirmation::OperationIssue;
        $predecessor = null;
        if ($operation === TranscriptPreviewConfirmation::OperationReplacement) {
            $predecessorId = $request->integer('predecessor');
            $predecessor = TranscriptSnapshot::query()
                ->where('transcript_request_id', $transcriptRequest->id)
                ->findOrFail($predecessorId);
        }

        $content = $this->preview->forRequest($transcriptRequest);
        $content['document'] = [
            'reference' => 'PREVIEW — '.$transcriptRequest->external_request_reference,
            'template_version' => $transcriptRequest->template_version,
            'generated_at' => now('Asia/Manila')->format('F j, Y g:i A').' Asia/Manila',
            'generation_reference' => 'TALA-GEN-'.strtoupper(substr($content['source_fingerprint'], 0, 16)),
        ];
        $html = view('outputs.tala-standard-tor', $content)->render();
        $accessLog = OutputAccessLog::query()->create([
            'output_type' => 'TALA_STANDARD_TOR',
            'source_record_type' => TranscriptRequest::class,
            'source_record_id' => $transcriptRequest->id,
            'student_profile_id' => $transcriptRequest->student_profile_id,
            'actor_user_id' => $actor->id,
            'actor_role' => User::StaffRoleRegistrar,
            'action' => 'preview',
            'copy_context' => 'non-issued-preview',
            'row_count' => collect($content['academic_years'])->flatten(1)->count(),
            'purpose' => 'Review the exact request-bound TOR before issuance.',
            'sensitivity' => 'restricted',
            'request_context' => [
                'transcript_request_id' => $transcriptRequest->id,
                'operation' => $operation,
                'predecessor_snapshot_id' => $predecessor?->id,
                'source_fingerprint' => $content['source_fingerprint'],
            ],
            'status' => 'generated',
            'occurred_at' => now(),
        ]);

        $confirmation = $this->confirmations->record(
            $transcriptRequest,
            $actor,
            $operation,
            $content,
            $accessLog,
            $predecessor,
        );

        return response($html)->header('X-TALA-Preview-Confirmation', $confirmation);
    }

    private function recordOutcome(
        TranscriptRequest $transcriptRequest,
        ?User $actor,
        string $action,
        string $status,
    ): void {
        OutputAccessLog::query()->create([
            'output_type' => 'TALA_STANDARD_TOR',
            'source_record_type' => TranscriptRequest::class,
            'source_record_id' => $transcriptRequest->id,
            'student_profile_id' => $transcriptRequest->student_profile_id,
            'actor_user_id' => $actor?->id,
            'actor_role' => $actor?->roles()->value('name') ?? 'unauthenticated',
            'action' => $action,
            'copy_context' => 'non-issued-preview',
            'row_count' => 0,
            'purpose' => 'Record a denied or failed TALA Standard TOR preview outcome.',
            'sensitivity' => 'restricted',
            'request_context' => ['transcript_request_id' => $transcriptRequest->id],
            'status' => $status,
            'occurred_at' => now(),
        ]);
    }
}
