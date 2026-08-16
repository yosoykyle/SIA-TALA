<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionApplication;
use App\Models\AdmissionApplicationEvent;
use App\Models\AdmissionCycle;
use App\Models\OperationalEvent;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChangeAdmissionApplicationLifecycle
{
    public function __construct(
        private readonly ReadyApplicantProjectionQuery $readiness,
        private readonly AdmissionNotificationLedger $notifications,
    ) {}

    public function withdrawByApplicant(
        AdmissionApplication $application,
        User $applicant,
        ?string $reason,
    ): AdmissionApplication {
        if ($application->user_id !== $applicant->id
            || ! $applicant->hasRole('applicant')
            || ! $applicant->canAuthenticate()) {
            throw new AuthorizationException('Applicants may withdraw only their own application.');
        }

        return $this->withdraw($application, $applicant, $reason, null, true);
    }

    public function withdrawByRegistrar(
        AdmissionApplication $application,
        User $actor,
        string $reason,
        string $authorityReference,
    ): AdmissionApplication {
        $this->authorizeRegistrar($actor);

        return $this->withdraw($application, $actor, $reason, $authorityReference, false);
    }

    public function reopen(
        AdmissionApplication $application,
        User $actor,
        string $reason,
        string $authorityReference,
    ): AdmissionApplication {
        $this->authorizeRegistrar($actor);
        $validated = $this->validateEvidence($reason, $authorityReference, requireReason: true);

        return DB::transaction(function () use ($application, $actor, $validated): AdmissionApplication {
            $locked = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);
            $this->assertRegistrationNotStarted($locked);

            if ($locked->application_state !== AdmissionApplication::StateWithdrawn) {
                throw ValidationException::withMessages([
                    'application_state' => 'Only a Withdrawn application can be reopened.',
                ]);
            }

            $cycle = AdmissionCycle::query()->findOrFail($locked->admission_cycle_id);

            if ($cycle->state !== AdmissionCycle::StatePublished) {
                throw ValidationException::withMessages([
                    'admission_cycle_id' => 'The governing Admission Cycle does not permit reopening.',
                ]);
            }

            $withdrawalEvent = $locked->events()
                ->where('event_type', AdmissionApplicationEvent::TypeWithdrawn)
                ->latest('id')
                ->firstOrFail();
            $previousState = $withdrawalEvent->payload['previous_state'] ?? AdmissionApplication::StateSubmitted;
            $restoredState = in_array($previousState, [
                AdmissionApplication::StateSubmitted,
                AdmissionApplication::StateActionNeeded,
                AdmissionApplication::StateAdmitted,
            ], true) ? $previousState : AdmissionApplication::StateSubmitted;
            $locked->forceFill(['application_state' => $restoredState])->save();
            $locked->events()->create([
                'event_type' => AdmissionApplicationEvent::TypeReopened,
                'event_key' => 'admission-reopened:'.Str::uuid(),
                'actor_id' => $actor->id,
                'source_type' => AdmissionApplication::class,
                'source_id' => $locked->id,
                'payload' => [
                    'restored_state' => $restoredState,
                    'reason' => $validated['reason'],
                    'authority_reference' => $validated['authority_reference'],
                ],
                'occurred_at' => now(config('app.timezone')),
            ]);

            return $locked->refresh();
        }, attempts: 3);
    }

    private function withdraw(
        AdmissionApplication $application,
        User $actor,
        ?string $reason,
        ?string $authorityReference,
        bool $selfService,
    ): AdmissionApplication {
        $validated = $this->validateEvidence($reason, $authorityReference, requireReason: ! $selfService);

        return DB::transaction(function () use ($application, $actor, $validated, $selfService): AdmissionApplication {
            $locked = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);
            $this->assertRegistrationNotStarted($locked);

            if (! in_array($locked->application_state, [
                AdmissionApplication::StateSubmitted,
                AdmissionApplication::StateActionNeeded,
                AdmissionApplication::StateAdmitted,
            ], true)) {
                throw ValidationException::withMessages([
                    'application_state' => 'Only a Submitted, Action Needed, or Admitted application can be withdrawn.',
                ]);
            }

            $previousState = $locked->application_state;
            $locked->forceFill(['application_state' => AdmissionApplication::StateWithdrawn])->save();
            $event = $locked->events()->create([
                'event_type' => AdmissionApplicationEvent::TypeWithdrawn,
                'event_key' => 'admission-withdrawn:'.Str::uuid(),
                'actor_id' => $actor->id,
                'source_type' => AdmissionApplication::class,
                'source_id' => $locked->id,
                'payload' => [
                    'previous_state' => $previousState,
                    'reason' => $validated['reason'],
                    'authority_reference' => $validated['authority_reference'],
                    'self_service' => $selfService,
                ],
                'occurred_at' => now(config('app.timezone')),
            ]);
            $this->notifications->queuePending(
                $locked,
                $locked->user()->firstOrFail(),
                eventType: OperationalEvent::TypeAdmissionApplicationWithdrawn,
                sourceKey: 'withdrawal-event:'.$event->id,
                safePayload: [
                    'application_reference' => $locked->application_reference,
                    'withdrawn' => true,
                    'support_contact' => $locked->admissionCycle()->firstOrFail()->support_contact,
                ],
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    private function assertRegistrationNotStarted(AdmissionApplication $application): void
    {
        if ($this->readiness->registrationHasStarted($application)) {
            throw ValidationException::withMessages([
                'registration' => 'Clinic 4 registration has started; withdrawal or reopening must stop.',
            ]);
        }
    }

    private function authorizeRegistrar(User $actor): void
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)
            || ! $actor->canAuthenticate()
            || ! $actor->can('approve-documents')) {
            throw new AuthorizationException('Only an authorized Registrar may record or reopen an offline withdrawal.');
        }
    }

    /** @return array{reason: string|null, authority_reference: string|null} */
    private function validateEvidence(
        ?string $reason,
        ?string $authorityReference,
        bool $requireReason,
    ): array {
        return Validator::make([
            'reason' => filled($reason) ? trim((string) $reason) : null,
            'authority_reference' => filled($authorityReference) ? trim((string) $authorityReference) : null,
        ], [
            'reason' => [$requireReason ? 'required' : 'nullable', 'string', 'max:1000'],
            'authority_reference' => [$requireReason ? 'required' : 'nullable', 'string', 'max:255'],
        ])->validate();
    }
}
