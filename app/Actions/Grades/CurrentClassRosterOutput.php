<?php

namespace App\Actions\Grades;

use App\Models\ClassOfferingTeachingAssignment;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CurrentClassRosterOutput
{
    public const CsvHeaders = ['Term', 'Class Reference', 'Course Code', 'Course Title', 'Program or Cohort', 'Student Number', 'Legal Name', 'As of (Asia/Manila)'];

    /** @return array{roster:GradeRoster,generatedAt:Carbon,headers:list<string>,rows:list<list<?string>>,count:int} */
    public function forRoster(GradeRoster $roster, User $actor): array
    {
        abort_unless($actor->canAuthenticate() && $actor->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleFaculty]), 403);
        $snapshot = $this->membershipSnapshot($roster, $actor);
        abort_unless($actor->hasRole(User::StaffRoleRegistrar)
            || ClassOfferingTeachingAssignment::query()->where('section_id', $snapshot['roster']->section_id)
                ->where('faculty_user_id', $actor->id)->where('state', ClassOfferingTeachingAssignment::StateActive)->exists(), 403);
        abort_if($snapshot['officialCount'] === 0, 409, 'No officially enrolled students are currently assigned to this class.');
        abort_unless($snapshot['isCurrent'], 409, $snapshot['error']);
        $roster = $snapshot['roster'];

        $generatedAt = now('Asia/Manila');
        $rows = $roster->rows->map(function (GradeRosterRow $row) use ($roster, $generatedAt): array {
            $student = $row->courseEnrollment?->enrollment?->studentProfile;
            $givenNames = collect([$student?->first_name, $student?->middle_name])->filter()->implode(' ');

            return [
                $roster->termOffering?->term?->label, $roster->section?->code,
                $roster->termOffering?->curriculumEntry?->courseSpecification?->course?->code,
                $roster->termOffering?->curriculumEntry?->courseSpecification?->title,
                $student?->program?->code, $student?->student_number,
                collect([$student?->last_name, $givenNames])->filter()->implode(', '),
                $generatedAt->format('Y-m-d H:i:s \\P\\H\\T'),
            ];
        })->all();

        return ['roster' => $roster, 'generatedAt' => $generatedAt, 'headers' => self::CsvHeaders, 'rows' => $rows, 'count' => count($rows)];
    }

    /** @return array{roster:GradeRoster,count:int,officialCount:int,isCurrent:bool,error:?string} */
    public function membershipSnapshot(GradeRoster $roster, User $actor): array
    {
        abort_unless($actor->canAuthenticate() && $actor->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAcademicHead, User::StaffRoleFaculty]), 403);
        $roster = GradeRoster::query()->findOrFail($roster->id);
        abort_unless($actor->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAcademicHead])
            || ClassOfferingTeachingAssignment::query()->where('section_id', $roster->section_id)
                ->where('faculty_user_id', $actor->id)->where('state', ClassOfferingTeachingAssignment::StateActive)->exists(), 403);

        $officialIds = CourseEnrollment::query()->where('term_offering_id', $roster->term_offering_id)
            ->where('status', CourseEnrollment::StatusActive)->where('is_current', true)
            ->whereHas('enrollment', fn (Builder $query): Builder => $query->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled))
            ->where(function (Builder $query) use ($roster): void {
                $query->where('section_id', $roster->section_id)
                    ->orWhereHas('seatReservations', fn (Builder $reservations): Builder => $reservations->where('section_id', $roster->section_id)->where('status', 'ACTIVE'));
            })->orderBy('id')->pluck('id');
        $rosterIds = $roster->rows()->where('is_current_membership', true)->orderBy('course_enrollment_id')->pluck('course_enrollment_id');
        $isCurrent = $officialIds->all() === $rosterIds->all();

        $roster->load(['rows' => fn (HasMany $query): HasMany => $query->where('is_current_membership', true)
            ->with('courseEnrollment.enrollment.studentProfile.program'), 'section', 'faculty', 'termOffering.term',
            'termOffering.curriculumEntry.courseSpecification.course']);
        if (! $isCurrent) {
            $roster->setRelation('rows', new Collection);

            return ['roster' => $roster, 'count' => 0, 'officialCount' => $officialIds->count(), 'isCurrent' => false,
                'error' => 'The class roster changed. Refresh the roster before generating an output.'];
        }
        $roster->setRelation('rows', $roster->rows->sortBy([
            fn (GradeRosterRow $left, GradeRosterRow $right): int => strcasecmp((string) $left->courseEnrollment?->enrollment?->studentProfile?->last_name, (string) $right->courseEnrollment?->enrollment?->studentProfile?->last_name),
            fn (GradeRosterRow $left, GradeRosterRow $right): int => strcasecmp((string) $left->courseEnrollment?->enrollment?->studentProfile?->first_name, (string) $right->courseEnrollment?->enrollment?->studentProfile?->first_name),
            fn (GradeRosterRow $left, GradeRosterRow $right): int => strcasecmp((string) $left->courseEnrollment?->enrollment?->studentProfile?->middle_name, (string) $right->courseEnrollment?->enrollment?->studentProfile?->middle_name),
            fn (GradeRosterRow $left, GradeRosterRow $right): int => strnatcasecmp((string) $left->courseEnrollment?->enrollment?->studentProfile?->student_number, (string) $right->courseEnrollment?->enrollment?->studentProfile?->student_number),
        ])->values());

        return ['roster' => $roster, 'count' => $roster->rows->count(), 'officialCount' => $officialIds->count(), 'isCurrent' => true, 'error' => null];
    }

    /** @return array<string, mixed> */
    public function preview(GradeRoster $roster, User $actor): array
    {
        try {
            $snapshot = $this->forRoster($roster, $actor);

            return [...$snapshot, 'rows' => array_slice($snapshot['rows'], 0, 5), 'error' => null];
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            return [
                'roster' => $roster->loadMissing(['section', 'termOffering.term', 'termOffering.curriculumEntry.courseSpecification']),
                'headers' => self::CsvHeaders, 'rows' => [], 'count' => 0, 'generatedAt' => now('Asia/Manila'),
                'error' => $exception->getMessage(),
            ];
        }
    }
}
