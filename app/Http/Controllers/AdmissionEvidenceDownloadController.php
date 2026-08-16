<?php

namespace App\Http\Controllers;

use App\Actions\Admissions\AdmissionEvidenceService;
use App\Models\DocumentEvidence;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdmissionEvidenceDownloadController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        Request $request,
        DocumentEvidence $evidence,
        AdmissionEvidenceService $evidenceService,
    ): Response {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        abort_unless($evidence->admission_application_id !== null, 404);

        $contents = $evidenceService->contents($evidence, $actor);
        $extension = match ($evidence->mime_type) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'bin',
        };

        return response($contents, 200, [
            'Content-Type' => $evidence->mime_type,
            'Content-Disposition' => sprintf('attachment; filename="evidence-%d.%s"', $evidence->id, $extension),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
