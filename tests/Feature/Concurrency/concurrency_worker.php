<?php

declare(strict_types=1);

ini_set('memory_limit', '256M');

use App\Actions\Completion\IssueTranscript;
use App\Actions\Completion\RecordDegreeConferral;
use App\Actions\Completion\ReplaceTranscript;
use App\Actions\Completion\SupersedeTranscriptSnapshots;
use App\Actions\Completion\TranscriptPreview;
use App\Actions\Completion\TranscriptPreviewConfirmation;
use App\Actions\Completion\VoidTranscript;
use App\Actions\Enrollment\PlaceRegistrationProposal;
use App\Actions\Grades\AmendIncDeadline;
use App\Actions\Grades\ReleaseIncCompletion;
use App\Models\GradeOutcomeEvent;
use App\Models\IncCompletionSubmission;
use App\Models\OutputAccessLog;
use App\Models\RegistrationProposalVersion;
use App\Models\StudentProfile;
use App\Models\TranscriptIssuanceEvent;
use App\Models\TranscriptRequest;
use App\Models\TranscriptSnapshot;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Ensure test environment variables are set before Laravel boots
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

putenv('DB_CONNECTION=mysql');
$_ENV['DB_CONNECTION'] = 'mysql';
$_SERVER['DB_CONNECTION'] = 'mysql';

putenv('DB_DATABASE=test_tala_db');
$_ENV['DB_DATABASE'] = 'test_tala_db';
$_SERVER['DB_DATABASE'] = 'test_tala_db';

putenv('CACHE_STORE=array');
$_ENV['CACHE_STORE'] = 'array';
$_SERVER['CACHE_STORE'] = 'array';

putenv('MAIL_MAILER=array');
$_ENV['MAIL_MAILER'] = 'array';
$_SERVER['MAIL_MAILER'] = 'array';

putenv('QUEUE_CONNECTION=sync');
$_ENV['QUEUE_CONNECTION'] = 'sync';
$_SERVER['QUEUE_CONNECTION'] = 'sync';

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// Safety guard: Must strictly operate on test_tala_db
if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'test_tala_db') {
    fwrite(STDERR, "FATAL: Concurrency worker must connect strictly to test_tala_db\n");
    exit(1);
}

config([
    'institution.address' => 'Synthetic Servitech Campus, Philippines',
    'institution.public.support_phone' => '0947 737 9208',
]);

$options = getopt('', ['action:', 'payload:', 'barrier:', 'ready:']);
$action = $options['action'] ?? null;
$rawPayload = $options['payload'] ?? '';
$payload = json_decode(base64_decode($rawPayload), true) ?? [];
$barrierFile = $options['barrier'] ?? null;
$readyFile = $options['ready'] ?? null;

if (! $action) {
    echo json_encode(['success' => false, 'message' => 'No action specified']);
    exit(1);
}

// Signal readiness if ready file provided
if ($readyFile) {
    @file_put_contents($readyFile, (string) getmypid());
}

// Wait on the barrier file for synchronized release
if ($barrierFile) {
    $waited = 0;
    while (! file_exists($barrierFile)) {
        usleep(500); // 0.5 ms
        $waited += 500;
        if ($waited > 20000000) { // 20s timeout
            echo json_encode(['success' => false, 'message' => 'Barrier wait timeout']);
            exit(2);
        }
    }
}

