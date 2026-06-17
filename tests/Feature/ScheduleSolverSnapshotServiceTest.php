<?php

namespace Tests\Feature;

use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Models\Curriculum;
use App\Models\CurriculumReadinessScope;
use App\Models\CurriculumSubject;
use App\Models\DeliveryPattern;
use App\Models\FacultySubjectEligibility;
use App\Models\Program;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScheduleSolverSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_capture_persists_normalized_solver_input_snapshot_for_ready_term(): void
    {
        $fixtures = $this->readySchedulingFixtures();

        $snapshot = app(ScheduleSolverSnapshotService::class)->captureForRun($fixtures['run']);

        $fixtures['run']->refresh();

        $this->assertSame(3, $snapshot['schema_version']);
        $this->assertSame($fixtures['run']->id, $snapshot['run_metadata']['run_id']);
        $this->assertSame($fixtures['term']->id, $snapshot['run_metadata']['term_id']);
        $this->assertSame($fixtures['term']->term_name, $snapshot['run_metadata']['term_name']);
        $this->assertTrue($snapshot['readiness']['is_ready']);
        $this->assertCount(1, $snapshot['curriculum_readiness_scopes']);
        $this->assertSame('ready_for_scheduling', $snapshot['curriculum_readiness_scopes'][0]['status']);

        $this->assertSame([
            'section_id' => $fixtures['section']->id,
            'section_name' => $fixtures['section']->name,
            'program_id' => $fixtures['program']->id,
            'program_code' => $fixtures['program']->code,
            'curriculum_id' => $fixtures['curriculum']->id,
            'curriculum_version' => $fixtures['curriculum']->version_name,
            'year_level' => '1st Year',
            'curriculum_period' => '1st Semester',
            'modality' => 'on_site',
            'max_seats' => 30,
            'enrolled_count' => 25,
            'available_seats' => 5,
            'fixed_room' => 'R-101',
            'delivery_group_ids' => [$fixtures['deliveryGroup']->id],
        ], $snapshot['sections'][0]);

        $this->assertCount(1, $snapshot['section_delivery_groups']);
        $this->assertSame($fixtures['deliveryGroup']->id, $snapshot['section_delivery_groups'][0]['section_delivery_group_id']);
        $this->assertSame($fixtures['section']->id, $snapshot['section_delivery_groups'][0]['section_id']);
        $this->assertSame('Primary F2F', $snapshot['section_delivery_groups'][0]['delivery_group_name']);
        $this->assertSame('on_site', $snapshot['section_delivery_groups'][0]['modality']);
        $this->assertTrue($snapshot['section_delivery_groups'][0]['room_required']);
        $this->assertSame('R-101', $snapshot['section_delivery_groups'][0]['fixed_room']);

        $this->assertCount(2, $snapshot['curriculum_subject_demand']);
        $this->assertSame(
            "{$fixtures['section']->id}:{$fixtures['deliveryGroup']->id}:{$fixtures['subjectA']->id}",
            $snapshot['curriculum_subject_demand'][0]['demand_key'],
        );
        $this->assertSame($fixtures['section']->id, $snapshot['curriculum_subject_demand'][0]['section_id']);
        $this->assertSame($fixtures['deliveryGroup']->id, $snapshot['curriculum_subject_demand'][0]['section_delivery_group_id']);
        $this->assertSame($fixtures['subjectA']->id, $snapshot['curriculum_subject_demand'][0]['subject_id']);
        $this->assertSame('3.00', $snapshot['curriculum_subject_demand'][0]['units']);
        $this->assertSame('3.00', $snapshot['curriculum_subject_demand'][0]['weekly_contact_hours']);
        $this->assertSame('3.00', $snapshot['curriculum_subject_demand'][0]['lec_hours']);
        $this->assertSame('major', $snapshot['curriculum_subject_demand'][0]['academic_subject_type']);
        $this->assertSame('lecture', $snapshot['curriculum_subject_demand'][0]['scheduling_group']);
        $this->assertSame('on_site', $snapshot['curriculum_subject_demand'][0]['modality']);
        $this->assertTrue($snapshot['curriculum_subject_demand'][0]['room_required']);
        $this->assertSame('R-101', $snapshot['curriculum_subject_demand'][0]['fixed_room']);

        $this->assertCount(2, $snapshot['faculty_eligibility']);
        $this->assertSame($fixtures['faculty']->id, $snapshot['faculty_eligibility'][0]['faculty_id']);
        $this->assertSame($fixtures['subjectA']->id, $snapshot['faculty_eligibility'][0]['subject_id']);
        $this->assertSame('default', $snapshot['faculty_eligibility'][0]['scope']);
        $this->assertSame('term', $snapshot['faculty_eligibility'][1]['scope']);

        $this->assertCount(1, $snapshot['faculty_availability']);
        $this->assertSame($fixtures['faculty']->id, $snapshot['faculty_availability'][0]['faculty_id']);
        $this->assertSame('locked', $snapshot['faculty_availability'][0]['status']);
        $this->assertSame(2, $snapshot['faculty_availability'][0]['version']);
        $this->assertSame([
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '12:00:00',
            'notes' => 'Morning only',
        ], $snapshot['faculty_availability'][0]['windows'][0]);

        $this->assertSame('R-101', $snapshot['rooms_catalog'][0]['room_code']);
        $this->assertSame('section_delivery_groups.room', $snapshot['rooms_catalog'][0]['source']);
        $this->assertSame([$fixtures['section']->id], $snapshot['rooms_catalog'][0]['section_ids']);
        $this->assertSame([$fixtures['deliveryGroup']->id], $snapshot['rooms_catalog'][0]['section_delivery_group_ids']);
        $this->assertSame(30, $snapshot['rooms_catalog'][0]['max_group_capacity']);

        $this->assertCount(1, $snapshot['existing_commitments']);
        $this->assertSame($fixtures['existingMeeting']->id, $snapshot['existing_commitments'][0]['section_meeting_id']);
        $this->assertSame($fixtures['deliveryGroup']->id, $snapshot['existing_commitments'][0]['section_delivery_group_id']);

        $this->assertSame('section_delivery_groups.room fixed-room catalog', $snapshot['policy_constraints']['room_catalog_mode']);
        $this->assertTrue($snapshot['policy_constraints']['delivery_group_required']);
        $this->assertTrue($snapshot['policy_constraints']['mandatory_faculty_assignment']);
        $this->assertSame(30, $snapshot['policy_constraints']['max_section_seats']);
        $this->assertSame('editable_bounded_max_30_not_below_enrolled_count', $snapshot['policy_constraints']['section_capacity_mode']);
        $this->assertSame($snapshot, $fixtures['run']->solver_input_snapshot);
        $this->assertNotNull($fixtures['run']->solver_snapshot_captured_at);
        $this->assertSame(
            hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            $fixtures['run']->solver_input_hash,
        );
    }

    public function test_capture_returns_existing_snapshot_instead_of_recapturing_source_changes(): void
    {
        $fixtures = $this->readySchedulingFixtures();
        $service = app(ScheduleSolverSnapshotService::class);

        $firstSnapshot = $service->captureForRun($fixtures['run']);
        $firstHash = $fixtures['run']->refresh()->solver_input_hash;

        $fixtures['section']->update(['room' => 'R-999']);
        CurriculumSubject::factory()->create([
            'curriculum_id' => $fixtures['curriculum']->id,
            'subject_id' => Subject::factory()->create(['code' => 'NEW-101'])->id,
            'year_level' => '1st Year',
            'semester' => '1st Semester',
            'sort_order' => 99,
        ]);

        $secondSnapshot = $service->captureForRun($fixtures['run']);

        $this->assertSame($firstSnapshot, $secondSnapshot);
        $this->assertSame($firstHash, $fixtures['run']->refresh()->solver_input_hash);
        $this->assertSame('R-101', $secondSnapshot['sections'][0]['fixed_room']);
        $this->assertCount(2, $secondSnapshot['curriculum_subject_demand']);
    }

    public function test_capture_rejects_unready_term_without_persisting_snapshot(): void
    {
        $registrar = User::factory()->create();
        $term = Term::factory()->create();
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusDraft,
            'requested_by' => $registrar->id,
            'generated_at' => now(),
            'constraint_summary' => [],
        ]);

        try {
            app(ScheduleSolverSnapshotService::class)->captureForRun($run);
            $this->fail('Expected unready term to block solver snapshot capture.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'Schedule solver snapshot cannot be captured until term readiness passes.',
                $exception->getMessage(),
            );
        }

        $this->assertNull($run->refresh()->solver_input_snapshot);
    }

    /**
     * @return array{
     *     term: Term,
     *     program: Program,
     *     curriculum: Curriculum,
     *     section: Section,
     *     deliveryGroup: SectionDeliveryGroup,
     *     subjectA: Subject,
     *     subjectB: Subject,
     *     faculty: User,
     *     existingMeeting: SectionMeeting,
     *     run: ScheduleGenerationRun
     * }
     */
    private function readySchedulingFixtures(): array
    {
        $registrar = User::factory()->create();
        $faculty = User::factory()->create(['name' => 'Ada Faculty']);
        $term = Term::factory()->create(['term_name' => '1st Semester AY 2026']);
        $program = Program::factory()->create(['code' => 'BSIT']);
        $curriculum = Curriculum::factory()->create([
            'program_id' => $program->id,
            'version_name' => 'BSIT 2026',
        ]);
        $section = Section::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => '1st Year',
            'curriculum_period' => '1st Semester',
            'name' => 'BSIT 1A',
            'room' => 'R-101',
            'max_seats' => 30,
            'enrolled_count' => 25,
            'modality' => 'on_site',
        ]);
        $deliveryGroup = $this->deliveryGroup($section);
        $subjectA = Subject::factory()->create([
            'code' => 'IT101',
            'units' => '3.00',
            'lec_hours' => '1.00',
        ]);
        $subjectB = Subject::factory()->create([
            'code' => 'MATH101',
            'units' => '3.00',
            'lec_hours' => '3.00',
        ]);

        CurriculumSubject::factory()->create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subjectA->id,
            'year_level' => '1st Year',
            'semester' => '1st Semester',
            'weekly_contact_hours' => '3.00',
            'academic_subject_type' => CurriculumSubject::AcademicSubjectTypeMajor,
            'scheduling_group' => CurriculumSubject::SchedulingGroupLecture,
            'sort_order' => 1,
        ]);
        CurriculumSubject::factory()->create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subjectB->id,
            'year_level' => '1st Year',
            'semester' => '1st Semester',
            'weekly_contact_hours' => '3.00',
            'academic_subject_type' => CurriculumSubject::AcademicSubjectTypeMinor,
            'scheduling_group' => CurriculumSubject::SchedulingGroupLecture,
            'sort_order' => 2,
        ]);
        CurriculumReadinessScope::query()->updateOrCreate(
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

        FacultySubjectEligibility::factory()->create([
            'faculty_id' => $faculty->id,
            'subject_id' => $subjectA->id,
            'term_id' => null,
            'priority' => 1,
        ]);
        FacultySubjectEligibility::factory()->create([
            'faculty_id' => $faculty->id,
            'subject_id' => $subjectB->id,
            'term_id' => $term->id,
            'priority' => 2,
        ]);
        FacultySubjectEligibility::factory()->inactive()->create([
            'faculty_id' => $faculty->id,
            'subject_id' => $subjectB->id,
            'term_id' => null,
        ]);

        $availabilityPeriodId = DB::table('faculty_availability_periods')->insertGetId([
            'term_id' => $term->id,
            'opens_at' => now()->subWeeks(2),
            'closes_at' => now()->subWeek(),
            'status' => 'locked',
            'created_by' => $registrar->id,
            'locked_at' => now()->subDays(3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('faculty_availability_submissions')->insert([
            'term_id' => $term->id,
            'availability_period_id' => $availabilityPeriodId,
            'faculty_id' => $faculty->id,
            'status' => 'submitted',
            'version' => 1,
            'submitted_at' => now()->subDays(8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $lockedSubmissionId = DB::table('faculty_availability_submissions')->insertGetId([
            'term_id' => $term->id,
            'availability_period_id' => $availabilityPeriodId,
            'faculty_id' => $faculty->id,
            'status' => 'locked',
            'version' => 2,
            'submitted_at' => now()->subDays(6),
            'locked_at' => now()->subDays(3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('faculty_availability_windows')->insert([
            'submission_id' => $lockedSubmissionId,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '12:00:00',
            'notes' => 'Morning only',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $existingMeeting = SectionMeeting::query()->create([
            'term_id' => $term->id,
            'section_id' => $section->id,
            'section_delivery_group_id' => $deliveryGroup->id,
            'subject_id' => $subjectA->id,
            'faculty_id' => $faculty->id,
            'room' => 'R-101',
            'day_of_week' => 2,
            'starts_at' => '13:00:00',
            'ends_at' => '14:30:00',
            'modality' => 'on_site',
            'committed_by' => $registrar->id,
            'committed_at' => now(),
        ]);

        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusDraft,
            'requested_by' => $registrar->id,
            'generated_at' => now(),
            'constraint_summary' => [],
        ]);

        return [
            'term' => $term,
            'program' => $program,
            'curriculum' => $curriculum,
            'section' => $section,
            'deliveryGroup' => $deliveryGroup,
            'subjectA' => $subjectA,
            'subjectB' => $subjectB,
            'faculty' => $faculty,
            'existingMeeting' => $existingMeeting,
            'run' => $run,
        ];
    }

    private function deliveryGroup(Section $section): SectionDeliveryGroup
    {
        $pattern = DeliveryPattern::factory()->create([
            'modality' => 'on_site',
            'default_room_required' => true,
        ]);

        return SectionDeliveryGroup::factory()->create([
            'section_id' => $section->id,
            'delivery_pattern_id' => $pattern->id,
            'name' => 'Primary F2F',
            'modality' => 'on_site',
            'capacity' => $section->max_seats,
            'assigned_count' => 0,
            'room_required' => true,
            'room' => 'R-101',
            'status' => SectionDeliveryGroup::StatusActive,
        ]);
    }
}
