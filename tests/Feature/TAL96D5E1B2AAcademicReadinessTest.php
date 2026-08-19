<?php

namespace Tests\Feature;

use App\Actions\AcademicSetup\CurriculumVersionLifecycleService;
use App\Filament\Pages\AcademicReadiness;
use App\Filament\Resources\AcademicYears\AcademicYearResource;
use App\Filament\Resources\CourseSpecifications\CourseSpecificationResource;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\CurriculumVersions\Pages\CreateCurriculumVersion;
use App\Filament\Resources\CurriculumVersions\Pages\ReviewCurriculumVersion;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Filament\Resources\ImportBatches\Pages\ViewImportBatch;
use App\Filament\Resources\Programs\ProgramResource;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\ImportBatch;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL96D5E1B2AAcademicReadinessTest extends TestCase
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
            User::StaffRoleAccounting,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function registrar_sidebar_exposes_one_academic_readiness_task_instead_of_peer_source_tables(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $labels = collect(Filament::getPanel('admin')->buildNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item): string => $item->getLabel())
            ->values()
            ->all();

        $this->assertContains('Catalog & Curricula', $labels);

        foreach (['Academic Years', 'Terms', 'Programs', 'Courses', 'Course Specifications', 'Curriculum Versions', 'Import Batches'] as $sourceRecordLabel) {
            $this->assertNotContains($sourceRecordLabel, $labels);
        }
    }

    #[Test]
    public function workbench_access_is_role_appropriate_while_source_record_routes_remain_available(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $accounting = $this->staff(User::StaffRoleAccounting);

        $this->actingAs($registrar)
            ->get(AcademicReadiness::getUrl())
            ->assertOk();

        foreach ([
            AcademicYearResource::getUrl('index'),
            ProgramResource::getUrl('index'),
            CourseSpecificationResource::getUrl('index'),
            CurriculumVersionResource::getUrl('index'),
            ImportBatchResource::getUrl('index'),
        ] as $url) {
            $this->actingAs($registrar)->get($url)->assertOk();
        }

        $this->actingAs($academicHead)
            ->get(AcademicReadiness::getUrl())
            ->assertOk();

        $this->actingAs($accounting)
            ->get(AcademicReadiness::getUrl())
            ->assertForbidden();
    }

    #[Test]
    public function workbench_states_the_program_readiness_blocker_and_next_action_in_plain_language(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $missing = Program::factory()->create([
            'code' => 'B2MISS',
            'name' => 'Missing Curriculum Program',
        ]);
        $incomplete = Program::factory()->create([
            'code' => 'B2DRAF',
            'name' => 'Draft Curriculum Program',
        ]);
        $ready = Program::factory()->create([
            'code' => 'B2RDY',
            'name' => 'Ready Curriculum Program',
        ]);

        CurriculumVersion::factory()->for($incomplete)->create([
            'version_code' => 'DRAF-2026',
            'name' => 'Draft without rows',
            'state' => CurriculumVersion::StateDraft,
        ]);

        $readyVersion = CurriculumVersion::factory()->for($ready)->create([
            'version_code' => 'RDY-2026',
            'name' => 'Ready Curriculum',
            'state' => CurriculumVersion::StateActive,
        ]);
        $specification = $this->readySpecification();
        CurriculumEntry::factory()
            ->for($readyVersion)
            ->for($specification)
            ->create();

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(AcademicReadiness::class)
            ->assertCanSeeTableRecords([$missing, $incomplete, $ready])
            ->assertSee('No curriculum has been recorded')
            ->assertSee('Create a curriculum draft')
            ->assertSee('The draft has no curriculum rows')
            ->assertSee('Add curriculum rows')
            ->assertSee('Ready for offerings')
            ->assertSee('Review curriculum');
    }

    #[Test]
    public function workbench_surfaces_a_pending_revision_instead_of_hiding_it_behind_the_active_curriculum(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create([
            'code' => 'B2REV',
            'name' => 'Program with Pending Revision',
        ]);
        CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'B2REV-ACTIVE',
            'state' => CurriculumVersion::StateActive,
            'created_at' => now()->subDay(),
        ]);
        CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'B2REV-DRAFT',
            'state' => CurriculumVersion::StateDraft,
            'created_at' => now(),
        ]);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(AcademicReadiness::class)
            ->assertSee('B2REV-DRAFT')
            ->assertDontSee('B2REV-ACTIVE');
    }

    #[Test]
    public function manually_created_draft_redirects_to_the_combined_curriculum_review(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['code' => 'B2MAN']);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::test(CreateCurriculumVersion::class)
            ->fillForm([
                'program_id' => $program->id,
                'version_code' => 'B2MAN-2026',
                'name' => 'Manual Curriculum Draft',
                'effective_entry_term_id' => null,
                'entries' => [],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $curriculum = CurriculumVersion::query()
            ->where('program_id', $program->id)
            ->where('version_code', 'B2MAN-2026')
            ->sole();

        $component->assertRedirect(
            CurriculumVersionResource::getUrl('review', ['record' => $curriculum]),
        );
    }

    #[Test]
    public function curriculum_review_combines_source_specification_placement_readiness_and_correction_path(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['code' => 'B2DBM']);
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'DBM-2026',
            'name' => 'DBM Three-Year Curriculum',
            'state' => CurriculumVersion::StateDraft,
        ]);
        $incompleteSpecification = CourseSpecification::factory()->create([
            'title' => 'Business Operations',
            'credit_units' => 3,
            'allowed_modalities' => [],
            'state' => CourseSpecification::StateDraft,
        ]);
        $entry = CurriculumEntry::factory()
            ->for($curriculum)
            ->for($incompleteSpecification)
            ->create([
                'year_level' => '2',
                'term_label' => 'Second Semester',
                'sequence' => 4,
                'requirement_group' => CurriculumEntry::RequirementGroupRequired,
            ]);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculum->getRouteKey()])
            ->assertCanSeeTableRecords([$entry])
            ->assertSee($incompleteSpecification->course->code)
            ->assertSee('Business Operations')
            ->assertSee('Second Semester')
            ->assertSee('Specification incomplete')
            ->assertSee('Allowed Modalities must contain only Face-to-Face and Online.')
            ->assertSee('Complete specification')
            ->assertActionVisible('editCurriculumRows');
    }

    #[Test]
    public function registrar_can_add_a_curriculum_row_without_leaving_the_combined_review(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $curriculum = CurriculumVersion::factory()->create([
            'state' => CurriculumVersion::StateDraft,
        ]);
        $specification = $this->readySpecification();

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculum->getRouteKey()])
            ->callAction(TestAction::make('addCurriculumRow')->table(), data: [
                'course_specification_id' => $specification->id,
                'year_level' => 1,
                'term_label' => 'First Semester',
                'term_type' => Term::TypeFirstSemester,
                'sequence' => 1,
                'requirement_group' => CurriculumEntry::RequirementGroupRequired,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Curriculum row added');

        $this->assertDatabaseHas('curriculum_entries', [
            'curriculum_version_id' => $curriculum->id,
            'course_specification_id' => $specification->id,
            'year_level' => '1',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
            'sequence' => 1,
            'requirement_group' => CurriculumEntry::RequirementGroupRequired,
        ]);
    }

    #[Test]
    public function registrar_can_correct_curriculum_placement_inside_the_combined_review(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $curriculum = CurriculumVersion::factory()->create([
            'state' => CurriculumVersion::StateDraft,
        ]);
        $entry = CurriculumEntry::factory()
            ->for($curriculum)
            ->for($this->readySpecification())
            ->create([
                'year_level' => '1',
                'term_label' => 'First Semester',
                'term_type' => Term::TypeFirstSemester,
                'sequence' => 1,
                'requirement_group' => CurriculumEntry::RequirementGroupRequired,
            ]);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculum->getRouteKey()])
            ->callAction(TestAction::make('editPlacement')->table($entry), data: [
                'year_level' => 2,
                'term_label' => 'Second Semester',
                'term_type' => Term::TypeSecondSemester,
                'sequence' => 4,
                'requirement_group' => CurriculumEntry::RequirementGroupElective,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Curriculum placement updated');

        $entry->refresh();

        $this->assertSame('2', $entry->year_level);
        $this->assertSame('Second Semester', $entry->term_label);
        $this->assertSame(Term::TypeSecondSemester, $entry->term_type);
        $this->assertSame(4, $entry->sequence);
        $this->assertSame(CurriculumEntry::RequirementGroupElective, $entry->requirement_group);
    }

    #[Test]
    public function curriculum_placement_rejects_a_whitespace_only_term_label(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $curriculum = CurriculumVersion::factory()->create([
            'state' => CurriculumVersion::StateDraft,
        ]);
        $entry = CurriculumEntry::factory()
            ->for($curriculum)
            ->for($this->readySpecification())
            ->create([
                'term_label' => 'First Semester',
            ]);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculum->getRouteKey()])
            ->callAction(TestAction::make('editPlacement')->table($entry), data: [
                'year_level' => 1,
                'term_label' => '   ',
                'term_type' => Term::TypeFirstSemester,
                'sequence' => 1,
                'requirement_group' => CurriculumEntry::RequirementGroupRequired,
            ])
            ->assertHasActionErrors(['term_label']);

        $this->assertSame('First Semester', $entry->refresh()->term_label);
    }

    #[Test]
    public function registrar_can_complete_a_draft_specification_inside_the_combined_review(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $curriculum = CurriculumVersion::factory()->create([
            'state' => CurriculumVersion::StateDraft,
        ]);
        $specification = CourseSpecification::factory()->create([
            'title' => 'Incomplete Business Operations',
            'credit_units' => 0,
            'grading_profile_key' => '',
            'allowed_modalities' => [],
            'state' => CourseSpecification::StateDraft,
        ]);
        $entry = CurriculumEntry::factory()
            ->for($curriculum)
            ->for($specification)
            ->create();

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculum->getRouteKey()])
            ->callAction(TestAction::make('completeSpecification')->table($entry), data: [
                'title' => 'Business Operations',
                'credit_units' => 3,
                'grading_profile_key' => CourseSpecification::GradingProfileServitechV1,
                'grading_profile_version' => 1,
                'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
                'allowed_modalities' => [
                    CourseSpecification::ModalityFaceToFace,
                    CourseSpecification::ModalityOnline,
                ],
                'same_faculty_default' => false,
                'components' => [[
                    'component_type' => CourseComponent::TypeLecture,
                    'weekly_contact_hours' => 3,
                    'meeting_pattern' => '2x90',
                    'room_type_default' => CourseComponent::RoomTypeLectureRoom,
                    'required_room_feature_keys' => [],
                    'modality_restriction' => null,
                    'requires_consecutive_block' => false,
                    'same_faculty' => false,
                    'sequence' => 1,
                ]],
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Course specification updated');

        $specification->refresh();

        $this->assertSame('Business Operations', $specification->title);
        $this->assertSame('3.00', $specification->credit_units);
        $this->assertSame(CourseSpecification::GradingProfileServitechV1, $specification->grading_profile_key);
        $this->assertSame([
            CourseSpecification::ModalityFaceToFace,
            CourseSpecification::ModalityOnline,
        ], $specification->allowed_modalities);
        $this->assertSame(CourseSpecification::StateDraft, $specification->state);
        $this->assertDatabaseHas('course_components', [
            'course_specification_id' => $specification->id,
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 3,
            'room_type_default' => CourseComponent::RoomTypeLectureRoom,
            'sequence' => 1,
        ]);
    }

    #[Test]
    public function specification_completion_rejects_a_whitespace_only_subject_title(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $curriculum = CurriculumVersion::factory()->create([
            'state' => CurriculumVersion::StateDraft,
        ]);
        $specification = CourseSpecification::factory()->create([
            'title' => 'Original Subject Title',
            'state' => CourseSpecification::StateDraft,
        ]);
        $entry = CurriculumEntry::factory()
            ->for($curriculum)
            ->for($specification)
            ->create();

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculum->getRouteKey()])
            ->callAction(TestAction::make('completeSpecification')->table($entry), data: [
                'title' => '   ',
                'credit_units' => 3,
                'grading_profile_key' => CourseSpecification::GradingProfileServitechV1,
                'grading_profile_version' => 1,
                'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
                'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
                'same_faculty_default' => false,
                'components' => [[
                    'component_type' => CourseComponent::TypeLecture,
                    'weekly_contact_hours' => 3,
                    'meeting_pattern' => '2x90',
                    'room_type_default' => CourseComponent::RoomTypeLectureRoom,
                    'required_room_feature_keys' => [],
                    'modality_restriction' => null,
                    'requires_consecutive_block' => false,
                    'same_faculty' => false,
                    'sequence' => 1,
                ]],
            ])
            ->assertHasActionErrors(['title']);

        $this->assertSame('Original Subject Title', $specification->refresh()->title);
    }

    #[Test]
    public function registrar_records_approval_and_confirms_activation_from_the_combined_review(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['code' => 'B2ACT']);
        $previous = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'B2ACT-OLD',
            'state' => CurriculumVersion::StateActive,
        ]);
        $candidate = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'B2ACT-NEW',
            'state' => CurriculumVersion::StateDraft,
        ]);
        CurriculumEntry::factory()
            ->for($candidate)
            ->for($this->readySpecification())
            ->create();

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $candidate->getRouteKey()])
            ->callAction('recordApproval', data: [
                'approval_reference' => 'Synthetic Academic Council Resolution 2026-01',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('External approval recorded')
            ->callAction('activateCurriculum')
            ->assertHasNoActionErrors()
            ->assertNotified('Curriculum activated');

        $this->assertSame(CurriculumVersion::StateActive, $candidate->refresh()->state);
        $this->assertSame(CurriculumVersion::StateSuperseded, $previous->refresh()->state);
    }

    #[Test]
    public function activation_remains_blocked_when_a_reviewed_row_has_an_incomplete_specification(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $candidate = CurriculumVersion::factory()->create([
            'version_code' => 'B2BLOCK',
            'state' => CurriculumVersion::StateDraft,
        ]);
        $incompleteSpecification = CourseSpecification::factory()->create([
            'credit_units' => 0,
            'allowed_modalities' => [],
            'state' => CourseSpecification::StateDraft,
        ]);
        CurriculumEntry::factory()
            ->for($candidate)
            ->for($incompleteSpecification)
            ->create();

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $candidate->getRouteKey()])
            ->callAction('recordApproval', data: [
                'approval_reference' => 'Synthetic Academic Council Resolution 2026-02',
            ])
            ->assertHasNoActionErrors();

        try {
            app(CurriculumVersionLifecycleService::class)->activate(
                actor: $registrar,
                curriculumVersion: $candidate->refresh(),
                confirmed: true,
            );
            $this->fail('Activation should remain blocked while a reviewed row is incomplete.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('curriculum_version', $exception->errors());
        }

        $this->assertSame(CurriculumVersion::StateRecordedApproved, $candidate->refresh()->state);
    }

    #[Test]
    public function academic_head_receives_the_same_curriculum_truth_without_mutation_actions(): void
    {
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $curriculum = CurriculumVersion::factory()->create();
        $entry = CurriculumEntry::factory()
            ->for($curriculum)
            ->for($this->readySpecification())
            ->create();

        $this->actingAs($academicHead);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculum->getRouteKey()])
            ->assertCanSeeTableRecords([$entry])
            ->assertActionHidden(TestAction::make('addCurriculumRow')->table())
            ->assertActionHidden(TestAction::make('editPlacement')->table($entry))
            ->assertActionHidden(TestAction::make('completeSpecification')->table($entry))
            ->assertActionVisible(TestAction::make('viewSpecification')->table($entry))
            ->assertActionHidden('editCurriculumRows')
            ->assertActionHidden('recordApproval')
            ->assertActionHidden('activateCurriculum');
    }

    #[Test]
    public function posted_curriculum_import_converges_on_the_same_curriculum_review(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['code' => 'B2DIT']);
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'DIT-2026',
        ]);
        $batch = ImportBatch::factory()->create([
            'type' => ImportBatch::TypeCurriculum,
            'state' => ImportBatch::StatePosted,
            'validation_details' => [
                'rows' => [[
                    'values' => [
                        'program_code' => 'B2DIT',
                        'curriculum_version_code' => 'DIT-2026',
                    ],
                ]],
            ],
        ]);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ViewImportBatch::class, ['record' => $batch->getRouteKey()])
            ->assertActionVisible('reviewCurriculumDraft')
            ->assertActionHasUrl(
                'reviewCurriculumDraft',
                CurriculumVersionResource::getUrl('review', ['record' => $curriculum]),
            );
    }

    private function readySpecification(): CourseSpecification
    {
        $specification = CourseSpecification::factory()->create([
            'allowed_modalities' => [
                CourseSpecification::ModalityFaceToFace,
                CourseSpecification::ModalityOnline,
            ],
            'state' => CourseSpecification::StateActive,
        ]);

        CourseComponent::factory()->for($specification)->create();

        return $specification;
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
