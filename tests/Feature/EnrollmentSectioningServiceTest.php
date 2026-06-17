<?php

namespace Tests\Feature;

use App\Actions\Enrollment\EnrollmentSectioningService;
use App\Models\Curriculum;
use App\Models\DeliveryPattern;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EnrollmentSectioningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_assign_stores_section_and_delivery_group_transactionally(): void
    {
        [$term, $program, $curriculum] = $this->academicContext();
        $section = $this->section($term, $program, $curriculum, ['max_seats' => 2]);
        $group = $this->group($section, ['capacity' => 2, 'modality' => 'online']);
        $enrollment = $this->enrollment($term, $program, ['modality' => 'on_site']);
        $registrar = $this->registrar();

        $assigned = app(EnrollmentSectioningService::class)->assign($enrollment, $section, $group, $registrar);

        $this->assertSame($section->id, $assigned->section_id);
        $this->assertSame($group->id, $assigned->section_delivery_group_id);
        $this->assertSame('online', $assigned->modality);
        $this->assertSame(1, $section->fresh()->enrolled_count);
        $this->assertSame(1, $group->fresh()->assigned_count);
    }

    public function test_reassignment_moves_counts_between_delivery_groups_without_double_counting_section(): void
    {
        [$term, $program, $curriculum] = $this->academicContext();
        $section = $this->section($term, $program, $curriculum, [
            'max_seats' => 3,
            'enrolled_count' => 1,
        ]);
        $oldGroup = $this->group($section, [
            'name' => 'Online Minor',
            'modality' => 'online',
            'capacity' => 2,
            'assigned_count' => 1,
        ]);
        $newGroup = $this->group($section, [
            'name' => 'Saturday F2F Major',
            'modality' => 'on_site',
            'capacity' => 2,
            'assigned_count' => 0,
        ]);
        $enrollment = $this->enrollment($term, $program, [
            'section_id' => $section->id,
            'section_delivery_group_id' => $oldGroup->id,
            'modality' => 'online',
        ]);

        app(EnrollmentSectioningService::class)->assign($enrollment, $section, $newGroup, $this->registrar());

        $this->assertSame(1, $section->fresh()->enrolled_count);
        $this->assertSame(0, $oldGroup->fresh()->assigned_count);
        $this->assertSame(1, $newGroup->fresh()->assigned_count);
        $this->assertSame($newGroup->id, $enrollment->fresh()->section_delivery_group_id);
    }

    public function test_full_delivery_group_blocks_assignment_and_keeps_counts_unchanged(): void
    {
        [$term, $program, $curriculum] = $this->academicContext();
        $section = $this->section($term, $program, $curriculum, [
            'max_seats' => 2,
            'enrolled_count' => 1,
        ]);
        $group = $this->group($section, [
            'capacity' => 1,
            'assigned_count' => 1,
        ]);
        $enrollment = $this->enrollment($term, $program);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('delivery group is already at capacity');

        try {
            app(EnrollmentSectioningService::class)->assign($enrollment, $section, $group, $this->registrar());
        } finally {
            $this->assertNull($enrollment->fresh()->section_delivery_group_id);
            $this->assertSame(1, $section->fresh()->enrolled_count);
            $this->assertSame(1, $group->fresh()->assigned_count);
        }
    }

    /**
     * @return array{Term, Program, Curriculum}
     */
    private function academicContext(): array
    {
        $term = Term::factory()->create();
        $program = Program::factory()->create();
        $curriculum = Curriculum::factory()->create(['program_id' => $program->id]);

        return [$term, $program, $curriculum];
    }

    private function section(Term $term, Program $program, Curriculum $curriculum, array $overrides = []): Section
    {
        return Section::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => '1st Year',
            'curriculum_period' => '1st Semester',
            ...$overrides,
        ]);
    }

    private function group(Section $section, array $overrides = []): SectionDeliveryGroup
    {
        $modality = $overrides['modality'] ?? 'online';
        $pattern = DeliveryPattern::factory()->create([
            'modality' => $modality,
            'default_room_required' => SectionDeliveryGroup::modalityRequiresRoom($modality),
        ]);

        return SectionDeliveryGroup::factory()->create([
            'section_id' => $section->id,
            'delivery_pattern_id' => $pattern->id,
            'modality' => $modality,
            'room_required' => SectionDeliveryGroup::modalityRequiresRoom($modality),
            'room' => SectionDeliveryGroup::modalityRequiresRoom($modality) ? 'R-101' : null,
            ...$overrides,
        ]);
    }

    private function enrollment(Term $term, Program $program, array $overrides = []): Enrollment
    {
        $student = StudentProfile::factory()->create([
            'program_id' => $program->id,
            'year_level' => '1st Year',
        ]);

        return Enrollment::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
            'year_level' => '1st Year',
            ...$overrides,
        ]);
    }

    private function registrar(): User
    {
        $registrar = User::factory()->create();
        $registrar->givePermissionTo(Permission::findOrCreate('manage-sections'));

        return $registrar;
    }
}
