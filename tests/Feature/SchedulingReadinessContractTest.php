<?php

namespace Tests\Feature;

use App\Actions\Scheduling\TermSchedulingReadinessService;
use App\Models\Curriculum;
use App\Models\CurriculumReadinessScope;
use App\Models\CurriculumSubject;
use App\Models\DeliveryPattern;
use App\Models\FacultyAvailabilityPeriod;
use App\Models\FacultyAvailabilitySubmission;
use App\Models\FacultyAvailabilityWindow;
use App\Models\FacultySubjectEligibility;
use App\Models\Program;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulingReadinessContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_term_is_ready_when_sections_have_explicit_curriculum_scope_and_minimal_room_input(): void
    {
        [$term, $program, $curriculum] = $this->curriculumFixtures();

        $subject = Subject::factory()->create();
        CurriculumSubject::factory()->create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'year_level' => '1st Year',
            'semester' => '1st Semester',
        ]);
        $this->readyScope($curriculum);
        $this->createSchedulableFaculty($term, $subject);

        $section = Section::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => '1st Year',
            'curriculum_period' => '1st Semester',
            'room' => 'R-101',
            'modality' => 'on_site',
        ]);
        $this->deliveryGroup($section);

        $readiness = app(TermSchedulingReadinessService::class)->evaluateTerm($term);

        $this->assertTrue($readiness['is_ready']);
        $this->assertSame([], $readiness['missing_term_fields']);
        $this->assertSame([], $readiness['section_issues']);
        $this->assertSame([], $readiness['delivery_group_issues']);
        $this->assertSame([], $readiness['faculty_input_issues']);
        $this->assertSame('section_delivery_groups.room fixed-room catalog; sections.room legacy compatibility', $readiness['room_catalog_mode']);
    }

    public function test_term_is_not_ready_when_section_solver_scope_is_missing(): void
    {
        [$term, $program] = $this->curriculumFixtures();

        $section = Section::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_id' => null,
            'year_level' => null,
            'curriculum_period' => null,
            'room' => null,
            'modality' => 'on_site',
        ]);

        $readiness = app(TermSchedulingReadinessService::class)->evaluateTerm($term);

        $this->assertFalse($readiness['is_ready']);
        $this->assertSame($section->id, $readiness['section_issues'][0]['section_id']);
        $this->assertSame([
            'curriculum_id',
            'year_level',
            'curriculum_period',
        ], $readiness['section_issues'][0]['missing_fields']);
        $this->assertFalse($readiness['section_issues'][0]['has_curriculum_demand']);
    }

    public function test_term_is_not_ready_without_sections(): void
    {
        $term = Term::factory()->create();

        $readiness = app(TermSchedulingReadinessService::class)->evaluateTerm($term);

        $this->assertFalse($readiness['is_ready']);
        $this->assertSame(['sections'], $readiness['section_issues'][0]['missing_fields']);
        $this->assertFalse($readiness['section_issues'][0]['has_curriculum_demand']);
    }

    public function test_term_is_not_ready_when_curriculum_scope_has_no_subject_demand(): void
    {
        [$term, $program, $curriculum] = $this->curriculumFixtures();

        Section::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => '1st Year',
            'curriculum_period' => '2nd Semester',
            'room' => 'R-101',
            'modality' => 'on_site',
        ]);

        $readiness = app(TermSchedulingReadinessService::class)->evaluateTerm($term);

        $this->assertFalse($readiness['is_ready']);
        $this->assertSame([], $readiness['section_issues'][0]['missing_fields']);
        $this->assertFalse($readiness['section_issues'][0]['has_curriculum_demand']);
    }

    public function test_term_is_not_ready_when_section_has_no_delivery_group(): void
    {
        [$term, $program, $curriculum] = $this->curriculumFixtures();

        $subject = Subject::factory()->create();
        CurriculumSubject::factory()->create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'year_level' => '1st Year',
            'semester' => '1st Semester',
        ]);
        $this->readyScope($curriculum);

        $section = Section::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => '1st Year',
            'curriculum_period' => '1st Semester',
            'room' => null,
            'modality' => 'on_site',
        ]);

        $readiness = app(TermSchedulingReadinessService::class)->evaluateTerm($term);

        $this->assertFalse($readiness['is_ready']);
        $this->assertSame([], $readiness['section_issues']);
        $this->assertSame($section->id, $readiness['delivery_group_issues'][0]['section_id']);
        $this->assertSame(['section_delivery_groups'], $readiness['delivery_group_issues'][0]['missing_fields']);
        $this->assertSame([], $readiness['faculty_input_issues']);
    }

    public function test_term_is_not_ready_when_section_capacity_violates_rescue_contract(): void
    {
        [$term, $program, $curriculum] = $this->curriculumFixtures();

        $subject = Subject::factory()->create();
        CurriculumSubject::factory()->create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'year_level' => '1st Year',
            'semester' => '1st Semester',
        ]);

        Section::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => '1st Year',
            'curriculum_period' => '1st Semester',
            'room' => 'R-101',
            'modality' => 'on_site',
            'max_seats' => 31,
            'enrolled_count' => 0,
        ]);
        Section::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => '1st Year',
            'curriculum_period' => '1st Semester',
            'room' => 'R-102',
            'modality' => 'on_site',
            'max_seats' => 10,
            'enrolled_count' => 12,
        ]);

        $readiness = app(TermSchedulingReadinessService::class)->evaluateTerm($term);

        $this->assertFalse($readiness['is_ready']);
        $this->assertSame('max_seats_capacity_contract', $readiness['section_issues'][0]['missing_fields'][0]);
        $this->assertSame('max_seats_below_enrolled_count', $readiness['section_issues'][1]['missing_fields'][0]);
    }

    public function test_term_is_not_ready_when_section_curriculum_does_not_belong_to_program(): void
    {
        [$term, $program] = $this->curriculumFixtures();
        $otherProgram = Program::factory()->create();
        $otherCurriculum = Curriculum::factory()->create([
            'program_id' => $otherProgram->id,
        ]);

        $subject = Subject::factory()->create();
        CurriculumSubject::factory()->create([
            'curriculum_id' => $otherCurriculum->id,
            'subject_id' => $subject->id,
            'year_level' => '1st Year',
            'semester' => '1st Semester',
        ]);

        Section::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_id' => $otherCurriculum->id,
            'year_level' => '1st Year',
            'curriculum_period' => '1st Semester',
            'room' => 'R-101',
            'modality' => 'on_site',
        ]);

        $readiness = app(TermSchedulingReadinessService::class)->evaluateTerm($term);

        $this->assertFalse($readiness['is_ready']);
        $this->assertContains('curriculum_program_mismatch', $readiness['section_issues'][0]['missing_fields']);
        $this->assertTrue($readiness['section_issues'][0]['has_curriculum_demand']);
    }

    public function test_term_is_not_ready_when_section_subject_demand_has_no_schedulable_faculty(): void
    {
        [$term, $program, $curriculum] = $this->curriculumFixtures();

        $subject = Subject::factory()->create(['code' => 'IT101']);
        CurriculumSubject::factory()->create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'year_level' => '1st Year',
            'semester' => '1st Semester',
        ]);
        $this->readyScope($curriculum);

        $section = Section::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => '1st Year',
            'curriculum_period' => '1st Semester',
            'room' => 'R-101',
            'modality' => 'on_site',
        ]);
        $group = $this->deliveryGroup($section);

        $readiness = app(TermSchedulingReadinessService::class)->evaluateTerm($term);

        $this->assertFalse($readiness['is_ready']);
        $this->assertSame([], $readiness['section_issues']);
        $this->assertSame([], $readiness['delivery_group_issues']);
        $this->assertSame($section->id, $readiness['faculty_input_issues'][0]['section_id']);
        $this->assertSame($group->id, $readiness['faculty_input_issues'][0]['section_delivery_group_id']);
        $this->assertSame($group->name, $readiness['faculty_input_issues'][0]['delivery_group_name']);
        $this->assertSame($subject->id, $readiness['faculty_input_issues'][0]['subject_id']);
        $this->assertSame('IT101', $readiness['faculty_input_issues'][0]['subject_code']);
        $this->assertSame([
            'active_faculty_subject_eligibility',
            'submitted_or_locked_faculty_availability',
        ], $readiness['faculty_input_issues'][0]['missing_inputs']);
        $this->assertSame(0, $readiness['faculty_input_issues'][0]['eligible_faculty_count']);
        $this->assertSame(0, $readiness['faculty_input_issues'][0]['schedulable_faculty_count']);
    }

    public function test_term_is_not_ready_when_eligible_faculty_has_no_submitted_or_locked_availability(): void
    {
        [$term, $program, $curriculum] = $this->curriculumFixtures();

        $subject = Subject::factory()->create(['code' => 'IT101']);
        $faculty = User::factory()->create();
        CurriculumSubject::factory()->create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'year_level' => '1st Year',
            'semester' => '1st Semester',
        ]);
        $this->readyScope($curriculum);
        FacultySubjectEligibility::factory()->create([
            'faculty_id' => $faculty->id,
            'subject_id' => $subject->id,
            'term_id' => $term->id,
        ]);

        $section = Section::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => '1st Year',
            'curriculum_period' => '1st Semester',
            'room' => 'R-101',
            'modality' => 'on_site',
        ]);
        $group = $this->deliveryGroup($section);

        $readiness = app(TermSchedulingReadinessService::class)->evaluateTerm($term);

        $this->assertFalse($readiness['is_ready']);
        $this->assertSame([], $readiness['delivery_group_issues']);
        $this->assertSame($group->id, $readiness['faculty_input_issues'][0]['section_delivery_group_id']);
        $this->assertSame(['submitted_or_locked_faculty_availability'], $readiness['faculty_input_issues'][0]['missing_inputs']);
        $this->assertSame(1, $readiness['faculty_input_issues'][0]['eligible_faculty_count']);
        $this->assertSame(0, $readiness['faculty_input_issues'][0]['schedulable_faculty_count']);
    }

    /**
     * @return array{Term, Program, Curriculum}
     */
    private function curriculumFixtures(): array
    {
        $term = Term::factory()->create();
        $program = Program::factory()->create();
        $curriculum = Curriculum::factory()->create([
            'program_id' => $program->id,
        ]);

        return [$term, $program, $curriculum];
    }

    private function createSchedulableFaculty(Term $term, Subject $subject): User
    {
        $registrar = User::factory()->create();
        $faculty = User::factory()->create();
        $period = FacultyAvailabilityPeriod::factory()->create([
            'term_id' => $term->id,
            'status' => FacultyAvailabilityPeriod::StatusLocked,
            'created_by' => $registrar->id,
            'locked_at' => now(),
        ]);
        $submission = FacultyAvailabilitySubmission::factory()->create([
            'term_id' => $term->id,
            'availability_period_id' => $period->id,
            'faculty_id' => $faculty->id,
            'status' => FacultyAvailabilitySubmission::StatusLocked,
            'locked_at' => now(),
            'approved_by' => $registrar->id,
            'approved_at' => now(),
        ]);
        FacultyAvailabilityWindow::factory()->create([
            'submission_id' => $submission->id,
        ]);
        FacultySubjectEligibility::factory()->create([
            'faculty_id' => $faculty->id,
            'subject_id' => $subject->id,
            'term_id' => $term->id,
            'approved_by' => $registrar->id,
        ]);

        return $faculty;
    }

    private function readyScope(Curriculum $curriculum): CurriculumReadinessScope
    {
        return CurriculumReadinessScope::query()->updateOrCreate(
            [
                'curriculum_id' => $curriculum->id,
                'year_level' => '1st Year',
                'curriculum_period' => '1st Semester',
            ],
            [
                'status' => CurriculumReadinessScope::StatusReadyForScheduling,
                'last_transition_at' => now(),
                'last_blockers' => [],
                'last_blocker_hash' => null,
            ],
        );
    }

    private function deliveryGroup(Section $section, array $overrides = []): SectionDeliveryGroup
    {
        $modality = $overrides['modality'] ?? 'on_site';
        $pattern = DeliveryPattern::factory()->create([
            'modality' => $modality,
            'default_room_required' => SectionDeliveryGroup::modalityRequiresRoom($modality),
        ]);

        return SectionDeliveryGroup::factory()->create([
            'section_id' => $section->id,
            'delivery_pattern_id' => $pattern->id,
            'name' => 'Primary Group',
            'modality' => $modality,
            'capacity' => $section->max_seats,
            'assigned_count' => 0,
            'room_required' => SectionDeliveryGroup::modalityRequiresRoom($modality),
            'room' => SectionDeliveryGroup::modalityRequiresRoom($modality) ? 'R-101' : null,
            'status' => SectionDeliveryGroup::StatusActive,
            ...$overrides,
        ]);
    }
}
