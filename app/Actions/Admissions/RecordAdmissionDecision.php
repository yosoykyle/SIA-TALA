<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionApplication;
use App\Models\AdmissionApplicationEvent;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirement;
use App\Models\Enrollment;
use App\Models\IdentityMatchReview;
use App\Models\OperationalEvent;
use App\Models\PreliminaryEvidenceReview;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecordAdmissionDecision
{
    public function __construct(
        private readonly AdmissionNotificationLedger $notifications,
        private readonly ReadyApplicantProjectionQuery $readiness,
    ) {}

    public function execute(
        AdmissionApplication $application,
        User $actor,
        string $decision,
        string $reason,
        ?string $authorityReference,
        string $applicantExplanation,
        ?int $expectedCurrentDecisionId = null,
        ?int $expectedSubmissionVersionId = null,
        bool $requiresExternalApproval = false,
    ): AdmissionDecision {
        $this->authorize($actor);
        $expectedSubmissionVersionId ??= $application->current_submission_version_id;
        $validated = Validator::make([
            'decision' => $decision,
            'reason' => trim($reason),
            'authority_reference' => filled($authorityReference) ? trim($authorityReference) : null,
            'applicant_explanation' => trim($applicantExplanation),
        ], [
            'decision' => ['required', Rule::in([
                AdmissionDecision::DecisionAdmitted,
                AdmissionDecision::DecisionNotAdmitted,
            ])],
            'reason' => ['required', 'string', 'max:2000'],
            'authority_reference' => ['nullable', 'string', 'max:255'],
            'applicant_explanation' => ['required', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use (
            $application,
            $actor,
            $validated,
            $expectedCurrentDecisionId,
            $expectedSubmissionVersionId,
            $requiresExternalApproval,
        ): AdmissionDecision {
            $locked = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);
            if ($locked->current_submission_version_id !== $expectedSubmissionVersionId) {
                throw ValidationException::withMessages([
                    'decision' => 'The submitted version changed. Refresh and review the current version before recording a decision.',
                ]);
            }

            $current = $locked->decisions()
                ->whereDoesntHave('successor')
                ->lockForUpdate()
                ->first();

            if (($current?->id) !== $expectedCurrentDecisionId) {
                throw ValidationException::withMessages([
                    'decision' => 'The current admission decision changed. Refresh before recording a successor.',
                ]);
            }

            if (($current instanceof AdmissionDecision || $requiresExternalApproval)
                && blank($validated['authority_reference'])) {
                throw ValidationException::withMessages([
                    'authority_reference' => 'Enter the separate approval reference for a replacement decision or exceptional approval.',
                ]);
            }

            if (! $current instanceof AdmissionDecision
                && $locked->application_state !== AdmissionApplication::StateSubmitted) {
                throw ValidationException::withMessages([
                    'application_state' => 'Only a Submitted application can receive its first admission decision.',
                ]);
            }

            if ($current instanceof AdmissionDecision
                && ! in_array($locked->application_state, [
                    AdmissionApplication::StateAdmitted,
                    AdmissionApplication::StateNotAdmitted,
                ], true)) {
                throw ValidationException::withMessages([
                    'application_state' => 'Only a current decided application can receive a superseding decision.',
                ]);
            }

            if ($validated['decision'] === AdmissionDecision::DecisionAdmitted
                && $locked->identityMatchReviews()
                    ->where('outcome', IdentityMatchReview::OutcomePending)
                    ->exists()) {
                throw ValidationException::withMessages([
                    'identity_match' => 'Resolve every private identity warning before recording Admitted.',
                ]);
            }

            if ($validated['decision'] === AdmissionDecision::DecisionAdmitted) {
                $this->assertPreliminaryReviewComplete($locked);
            }
            $wasReady = $this->readiness->forApplication($locked)['ready'];
            $recorded = $locked->decisions()->create([
                'application_submission_version_id' => $locked->current_submission_version_id,
                'decision' => $validated['decision'],
                'reason' => $validated['reason'],
                'authority_reference' => $validated['authority_reference'],
                'applicant_explanation' => $validated['applicant_explanation'],
                'decided_by' => $actor->id,
                'decided_at' => now(config('app.timezone')),
                'supersedes_admission_decision_id' => $current?->id,
            ]);
            $locked->forceFill([
                'application_state' => $validated['decision'] === AdmissionDecision::DecisionAdmitted
                    ? AdmissionApplication::StateAdmitted
                    : AdmissionApplication::StateNotAdmitted,
            ])->save();
            $locked->events()->create([
                'event_type' => AdmissionApplicationEvent::TypeDecisionRecorded,
                'event_key' => 'admission-decision:'.$recorded->id.':'.Str::uuid(),
                'actor_id' => $actor->id,
                'source_type' => AdmissionDecision::class,
                'source_id' => $recorded->id,
                'payload' => [
                    'decision' => $recorded->decision,
                    'supersedes_decision_id' => $current?->id,
                    'requires_external_approval' => $requiresExternalApproval,
                ],
                'occurred_at' => $recorded->decided_at,
            ]);
            $this->notifications->queuePending(
                $locked,
                $locked->user()->firstOrFail(),
                eventType: $recorded->decision === AdmissionDecision::DecisionAdmitted
                    ? OperationalEvent::TypeAdmissionApplicationAdmitted
                    : OperationalEvent::TypeAdmissionApplicationNotAdmitted,
                sourceKey: 'admission-decision:'.$recorded->id,
                safePayload: [
                    'application_reference' => $locked->application_reference,
                    'result' => $recorded->decision,
                    'applicant_explanation' => $recorded->applicant_explanation,
                    'credential_instructions' => $this->credentialInstructions($locked),
                    'support_contact' => $locked->admissionCycle()->firstOrFail()->support_contact,
                ],
            );
            $isReady = $this->readiness->forApplication($locked->fresh())['ready'];

            if ($wasReady !== $isReady) {
                $readinessEvent = $locked->events()->create([
                    'event_type' => $isReady
                        ? AdmissionApplicationEvent::TypeReadinessBecameTrue
                        : AdmissionApplicationEvent::TypeReadinessBecameFalse,
                    'event_key' => 'admission-readiness-decision:'.$recorded->id.':'.Str::uuid(),
                    'actor_id' => null,
                    'source_type' => AdmissionDecision::class,
                    'source_id' => $recorded->id,
                    'payload' => ['ready' => $isReady],
                    'occurred_at' => now(config('app.timezone')),
                ]);

                if ($isReady) {
                    $this->notifications->queuePending(
                        $locked,
                        $locked->user()->firstOrFail(),
                        eventType: OperationalEvent::TypeAdmissionReadyForEnrollment,
                        sourceKey: 'application:'.$locked->id.':first-ready',
                        safePayload: [
                            'application_reference' => $locked->application_reference,
                            'ready' => true,
                        ],
                    );
                }
            }

            if ($current instanceof AdmissionDecision && ! $isReady) {
                foreach (Enrollment::query()->where('admission_application_id', $locked->id)->lockForUpdate()->get() as $case) {
                    $case->registrationEvents()->create([
                        'sequence' => ((int) $case->registrationEvents()->max('sequence')) + 1,
                        'event_type' => $case->officially_enrolled_at === null ? 'AdmissionDecisionActionNeeded' : 'AdmissionsDiscrepancyRequiresRegistrar',
                        'from_outcome' => $case->canonical_outcome,
                        'to_outcome' => $case->canonical_outcome,
                        'reason' => 'Admission decision #'.$recorded->id.' supersedes #'.$current->id.' for submission version #'.$recorded->application_submission_version_id.'. Registrar must resolve the changed admissions source for this learner and Term before the next authorized action.',
                        'authority_reference' => $recorded->authority_reference,
                        'actor_id' => $actor->id,
                        'recorded_at' => $recorded->decided_at,
                    ]);
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
            throw new AuthorizationException('Only an authorized Registrar may record an admission decision.');
        }
    }

    private function assertPreliminaryReviewComplete(AdmissionApplication $application): void
    {
        $set = $application->currentSubmissionVersion()->firstOrFail()->requirementSet()->firstOrFail();
        $requirements = $set->requirements()
            ->where('due_stage', AdmissionRequirement::DuePreliminaryReview)
            ->get();

        foreach ($requirements as $requirement) {
            $evidence = $application->evidenceVersions()
                ->where('admission_requirement_id', $requirement->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();
            $review = $evidence?->preliminaryReviews()
                ->whereDoesntHave('successor')
                ->lockForUpdate()
                ->first();

            if ($review?->result !== PreliminaryEvidenceReview::ResultAccepted) {
                throw ValidationException::withMessages([
                    'preliminary_evidence' => 'Every pre-decision requirement needs an acceptable current preliminary review.',
                ]);
            }
        }
    }

    /** @return list<string> */
    private function credentialInstructions(AdmissionApplication $application): array
    {
        return $application->currentSubmissionVersion()
            ->firstOrFail()
            ->requirementSet()
            ->firstOrFail()
            ->requirements()
            ->whereNotNull('applicant_instructions')
            ->orderBy('display_order')
            ->pluck('applicant_instructions')
            ->filter(fn (mixed $instruction): bool => is_string($instruction) && filled($instruction))
            ->values()
            ->all();
    }
}
