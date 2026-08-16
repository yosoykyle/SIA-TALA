<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;

class AdmissionRequirementSetCompletenessService
{
    /** @return list<string> */
    public function errors(AdmissionRequirementSet $requirementSet): array
    {
        $requirementSet->loadMissing('requirements');
        $errors = [];

        if ($requirementSet->requirements->isEmpty()) {
            return ['Add at least one complete requirement before publication.'];
        }

        foreach ($requirementSet->requirements as $requirement) {
            if (! in_array($requirement->credential_classification, AdmissionRequirement::credentialClassifications(), true)) {
                $errors[] = "{$requirement->label} needs an explicit credential classification.";

                continue;
            }

            if (AdmissionRequirement::isCoreClassification($requirement->credential_classification)) {
                if ($requirement->exception_permitted) {
                    $errors[] = "{$requirement->label} is core and cannot permit an exception.";
                }

                if ($requirement->official_submission_method === AdmissionRequirement::SubmissionNone) {
                    $errors[] = "{$requirement->label} is a core official credential and needs an official submission method.";
                }
            }

            if ($requirement->credential_classification === AdmissionRequirement::ClassificationNonCore
                && $requirement->exception_permitted
                && blank($requirement->required_approving_authority)) {
                $errors[] = "{$requirement->label} permits an exception but has no required approving authority.";
            }
        }

        $requiredClassification = match ($requirementSet->application_path) {
            AdmissionCycle::PathFirstYear => AdmissionRequirement::ClassificationCoreFirstYearCompletionCredential,
            AdmissionCycle::PathTransferee => AdmissionRequirement::ClassificationCoreTransferCredential,
            default => null,
        };

        if ($requiredClassification === null) {
            $errors[] = 'The requirement set has an unsupported application path.';
        } else {
            $hasMandatoryCoreCredential = $requirementSet->requirements->contains(
                fn (AdmissionRequirement $requirement): bool => $requirement->credential_classification === $requiredClassification
                    && $requirement->due_stage === AdmissionRequirement::DueEnrollmentReadiness
                    && $requirement->official_submission_method !== AdmissionRequirement::SubmissionNone
                    && ! $requirement->exception_permitted,
            );

            if (! $hasMandatoryCoreCredential) {
                $pathLabel = $requirementSet->application_path === AdmissionCycle::PathFirstYear
                    ? 'Form 138 or equivalent'
                    : 'Transfer Credential or Certificate of Transfer';
                $errors[] = "Add the non-waivable {$pathLabel} classification due at Enrollment Readiness.";
            }
        }

        return array_values(array_unique($errors));
    }

    public function isComplete(AdmissionRequirementSet $requirementSet): bool
    {
        return $this->errors($requirementSet) === [];
    }
}
