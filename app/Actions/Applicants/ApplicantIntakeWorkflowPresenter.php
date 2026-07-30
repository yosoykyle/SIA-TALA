<?php

namespace App\Actions\Applicants;

use App\Models\ApplicantIntake;
use App\Models\ChecklistItem;
use Carbon\CarbonInterface;

class ApplicantIntakeWorkflowPresenter
{
    public function __construct(
        private readonly ApplicantDuplicateCandidateFinder $duplicateCandidateFinder,
    ) {}

    /**
     * @return array{
     *     stage:string,
     *     responsible_party:string,
     *     next_action:string,
     *     handover_blocker_count:int,
     *     requirement_count:int,
     *     resolved_requirement_count:int,
     *     outstanding_requirement_count:int,
     *     requirements_summary:string,
     *     ready_for_handover:bool,
     *     last_activity_at:?CarbonInterface
     * }
     */
    public function present(ApplicantIntake $intake): array
    {
        $intake->loadMissing('checklistItems');

        $requirementCount = $intake->checklistItems->count();
        $resolvedRequirementCount = $intake->checklistItems
            ->filter(fn (ChecklistItem $item): bool => $item->isResolved())
            ->count();
        $outstandingRequirementCount = $requirementCount - $resolvedRequirementCount;
        $handoverBlockerCount = $intake->checklistItems
            ->filter(fn (ChecklistItem $item): bool => $item->blocking_level === ChecklistItem::BlockingHandover)
            ->reject(fn (ChecklistItem $item): bool => $item->isResolved())
            ->count();
        $hasUnresolvedIdentityCandidate = $this->duplicateCandidateFinder
            ->requiresNonReturningIdentityReview($intake);

        [$stage, $responsibleParty, $nextAction] = $this->workflowState($intake, $hasUnresolvedIdentityCandidate);

        return [
            'stage' => $stage,
            'responsible_party' => $responsibleParty,
            'next_action' => $nextAction,
            'handover_blocker_count' => $handoverBlockerCount,
            'requirement_count' => $requirementCount,
            'resolved_requirement_count' => $resolvedRequirementCount,
            'outstanding_requirement_count' => $outstandingRequirementCount,
            'requirements_summary' => $this->requirementsSummary(
                $requirementCount,
                $resolvedRequirementCount,
                $outstandingRequirementCount,
                $handoverBlockerCount,
            ),
            'ready_for_handover' => $intake->status === ApplicantIntake::StatusApproved
                && $intake->handed_over_at === null
                && $handoverBlockerCount === 0
                && ! $hasUnresolvedIdentityCandidate,
            'last_activity_at' => collect([
                $intake->submitted_at,
                $intake->reviewed_at,
                $intake->approved_at,
                $intake->handed_over_at,
                $intake->archived_at,
                $intake->updated_at,
            ])->filter()->sortDesc()->first(),
        ];
    }

    /** @return array{string, string, string} */
    private function workflowState(ApplicantIntake $intake, bool $hasUnresolvedIdentityCandidate): array
    {
        if ($intake->handed_over_at !== null) {
            return [
                'Student Record Created',
                'Enrollment Team / Student',
                'Continue enrollment in Student Hub',
            ];
        }

        if ($hasUnresolvedIdentityCandidate) {
            return [
                'Identity Match Review',
                'Registrar',
                'Investigate the possible existing student record before handover',
            ];
        }

        return match ($intake->status) {
            ApplicantIntake::StatusPending => [
                'Evidence Review',
                'Registrar',
                'Review submitted requirements',
            ],
            ApplicantIntake::StatusActionRequired => [
                'Applicant Action Required',
                'Applicant',
                'Wait for the applicant to replace or complete requirements',
            ],
            ApplicantIntake::StatusForEvaluation => [
                'Registrar Evaluation',
                'Registrar',
                'Approve the application or return it for correction',
            ],
            ApplicantIntake::StatusApproved => [
                'Ready for Handover',
                'Registrar',
                'Resolve any identity match and hand over the approved applicant',
            ],
            ApplicantIntake::StatusWithdrawn => [
                'Withdrawn',
                'None',
                'No operational action required',
            ],
            default => [
                'Draft',
                'Applicant',
                'Complete and submit the application',
            ],
        };
    }

    private function requirementsSummary(
        int $requirementCount,
        int $resolvedRequirementCount,
        int $outstandingRequirementCount,
        int $handoverBlockerCount,
    ): string {
        if ($requirementCount === 0) {
            return 'No requirement checklist is recorded';
        }

        $requirementNoun = $requirementCount === 1 ? 'requirement' : 'requirements';
        $blockerSummary = $handoverBlockerCount === 0
            ? 'none blocks handover'
            : "{$handoverBlockerCount} blocks handover";

        return "{$resolvedRequirementCount} of {$requirementCount} {$requirementNoun} resolved; "
            ."{$outstandingRequirementCount} outstanding; {$blockerSummary}";
    }
}
