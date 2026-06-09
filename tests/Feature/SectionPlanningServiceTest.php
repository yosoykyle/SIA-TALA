<?php

namespace Tests\Feature;

use App\Actions\Scheduling\SectionPlanningService;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Term;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SectionPlanningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_section_planning_payload_is_normalized_for_solver_readiness(): void
    {
        [$term, $program, $curriculum] = $this->planningFixtures();

        $payload = app(SectionPlanningService::class)->prepareForSave([
            'term_id' => (string) $term->id,
            'program_id' => (string) $program->id,
            'curriculum_id' => (string) $curriculum->id,
            'year_level' => ' 1st Year ',
            'curriculum_period' => ' 1st Semester ',
            'name' => ' BSIT 1A ',
            'modality' => 'on_site',
            'room' => ' R-101 ',
            'max_seats' => '30',
            'enrolled_count' => '12',
        ]);

        $this->assertSame($term->id, $payload['term_id']);
        $this->assertSame($program->id, $payload['program_id']);
        $this->assertSame($curriculum->id, $payload['curriculum_id']);
        $this->assertSame('1st Year', $payload['year_level']);
        $this->assertSame('1st Semester', $payload['curriculum_period']);
        $this->assertSame('BSIT 1A', $payload['name']);
        $this->assertSame('R-101', $payload['room']);
        $this->assertSame(30, $payload['max_seats']);
        $this->assertSame(12, $payload['enrolled_count']);
    }

    public function test_online_and_modular_section_planning_clears_physical_room(): void
    {
        [$term, $program, $curriculum] = $this->planningFixtures();

        foreach (['online', 'modular'] as $modality) {
            $payload = app(SectionPlanningService::class)->prepareForSave([
                'term_id' => $term->id,
                'program_id' => $program->id,
                'curriculum_id' => $curriculum->id,
                'year_level' => '1st Year',
                'curriculum_period' => '1st Semester',
                'name' => "Section {$modality}",
                'modality' => $modality,
                'room' => 'R-999',
                'max_seats' => 30,
                'enrolled_count' => 0,
            ]);

            $this->assertNull($payload['room']);
        }
    }

    public function test_section_planning_rejects_capacity_room_and_curriculum_program_violations(): void
    {
        [$term, $program] = $this->planningFixtures();
        $otherProgram = Program::factory()->create();
        $otherCurriculum = Curriculum::factory()->create([
            'program_id' => $otherProgram->id,
        ]);

        try {
            app(SectionPlanningService::class)->prepareForSave([
                'term_id' => $term->id,
                'program_id' => $program->id,
                'curriculum_id' => $otherCurriculum->id,
                'year_level' => '1st Year',
                'curriculum_period' => '1st Semester',
                'name' => 'BSIT 1A',
                'modality' => 'on_site',
                'room' => null,
                'max_seats' => 31,
                'enrolled_count' => 32,
            ]);

            $this->fail('Expected section planning validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('max_seats', $exception->errors());
            $this->assertArrayHasKey('room', $exception->errors());
            $this->assertArrayHasKey('curriculum_id', $exception->errors());
        }
    }

    /**
     * @return array{Term, Program, Curriculum}
     */
    private function planningFixtures(): array
    {
        $term = Term::factory()->create();
        $program = Program::factory()->create();
        $curriculum = Curriculum::factory()->create([
            'program_id' => $program->id,
        ]);

        return [$term, $program, $curriculum];
    }
}
