<?php

namespace App\Actions\Finance;

use App\Actions\Enrollment\RegistrationPlacementValidator;
use App\Models\Assessment;
use App\Models\CourseDropRecord;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\RegistrationProposalVersion;
use App\Models\Term;
use Illuminate\Validation\ValidationException;

class IndividualAssessmentSources
{
    public function __construct(private readonly RegistrationPlacementValidator $placementValidator) {}

    /** @return array{initial:bool,adjustment:bool,course_drop:?CourseDropRecord,categories:list<string>} */
    public function forEnrollment(Enrollment $enrollment): array
    {
        $none = ['initial' => false, 'adjustment' => false, 'course_drop' => null, 'categories' => []];
        $case = Enrollment::query()->with(['term', 'currentProposalVersion.confirmation', 'currentCorVersion'])->find($enrollment->id);
        $proposal = $case?->currentProposalVersion;
        if ($case === null || $proposal === null || (int) $proposal->enrollment_id !== (int) $case->id) {
            return $none;
        }

        if ($case->canonical_outcome === Enrollment::OutcomeInProgress) {
            $categories = [];
            if ($case->term?->type === Term::TypeSpecialTerm) {
                $categories[] = Assessment::CategorySpecialTerm;
            }
            if ($case->selection_basis === Enrollment::SelectionIndividuallyAdvised) {
                $categories = [...$categories, Assessment::CategoryIndividuallyAdvised, Assessment::CategoryReducedEnrollment];
            }
            if ($categories === []) {
                return $none;
            }
            try {
                $this->placementValidator->assertCurrent($case, $proposal, requireConfirmation: false);
            } catch (ValidationException) {
                return $none;
            }

            return [...$none, 'initial' => true, 'categories' => $categories];
        }

        if ($case->canonical_outcome !== Enrollment::OutcomeOfficiallyEnrolled) {
            return $none;
        }

        $cor = $case->currentCorVersion;
        $proposalHasRecordedDrop = $cor !== null && (int) $cor->enrollment_id === (int) $case->id
            && (int) $cor->registration_proposal_version_id === (int) $proposal->id
            && data_get($cor->snapshot, 'change.type') === 'CourseDrop';
        $adjustment = ! $proposalHasRecordedDrop && $proposal->purpose === RegistrationProposalVersion::PurposeAdjustment
            && $proposal->state === RegistrationProposalVersion::StateConfirmed && $proposal->confirmation !== null;
        $drop = null;
        if ($proposalHasRecordedDrop
            && filled(data_get($cor->snapshot, 'program_id')) && ! empty($cor->snapshot['courses'])) {
            $candidate = CourseDropRecord::query()->with('courseEnrollment')
                ->where('enrollment_id', $case->id)->find(data_get($cor->snapshot, 'change.record_id'));
            $course = $candidate?->courseEnrollment;
            if ($candidate !== null && $candidate->recorded_at !== null
                && $course !== null && (int) $course->enrollment_id === (int) $case->id
                && ! $course->is_current && $course->status === CourseEnrollment::StatusDropped
                && ! collect($cor->snapshot['courses'])->contains(fn (array $row): bool => (int) $row['course_enrollment_id'] === (int) $course->id)
                && ! Assessment::query()->where('enrollment_id', $case->id)->where('source_snapshot->course_drop->id', $candidate->id)->exists()) {
                $drop = $candidate;
            }
        }

        return [...$none, 'adjustment' => $adjustment, 'course_drop' => $drop,
            'categories' => $adjustment || $drop !== null ? [Assessment::CategoryAuthorizedAdjustment] : []];
    }
}
