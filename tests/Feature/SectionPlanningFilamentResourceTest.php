<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\User;
use App\Policies\SectionPolicy;
use Tests\TestCase;

class SectionPlanningFilamentResourceTest extends TestCase
{
    public function test_section_planning_resource_exposes_typed_solver_readiness_fields(): void
    {
        $resource = $this->resourceSource('Sections/SectionResource.php');
        $form = $this->resourceSource('Sections/Schemas/SectionForm.php');
        $table = $this->resourceSource('Sections/Tables/SectionsTable.php');
        $createPage = $this->resourceSource('Sections/Pages/CreateSection.php');
        $editPage = $this->resourceSource('Sections/Pages/EditSection.php');

        $this->assertStringContainsString("'Registrar'", $resource);
        $this->assertStringContainsString('Section Planning', $resource);
        $this->assertStringContainsString('CreateSection::route', $resource);
        $this->assertStringContainsString('EditSection::route', $resource);

        foreach ([
            "Select::make('term_id')",
            "Select::make('program_id')",
            "Select::make('curriculum_id')",
            "Select::make('year_level')",
            "Select::make('curriculum_period')",
            "Select::make('modality')",
            "TextInput::make('name')",
            "Select::make('room')",
            "TextInput::make('max_seats')",
            "TextInput::make('enrolled_count')",
        ] as $typedField) {
            $this->assertStringContainsString($typedField, $form);
        }

        $this->assertStringContainsString('Room::selectOptions', $form);
        $this->assertStringContainsString('Section::MaxRescueSeats', $form);
        $this->assertStringContainsString('Section::modalityRequiresRoom', $form);
        $this->assertStringContainsString('SectionPlanningService', $createPage);
        $this->assertStringContainsString('SectionPlanningService', $editPage);
        $this->assertStringNotContainsString('DeleteAction::make()', $editPage);
        $this->assertStringNotContainsString('DeleteBulkAction::make()', $table);
    }

    public function test_schedule_drafts_expose_service_backed_generate_action_not_generic_crud(): void
    {
        $listPage = $this->resourceSource('ScheduleGenerationRuns/Pages/ListScheduleGenerationRuns.php');

        $this->assertStringContainsString("Action::make('generateSchedule')", $listPage);
        $this->assertStringContainsString("Select::make('term_id')", $listPage);
        $this->assertStringContainsString('ScheduleGenerationService', $listPage);
        $this->assertStringContainsString('Creates an immutable input snapshot', $listPage);
        $this->assertStringNotContainsString('CreateAction::make()', $listPage);
    }

    public function test_section_policy_limits_planning_to_schedule_managers_and_blocks_delete(): void
    {
        $policy = app(SectionPolicy::class);
        $manager = new class extends User
        {
            public function can($abilities, $arguments = []): bool
            {
                return $abilities === 'manage-schedules';
            }
        };
        $viewer = new class extends User
        {
            public function can($abilities, $arguments = []): bool
            {
                return false;
            }
        };

        $section = new Section;

        $this->assertTrue($policy->viewAny($manager));
        $this->assertTrue($policy->create($manager));
        $this->assertTrue($policy->update($manager, $section));
        $this->assertFalse($policy->delete($manager, $section));
        $this->assertFalse($policy->viewAny($viewer));
    }

    private function resourceSource(string $relativePath): string
    {
        $source = file_get_contents(app_path("Filament/Resources/{$relativePath}"));

        $this->assertIsString($source);

        return $source;
    }
}
