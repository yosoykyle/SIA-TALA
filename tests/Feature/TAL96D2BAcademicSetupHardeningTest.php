<?php

namespace Tests\Feature;

use App\Actions\AcademicSetup\CourseSpecificationLifecycleService;
use App\Actions\AcademicSetup\CurriculumVersionLifecycleService;
use App\Filament\Resources\CourseSpecifications\Pages\CreateCourseSpecification;
use App\Filament\Resources\CourseSpecifications\Pages\EditCourseSpecification;
use App\Filament\Resources\CourseSpecifications\Pages\ViewCourseSpecification;
use App\Filament\Resources\CurriculumVersions\Pages\CreateCurriculumVersion;
use App\Filament\Resources\CurriculumVersions\Pages\EditCurriculumVersion;
use App\Filament\Resources\CurriculumVersions\Pages\ViewCurriculumVersion;
use App\Filament\Resources\Terms\Pages\CreateTerm;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
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
            'state' => CurriculumVersion::StateRecordedApproved,
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

        Livewire::test(ViewCourseSpecification::class, ['record' => $activeSpecification->id])
            ->assertActionHidden('edit')
            ->assertActionVisible('copyToDraft')
            ->assertActionHidden('activateRevision');

        Livewire::test(ViewCurriculumVersion::class, ['record' => $draftCurriculum->id])
            ->assertActionVisible('edit')
            ->assertActionVisible('recordApproval')
            ->assertActionHidden('activateCurriculum');
    }

    #[Test]
    public function lifecycle_state_payloads_cannot_bypass_controlled_actions(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $course = Course::factory()->create();
        $program = Program::factory()->create();

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateCourseSpecification::class)
            ->fillForm([
                'course_id' => $course->id,
                'revision_code' => 'TAMPERED-CREATE',
                'title' => 'Server-owned lifecycle state',
                'credit_units' => '3.00',
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
            ->call('create')
            ->assertHasNoFormErrors();

        $specification = CourseSpecification::query()
            ->where('revision_code', 'TAMPERED-CREATE')
            ->firstOrFail();

        $this->assertSame(CourseSpecification::StateDraft, $specification->state);

        Livewire::test(EditCourseSpecification::class, ['record' => $specification->id])
            ->fillForm([
                'title' => 'Still a Draft after edit',
                'state' => CourseSpecification::StateActive,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(CourseSpecification::StateDraft, $specification->fresh()->state);

        Livewire::test(CreateCurriculumVersion::class)
            ->fillForm([
                'program_id' => $program->id,
                'version_code' => 'TAMPERED-CURRICULUM',
                'name' => 'Server-owned curriculum lifecycle',
                'state' => CurriculumVersion::StateActive,
                'approval_reference' => 'UNAUTHORIZED-APPROVAL',
                'approved_by' => $registrar->id,
                'approved_at' => now(),
                'entries' => [],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $curriculum = CurriculumVersion::query()
            ->where('version_code', 'TAMPERED-CURRICULUM')
            ->firstOrFail();

        $this->assertSame(CurriculumVersion::StateDraft, $curriculum->state);
        $this->assertNull($curriculum->approval_reference);
        $this->assertNull($curriculum->approved_by);
        $this->assertNull($curriculum->approved_at);

        Livewire::test(EditCurriculumVersion::class, ['record' => $curriculum->id])
            ->fillForm([
                'name' => 'Still a Draft after edit',
                'state' => CurriculumVersion::StateActive,
                'approval_reference' => 'UNAUTHORIZED-EDIT',
                'approved_by' => $registrar->id,
                'approved_at' => now(),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $curriculum->refresh();

        $this->assertSame(CurriculumVersion::StateDraft, $curriculum->state);
        $this->assertNull($curriculum->approval_reference);
        $this->assertNull($curriculum->approved_by);
        $this->assertNull($curriculum->approved_at);
    }

    #[Test]
    public function curriculum_activation_supersedes_the_previous_active_version_without_moving_existing_students(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['duration_years' => 3]);
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
        ]);
        $student = StudentProfile::factory()->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $previous->id,
        ]);
        $service = app(CurriculumVersionLifecycleService::class);

        $recorded = $service->recordApproval(
            actor: $registrar,
            curriculumVersion: $candidate,
            approvalReference: 'BOARD-2026-017',
        );
        $impact = $service->activationImpact($recorded);
        $activated = $service->activate(
            actor: $registrar,
            curriculumVersion: $recorded,
            confirmed: true,
        );

        $this->assertSame(CurriculumVersion::StateRecordedApproved, $recorded->state);
        $this->assertSame($previous->version_code, $impact['active_version_code']);
        $this->assertSame(1, $impact['existing_student_locks']);
        $this->assertSame(CurriculumVersion::StateSuperseded, $previous->fresh()->state);
        $this->assertSame(CurriculumVersion::StateActive, $activated->state);
        $this->assertSame($previous->id, $student->fresh()->curriculum_version_id);
        $this->assertSame(1, CurriculumVersion::query()
            ->where('program_id', $program->id)
            ->where('state', CurriculumVersion::StateActive)
            ->count());
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
        $candidate = CurriculumVersion::factory()->create([
            'state' => CurriculumVersion::StateRecordedApproved,
            'approval_reference' => 'BOARD-2026-018',
            'approved_by' => $registrar->id,
            'approved_at' => now(),
        ]);
        CurriculumEntry::factory()->create([
            'curriculum_version_id' => $candidate->id,
            'course_specification_id' => CourseSpecification::factory()->create([
                'state' => CourseSpecification::StateDraft,
            ])->id,
        ]);

        try {
            app(CurriculumVersionLifecycleService::class)->activate(
                actor: $registrar,
                curriculumVersion: $candidate,
                confirmed: true,
            );
            $this->fail('A curriculum with a non-Active Course Specification must not activate.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('curriculum_version', $exception->errors());
        }

        $this->assertSame(CurriculumVersion::StateRecordedApproved, $candidate->fresh()->state);
    }

    #[Test]
    public function curriculum_activation_requires_explicit_confirmation(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $candidate = CurriculumVersion::factory()->create([
            'state' => CurriculumVersion::StateRecordedApproved,
            'approval_reference' => 'BOARD-2026-019',
            'approved_by' => $registrar->id,
            'approved_at' => now(),
        ]);
        CurriculumEntry::factory()->create([
            'curriculum_version_id' => $candidate->id,
            'course_specification_id' => $this->readySpecification(CourseSpecification::StateActive)->id,
        ]);

        try {
            app(CurriculumVersionLifecycleService::class)->activate(
                actor: $registrar,
                curriculumVersion: $candidate,
                confirmed: false,
            );
            $this->fail('Curriculum activation must require explicit confirmation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('confirmation', $exception->errors());
        }

        $this->assertSame(CurriculumVersion::StateRecordedApproved, $candidate->fresh()->state);
    }

    #[Test]
    public function curriculum_activation_requires_complete_approval_evidence(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $candidate = CurriculumVersion::factory()->create([
            'state' => CurriculumVersion::StateRecordedApproved,
            'approval_reference' => null,
            'approved_by' => null,
            'approved_at' => null,
        ]);
        CurriculumEntry::factory()->create([
            'curriculum_version_id' => $candidate->id,
            'course_specification_id' => $this->readySpecification(CourseSpecification::StateActive)->id,
        ]);

        try {
            app(CurriculumVersionLifecycleService::class)->activate(
                actor: $registrar,
                curriculumVersion: $candidate,
                confirmed: true,
            );
            $this->fail('Curriculum activation must require complete approval evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('curriculum_version', $exception->errors());
        }

        $this->assertSame(CurriculumVersion::StateRecordedApproved, $candidate->fresh()->state);
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
