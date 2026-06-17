<?php

namespace Tests\Feature;

use App\Actions\Scheduling\SectionDeliveryGroupService;
use App\Models\Curriculum;
use App\Models\DeliveryPattern;
use App\Models\Program;
use App\Models\Room;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SectionDeliveryGroupServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_creates_group_and_freezes_selected_delivery_pattern(): void
    {
        $actor = User::factory()->create();
        $section = $this->section(['max_seats' => 30]);
        $pattern = DeliveryPattern::factory()->create([
            'modality' => 'on_site',
            'default_room_required' => true,
        ]);
        $room = Room::factory()->create(['code' => 'R-101']);

        $group = app(SectionDeliveryGroupService::class)->save($section, [
            'delivery_pattern_id' => $pattern->id,
            'name' => 'Saturday Major F2F',
            'modality' => 'on_site',
            'capacity' => 25,
            'room' => $room->code,
            'status' => SectionDeliveryGroup::StatusActive,
        ], null, $actor);

        $this->assertSame($section->id, $group->section_id);
        $this->assertSame('Saturday Major F2F', $group->name);
        $this->assertTrue($group->room_required);
        $this->assertSame($room->code, $group->room);
        $this->assertSame($actor->id, $group->created_by);
        $this->assertTrue($pattern->fresh()->is_frozen);
        $this->assertNotNull($pattern->fresh()->used_at);
    }

    public function test_capacity_cannot_exceed_parent_section_capacity(): void
    {
        $section = $this->section(['max_seats' => 20]);
        $pattern = DeliveryPattern::factory()->online()->create();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('capacity cannot exceed parent section capacity');

        app(SectionDeliveryGroupService::class)->save($section, [
            'delivery_pattern_id' => $pattern->id,
            'name' => 'Online Minor',
            'modality' => 'online',
            'capacity' => 21,
            'status' => SectionDeliveryGroup::StatusActive,
        ]);
    }

    public function test_online_group_clears_room_even_if_room_is_supplied(): void
    {
        $section = $this->section();
        $pattern = DeliveryPattern::factory()->online()->create();
        Room::factory()->create(['code' => 'R-202']);

        $group = app(SectionDeliveryGroupService::class)->save($section, [
            'delivery_pattern_id' => $pattern->id,
            'name' => 'Online Minor',
            'modality' => 'online',
            'capacity' => 20,
            'room' => 'R-202',
            'status' => SectionDeliveryGroup::StatusActive,
        ]);

        $this->assertFalse($group->room_required);
        $this->assertNull($group->room);
    }

    public function test_inactive_pattern_is_rejected(): void
    {
        $section = $this->section();
        $pattern = DeliveryPattern::factory()->inactive()->create([
            'modality' => 'online',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Selected delivery pattern must be active');

        app(SectionDeliveryGroupService::class)->save($section, [
            'delivery_pattern_id' => $pattern->id,
            'name' => 'Online Minor',
            'modality' => 'online',
            'capacity' => 20,
            'status' => SectionDeliveryGroup::StatusActive,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function section(array $overrides = []): Section
    {
        $program = Program::factory()->create();
        $curriculum = Curriculum::factory()->create(['program_id' => $program->id]);

        return Section::factory()->create([
            'term_id' => Term::factory(),
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => '1st Year',
            'curriculum_period' => '1st Semester',
            ...$overrides,
        ]);
    }
}
