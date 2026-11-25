<?php

namespace Tests;

use App\Models\ClassOfferingTeachingAssignment;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;

/**
 * Builds students with released results for the #99 Scope 11 irregular-student tests.
 *
 * Expects $this->registrar, $this->faculty and $this->curriculum to be set in setUp().
 */
trait BuildsIrregularStudents
{
    protected function student(): StudentProfile
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole('student');

        return StudentProfile::factory()->create([
            'user_id' => $user->id,
            'program_id' => $this->curriculum->program_id,
            'curriculum_version_id' => $this->curriculum->id,
        ]);
    }

    protected function term(\DateTimeInterface $startsOn, \DateTimeInterface $endsOn, string $type = Term::TypeFirstSemester, string $state = Term::StateClosed): Term
    {
        return Term::factory()->create([
            'type' => $type,
            'state' => $state,
            'starts_on' => $startsOn->format('Y-m-d'),
            'ends_on' => $endsOn->format('Y-m-d'),
        ]);
    }

    protected function entry(string $code, string $title): CurriculumEntry
    {
        $specification = CourseSpecification::factory()->create([
            'course_id' => Course::factory()->create(['code' => $code])->id,
            'title' => $title,
            'credit_units' => 3.00,
            'state' => CourseSpecification::StateActive,
        ]);

        return CurriculumEntry::factory()->create([
            'curriculum_version_id' => $this->curriculum->id,
            'course_specification_id' => $specification->id,
        ])->load('courseSpecification');
    }

    protected function attempt(StudentProfile $student, Term $term, CurriculumEntry $entry, string $resultCode, string $category): void
    {
        $offering = TermOffering::factory()->create([
            'term_id' => $term->id,
            'curriculum_entry_id' => $entry->id,
            'state' => TermOffering::StateScheduled,
        ]);
        $section = Section::factory()->create(['term_offering_id' => $offering->id]);
        $enrollment = Enrollment::factory()->create([
            'credential_user_id' => $student->user_id,
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'officially_enrolled_at' => now(),
        ]);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => 3.00,
            'added_at' => now(),
        ]);
        $assignment = ClassOfferingTeachingAssignment::query()->create([
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'faculty_user_id' => $this->faculty->id,
            'role' => ClassOfferingTeachingAssignment::RoleDesignated,
            'state' => ClassOfferingTeachingAssignment::StateActive,
            'authority_reference' => 'IRREG-'.$courseEnrollment->id,
            'assigned_by' => $this->registrar->id,
            'effective_at' => now(),
        ]);
        $roster = GradeRoster::factory()->create([
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'faculty_user_id' => $this->faculty->id,
            'teaching_assignment_id' => $assignment->id,
            'state' => GradeRoster::StateReleased,
            'submitted_by' => $this->faculty->id,
            'submitted_at' => now(),
            'released_by' => $this->registrar->id,
            'released_at' => now(),
        ]);
        $row = GradeRosterRow::query()->create([
            'grade_roster_id' => $roster->id,
            'course_enrollment_id' => $courseEnrollment->id,
            'current_outcome_code' => $resultCode,
            'current_outcome_category' => $category,
            'released_at' => now(),
        ]);
        GradeOutcomeEvent::query()->create([
            'grade_roster_row_id' => $row->id,
            'source_key' => 'irregular-initial:'.$row->id,
            'event_type' => GradeOutcomeEvent::TypeInitialRelease,
            'result_code' => $resultCode,
            'source_term_ends_on' => $term->ends_on,
            'new_value' => is_numeric($resultCode) ? (float) $resultCode : null,
            'new_category' => $category,
            'reason' => 'Initial release',
            'recorded_by' => $this->registrar->id,
            'released_at' => now(),
        ]);
    }

    protected function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
