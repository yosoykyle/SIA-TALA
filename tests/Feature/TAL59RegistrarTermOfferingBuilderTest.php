<?php

namespace Tests\Feature;

use App\Actions\Registrar\BuildTermOfferings;
use App\Filament\Resources\TermOfferings\Pages\ListTermOfferings;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL59RegistrarTermOfferingBuilderTest extends TestCase
{
    use DatabaseTransactions;

    private BuildTermOfferings $builder;

    private int $scopeCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());

        $this->builder = app(BuildTermOfferings::class);

        foreach ([User::StaffRoleRegistrar, User::StaffRoleSystemSuperAdmin, User::StaffRoleFaculty] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_preview_returns_only_active_matching_curriculum_entries_with_inherited_course_facts(): void
    {
        [$term, $program, $curriculum, $entry, $specification] = $this->scope();
        $otherYear = CurriculumEntry::factory()->for($curriculum)->create(['year_level' => 'Second Year']);
        $otherTerm = CurriculumEntry::factory()->for($curriculum)->create(['term_type' => Term::TypeSecondSemester]);
        $retiredSpecificationEntry = CurriculumEntry::factory()->for($curriculum)->create([
            'course_specification_id' => CourseSpecification::factory()->create(['state' => CourseSpecification::StateRetired])->id,
        ]);

        $originalSpecificationAttributes = $specification->fresh()->toArray();
        $preview = $this->builder->preview($term, $program, $curriculum, 'First Year');

        $this->assertCount(1, $preview);
        $previewEntry = $preview->first();
        $this->assertInstanceOf(CurriculumEntry::class, $previewEntry);
        $previewSpecification = $previewEntry->getRelationValue('courseSpecification');
        $this->assertInstanceOf(CourseSpecification::class, $previewSpecification);
        $previewCourse = $previewSpecification->getRelationValue('course');
        $this->assertInstanceOf(Course::class, $previewCourse);
        $previewComponents = $previewSpecification->getRelationValue('components');
        $this->assertInstanceOf(Collection::class, $previewComponents);
        $this->assertTrue($previewEntry->is($entry));
        $this->assertSame('IT101', $previewCourse->code);
        $this->assertSame('Introduction to Computing', $previewSpecification->title);
        $this->assertSame('3.00', $previewSpecification->credit_units);
        $this->assertSame([TermOffering::ModalityFaceToFace, TermOffering::ModalityOnline], $previewSpecification->getAttribute('allowed_modalities'));
        $this->assertCount(1, $previewComponents);
        $this->assertFalse($preview->contains($otherYear));
        $this->assertFalse($preview->contains($otherTerm));
        $this->assertFalse($preview->contains($retiredSpecificationEntry));
        $this->assertSame($originalSpecificationAttributes, $specification->fresh()->toArray());
    }

    public function test_regular_generation_is_transactional_and_idempotent(): void
    {
        [$term, $program, $curriculum, $entry] = $this->scope();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $rows = [$this->regularRow($entry, 'BSIT-1A')];

        $first = $this->builder->regular($registrar, $term, $program, $curriculum, 'First Year', $rows);
        $second = $this->builder->regular($registrar, $term, $program, $curriculum, 'First Year', $rows);

        $this->assertSame(1, $first['created']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, TermOffering::query()->count());
        $this->assertSame(1, Section::query()->count());
        $this->assertSame(1, SectionDeliveryGroup::query()->count());
        $offering = TermOffering::query()->firstOrFail();
        $this->assertSame(TermOffering::CategoryRegular, $offering->category);
        $this->assertSame(TermOffering::ArrangementNormalClass, $offering->delivery_variant);
        $this->assertSame(TermOffering::StatePendingScheduling, $offering->state);
    }

    public function test_invalid_later_row_rolls_back_the_entire_regular_generation(): void
    {
        [$term, $program, $curriculum, $entry] = $this->scope();
        $secondEntry = CurriculumEntry::factory()->for($curriculum)->create([
            'course_specification_id' => CourseSpecification::factory()->create([
                'state' => CourseSpecification::StateActive,
                'allowed_modalities' => [TermOffering::ModalityFaceToFace],
            ])->id,
            'sequence' => 2,
        ]);
        $registrar = $this->staff(User::StaffRoleRegistrar);

        try {
            $this->builder->regular($registrar, $term, $program, $curriculum, 'First Year', [
                $this->regularRow($entry, 'BSIT-1A'),
                $this->regularRow($secondEntry, 'BSIT-1B', groupExpectedCount: 31),
            ]);
            $this->fail('Expected validation to fail.');
        } catch (ValidationException) {
            $this->assertSame(0, TermOffering::query()->count());
            $this->assertSame(0, Section::query()->count());
            $this->assertSame(0, SectionDeliveryGroup::query()->count());
        }
    }

    public function test_invalid_counts_capacity_modality_inactive_curriculum_and_mismatched_placement_are_rejected(): void
    {
        [$term, $program, $curriculum, $entry] = $this->scope();
        $registrar = $this->staff(User::StaffRoleRegistrar);

        foreach ([
            ['row' => $this->regularRow($entry, 'BSIT-1A', expectedCount: -1), 'year' => 'First Year'],
            ['row' => $this->regularRow($entry, 'BSIT-1A', capacity: 0), 'year' => 'First Year'],
            ['row' => $this->regularRow($entry, 'BSIT-1A', modality: TermOffering::ModalityModular), 'year' => 'First Year'],
            ['row' => $this->regularRow($entry, 'BSIT-1A'), 'year' => 'Second Year'],
        ] as $case) {
            try {
                $this->builder->regular($registrar, $term, $program, $curriculum, $case['year'], [$case['row']]);
                $this->fail('Expected validation to fail.');
            } catch (ValidationException) {
                $this->assertSame(0, TermOffering::query()->count());
            }
        }

        $curriculum->update(['state' => CurriculumVersion::StateDraft]);
        $this->expectException(ValidationException::class);
        $this->builder->regular($registrar, $term, $program, $curriculum->fresh(), 'First Year', [$this->regularRow($entry, 'BSIT-1A')]);
    }

    public function test_scheduled_and_cancelled_offerings_are_blocked_without_overwrite(): void
    {
        [$term, $program, $curriculum, $entry] = $this->scope();
        $registrar = $this->staff(User::StaffRoleRegistrar);

        foreach ([TermOffering::StateScheduled, TermOffering::StateCancelled] as $state) {
            $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
                'state' => $state,
                'expected_count' => 12,
            ]);

            $result = $this->builder->regular($registrar, $term, $program, $curriculum, 'First Year', [$this->regularRow($entry, 'BSIT-1A')]);

            $this->assertSame(1, $result['blocked']);
            $this->assertSame(12, $offering->fresh()->expected_count);
            $offering->delete();
        }
    }

    public function test_special_offering_requires_reason_and_valid_delivery_arrangement(): void
    {
        [$term, , , $entry] = $this->scope();
        $registrar = $this->staff(User::StaffRoleRegistrar);

        foreach ([
            ['special_reason' => '', 'delivery_variant' => TermOffering::ArrangementTutorial],
            ['special_reason' => 'Approved petition', 'delivery_variant' => 'LAB_ONLY'],
        ] as $invalid) {
            try {
                $this->builder->special($registrar, $term, $entry, [
                    ...$invalid,
                    ...$this->specialData('BSIT-1S'),
                    ...$invalid,
                ]);
                $this->fail('Expected validation to fail.');
            } catch (ValidationException) {
                $this->assertSame(0, TermOffering::query()->count());
            }
        }

        $result = $this->builder->special($registrar, $term, $entry, $this->specialData('BSIT-1S'));
        $offering = TermOffering::query()->firstOrFail();

        $this->assertSame(1, $result['created']);
        $this->assertSame(TermOffering::CategorySpecial, $offering->category);
        $this->assertSame('Approved graduating-student need', $offering->special_reason);
        $this->assertSame(TermOffering::ArrangementTutorial, $offering->delivery_variant);
    }

    public function test_only_registrar_and_system_super_admin_can_mutate_offerings(): void
    {
        [$term, $program, $curriculum, $entry] = $this->scope();
        $row = $this->regularRow($entry, 'BSIT-1A');

        foreach ([User::StaffRoleRegistrar, User::StaffRoleSystemSuperAdmin] as $role) {
            $result = $this->builder->regular($this->staff($role), $term, $program, $curriculum, 'First Year', [$row]);
            $this->assertContains($result['created'] + $result['skipped'], [1]);
        }

        [$otherTerm, $otherProgram, $otherCurriculum, $otherEntry] = $this->scope();
        $this->expectException(AuthorizationException::class);
        $this->builder->regular(
            $this->staff(User::StaffRoleFaculty),
            $otherTerm,
            $otherProgram,
            $otherCurriculum,
            'First Year',
            [$this->regularRow($otherEntry, 'BSIT-1B')],
        );
    }

    public function test_term_offering_resource_is_explicitly_registered_and_boots_for_registrar(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $this->assertTrue(Route::has('filament.admin.resources.term-offerings.index'));

        Livewire::actingAs($registrar)
            ->test(ListTermOfferings::class)
            ->assertOk();
    }

    /**
     * @return array{Term, Program, CurriculumVersion, CurriculumEntry, CourseSpecification}
     */
    private function scope(): array
    {
        $term = Term::factory()->create(['type' => Term::TypeFirstSemester]);
        $program = Program::factory()->create(['code' => 'BS'.++$this->scopeCounter]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => 'IT10'.$this->scopeCounter]);
        $specification = CourseSpecification::factory()->for($course)->create([
            'title' => 'Introduction to Computing',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace, TermOffering::ModalityOnline],
            'same_faculty_default' => true,
        ]);
        CourseComponent::factory()->for($specification)->create();
        $entry = CurriculumEntry::factory()->for($curriculum)->for($specification, 'courseSpecification')->create([
            'year_level' => 'First Year',
            'term_type' => $term->type,
            'sequence' => 1,
        ]);

        return [$term, $program, $curriculum, $entry, $specification];
    }

    /**
     * @return array<string, mixed>
     */
    private function regularRow(
        CurriculumEntry $entry,
        string $code,
        int $expectedCount = 30,
        int $capacity = 30,
        int $groupExpectedCount = 30,
        string $modality = TermOffering::ModalityFaceToFace,
    ): array {
        return [
            'include' => true,
            'curriculum_entry_id' => $entry->id,
            'modality' => $modality,
            'expected_count' => $expectedCount,
            'same_faculty_override' => null,
            'sections' => [[
                'code' => $code,
                'capacity' => $capacity,
                'delivery_groups' => [[
                    'name' => 'Regular Cohort',
                    'expected_count' => $groupExpectedCount,
                    'modality' => $modality,
                ]],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function specialData(string $code): array
    {
        return [
            'special_reason' => 'Approved graduating-student need',
            'delivery_variant' => TermOffering::ArrangementTutorial,
            'modality' => TermOffering::ModalityFaceToFace,
            'expected_count' => 10,
            'sections' => [[
                'code' => $code,
                'capacity' => 10,
                'delivery_groups' => [[
                    'name' => 'Tutorial Cohort',
                    'expected_count' => 10,
                    'modality' => TermOffering::ModalityFaceToFace,
                ]],
            ]],
        ];
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
