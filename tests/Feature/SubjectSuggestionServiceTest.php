<?php

namespace Tests\Feature;

use App\Actions\Enrollment\SubjectSuggestionService;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\Grade;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\Term;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubjectSuggestionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggests_current_curriculum_subjects_when_prerequisites_are_passed(): void
    {
        [$enrollment, $studentProfile, $curriculum] = $this->enrollmentContext();
        $basicMath = Subject::factory()->create(['code' => 'MATH100', 'description' => 'Basic Math']);
        $advancedMath = Subject::factory()->create(['code' => 'MATH200', 'description' => 'Advanced Math']);
        $noPrerequisite = Subject::factory()->create(['code' => 'ENG100', 'description' => 'English Communication']);
        $alreadyPassed = Subject::factory()->create(['code' => 'GE100', 'description' => 'General Education']);

        $this->curriculumSubject($curriculum, $advancedMath, sortOrder: 10);
        $this->curriculumSubject($curriculum, $noPrerequisite, sortOrder: 20);
        $this->curriculumSubject($curriculum, $alreadyPassed, sortOrder: 30);
        $this->subjectRequires($advancedMath, $basicMath);
        $this->finalizedGrade($studentProfile, $basicMath, grade: '3.00', remarks: 'passed');
        $this->finalizedGrade($studentProfile, $alreadyPassed, grade: '2.50', remarks: 'passed');

        $result = app(SubjectSuggestionService::class)->suggestForEnrollment($enrollment);

        $this->assertSame([$advancedMath->id, $noPrerequisite->id], array_column($result['suggested'], 'subject_id'));
        $this->assertSame([$alreadyPassed->id], array_column($result['already_passed'], 'subject_id'));
        $this->assertSame([], $result['blocked']);
        $this->assertSame(2, $result['summary']['suggested_count']);
        $this->assertFalse($result['summary']['has_blockers']);
    }

    public function test_blocks_subjects_with_missing_failed_or_active_inc_prerequisites(): void
    {
        [$enrollment, $studentProfile, $curriculum] = $this->enrollmentContext();
        $missingPrerequisite = Subject::factory()->create(['code' => 'HIST100']);
        $failedPrerequisite = Subject::factory()->create(['code' => 'SCI100']);
        $incPrerequisite = Subject::factory()->create(['code' => 'IT100']);
        $targetMissing = Subject::factory()->create(['code' => 'HIST200']);
        $targetFailed = Subject::factory()->create(['code' => 'SCI200']);
        $targetInc = Subject::factory()->create(['code' => 'IT200']);

        foreach ([$targetMissing, $targetFailed, $targetInc] as $index => $subject) {
            $this->curriculumSubject($curriculum, $subject, sortOrder: ($index + 1) * 10);
        }

        $this->subjectRequires($targetMissing, $missingPrerequisite);
        $this->subjectRequires($targetFailed, $failedPrerequisite);
        $this->subjectRequires($targetInc, $incPrerequisite);
        $this->finalizedGrade($studentProfile, $failedPrerequisite, grade: '5.00', remarks: 'failed');
        $this->finalizedGrade($studentProfile, $incPrerequisite, grade: null, remarks: 'inc', isInc: true);

        $result = app(SubjectSuggestionService::class)->suggestForEnrollment($enrollment);
        $blocked = collect($result['blocked'])->keyBy('code');

        $this->assertSame('missing_history', $blocked->get('HIST200')['blockers'][0]['reason']);
        $this->assertSame('failed', $blocked->get('SCI200')['blockers'][0]['reason']);
        $this->assertSame('active_inc', $blocked->get('IT200')['blockers'][0]['reason']);
        $this->assertSame([], $result['suggested']);
        $this->assertTrue($result['summary']['has_blockers']);
    }

    public function test_latest_finalized_attempt_controls_prerequisites_and_failed_subjects_become_back_subjects(): void
    {
        [$enrollment, $studentProfile, $curriculum] = $this->enrollmentContext();
        $repeatedPrerequisite = Subject::factory()->create(['code' => 'ACC100']);
        $advancedAccounting = Subject::factory()->create(['code' => 'ACC200']);
        $failedCurrentSubject = Subject::factory()->create(['code' => 'BUS100']);

        $this->curriculumSubject($curriculum, $advancedAccounting, sortOrder: 10);
        $this->curriculumSubject($curriculum, $failedCurrentSubject, sortOrder: 20);
        $this->subjectRequires($advancedAccounting, $repeatedPrerequisite);
        $this->finalizedGrade($studentProfile, $repeatedPrerequisite, grade: '5.00', remarks: 'failed');
        $this->finalizedGrade($studentProfile, $repeatedPrerequisite, grade: '2.75', remarks: 'passed');
        $this->finalizedGrade($studentProfile, $failedCurrentSubject, grade: '5.00', remarks: 'failed');

        $result = app(SubjectSuggestionService::class)->suggestForEnrollment($enrollment);

        $this->assertSame([$advancedAccounting->id], array_column($result['suggested'], 'subject_id'));
        $this->assertSame([$failedCurrentSubject->id], array_column($result['back_subjects'], 'subject_id'));
        $this->assertSame([], $result['blocked']);
    }

    /**
     * @return array{Enrollment, StudentProfile, Curriculum}
     */
    private function enrollmentContext(): array
    {
        $term = Term::factory()->create();
        $program = Program::factory()->create(['department' => 'college']);
        $curriculum = Curriculum::factory()->create(['program_id' => $program->id]);
        $studentProfile = StudentProfile::factory()->create([
            'program_id' => $program->id,
            'year_level' => '1st Year',
        ]);
        $section = Section::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => '1st Year',
            'curriculum_period' => '1st Semester',
        ]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $term->id,
            'section_id' => $section->id,
            'status' => 'pre_enrolled',
            'student_type' => 'irregular',
            'year_level' => '1st Year',
        ]);

        return [$enrollment, $studentProfile, $curriculum];
    }

    private function curriculumSubject(Curriculum $curriculum, Subject $subject, int $sortOrder): CurriculumSubject
    {
        return CurriculumSubject::factory()->create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'year_level' => '1st Year',
            'semester' => '1st Semester',
            'sort_order' => $sortOrder,
        ]);
    }

    private function subjectRequires(Subject $subject, Subject $prerequisite): void
    {
        DB::table('prerequisites')->insert([
            'subject_id' => $subject->id,
            'prerequisite_id' => $prerequisite->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function finalizedGrade(
        StudentProfile $studentProfile,
        Subject $subject,
        ?string $grade,
        string $remarks,
        bool $isInc = false,
    ): Grade {
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $term->id,
            'status' => 'completed',
        ]);
        $enrollmentSubject = EnrollmentSubject::query()->create([
            'enrollment_id' => $enrollment->id,
            'subject_id' => $subject->id,
            'units' => (string) $subject->units,
            'lec_hours' => (string) $subject->lec_hours,
            'status' => 'enrolled',
            'is_dropped' => false,
        ]);

        return Grade::query()->create([
            'enrollment_id' => $enrollment->id,
            'enrollment_subject_id' => $enrollmentSubject->id,
            'subject_id' => $subject->id,
            'term_id' => $term->id,
            'prelim_grade' => $isInc ? null : '75.00',
            'midterm_grade' => $isInc ? null : '75.00',
            'final_grade' => $isInc ? null : '75.00',
            'grade' => $grade,
            'remarks' => $remarks,
            'is_inc' => $isInc,
            'inc_expires_at' => $isInc ? now()->addMonths(6) : null,
            'is_finalized' => true,
            'finalized_at' => now(),
        ]);
    }
}
