<?php

namespace Tests\Feature;

use App\Filament\Resources\GraduationReviewBatches\Pages\EditGraduationReviewBatch;
use App\Filament\Resources\GraduationReviewBatches\Pages\ViewGraduationReviewBatch;
use App\Filament\Resources\GraduationReviewBatches\RelationManagers\MembersRelationManager;
use App\Filament\Student\Pages\Completion;
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

/**
 * TAL-90C: Staff Visibility and Student-Safe Completion Regression.
 *
 * Owning contract: PRD 11_student_lifecycle.md Section 11.3.1 rules 8-9 (staff-only default
 * visibility, Registrar-exposed simplified student view, and retraction of the student-facing
 * view when a Graduation Review Batch membership is deactivated). Complements the visibility
 * and student-safe projection coverage already in GraduationReviewBatchFilamentTest and
 * StudentHubCompletionReviewTest by proving inactive-member behavior.
 */
final class TAL90CStaffVisibilityCompletionRegressionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        foreach ([User::StaffRoleRegistrar, User::StaffRoleSystemSuperAdmin, 'student'] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function delete_action_soft_deactivates_member_and_preserves_snapshot_history(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $batch = GraduationReviewBatch::factory()->create(['created_by' => $registrar->id]);
        $member = GraduationReviewMember::factory()->create([
            'graduation_review_batch_id' => $batch->id,
            'student_profile_id' => StudentProfile::factory()->create()->id,
            'is_active' => true,
        ]);
        $snapshot = GraduationSnapshot::factory()->create(['graduation_review_member_id' => $member->id]);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // The built-in Delete action is available where the relation manager is editable (Edit page).
        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => EditGraduationReviewBatch::class,
        ])->callTableAction('delete', $member);

        // Row is retained but deactivated (soft-remove from the review list).
        $this->assertDatabaseHas('graduation_review_members', [
            'id' => $member->id,
            'is_active' => false,
        ]);
        // Snapshot history is preserved for staff records.
        $this->assertDatabaseHas('graduation_snapshots', ['id' => $snapshot->id]);
    }

    #[Test]
    public function refresh_snapshot_is_blocked_on_an_inactive_member(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $batch = GraduationReviewBatch::factory()->create(['created_by' => $registrar->id]);
        $inactive = GraduationReviewMember::factory()->create([
            'graduation_review_batch_id' => $batch->id,
            'student_profile_id' => StudentProfile::factory()->create()->id,
            'is_active' => false,
        ]);

        $this->assertFalse($registrar->can('refreshSnapshot', $inactive));

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewGraduationReviewBatch::class,
        ])->assertTableActionHidden('refreshSnapshot', $inactive);
    }

    #[Test]
    public function bulk_refresh_skips_inactive_members_and_only_regenerates_active_ones(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $batch = GraduationReviewBatch::factory()->create(['created_by' => $registrar->id]);

        $active = GraduationReviewMember::factory()->create([
            'graduation_review_batch_id' => $batch->id,
            'student_profile_id' => StudentProfile::factory()->create()->id,
            'is_active' => true,
        ]);
        $inactive = GraduationReviewMember::factory()->create([
            'graduation_review_batch_id' => $batch->id,
            'student_profile_id' => StudentProfile::factory()->create()->id,
            'is_active' => false,
        ]);
        GraduationSnapshot::factory()->create(['graduation_review_member_id' => $active->id, 'version' => 1]);
        GraduationSnapshot::factory()->create(['graduation_review_member_id' => $inactive->id, 'version' => 1]);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewGraduationReviewBatch::class,
        ])
            ->mountTableBulkAction('refreshSelectedSnapshots', [$active, $inactive])
            ->callMountedTableBulkAction();

        $this->assertSame(2, $active->snapshots()->count());
        $this->assertSame(1, $inactive->snapshots()->count());
    }

    #[Test]
    public function deactivated_member_snapshot_no_longer_appears_on_student_completion_page(): void
    {
        $student = $this->student();
        $profile = StudentProfile::factory()->create(['user_id' => $student->id]);
        $member = $this->member($profile);
        GraduationSnapshot::factory()->create([
            'graduation_review_member_id' => $member->id,
            'result_status' => 'Blocked: Pending Grade',
            'made_visible_at' => now(),
            'evaluation_snapshot' => $this->snapshotPayload('Blocked: Pending Grade'),
        ]);

        // Registrar removes the student from the review list after exposure.
        $member->update(['is_active' => false]);

        $this->actingAs($student);
        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::test(Completion::class)
            ->assertSee('No completion eligibility review has been shared')
            ->assertDontSee('Blocked: Pending Grade')
            ->assertDontSee('Please contact the Registrar');
    }

    #[Test]
    public function active_member_exposed_snapshot_still_shows_student_safe_content(): void
    {
        $student = $this->student();
        $profile = StudentProfile::factory()->create(['user_id' => $student->id]);
        $member = $this->member($profile);
        GraduationSnapshot::factory()->create([
            'graduation_review_member_id' => $member->id,
            'result_status' => 'Blocked: Pending Grade',
            'made_visible_at' => now(),
            'evaluation_snapshot' => $this->snapshotPayload('Blocked: Pending Grade'),
        ]);

        $this->assertTrue($member->fresh()->is_active);

        $this->actingAs($student);
        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::test(Completion::class)
            ->assertSee('Review blocked: Pending Grade')
            ->assertSee('Please contact the Registrar')
            ->assertSee('Registrar Office')
            ->assertDontSee('Private staff evidence');
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }

    private function student(): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole('student');

        return $user;
    }

    private function member(StudentProfile $profile): GraduationReviewMember
    {
        return GraduationReviewMember::factory()->create([
            'graduation_review_batch_id' => GraduationReviewBatch::factory()->create()->id,
            'student_profile_id' => $profile->id,
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function snapshotPayload(string $status): array
    {
        return [
            'student' => ['student_number' => 'SIA-2026-0001', 'name' => 'Sample Student'],
            'program' => ['code' => 'BSIT', 'name' => 'Information Technology'],
            'curriculum_version' => ['id' => 1, 'name' => 'BSIT 2026'],
            'generated' => ['at' => now()->toISOString(), 'by' => 'Registrar'],
            'result_status' => $status,
            'blocker_groups' => [['key' => 'pending_grade', 'label' => 'Pending Grade', 'student_label' => 'Pending Grade']],
            'completed_requirements' => [],
            'current_enrollments' => [],
            'missing_requirements' => [],
            'failed_requirements' => [],
            'pending_grade_requirements' => [['course_code' => 'CAP-102', 'title' => 'Capstone 2']],
            'inc_requirements' => [],
            'withdrawn_or_dropped_requirements' => [],
            'accepted_credits' => [],
            'approved_exceptions' => [],
            'active_holds' => [['label' => 'Registrar Hold', 'student_message' => 'Please contact the Registrar']],
            'clearance_blockers' => [],
            'remaining_units' => 3.0,
            'source_references' => [['type' => 'private_note', 'label' => 'Private staff evidence']],
            'student_projection' => [
                'result_status' => $status,
                'remaining_requirements' => [],
                'pending_grade_blockers' => ['CAP-102 Capstone 2'],
                'inc_blockers' => [],
                'hold_or_clearance_labels' => ['Registrar Hold'],
                'required_action' => 'Please contact the Registrar',
                'office_to_contact' => 'Registrar Office',
            ],
        ];
    }
}
