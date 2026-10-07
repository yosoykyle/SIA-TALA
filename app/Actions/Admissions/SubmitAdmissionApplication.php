<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionApplication;
use App\Models\AdmissionApplicationEvent;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationCorrectionItem;
use App\Models\ApplicationCorrectionRequest;
use App\Models\ApplicationSubmissionVersion;
use App\Models\OperationalEvent;
use App\Models\User;
use App\Support\AdmissionApplicationReference;
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
        private readonly ResolveAdmissionRequirementSet $requirementSets,
    ) {}

    public function execute(
        AdmissionApplication $application,
        User $applicant,
        ?int $expectedRequirementSetId = null,
    ): AdmissionApplication {
        if ($application->user_id !== $applicant->id
            || ! $applicant->hasRole('applicant')
            || ! $applicant->canAuthenticate()) {
            throw new AuthorizationException('Applicants may submit only their own application.');
        }

        return DB::transaction(function () use (
            $application,
            $applicant,
            $expectedRequirementSetId,
        ): AdmissionApplication {
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

            $requirementSet = $this->requirementSets->forApplication($locked, lockForUpdate: true);

            if ($expectedRequirementSetId !== null && $expectedRequirementSetId !== $requirementSet->id) {
                throw ValidationException::withMessages([
                    'requirements' => 'The governing Requirement Set changed while this page was open. Review the current requirements before submitting.',
                ]);
            }

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

            if ($firstSubmission && blank($locked->application_reference)) {
                $locked->application_reference = AdmissionApplicationReference::generate($submittedAt->year);
            }

            $snapshot = Arr::only($locked->getAttributes(), $this->snapshotAttributes());
            $snapshot['application_reference'] = $locked->application_reference;
            $snapshot['admission_cycle_id'] = $locked->admission_cycle_id;
            $snapshot['term_id'] = $locked->term_id;
            $snapshot['admission_cycle'] = Arr::only(
                $cycle->getAttributes(),
                ['id', 'code', 'label', 'term_id'],
            );
            $snapshot['term'] = Arr::only(
                $locked->term()->firstOrFail()->getAttributes(),
                ['id', 'code', 'label'],
            );
            $snapshot['program'] = Arr::only(
                $locked->program()->firstOrFail()->getAttributes(),
                ['id', 'code', 'name'],
            );
            $snapshot['application_state_at_submission'] = AdmissionApplication::StateSubmitted;
            $snapshot['requirement_set'] = Arr::only($requirementSet->getAttributes(), ['id', 'version', 'application_path']);
            $snapshot['requirements'] = $this->requirementSnapshot($locked, $requirementSet);

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

    /** @return list<array<string, mixed>> */
    private function requirementSnapshot(AdmissionApplication $application, AdmissionRequirementSet $set): array
    {
        return $set->requirements()->orderBy('display_order')->orderBy('id')->get()
            ->map(function (AdmissionRequirement $requirement) use ($application): array {
                $evidence = $application->evidenceVersions()
                    ->where('admission_requirement_id', $requirement->id)
                    ->latest('id')->lockForUpdate()->first();
                $review = $evidence?->preliminaryReviews()->whereDoesntHave('successor')->first();
                $credential = $application->credentialResults()
                    ->where('admission_requirement_id', $requirement->id)
                    ->whereDoesntHave('successor')->first();

                return [
                    ...Arr::only($requirement->getAttributes(), [
                        'id', 'code', 'label', 'due_stage', 'official_submission_method',
                        'applicant_instructions', 'requires_preliminary_evidence', 'display_order',
                    ]),
                    'evidence' => $evidence === null ? null : Arr::only($evidence->getAttributes(), [
                        'id', 'application_submission_version_id', 'checksum', 'mime_type', 'size_bytes',
                        'evidence_method', 'status', 'uploaded_at', 'replaces_document_evidence_id',
                    ]),
                    'preliminary_review' => $review === null ? null : Arr::only($review->getAttributes(), [
                        'id', 'result', 'reviewed_at',
                    ]),
                    'preliminary_result' => $review?->result ?? ($evidence === null
                        ? ($requirement->requires_preliminary_evidence ? 'NotSubmitted' : 'NotRequired')
                        : 'UnderReview'),
                    'official_credential_result' => $credential?->result,
                ];
            })->all();
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
            'birth_date' => ['required', 'date', 'before_or_equal:'.now('Asia/Manila')->toDateString()],
            'citizenship_country_code' => ['required', 'string', 'size:2', Rule::in(['PH'])],
            'phone' => ['required', 'regex:/^09\d{9}$/'],
            'current_city_municipality' => ['required', 'string', 'between:1,120'],
            'current_province' => ['required', 'string', 'between:1,120'],
            'prior_school_name' => ['required', 'string', 'between:1,160'],
            'prior_school_country_code' => ['required', 'string', 'size:2'],
            'prior_school_completion_year' => ['required', 'integer', 'digits:4', 'max:'.now('Asia/Manila')->year],
            'lrn_availability' => ['required', Rule::in(['Provided', 'NotIssued', 'NotAvailable'])],
            'lrn' => [Rule::requiredIf($application->lrn_availability === 'Provided'), 'nullable', 'regex:/^\\d{12}$/',
                Rule::prohibitedIf(in_array($application->lrn_availability, ['NotIssued', 'NotAvailable'], true))],
            'gender' => ['nullable', 'string', 'between:1,40'],
            'civil_status' => ['nullable', 'string', 'between:1,40'],
            'current_barangay' => ['nullable', 'string', 'between:1,120'],
            'current_street_address' => ['nullable', 'string', 'between:1,160'],
            'current_postal_code' => ['nullable', 'regex:/^\\d{4}$/'],
            'prior_school_address' => ['nullable', 'string', 'between:1,160'],
            'privacy_acknowledged_at' => ['required', 'date'],
            'accuracy_declared_at' => ['required', 'date'],
        ])->validate();

        if ($application->privacy_notice_reference !== $cycle->privacy_notice_reference) {
            throw ValidationException::withMessages([
                'privacy_acknowledged' => 'Acknowledge the current Admission Cycle privacy notice before submitting.',
            ]);
        }

        if ((filled($application->gender) || filled($application->civil_status))
            && ($application->optional_identity_consented_at === null
                || $application->optional_identity_notice_reference !== $cycle->privacy_notice_reference
                || $application->optional_identity_consent_purpose !== 'Identity-record comparison')) {
            throw ValidationException::withMessages(['optional_identity_consent' => 'Agree to identity-record comparison before supplying optional sex/civil-status details, or clear those values.']);
        }

        if (CarbonImmutable::parse($application->birth_date->toDateString(), config('app.display_timezone'))
            ->diffInYears(CarbonImmutable::today(config('app.display_timezone'))) < 18
            || filled($application->guardian_full_name) || filled($application->guardian_relationship) || filled($application->guardian_mobile)) {
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
            'lrn_availability',
            'gender',
            'civil_status',
            'current_barangay',
            'current_street_address',
            'current_postal_code',
            'prior_school_address',
            'optional_identity_notice_reference',
            'optional_identity_consent_purpose',
            'optional_identity_consented_at',
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
