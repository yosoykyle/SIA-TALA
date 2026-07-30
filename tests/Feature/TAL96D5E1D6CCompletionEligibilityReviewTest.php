<?php

namespace Tests\Feature;

use App\Actions\Graduation\GraduationEligibilitySnapshotService;
use App\Filament\Resources\GraduationReviewBatches\GraduationReviewBatchResource;
use App\Filament\Resources\GraduationReviewBatches\Pages\ViewGraduationReviewBatch;
use App\Filament\Resources\GraduationReviewBatches\RelationManagers\MembersRelationManager;
use App\Filament\Student\Pages\Completion;
use App\Models\GraduationReviewBatch;
use App\Models\GraduationReviewMember;
use App\Models\GraduationSnapshot;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL96D5E1D6CCompletionEligibilityReviewTest extends TestCase
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
    public function system_super_admin_can_review_but_cannot_mutate_an_individual_completion_review(): void
    {
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $batch = GraduationReviewBatch::factory()->create();
        $member = GraduationReviewMember::factory()->create([
            'graduation_review_batch_id' => $batch->id,
        ]);
        $snapshot = GraduationSnapshot::factory()->create([
            'graduation_review_member_id' => $member->id,
        ]);

        $this->assertTrue($superAdmin->can('view', $batch));
        $this->assertTrue($superAdmin->can('view', $member));
        $this->assertTrue($superAdmin->can('view', $snapshot));
        $this->assertFalse($superAdmin->can('create', GraduationReviewBatch::class));
        $this->assertFalse($superAdmin->can('refreshSnapshot', $member));
        $this->assertFalse($superAdmin->can('updateVisibility', $snapshot));

        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertTrue(GraduationReviewBatchResource::canAccess());
        $this->assertFalse(GraduationReviewBatchResource::canCreate());

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewGraduationReviewBatch::class,
        ])
            ->assertCanSeeTableRecords([$member])
            ->assertTableActionHidden('refreshSnapshot', $member)
            ->assertTableActionHidden('makeVisible', $member)
            ->assertTableBulkActionHidden('refreshSelectedSnapshots');
    }

    #[Test]
    public function snapshot_service_rejects_a_non_registrar_actor(): void
    {
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $member = GraduationReviewMember::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(GraduationEligibilitySnapshotService::class)->generate($member, $superAdmin);
    }

    #[Test]
    public function registrar_closes_a_review_batch_through_one_consistent_action(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $batch = GraduationReviewBatch::factory()->create([
            'state' => GraduationReviewBatch::StateOpen,
            'closed_at' => null,
        ]);
        $member = GraduationReviewMember::factory()->create([
            'graduation_review_batch_id' => $batch->id,
        ]);
        $snapshot = GraduationSnapshot::factory()->create([
            'graduation_review_member_id' => $member->id,
        ]);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ViewGraduationReviewBatch::class, ['record' => $batch->getRouteKey()])
            ->assertActionVisible('closeReview')
            ->callAction('closeReview')
            ->assertNotified('Completion review closed')
            ->assertActionHidden('closeReview');

        $this->assertSame(GraduationReviewBatch::StateClosed, $batch->fresh()->state);
        $this->assertNotNull($batch->fresh()->closed_at);
        $this->assertFalse($registrar->can('update', $batch->fresh()));
        $this->assertFalse($registrar->can('refreshSnapshot', $member));
        $this->assertFalse($registrar->can('updateVisibility', $snapshot));
    }

    #[Test]
    public function registrar_sees_the_primary_blocker_from_the_generated_snapshot_contract(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $batch = GraduationReviewBatch::factory()->create();
        $member = GraduationReviewMember::factory()->create([
            'graduation_review_batch_id' => $batch->id,
        ]);
        GraduationSnapshot::factory()->create([
            'graduation_review_member_id' => $member->id,
            'result_status' => GraduationEligibilitySnapshotService::ResultBlockedHoldOrClearance,
            'evaluation_snapshot' => [
                'blocker_groups' => [
                    [
                        'key' => 'hold_or_clearance',
                        'label' => 'Hold or Clearance',
                        'student_label' => 'Hold or Clearance',
                    ],
                ],
                'student_projection' => [
                    'remaining_units' => 3.0,
                ],
            ],
        ]);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewGraduationReviewBatch::class,
        ])
            ->assertSee('3.0 units remaining')
            ->assertSee('Hold or Clearance');
    }

    #[Test]
    public function registrar_must_confirm_single_and_bulk_eligibility_refresh_consequences(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $batch = GraduationReviewBatch::factory()->create();
        $member = GraduationReviewMember::factory()->create([
            'graduation_review_batch_id' => $batch->id,
        ]);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewGraduationReviewBatch::class,
        ])
            ->mountTableAction('refreshSnapshot', $member)
            ->assertMountedActionModalSee([
                'Refresh this eligibility review?',
                'This creates a new immutable eligibility snapshot',
            ])
            ->callMountedTableAction()
            ->assertNotified('Eligibility review refreshed');

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewGraduationReviewBatch::class,
        ])
            ->mountTableBulkAction('refreshSelectedSnapshots', [$member])
            ->assertMountedActionModalSee([
                'Refresh selected eligibility reviews?',
                'This creates a new immutable snapshot for every authorized active student selected',
            ])
            ->callMountedTableBulkAction()
            ->assertNotified('Selected eligibility reviews refreshed');

        $this->assertSame(2, $member->snapshots()->count());
    }

    #[Test]
    public function student_result_explains_eligibility_without_claiming_degree_conferral(): void
    {
        $student = $this->staff('student');
        $profile = StudentProfile::factory()->create(['user_id' => $student->id]);
        $member = GraduationReviewMember::factory()->create([
            'student_profile_id' => $profile->id,
            'is_active' => true,
        ]);
        GraduationSnapshot::factory()->create([
            'graduation_review_member_id' => $member->id,
            'result_status' => GraduationEligibilitySnapshotService::ResultComplete,
            'made_visible_at' => now(),
            'evaluation_snapshot' => [
                'student_projection' => [
                    'result_status' => GraduationEligibilitySnapshotService::ResultComplete,
                    'remaining_units' => 0.0,
                    'remaining_requirements' => [],
                    'failed_requirements' => [],
                    'in_progress_requirements' => [],
                    'pending_grade_blockers' => [],
                    'inc_blockers' => [],
                    'hold_or_clearance_items' => [],
                    'required_action' => 'No further action is required.',
                    'offices_to_contact' => ['Registrar Office'],
                ],
            ],
        ]);

        $this->actingAs($student);
        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::test(Completion::class)
            ->assertSee('Requirements complete for Registrar review')
            ->assertSee('This eligibility review does not confer a degree')
            ->assertSee('No action is required for this review')
            ->assertDontSee('Snapshot version')
            ->assertDontSee('Degree conferred');
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
