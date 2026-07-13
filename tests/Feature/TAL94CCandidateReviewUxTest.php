<?php

namespace Tests\Feature;

use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ViewScheduleGenerationRun;
use App\Filament\Resources\ScheduleGenerationRuns\RelationManagers\CandidateRowsRelationManager;
use App\Models\CandidateScheduleRow;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\FacultyQualification;
use App\Models\Program;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL94CCandidateReviewUxTest extends TestCase
{
    use DatabaseTransactions;

    private int $contextCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotContains(DB::connection()->getDatabaseName(), [
            'tala_db',
            'demo_tala_db',
            'tala_test_codex',
        ]);

        foreach (User::staffRoleNames() as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_candidate_review_table_and_mutation_controls_follow_role_boundaries(): void
    {
        $context = $this->context();
        $candidate = $this->candidate($context);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $faculty = $this->staff(User::StaffRoleFaculty);

        Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $context['run']->getRouteKey()])
            ->assertOk()
            ->assertActionVisible('manualScheduleOverride');

        Livewire::actingAs($registrar)
            ->test(CandidateRowsRelationManager::class, [
                'ownerRecord' => $context['run'],
                'pageClass' => ViewScheduleGenerationRun::class,
            ])
            ->assertCanSeeTableRecords([$candidate])
            ->assertTableColumnExists('schedulingDemand.courseComponent.courseSpecification.course.code')
            ->assertTableColumnExists('schedulingDemand.sectionDeliveryGroup.section.code')
            ->assertTableColumnExists('time_range')
            ->assertTableActionVisible('correctAssignment', $candidate);

        Livewire::actingAs($academicHead)
            ->test(ViewScheduleGenerationRun::class, ['record' => $context['run']->getRouteKey()])
            ->assertOk()
            ->assertActionHidden('manualScheduleOverride');

        Livewire::actingAs($academicHead)
            ->test(CandidateRowsRelationManager::class, [
                'ownerRecord' => $context['run'],
                'pageClass' => ViewScheduleGenerationRun::class,
            ])
            ->assertCanSeeTableRecords([$candidate])
            ->assertTableActionHidden('correctAssignment', $candidate);

        $this->assertTrue(Gate::forUser($superAdmin)->allows('reviewCandidates', $context['run']));
        $this->assertFalse(Gate::forUser($academicHead)->allows('reviewCandidates', $context['run']));
        $this->assertFalse(Gate::forUser($accounting)->allows('reviewCandidates', $context['run']));
        $this->assertFalse(Gate::forUser($faculty)->allows('reviewCandidates', $context['run']));
    }

    public function test_registrar_can_submit_a_valid_candidate_correction_through_the_relation_action(): void
    {
        $context = $this->context();
        $candidate = $this->candidate($context);
        $registrar = $this->staff(User::StaffRoleRegistrar);

        Livewire::actingAs($registrar)
            ->test(CandidateRowsRelationManager::class, [
                'ownerRecord' => $context['run'],
                'pageClass' => ViewScheduleGenerationRun::class,
            ])
            ->callTableAction('correctAssignment', $candidate, data: [
                'faculty_user_id' => $context['faculty']->id,
                'room_id' => $context['room']->id,
                'day_of_week' => 1,
                'starts_at' => '09:00:00',
                'ends_at' => '12:00:00',
                'override_authority' => 'Registrar scheduling memorandum',
                'override_reason' => 'Corrected the start time after faculty confirmation.',
            ])
            ->assertNotified('Candidate assignment corrected');

        $corrected = $context['run']->candidateRows()->sole();
        $diagnostics = $context['run']->fresh()->getAttribute('diagnostics');

        $this->assertSame('09:00:00', $corrected->starts_at);
        $this->assertSame('Registrar scheduling memorandum', $corrected->override_authority);
        $this->assertSame(CandidateScheduleRow::StatusWarning, $corrected->status);
        $this->assertSame('accepted', $diagnostics['current_revalidation']['status']);
        $this->assertSame('original-result-preserved', $diagnostics['solver_result']['marker']);
        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $context['run']->id,
            'event' => 'candidate_correction',
            'causer_id' => $registrar->id,
        ]);
    }

    public function test_invalid_candidate_correction_is_reported_and_preserves_the_original_row(): void
    {
        $context = $this->context();
        $candidate = $this->candidate($context);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $context['qualification']->update(['is_active' => false]);

        Livewire::actingAs($registrar)
            ->test(CandidateRowsRelationManager::class, [
                'ownerRecord' => $context['run'],
                'pageClass' => ViewScheduleGenerationRun::class,
            ])
            ->callTableAction('correctAssignment', $candidate, data: [
                'faculty_user_id' => $context['faculty']->id,
                'room_id' => $context['room']->id,
                'day_of_week' => 1,
                'starts_at' => '09:00:00',
                'ends_at' => '12:00:00',
                'override_authority' => 'Registrar scheduling memorandum',
                'override_reason' => 'This invalid proposal must remain atomic.',
            ])
            ->assertNotified('Candidate correction blocked');

        $preserved = CandidateScheduleRow::query()->sole();
        $diagnostics = $context['run']->fresh()->getAttribute('diagnostics');

        $this->assertSame($candidate->id, $preserved->id);
        $this->assertSame('08:00:00', $preserved->starts_at);
        $this->assertSame('blocked', $diagnostics['current_revalidation']['status']);
        $this->assertContains(
            'faculty_not_eligible',
            collect($diagnostics['current_revalidation']['findings'])->pluck('code')->all(),
        );
        $this->assertDatabaseMissing('activity_log', [
            'subject_id' => $context['run']->id,
            'event' => 'candidate_correction',
        ]);
    }

    public function test_manual_override_action_requires_a_complete_valid_set_and_records_evidence_atomically(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $incomplete = $this->context(demandCount: 2, runStatus: ScheduleGenerationRun::StatusBlocked);

        $incompleteComponent = Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $incomplete['run']->getRouteKey()])
            ->assertActionVisible('manualScheduleOverride')
            ->mountAction('manualScheduleOverride');
        $incompleteAssignments = $incompleteComponent->get('mountedActions.0.data.assignments');
        $incompleteKeys = array_keys($incompleteAssignments);
        $firstLabel = $incompleteAssignments[$incompleteKeys[0]]['assignment_label'];

        $this->assertStringStartsWith('CC', $firstLabel);
        $this->assertStringContainsString('Lecture | Meeting 1', $firstLabel);
        $this->assertStringNotContainsString('L E C T U R E', $firstLabel);

        $incompleteAssignments[$incompleteKeys[0]] = [
            ...$incompleteAssignments[$incompleteKeys[0]],
            ...$this->assignment($incomplete, 0),
        ];

        $incompleteComponent
            ->setActionData([
                'assignments' => $incompleteAssignments,
                'override_authority' => 'Registrar override memorandum',
                'override_reason' => 'This deliberately incomplete proposal must be rejected.',
            ])
            ->callMountedAction()
            ->assertHasActionErrors();

        $blockedRun = $incomplete['run']->fresh();

        $this->assertSame(ScheduleGenerationRun::StatusBlocked, $blockedRun->status);
        $this->assertSame(0, $incomplete['run']->candidateRows()->count());
        $this->assertArrayNotHasKey('current_revalidation', $blockedRun->diagnostics);

        $complete = $this->context(demandCount: 2, runStatus: ScheduleGenerationRun::StatusFailed);

        $completeComponent = Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $complete['run']->getRouteKey()])
            ->mountAction('manualScheduleOverride');
        $completeAssignments = $completeComponent->get('mountedActions.0.data.assignments');

        foreach (array_keys($completeAssignments) as $index => $key) {
            $completeAssignments[$key] = [
                ...$completeAssignments[$key],
                ...$this->assignment($complete, $index, dayOfWeek: $index + 1),
            ];
        }

        $completeComponent
            ->setActionData([
                'assignments' => $completeAssignments,
                'override_authority' => 'Academic Head approved override memorandum',
                'override_reason' => 'Recorded the complete feasible schedule after solver review.',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Manual Schedule Override accepted');

        $accepted = $complete['run']->fresh();

        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $accepted->status);
        $this->assertSame(2, $accepted->candidateRows()->count());
        $this->assertSame(2, $accepted->candidateRows()->whereNotNull('override_authority')->count());
        $this->assertSame('accepted', $accepted->diagnostics['current_revalidation']['status']);
        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $accepted->id,
            'event' => 'manual_schedule_override',
            'causer_id' => $registrar->id,
        ]);
    }

    public function test_conflicting_complete_override_is_blocked_with_structured_findings_and_no_partial_rows(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $context = $this->context(demandCount: 2, runStatus: ScheduleGenerationRun::StatusBlocked);
        $component = Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $context['run']->getRouteKey()])
            ->mountAction('manualScheduleOverride');
        $assignments = $component->get('mountedActions.0.data.assignments');

        foreach (array_keys($assignments) as $index => $key) {
            $assignments[$key] = [
                ...$assignments[$key],
                ...$this->assignment($context, $index),
            ];
        }

        $component
            ->setActionData([
                'assignments' => $assignments,
                'override_authority' => 'Registrar override memorandum',
                'override_reason' => 'This overlapping complete set must fail atomically.',
            ])
            ->callMountedAction()
            ->assertNotified('Manual Schedule Override blocked');

        $blocked = $context['run']->fresh();
        $codes = collect($blocked->diagnostics['current_revalidation']['findings'])->pluck('code')->all();

        $this->assertSame(ScheduleGenerationRun::StatusBlocked, $blocked->status);
        $this->assertSame(0, $blocked->candidateRows()->count());
        $this->assertContains('faculty_overlap', $codes);
        $this->assertContains('room_overlap', $codes);
        $this->assertDatabaseMissing('activity_log', [
            'subject_id' => $blocked->id,
            'event' => 'manual_schedule_override',
        ]);
    }

    public function test_run_view_prioritizes_latest_validation_and_keeps_original_solver_provenance_without_raw_json(): void
    {
        $context = $this->context();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $context['run']->update([
            'objective_value' => 42.5,
            'diagnostics' => [
                'solver_result' => [
                    'solver_status' => 'optimal',
                    'summary' => [
                        'assigned_count' => 1,
                        'unassigned_count' => 0,
                        'hard_violation_count' => 0,
                        'warning_count' => 0,
                    ],
                    'findings' => [[
                        'code' => 'old_solver_finding',
                        'severity' => 'advisory',
                        'constraint' => 'original_solver_result',
                        'message' => 'Old solver finding is not the latest validation truth.',
                    ]],
                    'raw_private_marker' => 'RAW_JSON_MUST_NOT_RENDER',
                ],
                'current_revalidation' => [
                    'context' => 'candidate_correction',
                    'status' => 'blocked',
                    'validated_at' => now()->toIso8601String(),
                    'summary' => [
                        'assigned_count' => 0,
                        'unassigned_count' => 1,
                        'hard_violation_count' => 1,
                        'warning_count' => 0,
                    ],
                    'findings' => [[
                        'code' => 'room_not_suitable',
                        'severity' => 'blocking',
                        'constraint' => 'room_suitability',
                        'message' => 'Current room capacity no longer satisfies the demand.',
                        'source_type' => 'room',
                        'source_id' => $context['room']->id,
                        'source_field' => 'capacity',
                    ]],
                ],
            ],
        ]);
        $roomUrl = RoomResource::getUrl('view', ['record' => $context['room']]);

        Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $context['run']->getRouteKey()])
            ->assertOk()
            ->assertSee('Current Validation')
            ->assertSee('Original Solver Score')
            ->assertSee('Original Solver Result')
            ->assertSee('Current room capacity no longer satisfies the demand.')
            ->assertSee($roomUrl, escape: false)
            ->assertDontSee('Old solver finding is not the latest validation truth.')
            ->assertDontSee('RAW_JSON_MUST_NOT_RENDER');
    }

    /**
     * @return array<string, mixed>
     */
    private function context(
        int $demandCount = 1,
        string $runStatus = ScheduleGenerationRun::StatusUnderReview,
    ): array {
        $this->contextCounter++;
        $term = Term::factory()->create([
            'label' => 'TAL-94C Term '.$this->contextCounter,
            'state' => Term::StateActive,
            'default_max_units' => 21.00,
            'scheduling_days' => [1, 2, 3, 4, 5, 6],
            'scheduling_slot_minutes' => 30,
        ]);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $room = Room::factory()->create([
            'code' => 'C-R'.$this->contextCounter,
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);
        $demands = [];
        $qualifications = [];

        for ($index = 0; $index < $demandCount; $index++) {
            $suffix = $this->contextCounter.'-'.$index;
            $program = Program::factory()->create(['code' => 'C'.$this->contextCounter.$index]);
            $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
            $course = Course::factory()->create(['code' => 'CC'.$suffix]);
            $specification = CourseSpecification::factory()->for($course)->create([
                'state' => CourseSpecification::StateActive,
                'allowed_modalities' => [TermOffering::ModalityFaceToFace],
                'credit_units' => 3.00,
                'same_faculty_default' => false,
            ]);
            $component = CourseComponent::factory()->for($specification)->create([
                'component_type' => CourseComponent::TypeLecture,
                'weekly_contact_hours' => 3.00,
                'room_type_default' => Room::TypeLectureRoom,
                'required_room_feature_keys' => [],
                'requires_consecutive_block' => false,
                'sequence' => 1,
            ]);
            $entry = CurriculumEntry::factory()->for($curriculum)->for($specification, 'courseSpecification')->create([
                'term_type' => $term->type,
                'term_label' => $term->label,
                'sequence' => 1,
            ]);
            $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
                'modality' => TermOffering::ModalityFaceToFace,
                'state' => TermOffering::StateScheduled,
                'expected_count' => 30,
            ]);
            $section = Section::factory()->for($offering, 'termOffering')->create([
                'code' => 'C-S'.$suffix,
                'capacity' => 30,
                'state' => Section::StateOpen,
            ]);
            $group = SectionDeliveryGroup::factory()->for($section)->create([
                'name' => 'TAL-94C Group '.$suffix,
                'expected_count' => 30,
                'modality' => TermOffering::ModalityFaceToFace,
                'state' => SectionDeliveryGroup::StateReady,
            ]);
            $qualifications[] = FacultyQualification::factory()->for($faculty, 'faculty')->for($course)->create([
                'is_active' => true,
            ]);
            $demands[] = SchedulingDemand::factory()
                ->for($offering)
                ->for($component)
                ->for($group)
                ->create([
                    'required_duration_minutes' => 180,
                    'meeting_count' => 1,
                    'modality' => TermOffering::ModalityFaceToFace,
                    'validation_state' => SchedulingDemand::ValidationReadyForReview,
                ]);
        }

        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => $runStatus,
            'requested_by' => null,
            'input_snapshot' => [
                'scheduling_demands' => collect($demands)
                    ->map(fn (SchedulingDemand $demand): array => ['scheduling_demand_id' => $demand->id])
                    ->all(),
            ],
            'input_hash' => hash('sha256', (string) Str::uuid()),
            'solver_version' => 'tal94c-test-solver',
            'model_version' => 'tal94-demand-v2',
            'diagnostics' => [
                'solver_result' => [
                    'marker' => 'original-result-preserved',
                    'solver_status' => 'optimal',
                    'summary' => [
                        'assigned_count' => $demandCount,
                        'unassigned_count' => 0,
                        'hard_violation_count' => 0,
                        'warning_count' => 0,
                    ],
                    'findings' => [],
                ],
            ],
        ]);

        return [
            'term' => $term,
            'faculty' => $faculty,
            'room' => $room,
            'demands' => $demands,
            'qualification' => $qualifications[0],
            'run' => $run,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function assignment(array $context, int $index, int $dayOfWeek = 1): array
    {
        return [
            'scheduling_demand_id' => $context['demands'][$index]->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $context['faculty']->id,
            'room_id' => $context['room']->id,
            'day_of_week' => $dayOfWeek,
            'starts_at' => '08:00:00',
            'ends_at' => '11:00:00',
            'time_block_key' => 'D'.$dayOfWeek.'-0800',
        ];
    }

    /** @param array<string, mixed> $context */
    private function candidate(array $context): CandidateScheduleRow
    {
        return CandidateScheduleRow::query()->create([
            'schedule_run_id' => $context['run']->id,
            ...$this->assignment($context, 0),
            'status' => CandidateScheduleRow::StatusOk,
            'scores' => ['objective' => 42.5],
            'warnings' => [],
            'violations' => [],
        ]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
