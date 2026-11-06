<?php

namespace App\Actions\Completion;

use App\Models\DegreeConferral;
use App\Models\StudentProfile;
use App\Models\TranscriptRequest;
use App\Models\TranscriptSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordTranscriptRequest
{
    public function execute(
        StudentProfile|DegreeConferral $student,
        User $actor,
        string $requestReference,
        CarbonInterface|string $requestedOn,
        ?string $signatoryName = null,
        ?string $signatoryTitle = null,
        ?string $sealInputType = null,
        ?string $sealPath = null,
        ?string $sealChecksum = null,
        ?string $sealPlacementInstruction = null,
    ): TranscriptRequest {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff may record a transcript request.');
        }
        $dateValue = $requestedOn instanceof CarbonInterface ? $requestedOn->toDateString() : $requestedOn;
        validator(['request_reference' => $requestReference, 'requested_on' => $dateValue], [
            'request_reference' => ['required', 'string', 'max:255'],
            'requested_on' => ['required', 'date', 'before_or_equal:today'],
        ])->validate();
        $date = CarbonImmutable::parse($dateValue, config('app.timezone'));
        $providedConferral = $student instanceof DegreeConferral ? $student : null;
        $studentId = $providedConferral?->student_profile_id ?? $student->id;

        return DB::transaction(function () use ($studentId, $providedConferral, $actor, $requestReference, $date, $signatoryName, $signatoryTitle, $sealInputType, $sealPath, $sealChecksum, $sealPlacementInstruction): TranscriptRequest {
            $lockedStudent = StudentProfile::query()->lockForUpdate()->findOrFail($studentId);
            $conferral = DegreeConferral::query()->where('student_profile_id', $lockedStudent->id)->whereNotNull('active_scope_key')->lockForUpdate()->first();
            if ($providedConferral instanceof DegreeConferral && $conferral?->id !== $providedConferral->id) {
                throw ValidationException::withMessages(['request' => 'The graduation source changed. Select the current Student record.']);
            }
            $existing = TranscriptRequest::query()->where('external_request_reference', trim($requestReference))->lockForUpdate()->first();
            if ($existing instanceof TranscriptRequest) {
                if ((int) $existing->student_profile_id !== (int) $lockedStudent->id || $existing->requested_on->toDateString() !== $date->toDateString()) {
                    throw ValidationException::withMessages(['request' => 'That request reference already identifies a different Student or request date.']);
                }

                return $existing;
            }
            $source = ['student_profile_id' => $lockedStudent->id, 'request_reference' => trim($requestReference), 'requested_on' => $date->toDateString()];
            $version = ((int) TranscriptRequest::query()->where('student_profile_id', $lockedStudent->id)->max('version')) + 1;

            return TranscriptRequest::query()->create([
                'student_profile_id' => $lockedStudent->id,
                'degree_conferral_id' => $conferral?->id,
                'version' => $version,
                'external_request_reference' => trim($requestReference),
                'requested_on' => $date->toDateString(),
                'due_on' => $date->addDays(30)->toDateString(),
                'template_version' => TranscriptRequest::TemplateServitechV1,
                'signatory_name' => filled($signatoryName) ? trim($signatoryName) : null,
                'signatory_title' => filled($signatoryTitle) ? trim($signatoryTitle) : null,
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

    /** @param array{signatory_name: string, signatory_title: string, seal_placement_instruction: string} $inputs */
    public function prepare(TranscriptRequest $request, User $actor, array $inputs): TranscriptRequest
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff may prepare official TOR inputs.');
        }
        $validated = validator($inputs, [
            'signatory_name' => ['required', 'string', 'max:255'],
            'signatory_title' => ['required', 'string', 'max:255'],
            'seal_placement_instruction' => ['required', 'string', 'max:1000'],
        ])->validate();

        return DB::transaction(function () use ($request, $validated): TranscriptRequest {
            $locked = TranscriptRequest::query()->lockForUpdate()->findOrFail($request->id);
            if (app(TranscriptLifecycleProjection::class)->currentSnapshot($locked) instanceof TranscriptSnapshot) {
                throw ValidationException::withMessages(['request' => 'An issued TOR is immutable. Void it before preparing a replacement.']);
            }
            $locked->update([
                'signatory_name' => trim($validated['signatory_name']),
                'signatory_title' => trim($validated['signatory_title']),
                'seal_input_type' => TranscriptRequest::SealPlacementInstruction,
                'seal_placement_instruction' => trim($validated['seal_placement_instruction']),
                'seal_path' => null,
                'seal_checksum' => null,
            ]);

            return $locked->refresh();
        }, attempts: 3);
    }
}
