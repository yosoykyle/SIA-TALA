<?php

namespace Tests\Feature;

use App\Actions\Graduation\GraduationEligibilitySnapshotService;
use App\Filament\Resources\GraduationReviewBatches\GraduationReviewBatchResource;
use App\Filament\Resources\GraduationReviewBatches\Pages\ViewGraduationReviewBatch;
use App\Filament\Resources\GraduationReviewBatches\RelationManagers\MembersRelationManager;
use App\Models\GraduationReviewBatch;
use App\Models\GraduationReviewMember;
use App\Models\GraduationSnapshot;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class GraduationReviewBatchFilamentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        foreach ([User::StaffRoleRegistrar, User::StaffRoleAcademicHead, User::StaffRoleSystemSuperAdmin, 'student'] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function resource_is_registered_but_unreachable_for_every_role(): void
    {
        $this->assertContains(GraduationReviewBatchResource::class, Filament::getPanel('admin')->getResources());
        $this->assertFalse(GraduationReviewBatchResource::shouldRegisterNavigation());

        foreach ([User::StaffRoleRegistrar, User::StaffRoleAcademicHead, User::StaffRoleSystemSuperAdmin, 'student'] as $role) {
            $this->actingAs($this->staff($role));
            Filament::setCurrentPanel(Filament::getPanel('admin'));

            $this->assertFalse(GraduationReviewBatchResource::canAccess(), $role);
            $this->get(GraduationReviewBatchResource::getUrl('index'))->assertForbidden();
        }
    }

    #[Test]
    public function registrar_cannot_reach_batch_pages_while_historical_records_remain_preserved(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $batch = GraduationReviewBatch::factory()->create(['created_by' => $registrar->id]);
        $member = GraduationReviewMember::factory()->create(['graduation_review_batch_id' => $batch->id]);
        $snapshot = GraduationSnapshot::factory()->create(['graduation_review_member_id' => $member->id]);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->get(GraduationReviewBatchResource::getUrl('index'))->assertForbidden();
        $this->get(GraduationReviewBatchResource::getUrl('view', ['record' => $batch]))->assertForbidden();
        $this->assertDatabaseHas('graduation_review_batches', ['id' => $batch->id]);
        $this->assertDatabaseHas('graduation_review_members', ['id' => $member->id]);
        $this->assertDatabaseHas('graduation_snapshots', ['id' => $snapshot->id]);
    }

    #[Test]
    public function member_relation_refresh_and_visibility_actions_work(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $batch = GraduationReviewBatch::factory()->create(['created_by' => $registrar->id]);
        $member = GraduationReviewMember::factory()->create([
            'graduation_review_batch_id' => $batch->id,
            'student_profile_id' => StudentProfile::factory()->create()->id,
        ]);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewGraduationReviewBatch::class,
        ])
            ->mountTableAction('refreshSnapshot', $member)
            ->callMountedTableAction()
            ->assertNotified('Eligibility review refreshed');

        $snapshot = GraduationSnapshot::query()->where('graduation_review_member_id', $member->id)->firstOrFail();
        $this->assertContains($snapshot->result_status, GraduationEligibilitySnapshotService::resultStatuses());

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewGraduationReviewBatch::class,
        ])
            ->callTableAction('makeVisible', $member, data: ['visibility_reason' => 'Registrar approved student-facing release.'])
            ->assertNotified('Eligibility review shared with student');

        $this->assertSame($registrar->id, $snapshot->fresh()->made_visible_by);
        $this->assertNotNull($snapshot->fresh()->made_visible_at);
        $this->assertSame('Registrar approved student-facing release.', $snapshot->fresh()->visibility_reason);

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewGraduationReviewBatch::class,
        ])
            ->callTableAction('hideVisible', $member)
            ->assertNotified('Eligibility review is no longer shared');

        $this->assertSame($registrar->id, $snapshot->fresh()->made_visible_by);
        $this->assertNull($snapshot->fresh()->made_visible_at);
        $this->assertSame('Hidden by Registrar.', $snapshot->fresh()->visibility_reason);

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewGraduationReviewBatch::class,
        ])
            ->mountTableBulkAction('refreshSelectedSnapshots', [$member])
            ->callMountedTableBulkAction()
            ->assertNotified('Selected eligibility reviews refreshed');

        $this->assertSame(2, $member->snapshots()->count());
    }

    #[Test]
    public function academic_head_cannot_reach_batch_pages_but_retains_historical_read_policy(): void
    {
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $batch = GraduationReviewBatch::factory()->create(['created_by' => $registrar->id]);
        $member = GraduationReviewMember::factory()->create([
            'graduation_review_batch_id' => $batch->id,
            'student_profile_id' => StudentProfile::factory()->create()->id,
        ]);
        GraduationSnapshot::factory()->create([
            'graduation_review_member_id' => $member->id,
            'made_visible_by' => $registrar->id,
            'made_visible_at' => now(),
            'visibility_reason' => 'Registrar approved student-facing release.',
        ]);

        $this->actingAs($academicHead);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->get(GraduationReviewBatchResource::getUrl('view', ['record' => $batch]))->assertForbidden();

        $this->assertTrue($academicHead->can('view', $batch));
        $this->assertTrue($academicHead->can('view', $member));
        $this->assertFalse($academicHead->can('refreshSnapshot', $member));
        $this->assertFalse($academicHead->can('updateVisibility', $member->latestSnapshot));
        $this->assertFalse($academicHead->can('refreshAnySnapshot', GraduationReviewMember::class));
        $this->assertDatabaseHas('graduation_snapshots', [
            'graduation_review_member_id' => $member->id,
        ]);
    }

    #[Test]
    public function system_super_admin_can_view_but_cannot_manage_member_snapshots_or_visibility(): void
    {
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $batch = GraduationReviewBatch::factory()->create(['created_by' => $superAdmin->id]);
        $member = GraduationReviewMember::factory()->create([
            'graduation_review_batch_id' => $batch->id,
            'student_profile_id' => StudentProfile::factory()->create()->id,
        ]);
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewGraduationReviewBatch::class,
        ])
            ->assertCanSeeTableRecords([$member])
            ->assertTableActionHidden('refreshSnapshot', $member)
            ->assertTableActionHidden('makeVisible', $member)
            ->assertTableActionHidden('hideVisible', $member)
            ->assertTableBulkActionHidden('refreshSelectedSnapshots');

        $this->assertFalse($superAdmin->can('refreshSnapshot', $member));
        $this->assertFalse($superAdmin->can('refreshAnySnapshot', GraduationReviewMember::class));
    }

    #[Test]
    public function visibility_action_requires_a_reason(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $batch = GraduationReviewBatch::factory()->create();
        $member = GraduationReviewMember::factory()->create(['graduation_review_batch_id' => $batch->id]);
        GraduationSnapshot::factory()->create(['graduation_review_member_id' => $member->id]);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewGraduationReviewBatch::class,
        ])
            ->callTableAction('makeVisible', $member, data: ['visibility_reason' => ''])
            ->assertHasTableActionErrors(['visibility_reason' => 'required']);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
