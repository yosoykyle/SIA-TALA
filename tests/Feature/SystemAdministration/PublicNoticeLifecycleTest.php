<?php

namespace Tests\Feature\SystemAdministration;

use App\Actions\PublicContent\ManagePublicContent;
use App\Models\FaqEntry;
use App\Models\PublicNotice;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PublicNoticeLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Role::findOrCreate(User::StaffRoleSystemSuperAdmin, 'web');
        $this->administrator = User::factory()->create(['status' => User::StatusActive]);
        $this->administrator->assignRole(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($this->administrator);
    }

    public function test_publishing_and_revising_preserves_attributable_history(): void
    {
        $actions = app(ManagePublicContent::class);
        $draft = $actions->create(PublicNotice::class, $this->administrator, $this->notice());
        $this->assertSame('Draft', $draft->state);
        $this->assertCount(0, PublicNotice::query()->effective()->get());
        $published = $actions->publish($draft, $this->administrator, $draft->revision);
        $this->assertSame($this->administrator->id, $published->published_by);

        $successor = $actions->save($published, $this->administrator, $this->notice(['title' => 'Updated hours']), $published->revision);
        $this->assertNotSame($published->id, $successor->id);
        $this->assertSame($published->id, $successor->previous_version_id);
        $this->assertSame(2, $successor->version);
        $this->assertSame('Office hours', $published->fresh()->title);
        $this->assertSame([$published->id], PublicNotice::query()->effective()->pluck('id')->all());

        $actions->publish($successor, $this->administrator, $successor->revision);
        $this->assertSame([$successor->id], PublicNotice::query()->effective()->pluck('id')->all());
        $this->assertTrue(Activity::query()->where('subject_type', PublicNotice::class)->where('causer_id', $this->administrator->id)->exists());
        $this->assertFalse($this->administrator->can('delete', $published));
    }

    public function test_scheduled_successor_takes_effect_at_its_window_without_reviving_expired_history(): void
    {
        $this->travelTo(now()->startOfDay());
        $actions = app(ManagePublicContent::class);
        $first = $actions->create(PublicNotice::class, $this->administrator, $this->notice());
        $first = $actions->publish($first, $this->administrator, $first->revision);
        $next = $actions->save($first, $this->administrator, $this->notice([
            'visible_from' => now()->addHour()->toDateTimeString(),
            'visible_until' => now()->addHours(2)->toDateTimeString(),
        ]), $first->revision);
        $next = $actions->publish($next, $this->administrator, $next->revision);
        $this->assertSame([$first->id], PublicNotice::query()->effective()->pluck('id')->all());
        $this->travel(1)->hours();
        $this->assertSame([$next->id], PublicNotice::query()->effective()->pluck('id')->all());
        $this->travel(1)->hours();
        $this->assertCount(0, PublicNotice::query()->effective()->get());
    }

    public function test_duplicate_publish_is_idempotent_and_stale_edits_post_nothing(): void
    {
        $actions = app(ManagePublicContent::class);
        $draft = $actions->create(PublicNotice::class, $this->administrator, $this->notice());
        $originalRevision = $draft->revision;
        $saved = $actions->save($draft, $this->administrator, $this->notice(['title' => 'Current hours']), $originalRevision);
        try {
            $actions->publish($draft, $this->administrator, $originalRevision);
            $this->fail('A stale draft must not publish.');
        } catch (ValidationException) {
            $this->assertCount(0, PublicNotice::query()->effective()->get());
        }
        $published = $actions->publish($saved, $this->administrator, $saved->revision);
        $eventCount = Activity::query()->count();
        $actions->publish($saved, $this->administrator, $saved->revision);
        $this->assertSame($eventCount, Activity::query()->count());
        $this->assertSame('Current hours', $published->title);
    }

    public function test_overlapping_publication_order_is_rejected_but_non_overlapping_windows_are_allowed(): void
    {
        $actions = app(ManagePublicContent::class);
        $one = $actions->create(PublicNotice::class, $this->administrator, $this->notice(['visible_until' => now()->addDay()->toDateTimeString()]));
        $actions->publish($one, $this->administrator, $one->revision);
        $two = $actions->create(PublicNotice::class, $this->administrator, $this->notice(['visible_from' => now()->addDay()->toDateTimeString()]));
        $actions->publish($two, $this->administrator, $two->revision);
        $conflict = $actions->create(PublicNotice::class, $this->administrator, $this->notice());
        $this->expectException(ValidationException::class);
        $actions->publish($conflict, $this->administrator, $conflict->revision);
    }

    public static function invalidNoticeData(): array
    {
        return [
            'empty title' => [['title' => '']],
            'long title' => [['title' => str_repeat('a', 161)]],
            'long message' => [['message' => str_repeat('a', 501)]],
            'zero order' => [['display_order' => 0]],
            'unsafe link' => [['link_url' => 'javascript:alert(1)', 'link_label' => 'Details']],
            'insecure link' => [['link_url' => 'http://example.test', 'link_label' => 'Details']],
            'embedded credentials' => [['link_url' => 'https://name:password@example.test', 'link_label' => 'Details']],
            'link without label' => [['link_url' => 'https://example.test']],
            'reversed dates' => [['visible_from' => '2026-08-29 10:00:00', 'visible_until' => '2026-08-28 10:00:00']],
        ];
    }

    #[DataProvider('invalidNoticeData')]
    public function test_invalid_content_cannot_be_saved(array $changes): void
    {
        $this->expectException(ValidationException::class);
        app(ManagePublicContent::class)->create(PublicNotice::class, $this->administrator, $this->notice($changes));
    }

    public function test_unpublish_keeps_history_and_the_public_projection_escapes_content(): void
    {
        $actions = app(ManagePublicContent::class);
        $notice = $actions->create(PublicNotice::class, $this->administrator, $this->notice(['message' => '<script>unsafe()</script>']));
        $notice = $actions->publish($notice, $this->administrator, $notice->revision);
        $this->get('/')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>unsafe()</script>', false);
        $actions->unpublish($notice, $this->administrator, $notice->revision);
        $this->assertModelExists($notice);
        $this->assertCount(0, PublicNotice::query()->effective()->get());
    }

    public function test_other_roles_cannot_manage_notices(): void
    {
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $this->assertFalse($registrar->can('create', PublicNotice::class));
        $this->expectException(AuthorizationException::class);
        app(ManagePublicContent::class)->create(PublicNotice::class, $registrar, $this->notice());
    }

    private function notice(array $changes = []): array
    {
        return array_replace(['title' => 'Office hours', 'message' => 'Contact the responsible school office for assistance.', 'display_order' => 1], $changes);
    }

    public function test_reordering_published_notices_is_atomic_and_preserves_both_versions(): void
    {
        $actions = app(ManagePublicContent::class);
        $one = $actions->create(PublicNotice::class, $this->administrator, $this->notice());
        $one = $actions->publish($one, $this->administrator, $one->revision);
        $two = $actions->create(PublicNotice::class, $this->administrator, $this->notice(['title' => 'Second notice', 'display_order' => 2]));
        $two = $actions->publish($two, $this->administrator, $two->revision);
        $signature = $actions->orderSignature($two);

        $moved = $actions->move($two, $this->administrator, 'up', $two->revision, $signature);

        $this->assertSame(['Second notice', 'Office hours'], PublicNotice::query()->effective()->orderBy('display_order')->pluck('title')->all());
        $this->assertSame(1, $one->fresh()->display_order);
        $this->assertSame(2, $two->fresh()->display_order);
        $this->assertSame($two->id, $moved->previous_version_id);
        $this->assertSame($this->administrator->id, $moved->published_by);
        $this->assertSame(4, PublicNotice::query()->count());

        try {
            $actions->move($two, $this->administrator, 'up', $two->revision, $signature);
            $this->fail('Retrying a superseded move must not publish another version.');
        } catch (ValidationException) {
            $this->assertSame(4, PublicNotice::query()->count());
        }
    }

    public function test_reorder_rejects_stale_group_and_preserves_existing_successor_draft(): void
    {
        $actions = app(ManagePublicContent::class);
        $one = $actions->create(PublicNotice::class, $this->administrator, $this->notice());
        $one = $actions->publish($one, $this->administrator, $one->revision);
        $two = $actions->create(PublicNotice::class, $this->administrator, $this->notice(['display_order' => 2]));
        $two = $actions->publish($two, $this->administrator, $two->revision);
        $signature = $actions->orderSignature($two);
        $draft = $actions->save($one, $this->administrator, $this->notice(['title' => 'Unfinished editorial work']), $one->revision);

        try {
            $actions->move($two, $this->administrator, 'up', $two->revision, $signature);
            $this->fail('A changed group must require a fresh review.');
        } catch (ValidationException) {
            $this->assertSame('Unfinished editorial work', $draft->fresh()->title);
            $this->assertSame([$one->id, $two->id], PublicNotice::query()->effective()->orderBy('display_order')->pluck('id')->all());
        }
    }

    public function test_permission_revocation_blocks_a_retry_without_changes(): void
    {
        $actions = app(ManagePublicContent::class);
        $draft = $actions->create(PublicNotice::class, $this->administrator, $this->notice());
        $published = $actions->publish($draft, $this->administrator, $draft->revision);
        Role::findByName(User::StaffRoleSystemSuperAdmin)->revokePermissionTo('manage-public-notices');

        $this->expectException(AuthorizationException::class);
        $actions->publish($published, $this->administrator, $published->revision);
    }

    public function test_reordering_uses_one_effective_timestamp_when_the_clock_advances_mid_operation(): void
    {
        $this->freezeSecond();
        $reorderedAt = now();
        $actions = new class extends ManagePublicContent
        {
            private bool $clockAdvanced = false;

            public function save(
                PublicNotice|FaqEntry $record,
                User $actor,
                array $data,
                int $expectedRevision,
            ): PublicNotice|FaqEntry {
                $saved = parent::save($record, $actor, $data, $expectedRevision);

                if (! $this->clockAdvanced && $record->wasPublished()) {
                    $this->clockAdvanced = true;
                    Carbon::setTestNow(now()->addSecond());
                }

                return $saved;
            }

            public function clockAdvanced(): bool
            {
                return $this->clockAdvanced;
            }
        };
        $one = $actions->create(PublicNotice::class, $this->administrator, $this->notice());
        $one = $actions->publish($one, $this->administrator, $one->revision);
        $two = $actions->create(PublicNotice::class, $this->administrator, $this->notice([
            'title' => 'Second notice',
            'display_order' => 2,
        ]));
        $two = $actions->publish($two, $this->administrator, $two->revision);
        $signature = $actions->orderSignature($two);

        $actions->move($two, $this->administrator, 'up', $two->revision, $signature);

        $successors = PublicNotice::query()
            ->whereNotNull('previous_version_id')
            ->orderBy('id')
            ->get();

        $this->assertTrue($actions->clockAdvanced());
        $this->assertCount(2, $successors);
        $this->assertTrue($successors->first()->visible_from->equalTo($successors->last()->visible_from));
        $this->assertTrue($successors->first()->published_at->equalTo($successors->last()->published_at));
        $this->assertTrue($successors->first()->visible_from->equalTo($reorderedAt));
        $this->assertTrue($successors->first()->published_at->equalTo($reorderedAt));
    }
}
