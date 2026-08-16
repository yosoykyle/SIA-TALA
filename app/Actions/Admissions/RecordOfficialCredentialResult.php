<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionApplication;
use App\Models\AdmissionApplicationEvent;
use App\Models\AdmissionRequirement;
use App\Models\OfficialCredentialResult;
use App\Models\OperationalEvent;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecordOfficialCredentialResult
{
    public function __construct(
        private readonly ReadyApplicantProjectionQuery $readiness,
        private readonly AdmissionNotificationLedger $notifications,
    ) {}

    public function execute(
        AdmissionApplication $application,
        AdmissionRequirement $requirement,
        User $actor,
        string $result,
        ?string $sourceReference,
        string $safeExplanation,
        string $authorityReference,
        ?CarbonInterface $exceptionExpiresAt = null,
        ?int $expectedCurrentResultId = null,
    ): OfficialCredentialResult {
        $this->authorize($actor);
        $validated = Validator::make([
            'result' => $result,
            'source_reference' => filled($sourceReference) ? trim((string) $sourceReference) : null,
            'safe_explanation' => trim($safeExplanation),
            'authority_reference' => trim($authorityReference),
            'exception_expires_at' => $exceptionExpiresAt,
        ], [
            'result' => ['required', Rule::in([
                OfficialCredentialResult::ResultNotYetDue,
                OfficialCredentialResult::ResultNotReceived,
                OfficialCredentialResult::ResultReceivedUnderReview,
                OfficialCredentialResult::ResultVerified,
                OfficialCredentialResult::ResultActionNeeded,
                OfficialCredentialResult::ResultAuthorizedException,
            ])],
            'source_reference' => [
                Rule::requiredIf(! in_array($result, [
                    OfficialCredentialResult::ResultNotYetDue,
                    OfficialCredentialResult::ResultNotReceived,
                ], true)),
                'nullable',
                'string',
                'max:255',
            ],
            'safe_explanation' => ['required', 'string', 'max:2000'],
            'authority_reference' => ['required', 'string', 'max:255'],
            'exception_expires_at' => [
                Rule::requiredIf($result === OfficialCredentialResult::ResultAuthorizedException),
                'nullable',
                'date',
                'after:now',
            ],
        ])->validate();

        return DB::transaction(function () use (
            $application,
            $requirement,
            $actor,
            $validated,
            $expectedCurrentResultId,
        ): OfficialCredentialResult {
            $locked = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);

            if ($locked->application_state !== AdmissionApplication::StateAdmitted) {
                throw ValidationException::withMessages([
                    'application_state' => 'Official credential outcomes require a current Admitted application.',
                ]);
            }

            $currentSet = $locked->currentSubmissionVersion()->firstOrFail()->requirementSet()->firstOrFail();

            if ($requirement->admission_requirement_set_id !== $currentSet->id) {
                throw ValidationException::withMessages([
                    'admission_requirement_id' => 'The credential requirement does not belong to the retained submission version.',
                ]);
            }

            $current = $locked->credentialResults()
                ->where('admission_requirement_id', $requirement->id)
                ->whereDoesntHave('successor')
                ->lockForUpdate()
                ->first();

            if (($current?->id) !== $expectedCurrentResultId) {
                throw ValidationException::withMessages([
                    'result' => 'The current credential result changed. Refresh before recording a successor.',
                ]);
            }

            if ($validated['result'] === OfficialCredentialResult::ResultAuthorizedException
                && ($requirement->credential_classification !== AdmissionRequirement::ClassificationNonCore
                    || ! $requirement->exception_permitted
                    || blank($requirement->required_approving_authority))) {
                throw ValidationException::withMessages([
                    'result' => 'This credential is not classified for an authorized non-core exception.',
                ]);
            }

            $wasReady = $this->readiness->forApplication($locked)['ready'];
            $recorded = $locked->credentialResults()->create([
                'admission_requirement_id' => $requirement->id,
                'result' => $validated['result'],
                'source_reference' => $validated['source_reference'],
                'safe_explanation' => $validated['safe_explanation'],
                'authority_reference' => $validated['authority_reference'],
                'exception_expires_at' => filled($validated['exception_expires_at'] ?? null)
                    ? CarbonImmutable::parse($validated['exception_expires_at'])
                    : null,
                'recorded_by' => $actor->id,
                'recorded_at' => now(config('app.timezone')),
                'supersedes_official_credential_result_id' => $current?->id,
            ]);
            $locked->events()->create([
                'event_type' => AdmissionApplicationEvent::TypeCredentialResultRecorded,
                'event_key' => 'credential-result:'.$recorded->id.':'.Str::uuid(),
                'actor_id' => $actor->id,
                'source_type' => OfficialCredentialResult::class,
                'source_id' => $recorded->id,
                'payload' => [
                    'requirement_id' => $requirement->id,
                    'result' => $recorded->result,
                    'supersedes_result_id' => $current?->id,
                ],
                'occurred_at' => $recorded->recorded_at,
            ]);
            $isReady = $this->readiness->forApplication($locked->fresh())['ready'];

            if ($wasReady !== $isReady) {
                $event = $locked->events()->create([
                    'event_type' => $isReady
                        ? AdmissionApplicationEvent::TypeReadinessBecameTrue
                        : AdmissionApplicationEvent::TypeReadinessBecameFalse,
                    'event_key' => 'admission-readiness:'.$recorded->id.':'.Str::uuid(),
                    'actor_id' => null,
                    'source_type' => OfficialCredentialResult::class,
                    'source_id' => $recorded->id,
                    'payload' => ['ready' => $isReady],
                    'occurred_at' => now(config('app.timezone')),
                ]);

                if ($isReady) {
                    $this->notifications->queuePending(
                        $locked,
                        $locked->user()->firstOrFail(),
                        eventType: OperationalEvent::TypeAdmissionReadyForEnrollment,
                        sourceKey: 'readiness-event:'.$event->id,
                        safePayload: [
                            'application_reference' => $locked->application_reference,
                            'ready' => true,
                        ],
                    );
                }
            }

            return $recorded;
        }, attempts: 3);
    }

    private function authorize(User $actor): void
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)
            || ! $actor->canAuthenticate()
            || ! $actor->can('approve-documents')) {
            throw new AuthorizationException('Only an authorized Registrar may record official credential outcomes.');
        }
    }
}
