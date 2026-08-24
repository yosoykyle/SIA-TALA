<?php

namespace App\Actions\SystemAdministration;

use App\Models\OperationalEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

class OperationalEvidenceRecorder
{
    public const TypeBackup = 'backup';

    public const TypeRestore = 'restore';

    public const MaxInputBytes = 65_536;

    /** @var list<string> */
    private const CommonFields = [
        'schema_version',
        'external_reference',
        'outcome',
        'started_at',
        'completed_at',
        'application_revision',
        'migration_result',
        'integrity_result',
        'operator_reference',
        'supersedes_external_reference',
    ];

    /** @var list<string> */
    private const BackupFields = [
        'generation_reference',
        'database_export_result',
        'private_files_result',
        'manifest_result',
        'off_host_result',
    ];

    /** @var list<string> */
    private const RestoreFields = [
        'generation_reference',
        'measured_duration_minutes',
        'observed_data_loss_minutes',
        'manifest_result',
        'database_restore_result',
        'private_files_restore_result',
        'authentication_result',
        'critical_journeys_result',
        'session_cache_result',
        'queue_integration_result',
        'lawful_disposition_result',
    ];

    /**
     * @return array{event: OperationalEvent, created: bool}
     */
    public function record(string $type, string $json): array
    {
        $payload = $this->decode($json);
        $this->validate($type, $payload);

        return DB::transaction(function () use ($type, $payload): array {
            $externalId = $this->externalId($type, (string) $payload['external_reference']);
            $fingerprint = hash('sha256', $this->canonicalJson($payload));
            $existing = OperationalEvent::query()
                ->where('event_domain', OperationalEvent::DomainOperations)
                ->where('external_id', $externalId)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof OperationalEvent) {
                if (data_get($existing->diagnostics, 'fingerprint') !== $fingerprint) {
                    throw ValidationException::withMessages([
                        'external_reference' => 'The external reference already identifies different evidence.',
                    ]);
                }

                return ['event' => $existing, 'created' => false];
            }

            $superseded = $this->supersededEvent($type, $payload);
            $completedAt = CarbonImmutable::parse((string) $payload['completed_at']);
            $hasReviewCheck = collect($payload)->contains(
                fn (mixed $value): bool => in_array($value, ['DEGRADED', 'NOT_CHECKED'], true),
            );
            $status = match (true) {
                $payload['outcome'] === 'FAILED' => OperationalEvent::StatusFailed,
                $hasReviewCheck => OperationalEvent::StatusReviewRequired,
                default => OperationalEvent::StatusProcessed,
            };

            $event = OperationalEvent::query()->create([
                'event_domain' => OperationalEvent::DomainOperations,
                'integration' => $type === self::TypeBackup
                    ? OperationalEvent::IntegrationBackup
                    : OperationalEvent::IntegrationRestore,
                'channel' => OperationalEvent::ChannelEvidenceIngest,
                'direction' => OperationalEvent::DirectionInbound,
                'event_type' => $type.'_evidence_recorded',
                'event_version' => (string) $payload['schema_version'],
                'external_id' => $externalId,
                'status' => $status,
                'occurred_at' => $completedAt,
                'processed_at' => $status === OperationalEvent::StatusProcessed ? now() : null,
                'failed_at' => in_array($status, [OperationalEvent::StatusFailed, OperationalEvent::StatusReviewRequired], true) ? now() : null,
                'related_record_type' => $superseded?->getMorphClass(),
                'related_record_id' => $superseded?->getKey(),
                'diagnostics' => ['fingerprint' => $fingerprint],
                'payload' => $payload,
            ]);

            return ['event' => $event, 'created' => true];
        });
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        if (strlen($json) > self::MaxInputBytes) {
            throw ValidationException::withMessages(['input' => 'Evidence input may not exceed 64 KiB.']);
        }

        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['input' => 'Evidence input must be valid JSON.']);
        }

        if (! is_array($payload) || array_is_list($payload)) {
            throw ValidationException::withMessages(['input' => 'Evidence input must be one JSON object.']);
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function validate(string $type, array $payload): void
    {
        if (! in_array($type, [self::TypeBackup, self::TypeRestore], true)) {
            throw ValidationException::withMessages(['type' => 'Unsupported evidence type.']);
        }

        $typeFields = $type === self::TypeBackup ? self::BackupFields : self::RestoreFields;
        $allowedFields = [...self::CommonFields, ...$typeFields];
        $unknownFields = array_values(array_diff(array_keys($payload), $allowedFields));

        if ($unknownFields !== []) {
            throw ValidationException::withMessages([
                'input' => 'Unknown evidence fields: '.implode(', ', $unknownFields).'.',
            ]);
        }

        $requiredFields = array_values(array_diff($allowedFields, ['supersedes_external_reference']));
        foreach ($requiredFields as $field) {
            if (! array_key_exists($field, $payload) || $payload[$field] === '' || $payload[$field] === null) {
                throw ValidationException::withMessages([$field => "The {$field} field is required."]);
            }
        }

        if ($payload['schema_version'] !== '1') {
            throw ValidationException::withMessages(['schema_version' => 'Only evidence schema version 1 is accepted.']);
        }

        foreach (['external_reference', 'application_revision', 'operator_reference', 'generation_reference'] as $field) {
            $this->validateOpaqueReference($field, $payload[$field]);
        }

        if (filled($payload['supersedes_external_reference'] ?? null)) {
            $this->validateOpaqueReference('supersedes_external_reference', $payload['supersedes_external_reference']);
        }

        if (! in_array($payload['outcome'], ['SUCCEEDED', 'FAILED'], true)) {
            throw ValidationException::withMessages(['outcome' => 'Outcome must be SUCCEEDED or FAILED.']);
        }

        if (! in_array($payload['migration_result'], ['MATCHED', 'MISMATCHED', 'NOT_CHECKED'], true)) {
            throw ValidationException::withMessages(['migration_result' => 'Migration result is invalid.']);
        }

        if (! in_array($payload['integrity_result'], ['PASSED', 'FAILED', 'NOT_CHECKED'], true)) {
            throw ValidationException::withMessages(['integrity_result' => 'Integrity result is invalid.']);
        }

        $startedAt = $this->parseTimestamp('started_at', $payload['started_at']);
        $completedAt = $this->parseTimestamp('completed_at', $payload['completed_at']);

        if ($completedAt->isBefore($startedAt)) {
            throw ValidationException::withMessages(['completed_at' => 'Completion must not precede the start.']);
        }

        if ($completedAt->isAfter(now()->addMinutes(5))) {
            throw ValidationException::withMessages(['completed_at' => 'Completion cannot be in the future.']);
        }

        $this->validateTypeChecks($type, $payload);

        if ($payload['outcome'] === 'SUCCEEDED') {
            if ($payload['migration_result'] !== 'MATCHED' || $payload['integrity_result'] !== 'PASSED') {
                throw ValidationException::withMessages(['outcome' => 'Successful evidence requires matched migrations and passed integrity.']);
            }

            $requiredSuccessFields = $type === self::TypeBackup
                ? ['database_export_result', 'private_files_result', 'manifest_result', 'off_host_result']
                : ['manifest_result', 'database_restore_result', 'private_files_restore_result', 'authentication_result', 'critical_journeys_result', 'session_cache_result'];

            foreach ($requiredSuccessFields as $field) {
                if ($payload[$field] !== 'PASSED') {
                    throw ValidationException::withMessages([$field => 'Successful evidence requires this check to pass.']);
                }
            }

            if ($type === self::TypeRestore) {
                foreach (['queue_integration_result', 'lawful_disposition_result'] as $field) {
                    if ($payload[$field] === 'FAILED') {
                        throw ValidationException::withMessages([$field => 'Successful evidence cannot contain a failed reconciliation check.']);
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function validateTypeChecks(string $type, array $payload): void
    {
        $fields = $type === self::TypeBackup
            ? ['database_export_result', 'private_files_result', 'manifest_result', 'off_host_result']
            : ['manifest_result', 'database_restore_result', 'private_files_restore_result', 'authentication_result', 'critical_journeys_result', 'session_cache_result'];

        foreach ($fields as $field) {
            if (! in_array($payload[$field], ['PASSED', 'FAILED', 'NOT_CHECKED'], true)) {
                throw ValidationException::withMessages([$field => "The {$field} result is invalid."]);
            }
        }

        if ($type === self::TypeRestore) {
            foreach (['measured_duration_minutes', 'observed_data_loss_minutes'] as $field) {
                if (! is_int($payload[$field]) || $payload[$field] < 0 || $payload[$field] > 525_600) {
                    throw ValidationException::withMessages([$field => "The {$field} value is invalid."]);
                }
            }

            foreach (['queue_integration_result', 'lawful_disposition_result'] as $field) {
                if (! in_array($payload[$field], ['PASSED', 'FAILED', 'NOT_CHECKED', 'DEGRADED', 'NOT_APPLICABLE'], true)) {
                    throw ValidationException::withMessages([$field => "The {$field} result is invalid."]);
                }
            }
        }
    }

    private function validateOpaqueReference(string $field, mixed $value): void
    {
        if (! is_string($value) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/', $value) !== 1) {
            throw ValidationException::withMessages([$field => "The {$field} must be an opaque reference."]);
        }
    }

    private function parseTimestamp(string $field, mixed $value): CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            throw ValidationException::withMessages([$field => "The {$field} must be an RFC 3339 timestamp."]);
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => "The {$field} timestamp is invalid."]);
        }
    }

    /** @param array<string, mixed> $payload */
    private function supersededEvent(string $type, array $payload): ?OperationalEvent
    {
        $reference = $payload['supersedes_external_reference'] ?? null;

        if (! is_string($reference) || $reference === '') {
            return null;
        }

        if ($reference === $payload['external_reference']) {
            throw ValidationException::withMessages(['supersedes_external_reference' => 'Evidence cannot supersede itself.']);
        }

        $event = OperationalEvent::query()
            ->where('event_domain', OperationalEvent::DomainOperations)
            ->where('external_id', $this->externalId($type, $reference))
            ->lockForUpdate()
            ->first();

        if (! $event instanceof OperationalEvent) {
            throw ValidationException::withMessages(['supersedes_external_reference' => 'The superseded evidence does not exist.']);
        }

        return $event;
    }

    private function externalId(string $type, string $reference): string
    {
        return "operations:{$type}:{$reference}";
    }

    /** @param array<string, mixed> $payload */
    private function canonicalJson(array $payload): string
    {
        $sorted = $this->sortRecursively($payload);

        return json_encode($sorted, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function sortRecursively(array $value): array
    {
        ksort($value);

        return Arr::map($value, fn (mixed $item): mixed => is_array($item) ? $this->sortRecursively($item) : $item);
    }
}
