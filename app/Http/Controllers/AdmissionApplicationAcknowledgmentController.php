<?php

namespace App\Http\Controllers;

use App\Models\AdmissionApplication;
use App\Models\ApplicationSubmissionVersion;
use App\Models\OutputAccessLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdmissionApplicationAcknowledgmentController extends Controller
{
    public function __invoke(
        Request $request,
        AdmissionApplication $application,
        ApplicationSubmissionVersion $version,
    ): View {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        abort_unless($actor->can('view', $application), 404);
        abort_unless($version->admission_application_id === $application->id, 404);

        $application->loadMissing(['admissionCycle', 'program', 'term']);
        $version->loadMissing(['requirementSet.requirements']);

        $outputReference = sprintf(
            'APP-ACK-%s-V%d',
            Str::upper((string) $application->application_reference),
            $version->version,
        );

        OutputAccessLog::query()->create([
            'output_type' => 'application.acknowledgment',
            'source_record_type' => AdmissionApplication::class,
            'source_record_id' => $application->id,
            'actor_user_id' => $actor->id,
            'actor_role' => $actor->getRoleNames()->first() ?? 'authenticated',
            'action' => $request->boolean('print') ? 'print' : 'view',
            'copy_context' => $application->user_id === $actor->id ? 'applicant' : 'registrar',
            'schedule_version' => $version->version,
            'row_count' => $version->requirementSet->requirements->count(),
            'purpose' => 'Application submission acknowledgment',
            'sensitivity' => 'restricted',
            'request_context' => [
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 255),
            ],
            'status' => 'generated',
            'occurred_at' => now(),
        ]);

        return view('admissions.application-acknowledgment', [
            'application' => $application,
            'version' => $version,
            'outputReference' => $outputReference,
            'generatedAt' => now(),
            'actor' => $actor,
        ]);
    }
}
