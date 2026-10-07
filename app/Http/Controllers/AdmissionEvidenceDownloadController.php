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
        $inline = $request->routeIs('admissions.evidence.view');
        $mime = $inline ? (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) : $evidence->mime_type;
        abort_if($inline && ! in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true), 415, 'This file can be downloaded for review.');

        $extension = match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'bin',
        };

        return response($contents, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => sprintf('%s; filename="evidence-%d.%s"', $inline ? 'inline' : 'attachment', $evidence->id, $extension),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
