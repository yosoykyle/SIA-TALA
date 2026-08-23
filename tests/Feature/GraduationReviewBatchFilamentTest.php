<?php

namespace Tests\Feature;

use App\Filament\Resources\GraduationReviewBatches\GraduationReviewBatchResource;
use App\Models\GraduationReviewBatch;
use App\Models\GraduationReviewMember;
use App\Models\GraduationSnapshot;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class GraduationReviewBatchFilamentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        foreach ([User::StaffRoleRegistrar, User::StaffRoleAcademicHead, User::StaffRoleSystemSuperAdmin] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function legacy_batch_resource_and_mutation_routes_are_not_registered(): void
    {
        $this->assertNotContains(GraduationReviewBatchResource::class, Filament::getPanel('admin')->getResources());
        $this->assertFalse(Route::has('filament.admin.resources.graduation-review-batches.index'));
        $this->assertFalse(Route::has('filament.admin.resources.graduation-review-batches.create'));
        $this->assertFalse(Route::has('filament.admin.resources.graduation-review-batches.edit'));
        $this->assertFalse(Route::has('filament.admin.resources.graduation-review-batches.view'));
    }

    #[Test]
    public function historical_batch_member_and_snapshot_rows_remain_preserved_and_readable_by_policy(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $batch = GraduationReviewBatch::factory()->create(['created_by' => $registrar->id]);
        $member = GraduationReviewMember::factory()->create(['graduation_review_batch_id' => $batch->id]);
        $snapshot = GraduationSnapshot::factory()->create(['graduation_review_member_id' => $member->id]);

        $this->assertTrue($academicHead->can('view', $batch));
        $this->assertTrue($academicHead->can('view', $member));
        $this->assertTrue($academicHead->can('view', $snapshot));
        $this->assertDatabaseHas('graduation_review_batches', ['id' => $batch->id]);
        $this->assertDatabaseHas('graduation_review_members', ['id' => $member->id]);
        $this->assertDatabaseHas('graduation_snapshots', ['id' => $snapshot->id]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
