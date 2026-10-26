<?php

namespace Tests\Feature;

use App\Actions\Scheduling\CandidateScheduleRowReviewService;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ViewScheduleGenerationRun;
use App\Filament\Resources\ScheduleGenerationRuns\RelationManagers\CandidateRowsRelationManager;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
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
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL94CCandidateReviewUxTest extends TestCase
{
    use DatabaseTransactions;

    private int $contextCounter = 0;

    public function test_completed_generation_reveals_its_meetings_when_the_open_run_refreshes(): void
    {
        $context = $this->context(runStatus: ScheduleGenerationRun::StatusQueued);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $specification = $context['demands'][0]->courseComponent->courseSpecification;
        $specification->update(['title' => 'Meeting available after generation']);

        $page = Livewire::actingAs($registrar)->test(ViewScheduleGenerationRun::class, [
            'record' => $context['run']->id,
        ])->assertSee('Waiting for the timetable worker')
            ->assertDontSee('Meeting available after generation');

        $context['run']->update(['status' => ScheduleGenerationRun::StatusUnderReview]);
        $this->candidate($context);

        $page->call('refreshScheduleRun')
            ->assertDontSee('Waiting for the timetable worker')
            ->assertSee('Meeting available after generation');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame(env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());
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
            ->assertDontSee('role="tablist"', escape: false)
            ->assertSee('Meetings to review')
            ->assertSee('Term revision history')
            ->assertActionDoesNotExist('manualScheduleOverride');

        Livewire::actingAs($registrar)
            ->test(CandidateRowsRelationManager::class, [
                'ownerRecord' => $context['run'],
                'pageClass' => ViewScheduleGenerationRun::class,
            ])
            ->assertCanSeeTableRecords([$candidate])
            ->assertSee($context['demands'][0]->sectionDeliveryGroup->section->code)
            ->assertSee('Lecture')->assertSee('Face-to-Face')
            ->assertSee('Monday')->assertSee('8:00 AM - 11:00 AM')
            ->assertTableActionVisible('correctAssignment', $candidate)
            ->searchTable($candidate->schedulingDemand->courseComponent->courseSpecification->title)
            ->assertCanSeeTableRecords([$candidate])
            ->searchTable($candidate->schedulingDemand->courseComponent->courseSpecification->course->code)
            ->assertCanSeeTableRecords([$candidate])
            ->searchTable($candidate->schedulingDemand->sectionDeliveryGroup->section->code)
            ->assertCanSeeTableRecords([$candidate])
            ->searchTable($candidate->faculty->name)
            ->assertCanSeeTableRecords([$candidate])
            ->searchTable($candidate->room->code)
            ->assertCanSeeTableRecords([$candidate])
            ->searchTable('no-matching-candidate-in-this-run')
            ->assertCanNotSeeTableRecords([$candidate]);

        Livewire::actingAs($academicHead)
            ->test(ViewScheduleGenerationRun::class, ['record' => $context['run']->getRouteKey()])
            ->assertOk()
            ->assertDontSee('role="tablist"', escape: false)
            ->assertSee('Meetings to review')
            ->assertSee('Term revision history')
            ->assertActionDoesNotExist('manualScheduleOverride');

        Livewire::actingAs($academicHead)
            ->test(CandidateRowsRelationManager::class, [
                'ownerRecord' => $context['run'],
                'pageClass' => ViewScheduleGenerationRun::class,
            ])
            ->assertCanSeeTableRecords([$candidate])
            ->assertTableActionHidden('correctAssignment', $candidate);

        $this->assertFalse(Gate::forUser($superAdmin)->allows('reviewCandidates', $context['run']));
        $this->assertFalse(Gate::forUser($academicHead)->allows('reviewCandidates', $context['run']));
        $this->assertFalse(Gate::forUser($accounting)->allows('reviewCandidates', $context['run']));
        $this->assertFalse(Gate::forUser($faculty)->allows('reviewCandidates', $context['run']));
    }

    public function test_candidate_review_shows_and_filters_online_and_face_to_face_teaching_modes(): void
    {
        $context = $this->context(demandCount: 2);
        $faceToFaceCandidate = $this->candidate($context);
        $onlineDemand = $context['demands'][1];
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $onlineDemand->termOffering()->update(['modality' => TermOffering::ModalityOnline]);
        $onlineDemand->sectionDeliveryGroup()->update(['modality' => TermOffering::ModalityOnline]);
        $onlineDemand->update(['modality' => TermOffering::ModalityOnline]);

        $onlineCandidate = CandidateScheduleRow::query()->create([
            'schedule_run_id' => $context['run']->id,
            ...$this->assignment($context, 1, dayOfWeek: 2),
            'room_id' => null,
            'status' => CandidateScheduleRow::StatusOk,
            'scores' => ['objective' => 43.5],
            'warnings' => [],
            'violations' => [],
        ]);

        Livewire::actingAs($registrar)
            ->test(CandidateRowsRelationManager::class, [
                'ownerRecord' => $context['run'],
                'pageClass' => ViewScheduleGenerationRun::class,
            ])
            ->assertCanSeeTableRecords([$faceToFaceCandidate, $onlineCandidate])
            ->assertSee('Online')->assertSee('Face-to-Face')
            ->assertTableFilterExists('teaching_mode')
            ->assertSchemaComponentExists('schedulingDemand.modality', 'infolist')
            ->filterTable('teaching_mode', TermOffering::ModalityOnline)
            ->assertCanSeeTableRecords([$onlineCandidate])
            ->assertCanNotSeeTableRecords([$faceToFaceCandidate])
            ->assertSee('Online');
    }

    public function test_repair_review_compares_recorded_assignments_and_filters_changes_without_granting_head_mutations(): void
    {
        $context = $this->context(demandCount: 3);
        $head = $this->staff(User::StaffRoleAcademicHead);
        $replacementTeacher = $this->staff(User::StaffRoleFaculty);
        $replacementRoom = Room::factory()->create(['code' => 'REPAIR-NEXT']);
        $successor = $context['run']->replicate();
        $successor->input_snapshot = ['operation' => ['kind' => 'repair']];
        $successor->input_hash = hash('sha256', 'repair-preview-test');
        $successor->save();
        $rows = [];

        foreach (['RequestedRepair', 'RepairImpact', 'Unchanged'] as $index => $change) {
            $before = CandidateScheduleRow::query()->create([
                'schedule_run_id' => $context['run']->id,
                ...$this->assignment($context, $index),
                'status' => CandidateScheduleRow::StatusOk,
            ]);
            $rows[] = CandidateScheduleRow::query()->create([
                ...$before->getAttributes(),
                'id' => null,
                'schedule_run_id' => $successor->id,
                'supersedes_candidate_row_id' => $before->id,
                'change_type' => $change,
                'faculty_user_id' => $change === 'Unchanged' ? $context['faculty']->id : $replacementTeacher->id,
                'room_id' => $change === 'Unchanged' ? $context['room']->id : $replacementRoom->id,
                'day_of_week' => $change === 'Unchanged' ? 1 : 2,
                'starts_at' => $change === 'Unchanged' ? '08:00:00' : '09:00:00',
                'ends_at' => $change === 'Unchanged' ? '11:00:00' : '12:00:00',
            ]);
        }

        Livewire::actingAs($head)
            ->test(CandidateRowsRelationManager::class, ['ownerRecord' => $successor, 'pageClass' => ViewScheduleGenerationRun::class])
            ->assertSee('Requested changes: 1 · Other changes: 1 · Unchanged meetings: 1.')
            ->assertCanSeeTableRecords($rows)
            ->filterTable('changed_meetings')
            ->assertSee('Requested changes: 1 · Other changes: 1 · Unchanged meetings: 1.')
            ->assertCanSeeTableRecords([$rows[0], $rows[1]])
            ->assertCanNotSeeTableRecords([$rows[2]])
            ->assertTableActionHidden('correctAssignment', $rows[0])
            ->assertTableActionHidden('findValidRepair', $rows[0])
            ->mountTableAction('view', $rows[0])
            ->assertMountedActionModalSee('Assignment comparison')
            ->assertMountedActionModalSee('Requested repair')
            ->assertMountedActionModalSee('Before')->assertMountedActionModalSee('After')
            ->assertMountedActionModalSee('Monday · 8:00 AM - 11:00 AM · Asia/Manila')
            ->assertMountedActionModalSee('Tuesday · 9:00 AM - 12:00 PM · Asia/Manila')
            ->assertMountedActionModalSee('Teacher: '.$context['faculty']->name)
            ->assertMountedActionModalSee('Teacher: '.$replacementTeacher->name)
            ->assertMountedActionModalSee('Room: '.$context['room']->code)
            ->assertMountedActionModalSee('Room: REPAIR-NEXT')
            ->assertMountedActionModalSeeHtml(ScheduleGenerationRunResource::getUrl('view', ['record' => $context['run']->id, 'termId' => $context['term']->id]));

        $this->assertSame('08:00:00', $rows[0]->supersedes->fresh()->starts_at);
        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $successor->fresh()->status);
    }

    public function test_repair_comparison_reports_unavailable_or_mismatched_baselines_without_inventing_previous_facts(): void
    {
        $context = $this->context(demandCount: 2);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $unrelated = $this->candidate($context);
        $candidate = CandidateScheduleRow::query()->create([
            'schedule_run_id' => $context['run']->id,
            ...$this->assignment($context, 1, 2),
            'status' => CandidateScheduleRow::StatusOk,
            'change_type' => 'RepairImpact',
        ]);

        foreach ([null, $unrelated->id] as $baselineId) {
            $candidate->update(['supersedes_candidate_row_id' => $baselineId]);

            Livewire::actingAs($registrar)
                ->test(CandidateRowsRelationManager::class, ['ownerRecord' => $context['run']->fresh(), 'pageClass' => ViewScheduleGenerationRun::class])
                ->mountTableAction('view', $candidate)
                ->assertMountedActionModalSee('Source assignment unavailable')
                ->assertMountedActionModalSee('Previous assignment unavailable. Inspect the source evidence before accepting this successor.')
                ->assertMountedActionModalSee('Tuesday · 8:00 AM - 11:00 AM · Asia/Manila')
                ->assertMountedActionModalDontSee('Monday · 8:00 AM - 11:00 AM · Asia/Manila');
        }
    }

    public function test_registrar_can_submit_a_valid_candidate_correction_through_the_relation_action(): void
    {
        $context = $this->context();
        $candidate = $this->candidate($context);
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $component = Livewire::actingAs($registrar)
            ->test(CandidateRowsRelationManager::class, [
                'ownerRecord' => $context['run'],
                'pageClass' => ViewScheduleGenerationRun::class,
            ])
            ->callAction(TestAction::make('correctAssignment')->table($candidate), data: [
                'faculty_user_id' => $context['faculty']->id,
                'room_id' => $context['room']->id,
                'day_of_week' => 1,
                'starts_at' => '09:00',
                'ends_at' => '12:00',
                'override_authority' => 'Registrar scheduling memorandum',
                'override_reason' => 'Corrected the start time after faculty confirmation.',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Candidate assignment corrected');

        $successor = ScheduleGenerationRun::query()
            ->where('term_id', $context['term']->id)
            ->whereKeyNot($context['run']->id)
            ->latest('id')
            ->firstOrFail();
        $corrected = $successor->candidateRows()->sole();
        $diagnostics = $successor->diagnostics;

        $component->assertRedirect(ScheduleGenerationRunResource::getUrl('view', [
            'record' => $successor,
            'termId' => $context['term']->id,
        ]));

        $this->assertSame('09:00:00', $corrected->starts_at);
        $this->assertSame('Registrar scheduling memorandum', $corrected->override_authority);
        $this->assertSame(CandidateScheduleRow::StatusOk, $corrected->status);
        $this->assertSame(ScheduleGenerationRun::StatusSuperseded, $context['run']->fresh()->status);
        $this->assertSame('08:00:00', $candidate->fresh()->starts_at);
        $this->assertSame('accepted', $diagnostics['current_revalidation']['status']);
        $this->assertSame('original-result-preserved', $diagnostics['source_solver_evidence']['result']['marker']);
        $this->assertSame($context['run']->id, $diagnostics['source_solver_evidence']['run_id']);
        $this->assertSame('original-result-preserved', $context['run']->fresh()->diagnostics['solver_result']['marker']);
        $this->assertArrayNotHasKey('solver_result', $diagnostics);
        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $successor->id,
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

        $preserved = $context['run']->candidateRows()->sole();
        $this->assertSame($candidate->id, $preserved->id);
        $this->assertSame('08:00:00', $preserved->starts_at);
        $this->assertArrayNotHasKey('current_revalidation', $context['run']->fresh()->diagnostics);
        $this->assertDatabaseMissing('activity_log', [
            'subject_id' => $context['run']->id,
            'event' => 'candidate_correction',
        ]);
    }

    public function test_generic_manual_override_action_is_retired_without_mutating_blocked_or_failed_runs(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $blocked = $this->context(demandCount: 2, runStatus: ScheduleGenerationRun::StatusBlocked);
        $failed = $this->context(demandCount: 2, runStatus: ScheduleGenerationRun::StatusFailed);

        foreach ([$blocked, $failed] as $context) {
            Livewire::actingAs($registrar)
                ->test(ViewScheduleGenerationRun::class, ['record' => $context['run']->getRouteKey()])
                ->assertOk()
                ->assertActionDoesNotExist('manualScheduleOverride');

            $this->assertSame(0, $context['run']->candidateRows()->count());
            $this->assertDatabaseMissing('activity_log', [
                'subject_id' => $context['run']->id,
                'event' => 'manual_schedule_override',
            ]);
        }
    }

    public function test_retired_manual_override_service_rejects_calls_without_writing_rows(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $context = $this->context(demandCount: 2, runStatus: ScheduleGenerationRun::StatusBlocked);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Generic Manual Schedule Override is retired.');

        try {
            app(CandidateScheduleRowReviewService::class)->replace(
                $context['run'],
                [$this->assignment($context, 0)],
                $registrar,
                'Registrar override memorandum',
                'The obsolete whole-set path must remain unreachable.',
            );
        } finally {
            $this->assertSame(0, $context['run']->candidateRows()->count());
        }
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
            ->assertSee('Current validation')
            ->assertSee('Original Solver Score')
            ->assertSee('Original solver result')
            ->assertSee('Timetable')
            ->assertSee('Source Contract')
            ->assertSee('Historical compatibility: tal94-demand-v2 / balanced_v1.')
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
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
        ]);
        foreach ([1, 2, 3, 4, 5, 6] as $day) {
            $package->teachingGridRows()->create([
                'day_of_week' => $day,
                'starts_at' => '07:00:00',
                'ends_at' => '21:00:00',
                'breaks' => [],
            ]);
        }
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
