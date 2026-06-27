<?php

namespace App\Actions\Applicants;

use App\Models\ApplicantIntake;
use App\Models\DocumentRequirementItem;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApplicantIntakeService
{
    public function __construct(
        private AdmissionRequirementResolver $requirementResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
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

        if (($validated['orientation_modality_acknowledged'] ?? false) === true) {
            $attributes['orientation_modality_acknowledged_at'] = now();
        }

        if (($validated['orientation_policy_accepted'] ?? false) === true) {
            $attributes['orientation_policy_accepted_at'] = now();
        }

        return DB::transaction(function () use ($applicant, $intake, $attributes): ApplicantIntake {
            if (! $intake instanceof ApplicantIntake) {
                $intake = new ApplicantIntake([
                    'user_id' => $applicant->id,
                    'status' => ApplicantIntake::StatusDraft,
                    'duplicate_check_status' => ApplicantIntake::DuplicateStatusClear,
                    'duplicate_check_payload' => ['matches' => []],
                    'meta' => [],
                ]);
            }

            $intake->fill($attributes);
            $intake->save();

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

        $data = [
            ...$intake->only($this->editableAttributes()),
            'orientation_modality_acknowledged' => $intake->orientation_modality_acknowledged_at !== null,
            'orientation_policy_accepted' => $intake->orientation_policy_accepted_at !== null,
        ];
        $validated = Validator::make($data, $this->submissionRules($data))->validate();
        $term = $this->resolveTerm($validated['term_id']);
        $duplicates = $this->duplicateMatches($intake, $validated);

        if ($duplicates !== []) {
            throw ValidationException::withMessages([
                'duplicate' => 'A matching applicant or student record already exists.',
            ]);
        }

        $requirements = $this->requirementResolver->resolve($validated, $term);
        $timestamp = CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($intake, $term, $requirements, $timestamp): ApplicantIntake {
            $locked = ApplicantIntake::query()->lockForUpdate()->findOrFail($intake->id);

            if ($locked->status !== ApplicantIntake::StatusDraft) {
                throw ValidationException::withMessages([
                    'status' => 'This application was already submitted.',
                ]);
            }

            $locked->forceFill([
                'term_id' => $term->id,
                'status' => ApplicantIntake::StatusPending,
                'duplicate_check_status' => ApplicantIntake::DuplicateStatusClear,
                'duplicate_check_payload' => ['matches' => []],
                'submitted_at' => $timestamp,
            ])->save();

            $locked->checklistItems()->delete();
            $this->initializeChecklistItems($locked, $requirements);
            $this->recordActivity(
                subject: $locked,
                event: 'applicant_intake_submitted',
                causer: User::query()->findOrFail($locked->user_id),
                properties: [
                    'status_before' => ApplicantIntake::StatusDraft,
                    'status_after' => ApplicantIntake::StatusPending,
                    'term_id' => $term->id,
                    'requirement_policy_id' => $requirements->policy->id,
                ],
                timestamp: $timestamp,
            );

            return $locked->refresh()->load(['checklistItems', 'program', 'term']);
        }, attempts: 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function draftRules(): array
    {
        return [
            'term_id' => ['sometimes', 'nullable', 'integer', Rule::exists((new Term)->getTable(), 'id')],
            'program_id' => ['sometimes', 'nullable', 'integer', Rule::exists((new Program)->getTable(), 'id')],
            'lrn' => ['sometimes', 'nullable', 'digits:12'],
            'birthdate' => ['sometimes', 'nullable', 'date', 'before:today'],
            'place_of_birth' => ['sometimes', 'nullable', 'string', 'max:255'],
            'gender' => ['sometimes', 'nullable', Rule::in(['male', 'female'])],
            'civil_status' => ['sometimes', 'nullable', Rule::in(['single', 'married', 'widowed', 'separated', 'annulled'])],
            'mothers_maiden_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_number' => ['sometimes', 'nullable', 'regex:/^09\d{9}$/'],
            'street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'barangay' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'province' => ['sometimes', 'nullable', 'string', 'max:255'],
            'region' => ['sometimes', 'nullable', 'string', 'max:255'],
            'zip_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'father_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'father_occupation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mother_occupation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'guardian_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'guardian_contact_number' => ['sometimes', 'nullable', 'regex:/^09\d{9}$/'],
            'guardian_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'year_level' => ['sometimes', 'nullable', 'string', 'max:80'],
            'applicant_type' => ['sometimes', 'nullable', Rule::in([
                ApplicantIntake::ApplicantTypeNew,
                ApplicantIntake::ApplicantTypeTransferee,
                ApplicantIntake::ApplicantTypeReturnee,
            ])],
            'preferred_modality' => ['sometimes', 'nullable', Rule::in(['on_site', 'blended', 'online'])],
            'last_school_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_school_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_school_year' => ['sometimes', 'nullable', 'string', 'max:80'],
            'identity_document_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'orientation_modality_acknowledged' => ['sometimes', 'boolean'],
            'orientation_policy_accepted' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function submissionRules(array $data): array
    {
        return [
            ...$this->draftRules(),
            'term_id' => ['required', 'integer', Rule::exists((new Term)->getTable(), 'id')],
            'program_id' => ['required', 'integer', Rule::exists((new Program)->getTable(), 'id')],
            'lrn' => ['required', 'digits:12'],
            'birthdate' => ['required', 'date', 'before:today'],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'civil_status' => ['required', Rule::in(['single', 'married', 'widowed', 'separated', 'annulled'])],
            'contact_number' => ['required', 'regex:/^09\d{9}$/'],
            'city' => ['required', 'string', 'max:255'],
            'province' => ['required', 'string', 'max:255'],
            'year_level' => ['required', 'string', 'max:80'],
            'applicant_type' => ['required', Rule::in([
                ApplicantIntake::ApplicantTypeNew,
                ApplicantIntake::ApplicantTypeTransferee,
                ApplicantIntake::ApplicantTypeReturnee,
            ])],
            'preferred_modality' => ['required', Rule::in(['on_site', 'blended', 'online'])],
            'last_school_name' => [
                Rule::requiredIf(($data['applicant_type'] ?? null) === ApplicantIntake::ApplicantTypeTransferee),
                'nullable',
                'string',
                'max:255',
            ],
            'last_school_address' => ['nullable', 'string', 'max:255'],
            'identity_document_url' => ['required', 'string', 'max:500'],
            'orientation_modality_acknowledged' => ['accepted'],
            'orientation_policy_accepted' => ['accepted'],
        ];
    }

    /**
     * @return list<string>
     */
    private function editableAttributes(): array
    {
        return [
            'term_id',
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
            'identity_document_url',
        ];
    }

    private function resolveTerm(mixed $termId): Term
    {
        $term = Term::query()->whereKey($termId)->where('is_active', true)->first();

        if (! $term instanceof Term) {
            throw ValidationException::withMessages([
                'term_id' => 'An active term is required before applicant intake can be submitted.',
            ]);
        }

        return $term;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function duplicateMatches(ApplicantIntake $intake, array $data): array
    {
        $matches = [];
        $lrn = (string) $data['lrn'];

        $studentProfile = StudentProfile::query()->where('lrn', $lrn)->first();

        if ($studentProfile instanceof StudentProfile) {
            $matches[] = [
                'type' => 'student_profile_lrn',
                'student_profile_id' => $studentProfile->id,
            ];
        }

        $otherApplicant = ApplicantIntake::query()
            ->whereKeyNot($intake->id)
            ->where('lrn', $lrn)
            ->first();

        if ($otherApplicant instanceof ApplicantIntake) {
            $matches[] = [
                'type' => 'applicant_intake_lrn',
                'applicant_intake_id' => $otherApplicant->id,
            ];
        }

        return $matches;
    }

    private function initializeChecklistItems(
        ApplicantIntake $intake,
        AdmissionRequirementResolution $resolution,
    ): void {
        foreach ($resolution->items as $item) {
            $intake->checklistItems()->create([
                'requirement_type' => $item->key,
                'status' => 'pending',
                'blocking_level' => $item->gate_type === DocumentRequirementItem::GateTypeAdmission
                    ? 'blocks_handover'
                    : 'retention_only',
                'evidence_method' => $this->evidenceMethod($item),
                'deadline' => null,
                'source_policy' => "Policy ID: {$resolution->policy->id}, Version: {$resolution->policy->version}",
                'notes' => $item->label,
            ]);
        }
    }

    private function evidenceMethod(DocumentRequirementItem $item): string
    {
        $methods = [];

        foreach (Arr::wrap($item->getAttribute('permitted_evidence_methods')) as $method) {
            if (is_string($method)) {
                $methods[] = $method;
            }
        }

        if (array_intersect([
            DocumentRequirementItem::EvidenceMethodPhysicalOriginal,
            DocumentRequirementItem::EvidenceMethodCertifiedCopy,
            DocumentRequirementItem::EvidenceMethodSchoolTransmission,
            'physical_copy',
        ], $methods) !== []) {
            return 'physical_copy';
        }

        if (array_intersect([
            DocumentRequirementItem::EvidenceMethodApplicantUpload,
            DocumentRequirementItem::EvidenceMethodRegistrarAssistedUpload,
            'digital_upload',
        ], $methods) !== []) {
            return 'digital_upload';
        }

        return 'metadata_only';
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
