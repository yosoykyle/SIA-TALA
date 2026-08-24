<?php

namespace Tests\Feature;

use App\Actions\SystemAdministration\GovernanceEvidenceProjection;
use App\Filament\Pages\GovernanceAudit;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL92ERetentionDisposalTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function retired_disposal_workflow_is_absent_and_the_exact_mvp_boundary_is_reachable(): void
    {
        Role::query()->firstOrCreate(['name' => User::StaffRoleSystemSuperAdmin, 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::StatusActive]);
        $admin->assignRole(User::StaffRoleSystemSuperAdmin);

        $this->assertFalse(Schema::hasTable('disposal_reviews'));
        $this->assertFileDoesNotExist(app_path('Models/DisposalReview.php'));
        $this->assertFileDoesNotExist(app_path('Enums/RetentionCategory.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Resources/DisposalReviews/DisposalReviewResource.php'));
        $this->assertFileDoesNotExist(config_path('tala_retention.php'));

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(GovernanceAudit::class)
            ->call('setActiveTab', GovernanceEvidenceProjection::PrivacyRetention)
            ->assertSee('Automatic retention disposal: Not provided in this MVP')
            ->assertSee('External compliance status: Not evaluated by TALA')
            ->assertSee('performs no disposal action');
    }
}
