<?php

namespace Tests\Feature\SystemAdministration;

use App\Actions\Authentication\TalaAppAuthentication;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\PublicContent\ManagePublicContent;
use App\Filament\Clusters\PublicContent;
use App\Filament\Resources\FaqEntries\FaqEntryResource;
use App\Filament\Resources\FaqEntries\Pages\EditFaqEntry;
use App\Filament\Resources\FaqEntries\Pages\ListFaqEntries;
use App\Filament\Resources\PublicNotices\Pages\CreatePublicNotice;
use App\Filament\Resources\PublicNotices\Pages\ListPublicNotices;
use App\Filament\Resources\PublicNotices\PublicNoticeResource;
use App\Models\FaqEntry;
use App\Models\PublicNotice;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
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
        Livewire::test(CreatePublicNotice::class)
            ->assertFormFieldIsHidden('link_label')
            ->fillForm([
                'title' => 'Synthetic support notice',
                'message' => 'Test fixture only.',
                'visible_until' => now()->addDay()->format('Y-m-d H:i:s'),
                'link_url' => 'https://example.test/advisory',
            ])
            ->assertFormFieldIsVisible('link_label')
            ->fillForm([
                'link_label' => 'Read advisory',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $notice = PublicNotice::query()->sole();
        $this->assertFalse($notice->isPublished());
        $this->assertSame(1, $notice->display_order);
        $this->assertSame('Announcements', PublicNoticeResource::getNavigationLabel());
        $this->assertSame('Announcement', PublicNoticeResource::getModelLabel());
        $this->assertSame('Announcements', PublicNoticeResource::getPluralModelLabel());
        $this->assertSame('FAQs', FaqEntryResource::getNavigationLabel());
        $this->assertSame('FAQ', FaqEntryResource::getModelLabel());
        $this->assertSame('FAQs', FaqEntryResource::getPluralModelLabel());

        $workbench = Livewire::test(ListPublicNotices::class)
            ->assertSee('Announcements')
            ->assertSee('Add announcement')
            ->assertSee('Preview')
            ->assertSee('Edit')
            ->assertSee('Publish')
            ->assertSee('Live on landing page')
            ->assertSee('Scheduled')
            ->assertSee('Drafts')
            ->assertSee('Total')
            ->assertActionExists(TestAction::make('preview')->table($notice))
            ->assertActionExists(TestAction::make('edit')->table($notice))
            ->assertActionExists(TestAction::make('publish')->table($notice))
            ->assertActionExists(TestAction::make('delete')->table($notice))
            ->mountAction(TestAction::make('preview')->table($notice))
            ->assertActionMounted(TestAction::make('preview')->table($notice));
        $this->assertSame(FiltersLayout::Dropdown, $workbench->instance()->getTable()->getFiltersLayout());
        $this->assertStringContainsString('Test fixture only.', $workbench->instance()->getMountedAction()->getModalContent()->render());
        Livewire::test(ListPublicNotices::class)
            ->callAction(TestAction::make('publish')->table($notice), data: ['revision' => $notice->revision])
            ->assertNotified();
        $this->assertTrue($notice->fresh()->isPublished());
    }

    public function test_public_content_cluster_suppresses_duplicate_top_tabs_and_faq_list_shows_kpis_and_add_cta(): void
    {
        $this->assertFalse(PublicContent::shouldRegisterSubNavigation());

        $faq = FaqEntry::factory()->published()->create(['category' => FaqEntry::CategoryGeneral, 'sort_order' => 1]);

        $faqList = Livewire::test(ListFaqEntries::class)
            ->assertSee('FAQs')
            ->assertSee('Add FAQ')
            ->assertSee('Preview')
            ->assertSee('Prepare update')
            ->assertSee('Unpublish')
            ->assertSee('Live on landing page')
            ->assertSee('Categories')
            ->assertSee('Drafts')
            ->assertSee('Total')
            ->assertActionExists(TestAction::make('preview')->table($faq))
            ->assertActionExists(TestAction::make('edit')->table($faq))
            ->assertActionExists(TestAction::make('unpublish')->table($faq));
        $this->assertSame(FiltersLayout::Dropdown, $faqList->instance()->getTable()->getFiltersLayout());
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

    public function test_drag_and_drop_reorder_table_persists_order_and_audits_notices_and_faqs(): void
    {
        $firstNotice = PublicNotice::factory()->create(['display_order' => 1]);
        $secondNotice = PublicNotice::factory()->create(['display_order' => 2]);

        Livewire::test(ListPublicNotices::class)
            ->call('reorderTable', [$secondNotice->id, $firstNotice->id]);

        $this->assertSame(1, $secondNotice->fresh()->display_order);
        $this->assertSame(2, $firstNotice->fresh()->display_order);

        $firstFaq = FaqEntry::factory()->create(['category' => FaqEntry::CategoryGeneral, 'sort_order' => 1]);
        $secondFaq = FaqEntry::factory()->create(['category' => FaqEntry::CategoryGeneral, 'sort_order' => 2]);

        Livewire::test(ListFaqEntries::class)
            ->call('reorderTable', [$secondFaq->id, $firstFaq->id]);

        $this->assertSame(1, $secondFaq->fresh()->sort_order);
        $this->assertSame(2, $firstFaq->fresh()->sort_order);
        $this->assertSame(2, Activity::query()->where('log_name', 'public_content')->where('event', 'reordered')->count());
    }

    public function test_create_announcement_publish_footer_action_publishes_directly(): void
    {
        Livewire::test(CreatePublicNotice::class)
            ->assertActionExists('preview')
            ->fillForm([
                'title' => 'Direct publish announcement',
                'message' => 'Published directly from the create form.',
            ])
            ->callAction('publish')
            ->assertNotified('Published to public landing page');

        $notice = PublicNotice::query()->where('title', 'Direct publish announcement')->sole();
        $this->assertTrue($notice->isPublished());
        $this->assertSame(1, $notice->display_order);
    }

    public function test_drag_and_drop_reorder_works_for_published_notices_and_faqs_while_keeping_wording_immutable(): void
    {
        $firstNotice = PublicNotice::factory()->create([
            'state' => 'Published',
            'ever_published' => true,
            'published_at' => now(),
            'display_order' => 1,
        ]);
        $secondNotice = PublicNotice::factory()->create([
            'state' => 'Published',
            'ever_published' => true,
            'published_at' => now(),
            'display_order' => 2,
        ]);

        Livewire::test(ListPublicNotices::class)
            ->call('reorderTable', [$secondNotice->id, $firstNotice->id])
            ->assertNotified('Display order updated');

        $this->assertSame(1, $secondNotice->fresh()->display_order);
        $this->assertSame(2, $firstNotice->fresh()->display_order);

        $firstFaq = FaqEntry::factory()->published()->create(['category' => FaqEntry::CategoryGeneral, 'sort_order' => 1]);
        $secondFaq = FaqEntry::factory()->published()->create(['category' => FaqEntry::CategoryGeneral, 'sort_order' => 2]);

        Livewire::test(ListFaqEntries::class)
            ->call('reorderTable', [$secondFaq->id, $firstFaq->id])
            ->assertNotified('Display order updated');

        $this->assertSame(1, $secondFaq->fresh()->sort_order);
        $this->assertSame(2, $firstFaq->fresh()->sort_order);

        $this->expectException(\LogicException::class);
        $firstFaq->fresh()->update(['question' => 'Attempted direct edit']);
    }

    public function test_edit_faq_save_as_draft_redirects_back_to_index_table(): void
    {
        $draft = FaqEntry::factory()->create([
            'question' => 'Initial draft question?',
            'answer' => 'Initial draft answer.',
            'category' => FaqEntry::CategoryGeneral,
        ]);

        Livewire::test(EditFaqEntry::class, ['record' => $draft->getRouteKey()])
            ->fillForm([
                'question' => 'Updated draft question?',
                'answer' => 'Updated draft answer.',
                'category' => FaqEntry::CategoryGeneral,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(FaqEntryResource::getUrl());
    }
}
