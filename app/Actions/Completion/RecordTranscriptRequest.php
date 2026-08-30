<?php

namespace App\Actions\Completion;

use App\Models\DegreeConferral;
use App\Models\TranscriptRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordTranscriptRequest
{
    public function execute(
        DegreeConferral $conferral,
        User $actor,
        string $requestReference,
        CarbonInterface|string $requestedOn,
        string $signatoryName,
        string $signatoryTitle,
        string $sealInputType,
        ?string $sealPath = null,
        ?string $sealChecksum = null,
        ?string $sealPlacementInstruction = null,
    ): TranscriptRequest {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff may record a transcript request.');
        }
        if (blank(trim($requestReference)) || blank(trim($signatoryName)) || blank(trim($signatoryTitle))) {
            throw ValidationException::withMessages(['request' => 'Request reference, signatory name, and signatory title are required.']);
        }
        $validSeal = ($sealInputType === TranscriptRequest::SealImage && filled($sealPath) && preg_match('/^[a-f0-9]{64}$/', (string) $sealChecksum) === 1)
            || ($sealInputType === TranscriptRequest::SealPlacementInstruction && filled($sealPlacementInstruction));
        if (! $validSeal) {
            throw ValidationException::withMessages(['seal' => 'Provide a private seal image with checksum or a seal-placement instruction.']);
        }
        $date = $requestedOn instanceof CarbonInterface
            ? CarbonImmutable::instance($requestedOn)
            : CarbonImmutable::parse($requestedOn, config('app.timezone'));

        return DB::transaction(function () use ($conferral, $actor, $requestReference, $date, $signatoryName, $signatoryTitle, $sealInputType, $sealPath, $sealChecksum, $sealPlacementInstruction): TranscriptRequest {
            $locked = DegreeConferral::query()->lockForUpdate()->findOrFail($conferral->id);
            $existing = TranscriptRequest::query()->where('external_request_reference', trim($requestReference))->lockForUpdate()->first();
            if ($existing instanceof TranscriptRequest) {
                $sameRequest = (int) $existing->degree_conferral_id === (int) $locked->id
                    && (string) $existing->getRawOriginal('requested_on') === $date->toDateString()
                    && $existing->signatory_name === trim($signatoryName)
                    && $existing->signatory_title === trim($signatoryTitle)
                    && $existing->seal_input_type === $sealInputType
                    && $existing->seal_path === $sealPath
                    && $existing->seal_checksum === $sealChecksum
                    && $existing->seal_placement_instruction === $sealPlacementInstruction;
                if (! $sameRequest) {
                    throw ValidationException::withMessages(['request' => 'That external request reference already identifies a different TOR request.']);
                }

                return $existing;
            }
            if ($locked->active_scope_key === null) {
                throw ValidationException::withMessages(['request' => 'A new TOR request requires the current conferral record.']);
            }
            $previous = TranscriptRequest::query()
                ->where('student_profile_id', $locked->student_profile_id)
                ->latest('version')
                ->lockForUpdate()
                ->first();
            $source = [
                'degree_conferral_id' => $locked->id,
                'conferral_fingerprint' => $locked->source_fingerprint,
                'request_reference' => trim($requestReference),
                'requested_on' => $date->toDateString(),
                'template_version' => TranscriptRequest::TemplateServitechV1,
                'signatory_name' => trim($signatoryName),
                'signatory_title' => trim($signatoryTitle),
                'seal_input_type' => $sealInputType,
                'seal_checksum' => $sealChecksum,
                'seal_placement_instruction' => $sealPlacementInstruction,
            ];

            return TranscriptRequest::query()->create([
                'student_profile_id' => $locked->student_profile_id,
                'degree_conferral_id' => $locked->id,
                'version' => ((int) $previous?->version) + 1,
                'supersedes_request_id' => null,
                'external_request_reference' => trim($requestReference),
                'requested_on' => $date->toDateString(),
                'due_on' => $date->addDays(30)->toDateString(),
                'template_version' => TranscriptRequest::TemplateServitechV1,
                'signatory_name' => trim($signatoryName),
                'signatory_title' => trim($signatoryTitle),
                'seal_input_type' => $sealInputType,
                'seal_path' => $sealPath,
                'seal_checksum' => $sealChecksum,
                'seal_placement_instruction' => $sealPlacementInstruction,
                'source_fingerprint' => hash('sha256', json_encode($source, JSON_THROW_ON_ERROR)),
                'state' => TranscriptRequest::StateOpen,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);
        }, attempts: 3);
    }
}
