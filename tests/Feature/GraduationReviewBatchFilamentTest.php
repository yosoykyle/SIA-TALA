<?php

namespace Tests\Feature;

use App\Actions\Graduation\GraduationEligibilitySnapshotService;
use App\Filament\Resources\GraduationReviewBatches\GraduationReviewBatchResource;
use App\Filament\Resources\GraduationReviewBatches\Pages\CreateGraduationReviewBatch;
use App\Filament\Resources\GraduationReviewBatches\Pages\ListGraduationReviewBatches;
use App\Filament\Resources\GraduationReviewBatches\Pages\ViewGraduationReviewBatch;
use App\Filament\Resources\GraduationReviewBatches\RelationManagers\MembersRelationManager;
use App\Models\AcademicYear;
use App\Models\GraduationReviewBatch;
use App\Models\GraduationReviewMember;
use App\Models\GraduationSnapshot;
use App\Models\StudentProfile;
use App\Models\Term;
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
    public function resource_is_registered_and_policy_boundaries_are_enforced(): void
    {
        $this->assertContains(GraduationReviewBatchResource::class, Filament::getPanel('admin')->getResources());

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->assertTrue(GraduationReviewBatchResource::canAccess());
        $this->assertTrue(GraduationReviewBatchResource::canCreate());

        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $this->actingAs($academicHead);
        $this->assertTrue(GraduationReviewBatchResource::canAccess());
        $this->assertFalse(GraduationReviewBatchResource::canCreate());

        $student = $this->staff('student');
        $this->actingAs($student);
        $this->assertFalse(GraduationReviewBatchResource::canAccess());
    }

    #[Test]
    public function registrar_can_create_filter_and_view_batch_records(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $year = AcademicYear::factory()->create(['label' => '2026-2027']);
        $term = Term::factory()->create(['academic_year_id' => $year->id, 'label' => 'Second Semester']);
        $other = GraduationReviewBatch::factory()->create(['state' => 'closed']);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateGraduationReviewBatch::class)
            ->fillForm([
                'academic_year_id' => $year->id,
                'term_id' => $term->id,
                'name' => 'March 2027 Completion Review',
                'state' => 'open',
                'filter_summary' => ['program' => 'BSIT'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $batch = GraduationReviewBatch::query()->where('name', 'March 2027 Completion Review')->firstOrFail();
        $this->assertSame($registrar->id, $batch->created_by);

        Livewire::test(ListGraduationReviewBatches::class)
            ->filterTable('state', 'open')
            ->assertCanSeeTableRecords([$batch])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(ViewGraduationReviewBatch::class, ['record' => $batch->getRouteKey()])
            ->assertSee('March 2027 Completion Review');
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
            ->callTableAction('refreshSnapshot', $member)
            ->assertNotified('Snapshot refreshed');

        $snapshot = GraduationSnapshot::query()->where('graduation_review_member_id', $member->id)->firstOrFail();
        $this->assertContains($snapshot->result_status, GraduationEligibilitySnapshotService::resultStatuses());

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewGraduationReviewBatch::class,
        ])
            ->callTableAction('makeVisible', $member, data: ['visibility_reason' => 'Registrar approved student-facing release.'])
            ->assertNotified('Snapshot visibility updated');

        $this->assertSame($registrar->id, $snapshot->fresh()->made_visible_by);
        $this->assertNotNull($snapshot->fresh()->made_visible_at);
        $this->assertSame('Registrar approved student-facing release.', $snapshot->fresh()->visibility_reason);

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewGraduationReviewBatch::class,
        ])
            ->callTableAction('hideVisible', $member)
            ->assertNotified('Snapshot hidden from Student Hub');

        $this->assertSame($registrar->id, $snapshot->fresh()->made_visible_by);
        $this->assertNull($snapshot->fresh()->made_visible_at);
        $this->assertSame('Hidden by Registrar.', $snapshot->fresh()->visibility_reason);

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewGraduationReviewBatch::class,
        ])
            ->callTableBulkAction('refreshSelectedSnapshots', [$member])
            ->assertNotified('Selected snapshots refreshed');

        $this->assertSame(2, $member->snapshots()->count());
    }

    #[Test]
    public function academic_head_can_view_but_cannot_manage_member_snapshots_or_visibility(): void
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

        Livewire::test(ViewGraduationReviewBatch::class, ['record' => $batch->getRouteKey()])
            ->assertOk()
            ->assertSee($batch->name);

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewGraduationReviewBatch::class,
        ])
            ->assertCanSeeTableRecords([$member])
            ->assertTableActionHidden('refreshSnapshot', $member)
            ->assertTableActionHidden('makeVisible', $member)
            ->assertTableActionHidden('hideVisible', $member)
            ->assertTableBulkActionHidden('refreshSelectedSnapshots');

        $this->assertFalse($academicHead->can('refreshSnapshot', $member));
        $this->assertFalse($academicHead->can('updateVisibility', $member->latestSnapshot));
        $this->assertFalse($academicHead->can('refreshAnySnapshot', GraduationReviewMember::class));
    }

    #[Test]
    public function system_super_admin_can_manage_member_snapshots_and_visibility(): void
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
            ->assertTableActionVisible('refreshSnapshot', $member)
            ->assertTableBulkActionVisible('refreshSelectedSnapshots')
            ->callTableAction('refreshSnapshot', $member)
            ->assertNotified('Snapshot refreshed');

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewGraduationReviewBatch::class,
        ])
            ->assertTableActionVisible('makeVisible', $member)
            ->callTableAction('makeVisible', $member, data: ['visibility_reason' => 'System Super Admin approved release.'])
            ->assertNotified('Snapshot visibility updated');

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewGraduationReviewBatch::class,
        ])
            ->assertTableActionVisible('hideVisible', $member)
            ->callTableAction('hideVisible', $member)
            ->assertNotified('Snapshot hidden from Student Hub');
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
