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
use Illuminate\Support\Str;
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
    ): AdmissionApplication {
        $this->authorizeApplicant($applicant);
        $validated = Validator::make($data, $this->draftRules())->validate();

        return DB::transaction(function () use (
            $applicant,
            $cycle,
            $validated,
            $application,
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
                    'application_reference' => $this->reference(),
                    'application_state' => AdmissionApplication::StateDraft,
                    'term_id' => $lockedCycle->term_id,
                    'email' => $applicant->email,
                    'status' => ApplicantIntake::StatusDraft,
                ]);
            } elseif ($lockedApplication->application_state === AdmissionApplication::StateDraft) {
                $this->assertCycleOpen($lockedCycle);
            } elseif ($lockedApplication->application_state === AdmissionApplication::StateActionNeeded) {
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

            return $lockedApplication->refresh();
        }, attempts: 3);
    }

    private function authorizeApplicant(User $applicant): void
    {
        if (! $applicant->hasRole('applicant') || ! $applicant->canAuthenticate()) {
            throw new AuthorizationException('Only an active Applicant may save an admission application.');
        }
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
            'program_id' => ['sometimes', 'required', 'integer', Rule::exists((new Program)->getTable(), 'id')],
            'application_path' => ['sometimes', 'required', Rule::in([
                AdmissionApplication::PathFirstYear,
                AdmissionApplication::PathTransferee,
            ])],
            'credential_basis' => ['sometimes', 'required', 'string', 'max:64'],
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'extension_name' => ['sometimes', 'nullable', 'string', 'max:50'],
            'birth_date' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'citizenship_country_code' => ['sometimes', 'required', 'string', 'size:2'],
            'phone' => ['sometimes', 'required', 'regex:/^09\d{9}$/'],
            'current_city_municipality' => ['sometimes', 'required', 'string', 'between:1,120'],
            'current_province' => ['sometimes', 'required', 'string', 'between:1,120'],
            'prior_school_name' => ['sometimes', 'required', 'string', 'between:1,160'],
            'prior_school_country_code' => ['sometimes', 'required', 'string', 'size:2'],
            'prior_school_completion_year' => ['sometimes', 'required', 'integer', 'digits:4', 'max:'.now(config('app.timezone'))->year],
            'lrn' => ['sometimes', 'nullable', 'regex:/^\d{12}$/'],
            'prior_college_identifier' => ['sometimes', 'nullable', 'string', 'between:1,64'],
            'guardian_full_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'guardian_relationship' => ['sometimes', 'nullable', 'string', 'between:1,60'],
            'guardian_mobile' => ['sometimes', 'nullable', 'regex:/^09\d{9}$/'],
            'privacy_acknowledged' => ['sometimes', 'accepted'],
            'accuracy_declared' => ['sometimes', 'accepted'],
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

    private function reference(): string
    {
        return 'APP-'.now(config('app.timezone'))->year.'-'.Str::upper((string) Str::ulid());
    }
}
