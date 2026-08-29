<?php

namespace App\Http\Controllers;

use App\Models\AdmissionApplication;
use App\Models\ApplicationSubmissionVersion;
use App\Models\OutputAccessLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class AdmissionApplicationAcknowledgmentController extends Controller
{
    public function __invoke(
        Request $request,
        AdmissionApplication $application,
        ApplicationSubmissionVersion $version,
    ): Response {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        abort_unless($actor->can('view', $application), 404);
        abort_unless($version->admission_application_id === $application->id, 404);

        $version->loadMissing(['requirementSet.requirements']);
        $generatedAt = CarbonImmutable::now(config('app.timezone'));
        $applicationReference = (string) data_get(
            $version->snapshot,
            'application_reference',
            $application->application_reference,
        );

        $outputReference = sprintf(
            'APP-ACK-%s-V%d',
            Str::upper($applicationReference),
            $version->version,
        );
        $source = [
            'application_reference' => $applicationReference,
            'admission_cycle' => data_get(
                $version->snapshot,
                'admission_cycle.label',
                'Admission Cycle record #'.data_get($version->snapshot, 'admission_cycle_id').' (legacy snapshot)',
            ),
            'admission_cycle_code' => data_get($version->snapshot, 'admission_cycle.code'),
            'term' => data_get(
                $version->snapshot,
                'term.label',
                'Term record #'.data_get($version->snapshot, 'term_id').' (legacy snapshot)',
            ),
            'program' => data_get(
                $version->snapshot,
                'program.name',
                'Program record #'.data_get($version->snapshot, 'program_id').' (legacy snapshot)',
            ),
            'application_path' => (string) data_get($version->snapshot, 'application_path'),
            'is_current' => $application->current_submission_version_id === $version->id,
        ];
        $response = response()->view('admissions.application-acknowledgment', [
            'application' => $application,
            'version' => $version,
            'outputReference' => $outputReference,
            'generatedAt' => $generatedAt,
            'actor' => $actor,
            'source' => $source,
        ]);

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
            'occurred_at' => $generatedAt,
        ]);

        return $response;
    }
}
