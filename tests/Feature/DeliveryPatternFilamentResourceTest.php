<?php

namespace Tests\Feature;

use App\Models\DeliveryPattern;
use App\Models\SectionDeliveryGroup;
use App\Models\User;
use App\Policies\DeliveryPatternPolicy;
use App\Policies\SectionDeliveryGroupPolicy;
use Tests\TestCase;

class DeliveryPatternFilamentResourceTest extends TestCase
{
    public function test_delivery_pattern_and_group_resources_are_service_backed_admin_surfaces(): void
    {
        $patternResource = $this->resourceSource('DeliveryPatterns/DeliveryPatternResource.php');
        $patternTable = $this->resourceSource('DeliveryPatterns/Tables/DeliveryPatternsTable.php');
        $patternCreatePage = $this->resourceSource('DeliveryPatterns/Pages/CreateDeliveryPattern.php');
        $patternEditPage = $this->resourceSource('DeliveryPatterns/Pages/EditDeliveryPattern.php');
        $groupResource = $this->resourceSource('SectionDeliveryGroups/SectionDeliveryGroupResource.php');
        $groupForm = $this->resourceSource('SectionDeliveryGroups/Schemas/SectionDeliveryGroupForm.php');
        $groupCreatePage = $this->resourceSource('SectionDeliveryGroups/Pages/CreateSectionDeliveryGroup.php');
        $groupEditPage = $this->resourceSource('SectionDeliveryGroups/Pages/EditSectionDeliveryGroup.php');
        $sectionResource = $this->resourceSource('Sections/SectionResource.php');
        $relationManager = $this->resourceSource('Sections/RelationManagers/DeliveryGroupsRelationManager.php');

        $this->assertStringContainsString("'Registrar'", $patternResource);
        $this->assertStringContainsString('Delivery Patterns', $patternResource);
        $this->assertStringContainsString('DeliveryPatternService', $patternCreatePage);
        $this->assertStringContainsString('DeliveryPatternService', $patternEditPage);
        $this->assertStringContainsString("Action::make('cloneVersion')", $patternTable);
        $this->assertStringNotContainsString('DeleteAction::make()', $patternEditPage);

        $this->assertStringContainsString("'Registrar'", $groupResource);
        $this->assertStringContainsString('Section Delivery Groups', $groupResource);
        $this->assertStringContainsString("Select::make('section_id')", $groupForm);
        $this->assertStringContainsString("Select::make('delivery_pattern_id')", $groupForm);
        $this->assertStringContainsString('SectionDeliveryGroupService', $groupCreatePage);
        $this->assertStringContainsString('SectionDeliveryGroupService', $groupEditPage);
        $this->assertStringNotContainsString('DeleteAction::make()', $groupEditPage);

        $this->assertStringContainsString('DeliveryGroupsRelationManager::class', $sectionResource);
        $this->assertStringContainsString('CreateAction::make()', $relationManager);
        $this->assertStringContainsString('EditAction::make()', $relationManager);
        $this->assertStringContainsString('SectionDeliveryGroupService', $relationManager);
        $this->assertStringNotContainsString('DissociateAction', $relationManager);
        $this->assertStringNotContainsString('DeleteBulkAction', $relationManager);
    }

    public function test_delivery_pattern_and_group_policies_limit_mutation_to_section_or_schedule_managers(): void
    {
        $patternPolicy = app(DeliveryPatternPolicy::class);
        $groupPolicy = app(SectionDeliveryGroupPolicy::class);
        $manager = new class extends User
        {
            public function can($abilities, $arguments = []): bool
            {
                return in_array($abilities, ['manage-sections', 'manage-schedules'], true);
            }
        };
        $viewer = new class extends User
        {
            public function can($abilities, $arguments = []): bool
            {
                return $abilities === 'view-global-records';
            }
        };
        $blocked = new class extends User
        {
            public function can($abilities, $arguments = []): bool
            {
                return false;
            }
        };

        $pattern = new DeliveryPattern;
        $group = new SectionDeliveryGroup;

        $this->assertTrue($patternPolicy->viewAny($manager));
        $this->assertTrue($patternPolicy->create($manager));
        $this->assertTrue($patternPolicy->update($manager, $pattern));
        $this->assertFalse($patternPolicy->delete($manager, $pattern));
        $this->assertTrue($patternPolicy->viewAny($viewer));
        $this->assertFalse($patternPolicy->create($viewer));
        $this->assertFalse($patternPolicy->viewAny($blocked));

        $this->assertTrue($groupPolicy->viewAny($manager));
        $this->assertTrue($groupPolicy->create($manager));
        $this->assertTrue($groupPolicy->update($manager, $group));
        $this->assertFalse($groupPolicy->delete($manager, $group));
        $this->assertTrue($groupPolicy->viewAny($viewer));
        $this->assertFalse($groupPolicy->create($viewer));
        $this->assertFalse($groupPolicy->viewAny($blocked));
    }

    private function resourceSource(string $relativePath): string
    {
        $source = file_get_contents(app_path("Filament/Resources/{$relativePath}"));

        $this->assertIsString($source);

        return $source;
    }
}
