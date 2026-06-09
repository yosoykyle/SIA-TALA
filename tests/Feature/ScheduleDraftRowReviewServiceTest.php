<?php

namespace Tests\Feature;

use App\Actions\Scheduling\ScheduleDraftRowReviewService;
use App\Models\FacultySubjectEligibility;
use App\Models\Program;
use App\Models\ScheduleDraftRow;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ScheduleDraftRowReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_registrar_can_revise_conflict_row_and_revalidate_it_as_warning(): void
    {
        [$run, $row, $faculty, $registrar] = $this->draftReviewFixtures();

        app(ScheduleDraftRowReviewService::class)->revise($row, [
            'faculty_id' => $faculty->id,
            'room' => 'R-101',
            'day_of_week' => 1,
            'starts_at' => '08:00',
            'ends_at' => '09:00',
            'modality' => 'on_site',
            'override_reason' => 'Registrar moved the class into the faculty availability window.',
        ], $registrar);

        $reviewedRow = ScheduleDraftRow::query()->where('generation_run_id', $run->id)->firstOrFail();

        $this->assertSame(ScheduleDraftRow::StatusWarning, $reviewedRow->status);
        $this->assertSame($faculty->id, $reviewedRow->faculty_id);
        $this->assertNull($reviewedRow->conflict_payload);
        $this->assertSame($registrar->id, $reviewedRow->edited_by);
        $this->assertNotNull($reviewedRow->edited_at);
        $this->assertSame('Registrar moved the class into the faculty availability window.', $reviewedRow->override_reason);
        $this->assertSame('registrar_manual_revision', $reviewedRow->warning_payload['items'][0]['type']);
        $this->assertSame(1, $run->refresh()->constraint_summary['solver_ingestion']['warning_count']);
    }

    public function test_revision_keeps_row_conflicted_when_hard_constraints_still_fail(): void
    {
        [$run, $row, $faculty, $registrar] = $this->draftReviewFixtures();

        app(ScheduleDraftRowReviewService::class)->revise($row, [
            'faculty_id' => $faculty->id,
            'room' => 'R-101',
            'day_of_week' => 2,
            'starts_at' => '08:00',
            'ends_at' => '09:00',
            'modality' => 'on_site',
            'override_reason' => 'Registrar attempted a revised slot.',
        ], $registrar);

        $reviewedRow = ScheduleDraftRow::query()->where('generation_run_id', $run->id)->firstOrFail();

        $this->assertSame(ScheduleDraftRow::StatusConflict, $reviewedRow->status);
        $this->assertTrue(collect($reviewedRow->conflict_payload['items'])->contains('type', 'outside_faculty_availability'));
        $this->assertSame($registrar->id, $reviewedRow->edited_by);
        $this->assertSame(1, $run->refresh()->constraint_summary['solver_ingestion']['conflict_count']);
    }

    public function test_revision_requires_manage_schedules_permission(): void
    {
        [, $row, $faculty] = $this->draftReviewFixtures();

        $this->expectException(AuthorizationException::class);

        app(ScheduleDraftRowReviewService::class)->revise($row, [
            'faculty_id' => $faculty->id,
            'room' => 'R-101',
            'day_of_week' => 1,
            'starts_at' => '08:00',
            'ends_at' => '09:00',
            'modality' => 'on_site',
            'override_reason' => 'Unauthorized attempt.',
        ], User::factory()->create());
    }

    /**
     * @return array{ScheduleGenerationRun, ScheduleDraftRow, User, User}
     */
    private function draftReviewFixtures(): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $registrar = User::factory()->create();
        $registrar->givePermissionTo(Permission::findOrCreate('manage-schedules'));

        $term = Term::factory()->create();
        $program = Program::factory()->create();
        $section = Section::factory()->for($term)->for($program)->create([
            'room' => 'R-101',
            'max_seats' => 30,
            'enrolled_count' => 20,
            'modality' => 'on_site',
        ]);
        $subject = Subject::factory()->create();
        $faculty = User::factory()->create();

        FacultySubjectEligibility::factory()->create([
            'faculty_id' => $faculty->id,
            'subject_id' => $subject->id,
            'term_id' => null,
        ]);

        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'requested_by' => $registrar->id,
            'generated_at' => now(),
            'constraint_summary' => [],
            'solver_input_snapshot' => $this->snapshot($section, $subject, $faculty),
            'solver_input_hash' => hash('sha256', 'test'),
            'solver_snapshot_captured_at' => now(),
        ]);

        $row = ScheduleDraftRow::query()->create([
            'generation_run_id' => $run->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'faculty_id' => null,
            'room' => 'R-101',
            'day_of_week' => null,
            'starts_at' => null,
            'ends_at' => null,
            'modality' => 'on_site',
            'status' => ScheduleDraftRow::StatusConflict,
            'conflict_payload' => [
                'source' => 'test',
                'items' => [
                    ['type' => 'missing_faculty_id', 'message' => 'Missing faculty.'],
                ],
            ],
        ]);

        return [$run, $row, $faculty, $registrar];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Section $section, Subject $subject, User $faculty): array
    {
        return [
            'schema_version' => 1,
            'sections' => [[
                'section_id' => $section->id,
                'section_name' => $section->name,
                'program_id' => $section->program_id,
                'modality' => $section->modality,
                'max_seats' => $section->max_seats,
                'enrolled_count' => $section->enrolled_count,
                'available_seats' => $section->max_seats - $section->enrolled_count,
                'fixed_room' => $section->room,
            ]],
            'curriculum_subject_demand' => [[
                'section_id' => $section->id,
                'subject_id' => $subject->id,
            ]],
            'faculty_availability' => [[
                'faculty_id' => $faculty->id,
                'status' => 'locked',
                'windows' => [
                    ['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                ],
            ]],
        ];
    }
}
