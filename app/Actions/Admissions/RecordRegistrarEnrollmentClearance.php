<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionApplication;
use App\Models\AdmissionApplicationEvent;
use App\Models\AdmissionDecision;
use App\Models\Enrollment;
use App\Models\IdentityMatchReview;
use App\Models\OperationalEvent;
use App\Models\RegistrarEnrollmentClearance;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecordRegistrarEnrollmentClearance
{
    public function __construct(
        private readonly ReadyApplicantProjectionQuery $readiness,
        private readonly AdmissionNotificationLedger $notifications,
    ) {}

    public function execute(
        AdmissionApplication $application,
        User $actor,
        string $result,
        bool $externalChecksConfirmed,
        ?string $safeInstruction = null,
        ?string $reason = null,
        ?string $authorityReference = null,
        ?int $expectedCurrentClearanceId = null,
        ?int $expectedDecisionId = null,
        ?int $expectedSubmissionVersionId = null,
    ): RegistrarEnrollmentClearance {
        if (! $actor->hasRole(User::StaffRoleRegistrar)
            || ! $actor->canAuthenticate() || ! $actor->can('approve-documents')) {
            throw new AuthorizationException('Only an authorized Registrar may record enrollment clearance.');
        }

        $validated = Validator::make([
            'result' => $result, 'external_checks_confirmed' => $externalChecksConfirmed,
            'safe_instruction' => filled($safeInstruction) ? trim($safeInstruction) : null,
            'reason' => filled($reason) ? trim($reason) : null,
            'authority_reference' => filled($authorityReference) ? trim($authorityReference) : null,
        ], [
            'result' => ['required', Rule::in(['Cleared', 'ActionNeeded'])],
            'external_checks_confirmed' => [Rule::when($result === 'Cleared', ['accepted'])],
            'safe_instruction' => [Rule::requiredIf($result === 'ActionNeeded'), 'nullable', 'string', 'max:2000'],
            'reason' => [Rule::requiredIf($expectedCurrentClearanceId !== null), 'nullable', 'string', 'max:2000'],
            'authority_reference' => ['nullable', 'string', 'max:255'],
        ])->validate();

        return DB::transaction(function () use ($application, $actor, $validated, $expectedCurrentClearanceId, $expectedDecisionId, $expectedSubmissionVersionId): RegistrarEnrollmentClearance {
            $locked = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);
            $decision = $locked->decisions()->whereDoesntHave('successor')->lockForUpdate()->first();
            $current = $locked->enrollmentClearances()->whereDoesntHave('successor')->lockForUpdate()->first();

            if ($locked->application_state !== AdmissionApplication::StateAdmitted
                || $decision?->decision !== AdmissionDecision::DecisionAdmitted
                || $decision?->id !== $expectedDecisionId
                || $locked->current_submission_version_id === null
                || $locked->current_submission_version_id !== $expectedSubmissionVersionId
                || (int) $decision->application_submission_version_id !== (int) $locked->current_submission_version_id
                || $current?->id !== $expectedCurrentClearanceId) {
                throw ValidationException::withMessages(['result' => 'The application, decision or clearance changed. Refresh and review the current sources before recording clearance.']);
            }

            if ($validated['result'] === 'Cleared' && $locked->identityMatchReviews()->where('outcome', IdentityMatchReview::OutcomePending)->exists()) {
                throw ValidationException::withMessages(['result' => 'Resolve the private identity warning before clearing enrollment.']);
            }

            $wasReady = $this->readiness->forApplication($locked)['ready'];
            $recorded = $locked->enrollmentClearances()->create([
                ...$validated,
                'application_submission_version_id' => $locked->current_submission_version_id,
                'admission_decision_id' => $decision->id,
                'identity_source_hash' => RegistrarEnrollmentClearance::identitySourceHash($locked),
                'recorded_by' => $actor->id,
                'recorded_at' => now(config('app.timezone')),
                'supersedes_clearance_id' => $current?->id,
            ]);
            $locked->events()->create([
                'event_type' => 'RegistrarEnrollmentClearanceRecorded',
                'event_key' => 'enrollment-clearance:'.$recorded->id,
                'actor_id' => $actor->id,
                'source_type' => $recorded::class,
                'source_id' => $recorded->id,
                'payload' => ['result' => $recorded->result, 'supersedes_clearance_id' => $current?->id],
                'occurred_at' => $recorded->recorded_at,
            ]);

            $isReady = $this->readiness->forApplication($locked->fresh())['ready'];
            if ($wasReady !== $isReady) {
                $locked->events()->create([
                    'event_type' => $isReady ? AdmissionApplicationEvent::TypeReadinessBecameTrue : AdmissionApplicationEvent::TypeReadinessBecameFalse,
                    'event_key' => 'clearance-readiness:'.$recorded->id,
                    'actor_id' => $actor->id,
                    'source_type' => $recorded::class,
                    'source_id' => $recorded->id,
                    'payload' => ['ready' => $isReady],
                    'occurred_at' => $recorded->recorded_at,
                ]);
                if ($isReady) {
                    $this->notifications->queuePending(
                        $locked, $locked->user()->firstOrFail(),
                        eventType: OperationalEvent::TypeAdmissionReadyForEnrollment,
                        sourceKey: 'application:'.$locked->id.':first-ready',
                        safePayload: ['application_reference' => $locked->application_reference, 'ready' => true],
                    );
                }
            }

            if (! $isReady) {
                foreach (Enrollment::query()->where('admission_application_id', $locked->id)->lockForUpdate()->get() as $case) {
                    $case->registrationEvents()->create([
                        'sequence' => ((int) $case->registrationEvents()->max('sequence')) + 1,
                        'event_type' => $case->officially_enrolled_at === null ? 'AdmissionClearanceActionNeeded' : 'AdmissionsDiscrepancyRequiresRegistrar',
                        'from_outcome' => $case->canonical_outcome,
                        'to_outcome' => $case->canonical_outcome,
                        'reason' => 'Registrar clearance #'.$recorded->id.' changed. Registrar must resolve the current admissions source before the next authorized action.',
                        'authority_reference' => $recorded->authority_reference,
                        'actor_id' => $actor->id, 'recorded_at' => $recorded->recorded_at,
                    ]);
                }
            }

            return $recorded;
        }, attempts: 3);
    }
}
