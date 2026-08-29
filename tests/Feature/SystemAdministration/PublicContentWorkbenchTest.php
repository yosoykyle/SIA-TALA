<?php

namespace Tests\Feature\SystemAdministration;

use App\Actions\Authentication\TalaAppAuthentication;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\PublicContent\ManagePublicContent;
use App\Filament\Clusters\PublicContent;
use App\Filament\Resources\FaqEntries\FaqEntryResource;
use App\Filament\Resources\PublicNotices\Pages\CreatePublicNotice;
use App\Filament\Resources\PublicNotices\Pages\ListPublicNotices;
use App\Filament\Resources\PublicNotices\PublicNoticeResource;
use App\Models\FaqEntry;
use App\Models\PublicNotice;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PublicContentWorkbenchTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->administrator = User::factory()->create();
        $this->administrator->assignRole(User::StaffRoleSystemSuperAdmin);
        $provider = app(TalaAppAuthentication::class);
        $provider->saveSecret($this->administrator, $provider->generateSecret());
        $this->administrator->forceFill(['two_factor_recovery_codes_acknowledged_at' => now()])->save();
        $this->actingAs($this->administrator)->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleSystemSuperAdmin]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_fixed_public_notice_permission_is_assigned_only_to_system_administration(): void
    {
        $this->assertTrue($this->administrator->can('manage-public-notices'));
        foreach (Role::query()->where('name', '!=', User::StaffRoleSystemSuperAdmin)->get() as $role) {
            $this->assertFalse($role->hasPermissionTo('manage-public-notices'));
        }
    }

    public function test_existing_faq_authority_remains_reachable_without_the_notice_permission(): void
    {
        Role::findByName(User::StaffRoleSystemSuperAdmin, 'web')->revokePermissionTo('manage-public-notices');
        $this->actingAs($this->administrator->fresh());

        $this->assertTrue(FaqEntryResource::canAccess());
        $this->assertFalse(PublicNoticeResource::canAccess());
        $this->assertTrue(PublicContent::canAccess());
        $this->get(PublicContent::getUrl())->assertRedirect(FaqEntryResource::getUrl());
    }

    public function test_native_notice_producer_saves_draft_previews_and_publishes_explicitly(): void
    {
        Livewire::test(CreatePublicNotice::class)->fillForm([
            'title' => 'Synthetic support notice', 'message' => 'Test fixture only.', 'display_order' => 1,
            'visible_until' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->call('create')->assertHasNoFormErrors();

        $notice = PublicNotice::query()->sole();
        $this->assertFalse($notice->isPublished());
        $workbench = Livewire::test(ListPublicNotices::class)
            ->assertSee('Notices')->assertSee('FAQ')
            ->assertActionExists(TestAction::make('moveUp')->table($notice))
            ->assertActionExists(TestAction::make('moveDown')->table($notice))
            ->mountAction(TestAction::make('preview')->table($notice))
            ->assertActionMounted(TestAction::make('preview')->table($notice));
        $this->assertStringContainsString('Test fixture only.', $workbench->instance()->getMountedAction()->getModalContent()->render());
        Livewire::test(ListPublicNotices::class)
            ->callAction(TestAction::make('publish')->table($notice), data: ['revision' => $notice->revision])
            ->assertNotified();
        $this->assertTrue($notice->fresh()->isPublished());
    }

    public function test_legacy_faq_links_redirect_to_the_authorized_canonical_cluster(): void
    {
        $faq = FaqEntry::query()->firstOrFail();
        $this->get('/admin/faq-entries')->assertRedirect(FaqEntryResource::getUrl());
        $this->get('/admin/faq-entries/create')->assertRedirect(FaqEntryResource::getUrl('create'));
        $this->get('/admin/faq-entries/'.$faq->id.'/edit')->assertRedirect(FaqEntryResource::getUrl('edit', ['record' => $faq]));

        $registrar = User::factory()->create();
        $registrar->assignRole(User::StaffRoleRegistrar);
        $this->actingAs($registrar)->get('/admin/faq-entries')->assertForbidden();
    }

    public function test_faq_reorder_stays_inside_its_category_and_preserves_published_history(): void
    {
        $actions = app(ManagePublicContent::class);
        $one = FaqEntry::factory()->published()->create(['category' => FaqEntry::CategoryTechnicalSupport, 'sort_order' => 1]);
        $two = FaqEntry::factory()->published()->create(['category' => FaqEntry::CategoryTechnicalSupport, 'sort_order' => 2]);
        $moved = $actions->move($two, $this->administrator, 'up', $two->revision, $actions->orderSignature($two));
        $this->assertSame(1, $moved->sort_order);
        $this->assertSame(2, $two->fresh()->sort_order);
        $this->assertSame(1, $one->fresh()->sort_order);
        $this->assertSame(2, FaqEntry::query()->effective()->where('category', FaqEntry::CategoryTechnicalSupport)->count());
    }
}
