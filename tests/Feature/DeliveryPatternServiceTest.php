<?php

namespace Tests\Feature;

use App\Actions\Scheduling\DeliveryPatternService;
use App\Models\DeliveryPattern;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeliveryPatternServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_used_pattern_rules_are_locked_and_must_be_cloned_for_rule_changes(): void
    {
        $actor = User::factory()->create();
        $pattern = DeliveryPattern::factory()->create([
            'code' => 'BLEND',
            'version' => 1,
            'name' => 'Blended Major Saturday',
            'modality' => 'blended',
            'default_room_required' => true,
            'is_frozen' => true,
            'used_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Clone a new version');

        app(DeliveryPatternService::class)->prepareForSave([
            'code' => 'BLEND',
            'version' => 1,
            'name' => 'Changed Rule Name',
            'modality' => 'online',
            'allowed_days' => [1, 2, 3],
            'subject_routing' => DeliveryPattern::SubjectRoutingSameSubjectSet,
            'enforcement_level' => DeliveryPattern::EnforcementStrict,
            'default_room_required' => false,
            'is_active' => true,
        ], $pattern, $actor);
    }

    public function test_clone_creates_next_version_without_mutating_source_pattern(): void
    {
        $actor = User::factory()->create();
        $source = DeliveryPattern::factory()->create([
            'code' => 'ONLINE',
            'version' => 2,
            'name' => 'Online Minors',
            'modality' => 'online',
            'default_room_required' => false,
            'allowed_days' => [1, 2, 3, 4, 5],
            'is_frozen' => true,
            'used_at' => now(),
        ]);

        $clone = app(DeliveryPatternService::class)->cloneNewVersion($source, $actor, [
            'name' => 'Online Minors Revised',
        ]);

        $this->assertSame('ONLINE', $clone->code);
        $this->assertSame(3, $clone->version);
        $this->assertSame('Online Minors Revised', $clone->name);
        $this->assertSame($source->id, $clone->cloned_from_id);
        $this->assertTrue($clone->is_active);
        $this->assertFalse($clone->is_frozen);
        $this->assertNull($clone->used_at);
        $this->assertSame('Online Minors', $source->fresh()->name);
    }

    public function test_online_pattern_cannot_require_a_room_by_default(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Only on-site and blended delivery patterns may require a room');

        app(DeliveryPatternService::class)->prepareForSave([
            'code' => 'ONLINE',
            'version' => 1,
            'name' => 'Online Delivery',
            'modality' => 'online',
            'allowed_days' => [1, 2, 3, 4, 5],
            'subject_routing' => DeliveryPattern::SubjectRoutingSameSubjectSet,
            'enforcement_level' => DeliveryPattern::EnforcementStrict,
            'default_room_required' => true,
            'is_active' => true,
        ]);
    }
}
