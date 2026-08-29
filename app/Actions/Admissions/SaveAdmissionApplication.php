<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\ApplicantIntake;
use App\Models\ApplicationCorrectionItem;
use App\Models\ApplicationCorrectionRequest;
use App\Models\Program;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveAdmissionApplication
{
    /** @param array<string, mixed> $data */
    public function execute(
        User $applicant,
        AdmissionCycle $cycle,
        array $data,
        ?AdmissionApplication $application = null,
        ?User $assistedBy = null,
        ?string $assistanceReason = null,
        ?string $assistanceAuthorityReference = null,
        ?string $assistanceEvidenceReference = null,
    ): AdmissionApplication {
        $assistance = $this->authorizeEntry(
            $applicant,
            $assistedBy,
            $assistanceReason,
            $assistanceAuthorityReference,
            $assistanceEvidenceReference,
        );
        $validated = Validator::make($data, $this->draftRules())->validate();

        return DB::transaction(function () use (
            $applicant,
            $cycle,
            $validated,
            $application,
            $assistedBy,
            $assistance,
        ): AdmissionApplication {
            $lockedCycle = AdmissionCycle::query()->lockForUpdate()->findOrFail($cycle->id);
            $lockedApplication = $application instanceof AdmissionApplication
                ? AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id)
                : AdmissionApplication::query()
                    ->canonical()
                    ->where('user_id', $applicant->id)
                    ->where('admission_cycle_id', $lockedCycle->id)
                    ->lockForUpdate()
                    ->first();

            if ($lockedApplication instanceof AdmissionApplication
                && ($lockedApplication->user_id !== $applicant->id
                    || $lockedApplication->admission_cycle_id !== $lockedCycle->id)) {
                throw new AuthorizationException('Applicants may edit only their own application.');
            }

            if (! $lockedApplication instanceof AdmissionApplication) {
                $this->assertCycleOpen($lockedCycle);
                $lockedApplication = new AdmissionApplication([
                    'user_id' => $applicant->id,
                    'admission_cycle_id' => $lockedCycle->id,
                    'application_state' => AdmissionApplication::StateDraft,
                    'term_id' => $lockedCycle->term_id,
                    'email' => $applicant->email,
                    'status' => ApplicantIntake::StatusDraft,
                ]);
            } elseif ($lockedApplication->application_state === AdmissionApplication::StateDraft) {
                $this->assertCycleOpen($lockedCycle);
            } elseif ($lockedApplication->application_state === AdmissionApplication::StateActionNeeded) {
                if ($assistedBy instanceof User) {
                    throw ValidationException::withMessages([
                        'application_state' => 'Registrar-assisted entry may prepare an unsubmitted Draft only. The Applicant must complete any scoped correction.',
                    ]);
                }

                $this->assertCorrectionScope($lockedApplication, array_keys($validated));
            } else {
                throw ValidationException::withMessages([
                    'application_state' => 'Submitted application facts are read-only unless a Registrar correction names them.',
                ]);
            }

            $candidate = [...$lockedApplication->getAttributes(), ...$validated];
            $this->assertApplicationScope($lockedCycle, $candidate);
            $attributes = Arr::only($validated, $this->editableAttributes());

            if (($validated['privacy_acknowledged'] ?? false) === true) {
                $attributes['privacy_notice_reference'] = $lockedCycle->privacy_notice_reference;
                $attributes['privacy_acknowledged_at'] = now(config('app.timezone'));
            }

            if (($validated['accuracy_declared'] ?? false) === true) {
                $attributes['accuracy_declared_at'] = now(config('app.timezone'));
            }

            $lockedApplication->fill($attributes);
            $canonical = [
                'admission_cycle_id' => $lockedCycle->id,
                'term_id' => $lockedCycle->term_id,
                'email' => $applicant->email,
            ];

            if ($lockedApplication->application_path === AdmissionApplication::PathTransferee) {
                $canonical['admission_category'] = ApplicantIntake::AdmissionCategoryTransfer;
            } elseif ($lockedApplication->application_path === AdmissionApplication::PathFirstYear) {
                $canonical['admission_category'] = ApplicantIntake::AdmissionCategoryFirstTimeCollege;
            }

            $lockedApplication->forceFill($canonical)->save();

            if ($assistedBy instanceof User) {
                activity()
                    ->performedOn($lockedApplication)
                    ->causedBy($assistedBy)
                    ->event('admission_assisted_draft_saved')
                    ->withProperties([
                        'applicant_user_id' => $applicant->id,
                        'authority_reference' => $assistance['authority_reference'],
                        'evidence_reference' => $assistance['evidence_reference'],
                        'reason' => $assistance['reason'],
                    ])
                    ->log('Registrar-assisted Application Draft saved for the Applicant owner.');
            }

            return $lockedApplication->refresh();
        }, attempts: 3);
    }

    /** @return array{reason: string|null, authority_reference: string|null, evidence_reference: string|null} */
    private function authorizeEntry(
        User $applicant,
        ?User $assistedBy,
        ?string $assistanceReason,
        ?string $assistanceAuthorityReference,
        ?string $assistanceEvidenceReference,
    ): array {
        if (! $applicant->hasRole('applicant')
            || ! $applicant->canAuthenticate()
            || ! $applicant->hasVerifiedEmail()) {
            throw new AuthorizationException('Only an active Applicant may save an admission application.');
        }

        if (! $assistedBy instanceof User) {
            return [
                'reason' => null,
                'authority_reference' => null,
                'evidence_reference' => null,
            ];
        }

        if (! $assistedBy->hasRole(User::StaffRoleRegistrar)
            || ! $assistedBy->canAuthenticate()
            || ! $assistedBy->can('approve-documents')) {
            throw new AuthorizationException('Only an active Registrar with application-review authority may prepare an assisted Draft.');
        }

        return Validator::make([
            'reason' => trim((string) $assistanceReason),
            'authority_reference' => trim((string) $assistanceAuthorityReference),
            'evidence_reference' => trim((string) $assistanceEvidenceReference),
        ], [
            'reason' => ['required', 'string', 'max:1000'],
            'authority_reference' => ['required', 'string', 'max:255'],
            'evidence_reference' => ['required', 'string', 'max:255'],
        ])->validate();
    }

    private function assertCycleOpen(AdmissionCycle $cycle): void
    {
        $now = CarbonImmutable::now(config('app.timezone'));

        if ($cycle->state !== AdmissionCycle::StatePublished
            || $cycle->opens_at === null
            || $cycle->closes_at === null
            || $now->lessThan($cycle->opens_at)
            || ! $now->lessThan($cycle->closes_at)) {
            throw ValidationException::withMessages([
                'admission_cycle_id' => 'This Admission Cycle is not currently accepting draft changes or first submissions.',
            ]);
        }
    }

    /** @param list<string> $keys */
    private function assertCorrectionScope(AdmissionApplication $application, array $keys): void
    {
        $activeRequest = $application->correctionRequests()
            ->where('state', ApplicationCorrectionRequest::StateActive)
            ->with('items')
            ->lockForUpdate()
            ->first();
        $allowedFields = $activeRequest?->items
            ->where('scope_type', ApplicationCorrectionItem::ScopeField)
            ->pluck('scope_key')
            ->all() ?? [];
        $submissionDeclarations = ['privacy_acknowledged', 'accuracy_declared'];
        $unscoped = array_values(array_diff($keys, $allowedFields, $submissionDeclarations));

        if (! $activeRequest instanceof ApplicationCorrectionRequest || $unscoped !== []) {
            throw ValidationException::withMessages([
                'correction_scope' => 'Only fields named by the active Registrar correction request may be edited.',
            ]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function assertApplicationScope(AdmissionCycle $cycle, array $attributes): void
    {
        if (filled($attributes['citizenship_country_code'] ?? null)
            && $attributes['citizenship_country_code'] !== 'PH') {
            throw ValidationException::withMessages([
                'citizenship_country_code' => 'This Applicant path currently supports Philippine citizenship records only. Contact the Registrar at '.config('institution.public.support_phone').' for the authorized process; no unsupported change was saved.',
            ]);
        }

        if (filled($attributes['prior_school_country_code'] ?? null)
            && $attributes['prior_school_country_code'] !== 'PH') {
            throw ValidationException::withMessages([
                'prior_school_country_code' => 'This Applicant path currently supports Philippine prior-school records only. Contact the Registrar at '.config('institution.public.support_phone').' for the authorized process; no unsupported change was saved.',
            ]);
        }

        $path = $attributes['application_path'] ?? null;
        $programId = $attributes['program_id'] ?? null;

        if (blank($path) || blank($programId)) {
            return;
        }

        $pivotFlag = $path === AdmissionApplication::PathTransferee
            ? 'accepts_transferee'
            : 'accepts_first_year';
        $accepts = $cycle->programs()
            ->whereKey($programId)
            ->where('is_active', true)
            ->wherePivot($pivotFlag, true)
            ->exists();

        if (! $accepts) {
            throw ValidationException::withMessages([
                'program_id' => 'Select an active Program accepting this application path.',
            ]);
        }

        $allowedCredentialBases = $path === AdmissionApplication::PathTransferee
            ? [AdmissionApplication::CredentialTransfer]
            : [
                AdmissionApplication::CredentialSeniorHighSchool,
                AdmissionApplication::CredentialAlsAe,
                AdmissionApplication::CredentialPept,
            ];

        if (filled($attributes['credential_basis'] ?? null)
            && ! in_array($attributes['credential_basis'], $allowedCredentialBases, true)) {
            throw ValidationException::withMessages([
                'credential_basis' => 'Select a credential basis published for this application path.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function draftRules(): array
    {
        return [
            'program_id' => ['sometimes', 'nullable', 'integer', Rule::exists((new Program)->getTable(), 'id')],
            'application_path' => ['sometimes', 'nullable', Rule::in([
                AdmissionApplication::PathFirstYear,
                AdmissionApplication::PathTransferee,
            ])],
            'credential_basis' => ['sometimes', 'nullable', 'string', 'max:64'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'extension_name' => ['sometimes', 'nullable', 'string', 'max:50'],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'citizenship_country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'phone' => ['sometimes', 'nullable', 'regex:/^09\d{9}$/'],
            'current_city_municipality' => ['sometimes', 'nullable', 'string', 'between:1,120'],
            'current_province' => ['sometimes', 'nullable', 'string', 'between:1,120'],
            'prior_school_name' => ['sometimes', 'nullable', 'string', 'between:1,160'],
            'prior_school_country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'prior_school_completion_year' => ['sometimes', 'nullable', 'integer', 'digits:4', 'max:'.now(config('app.timezone'))->year],
            'lrn' => ['sometimes', 'nullable', 'regex:/^\d{12}$/'],
            'prior_college_identifier' => ['sometimes', 'nullable', 'string', 'between:1,64'],
            'guardian_full_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'guardian_relationship' => ['sometimes', 'nullable', 'string', 'between:1,60'],
            'guardian_mobile' => ['sometimes', 'nullable', 'regex:/^09\d{9}$/'],
            'privacy_acknowledged' => ['sometimes', 'boolean'],
            'accuracy_declared' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<string> */
    private function editableAttributes(): array
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
        ];
    }
}
