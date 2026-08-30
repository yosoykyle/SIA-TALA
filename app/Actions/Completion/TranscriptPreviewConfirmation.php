<?php

namespace App\Actions\Completion;

use App\Models\OfficialOutputPaymentClearance;
use App\Models\OutputAccessLog;
use App\Models\TranscriptRequest;
use App\Models\TranscriptSnapshot;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TranscriptPreviewConfirmation
{
    public const OperationIssue = 'issue';

    public const OperationReplacement = 'replacement';

    public function __construct(private readonly Session $session) {}

    /** @param array<string, mixed> $content */
    public function record(
        TranscriptRequest $request,
        User $actor,
        string $operation,
        array $content,
        OutputAccessLog $accessLog,
        ?TranscriptSnapshot $predecessor = null,
    ): string {
        $this->assertOperation($operation);
        $reference = $this->nextReference($request);
        $confirmation = (string) Str::uuid();
        $clearance = $request->currentClearance();
        $binding = [
            'reference' => $confirmation,
            'preview_access_log_id' => $accessLog->id,
            'actor_id' => $actor->id,
            'request_id' => $request->id,
            'operation' => $operation,
            'source_fingerprint' => (string) $content['source_fingerprint'],
            'expected_reference' => $reference,
            'template_version' => $request->template_version,
            'clearance_id' => $clearance?->id,
            'clearance_state' => $clearance instanceof OfficialOutputPaymentClearance
                ? $clearance->state
                : OfficialOutputPaymentClearance::StateActionNeeded,
            'signatory_fingerprint' => $this->signatoryFingerprint($request),
            'predecessor_snapshot_id' => $predecessor?->id,
            'predecessor_event_id' => $predecessor?->events()->latest('id')->value('id'),
            'created_at' => now()->toIso8601String(),
            'result_snapshot_id' => null,
        ];

        $this->session->put($this->bindingKey($confirmation), $binding);
        $this->session->put($this->latestKey($actor, $request, $operation), $confirmation);

        return $confirmation;
    }

    public function latestFor(User $actor, TranscriptRequest $request, string $operation): ?string
    {
        $reference = $this->session->get($this->latestKey($actor, $request, $operation));

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    /** @return array<string, mixed> */
    public function validate(
        string $reference,
        TranscriptRequest $request,
        User $actor,
        string $operation,
        string $sourceFingerprint,
        string $expectedReference,
        ?TranscriptSnapshot $predecessor = null,
    ): array {
        $this->assertOperation($operation);
        $binding = $this->session->get($this->bindingKey($reference));
        if (! is_array($binding)) {
            throw ValidationException::withMessages(['preview' => 'Preview the current TOR and review it before confirming this action.']);
        }

        $clearance = $request->currentClearance();
        $actual = [
            'reference' => $reference,
            'actor_id' => $actor->id,
            'request_id' => $request->id,
            'operation' => $operation,
            'source_fingerprint' => $sourceFingerprint,
            'expected_reference' => $expectedReference,
            'template_version' => $request->template_version,
            'clearance_id' => $clearance?->id,
            'clearance_state' => $clearance instanceof OfficialOutputPaymentClearance
                ? $clearance->state
                : OfficialOutputPaymentClearance::StateActionNeeded,
            'signatory_fingerprint' => $this->signatoryFingerprint($request),
            'predecessor_snapshot_id' => $predecessor?->id,
            'predecessor_event_id' => $predecessor?->events()->latest('id')->value('id'),
        ];

        foreach ($actual as $key => $value) {
            if (($binding[$key] ?? null) !== $value) {
                throw ValidationException::withMessages(['preview' => 'The reviewed TOR sources changed. Refresh the preview before confirming this action.']);
            }
        }

        return $binding;
    }

    public function completedSnapshot(
        string $reference,
        User $actor,
        TranscriptRequest $request,
        string $operation,
    ): ?TranscriptSnapshot {
        $binding = $this->session->get($this->bindingKey($reference));
        if (! is_array($binding)
            || ($binding['actor_id'] ?? null) !== $actor->id
            || ($binding['request_id'] ?? null) !== $request->id
            || ($binding['operation'] ?? null) !== $operation) {
            return null;
        }
        $snapshotId = $binding['result_snapshot_id'] ?? null;

        return is_numeric($snapshotId) ? TranscriptSnapshot::query()->find((int) $snapshotId) : null;
    }

    public function complete(string $reference, TranscriptSnapshot $snapshot): void
    {
        $binding = $this->session->get($this->bindingKey($reference));
        if (! is_array($binding)) {
            return;
        }

        $binding['result_snapshot_id'] = $snapshot->id;
        $binding['consumed_at'] = now()->toIso8601String();
        $this->session->put($this->bindingKey($reference), $binding);
    }

    public function nextReference(TranscriptRequest $request): string
    {
        $version = ((int) $request->snapshots()->max('version')) + 1;

        return sprintf('TOR-%s-%06d-V%d', now()->format('Y'), $request->id, $version);
    }

    private function signatoryFingerprint(TranscriptRequest $request): string
    {
        return hash('sha256', json_encode([
            'name' => $request->signatory_name,
            'title' => $request->signatory_title,
            'seal_input_type' => $request->seal_input_type,
            'seal_checksum' => $request->seal_checksum,
            'seal_placement_instruction' => $request->seal_placement_instruction,
        ], JSON_THROW_ON_ERROR));
    }

    private function bindingKey(string $reference): string
    {
        return "transcript_preview_confirmations.{$reference}";
    }

    private function latestKey(User $actor, TranscriptRequest $request, string $operation): string
    {
        return "transcript_preview_latest.{$actor->id}.{$request->id}.{$operation}";
    }

    private function assertOperation(string $operation): void
    {
        if (! in_array($operation, [self::OperationIssue, self::OperationReplacement], true)) {
            throw ValidationException::withMessages(['preview' => 'The requested TOR preview operation is unsupported.']);
        }
    }
}
