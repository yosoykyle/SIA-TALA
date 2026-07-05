<?php

namespace Tests\Feature;

use App\Actions\Scheduling\SectionDeliveryGroupService;
use App\Actions\Scheduling\SectionPlanningService;
use App\Filament\Resources\Sections\Pages\CreateSection;
use App\Filament\Resources\Sections\Pages\EditSection;
use App\Filament\Resources\Sections\Pages\ListSections;
use App\Filament\Resources\Sections\RelationManagers\DeliveryGroupsRelationManager;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class TAL85ASectionDeliveryGroupSourceRecordUiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_test_codex', DB::connection()->getDatabaseName());

        Permission::findOrCreate('manage-schedules', 'web');
        Permission::findOrCreate('manage-sections', 'web');
    }

    public function test_registrar_can_access_registered_section_source_record_surface(): void
    {
        $registrar = $this->registrar();

        $this->assertTrue(Route::has('filament.admin.resources.sections.index'));

        Livewire::actingAs($registrar)
            ->test(ListSections::class)
            ->assertOk();
    }

    public function test_section_form_and_table_use_clean_source_record_fields(): void
    {
        $registrar = $this->registrar();

        Livewire::actingAs($registrar)
            ->test(CreateSection::class)
            ->assertFormFieldExists('term_offering_id')
            ->assertFormFieldExists('code')
            ->assertFormFieldExists('capacity')
            ->assertFormFieldExists('state')
            ->assertFormFieldDoesNotExist('term_id')
            ->assertFormFieldDoesNotExist('program_id')
            ->assertFormFieldDoesNotExist('curriculum_id')
            ->assertFormFieldDoesNotExist('year_level')
            ->assertFormFieldDoesNotExist('curriculum_period')
            ->assertFormFieldDoesNotExist('name')
            ->assertFormFieldDoesNotExist('modality')
            ->assertFormFieldDoesNotExist('room')
            ->assertFormFieldDoesNotExist('max_seats')
            ->assertFormFieldDoesNotExist('enrolled_count');

        Livewire::actingAs($registrar)
            ->test(ListSections::class)
            ->assertTableColumnExists('termOffering.term.label')
            ->assertTableColumnExists('termOffering.curriculumEntry.courseSpecification.course.code')
            ->assertTableColumnExists('code')
            ->assertTableColumnExists('capacity')
            ->assertTableColumnExists('state')
            ->assertTableColumnDoesNotExist('term.term_name')
            ->assertTableColumnDoesNotExist('termOffering.term.term_name')
            ->assertTableColumnDoesNotExist('program.code')
            ->assertTableColumnDoesNotExist('curriculum.version_name')
            ->assertTableColumnDoesNotExist('year_level')
            ->assertTableColumnDoesNotExist('curriculum_period')
            ->assertTableColumnDoesNotExist('name')
            ->assertTableColumnDoesNotExist('modality')
            ->assertTableColumnDoesNotExist('room')
            ->assertTableColumnDoesNotExist('max_seats')
            ->assertTableColumnDoesNotExist('enrolled_count');
    }

    public function test_section_planning_service_accepts_only_clean_section_fields(): void
    {
        $offering = TermOffering::factory()->create();
        $prepared = app(SectionPlanningService::class)->prepareForSave([
            'term_offering_id' => $offering->id,
            'code' => 'BSIT-1A',
            'capacity' => 30,
            'state' => Section::StatePlanned,
            'term_id' => $offering->term_id,
            'program_id' => 999,
            'name' => 'Legacy Name',
            'max_seats' => 99,
            'enrolled_count' => 12,
        ]);

        $this->assertSame([
            'term_offering_id' => $offering->id,
            'code' => 'BSIT-1A',
            'capacity' => 30,
            'state' => Section::StatePlanned,
        ], $prepared);
    }

    public function test_delivery_group_relation_manager_uses_clean_source_record_fields(): void
    {
        $registrar = $this->registrar();
        $section = Section::factory()->create(['capacity' => 30]);

        Livewire::actingAs($registrar)
            ->test(DeliveryGroupsRelationManager::class, [
                'ownerRecord' => $section,
                'pageClass' => EditSection::class,
            ])
            ->assertTableColumnExists('name')
            ->assertTableColumnExists('expected_count')
            ->assertTableColumnExists('modality')
            ->assertTableColumnExists('state')
            ->assertTableColumnDoesNotExist('deliveryPattern.code')
            ->assertTableColumnDoesNotExist('capacity')
            ->assertTableColumnDoesNotExist('assigned_count')
            ->assertTableColumnDoesNotExist('available_seats')
            ->assertTableColumnDoesNotExist('room_required')
            ->assertTableColumnDoesNotExist('room')
            ->assertTableColumnDoesNotExist('status');
    }

    public function test_delivery_group_service_rejects_expected_count_above_section_capacity(): void
    {
        $section = Section::factory()->create(['capacity' => 25]);

        $this->expectException(ValidationException::class);

        app(SectionDeliveryGroupService::class)->save($section, [
            'name' => 'Overflow Cohort',
            'expected_count' => 26,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StatePlanned,
        ]);
    }

    public function test_delivery_group_service_does_not_require_legacy_fields(): void
    {
        $section = Section::factory()->create(['capacity' => 30]);

        $group = app(SectionDeliveryGroupService::class)->save($section, [
            'name' => 'Regular Cohort',
            'expected_count' => 30,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
            'delivery_pattern_id' => 999,
            'capacity' => 999,
            'assigned_count' => 12,
            'room_required' => true,
            'room' => 'ROOM-101',
            'status' => 'ACTIVE',
            'closed_at' => now(),
        ]);

        $this->assertTrue($group->section->is($section));
        $this->assertSame('Regular Cohort', $group->name);
        $this->assertSame(30, $group->expected_count);
        $this->assertSame(TermOffering::ModalityFaceToFace, $group->modality);
        $this->assertSame(SectionDeliveryGroup::StateReady, $group->state);
        $this->assertNull($group->delivery_override);
        $this->assertArrayNotHasKey('delivery_pattern_id', $group->getAttributes());
        $this->assertArrayNotHasKey('capacity', $group->getAttributes());
        $this->assertArrayNotHasKey('assigned_count', $group->getAttributes());
        $this->assertArrayNotHasKey('room_required', $group->getAttributes());
        $this->assertArrayNotHasKey('room', $group->getAttributes());
        $this->assertArrayNotHasKey('status', $group->getAttributes());
        $this->assertArrayNotHasKey('closed_at', $group->getAttributes());
    }

    private function registrar(): User
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->givePermissionTo('manage-schedules');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $registrar;
    }
}
