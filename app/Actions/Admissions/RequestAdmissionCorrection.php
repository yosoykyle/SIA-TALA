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
    ): ApplicationCorrectionRequest {
        $this->authorize($actor);
        $validated = Validator::make([
            'scopes' => $scopes,
            'applicant_instruction' => trim($applicantInstruction),
            'responsible_party' => trim($responsibleParty),
            'due_at' => $dueAt,
        ], [
            'scopes' => ['required', 'array', 'min:1'],
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

        return DB::transaction(function () use ($application, $actor, $validated, $dueAt): ApplicationCorrectionRequest {
            $locked = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);

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
            $this->assertScopesApply($validated['scopes'], $currentSet->id);
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
            ]);

            foreach ($validated['scopes'] as $scope) {
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
                    'scope_count' => count($validated['scopes']),
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
                    'affected_items' => collect($validated['scopes'])
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

    /** @param list<array{type: string, key: string, admission_requirement_id: int|null}> $scopes */
    private function assertScopesApply(array $scopes, int $requirementSetId): void
    {
        $fieldKeys = [
            'program_id', 'application_path', 'credential_basis', 'first_name', 'middle_name',
            'last_name', 'extension_name', 'birth_date', 'citizenship_country_code', 'phone',
            'current_city_municipality', 'current_province', 'prior_school_name',
            'prior_school_country_code', 'prior_school_completion_year', 'lrn',
            'prior_college_identifier', 'guardian_full_name', 'guardian_relationship',
            'guardian_mobile', 'privacy_acknowledged', 'accuracy_declared',
        ];

        foreach ($scopes as $scope) {
            $valid = $scope['type'] === ApplicationCorrectionItem::ScopeField
                ? in_array($scope['key'], $fieldKeys, true)
                : filled($scope['admission_requirement_id'])
                    && AdmissionRequirement::query()
                        ->where('id', $scope['admission_requirement_id'])
                        ->where('admission_requirement_set_id', $requirementSetId)
                        ->exists();

            if (! $valid) {
                throw ValidationException::withMessages([
                    'scopes' => 'Every correction item must name an applicable field or evidence requirement.',
                ]);
            }
        }
    }
}
