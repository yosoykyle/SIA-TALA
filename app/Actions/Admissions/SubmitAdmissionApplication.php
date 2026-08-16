<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionApplication;
use App\Models\AdmissionApplicationEvent;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationCorrectionItem;
use App\Models\ApplicationCorrectionRequest;
use App\Models\ApplicationSubmissionVersion;
use App\Models\OperationalEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SubmitAdmissionApplication
{
    public function __construct(
        private readonly AdmissionNotificationLedger $notifications,
        private readonly DetectAdmissionIdentityWarnings $identityWarnings,
    ) {}

    public function execute(AdmissionApplication $application, User $applicant): AdmissionApplication
    {
        if ($application->user_id !== $applicant->id
            || ! $applicant->hasRole('applicant')
            || ! $applicant->canAuthenticate()) {
            throw new AuthorizationException('Applicants may submit only their own application.');
        }

        return DB::transaction(function () use ($application, $applicant): AdmissionApplication {
            $locked = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);

            if (! in_array($locked->application_state, [
                AdmissionApplication::StateDraft,
                AdmissionApplication::StateActionNeeded,
            ], true)) {
                throw ValidationException::withMessages([
                    'application_state' => 'Only a Draft or active correction can be submitted.',
                ]);
            }

            $cycle = AdmissionCycle::query()->lockForUpdate()->findOrFail($locked->admission_cycle_id);
            $firstSubmission = $locked->current_submission_version_id === null;

            if ($firstSubmission) {
                $this->assertFirstSubmissionsOpen($cycle);
            }

            $requirementSet = $this->requirementSet($locked, $firstSubmission);
            $this->validateCompleteApplication($locked, $cycle);
            $this->assertRequiredEvidenceExists($locked, $requirementSet);
            $activeCorrection = $locked->correctionRequests()
                ->where('state', ApplicationCorrectionRequest::StateActive)
                ->lockForUpdate()
                ->first();

            if (! $firstSubmission && ! $activeCorrection instanceof ApplicationCorrectionRequest) {
                throw ValidationException::withMessages([
                    'correction_request' => 'This resubmission has no active Registrar correction request.',
                ]);
            }

            if ($activeCorrection instanceof ApplicationCorrectionRequest) {
                $this->assertNamedCorrectionsWereHandled($locked, $activeCorrection);
            }

            $version = (int) $locked->submissionVersions()->lockForUpdate()->max('version') + 1;
            $submittedAt = CarbonImmutable::now(config('app.timezone'));
            $snapshot = Arr::only($locked->getAttributes(), $this->snapshotAttributes());
            $snapshot['application_reference'] = $locked->application_reference;
            $snapshot['admission_cycle_id'] = $locked->admission_cycle_id;
            $snapshot['term_id'] = $locked->term_id;

            $submission = $locked->submissionVersions()->create([
                'admission_requirement_set_id' => $requirementSet->id,
                'version' => $version,
                'snapshot' => $snapshot,
                'privacy_notice_reference' => $locked->privacy_notice_reference,
                'submitted_by' => $applicant->id,
                'submitted_at' => $submittedAt,
            ]);

            if ($activeCorrection instanceof ApplicationCorrectionRequest) {
                $activeCorrection->forceFill([
                    'state' => ApplicationCorrectionRequest::StateCompleted,
                    'completed_at' => $submittedAt,
                ])->save();
            }

            $locked->forceFill([
                'application_state' => AdmissionApplication::StateSubmitted,
                'current_submission_version_id' => $submission->id,
                'submitted_at' => $submittedAt,
                'accuracy_declared_at' => $submittedAt,
            ])->save();
            $this->identityWarnings->forApplication($locked);
            $locked->events()->create([
                'event_type' => $firstSubmission
                    ? AdmissionApplicationEvent::TypeSubmitted
                    : AdmissionApplicationEvent::TypeResubmitted,
                'event_key' => 'admission-submission:'.$submission->id.':'.Str::uuid(),
                'actor_id' => $applicant->id,
                'source_type' => ApplicationSubmissionVersion::class,
                'source_id' => $submission->id,
                'payload' => [
                    'version' => $version,
                    'requirement_set_id' => $requirementSet->id,
                ],
                'occurred_at' => $submittedAt,
            ]);
            $this->notifications->queuePending(
                $locked,
                $applicant,
                eventType: $firstSubmission
                    ? OperationalEvent::TypeAdmissionApplicationSubmitted
                    : OperationalEvent::TypeAdmissionApplicationResubmitted,
                sourceKey: 'submission:'.$submission->id,
                safePayload: [
                    'application_reference' => $locked->application_reference,
                    'submitted_at' => $submittedAt->toIso8601String(),
                ],
            );

            return $locked->refresh()->load('currentSubmissionVersion');
        }, attempts: 3);
    }

    private function assertFirstSubmissionsOpen(AdmissionCycle $cycle): void
    {
        $now = CarbonImmutable::now(config('app.timezone'));

        if ($cycle->state !== AdmissionCycle::StatePublished
            || $cycle->opens_at === null
            || $cycle->closes_at === null
            || $now->lessThan($cycle->opens_at)
            || ! $now->lessThan($cycle->closes_at)) {
            throw ValidationException::withMessages([
                'admission_cycle_id' => 'The Admission Cycle closed before submission. Your draft remains saved.',
            ]);
        }
    }

    private function requirementSet(
        AdmissionApplication $application,
        bool $firstSubmission,
    ): AdmissionRequirementSet {
        if (! $firstSubmission) {
            return $application->currentSubmissionVersion()
                ->lockForUpdate()
                ->firstOrFail()
                ->requirementSet()
                ->firstOrFail();
        }

        $set = AdmissionRequirementSet::query()
            ->where('admission_cycle_id', $application->admission_cycle_id)
            ->where('application_path', $application->application_path)
            ->where('state', AdmissionRequirementSet::StatePublished)
            ->where('effective_at', '<=', now(config('app.timezone')))
            ->latest('version')
            ->lockForUpdate()
            ->first();

        if (! $set instanceof AdmissionRequirementSet) {
            throw ValidationException::withMessages([
                'requirements' => 'No effective published requirement version applies to this application.',
            ]);
        }

        return $set;
    }

    private function validateCompleteApplication(
        AdmissionApplication $application,
        AdmissionCycle $cycle,
    ): void {
        $attributes = $application->getAttributes();
        Validator::make($attributes, [
            'program_id' => ['required', 'integer'],
            'application_path' => ['required', Rule::in([
                AdmissionApplication::PathFirstYear,
                AdmissionApplication::PathTransferee,
            ])],
            'credential_basis' => ['required', Rule::in($this->credentialBasesFor($application->application_path))],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'birth_date' => ['required', 'date', 'before_or_equal:today'],
            'citizenship_country_code' => ['required', 'string', 'size:2', Rule::in(['PH'])],
            'phone' => ['required', 'regex:/^09\d{9}$/'],
            'current_city_municipality' => ['required', 'string', 'between:1,120'],
            'current_province' => ['required', 'string', 'between:1,120'],
            'prior_school_name' => ['required', 'string', 'between:1,160'],
            'prior_school_country_code' => ['required', 'string', 'size:2'],
            'prior_school_completion_year' => ['required', 'integer', 'digits:4', 'max:'.now(config('app.timezone'))->year],
            'lrn' => ['nullable', 'digits:12'],
            'privacy_acknowledged_at' => ['required', 'date'],
            'accuracy_declared_at' => ['required', 'date'],
        ])->validate();

        if ($application->privacy_notice_reference !== $cycle->privacy_notice_reference) {
            throw ValidationException::withMessages([
                'privacy_acknowledged' => 'Acknowledge the current Admission Cycle privacy notice before submitting.',
            ]);
        }

        if (CarbonImmutable::parse($application->birth_date)->age < 18) {
            Validator::make($attributes, [
                'guardian_full_name' => ['required', 'string', 'max:160'],
                'guardian_relationship' => ['required', 'string', 'between:1,60'],
                'guardian_mobile' => ['required', 'regex:/^09\d{9}$/'],
            ])->validate();
        }
    }

    private function assertRequiredEvidenceExists(
        AdmissionApplication $application,
        AdmissionRequirementSet $requirementSet,
    ): void {
        $requiredIds = $requirementSet->requirements()
            ->where('requires_preliminary_evidence', true)
            ->pluck('id');

        if ($requiredIds->isEmpty()) {
            return;
        }

        $providedIds = $application->evidenceVersions()
            ->whereIn('admission_requirement_id', $requiredIds)
            ->pluck('admission_requirement_id')
            ->unique();
        $missing = $requiredIds->diff($providedIds);

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'evidence' => 'Upload a valid private file for every required preliminary-evidence item.',
            ]);
        }
    }

    private function assertNamedCorrectionsWereHandled(
        AdmissionApplication $application,
        ApplicationCorrectionRequest $request,
    ): void {
        $request->loadMissing('items');
        $fieldCorrectionExists = $request->items
            ->contains('scope_type', ApplicationCorrectionItem::ScopeField);

        if ($fieldCorrectionExists
            && $application->updated_at->lessThan($request->requested_at)) {
            throw ValidationException::withMessages([
                'correction_scope' => 'Save every named field correction before resubmitting.',
            ]);
        }

        foreach ($request->items->where('scope_type', ApplicationCorrectionItem::ScopeEvidence) as $item) {
            $replacementExists = $application->evidenceVersions()
                ->where('admission_requirement_id', $item->admission_requirement_id)
                ->where('uploaded_at', '>=', $request->requested_at)
                ->exists();

            if (! $replacementExists) {
                throw ValidationException::withMessages([
                    'correction_scope' => 'Upload a new private evidence version for every named evidence correction.',
                ]);
            }
        }
    }

    /** @return list<string> */
    private function credentialBasesFor(string $path): array
    {
        return $path === AdmissionApplication::PathTransferee
            ? [AdmissionApplication::CredentialTransfer]
            : [
                AdmissionApplication::CredentialSeniorHighSchool,
                AdmissionApplication::CredentialAlsAe,
                AdmissionApplication::CredentialPept,
            ];
    }

    /** @return list<string> */
    private function snapshotAttributes(): array
    {
        return [
            'program_id',
            'application_path',
            'credential_basis',
            'first_name',
            'middle_name',
            'last_name',
            'extension_name',
            'birth_date',
            'citizenship_country_code',
            'email',
            'phone',
            'current_city_municipality',
            'current_province',
            'prior_school_name',
            'prior_school_country_code',
            'prior_school_completion_year',
            'lrn',
            'prior_college_identifier',
            'guardian_full_name',
            'guardian_relationship',
            'guardian_mobile',
            'privacy_notice_reference',
            'privacy_acknowledged_at',
            'accuracy_declared_at',
        ];
    }
}
