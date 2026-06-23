<?php

namespace Tests\Feature;

use App\Filament\Resources\GradeSubmissionPackages\GradeSubmissionPackageResource;
use App\Filament\Resources\GradeSubmissionPackages\Schemas\GradeSubmissionPackageInfolist;
use App\Filament\Resources\GradeSubmissionPackages\Tables\GradeSubmissionPackagesTable;
use App\Models\GradeSubmissionPackage;
use App\Models\User;
use App\Policies\GradeSubmissionPackagePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GradeSubmissionPackageFilamentResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_grade_submission_package_resource_is_registrar_list_view_queue_not_crud(): void
    {
        foreach ([
            'GradeSubmissionPackages/Pages/CreateGradeSubmissionPackage.php',
            'GradeSubmissionPackages/Pages/EditGradeSubmissionPackage.php',
            'GradeSubmissionPackages/Schemas/GradeSubmissionPackageForm.php',
        ] as $relativePath) {
            $this->assertFileDoesNotExist(app_path("Filament/Resources/{$relativePath}"));
        }

        $resource = $this->source(GradeSubmissionPackageResource::class);

        $this->assertStringContainsString("'index' => ListGradeSubmissionPackages::route('/')", $resource);
        $this->assertStringContainsString("'view' => ViewGradeSubmissionPackage::route('/{record}')", $resource);
        $this->assertStringNotContainsString("'create'", $resource);
        $this->assertStringNotContainsString("'edit'", $resource);
        $this->assertStringNotContainsString('GradeSubmissionPackageForm', $resource);
        $this->assertStringContainsString('canCreate(): bool', $resource);
        $this->assertStringContainsString('return false;', $resource);
    }

    public function test_registrar_queue_table_exposes_only_typed_return_and_verify_actions(): void
    {
        $table = $this->source(GradeSubmissionPackagesTable::class);

        foreach (['returnForRevision', 'verifyAndFinalize'] as $action) {
            $this->assertStringContainsString($action, $table);
            $this->assertStringContainsString("can('{$action}'", $table);
        }

        $this->assertStringContainsString('GradeSubmissionPackageService', $table);
        $this->assertStringContainsString('Return Grade Package', $table);
        $this->assertStringContainsString('Verify and Finalize Grade Package', $table);
        $this->assertStringContainsString("SelectFilter::make('state')", $table);
        $this->assertStringContainsString("TextColumn::make('items_count')", $table);
        $this->assertStringNotContainsString('EditAction::make', $table);
        $this->assertStringNotContainsString('DeleteBulkAction::make', $table);
        $this->assertStringNotContainsString('BulkActionGroup::make', $table);
    }

    public function test_infolist_shows_package_header_and_item_snapshots(): void
    {
        $infolist = $this->source(GradeSubmissionPackageInfolist::class);

        foreach ([
            'roster_snapshot_checksum',
            'registrarReviewer.name',
            'return_reason',
            'RepeatableEntry::make(\'items\')',
            'entered_values.prelim_grade',
            'entered_values.midterm_grade',
            'entered_values.final_grade',
            'derived_grade.grade',
            'derived_grade.remarks',
        ] as $expected) {
            $this->assertStringContainsString($expected, $infolist);
        }
    }

    public function test_policy_allows_registrar_actions_only_for_submitted_packages(): void
    {
        $this->seedPermissions();

        $registrar = User::factory()->create();
        $registrar->assignRole('registrar');
        $registrar->givePermissionTo('verify-grade-submissions');

        $academicHead = User::factory()->create();
        $academicHead->assignRole('academic-head');
        $academicHead->givePermissionTo('view-grade-submission-progress');

        $submitted = new GradeSubmissionPackage(['state' => GradeSubmissionPackage::StateSubmitted]);
        $returned = new GradeSubmissionPackage(['state' => GradeSubmissionPackage::StateReturned]);
        $policy = new GradeSubmissionPackagePolicy;

        $this->assertTrue($policy->viewAny($registrar));
        $this->assertTrue($policy->viewAny($academicHead));
        $this->assertTrue($policy->returnForRevision($registrar, $submitted));
        $this->assertTrue($policy->verifyAndFinalize($registrar, $submitted));
        $this->assertFalse($policy->returnForRevision($registrar, $returned));
        $this->assertFalse($policy->verifyAndFinalize($registrar, $returned));
        $this->assertFalse($policy->returnForRevision($academicHead, $submitted));
        $this->assertFalse($policy->verifyAndFinalize($academicHead, $submitted));
        $this->assertFalse($policy->create($registrar));
        $this->assertFalse($policy->update($registrar, $submitted));
        $this->assertFalse($policy->delete($registrar, $submitted));
    }

    private function seedPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['registrar', 'academic-head'] as $role) {
            Role::findOrCreate($role);
        }

        foreach (['verify-grade-submissions', 'view-grade-submission-progress'] as $permission) {
            Permission::findOrCreate($permission);
        }
    }

    private function source(string $class): string
    {
        $reflection = new \ReflectionClass($class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);

        return $source;
    }
}
