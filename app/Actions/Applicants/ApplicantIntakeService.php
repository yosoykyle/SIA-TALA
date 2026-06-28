<?php

namespace App\Actions\Applicants;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApplicantIntakeService
{
    public function __construct(
        private AdmissionRequirementResolver $requirementResolver,
    ) {}

    /** @param array<string, mixed> $data */
    public function saveDraft(User $applicant, array $data): ApplicantIntake
    {
        if (! $applicant->hasRole('applicant')) {
            throw ValidationException::withMessages([
                'applicant' => 'Only applicant accounts can own an applicant intake.',
            ]);
        }

        $validated = Validator::make($data, $this->draftRules())->validate();
        $intake = $applicant->applicantIntake()->first();

        if ($intake instanceof ApplicantIntake && $intake->status !== ApplicantIntake::StatusDraft) {
            throw ValidationException::withMessages([
                'status' => 'A submitted application can no longer be edited as a draft.',
            ]);
        }

        $attributes = Arr::only($validated, $this->editableAttributes());
        $attributes += [
            'first_name' => $applicant->first_name ?? $applicant->name,
            'middle_name' => $applicant->middle_name,
            'last_name' => $applicant->last_name ?? $applicant->name,
            'email' => $applicant->email,
        ];

        return DB::transaction(function () use ($applicant, $intake, $attributes): ApplicantIntake {
            $intake ??= new ApplicantIntake([
                'user_id' => $applicant->id,
                'status' => ApplicantIntake::StatusDraft,
            ]);
            $intake->fill($attributes)->save();

            return $intake->refresh();
        }, attempts: 3);
    }

    public function submit(ApplicantIntake $intake): ApplicantIntake
    {
        if ($intake->status !== ApplicantIntake::StatusDraft) {
            throw ValidationException::withMessages([
                'status' => 'Only draft applications can be submitted.',
            ]);
        }

        Validator::make($intake->only($this->editableAttributes()), $this->submissionRules())->validate();
        $this->assertNoUnresolvedDuplicate($intake);
        $policies = $this->requirementResolver->resolve($intake);
        $timestamp = CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($intake, $policies, $timestamp): ApplicantIntake {
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
                $locked->checklistItems()->create($this->checklistAttributes($policy));
            }

            $this->recordIdentityEvidence($locked, $timestamp);
            $this->recordActivity($locked, $timestamp);

            return $locked->refresh()->load(['checklistItems.documentEvidence', 'program', 'term']);
        }, attempts: 3);
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
            'first_name' => ['sometimes', 'string', 'max:255'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before:today'],
            'email' => ['sometimes', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'regex:/^09\d{9}$/'],
            'prior_school' => ['sometimes', 'nullable', 'string', 'max:255'],
            'identity_evidence_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
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
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'regex:/^09\d{9}$/'],
            'prior_school' => ['required', 'string', 'max:255'],
            'identity_evidence_reference' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return list<string> */
    private function editableAttributes(): array
    {
        return [
            'term_id', 'program_id', 'admission_category', 'credential_basis',
            'first_name', 'middle_name', 'last_name', 'birth_date', 'email',
            'phone', 'prior_school', 'identity_evidence_reference',
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

    private function recordIdentityEvidence(ApplicantIntake $intake, CarbonImmutable $timestamp): void
    {
        $path = (string) $intake->identity_evidence_reference;
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            throw ValidationException::withMessages([
                'identity_evidence_reference' => 'The identity evidence file is unavailable.',
            ]);
        }

        $checklistItem = $intake->checklistItems()
            ->where('evidence_method', 'DIGITAL_UPLOAD')
            ->orderByRaw("CASE WHEN requirement_type = 'IDENTITY_DOCUMENT' THEN 0 ELSE 1 END")
            ->first();

        if (! $checklistItem instanceof ChecklistItem) {
            throw ValidationException::withMessages([
                'identity_evidence_reference' => 'An effective digital-upload requirement is required.',
            ]);
        }

        DocumentEvidence::query()->create([
            'checklist_item_id' => $checklistItem->id,
            'disk' => 'local',
            'path' => $path,
            'checksum' => hash_file('sha256', $disk->path($path)),
            'mime_type' => $disk->mimeType($path) ?: 'application/octet-stream',
            'size_bytes' => $disk->size($path),
            'evidence_method' => 'DIGITAL_UPLOAD',
            'status' => 'SUBMITTED',
            'uploaded_by' => $intake->user_id,
            'uploaded_at' => $timestamp,
        ]);
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
