<?php

namespace Tests\Feature;

use App\Actions\PublicContent\ManagePublicContent;
use App\Filament\Resources\FaqEntries\FaqEntryResource;
use App\Filament\Resources\FaqEntries\Pages\CreateFaqEntry;
use App\Filament\Resources\FaqEntries\Pages\EditFaqEntry;
use App\Filament\Resources\FaqEntries\Pages\ListFaqEntries;
use App\Models\FaqEntry;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FaqEntrySeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * TAL-93J2e: FaqEntry migration/model/seeder plus the admin resource and the
 * dynamic public landing FAQ surface it now feeds.
 */
class TAL93J2eFaqEntryAcceptanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_migration_defines_the_expected_schema(): void
    {
        $this->assertTrue(Schema::hasTable('faq_entries'));
        $this->assertTrue(Schema::hasColumns('faq_entries', [
            'id', 'question', 'answer', 'category', 'sort_order',
            'is_published', 'system_key', 'created_by', 'updated_by',
            'created_at', 'updated_at',
        ]));

        $indexes = collect(DB::select('SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_list FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? GROUP BY index_name', ['faq_entries']))
            ->pluck('columns_list')
            ->all();

        $this->assertContains('is_published,sort_order', $indexes, 'Missing composite (is_published, sort_order) index.');

        $uniqueIndexes = collect(DB::select('SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_list FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND non_unique = 0 GROUP BY index_name', ['faq_entries']))
            ->pluck('columns_list')
            ->all();
        $this->assertContains('system_key', $uniqueIndexes, 'system_key must be uniquely indexed.');

        foreach (['created_by', 'updated_by'] as $column) {
            $foreignKey = DB::selectOne('SELECT kcu.referenced_table_name, rc.delete_rule FROM information_schema.key_column_usage kcu JOIN information_schema.referential_constraints rc ON rc.constraint_schema = kcu.constraint_schema AND rc.constraint_name = kcu.constraint_name WHERE kcu.constraint_schema = DATABASE() AND kcu.table_name = ? AND kcu.column_name = ?', ['faq_entries', $column]);
            $this->assertNotNull($foreignKey, "Missing foreign key faq_entries.{$column}");
            $this->assertSame('users', $foreignKey->REFERENCED_TABLE_NAME);
            $this->assertSame('SET NULL', $foreignKey->DELETE_RULE);

            $definition = DB::selectOne('SELECT is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', ['faq_entries', $column]);
            $this->assertSame('YES', $definition->IS_NULLABLE, "faq_entries.{$column} must be nullable.");
        }
    }

    public function test_seeding_is_idempotent_and_does_not_overwrite_admin_edits(): void
    {
        // DatabaseSeeder (setUp) already ran FaqEntrySeeder once.
        $this->assertSame(3, FaqEntry::query()->count());

        $original = FaqEntry::query()->where('system_key', 'apply-admission')->firstOrFail();
        $edited = app(ManagePublicContent::class)->save($original, $this->actingAsSuperAdmin(), [
            'question' => 'Admin curated question?', 'sort_order' => 99,
            'answer' => $original->answer, 'category' => $original->category,
        ], $original->revision);

        (new FaqEntrySeeder)->run();

        $edited->refresh();
        $this->assertSame('Admin curated question?', $edited->question, 'Re-seeding must not overwrite later admin edits.');
        $this->assertSame(99, $edited->sort_order);
        $this->assertSame(4, FaqEntry::query()->count(), 'Re-seeding must preserve the original and successor without duplicates.');
        $this->assertSame('How do I apply for admission?', $original->fresh()->question);
        $this->assertSame(3, FaqEntry::query()->distinct()->count('system_key'));
    }

    public function test_super_admin_can_create_edit_and_delete_with_attribution(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $expectedSortOrder = ((int) FaqEntry::query()->max('sort_order')) + 1;

        Livewire::test(CreateFaqEntry::class)
            ->fillForm([
                'question' => 'Where do I check my grades?',
                'answer' => 'Grades appear in Student Hub after finalization.',
                'category' => FaqEntry::CategoryGradesAcademics,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(FaqEntryResource::getUrl('index'));

        $created = FaqEntry::query()->where('question', 'Where do I check my grades?')->firstOrFail();
        $this->assertSame($expectedSortOrder, $created->sort_order);
        $this->assertSame($admin->id, $created->created_by);
        $this->assertSame($admin->id, $created->updated_by);
        $this->assertFalse($created->is_published, 'Saving a draft must not publish it.');
        $this->assertTrue(Activity::query()->where('log_name', 'faq')->where('subject_id', $created->id)->exists());

        $other = User::factory()->create(['status' => User::StatusActive]);
        $other->assignRole('system-super-admin');
        $this->actingAs($other);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(EditFaqEntry::class, ['record' => $created->id])
            ->fillForm(['question' => 'Where can I see my grades?'])
            ->call('save')
            ->assertHasNoFormErrors();

        $created->refresh();
        $this->assertSame('Where can I see my grades?', $created->question);
        $this->assertSame($admin->id, $created->created_by, 'Creator attribution must not change on edit.');
        $this->assertSame($other->id, $created->updated_by, 'Updater attribution must reflect the editing admin.');

        Livewire::test(ListFaqEntries::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($created))
            ->assertHasNoActionErrors();

        $this->assertModelMissing($created);
    }

    public function test_registrar_cannot_manage_faqs(): void
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole('registrar');

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertFalse(FaqEntryResource::canAccess());
        $this->assertFalse($registrar->can('create', FaqEntry::class));
        $this->assertFalse($registrar->can('update', FaqEntry::factory()->create()));
    }

    public function test_form_validation_enforces_required_fields_and_category_options(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateFaqEntry::class)
            ->fillForm([
                'question' => null,
                'answer' => null,
                'category' => 'not-a-real-category',
                'sort_order' => 1,
                'is_published' => false,
            ])
            ->call('create')
            ->assertHasFormErrors([
                'question' => 'required',
                'answer' => 'required',
                'category' => 'in',
            ]);
    }

    public function test_faq_order_is_explicit_and_does_not_rewrite_published_versions_through_dragging(): void
    {
        $form = file_get_contents(app_path('Filament/Resources/FaqEntries/Schemas/FaqEntryForm.php'));
        $table = file_get_contents(app_path('Filament/Resources/FaqEntries/Tables/FaqEntriesTable.php'));

        $this->assertIsString($form);
        $this->assertIsString($table);
        $this->assertStringContainsString("TextInput::make('sort_order')", $form);
        $this->assertStringNotContainsString('->reorderable(', $table);
        $this->assertStringContainsString("TextColumn::make('sort_order')", $table);
    }

    public function test_public_landing_renders_only_published_entries_in_order(): void
    {
        FaqEntry::query()->delete();

        FaqEntry::factory()->published()->create(['question' => 'Second published question?', 'sort_order' => 2]);
        FaqEntry::factory()->published()->create(['question' => 'First published question?', 'sort_order' => 1]);
        FaqEntry::factory()->create(['question' => 'Hidden unpublished question?', 'sort_order' => 0]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSeeInOrder(['First published question?', 'Second published question?']);
        $response->assertDontSee('Hidden unpublished question?');
    }

    public function test_public_landing_escapes_answer_and_hides_unsafe_html(): void
    {
        FaqEntry::query()->delete();

        FaqEntry::factory()->published()->create([
            'question' => 'Is my answer escaped?',
            'answer' => '<script>alert("xss")</script> plain guidance',
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Is my answer escaped?');
        $response->assertDontSee('<script>alert("xss")</script>', false);
        $response->assertSee('&lt;script&gt;', false);
    }

    public function test_public_landing_shows_empty_state_when_no_published_entries(): void
    {
        FaqEntry::query()->delete();
        FaqEntry::factory()->create(['question' => 'Draft only question?']);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('No public FAQs are available yet.');
        $response->assertDontSee('Draft only question?');
    }

    private function actingAsSuperAdmin(): User
    {
        $admin = User::factory()->create(['status' => User::StatusActive]);
        $admin->assignRole('system-super-admin');

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $admin;
    }
}
