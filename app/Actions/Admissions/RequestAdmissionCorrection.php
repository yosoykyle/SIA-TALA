<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionApplication;
use App\Models\AdmissionApplicationEvent;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\ApplicationCorrectionItem;
use App\Models\ApplicationCorrectionRequest;
use App\Models\OperationalEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RequestAdmissionCorrection
{
    public function __construct(private readonly AdmissionNotificationLedger $notifications) {}

    /** @param list<array{type: string, key: string, admission_requirement_id: int|null}> $scopes */
    public function execute(
        AdmissionApplication $application,
        User $actor,
        array $scopes,
        string $applicantInstruction,
        string $responsibleParty,
        CarbonInterface $dueAt,
        ?int $expectedSubmissionVersionId = null,
        ?string $targetApplicationPath = null,
        ?int $expectedTargetRequirementSetId = null,
    ): ApplicationCorrectionRequest {
        $this->authorize($actor);
        $validated = Validator::make([
            'scopes' => $scopes,
            'applicant_instruction' => trim($applicantInstruction),
            'responsible_party' => trim($responsibleParty),
            'due_at' => $dueAt,
        ], [
            'scopes' => $targetApplicationPath === null ? ['required', 'array', 'min:1'] : ['present', 'array'],
            'scopes.*.type' => ['required', Rule::in([
                ApplicationCorrectionItem::ScopeField,
                ApplicationCorrectionItem::ScopeEvidence,
            ])],
            'scopes.*.key' => ['required', 'string', 'max:160'],
            'scopes.*.admission_requirement_id' => ['nullable', 'integer'],
            'applicant_instruction' => ['required', 'string', 'max:2000'],
            'responsible_party' => ['required', 'string', 'max:120'],
            'due_at' => ['required', 'date', 'after:now'],
        ])->validate();
        $dueAt = CarbonImmutable::instance($dueAt);

        $expectedSubmissionVersionId ??= $application->current_submission_version_id;

        return DB::transaction(function () use ($application, $actor, $validated, $dueAt, $expectedSubmissionVersionId, $targetApplicationPath, $expectedTargetRequirementSetId): ApplicationCorrectionRequest {
            $locked = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);

            if ($locked->current_submission_version_id !== $expectedSubmissionVersionId) {
                throw ValidationException::withMessages([
                    'application_state' => 'The submitted version changed. Refresh and review it before requesting correction.',
                ]);
            }

            if ($locked->application_state !== AdmissionApplication::StateSubmitted) {
                throw ValidationException::withMessages([
                    'application_state' => 'A correction can be requested only from a Submitted application.',
                ]);
            }

            if ($locked->correctionRequests()
                ->where('state', ApplicationCorrectionRequest::StateActive)
                ->lockForUpdate()
                ->exists()) {
                throw ValidationException::withMessages([
                    'correction_request' => 'Complete the active correction request before issuing another.',
                ]);
            }

            $locked->assertReviewSource($actor, $expectedSubmissionVersionId);
            $cycle = AdmissionCycle::query()->lockForUpdate()->findOrFail($locked->admission_cycle_id);
            $now = CarbonImmutable::now(config('app.timezone'));

            if ($cycle->state !== AdmissionCycle::StatePublished
                || $cycle->correction_closes_at === null
                || $now->greaterThan($cycle->correction_closes_at)) {
                throw ValidationException::withMessages([
                    'due_at' => 'The correction boundary has passed. An authorized Registrar must extend the correction boundary before issuing another request; existing corrections and review work remain available.',
                ]);
            }

            if ($dueAt->lessThanOrEqualTo($now)) {
                throw ValidationException::withMessages([
                    'due_at' => 'The correction due time must still be in the future when the request is issued.',
                ]);
            }

            if ($dueAt->greaterThan($cycle->correction_closes_at)) {
                throw ValidationException::withMessages([
                    'due_at' => 'The correction due time exceeds the current correction boundary.',
                ]);
            }

            $currentSet = $locked->currentSubmissionVersion()->firstOrFail()->requirementSet()->firstOrFail();
            $typeCorrection = null;
            $requestedScopes = $validated['scopes'];
            if ($targetApplicationPath !== null) {
                $typeCorrection = $this->studentTypeScope($locked, $targetApplicationPath);
                if ($typeCorrection['requirement_set_id'] !== $expectedTargetRequirementSetId) {
                    throw ValidationException::withMessages(['target_application_path' => 'The target requirements changed. Preview and confirm the complete correction scope again.']);
                }
                $requestedScopes = [...$requestedScopes, ...$typeCorrection['scopes']];
            } elseif (collect($requestedScopes)->contains(fn (array $scope): bool => $scope['type'] === ApplicationCorrectionItem::ScopeField && $scope['key'] === 'application_path')) {
                throw ValidationException::withMessages(['target_application_path' => 'Select the target Student type and confirm its complete correction scope.']);
            }
            $scopes = $this->normalizedScopes($requestedScopes, $typeCorrection['requirement_set_id'] ?? $currentSet->id);
            $sequence = (int) $locked->correctionRequests()->lockForUpdate()->max('sequence') + 1;
            $request = $locked->correctionRequests()->create([
                'sequence' => $sequence,
                'state' => ApplicationCorrectionRequest::StateActive,
                'applicant_instruction' => $validated['applicant_instruction'],
                'responsible_party' => $validated['responsible_party'],
                'due_at' => $dueAt,
                'requested_by' => $actor->id,
                'requested_at' => now(config('app.timezone')),
                'completed_at' => null,
                'supersedes_correction_request_id' => null,
                'source_submission_version_id' => $typeCorrection === null ? null : $locked->current_submission_version_id,
                'target_requirement_set_id' => $typeCorrection['requirement_set_id'] ?? null,
                'target_application_path' => $targetApplicationPath,
            ]);

            foreach ($scopes as $scope) {
                $request->items()->create([
                    'scope_type' => $scope['type'],
                    'scope_key' => $scope['key'],
                    'admission_requirement_id' => $scope['admission_requirement_id'] ?? null,
                ]);
            }

            $locked->forceFill(['application_state' => AdmissionApplication::StateActionNeeded])->save();
            $locked->events()->create([
                'event_type' => AdmissionApplicationEvent::TypeCorrectionRequested,
                'event_key' => 'admission-correction:'.$request->id.':'.Str::uuid(),
                'actor_id' => $actor->id,
                'source_type' => ApplicationCorrectionRequest::class,
                'source_id' => $request->id,
                'payload' => [
                    'sequence' => $sequence,
                    'due_at' => $dueAt->toIso8601String(),
                    'scope_count' => count($scopes),
                ],
                'occurred_at' => now(config('app.timezone')),
            ]);
            $this->notifications->queuePending(
                $locked,
                $locked->user()->firstOrFail(),
                eventType: OperationalEvent::TypeAdmissionCorrectionRequested,
                sourceKey: 'correction-request:'.$request->id,
                safePayload: [
                    'application_reference' => $locked->application_reference,
                    'affected_items' => collect($scopes)
                        ->map(function (array $scope): string {
                            if ($scope['type'] === ApplicationCorrectionItem::ScopeEvidence
                                && filled($scope['admission_requirement_id'] ?? null)) {
                                return AdmissionRequirement::query()
                                    ->findOrFail((int) $scope['admission_requirement_id'])
                                    ->label;
                            }

                            return str($scope['key'])->headline()->toString();
                        })
                        ->values()
                        ->all(),
                    'instruction' => $request->applicant_instruction,
                    'due_at' => $dueAt->toIso8601String(),
                ],
            );

            return $request->load('items');
        }, attempts: 3);
    }

    private function authorize(User $actor): void
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)
            || ! $actor->canAuthenticate()
            || ! $actor->can('approve-documents')) {
            throw new AuthorizationException('Only an authorized Registrar may request an application correction.');
        }
    }

    /** @return array{requirement_set_id: int, scopes: list<array{type: string, key: string, admission_requirement_id: int|null}>} */
    public function studentTypeScope(AdmissionApplication $application, string $targetPath): array
    {
        if ($application->application_state !== AdmissionApplication::StateSubmitted
            || $application->current_submission_version_id === null || ! $application->reviewHasStarted()
            || $application->decisions()->exists()
            || ! in_array($targetPath, [AdmissionApplication::PathFirstYear, AdmissionApplication::PathTransferee], true)
            || $targetPath === $application->application_path) {
            throw ValidationException::withMessages(['target_application_path' => 'Student type correction requires a reviewed submission before its first decision. Choose the other supported type.']);
        }
        $cycle = $application->admissionCycle()->firstOrFail();
        $acceptance = $targetPath === AdmissionApplication::PathFirstYear ? 'accepts_first_year' : 'accepts_transferee';
        if ($cycle->state !== AdmissionCycle::StatePublished || $cycle->term_id !== $application->term_id
            || ! $cycle->programs()->whereKey($application->program_id)->where('is_active', true)->wherePivot($acceptance, true)->exists()) {
            throw ValidationException::withMessages(['target_application_path' => 'The bound intake cannot accept this Program and Student type. Resolve the intake configuration with the Registrar before issuing this correction.']);
        }
        $set = app(ResolveAdmissionRequirementSet::class)->forScope($cycle->id, $targetPath);
        $scopes = [];
        foreach (['application_path', 'credential_basis', 'prior_college_identifier', 'prior_school_name',
            'prior_school_country_code', 'prior_school_completion_year', 'prior_school_address', 'accuracy_declared'] as $field) {
            $scopes[] = ['type' => ApplicationCorrectionItem::ScopeField, 'key' => $field, 'admission_requirement_id' => null];
        }
        foreach ($set->requirements()->where('requires_preliminary_evidence', true)->orderBy('display_order')->orderBy('id')->get() as $requirement) {
            $retained = $application->evidenceVersions()->where('admission_requirement_id', $requirement->id)->latest('id')->first();
            if ($retained !== null && app(AdmissionEvidenceService::class)->storedCopyIsAvailable($retained, $application)) {
                continue;
            }
            $scopes[] = ['type' => ApplicationCorrectionItem::ScopeEvidence, 'key' => $requirement->code, 'admission_requirement_id' => $requirement->id];
        }

        return ['requirement_set_id' => $set->id, 'scopes' => $scopes];
    }

    /**
     * @param  list<array{type: string, key: string, admission_requirement_id: int|null}>  $scopes
     * @return list<array{type: string, key: string, admission_requirement_id: int|null}>
     */
    private function normalizedScopes(array $scopes, int $requirementSetId): array
    {
        $fieldKeys = [
            'program_id', 'application_path', 'credential_basis', 'first_name', 'middle_name',
            'last_name', 'extension_name', 'birth_date', 'citizenship_country_code', 'phone',
            'current_city_municipality', 'current_province', 'prior_school_name',
            'prior_school_country_code', 'prior_school_completion_year', 'lrn',
            'prior_college_identifier', 'guardian_full_name', 'guardian_relationship',
            'guardian_mobile', 'privacy_acknowledged', 'accuracy_declared',
            'lrn_availability', 'gender', 'civil_status', 'current_barangay',
            'current_street_address', 'current_postal_code', 'prior_school_address',
        ];
        $normalized = [];

        foreach ($scopes as $scope) {
            if ($scope['type'] === ApplicationCorrectionItem::ScopeField) {
                $valid = in_array($scope['key'], $fieldKeys, true);
            } else {
                $requirement = filled($scope['admission_requirement_id'])
                    ? AdmissionRequirement::query()
                        ->where('id', $scope['admission_requirement_id'])
                        ->where('admission_requirement_set_id', $requirementSetId)
                        ->where('requires_preliminary_evidence', true)
                        ->first()
                    : null;
                $valid = $requirement instanceof AdmissionRequirement;

                if ($valid) {
                    $scope['key'] = $requirement->code;
                }
            }

            if (! $valid) {
                throw ValidationException::withMessages([
                    'scopes' => 'Every correction item must name an applicable field or preliminary digital-evidence requirement.',
                ]);
            }

            $normalized[] = $scope;
        }

        return collect($normalized)->unique(fn (array $scope): string => $scope['type'].':'.$scope['key'])->values()->all();
    }
}
