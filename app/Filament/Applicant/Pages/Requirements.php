<?php

namespace App\Filament\Applicant\Pages;

use App\Models\AdmissionApplication;
use App\Models\AdmissionRequirement;
use App\Models\DocumentEvidence;
use App\Models\OfficialCredentialResult;
use App\Models\PreliminaryEvidenceReview;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class Requirements extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Requirements';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.applicant.pages.requirements';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasRole('applicant');
    }

    public function application(): ?AdmissionApplication
    {
        return AdmissionApplication::query()
            ->canonical()
            ->with([
                'admissionCycle',
                'currentSubmissionVersion.requirementSet.requirements',
                'evidenceVersions.preliminaryReviews',
                'credentialResults.requirement',
                'correctionRequests.items.admissionRequirement',
            ])
            ->where('user_id', Auth::id())
            ->latest('updated_at')
            ->first();
    }

    /** @return array<int, array{requirement: AdmissionRequirement, evidence: ?DocumentEvidence, result: string, instruction: string, updated_at: mixed, action: string}> */
    public function preliminaryRows(AdmissionApplication $application): array
    {
        $requirements = $application->currentSubmissionVersion?->requirementSet?->requirements
            ?->where('requires_preliminary_evidence', true)
            ->sortBy('display_order') ?? collect();

        return $requirements->map(function (AdmissionRequirement $requirement) use ($application): array {
            $evidence = $application->evidenceVersions
                ->where('admission_requirement_id', $requirement->id)
                ->sortByDesc('uploaded_at')
                ->first();
            $review = $evidence instanceof DocumentEvidence
                ? $evidence->preliminaryReviews->sortByDesc('reviewed_at')->first()
                : null;
            $result = $review instanceof PreliminaryEvidenceReview
                ? $review->result
                : ($evidence instanceof DocumentEvidence ? PreliminaryEvidenceReview::ResultUnderReview : 'NotSubmitted');

            return [
                'requirement' => $requirement,
                'evidence' => $evidence,
                'result' => $result,
                'instruction' => $review instanceof PreliminaryEvidenceReview && filled($review->reason)
                    ? $review->reason
                    : $requirement->applicant_instructions,
                'updated_at' => $review instanceof PreliminaryEvidenceReview
                    ? $review->reviewed_at
                    : ($evidence instanceof DocumentEvidence ? $evidence->uploaded_at : null),
                'action' => $result === PreliminaryEvidenceReview::ResultActionNeeded
                    ? 'Open Application to replace only this evidence item.'
                    : 'No Applicant action currently available.',
            ];
        })->values()->all();
    }

    /** @return array<int, array{requirement: AdmissionRequirement, result: string, instruction: string, updated_at: mixed, action: string}> */
    public function officialRows(AdmissionApplication $application): array
    {
        $requirements = $application->currentSubmissionVersion?->requirementSet?->requirements
            ?->sortBy(fn (AdmissionRequirement $requirement): string => $requirement->due_stage.sprintf('%05d', $requirement->display_order)) ?? collect();

        return $requirements->map(function (AdmissionRequirement $requirement) use ($application): array {
            $credentialResult = $application->credentialResults
                ->where('admission_requirement_id', $requirement->id)
                ->sortByDesc('recorded_at')
                ->first();

            return [
                'requirement' => $requirement,
                'result' => $credentialResult instanceof OfficialCredentialResult
                    ? $credentialResult->result
                    : OfficialCredentialResult::ResultNotYetDue,
                'instruction' => $credentialResult instanceof OfficialCredentialResult && filled($credentialResult->safe_explanation)
                    ? $credentialResult->safe_explanation
                    : $requirement->applicant_instructions,
                'updated_at' => $credentialResult instanceof OfficialCredentialResult
                    ? $credentialResult->recorded_at
                    : null,
                'action' => $this->officialAction(
                    $requirement,
                    $credentialResult instanceof OfficialCredentialResult ? $credentialResult->result : null,
                ),
            ];
        })->values()->all();
    }

    public function resultLabel(string $result): string
    {
        return match ($result) {
            PreliminaryEvidenceReview::ResultAccepted => 'Accepted as preliminary evidence',
            default => str($result)->headline()->toString(),
        };
    }

    public function resultColor(string $result): string
    {
        return match ($result) {
            PreliminaryEvidenceReview::ResultAccepted,
            OfficialCredentialResult::ResultVerified,
            OfficialCredentialResult::ResultAuthorizedException => 'success',
            PreliminaryEvidenceReview::ResultActionNeeded,
            OfficialCredentialResult::ResultActionNeeded => 'danger',
            OfficialCredentialResult::ResultNotYetDue => 'gray',
            default => 'warning',
        };
    }

    private function officialAction(AdmissionRequirement $requirement, ?string $result): string
    {
        return match ($result) {
            OfficialCredentialResult::ResultActionNeeded => 'Follow the Registrar instruction shown here.',
            OfficialCredentialResult::ResultVerified,
            OfficialCredentialResult::ResultAuthorizedException => 'No Applicant action.',
            default => match ($requirement->official_submission_method) {
                AdmissionRequirement::SubmissionInPerson => 'Provide the official credential to the Registrar in person.',
                AdmissionRequirement::SubmissionSchoolToSchool => 'Coordinate the school-to-school process stated by the Registrar.',
                default => 'No separate official submission is required.',
            },
        };
    }
}
