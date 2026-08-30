<?php

namespace App\Actions\Enrollment;

use App\Models\AdmissionApplication;
use App\Models\Enrollment;
use App\Models\RegistrationIdentityConfirmationVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmRegistrationIdentity
{
    public function execute(Enrollment $enrollment, User $learner): RegistrationIdentityConfirmationVersion
    {
        if (! $learner->canAuthenticate() || (int) $enrollment->credential_user_id !== (int) $learner->id) {
            throw new AuthorizationException('Only the owning learner may confirm registration identity facts.');
        }

        return DB::transaction(function () use ($enrollment, $learner): RegistrationIdentityConfirmationVersion {
            $locked = Enrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $application = AdmissionApplication::query()->whereKey($locked->admission_application_id)->lockForUpdate()->first();

            if (! $application instanceof AdmissionApplication) {
                throw ValidationException::withMessages([
                    'identity' => 'First enrollment identity confirmation requires the admitted Application source.',
                ]);
            }

            $snapshot = $this->sourceSnapshot($application);
            $sourceHash = $this->sourceHash($snapshot);
            $existing = RegistrationIdentityConfirmationVersion::query()
                ->where('enrollment_id', $locked->id)
                ->where('source_hash', $sourceHash)
                ->first();

            if ($existing instanceof RegistrationIdentityConfirmationVersion) {
                return $existing;
            }

            $previous = RegistrationIdentityConfirmationVersion::query()
                ->where('enrollment_id', $locked->id)
                ->latest('version')
                ->lockForUpdate()
                ->first();

            return RegistrationIdentityConfirmationVersion::query()->create([
                'enrollment_id' => $locked->id,
                'supersedes_version_id' => $previous?->id,
                'version' => ($previous->version ?? 0) + 1,
                'admission_application_id' => $application->id,
                'source_version' => $application->updated_at?->toISOString() ?? 'source-without-timestamp',
                'source_hash' => $sourceHash,
                'identity_snapshot' => $snapshot,
                'confirmed_by' => $learner->id,
                'confirmed_at' => now(),
            ]);
        }, attempts: 3);
    }

    /** @return array<string, int|string|null> */
    public function sourceSnapshot(AdmissionApplication $application): array
    {
        return [
            'admission_application_id' => (int) $application->id,
            'first_name' => $application->first_name,
            'middle_name' => $application->middle_name,
            'last_name' => $application->last_name,
            'birth_date' => $application->birth_date?->toDateString(),
            'prior_identifier' => $application->lrn ?: $application->prior_college_identifier,
            'email' => $application->email,
            'phone' => $application->phone,
            'address' => collect([$application->current_city_municipality, $application->current_province])->filter()->implode(', '),
        ];
    }

    /** @param array<string, int|string|null> $snapshot */
    public function sourceHash(array $snapshot): string
    {
        ksort($snapshot);

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public function latestMatching(Enrollment $enrollment, AdmissionApplication $application): ?RegistrationIdentityConfirmationVersion
    {
        return RegistrationIdentityConfirmationVersion::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('source_hash', $this->sourceHash($this->sourceSnapshot($application)))
            ->latest('version')
            ->first();
    }
}
