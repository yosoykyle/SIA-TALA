<?php

namespace App\Actions\Applicants;

use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use App\Models\AdmissionRequirementPolicy;
use App\Models\ApplicantIntake;
use App\Models\ChecklistItem;
use App\Models\DocumentEvidence;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApplicantIntakeService
{
    public function __construct(
        private AdmissionRequirementResolver $requirementResolver,
        private AdmissionWindowService $admissionWindowService,
    ) {}

    /** @param array<string, mixed> $data */
    public function saveDraft(User $applicant, array $data): ApplicantIntake
    {
        if (! $applicant->hasRole('applicant') || ! $applicant->canAuthenticate()) {
            throw ValidationException::withMessages([
                'applicant' => 'Only applicant accounts can own an applicant intake.',
            ]);
        }

        $validated = Validator::make($data, $this->draftRules())->validate();
        $intake = $applicant->applicantIntakes()
            ->where('status', '!=', ApplicantIntake::StatusWithdrawn)
            ->latest('id')
            ->first();

        if ($intake instanceof ApplicantIntake && $intake->status !== ApplicantIntake::StatusDraft) {
            throw ValidationException::withMessages([
                'status' => 'Complete the current application before starting another admission intake.',
            ]);
        }

        $termAlreadyHasApplication = $applicant->applicantIntakes()
            ->where('term_id', (int) $validated['term_id'])
            ->when($intake instanceof ApplicantIntake, fn ($query) => $query->whereKeyNot($intake->id))
            ->exists();

        if ($termAlreadyHasApplication) {
            throw ValidationException::withMessages([
                'term_id' => 'An application for this admission term already exists. Contact the Registrar if you need to apply again for the same term.',
            ]);
        }

        if (! $intake instanceof ApplicantIntake) {
            $this->assertAdmissionsWindowOpen((int) $validated['term_id']);
        }

        $policies = $this->requirementResolver->resolveFor(
            (string) $validated['admission_category'],
            (string) $validated['credential_basis'],
            failWhenEmpty: false,
        );
        $digitalPolicyIds = $policies
            ->where('evidence_method', ChecklistItem::EvidenceMethodDigitalUpload)
            ->pluck('id')
            ->map(fn (mixed $policyId): int => (int) $policyId)
            ->all();
        $documentReferences = collect($validated['document_uploads'] ?? [])
            ->filter(fn (mixed $path, mixed $policyId): bool => is_string($path)
                && filled($path)
                && in_array((int) $policyId, $digitalPolicyIds, true))
            ->mapWithKeys(fn (string $path, mixed $policyId): array => [(int) $policyId => $path])
            ->all();
        $identityPolicyId = $policies
            ->firstWhere('requirement_type', 'IDENTITY_DOCUMENT')?->getKey();

        if ($documentReferences === []
            && filled($validated['identity_evidence_reference'] ?? null)
            && $identityPolicyId !== null) {
            $documentReferences[(int) $identityPolicyId] = (string) $validated['identity_evidence_reference'];
        }

        $this->assertDocumentReferencesBelongToApplicant($applicant->id, $policies, $documentReferences);

        $previousReferences = collect($intake instanceof ApplicantIntake
            ? ($intake->getAttribute('draft_document_references') ?? [])
            : [])
            ->push($intake instanceof ApplicantIntake ? $intake->identity_evidence_reference : null)
            ->filter(fn (mixed $path): bool => filled($path))
            ->map(fn (mixed $path): string => (string) $path)
            ->unique()
            ->values()
            ->all();
        $attributes = Arr::only($validated, $this->editableAttributes());
        $attributes['draft_document_references'] = $documentReferences === [] ? null : $documentReferences;
        $attributes['identity_evidence_reference'] = $identityPolicyId === null
            ? null
            : ($documentReferences[(int) $identityPolicyId] ?? null);
        $attributes['first_name'] ??= $applicant->first_name ?? $applicant->name;
        $attributes['middle_name'] ??= $applicant->middle_name;
        $attributes['last_name'] ??= $applicant->last_name ?? $applicant->name;
        $attributes['email'] ??= $applicant->email;

        $saved = DB::transaction(function () use ($applicant, $intake, $attributes): ApplicantIntake {
            $isNewIntake = ! $intake instanceof ApplicantIntake;
            $intake ??= new ApplicantIntake([
                'user_id' => $applicant->id,
                'status' => ApplicantIntake::StatusDraft,
            ]);
            $intake->fill($attributes)->save();

            if ($isNewIntake && $applicant->status === User::StatusApplicantWithdrawn) {
                $applicant->forceFill(['status' => User::StatusApplicantPending])->save();
            }

            return $intake->refresh();
        }, attempts: 3);

        $this->deleteStaleDraftFiles($applicant->id, $previousReferences, array_values($documentReferences));

        return $saved;
    }

    public function submit(ApplicantIntake $intake, bool $informationConfirmed): ApplicantIntake
    {
        if (! $informationConfirmed) {
            throw ValidationException::withMessages([
                'information_confirmed' => 'Confirm that the application information and identity evidence are accurate before submitting.',
            ]);
        }

        if ($intake->status !== ApplicantIntake::StatusDraft) {
            throw ValidationException::withMessages([
                'status' => 'Only draft applications can be submitted.',
            ]);
        }

        Validator::make($intake->only($this->editableAttributes()), $this->submissionRules())->validate();
        $this->assertActiveSubmissionScope($intake);
        $this->assertNoUnresolvedDuplicate($intake);
        $policies = $this->requirementResolver->resolve($intake);
        $documentReferences = $this->documentReferencesFor($intake, $policies);
        $this->assertRequiredDigitalEvidence($policies, $documentReferences);
        $this->assertDocumentReferencesBelongToApplicant($intake->user_id, $policies, $documentReferences);
        $this->assertDigitalFilesAreValid($documentReferences);
        $timestamp = CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($intake, $policies, $documentReferences, $timestamp): ApplicantIntake {
            $locked = ApplicantIntake::query()->lockForUpdate()->findOrFail($intake->id);

            if ($locked->status !== ApplicantIntake::StatusDraft) {
                throw ValidationException::withMessages([
                    'status' => 'This application was already submitted.',
                ]);
            }

            $locked->forceFill([
                'status' => ApplicantIntake::StatusPending,
                'submitted_at' => $timestamp,
            ])->save();

            foreach ($policies as $policy) {
                $checklistItem = $locked->checklistItems()->create($this->checklistAttributes($policy));
                $path = $documentReferences[(int) $policy->id] ?? null;

                if ($policy->evidence_method === ChecklistItem::EvidenceMethodDigitalUpload && is_string($path)) {
                    $this->recordDigitalEvidence($checklistItem, $locked, $path, $timestamp);
                }
            }

            $this->recordActivity($locked, $timestamp);

            return $locked->refresh()->load(['checklistItems.documentEvidence', 'program', 'term']);
        }, attempts: 3);
    }

    private function assertActiveSubmissionScope(ApplicantIntake $intake): void
    {
        if (! Term::query()->whereKey($intake->term_id)->where('state', Term::StateActive)->exists()) {
            throw ValidationException::withMessages([
                'term_id' => 'Select an active admission term before submitting.',
            ]);
        }

        if (! Program::query()->whereKey($intake->program_id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'program_id' => 'Select an active program before submitting.',
            ]);
        }

        $this->assertAdmissionsWindowOpen((int) $intake->term_id);
    }

    private function assertAdmissionsWindowOpen(int $termId): void
    {
        try {
            $this->admissionWindowService->admissionsWindow($termId);
        } catch (CalendarGateViolation $exception) {
            throw ValidationException::withMessages([
                'term_id' => $exception->getMessage(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function draftRules(): array
    {
        return [
            'term_id' => ['required', 'integer', Rule::exists((new Term)->getTable(), 'id')],
            'program_id' => ['required', 'integer', Rule::exists((new Program)->getTable(), 'id')],
            'admission_category' => ['required', Rule::in([
                ApplicantIntake::AdmissionCategoryFirstTimeCollege,
                ApplicantIntake::AdmissionCategoryTransfer,
                ApplicantIntake::AdmissionCategoryReturning,
            ])],
            'credential_basis' => ['required', Rule::in([
                ApplicantIntake::CredentialBasisSeniorHighSchool,
                ApplicantIntake::CredentialBasisTransferCredentials,
                ApplicantIntake::CredentialBasisPriorStudentRecord,
            ])],
            'modality_preference' => ['sometimes', 'nullable', Rule::in([
                ApplicantIntake::ModalityPreferenceFaceToFace,
                ApplicantIntake::ModalityPreferenceOnline,
            ])],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'extension_name' => ['sometimes', 'nullable', 'string', 'max:50'],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before:today'],
            'gender' => ['sometimes', 'nullable', Rule::in(['MALE', 'FEMALE', 'OTHER', 'PREFER_NOT_TO_SAY'])],
            'civil_status' => ['sometimes', 'nullable', Rule::in(['SINGLE', 'MARRIED', 'WIDOWED', 'SEPARATED'])],
            'birth_place' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'regex:/^09\d{9}$/'],
            'address_barangay' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_district' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_province' => ['sometimes', 'nullable', 'string', 'max:255'],
            'prior_school' => ['sometimes', 'nullable', 'string', 'max:255'],
            'guardian_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'guardian_phone' => ['sometimes', 'nullable', 'regex:/^09\d{9}$/'],
            'guardian_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'identity_evidence_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'document_uploads' => ['sometimes', 'array'],
            'document_uploads.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, mixed> */
    private function submissionRules(): array
    {
        return [
            ...$this->draftRules(),
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'birth_date' => ['required', 'date', 'before:today'],
            'modality_preference' => ['required', Rule::in([
                ApplicantIntake::ModalityPreferenceFaceToFace,
                ApplicantIntake::ModalityPreferenceOnline,
            ])],
            'gender' => ['required', Rule::in(['MALE', 'FEMALE', 'OTHER', 'PREFER_NOT_TO_SAY'])],
            'civil_status' => ['required', Rule::in(['SINGLE', 'MARRIED', 'WIDOWED', 'SEPARATED'])],
            'birth_place' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'regex:/^09\d{9}$/'],
            'address_barangay' => ['required', 'string', 'max:255'],
            'address_street' => ['required', 'string', 'max:255'],
            'address_city' => ['required', 'string', 'max:255'],
            'address_province' => ['required', 'string', 'max:255'],
            'prior_school' => ['required', 'string', 'max:255'],
            'guardian_name' => ['required', 'string', 'max:255'],
            'guardian_phone' => ['required', 'regex:/^09\d{9}$/'],
            'guardian_address' => ['required', 'string', 'max:1000'],
        ];
    }

    /** @return list<string> */
    private function editableAttributes(): array
    {
        return [
            'term_id', 'program_id', 'admission_category', 'credential_basis',
            'modality_preference', 'first_name', 'middle_name', 'last_name',
            'extension_name', 'birth_date', 'gender', 'civil_status', 'birth_place',
            'email', 'phone', 'address_barangay', 'address_street', 'address_city',
            'address_district', 'address_province', 'prior_school', 'guardian_name',
            'guardian_phone', 'guardian_address', 'identity_evidence_reference',
            'draft_document_references',
        ];
    }

    private function assertNoUnresolvedDuplicate(ApplicantIntake $intake): void
    {
        if ($intake->admission_category === ApplicantIntake::AdmissionCategoryReturning) {
            return;
        }

        $studentMatch = StudentProfile::query()
            ->whereRaw('LOWER(first_name) = ?', [mb_strtolower($intake->first_name)])
            ->whereRaw('LOWER(last_name) = ?', [mb_strtolower($intake->last_name)])
            ->whereDate('birth_date', $intake->birth_date)
            ->exists();
        $applicantMatch = ApplicantIntake::query()
            ->whereKeyNot($intake->id)
            ->where('user_id', '!=', $intake->user_id)
            ->whereRaw('LOWER(first_name) = ?', [mb_strtolower($intake->first_name)])
            ->whereRaw('LOWER(last_name) = ?', [mb_strtolower($intake->last_name)])
            ->whereDate('birth_date', $intake->birth_date)
            ->exists();

        if ($studentMatch || $applicantMatch) {
            throw ValidationException::withMessages([
                'duplicate' => 'A matching applicant or student record already exists.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function checklistAttributes(AdmissionRequirementPolicy $policy): array
    {
        return [
            'owner_type' => ChecklistItem::OwnerApplicant,
            'student_profile_id' => null,
            'source_policy_id' => $policy->id,
            'requirement_type' => $policy->requirement_type,
            'status' => ChecklistItem::StatusPending,
            'blocking_level' => $policy->blocking_level,
            'evidence_method' => $policy->evidence_method,
            'verification_status' => ChecklistItem::VerificationNotReviewed,
        ];
    }

    /**
     * @param  Collection<int, AdmissionRequirementPolicy>  $policies
     * @return array<int, string>
     */
    private function documentReferencesFor(ApplicantIntake $intake, Collection $policies): array
    {
        $references = collect($intake->getAttribute('draft_document_references') ?? [])
            ->filter(fn (mixed $path): bool => filled($path))
            ->mapWithKeys(fn (mixed $path, mixed $policyId): array => [(int) $policyId => (string) $path])
            ->all();
        $identityPolicy = $policies->firstWhere('requirement_type', 'IDENTITY_DOCUMENT');

        if ($references === [] && $identityPolicy instanceof AdmissionRequirementPolicy && filled($intake->identity_evidence_reference)) {
            $references[(int) $identityPolicy->id] = (string) $intake->identity_evidence_reference;
        }

        return $references;
    }

    /**
     * @param  Collection<int, AdmissionRequirementPolicy>  $policies
     * @param  array<int, string>  $references
     */
    private function assertRequiredDigitalEvidence(Collection $policies, array $references): void
    {
        foreach ($policies as $policy) {
            if ($policy->evidence_method !== ChecklistItem::EvidenceMethodDigitalUpload
                || in_array($policy->blocking_level, [
                    ChecklistItem::BlockingRetentionOnly,
                    ChecklistItem::BlockingAdvisoryOnly,
                ], true)
                || filled($references[(int) $policy->id] ?? null)) {
                continue;
            }

            $label = AdmissionRequirementPolicy::requirementTypeOptions()[$policy->requirement_type]
                ?? str($policy->requirement_type)->replace('_', ' ')->title()->toString();

            throw ValidationException::withMessages([
                "document_uploads.{$policy->id}" => "Upload the required {$label} before submitting.",
            ]);
        }
    }

    /** @param array<int, string> $references */
    private function assertDigitalFilesAreValid(array $references): void
    {
        $disk = Storage::disk('local');

        foreach ($references as $policyId => $path) {
            if (! $disk->exists($path)) {
                throw ValidationException::withMessages([
                    "document_uploads.{$policyId}" => 'The uploaded evidence file is unavailable. Upload it again.',
                ]);
            }

            $mimeType = $disk->mimeType($path) ?: 'application/octet-stream';

            if (! in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png'], true)
                || $disk->size($path) > 5 * 1024 * 1024) {
                throw ValidationException::withMessages([
                    "document_uploads.{$policyId}" => 'Upload a PDF, JPG, or PNG file no larger than 5 MB.',
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, AdmissionRequirementPolicy>  $policies
     * @param  array<int, string>  $references
     */
    private function assertDocumentReferencesBelongToApplicant(
        int $applicantId,
        Collection $policies,
        array $references,
    ): void {
        $policiesById = $policies->keyBy(fn (AdmissionRequirementPolicy $policy): int => (int) $policy->id);

        foreach ($references as $policyId => $path) {
            $policy = $policiesById->get((int) $policyId);
            $belongsToApplicant = $this->pathIsInside(
                $path,
                "applicant-requirement-documents/{$applicantId}/{$policyId}",
            );

            if ($policy instanceof AdmissionRequirementPolicy
                && $policy->requirement_type === 'IDENTITY_DOCUMENT') {
                $belongsToApplicant = $belongsToApplicant || $this->pathIsInside(
                    $path,
                    "applicant-identity-documents/{$applicantId}",
                );
            }

            if (! $policy instanceof AdmissionRequirementPolicy || ! $belongsToApplicant) {
                throw ValidationException::withMessages([
                    "document_uploads.{$policyId}" => 'The selected evidence path is not permitted for this applicant. Upload the file again.',
                ]);
            }
        }
    }

    private function pathIsInside(string $path, string $directory): bool
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedDirectory = trim(str_replace('\\', '/', $directory), '/').'/';

        return ! str_contains($normalizedPath, '../')
            && str_starts_with($normalizedPath, $normalizedDirectory)
            && strlen($normalizedPath) > strlen($normalizedDirectory);
    }

    private function recordDigitalEvidence(
        ChecklistItem $checklistItem,
        ApplicantIntake $intake,
        string $path,
        CarbonImmutable $timestamp,
    ): void {
        $disk = Storage::disk('local');
        $checksum = hash_file('sha256', $disk->path($path));

        if (! is_string($checksum)) {
            throw ValidationException::withMessages([
                "document_uploads.{$checklistItem->source_policy_id}" => 'The uploaded evidence could not be verified. Upload it again.',
            ]);
        }

        DocumentEvidence::query()->create([
            'checklist_item_id' => $checklistItem->id,
            'disk' => 'local',
            'path' => $path,
            'checksum' => $checksum,
            'mime_type' => $disk->mimeType($path) ?: 'application/octet-stream',
            'size_bytes' => $disk->size($path),
            'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
            'status' => DocumentEvidence::StatusSubmitted,
            'uploaded_by' => $intake->user_id,
            'uploaded_at' => $timestamp,
        ]);

        $checklistItem->forceFill([
            'status' => ChecklistItem::StatusReceivedDigital,
            'verification_status' => ChecklistItem::VerificationNotReviewed,
        ])->save();
    }

    /**
     * @param  list<string>  $previousReferences
     * @param  list<string>  $retainedReferences
     */
    private function deleteStaleDraftFiles(
        int $applicantId,
        array $previousReferences,
        array $retainedReferences,
    ): void {
        $staleReferences = array_values(array_filter(
            array_diff($previousReferences, $retainedReferences),
            fn (string $path): bool => $this->pathIsInside(
                $path,
                "applicant-requirement-documents/{$applicantId}",
            ) || $this->pathIsInside(
                $path,
                "applicant-identity-documents/{$applicantId}",
            ),
        ));

        if ($staleReferences === []) {
            return;
        }

        $protectedReferences = DocumentEvidence::query()
            ->whereIn('path', $staleReferences)
            ->pluck('path')
            ->all();

        Storage::disk('local')->delete(array_values(array_diff($staleReferences, $protectedReferences)));
    }

    private function recordActivity(ApplicantIntake $intake, CarbonImmutable $timestamp): void
    {
        DB::table('activity_log')->insert([
            'log_name' => 'applicant_intake',
            'description' => 'Applicant intake transition.',
            'subject_type' => ApplicantIntake::class,
            'subject_id' => $intake->id,
            'event' => 'applicant_intake_submitted',
            'causer_type' => User::class,
            'causer_id' => $intake->user_id,
            'properties' => json_encode([
                'status_before' => ApplicantIntake::StatusDraft,
                'status_after' => ApplicantIntake::StatusPending,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }
}
