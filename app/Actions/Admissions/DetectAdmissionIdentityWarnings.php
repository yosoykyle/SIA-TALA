<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionApplication;
use App\Models\IdentityMatchReview;
use App\Models\OfficialCredentialResult;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Builder;

class DetectAdmissionIdentityWarnings
{
    /** @return list<IdentityMatchReview> */
    public function forApplication(AdmissionApplication $application): array
    {
        $warnings = [];
        $lrnCandidateUserIds = $this->verifiedLrnCandidateUserIds($application);

        foreach ($lrnCandidateUserIds as $candidateUserId) {
            $warnings[] = $this->warning(
                $application,
                IdentityMatchReview::TypeVerifiedLrnCollision,
                $candidateUserId,
            );
        }

        foreach ($this->exactNameBirthDateCandidateUserIds($application) as $candidateUserId) {
            if (in_array($candidateUserId, $lrnCandidateUserIds, true)) {
                continue;
            }

            $warnings[] = $this->warning(
                $application,
                IdentityMatchReview::TypeExactNameBirthDate,
                $candidateUserId,
            );
        }

        return $warnings;
    }

    /** @return list<int> */
    private function verifiedLrnCandidateUserIds(AdmissionApplication $application): array
    {
        if (blank($application->lrn)) {
            return [];
        }

        return AdmissionApplication::query()
            ->canonical()
            ->whereKeyNot($application->id)
            ->where('user_id', '!=', $application->user_id)
            ->where('lrn', $application->lrn)
            ->whereHas('credentialResults', fn (Builder $query): Builder => $query
                ->where('result', OfficialCredentialResult::ResultVerified)
                ->whereDoesntHave('successor'))
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<int> */
    private function exactNameBirthDateCandidateUserIds(AdmissionApplication $application): array
    {
        if ($application->birth_date === null || blank($application->first_name) || blank($application->last_name)) {
            return [];
        }

        $firstName = mb_strtolower(trim($application->first_name));
        $lastName = mb_strtolower(trim($application->last_name));
        $birthDate = $application->birth_date->toDateString();
        $applicationUsers = AdmissionApplication::query()
            ->canonical()
            ->whereKeyNot($application->id)
            ->where('user_id', '!=', $application->user_id)
            ->whereRaw('LOWER(TRIM(first_name)) = ?', [$firstName])
            ->whereRaw('LOWER(TRIM(last_name)) = ?', [$lastName])
            ->whereDate('birth_date', $birthDate)
            ->pluck('user_id');
        $studentUsers = StudentProfile::query()
            ->whereNull('archived_at')
            ->whereNull('merged_into_id')
            ->where('user_id', '!=', $application->user_id)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('applicant_intake_id')
                ->orWhere('applicant_intake_id', '!=', $application->id))
            ->whereRaw('LOWER(TRIM(first_name)) = ?', [$firstName])
            ->whereRaw('LOWER(TRIM(last_name)) = ?', [$lastName])
            ->whereDate('birth_date', $birthDate)
            ->pluck('user_id');

        return $applicationUsers
            ->merge($studentUsers)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function warning(
        AdmissionApplication $application,
        string $matchType,
        int $candidateUserId,
    ): IdentityMatchReview {
        return $application->identityMatchReviews()->firstOrCreate(
            ['review_key' => "identity-warning:{$application->id}:{$matchType}:{$candidateUserId}"],
            [
                'match_type' => $matchType,
                'outcome' => IdentityMatchReview::OutcomePending,
                'candidate_user_id' => $candidateUserId,
                'evidence_reference' => null,
                'corrected_identifier' => null,
                'resolved_by' => null,
                'resolved_at' => null,
            ],
        );
    }
}
