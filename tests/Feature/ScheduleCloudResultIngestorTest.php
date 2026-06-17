<?php

namespace Tests\Feature;

use App\Actions\Scheduling\ScheduleCloudResultIngestor;
use App\Actions\Scheduling\ScheduleCommitService;
use App\Models\FacultyAvailabilityPeriod;
use App\Models\FacultyAvailabilitySubmission;
use App\Models\FacultyAvailabilityWindow;
use App\Models\FacultySubjectEligibility;
use App\Models\Program;
use App\Models\ScheduleDraftRow;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ScheduleCloudResultIngestorTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingests_valid_solver_rows_as_ok_draft_rows_and_records_summary(): void
    {
        [$run, $section, $subject, $faculty] = $this->readyRun();

        $summary = app(ScheduleCloudResultIngestor::class)->ingest($run, [
            'draft_rows' => [
                $this->solverRow($section, $subject, $faculty),
            ],
        ]);

        $draftRow = ScheduleDraftRow::query()->first();
        $deliveryGroup = $section->deliveryGroups()->firstOrFail();

        $this->assertSame(1, $summary['draft_row_count']);
        $this->assertSame(1, $summary['ok_count']);
        $this->assertSame(0, $summary['conflict_count']);
        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $run->refresh()->status);
        $this->assertNotNull($draftRow);
        $this->assertSame(ScheduleDraftRow::StatusOk, $draftRow->status);
        $this->assertSame($deliveryGroup->id, $draftRow->section_delivery_group_id);
        $this->assertSame($faculty->id, $draftRow->faculty_id);
        $this->assertSame(1, $run->constraint_summary['solver_ingestion']['ok_count']);
    }

    public function test_missing_or_ineligible_faculty_becomes_conflict_instead_of_ok(): void
    {
        [$run, $section, $subject, $faculty] = $this->readyRun(createEligibility: false);

        app(ScheduleCloudResultIngestor::class)->ingest($run, [
            'draft_rows' => [
                $this->solverRow($section, $subject, null, [
                    'status' => ScheduleDraftRow::StatusOk,
                    'modality' => 'modular',
                    'room' => null,
                ]),
                $this->solverRow($section, $subject, $faculty, [
                    'starts_at' => '10:00:00',
                    'ends_at' => '11:00:00',
                ]),
            ],
        ]);

        $draftRows = ScheduleDraftRow::query()->orderBy('id')->get();

        $this->assertCount(2, $draftRows);
        $this->assertSame(ScheduleDraftRow::StatusConflict, $draftRows[0]->status);
        $this->assertSame(ScheduleDraftRow::StatusConflict, $draftRows[1]->status);
        $this->assertSame('missing_faculty_id', $draftRows[0]->conflict_payload['items'][0]['type']);
        $this->assertTrue(collect($draftRows[1]->conflict_payload['items'])->contains('type', 'missing_faculty_subject_eligibility'));
    }

    public function test_warning_rows_with_hard_valid_assignments_can_be_committed(): void
    {
        [$run, $section, $subject, $faculty, $registrar] = $this->readyRun(registrar: $this->registrar());

        app(ScheduleCloudResultIngestor::class)->ingest($run, [
            'draft_rows' => [
                $this->solverRow($section, $subject, $faculty, [
                    'status' => ScheduleDraftRow::StatusWarning,
                    'warning_payload' => [
                        ['type' => 'soft_preference_miss', 'message' => 'Faculty preferred a later slot.'],
                    ],
                ]),
            ],
        ]);

        $this->assertSame(ScheduleDraftRow::StatusWarning, ScheduleDraftRow::query()->value('status'));

        app(ScheduleCommitService::class)->commit($run->fresh(), $registrar);

        $this->assertDatabaseHas('section_meetings', [
            'schedule_generation_run_id' => $run->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'faculty_id' => $faculty->id,
        ]);
    }

    public function test_published_runs_reject_late_solver_ingestion(): void
    {
        [$run, $section, $subject, $faculty] = $this->readyRun();
        $run->forceFill(['status' => ScheduleGenerationRun::StatusPublished])->save();

        $this->expectException(ValidationException::class);

        app(ScheduleCloudResultIngestor::class)->ingest($run, [
            'draft_rows' => [
                $this->solverRow($section, $subject, $faculty),
            ],
        ]);
    }

    public function test_availability_and_internal_delivery_group_overlaps_are_stored_as_conflicts(): void
    {
        [$run, $section, $subject, $faculty] = $this->readyRun();
        $secondSubject = Subject::factory()->create();
        $secondFaculty = User::factory()->create();

        FacultySubjectEligibility::factory()->create([
            'faculty_id' => $secondFaculty->id,
            'subject_id' => $secondSubject->id,
            'term_id' => null,
        ]);

        $run->forceFill([
            'solver_input_snapshot' => $this->snapshot($section, [
                $subject,
                $secondSubject,
            ], [
                $faculty->id => [['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']],
                $secondFaculty->id => [['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']],
            ]),
        ])->save();

        app(ScheduleCloudResultIngestor::class)->ingest($run, [
            'draft_rows' => [
                $this->solverRow($section, $subject, $faculty),
                $this->solverRow($section, $secondSubject, $secondFaculty, [
                    'starts_at' => '08:30:00',
                    'ends_at' => '09:30:00',
                    'room' => 'R-102',
                ]),
                $this->solverRow($section, $secondSubject, $secondFaculty, [
                    'day_of_week' => 2,
                    'starts_at' => '08:30:00',
                    'ends_at' => '09:30:00',
                    'room' => 'R-102',
                ]),
            ],
        ]);

        $draftRows = ScheduleDraftRow::query()->orderBy('id')->get();

        $this->assertSame(ScheduleDraftRow::StatusOk, $draftRows[0]->status);
        $this->assertSame(ScheduleDraftRow::StatusConflict, $draftRows[1]->status);
        $this->assertSame(ScheduleDraftRow::StatusConflict, $draftRows[2]->status);
        $this->assertTrue(collect($draftRows[1]->conflict_payload['items'])->contains('type', 'internal_delivery_group_overlap'));
        $this->assertTrue(collect($draftRows[2]->conflict_payload['items'])->contains('type', 'outside_faculty_availability'));
    }

    public function test_invalid_time_missing_required_room_and_existing_room_conflict_are_conflicts(): void
    {
        [$run, $section, $subject, $faculty, $registrar] = $this->readyRun(registrar: User::factory()->create());
        $section->deliveryGroups()->firstOrFail()->forceFill([
            'room_required' => true,
            'room' => null,
        ])->save();
        $run->forceFill([
            'solver_input_snapshot' => $this->snapshot($section, [$subject], [
                $faculty->id => [['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '15:00:00']],
            ]),
        ])->save();
        $otherSection = Section::factory()->for($section->term)->for(Program::factory())->create([
            'room' => 'R-101',
        ]);
        $otherDeliveryGroup = $this->deliveryGroupFor($otherSection, room: 'R-101');
        $otherSubject = Subject::factory()->create();

        FacultySubjectEligibility::factory()->create([
            'faculty_id' => $faculty->id,
            'subject_id' => $otherSubject->id,
            'term_id' => null,
        ]);

        SectionMeeting::query()->create([
            'term_id' => $run->term_id,
            'section_id' => $otherSection->id,
            'section_delivery_group_id' => $otherDeliveryGroup->id,
            'subject_id' => $otherSubject->id,
            'faculty_id' => $faculty->id,
            'room' => 'R-101',
            'day_of_week' => 1,
            'starts_at' => '13:00:00',
            'ends_at' => '14:00:00',
            'modality' => 'on_site',
            'committed_by' => $registrar->id,
            'committed_at' => now(),
        ]);

        app(ScheduleCloudResultIngestor::class)->ingest($run, [
            'draft_rows' => [
                $this->solverRow($section, $subject, $faculty, [
                    'room' => null,
                ]),
                $this->solverRow($section, $subject, $faculty, [
                    'room' => 'R-101',
                    'starts_at' => '10:00:00',
                    'ends_at' => '10:00:00',
                ]),
                $this->solverRow($section, $subject, $faculty, [
                    'room' => 'R-101',
                    'starts_at' => '13:30:00',
                    'ends_at' => '14:30:00',
                ]),
            ],
        ]);

        $draftRows = ScheduleDraftRow::query()->orderBy('id')->get();

        $this->assertTrue(collect($draftRows[0]->conflict_payload['items'])->contains('type', 'missing_required_room'));
        $this->assertTrue(collect($draftRows[1]->conflict_payload['items'])->contains('type', 'invalid_time_range'));
        $this->assertTrue(collect($draftRows[2]->conflict_payload['items'])->contains('type', 'room_overlap'));
        $this->assertTrue(collect($draftRows[2]->conflict_payload['items'])->contains('type', 'faculty_overlap'));
    }

    public function test_solver_row_room_must_match_fixed_section_room_snapshot(): void
    {
        [$run, $section, $subject, $faculty] = $this->readyRun();

        app(ScheduleCloudResultIngestor::class)->ingest($run, [
            'draft_rows' => [
                $this->solverRow($section, $subject, $faculty, [
                    'room' => 'R-999',
                ]),
            ],
        ]);

        $draftRow = ScheduleDraftRow::query()->first();

        $this->assertNotNull($draftRow);
        $this->assertSame(ScheduleDraftRow::StatusConflict, $draftRow->status);
        $this->assertTrue(collect($draftRow->conflict_payload['items'])->contains('type', 'room_mismatch_fixed_delivery_group_room'));
    }

    /**
     * @return array{ScheduleGenerationRun, Section, Subject, User, User}
     */
    private function readyRun(?User $registrar = null, bool $createEligibility = true): array
    {
        $registrar ??= User::factory()->create();
        $term = Term::factory()->create();
        $program = Program::factory()->create();
        $section = Section::factory()->for($term)->for($program)->create([
            'room' => 'R-101',
            'max_seats' => 30,
            'enrolled_count' => 25,
            'modality' => 'on_site',
        ]);
        $this->deliveryGroupFor($section, room: 'R-101');
        $subject = Subject::factory()->create();
        $faculty = User::factory()->create();

        if ($createEligibility) {
            FacultySubjectEligibility::factory()->create([
                'faculty_id' => $faculty->id,
                'subject_id' => $subject->id,
                'term_id' => null,
            ]);
        }
        $this->createFacultyAvailability($term, $faculty);

        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusDraft,
            'requested_by' => $registrar->id,
            'generated_at' => now(),
            'constraint_summary' => [],
            'solver_input_snapshot' => $this->snapshot($section, [$subject], [
                $faculty->id => [['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '15:00:00']],
            ]),
            'solver_input_hash' => hash('sha256', 'test'),
            'solver_snapshot_captured_at' => now(),
        ]);

        return [$run, $section, $subject, $faculty, $registrar];
    }

    private function createFacultyAvailability(Term $term, User $faculty): void
    {
        $period = FacultyAvailabilityPeriod::query()->firstOrCreate(
            ['term_id' => $term->id],
            [
                'opens_at' => now()->subDay(),
                'closes_at' => now()->addDays(7),
                'status' => 'open',
                'created_by' => User::factory()->create()->id,
                'locked_at' => null,
            ],
        );

        $submission = FacultyAvailabilitySubmission::factory()->create([
            'term_id' => $term->id,
            'availability_period_id' => $period->id,
            'faculty_id' => $faculty->id,
            'status' => FacultyAvailabilitySubmission::StatusLocked,
            'version' => 1,
            'locked_at' => now(),
        ]);

        FacultyAvailabilityWindow::factory()->create([
            'submission_id' => $submission->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '15:00:00',
        ]);
    }

    private function registrar(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $registrar = User::factory()->create();
        $registrar->givePermissionTo(Permission::findOrCreate('manage-schedules'));

        return $registrar;
    }

    /**
     * @param  list<Subject>  $subjects
     * @param  array<int, list<array{day_of_week:int, starts_at:string, ends_at:string}>>  $availability
     * @return array<string, mixed>
     */
    private function snapshot(Section $section, array $subjects, array $availability): array
    {
        $deliveryGroup = $section->deliveryGroups()->firstOrFail();

        return [
            'schema_version' => 3,
            'sections' => [[
                'section_id' => $section->id,
                'section_name' => $section->name,
                'program_id' => $section->program_id,
                'modality' => $section->modality,
                'max_seats' => $section->max_seats,
                'enrolled_count' => $section->enrolled_count,
                'available_seats' => $section->max_seats - $section->enrolled_count,
                'fixed_room' => $section->room,
                'delivery_group_ids' => [$deliveryGroup->id],
            ]],
            'section_delivery_groups' => [[
                'section_delivery_group_id' => $deliveryGroup->id,
                'section_id' => $section->id,
                'delivery_group_name' => $deliveryGroup->name,
                'modality' => $deliveryGroup->modality,
                'capacity' => $deliveryGroup->capacity,
                'assigned_count' => $deliveryGroup->assigned_count,
                'available_seats' => $deliveryGroup->availableSeats(),
                'room_required' => $deliveryGroup->room_required,
                'fixed_room' => $deliveryGroup->room,
            ]],
            'curriculum_subject_demand' => collect($subjects)
                ->map(fn (Subject $subject): array => [
                    'demand_key' => "{$section->id}:{$deliveryGroup->id}:{$subject->id}",
                    'section_id' => $section->id,
                    'section_delivery_group_id' => $deliveryGroup->id,
                    'subject_id' => $subject->id,
                    'modality' => $deliveryGroup->modality,
                    'room_required' => $deliveryGroup->room_required,
                    'fixed_room' => $deliveryGroup->room,
                ])
                ->all(),
            'faculty_availability' => collect($availability)
                ->map(fn (array $windows, int $facultyId): array => [
                    'faculty_id' => $facultyId,
                    'status' => 'locked',
                    'windows' => $windows,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function solverRow(Section $section, Subject $subject, ?User $faculty, array $overrides = []): array
    {
        $deliveryGroup = $section->deliveryGroups()->firstOrFail();

        return [
            'section_id' => $section->id,
            'section_delivery_group_id' => $deliveryGroup->id,
            'subject_id' => $subject->id,
            'faculty_id' => $faculty?->id,
            'room' => $deliveryGroup->room,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:00:00',
            'modality' => $deliveryGroup->modality,
            'status' => ScheduleDraftRow::StatusOk,
            ...$overrides,
        ];
    }

    private function deliveryGroupFor(Section $section, ?string $room = null): SectionDeliveryGroup
    {
        return SectionDeliveryGroup::factory()->create([
            'section_id' => $section->id,
            'name' => 'Primary F2F',
            'modality' => $section->modality,
            'capacity' => $section->max_seats ?? 30,
            'assigned_count' => 0,
            'room_required' => Section::modalityRequiresRoom($section->modality),
            'room' => $room ?? $section->room,
            'status' => SectionDeliveryGroup::StatusActive,
        ]);
    }
}
