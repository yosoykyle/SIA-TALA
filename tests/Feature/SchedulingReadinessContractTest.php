<?php

namespace Tests\Feature;

use App\Actions\Scheduling\TermSchedulingReadinessService;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Term;
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

        Section::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => '1st Year',
            'curriculum_period' => '1st Semester',
            'room' => 'R-101',
            'modality' => 'on_site',
        ]);

        $readiness = app(TermSchedulingReadinessService::class)->evaluateTerm($term);

        $this->assertTrue($readiness['is_ready']);
        $this->assertSame([], $readiness['missing_term_fields']);
        $this->assertSame([], $readiness['section_issues']);
        $this->assertSame('sections.room fixed-room rescue catalog', $readiness['room_catalog_mode']);
    }

    public function test_term_is_not_ready_when_section_solver_scope_or_room_input_is_missing(): void
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
            'room',
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
}
