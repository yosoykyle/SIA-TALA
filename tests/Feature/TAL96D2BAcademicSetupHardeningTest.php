<?php

namespace Tests\Feature;

use App\Actions\AcademicSetup\CourseSpecificationLifecycleService;
use App\Actions\AcademicSetup\CurriculumVersionLifecycleService;
use App\Filament\Resources\Courses\Pages\ViewCourse;
use App\Filament\Resources\CurriculumVersions\Pages\ListCurriculumVersions;
use App\Filament\Resources\CurriculumVersions\Pages\ReviewCurriculumVersion;
use App\Filament\Resources\Terms\Pages\CreateTerm;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\ExternalCompetencyRequirement;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL96D2BAcademicSetupHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        foreach ([User::StaffRoleRegistrar, User::StaffRoleAcademicHead] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    #[Test]
    public function catalog_accepts_only_face_to_face_and_online_modalities(): void
    {
        $this->assertSame([
            CourseSpecification::ModalityFaceToFace => 'Face-to-Face',
            CourseSpecification::ModalityOnline => 'Online',
        ], CourseSpecification::modalityOptions());
    }

    #[Test]
    public function term_dates_must_remain_inside_the_selected_academic_year(): void
    {
        $academicYear = AcademicYear::factory()->create([
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
        ]);

        $this->actingAs($this->staff(User::StaffRoleRegistrar));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateTerm::class)
            ->fillForm([
                'academic_year_id' => $academicYear->id,
                'type' => Term::TypeFirstSemester,
                'label' => 'Invalid First Semester',
                'starts_on' => '2026-07-31',
                'ends_on' => '2027-06-01',
                'state' => Term::StateDraft,
                'scheduling_slot_minutes' => 30,
                'scheduling_days' => [1, 2, 3, 4, 5, 6],
                'scheduling_day_starts_at' => '07:00',
                'scheduling_day_ends_at' => '20:00',
            ])
            ->call('create')
            ->assertHasFormErrors([
                'starts_on',
                'ends_on',
            ]);

        $this->assertDatabaseMissing('terms', ['label' => 'Invalid First Semester']);
    }

    #[Test]
    public function only_draft_course_specifications_and_curricula_are_directly_editable(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $this->assertTrue($registrar->can('update', CourseSpecification::factory()->create([
            'state' => CourseSpecification::StateDraft,
        ])));
        $this->assertFalse($registrar->can('update', CourseSpecification::factory()->create([
            'state' => CourseSpecification::StateActive,
        ])));
        $this->assertFalse($registrar->can('update', CourseSpecification::factory()->create([
            'state' => CourseSpecification::StateRetired,
        ])));

        $this->assertTrue($registrar->can('update', CurriculumVersion::factory()->create([
            'state' => CurriculumVersion::StateDraft,
        ])));
        $this->assertFalse($registrar->can('update', CurriculumVersion::factory()->create([
            'state' => CurriculumVersion::StateSuperseded,
        ])));
        $this->assertFalse($registrar->can('update', CurriculumVersion::factory()->create([
            'state' => CurriculumVersion::StateActive,
        ])));
    }

    #[Test]
    public function registrar_can_copy_a_protected_course_revision_into_an_independent_draft(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $source = $this->readySpecification(CourseSpecification::StateActive);

        $draft = app(CourseSpecificationLifecycleService::class)->copyToDraft(
            actor: $registrar,
            source: $source,
            revisionCode: '2027-DRAFT',
        );

        $this->assertSame(CourseSpecification::StateActive, $source->fresh()->state);
        $this->assertSame(CourseSpecification::StateDraft, $draft->state);
        $this->assertSame('2027-DRAFT', $draft->revision_code);
        $this->assertSame($source->components()->count(), $draft->components()->count());

        $draft->components()->firstOrFail()->update(['weekly_contact_hours' => '4.00']);

        $this->assertSame('3.00', $source->components()->firstOrFail()->weekly_contact_hours);
        $this->assertSame('4.00', $draft->components()->firstOrFail()->weekly_contact_hours);
    }

    #[Test]
    public function complete_course_specification_activation_protects_the_revision_from_direct_edits(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $draft = $this->readySpecification(CourseSpecification::StateDraft);

        $active = app(CourseSpecificationLifecycleService::class)->activate($registrar, $draft);

        $this->assertSame(CourseSpecification::StateActive, $active->state);
        $this->assertFalse($registrar->can('update', $active));
    }

    #[Test]
    public function externally_arranged_courses_activate_without_fabricated_recurring_meetings(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $external = CourseSpecification::factory()->create([
            'state' => CourseSpecification::StateDraft,
            'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
        ]);

        $active = app(CourseSpecificationLifecycleService::class)->activate($registrar, $external);

        $this->assertSame(CourseSpecification::StateActive, $active->state);
        $this->assertSame(0, $active->components()->count());

        $invalid = CourseSpecification::factory()->create([
            'state' => CourseSpecification::StateDraft,
            'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
        ]);
        CourseComponent::factory()->for($invalid)->create();

        $this->expectException(ValidationException::class);
        app(CourseSpecificationLifecycleService::class)->activate($registrar, $invalid);
    }

    #[Test]
    public function lifecycle_actions_replace_direct_state_editing_on_the_staff_surfaces(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $activeSpecification = $this->readySpecification(CourseSpecification::StateActive);
        $draftCurriculum = CurriculumVersion::factory()->create([
            'state' => CurriculumVersion::StateDraft,
        ]);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ViewCourse::class, ['record' => $activeSpecification->course_id])
            ->assertActionHidden('editRevision')
            ->assertActionVisible('reviseSubject')
            ->assertActionHidden('activateRevision');

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $draftCurriculum->id])
            ->assertOk();

        $activeCurriculum = CurriculumVersion::factory()->create([
            'state' => CurriculumVersion::StateActive,
        ]);

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $activeCurriculum->id])
            ->assertActionHidden('addCurriculumRow')
            ->assertActionHidden('activateCurriculum')
            ->assertActionVisible('copyToDraft');
    }

    #[Test]
    public function lifecycle_state_payloads_cannot_bypass_controlled_actions(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $course = Course::factory()->create();
        $program = Program::factory()->create();

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ViewCourse::class, ['record' => $course->id])
            ->callAction('createRevision', data: [
                'course_id' => $course->id,
                'revision_code' => 'TAMPERED-CREATE',
                'title' => 'Server-owned lifecycle state',
                'credit_units' => '3.00',
                'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
                'grading_profile_key' => CourseSpecification::GradingProfileServitechV1,
                'grading_profile_version' => 1,
                'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
                'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
                'same_faculty_default' => false,
                'state' => CourseSpecification::StateActive,
                'components' => [[
                    'component_type' => CourseComponent::TypeLecture,
                    'weekly_contact_hours' => '3.00',
                    'meeting_pattern' => '2x90',
                    'room_type_default' => CourseComponent::RoomTypeLectureRoom,
                    'required_room_feature_keys' => [],
                    'modality_restriction' => null,
                    'requires_consecutive_block' => false,
                    'same_faculty' => false,
                    'sequence' => 1,
                ]],
            ])
            ->assertHasNoActionErrors();

        $specification = CourseSpecification::query()
            ->where('course_id', $course->id)
            ->firstOrFail();

        $this->assertSame(CourseSpecification::StateDraft, $specification->state);

        Livewire::test(ViewCourse::class, ['record' => $course->id])
            ->callAction('editRevision', data: [
                'title' => 'Still a Draft after edit',
                'credit_units' => '3.00',
                'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
                'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
                'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
                'same_faculty_default' => false,
                'state' => CourseSpecification::StateActive,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(CourseSpecification::StateDraft, $specification->fresh()->state);

        Livewire::test(ListCurriculumVersions::class)
            ->callAction('create', data: [
                'program_id' => $program->id,
                'version_code' => 'TAMPERED-CURRICULUM',
                'name' => 'Server-owned curriculum lifecycle',
                'state' => CurriculumVersion::StateActive,
                'approved_by' => $registrar->id,
                'approved_at' => now(),
            ])
            ->assertHasNoActionErrors();

        $curriculum = CurriculumVersion::query()
            ->where('name', 'Server-owned curriculum lifecycle')
            ->firstOrFail();

        $this->assertSame(CurriculumVersion::StateDraft, $curriculum->state);
        $this->assertNull($curriculum->approved_by);
        $this->assertNull($curriculum->approved_at);

        Livewire::test(ReviewCurriculumVersion::class, ['record' => $curriculum->id])
            ->assertOk();
    }

    #[Test]
    public function curriculum_activation_supersedes_the_previous_active_version_without_moving_existing_students(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['duration_years' => 4]);
        $previous = CurriculumVersion::factory()->create([
            'program_id' => $program->id,
            'state' => CurriculumVersion::StateActive,
        ]);
        $candidate = CurriculumVersion::factory()->create([
            'program_id' => $program->id,
            'state' => CurriculumVersion::StateDraft,
        ]);
        CurriculumEntry::factory()->create([
            'curriculum_version_id' => $candidate->id,
            'course_specification_id' => $this->readySpecification(CourseSpecification::StateActive)->id,
            'year_level' => '4',
        ]);
        $student = StudentProfile::factory()->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $previous->id,
        ]);
        $service = app(CurriculumVersionLifecycleService::class);

        $impact = $service->activationImpact($candidate);
        $activated = $service->activate(actor: $registrar, curriculumVersion: $candidate);

        $this->assertSame([], $impact['readiness_errors']);
        $this->assertSame($previous->name, $impact['active_version_name']);
        $this->assertSame(1, $impact['existing_student_locks']);
        $this->assertSame(CurriculumVersion::StateSuperseded, $previous->fresh()->state);
        $this->assertSame(CurriculumVersion::StateActive, $activated->state);
        $this->assertSame($registrar->id, $activated->approved_by);
        $this->assertSame($previous->id, $student->fresh()->curriculum_version_id);
        $this->assertSame(1, CurriculumVersion::query()
            ->where('program_id', $program->id)
            ->where('state', CurriculumVersion::StateActive)
            ->count());
    }

    #[Test]
    public function activation_records_an_explicitly_chosen_first_intake_and_only_activates_drafts(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $intake = Term::factory()->create();
        $candidate = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateDraft]);
        CurriculumEntry::factory()->create([
            'curriculum_version_id' => $candidate->id,
            'course_specification_id' => $this->readySpecification(CourseSpecification::StateActive)->id,
        ]);
        $service = app(CurriculumVersionLifecycleService::class);

        $activated = $service->activate(actor: $registrar, curriculumVersion: $candidate, effectiveEntryTermId: $intake->id);

        $this->assertSame($intake->id, $activated->effective_entry_term_id);

        try {
            $service->activate(actor: $registrar, curriculumVersion: $activated);
            $this->fail('An active curriculum must not be activated again.');
        } catch (AuthorizationException) {
            $this->assertSame(CurriculumVersion::StateActive, $activated->fresh()->state);
        }
    }

    #[Test]
    public function registrar_can_copy_an_active_curriculum_into_an_editable_draft_successor(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['duration_years' => 4]);
        $active = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'BSIS-2024',
            'name' => 'BSIS 2024',
            'state' => CurriculumVersion::StateActive,
        ]);
        $entry = CurriculumEntry::factory()->for($active)->create([
            'course_specification_id' => $this->readySpecification(CourseSpecification::StateActive)->id,
            'year_level' => '4',
            'term_label' => 'Second Semester',
            'term_type' => Term::TypeSecondSemester,
        ]);
        ExternalCompetencyRequirement::query()->create([
            'curriculum_version_id' => $active->id,
            'curriculum_entry_id' => $entry->id,
            'requirement_code' => 'TESDA-NCII',
            'qualification_label' => 'Computer Systems Servicing NC II',
            'qualification_level' => 'NC II',
            'treatment' => ExternalCompetencyRequirement::TreatmentTrackedOnly,
            'state' => 'ACTIVE',
            'recorded_by' => $registrar->id,
            'recorded_at' => now(),
        ]);
        $student = StudentProfile::factory()->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $active->id,
        ]);

        $draft = app(CurriculumVersionLifecycleService::class)->copyToDraft(
            actor: $registrar,
            source: $active,
            versionCode: 'BSIS-2026-DRAFT',
            name: 'BSIS 2026 Revision',
        );

        $this->assertSame(CurriculumVersion::StateDraft, $draft->state);
        $this->assertSame(CurriculumVersion::StateActive, $active->fresh()->state);
        $this->assertNull($draft->effective_entry_term_id, 'A copied draft asks for its own first intake.');
        $this->assertSame(1, $draft->entries()->count());
        $this->assertSame('4', $draft->entries()->sole()->year_level);
        $this->assertSame(1, $draft->externalCompetencyRequirements()->count());
        $this->assertSame('Draft', $draft->externalCompetencyRequirements()->sole()->state);
        $this->assertSame($active->id, $student->fresh()->curriculum_version_id);
        $this->assertFalse($registrar->can('update', $active->fresh()));
        $this->assertTrue($registrar->can('update', $draft));
    }

    #[Test]
    public function course_specification_activation_blocks_incomplete_drafts(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $draft = CourseSpecification::factory()->create([
            'state' => CourseSpecification::StateDraft,
            'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
        ]);

        try {
            app(CourseSpecificationLifecycleService::class)->activate($registrar, $draft);
            $this->fail('An incomplete Course Specification must not activate.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('course_specification', $exception->errors());
        }

        $this->assertSame(CourseSpecification::StateDraft, $draft->fresh()->state);
    }

    #[Test]
    public function curriculum_activation_blocks_incomplete_referenced_specifications(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['duration_years' => 4]);
        $candidate = CurriculumVersion::factory()->for($program)->create([
            'state' => CurriculumVersion::StateDraft,
        ]);
        CurriculumEntry::factory()->create([
            'curriculum_version_id' => $candidate->id,
            'course_specification_id' => CourseSpecification::factory()->create([
                'state' => CourseSpecification::StateDraft,
            ])->id,
        ]);

        try {
            app(CurriculumVersionLifecycleService::class)->activate(actor: $registrar, curriculumVersion: $candidate);
            $this->fail('A curriculum with an incomplete subject must not activate.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('curriculum_version', $exception->errors());
        }

        $this->assertSame(CurriculumVersion::StateDraft, $candidate->fresh()->state);
    }

    private function readySpecification(string $state): CourseSpecification
    {
        $specification = CourseSpecification::factory()->create([
            'state' => $state,
            'allowed_modalities' => [
                CourseSpecification::ModalityFaceToFace,
                CourseSpecification::ModalityOnline,
            ],
        ]);
        $specification->components()->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => '3.00',
            'meeting_pattern' => '2x90',
            'room_type_default' => CourseComponent::RoomTypeLectureRoom,
            'required_room_feature_keys' => [],
            'modality_restriction' => null,
            'requires_consecutive_block' => false,
            'same_faculty' => false,
            'sequence' => 1,
        ]);

        return $specification;
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
