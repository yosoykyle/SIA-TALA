<?php

namespace App\Http\Controllers;

use App\Actions\Completion\TranscriptPreview;
use App\Models\OutputAccessLog;
use App\Models\TranscriptRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TranscriptPreviewController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __construct(private readonly TranscriptPreview $preview) {}

    public function __invoke(Request $request, TranscriptRequest $transcriptRequest): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);

        $content = $this->preview->forRequest($transcriptRequest);
        $content['document'] = [
            'reference' => 'PREVIEW — '.$transcriptRequest->external_request_reference,
            'template_version' => $transcriptRequest->template_version,
            'generated_at' => now('Asia/Manila')->format('F j, Y g:i A').' Asia/Manila',
            'generation_reference' => 'TALA-GEN-'.strtoupper(substr($content['source_fingerprint'], 0, 16)),
        ];
        $response = response()->view('outputs.tala-standard-tor', $content);
        OutputAccessLog::query()->create([
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
            'request_context' => ['transcript_request_id' => $transcriptRequest->id],
            'status' => 'generated',
            'occurred_at' => now(),
        ]);

        return $response;
    }
}
