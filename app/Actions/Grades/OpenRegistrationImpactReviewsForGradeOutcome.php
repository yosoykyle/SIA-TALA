<?php

namespace App\Actions\Grades;

use App\Actions\Enrollment\RecordRegistrationSourceImpactReview;
use App\Actions\Enrollment\RegistrationAcademicEligibilityQuery;
use App\Actions\Enrollment\RegistrationAcademicResultImpactQuery;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRosterRow;
use App\Models\RegistrationCaseEvent;
use App\Models\StudentProfile;
use App\Models\User;

class OpenRegistrationImpactReviewsForGradeOutcome
{
    public function __construct(
        private readonly RecordRegistrationSourceImpactReview $impactReviews,
        private readonly RegistrationAcademicResultImpactQuery $impactQuery,
        private readonly RegistrationAcademicEligibilityQuery $eligibility,
    ) {}

    public function execute(GradeRosterRow $row, GradeOutcomeEvent $event, User $actor): void
    {
        if ($this->isPassingOutcome($event)) {
            return;
        }

        $row->loadMissing([
            'courseEnrollment.enrollment.term',
            'courseEnrollment.enrollment.studentProfile',
            'courseEnrollment.termOffering.curriculumEntry.courseSpecification',
        ]);
        $sourceEnrollment = $row->courseEnrollment?->enrollment;
        $credentialUserId = $sourceEnrollment?->credential_user_id;
        $sourceTermStart = $sourceEnrollment?->term?->starts_on;
        $sourceCourseId = $row->courseEnrollment?->termOffering?->curriculumEntry?->courseSpecification?->course_id;

        if ($credentialUserId === null || $sourceTermStart === null || $sourceCourseId === null) {
            return;
        }

        $studentProfile = $sourceEnrollment->studentProfile;
        if ($studentProfile instanceof StudentProfile
            && $this->eligibility->satisfiedCourseIds($studentProfile)->contains((int) $sourceCourseId)) {
            return;
        }

        Enrollment::query()
            ->where('credential_user_id', $credentialUserId)
            ->whereIn('canonical_outcome', [Enrollment::OutcomeInProgress, Enrollment::OutcomeOfficiallyEnrolled])
            ->whereHas('term', fn ($query) => $query->where('ends_on', '>=', $sourceTermStart))
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

    private function isPassingOutcome(GradeOutcomeEvent $event): bool
    {
        return in_array($event->new_category, [
            GradeRosterRow::CategoryPassing,
            GradeRosterRow::CategoryTransferCredit,
        ], true)
            || RegistrationAcademicEligibilityQuery::isPassingResult($event->result_code);
    }
}