try {
    $result = match ($action) {
        'ping' => [
            'database' => DB::connection()->getDatabaseName(),
            'timestamp' => microtime(true),
        ],
        'placement' => (function () use ($payload) {
            $proposal = RegistrationProposalVersion::findOrFail($payload['proposal_id']);
            $actor = User::findOrFail($payload['registrar_id']);
            $placed = app(PlaceRegistrationProposal::class)->execute($proposal, $actor);

            return [
                'proposal_id' => $placed->id,
                'state' => $placed->state,
                'status' => 'placed',
            ];
        })(),
        'inc_release' => (function () use ($payload) {
            $submission = IncCompletionSubmission::findOrFail($payload['submission_id']);
            $registrar = User::findOrFail($payload['registrar_id']);
            $event = app(ReleaseIncCompletion::class)->execute(
                $submission,
                $registrar,
                $payload['authority_reference'] ?? 'AUTH-CONC-INC'
            );

            return [
                'event_id' => $event->id,
                'result_code' => $event->result_code,
                'status' => 'released',
            ];
        })(),
        'conferral' => (function () use ($payload) {
            $student = StudentProfile::findOrFail($payload['student_id']);
            $actor = User::findOrFail($payload['registrar_id']);
            $conferral = app(RecordDegreeConferral::class)->execute(
                $student,
                $actor,
                $payload['degree_name'] ?? 'Bachelor of Science in Information Technology',
                $payload['conferred_on'] ?? '2026-06-30',
                $payload['authority_reference'] ?? 'AUTH-CONC-CONF',
            );

            return [
                'conferral_id' => $conferral->id,
                'version' => $conferral->version,
                'status' => 'conferred',
            ];
        })(),
        'transcript_void' => (function () use ($payload) {
            $snapshot = TranscriptSnapshot::findOrFail($payload['snapshot_id']);
            $actor = User::findOrFail($payload['registrar_id']);
            $event = app(VoidTranscript::class)->execute(
                $snapshot,
                $actor,
                $payload['authority_reference'] ?? 'AUTH-CONC-VOID',
                $payload['reason'] ?? 'Concurrent void attempt verification',
            );

            return [
                'event_id' => $event->id,
                'reference' => $event->reference,
                'status' => 'voided',
            ];
        })(),
        'amend_inc_deadline' => (function () use ($payload) {
            $incomplete = GradeOutcomeEvent::findOrFail($payload['incomplete_event_id']);
            $registrar = User::findOrFail($payload['registrar_id']);
            $newDeadline = Carbon::parse($payload['new_deadline']);
            $amendment = app(AmendIncDeadline::class)->execute(
                $incomplete,
                $newDeadline,
                $payload['authority_reference'] ?? 'AUTH-AMEND-CONC',
                Carbon::parse($payload['authority_date'] ?? '2026-06-01'),
                $payload['reason'] ?? 'Intervening deadline amendment during concurrency test',
                $registrar,
            );

            return [
                'amendment_id' => $amendment->id,
                'new_deadline' => $amendment->new_deadline->toDateString(),
                'status' => 'amended',
            ];
        })(),
        'transcript_issue' => (function () use ($payload) {
            $request = TranscriptRequest::findOrFail($payload['request_id']);
            $registrar = User::findOrFail($payload['registrar_id']);
            $preview = app(TranscriptPreview::class);
            $content = $preview->forRequest($request, TranscriptSnapshot::StatusIssued);
            $accessLog = OutputAccessLog::query()->create([
                'output_type' => 'TALA_STANDARD_TOR',
                'source_record_type' => TranscriptRequest::class,
                'source_record_id' => $request->id,
                'student_profile_id' => $request->student_profile_id,
                'actor_user_id' => $registrar->id,
                'actor_role' => User::StaffRoleRegistrar,
                'action' => 'preview',
                'copy_context' => 'official-transcript',
                'row_count' => collect($content['academic_years'])->flatten(1)->count(),
                'purpose' => 'Preview TOR before official issuance.',
                'sensitivity' => 'restricted',
                'request_context' => ['request_id' => $request->id],
                'status' => 'completed',
                'occurred_at' => now(),
            ]);
            $confirmation = app(TranscriptPreviewConfirmation::class)->record(
                $request,
                $registrar,
                TranscriptPreviewConfirmation::OperationIssue,
                $content,
                $accessLog,
            );
            $snapshot = app(IssueTranscript::class)->execute(
                $request,
                $registrar,
                $payload['authority_reference'] ?? 'AUTH-CONC-ISSUE',
                $confirmation,
            );

            return [
                'snapshot_id' => $snapshot->id,
                'reference' => $snapshot->reference,
                'version' => $snapshot->version,
                'status' => 'issued',
            ];
        })(),
        'transcript_replace' => (function () use ($payload) {
            $predecessor = TranscriptSnapshot::findOrFail($payload['predecessor_id']);
            $request = TranscriptRequest::findOrFail($predecessor->transcript_request_id);
            $registrar = User::findOrFail($payload['registrar_id']);
            $preview = app(TranscriptPreview::class);
            $content = $preview->forRequest($request, TranscriptIssuanceEvent::TypeReplacement);
            $accessLog = OutputAccessLog::query()->create([
                'output_type' => 'TALA_STANDARD_TOR',
                'source_record_type' => TranscriptRequest::class,
                'source_record_id' => $request->id,
                'student_profile_id' => $request->student_profile_id,
                'actor_user_id' => $registrar->id,
                'actor_role' => User::StaffRoleRegistrar,
                'action' => 'preview',
                'copy_context' => 'official-transcript',
                'row_count' => collect($content['academic_years'])->flatten(1)->count(),
                'purpose' => 'Preview TOR before official replacement.',
                'sensitivity' => 'restricted',
                'request_context' => ['request_id' => $request->id],
                'status' => 'completed',
                'occurred_at' => now(),
            ]);
            $confirmation = app(TranscriptPreviewConfirmation::class)->record(
                $request,
                $registrar,
                TranscriptPreviewConfirmation::OperationReplacement,
                $content,
                $accessLog,
                $predecessor,
            );
            $replacement = app(ReplaceTranscript::class)->execute(
                $predecessor,
                $registrar,
                $payload['authority_reference'] ?? 'AUTH-CONC-REPLACE',
                $payload['reason'] ?? 'Concurrent replacement verification',
                $confirmation,
            );

            return [
                'snapshot_id' => $replacement->id,
                'reference' => $replacement->reference,
                'version' => $replacement->version,
                'supersedes_snapshot_id' => $replacement->supersedes_snapshot_id,
                'status' => 'replacement',
            ];
        })(),
        'transcript_supersede' => (function () use ($payload) {
            $student = StudentProfile::findOrFail($payload['student_id']);
            $actor = User::findOrFail($payload['registrar_id']);
            $count = app(SupersedeTranscriptSnapshots::class)->execute(
                $student,
                $actor,
                $payload['authority_reference'] ?? 'AUTH-CONC-SUPERSEDE',
                $payload['reason'] ?? 'Concurrent supersede verification',
            );

            return [
                'student_id' => $student->id,
                'count' => $count,
                'status' => 'superseded',
            ];
        })(),
        default => throw new InvalidArgumentException("Unknown action: {$action}"),
    };

    echo json_encode([
        'success' => true,
        'pid' => getmypid(),
        'executed_at' => microtime(true),
        'data' => $result,
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'pid' => getmypid(),
        'executed_at' => microtime(true),
        'exception' => get_class($e),
        'message' => $e->getMessage(),
        'errors' => ($e instanceof ValidationException) ? $e->errors() : null,
    ]);
}
