<?php

namespace App\Actions\Grades;

use App\Actions\Enrollment\RecordRegistrationSourceImpactReview;
use App\Actions\Enrollment\RegistrationAcademicResultImpactQuery;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRosterRow;
use App\Models\RegistrationCaseEvent;
use App\Models\User;

class OpenRegistrationImpactReviewsForGradeOutcome
{
    public function __construct(
        private readonly RecordRegistrationSourceImpactReview $impactReviews,
        private readonly RegistrationAcademicResultImpactQuery $impactQuery,
    ) {}

    public function execute(GradeRosterRow $row, GradeOutcomeEvent $event, User $actor): void
    {
        $row->loadMissing('courseEnrollment.enrollment.term');
        $sourceEnrollment = $row->courseEnrollment?->enrollment;
        $credentialUserId = $sourceEnrollment?->credential_user_id;
        $sourceTermStart = $sourceEnrollment?->term?->starts_on;

        if ($credentialUserId === null || $sourceTermStart === null) {
            return;
        }

        Enrollment::query()
            ->where('credential_user_id', $credentialUserId)
            ->whereIn('canonical_outcome', [Enrollment::OutcomeInProgress, Enrollment::OutcomeOfficiallyEnrolled])
            ->whereHas('term', fn ($query) => $query->where('starts_on', '>', $sourceTermStart))
            ->whereKeyNot($sourceEnrollment->id)
            ->get()
            ->filter(fn (Enrollment $registrationCase): bool => $this->impactQuery->affects($registrationCase, $row))
            ->each(fn (Enrollment $registrationCase): RegistrationCaseEvent => $this->impactReviews->open(
                $registrationCase,
                $actor,
                RecordRegistrationSourceImpactReview::SourceAcademicResult,
                'grade-outcome-event:'.$event->id,
                'Review the changed released academic result against the current Registration Proposal.',
            ));
    }
}
