<?php

namespace Tests\Feature;

use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Resources\Courses\Pages\CreateCourse;
use App\Filament\Resources\CourseSpecifications\CourseSpecificationResource;
use App\Filament\Resources\CourseSpecifications\Pages\CreateCourseSpecification;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\CurriculumVersions\Pages\CreateCurriculumVersion;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL82BCurriculumCourseCatalogAcceptanceTest extends TestCase
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
            User::StaffRoleFaculty,
            User::StaffRoleSystemSuperAdmin,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function clean_course_catalog_resources_are_registered_without_reactivating_legacy_catalog_resources(): void
    {
        $resources = Filament::getPanel('admin')->getResources();

        $this->assertContains(CourseResource::class, $resources);
        $this->assertContains(CourseSpecificationResource::class, $resources);
        $this->assertContains(CurriculumVersionResource::class, $resources);
        $this->assertNotContains('App\Filament\Resources\Subjects\SubjectResource', $resources);
        $this->assertNotContains('App\Filament\Resources\Curriculums\CurriculumResource', $resources);
    }

    /**
     * @param  list<class-string>  $visibleResources
     * @param  list<class-string>  $hiddenResources
     */
    #[DataProvider('resourceAccessBoundaries')]
    public function test_resource_access_follows_registrar_manage_and_academic_head_view_boundary(
        string $role,
        bool $canCreate,
        array $visibleResources,
        array $hiddenResources,
    ): void {
        $this->actingAs($this->staff($role));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        foreach ($visibleResources as $resource) {
            $this->assertTrue($resource::canAccess(), "{$role} should access {$resource}.");
            $this->assertSame($canCreate, $resource::canCreate(), "{$role} create boundary mismatch for {$resource}.");
        }

        foreach ($hiddenResources as $resource) {
            $this->assertFalse($resource::canAccess(), "{$role} should not access {$resource}.");
        }
    }

    #[Test]
    public function academic_head_can_view_but_cannot_mutate_clean_course_catalog_records(): void
    {
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $course = Course::factory()->create();
        $specification = CourseSpecification::factory()->create(['course_id' => $course->id]);
        $curriculumVersion = CurriculumVersion::factory()->create();

        $this->actingAs($academicHead);

        $this->assertTrue($academicHead->can('view', $course));
        $this->assertTrue($academicHead->can('view', $specification));
        $this->assertTrue($academicHead->can('view', $curriculumVersion));
        $this->assertFalse($academicHead->can('create', Course::class));
        $this->assertFalse($academicHead->can('update', $course));
        $this->assertFalse($academicHead->can('create', CourseSpecification::class));
        $this->assertFalse($academicHead->can('update', $specification));
        $this->assertFalse($academicHead->can('create', CurriculumVersion::class));
        $this->assertFalse($academicHead->can('update', $curriculumVersion));
    }

    #[Test]
    public function registrar_can_create_clean_course_specification_and_curriculum_version_records(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateCourse::class)
            ->fillForm([
                'code' => 'IT101',
                'state' => Course::StateActive,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $course = Course::query()->where('code', 'IT101')->firstOrFail();
        $prerequisite = Course::factory()->create(['code' => 'IT100']);
        $term = Term::factory()
            ->for(AcademicYear::factory()->create(['label' => '2026-2027']))
            ->create(['label' => 'First Semester']);

        Livewire::test(CreateCourseSpecification::class)
            ->fillForm([
                'course_id' => $course->id,
                'revision_code' => '2026A',
                'title' => 'Introduction to Computing',
                'description' => 'Foundational computing concepts for first-year learners.',
                'credit_units' => '3.00',
                'grading_profile_key' => CourseSpecification::GradingProfileServitechV1,
                'grading_profile_version' => 1,
                'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
                'same_faculty_default' => false,
                'effective_term_id' => $term->id,
                'state' => CourseSpecification::StateDraft,
                'components' => [[
                    'component_type' => CourseComponent::TypeLecture,
                    'weekly_contact_hours' => '3.00',
                    'room_type_default' => CourseComponent::RoomTypeLectureRoom,
                    'modality_restriction' => null,
                    'requires_consecutive_block' => true,
                    'same_faculty' => false,
                    'sequence' => 1,
                ]],
                'requirements' => [[
                    'rule_type' => CourseRequirement::TypePrerequisite,
                    'group_key' => 'G1',
                    'related_course_id' => $prerequisite->id,
                    'required_outcome' => CourseRequirement::RequiredOutcomePassed,
                    'minimum_grade' => null,
                    'accepts_transfer_credit' => true,
                    'authority' => 'Registrar-recorded curriculum setup',
                    'state' => CourseRequirement::StateActive,
                    'sequence' => 1,
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $specification = CourseSpecification::query()
            ->where('course_id', $course->id)
            ->where('revision_code', '2026A')
            ->firstOrFail();

        $this->assertDatabaseHas('course_components', [
            'course_specification_id' => $specification->id,
            'component_type' => CourseComponent::TypeLecture,
            'room_type_default' => CourseComponent::RoomTypeLectureRoom,
        ]);
        $this->assertDatabaseHas('course_requirements', [
            'course_specification_id' => $specification->id,
            'rule_type' => CourseRequirement::TypePrerequisite,
            'related_course_id' => $prerequisite->id,
            'state' => CourseRequirement::StateActive,
        ]);

        $program = Program::factory()->create(['code' => 'BSIT', 'name' => 'Bachelor of Science in Information Technology']);

        Livewire::test(CreateCurriculumVersion::class)
            ->fillForm([
                'program_id' => $program->id,
                'version_code' => '2026-BSIT',
                'name' => 'BSIT 2026 Curriculum',
                'effective_entry_term_id' => $term->id,
                'state' => CurriculumVersion::StateDraft,
                'approval_reference' => 'BOR-2026-01',
                'approved_by' => $registrar->id,
                'approved_at' => now()->seconds(0),
                'entries' => [[
                    'course_specification_id' => $specification->id,
                    'year_level' => '1',
                    'term_label' => 'First Semester',
                    'term_type' => Term::TypeFirstSemester,
                    'sequence' => 1,
                    'requirement_group' => CurriculumEntry::RequirementGroupRequired,
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $curriculumVersion = CurriculumVersion::query()
            ->where('program_id', $program->id)
            ->where('version_code', '2026-BSIT')
            ->firstOrFail();

        $this->assertDatabaseHas('curriculum_entries', [
            'curriculum_version_id' => $curriculumVersion->id,
            'course_specification_id' => $specification->id,
            'year_level' => '1',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
            'requirement_group' => CurriculumEntry::RequirementGroupRequired,
        ]);
    }

    /**
     * @return array<string, array{role: string, canCreate: bool, visibleResources: list<class-string>, hiddenResources: list<class-string>}>
     */
    public static function resourceAccessBoundaries(): array
    {
        $catalogResources = [
            CourseResource::class,
            CourseSpecificationResource::class,
            CurriculumVersionResource::class,
        ];

        return [
            'registrar manages clean catalog resources' => [
                'role' => User::StaffRoleRegistrar,
                'canCreate' => true,
                'visibleResources' => $catalogResources,
                'hiddenResources' => [],
            ],
            'academic head views clean catalog resources only' => [
                'role' => User::StaffRoleAcademicHead,
                'canCreate' => false,
                'visibleResources' => $catalogResources,
                'hiddenResources' => [],
            ],
            'accounting cannot access academic catalog resources' => [
                'role' => User::StaffRoleAccounting,
                'canCreate' => false,
                'visibleResources' => [],
                'hiddenResources' => $catalogResources,
            ],
            'faculty cannot access registrar-owned academic catalog resources' => [
                'role' => User::StaffRoleFaculty,
                'canCreate' => false,
                'visibleResources' => [],
                'hiddenResources' => $catalogResources,
            ],
            'system super admin remains outside operational catalog resources' => [
                'role' => User::StaffRoleSystemSuperAdmin,
                'canCreate' => false,
                'visibleResources' => [],
                'hiddenResources' => $catalogResources,
            ],
        ];
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
