<?php

namespace App\Actions\Applicants;

use App\Actions\Fortify\PasswordValidationRules;
use App\Jobs\ProcessDocumentOcrJob;
use App\Models\ApplicantDocumentRequirement;
use App\Models\ApplicantIntake;
use App\Models\DocumentUpload;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class ApplicantIntakeService
{
    use PasswordValidationRules;

    public function __construct(
        private AdmissionRequirementResolver $requirementResolver,
        private RetentionDocumentUndertakingService $retentionDocumentUndertakings,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): ApplicantIntake
    {
        $validated = $this->validateIntake($data);
        $term = $this->resolveTerm($validated['term_id'] ?? null);
        $duplicates = $this->duplicateMatches($validated);

        if ($duplicates !== []) {
            throw ValidationException::withMessages([
                'duplicate' => 'A matching applicant or student record already exists.',
            ]);
        }

        $timestamp = CarbonImmutable::now(config('app.timezone'));
        $requirementResolution = $this->requirementResolver->resolve($validated, $term);
        $requiredDocuments = $requirementResolution->documentKeys();

        return DB::transaction(function () use ($validated, $term, $timestamp, $requiredDocuments, $requirementResolution): ApplicantIntake {
            $user = User::query()->create([
                ...User::staffNamePayload(
                    (string) $validated['first_name'],
                    $validated['middle_name'] ?? null,
                    (string) $validated['last_name'],
                    $validated['suffix'] ?? null,
                ),
                'username' => $validated['email'],
                'email' => $validated['email'],
                'password' => Hash::make((string) $validated['password']),
                'status' => User::StatusApplicantPending,
            ]);

            Role::findOrCreate('applicant', 'web');
            $user->assignRole('applicant');

            $intake = ApplicantIntake::query()->create([
                ...Arr::only($validated, [
                    'program_id',
                    'lrn',
                    'birthdate',
                    'place_of_birth',
                    'gender',
                    'civil_status',
                    'mothers_maiden_name',
                    'contact_number',
                    'street',
                    'barangay',
                    'city',
                    'province',
                    'region',
                    'zip_code',
                    'father_name',
                    'father_occupation',
                    'mother_occupation',
                    'guardian_name',
                    'guardian_contact_number',
                    'guardian_address',
                    'year_level',
                    'applicant_type',
                    'preferred_modality',
                    'last_school_name',
                    'last_school_address',
                    'last_school_year',
                ]),
                'user_id' => $user->id,
                'term_id' => $term->id,
                'orientation_modality_acknowledged_at' => $timestamp,
                'orientation_policy_accepted_at' => $timestamp,
                'status' => ApplicantIntake::StatusPending,
                'duplicate_check_status' => ApplicantIntake::DuplicateStatusClear,
                'duplicate_check_payload' => ['matches' => []],
                'required_documents' => $requiredDocuments,
                'submitted_at' => $timestamp,
                'meta' => [
                    'discount_eligible' => $this->isFreshmenDiscountEligible($validated),
                ],
            ]);

            $this->materializeDocumentRequirements($intake, $requirementResolution);

            $this->recordActivity(
                subject: $intake,
                event: 'applicant_intake_created',
                causer: $user,
                properties: [
                    'status_after' => ApplicantIntake::StatusPending,
                    'term_id' => $term->id,
                    'required_documents' => $requiredDocuments,
                ],
                timestamp: $timestamp,
            );

            return $intake->refresh()->load(['user', 'term', 'program']);
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function recordDocumentUpload(ApplicantIntake $intake, array $data): DocumentUpload
    {
        if (! in_array($intake->status, [
            ApplicantIntake::StatusPending,
            ApplicantIntake::StatusActionRequired,
            ApplicantIntake::StatusForEvaluation,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only active applicant intakes can receive document uploads.',
            ]);
        }

        $validated = Validator::make($data, [
            'document_type' => ['required', 'string', Rule::in($intake->requiredDocumentTypes())],
            'file_disk' => ['nullable', 'string', 'max:255'],
            'file_path' => ['required', 'string', 'max:500'],
            'file_name' => ['required', 'string', 'max:255'],
            'mime_type' => ['nullable', 'string', 'max:100'],
            'file_size' => ['nullable', 'integer', 'min:1'],
            'checksum' => ['nullable', 'string', 'max:128'],
            'student_confirmed_payload' => ['nullable', 'array'],
        ])->validate();

        $timestamp = CarbonImmutable::now(config('app.timezone'));

        $upload = DB::transaction(function () use ($intake, $validated, $timestamp): DocumentUpload {
            $locked = ApplicantIntake::query()
                ->lockForUpdate()
                ->findOrFail($intake->id);
            $requirement = $locked->applicantDocumentRequirements()
                ->where('item_key', $validated['document_type'])
                ->first();

            $documentUpload = DocumentUpload::query()->create([
                'applicant_intake_id' => $locked->id,
                'applicant_document_requirement_id' => $requirement?->id,
                'student_profile_id' => null,
                'user_id' => $locked->user_id,
                'term_id' => $locked->term_id,
                'document_type' => $validated['document_type'],
                'file_disk' => $validated['file_disk'] ?? 'local',
                'file_path' => $validated['file_path'],
                'file_name' => $validated['file_name'],
                'mime_type' => $validated['mime_type'] ?? null,
                'file_size' => $validated['file_size'] ?? null,
                'checksum' => $validated['checksum'] ?? null,
                'upload_status' => 'uploaded',
                'ocr_review_status' => DocumentUpload::ReviewStatusUploaded,
                'student_confirmed_payload' => $validated['student_confirmed_payload'] ?? [],
                'student_confirmed_at' => array_key_exists('student_confirmed_payload', $validated)
                    ? $timestamp
                    : null,
            ]);

            if ($requirement instanceof ApplicantDocumentRequirement) {
                $requirement->forceFill([
                    'evidence_state' => ApplicantDocumentRequirement::EvidenceStateSubmitted,
                ])->save();
            }

            if ($locked->status === ApplicantIntake::StatusActionRequired) {
                $locked->forceFill([
                    'status' => ApplicantIntake::StatusPending,
                    'action_required_at' => null,
                ])->save();

                $locked->user()->update([
                    'status' => User::StatusApplicantPending,
                ]);
            }

            $this->recordActivity(
                subject: $locked,
                event: 'applicant_document_uploaded',
                causer: $locked->user,
                properties: [
                    'document_upload_id' => $documentUpload->id,
                    'document_type' => $documentUpload->document_type,
                    'ocr_review_status' => $documentUpload->ocr_review_status,
                ],
                timestamp: $timestamp,
            );

            return $documentUpload;
        }, attempts: 3);

        ProcessDocumentOcrJob::dispatch($upload->id);

        return $upload->refresh()->load(['applicantIntake', 'user', 'term']);
    }

    /**
     * @throws ValidationException
     */
    public function submitForRegistrarEvaluation(ApplicantIntake $intake): ApplicantIntake
    {
        $missingDocuments = $intake->missingSubmittedAdmissionGateDocumentTypes();

        if ($missingDocuments !== []) {
            throw ValidationException::withMessages([
                'required_documents' => 'All admission-gate document types must be uploaded before Registrar evaluation.',
            ]);
        }

        $timestamp = CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($intake, $timestamp): ApplicantIntake {
            $locked = ApplicantIntake::query()
                ->lockForUpdate()
                ->findOrFail($intake->id);

            $locked->forceFill([
                'status' => ApplicantIntake::StatusForEvaluation,
            ])->save();

            $locked->user()->update([
                'status' => User::StatusApplicantForEvaluation,
            ]);

            $this->recordActivity(
                subject: $locked,
                event: 'applicant_intake_submitted_for_evaluation',
                causer: $locked->user,
                properties: [
                    'status_after' => ApplicantIntake::StatusForEvaluation,
                ],
                timestamp: $timestamp,
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    /**
     * @throws ValidationException
     */
    public function approveForPayment(ApplicantIntake $intake, User $registrar): ApplicantIntake
    {
        if (! $registrar->can('approve-documents') && ! $registrar->can('evaluate-transferees')) {
            throw ValidationException::withMessages([
                'registrar' => 'Only authorized Registrar staff can approve applicant intake for payment.',
            ]);
        }

        $missingDocuments = $intake->missingApprovedAdmissionGateDocumentTypes();

        if ($missingDocuments !== []) {
            throw ValidationException::withMessages([
                'required_documents' => 'Every admission-gate applicant document must be Registrar-approved before payment unlock.',
            ]);
        }

        if ($intake->duplicate_check_status !== ApplicantIntake::DuplicateStatusClear) {
            throw ValidationException::withMessages([
                'duplicate_check_status' => 'Duplicate-check blockers must be cleared before payment unlock.',
            ]);
        }

        $timestamp = CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($intake, $registrar, $timestamp): ApplicantIntake {
            $locked = ApplicantIntake::query()
                ->lockForUpdate()
                ->findOrFail($intake->id);

            $locked->forceFill([
                'status' => ApplicantIntake::StatusApproved,
                'registrar_reviewed_by' => $registrar->id,
                'registrar_reviewed_at' => $timestamp,
                'approved_at' => $timestamp,
            ])->save();

            $locked->user()->update([
                'status' => User::StatusApplicantApproved,
            ]);

            $this->retentionDocumentUndertakings->openForApprovedIntake($locked, $registrar, $timestamp);

            $this->recordActivity(
                subject: $locked,
                event: 'applicant_intake_approved_for_payment',
                causer: $registrar,
                properties: [
                    'status_after' => ApplicantIntake::StatusApproved,
                ],
                timestamp: $timestamp,
            );

            return $locked->refresh()->load(['user', 'registrarReviewer']);
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validateIntake(array $data): array
    {
        $validator = Validator::make($data, [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:40'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => $this->passwordRules(),
            'term_id' => ['nullable', 'integer', Rule::exists((new Term)->getTable(), 'id')],
            'program_id' => ['required', 'integer', Rule::exists((new Program)->getTable(), 'id')],
            'lrn' => ['required', 'digits:12'],
            'birthdate' => ['required', 'date', 'before:today'],
            'place_of_birth' => ['nullable', 'string', 'max:255'],
            'gender' => ['required', 'string', Rule::in(['male', 'female'])],
            'civil_status' => ['required', 'string', Rule::in(['single', 'married', 'widowed', 'separated', 'annulled'])],
            'mothers_maiden_name' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['required', 'regex:/^09\d{9}$/'],
            'street' => ['nullable', 'string', 'max:255'],
            'barangay' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'province' => ['required', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'zip_code' => ['nullable', 'string', 'max:20'],
            'father_name' => ['nullable', 'string', 'max:255'],
            'father_occupation' => ['nullable', 'string', 'max:255'],
            'mother_occupation' => ['nullable', 'string', 'max:255'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_contact_number' => ['nullable', 'regex:/^09\d{9}$/'],
            'guardian_address' => ['nullable', 'string', 'max:255'],
            'year_level' => ['required', 'string', 'max:80'],
            'applicant_type' => ['required', Rule::in([
                ApplicantIntake::ApplicantTypeNew,
                ApplicantIntake::ApplicantTypeTransferee,
                ApplicantIntake::ApplicantTypeReturnee,
            ])],
            'preferred_modality' => ['required', 'string'],
            'last_school_name' => [
                Rule::requiredIf(($data['applicant_type'] ?? null) === ApplicantIntake::ApplicantTypeTransferee),
                'nullable',
                'string',
                'max:255',
            ],
            'last_school_address' => [
                Rule::requiredIf(($data['applicant_type'] ?? null) === ApplicantIntake::ApplicantTypeTransferee),
                'nullable',
                'string',
                'max:255',
            ],
            'last_school_year' => ['nullable', 'string', 'max:80'],
            'orientation_modality_acknowledged' => ['accepted'],
            'orientation_policy_accepted' => ['accepted'],
        ]);

        $validator->after(function ($validator) use ($data): void {
            try {
                $birthdate = CarbonImmutable::parse((string) ($data['birthdate'] ?? 'today'));
            } catch (\Throwable) {
                return;
            }

            $age = $birthdate->age;

            if ($age < 18 && blank($data['guardian_name'] ?? null)) {
                $validator->errors()->add('guardian_name', 'Guardian name is required for minor applicants.');
            }

            if ($age < 18 && blank($data['guardian_contact_number'] ?? null)) {
                $validator->errors()->add('guardian_contact_number', 'Guardian contact number is required for minor applicants.');
            }

            $modality = $data['preferred_modality'] ?? null;
            $allowedModalities = ['on_site', 'blended', 'online'];

            if (! in_array($modality, $allowedModalities, true)) {
                $validator->errors()->add('preferred_modality', 'Preferred modality is not valid for the College deployment.');
            }
        });

        return $validator->validate();
    }

    /**
     * @throws ValidationException
     */
    private function resolveTerm(mixed $termId): Term
    {
        if ($termId !== null) {
            return Term::query()->findOrFail((int) $termId);
        }

        $term = Term::query()
            ->where('is_active', true)
            ->latest('id')
            ->first();

        if (! $term instanceof Term) {
            throw ValidationException::withMessages([
                'term_id' => 'An active term is required before applicant intake can start.',
            ]);
        }

        return $term;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function duplicateMatches(array $data): array
    {
        $matches = [];

        $studentProfile = StudentProfile::query()
            ->with('user:id,name,email')
            ->where('lrn', $data['lrn'])
            ->first();

        if ($studentProfile instanceof StudentProfile) {
            $matches[] = [
                'type' => 'student_profile_lrn',
                'student_profile_id' => $studentProfile->id,
                'user_id' => $studentProfile->user_id,
            ];
        }

        $applicant = ApplicantIntake::query()
            ->where('lrn', $data['lrn'])
            ->orWhere(function ($query) use ($data): void {
                $query->whereDate('birthdate', $data['birthdate'])
                    ->whereHas('user', function ($userQuery) use ($data): void {
                        $userQuery
                            ->where('first_name', $data['first_name'])
                            ->where('last_name', $data['last_name']);
                    });
            })
            ->first();

        if ($applicant instanceof ApplicantIntake) {
            $matches[] = [
                'type' => 'applicant_intake_identity',
                'applicant_intake_id' => $applicant->id,
                'user_id' => $applicant->user_id,
            ];
        }

        return $matches;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isFreshmenDiscountEligible(array $data): bool
    {
        return $data['applicant_type'] === ApplicantIntake::ApplicantTypeNew
            && in_array($data['year_level'], ['1st Year', 'first_year'], true);
    }

    private function materializeDocumentRequirements(
        ApplicantIntake $intake,
        AdmissionRequirementResolution $resolution,
    ): void {
        foreach ($resolution->items as $item) {
            ApplicantDocumentRequirement::query()->create([
                'applicant_intake_id' => $intake->id,
                'admission_offering_id' => $resolution->offering->id,
                'admission_requirement_policy_id' => $resolution->policy->id,
                'document_requirement_item_id' => $item->id,
                'item_key' => $item->key,
                'label' => $item->label,
                'gate_type' => $item->gate_type,
                'permitted_evidence_methods' => $item->permitted_evidence_methods,
                'storage_class' => $item->storage_class,
                'sensitivity_class' => $item->sensitivity_class,
                'ocr_policy' => $item->ocr_policy,
                'deadline_strategy' => $item->deadline_strategy,
                'evidence_state' => ApplicantDocumentRequirement::EvidenceStatePending,
                'meta' => [
                    'policy_version' => $resolution->policy->version,
                    'source_item_key' => $item->key,
                ],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function recordActivity(
        ApplicantIntake $subject,
        string $event,
        User $causer,
        array $properties,
        CarbonImmutable $timestamp,
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'applicant_intake',
            'description' => 'Applicant intake transition.',
            'subject_type' => ApplicantIntake::class,
            'subject_id' => $subject->id,
            'event' => $event,
            'causer_type' => User::class,
            'causer_id' => $causer->id,
            'properties' => json_encode($properties, JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }
}
