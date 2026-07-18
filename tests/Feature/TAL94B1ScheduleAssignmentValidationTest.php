<?php

namespace Tests\Feature;

use App\Actions\Scheduling\ScheduleAssignmentValidationService;
use App\Actions\Scheduling\ScheduleCloudResultIngestor;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ViewScheduleGenerationRun;
use App\Filament\Resources\SchedulingDemands\SchedulingDemandResource;
use App\Models\CandidateScheduleRow;
use App\Models\CourseComponent;
use App\Models\FacultyQualification;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL94B1ScheduleAssignmentValidationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleSystemSuperAdmin,
            User::StaffRoleFaculty,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_valid_v2_result_is_accepted_and_ingested(): void
    {
        $context = $this->context();
        $result = $this->validResult($context);

        $validation = app(ScheduleAssignmentValidationService::class)
            ->validate($context['run'], $result);

        $this->assertTrue($validation->passes(), json_encode($validation->findings(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->assertSame([], $validation->blockingFindings());
        $this->assertCount(1, $validation->candidateRows());

        $summary = app(ScheduleCloudResultIngestor::class)
            ->ingest($context['run'], $result);

        $candidate = CandidateScheduleRow::query()->sole();

        $this->assertSame('accepted', $summary['status']);
        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $context['run']->fresh()->status);
        $this->assertSame($context['demands'][0]->id, $candidate->scheduling_demand_id);
        $this->assertSame(CandidateScheduleRow::StatusOk, $candidate->status);
        $this->assertEquals(
            $result['solver_statistics'],
            data_get($context['run']->fresh()->diagnostics, 'solver_result.solver_statistics'),
        );
    }

    public function test_warning_only_result_is_accepted_without_becoming_a_hard_violation(): void
    {
        $context = $this->context();
        $result = $this->validResult($context);
        $warning = [
            'type' => 'soft_quality_warning',
            'message' => 'The candidate has a lower soft-quality score.',
        ];
        $result['assignments'][0]['assignment_status'] = 'warning';
        $result['assignments'][0]['warnings'] = [$warning];
        $result['warnings'] = [$warning];
        $result['warning_count'] = 1;

        $summary = app(ScheduleCloudResultIngestor::class)
            ->ingest($context['run'], $result);

        $candidate = CandidateScheduleRow::query()->sole();
        $findings = $context['run']->fresh()->getAttribute('diagnostics')['solver_result']['findings'];

        $this->assertSame('accepted', $summary['status']);
        $this->assertSame(0, $summary['hard_violation_count']);
        $this->assertSame(1, $summary['warning_count']);
        $this->assertSame(CandidateScheduleRow::StatusWarning, $candidate->status);
        $this->assertContains('soft_quality_warning', collect($findings)->pluck('code')->all());
        $this->assertSame([], collect($findings)->where('severity', 'blocking')->all());
    }

    public function test_missing_captured_demand_blocks_before_candidate_persistence(): void
    {
        $context = $this->context();
        $result = $this->validResult($context);
        $context['demands'][0]->delete();

        $summary = app(ScheduleCloudResultIngestor::class)
            ->ingest($context['run'], $result);

        $findings = $context['run']->fresh()->getAttribute('diagnostics')['solver_result']['findings'];

        $this->assertSame('blocked', $summary['status']);
        $this->assertSame(0, CandidateScheduleRow::query()->count());
        $this->assertContains('missing_persistence_source', collect($findings)->pluck('code')->all());
    }

    public function test_legacy_result_blocks_and_preserves_prior_candidate_rows(): void
    {
        $context = $this->context();
        $prior = $this->candidate($context, $context['demands'][0]);
        $legacy = $this->validResult($context);
        $legacy['draft_rows'] = $legacy['assignments'];
        unset($legacy['assignments']);

        $summary = app(ScheduleCloudResultIngestor::class)
            ->ingest($context['run'], $legacy);

        $diagnostics = $context['run']->fresh()->getAttribute('diagnostics');

        $this->assertSame('blocked', $summary['status']);
        $this->assertSame(1, $summary['preserved_candidate_row_count']);
        $this->assertSame(ScheduleGenerationRun::StatusBlocked, $context['run']->fresh()->status);
        $this->assertModelExists($prior);
        $this->assertContains('legacy_draft_rows_not_allowed', collect($diagnostics['solver_result']['findings'])->pluck('code')->all());
    }

    public function test_envelope_identity_counters_and_objective_must_reconcile(): void
    {
        $context = $this->context();
        $valid = $this->validResult($context);
        $cases = [
            'solver_run_mismatch' => function (array $result) use ($context): array {
                $result['solver_run_id'] = $context['run']->id + 1;

                return $result;
            },
            'model_version_mismatch' => function (array $result): array {
                $result['model_version'] = 'legacy-v1';

                return $result;
            },
            'assigned_count_mismatch' => function (array $result): array {
                $result['assigned_count'] = 0;

                return $result;
            },
            'objective_score_mismatch' => function (array $result): array {
                $result['objective_score'] = 99;

                return $result;
            },
            'objective_weight_mismatch' => function (array $result): array {
                $result['objective_details']['terms']['prefer_earlier_time_blocks']['weight'] = 2;
                $result['objective_details']['terms']['prefer_earlier_time_blocks']['weighted'] = 2;
                $result['objective_details']['total'] = 5;
                $result['objective_score'] = 5;

                return $result;
            },
            'objective_term_missing' => function (array $result): array {
                unset($result['objective_details']['terms']['prefer_earlier_time_blocks']);
                $result['objective_details']['total'] = 3;
                $result['objective_score'] = 3;

                return $result;
            },
        ];

        foreach ($cases as $expectedCode => $mutate) {
            $validation = app(ScheduleAssignmentValidationService::class)
                ->validate($context['run'], $mutate($valid));

            $this->assertFalse($validation->passes(), $expectedCode);
            $this->assertContains($expectedCode, collect($validation->findings())->pluck('code')->all());
        }
    }

    public function test_solver_statistics_must_be_present_typed_and_allowlisted(): void
    {
        $context = $this->context();
        $valid = $this->validResult($context);
        $cases = [
            'missing' => function (array $result): array {
                unset($result['solver_statistics']);

                return $result;
            },
            'unknown_field' => function (array $result): array {
                $result['solver_statistics']['raw_solver_log'] = 'must not be persisted';

                return $result;
            },
            'malformed_field' => function (array $result): array {
                $result['solver_statistics']['candidate_count'] = 'many';

                return $result;
            },
        ];

        foreach ($cases as $name => $mutate) {
            $validation = app(ScheduleAssignmentValidationService::class)
                ->validate($context['run'], $mutate($valid));

            $this->assertFalse($validation->passes(), $name);
            $this->assertContains('invalid_response_field', collect($validation->findings())->pluck('code')->all());
        }

        $tampered = $cases['unknown_field']($valid);
        $summary = app(ScheduleCloudResultIngestor::class)->ingest($context['run'], $tampered);
        $diagnostics = $context['run']->fresh()->diagnostics;

        $this->assertSame('blocked', $summary['status']);
        $this->assertNull(data_get($diagnostics, 'solver_result.solver_statistics'));
        $this->assertStringNotContainsString(
            'must not be persisted',
            json_encode($diagnostics, JSON_THROW_ON_ERROR),
        );
    }

    public function test_exact_coverage_and_assignment_hard_constraints_are_enforced(): void
    {
        $context = $this->context();
        $valid = $this->validResult($context);
        $otherFaculty = User::factory()->create(['status' => User::StatusActive]);
        $badRoom = Room::factory()->create([
            'room_type' => Room::TypeLaboratory,
            'capacity' => 5,
            'is_active' => true,
        ]);
        $badRoomPayload = [
            'room_id' => $badRoom->id,
            'room_type' => $badRoom->room_type,
            'capacity' => $badRoom->capacity,
            'feature_keys' => [],
        ];
        $cases = [
            'missing_assignment' => function (array $result): array {
                $result['assignments'] = [];
                $result['assigned_count'] = 0;

                return $result;
            },
            'assignment_duration_mismatch' => function (array $result): array {
                $result['assignments'][0]['ends_at'] = '09:00:00';
                $result['assignments'][0]['end_time'] = '09:00:00';

                return $result;
            },
            'faculty_not_eligible' => function (array $result) use ($otherFaculty): array {
                $result['assignments'][0]['faculty_id'] = $otherFaculty->id;
                $result['assignments'][0]['faculty_user_id'] = $otherFaculty->id;

                return $result;
            },
            'room_not_suitable' => function (array $result) use ($badRoom): array {
                $result['assignments'][0]['room_id'] = $badRoom->id;

                return $result;
            },
            'fixed_day_mismatch' => function (array $result): array {
                $result['__snapshot']['scheduling_demands'][0]['fixed_day_of_week'] = 2;

                return $result;
            },
            'calendar_block_overlap' => function (array $result): array {
                $result['__snapshot']['calendar_blocks'][] = [
                    'calendar_event_id' => 771,
                    'scope_type' => 'INSTITUTION',
                    'room_id' => null,
                    'faculty_user_id' => null,
                    'day_of_week' => 1,
                    'starts_at' => '09:00:00',
                    'ends_at' => '11:00:00',
                ];

                return $result;
            },
        ];

        foreach ($cases as $expectedCode => $mutate) {
            $result = $valid;
            $result['__snapshot'] = $context['snapshot'];
            $mutated = $mutate($result);
            $context['run']->forceFill(['input_snapshot' => $mutated['__snapshot']])->save();
            unset($mutated['__snapshot']);

            if ($expectedCode === 'room_not_suitable') {
                $snapshot = $context['run']->getAttribute('input_snapshot');
                $snapshot['rooms'][] = $badRoomPayload;
                $context['run']->forceFill(['input_snapshot' => $snapshot])->save();
            }

            $validation = app(ScheduleAssignmentValidationService::class)
                ->validate($context['run']->fresh(), $mutated);

            $this->assertFalse($validation->passes(), $expectedCode);
            $this->assertContains($expectedCode, collect($validation->findings())->pluck('code')->all());
            $context['run']->forceFill(['input_snapshot' => $context['snapshot']])->save();
        }
    }

    public function test_overlap_same_faculty_and_deduplicated_load_rules_are_enforced(): void
    {
        $context = $this->context(demandCount: 2);
        $valid = $this->validResult($context);
        $validation = app(ScheduleAssignmentValidationService::class)
            ->validate($context['run'], $valid);

        $this->assertTrue(
            $validation->passes(),
            'Linked components should count one offering/group load once: '.json_encode($validation->findings(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        $overlap = $valid;
        $overlap['assignments'][1]['day'] = 1;
        $overlap['assignments'][1]['day_of_week'] = 1;
        $overlap['assignments'][1]['start_time'] = '08:00:00';
        $overlap['assignments'][1]['starts_at'] = '08:00:00';
        $overlap['assignments'][1]['end_time'] = '10:00:00';
        $overlap['assignments'][1]['ends_at'] = '10:00:00';
        $overlap['assignments'][1]['time_slot_id'] = 1;
        $overlap['assignments'][1]['time_block_reference'] = 'D1-0800';
        $overlap['assignments'][1]['time_block_key'] = 'D1-0800';
        $overlapValidation = app(ScheduleAssignmentValidationService::class)
            ->validate($context['run'], $overlap);
        $overlapCodes = collect($overlapValidation->findings())->pluck('code')->all();

        $this->assertContains('faculty_overlap', $overlapCodes);
        $this->assertContains('room_overlap', $overlapCodes);
        $this->assertContains('delivery_group_overlap', $overlapCodes);

        $otherFaculty = User::factory()->create(['status' => User::StatusActive]);
        $snapshot = $context['snapshot'];
        $snapshot['scheduling_demands'][0]['same_faculty_required'] = true;
        $snapshot['scheduling_demands'][1]['same_faculty_required'] = true;
        $snapshot['scheduling_demands'][1]['eligible_faculty_user_ids'][] = $otherFaculty->id;
        $snapshot['faculty'][] = ['faculty_id' => $otherFaculty->id, 'max_allowed_units' => '3.00'];
        $context['run']->forceFill(['input_snapshot' => $snapshot])->save();
        $differentFaculty = $valid;
        $differentFaculty['assignments'][1]['faculty_id'] = $otherFaculty->id;
        $differentFaculty['assignments'][1]['faculty_user_id'] = $otherFaculty->id;
        $sameFacultyValidation = app(ScheduleAssignmentValidationService::class)
            ->validate($context['run']->fresh(), $differentFaculty);

        $this->assertContains('same_faculty_mismatch', collect($sameFacultyValidation->findings())->pluck('code')->all());

        $snapshot = $context['snapshot'];
        $snapshot['scheduling_demands'][1]['term_offering_id'] += 1000;
        $snapshot['scheduling_demands'][1]['section_delivery_group_id'] += 1000;
        $context['run']->forceFill(['input_snapshot' => $snapshot])->save();
        $excessLoad = $valid;
        $excessLoad['assignments'][1]['term_offering_id'] = $snapshot['scheduling_demands'][1]['term_offering_id'];
        $excessLoad['assignments'][1]['section_delivery_group_id'] = $snapshot['scheduling_demands'][1]['section_delivery_group_id'];
        $loadValidation = app(ScheduleAssignmentValidationService::class)
            ->validate($context['run']->fresh(), $excessLoad);

        $this->assertContains('faculty_load_exceeded', collect($loadValidation->findings())->pluck('code')->all());
    }

    public function test_shared_cohort_overlap_is_rejected_across_course_specific_delivery_groups(): void
    {
        $context = $this->context(demandCount: 2);
        $snapshot = $context['snapshot'];
        $sharedCohortId = $snapshot['scheduling_demands'][0]['section_delivery_group_id'];
        $snapshot['scheduling_demands'][0]['cohort_or_student_group_id'] = $sharedCohortId;
        $snapshot['scheduling_demands'][1]['section_delivery_group_id'] += 1000;
        $snapshot['scheduling_demands'][1]['cohort_or_student_group_id'] = $sharedCohortId;
        $snapshot['student_cohort_groups'] = collect($snapshot['scheduling_demands'])
            ->map(fn (array $demand): array => [
                'cohort_or_student_group_id' => $sharedCohortId,
                'section_delivery_group_id' => $demand['section_delivery_group_id'],
                'expected_count' => $demand['expected_count'],
            ])
            ->all();
        $context['snapshot'] = $snapshot;
        $context['run']->forceFill(['input_snapshot' => $snapshot])->save();

        $result = $this->validResult($context);
        $result['assignments'][1]['day'] = 1;
        $result['assignments'][1]['day_of_week'] = 1;
        $result['assignments'][1]['start_time'] = '08:00:00';
        $result['assignments'][1]['starts_at'] = '08:00:00';
        $result['assignments'][1]['end_time'] = '10:00:00';
        $result['assignments'][1]['ends_at'] = '10:00:00';
        $result['assignments'][1]['time_slot_id'] = 1;
        $result['assignments'][1]['time_block_reference'] = 'D1-0800';
        $result['assignments'][1]['time_block_key'] = 'D1-0800';

        foreach ($result['assignments'] as $index => $assignment) {
            $result['assignments'][$index]['cohort_or_student_group_id'] = $sharedCohortId;
        }

        $validation = app(ScheduleAssignmentValidationService::class)
            ->validate($context['run']->fresh(), $result);

        $this->assertContains('cohort_overlap', collect($validation->findings())->pluck('code')->all());
    }

    public function test_native_non_solution_statuses_are_blocking_without_candidate_writes(): void
    {
        $context = $this->context();

        foreach (['infeasible', 'model_invalid', 'unknown'] as $status) {
            $result = $this->validResult($context);
            $result['solver_status'] = $status;
            $result['objective_score'] = null;
            $result['objective_details'] = [
                'profile_key' => 'balanced_v1',
                'profile_version' => 1,
                'terms' => [],
            ];
            $result['timeout'] = $status === 'unknown';

            if ($status === 'model_invalid') {
                $result['assignments'] = [];
                $result['assigned_count'] = 0;
                $result['unassigned_count'] = 0;
            } else {
                $result['assignments'][0]['assignment_status'] = 'conflict';
                $result['assignments'][0]['violations'] = [[
                    'type' => 'solver_unassigned',
                    'message' => 'No valid assignment was produced.',
                ]];
                $result['assigned_count'] = 0;
                $result['unassigned_count'] = 1;
                $result['hard_violation_count'] = 1;
                $result['hard_constraint_violations'] = $result['assignments'][0]['violations'];
                $result['infeasible_reasons'] = $result['assignments'][0]['violations'];
            }

            $summary = app(ScheduleCloudResultIngestor::class)
                ->ingest($context['run']->fresh(), $result);

            $this->assertSame('blocked', $summary['status']);
            $this->assertSame(0, CandidateScheduleRow::query()->count());
            $this->assertContains('solver_'.$status, collect($context['run']->fresh()->getAttribute('diagnostics')['solver_result']['findings'])->pluck('code')->all());
        }
    }

    public function test_atomic_replacement_rolls_back_when_persistence_throws(): void
    {
        $context = $this->context(demandCount: 2);
        $prior = $this->candidate($context, $context['demands'][0]);
        $result = $this->validResult($context);
        $event = 'eloquent.created: '.CandidateScheduleRow::class;

        Event::listen($event, function (CandidateScheduleRow $row) use ($context): void {
            if ($row->scheduling_demand_id === $context['demands'][1]->id) {
                throw new RuntimeException('Forced candidate persistence failure.');
            }
        });

        try {
            app(ScheduleCloudResultIngestor::class)->ingest($context['run'], $result);
            $this->fail('The forced persistence failure did not occur.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced candidate persistence failure.', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertModelExists($prior);
        $this->assertSame(1, CandidateScheduleRow::query()->count());
        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $context['run']->fresh()->status);
    }

    public function test_schedule_run_view_renders_structured_source_linked_findings(): void
    {
        $context = $this->context();
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $context['run']->forceFill([
            'diagnostics' => [
                'solver_result' => [
                    'solver_status' => 'blocked',
                    'summary' => [
                        'assigned_count' => 0,
                        'unassigned_count' => 1,
                        'hard_violation_count' => 1,
                        'warning_count' => 0,
                    ],
                    'findings' => [[
                        'code' => 'faculty_not_eligible',
                        'severity' => 'blocking',
                        'constraint' => 'respect_faculty_qualification_and_load',
                        'message' => 'Assigned faculty is not eligible.',
                        'scheduling_demand_id' => $context['demands'][0]->id,
                        'meeting_sequence' => 1,
                        'source_type' => 'scheduling_demand',
                        'source_id' => $context['demands'][0]->id,
                        'source_field' => 'eligible_faculty_user_ids',
                    ]],
                ],
            ],
        ])->save();

        Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $context['run']->getRouteKey()])
            ->assertOk()
            ->assertSee('Assigned faculty is not eligible.')
            ->assertSee('Scheduling Demand #'.$context['demands'][0]->id)
            ->assertSeeHtml('href="'.SchedulingDemandResource::getUrl('view', ['record' => $context['demands'][0]]).'"');
    }

    /**
     * @return array{run:ScheduleGenerationRun,term:Term,faculty:User,room:Room,demands:list<SchedulingDemand>,snapshot:array<string,mixed>}
     */
    private function context(int $demandCount = 1): array
    {
        $term = Term::factory()->create([
            'scheduling_days' => [1, 2, 3, 4, 5],
            'scheduling_day_starts_at' => '08:00:00',
            'scheduling_day_ends_at' => '18:00:00',
            'scheduling_slot_minutes' => 60,
            'default_max_units' => 3.00,
        ]);
        $offering = TermOffering::factory()->for($term)->create([
            'modality' => TermOffering::ModalityFaceToFace,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create(['capacity' => 30]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'expected_count' => 30,
            'modality' => TermOffering::ModalityFaceToFace,
        ]);
        $faculty = User::factory()->create(['status' => User::StatusActive]);
        $faculty->assignRole(User::StaffRoleFaculty);
        $room = Room::factory()->create([
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);
        $demands = [];
        $demandPayloads = [];

        for ($index = 0; $index < $demandCount; $index++) {
            $component = CourseComponent::factory()->create([
                'weekly_contact_hours' => 2.00,
                'room_type_default' => Room::TypeLectureRoom,
            ]);
            $component->load('courseSpecification.course');
            $course = $component->courseSpecification->course;
            $qualification = FacultyQualification::factory()
                ->for($faculty, 'faculty')
                ->for($course)
                ->create(['is_active' => true]);
            $demand = SchedulingDemand::factory()
                ->for($offering)
                ->for($component)
                ->for($group)
                ->create([
                    'required_duration_minutes' => 120,
                    'meeting_count' => 1,
                    'modality' => TermOffering::ModalityFaceToFace,
                    'validation_state' => SchedulingDemand::ValidationReadyForReview,
                ]);
            $demands[] = $demand;
            $demandPayloads[] = [
                'scheduling_demand_id' => $demand->id,
                'demand_key' => $demand->demand_key,
                'term_offering_id' => $offering->id,
                'section_id' => $section->id,
                'section_delivery_group_id' => $group->id,
                'course_id' => $course->id,
                'course_component_id' => $component->id,
                'required_duration_minutes' => 120,
                'meeting_count' => 1,
                'modality' => TermOffering::ModalityFaceToFace,
                'expected_count' => 30,
                'section_capacity' => 30,
                'room_type_requirement' => Room::TypeLectureRoom,
                'required_room_feature_keys' => [],
                'load_units' => '3.00',
                'room_required' => true,
                'same_faculty_required' => false,
                'requires_consecutive_block' => true,
                'eligible_faculty_user_ids' => [$faculty->id],
                'faculty_load_options' => [[
                    'faculty_user_id' => $faculty->id,
                    'qualification_id' => $qualification->id,
                    'term_load_override_id' => null,
                    'max_allowed_units' => '3.00',
                ]],
                'fixed_faculty_user_id' => null,
                'fixed_room_id' => null,
                'fixed_day_of_week' => null,
                'fixed_start_time' => null,
            ];
        }

        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'requested_by' => null,
            'input_snapshot' => [],
            'input_hash' => hash('sha256', (string) Str::uuid()),
            'solver_version' => 'pending',
        ]);
        $timeSlots = [];
        $slotId = 1;

        foreach ([1, 2, 3, 4, 5] as $day) {
            foreach (['08:00:00', '09:00:00', '10:00:00', '11:00:00', '12:00:00'] as $startsAt) {
                $hour = (int) substr($startsAt, 0, 2);
                $timeSlots[] = [
                    'time_slot_id' => $slotId++,
                    'time_block_key' => sprintf('D%d-%02d00', $day, $hour),
                    'day_of_week' => $day,
                    'starts_at' => $startsAt,
                    'ends_at' => sprintf('%02d:00:00', $hour + 1),
                    'duration_minutes' => 60,
                ];
            }
        }
        $snapshot = [
            'contract_version' => 'tal94-demand-v2',
            'run_metadata' => [
                'solver_run_id' => $run->id,
                'term_id' => $term->id,
                'timezone' => config('app.timezone'),
            ],
            'term' => [
                'term_id' => $term->id,
                'scheduling_slot_minutes' => 60,
                'scheduling_days' => [1, 2, 3, 4, 5],
                'scheduling_day_starts_at' => '08:00:00',
                'scheduling_day_ends_at' => '18:00:00',
                'default_max_units' => '3.00',
            ],
            'time_slots' => $timeSlots,
            'scheduling_demands' => $demandPayloads,
            'rooms' => [[
                'room_id' => $room->id,
                'room_type' => $room->room_type,
                'capacity' => $room->capacity,
                'feature_keys' => [],
            ]],
            'faculty' => [[
                'faculty_id' => $faculty->id,
                'max_allowed_units' => '3.00',
            ]],
            'calendar_blocks' => [],
            'constraint_profile' => [
                'key' => 'balanced_v1',
                'version' => 1,
                'soft_weights' => [
                    'prefer_earlier_time_blocks' => 1,
                    'reduce_faculty_idle_gaps' => 1,
                    'balance_faculty_load' => 1,
                    'use_rooms_efficiently' => 1,
                ],
            ],
        ];
        $run->forceFill(['input_snapshot' => $snapshot])->save();

        return compact('run', 'term', 'faculty', 'room', 'demands', 'snapshot');
    }

    /**
     * @param  array{run:ScheduleGenerationRun,term:Term,faculty:User,room:Room,demands:list<SchedulingDemand>,snapshot:array<string,mixed>}  $context
     * @return array<string,mixed>
     */
    private function validResult(array $context): array
    {
        $assignments = [];

        foreach ($context['snapshot']['scheduling_demands'] as $index => $demand) {
            $startsAt = $index === 0 ? '08:00:00' : '10:00:00';
            $endsAt = $index === 0 ? '10:00:00' : '12:00:00';
            $timeSlotId = $index === 0 ? 1 : 3;
            $timeBlockKey = $index === 0 ? 'D1-0800' : 'D1-1000';
            $assignments[] = [
                'scheduling_demand_id' => $demand['scheduling_demand_id'],
                'term_offering_id' => $demand['term_offering_id'],
                'section_id' => $demand['section_id'],
                'section_delivery_group_id' => $demand['section_delivery_group_id'],
                'subject_id' => $demand['course_id'],
                'course_component_id' => $demand['course_component_id'],
                'faculty_id' => $context['faculty']->id,
                'faculty_user_id' => $context['faculty']->id,
                'room_id' => $context['room']->id,
                'day' => 1,
                'day_of_week' => 1,
                'start_time' => $startsAt,
                'end_time' => $endsAt,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'time_slot_id' => $timeSlotId,
                'time_block_reference' => $timeBlockKey,
                'time_block_key' => $timeBlockKey,
                'meeting_sequence' => 1,
                'meeting_pattern' => 'single_block',
                'assignment_status' => 'ok',
                'violations' => [],
                'warnings' => [],
                'scores' => ['priority' => 1],
                'soft_constraint_scores' => ['priority' => 1],
            ];
        }

        return [
            'solver_run_id' => $context['run']->id,
            'solver_status' => 'optimal',
            'candidate_schedule_id' => 'cp-sat-'.$context['run']->id,
            'assignments' => $assignments,
            'hard_constraint_violations' => [],
            'hard_violation_count' => 0,
            'soft_constraint_scores' => ['assigned_count' => count($assignments)],
            'infeasible_reasons' => [],
            'warnings' => [],
            'runtime_seconds' => 0.25,
            'objective_score' => 4,
            'objective_details' => [
                'profile_key' => 'balanced_v1',
                'profile_version' => 1,
                'terms' => [
                    'prefer_earlier_time_blocks' => ['raw' => 1, 'weight' => 1, 'weighted' => 1],
                    'reduce_faculty_idle_gaps' => ['raw' => 1, 'weight' => 1, 'weighted' => 1],
                    'balance_faculty_load' => ['raw' => 1, 'weight' => 1, 'weighted' => 1],
                    'use_rooms_efficiently' => ['raw' => 1, 'weight' => 1, 'weighted' => 1],
                ],
                'total' => 4,
            ],
            'solver_statistics' => [
                'ortools_version' => '9.15.6755',
                'input_demand_count' => count($assignments),
                'input_faculty_count' => 1,
                'input_room_count' => 1,
                'input_time_slot_count' => count($context['snapshot']['time_slots']),
                'candidate_count' => count($assignments),
                'model_variable_count' => count($assignments),
                'model_constraint_count' => count($assignments),
                'no_overlap_constraint_count' => 0,
                'best_objective_bound' => 4.0,
                'relative_optimality_gap' => 0.0,
                'boolean_variable_count' => count($assignments),
                'branch_count' => 0,
                'conflict_count' => 0,
                'deterministic_time_seconds' => 0.01,
                'wall_time_seconds' => 0.02,
                'worker_count' => 1,
                'random_seed' => 20260718,
            ],
            'solver_version' => 'cloud-cp-sat-tal94-demand-v2',
            'model_version' => 'tal94-demand-v2',
            'generated_at' => now()->toIso8601String(),
            'assigned_count' => count($assignments),
            'unassigned_count' => 0,
            'warning_count' => 0,
            'timeout' => false,
        ];
    }

    /**
     * @param  array{run:ScheduleGenerationRun,faculty:User,room:Room}  $context
     */
    private function candidate(array $context, SchedulingDemand $demand): CandidateScheduleRow
    {
        return CandidateScheduleRow::query()->create([
            'schedule_run_id' => $context['run']->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $context['faculty']->id,
            'room_id' => $context['room']->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'time_block_key' => 'D1-0800',
            'status' => CandidateScheduleRow::StatusOk,
            'scores' => [],
            'warnings' => [],
            'violations' => [],
        ]);
    }
}
