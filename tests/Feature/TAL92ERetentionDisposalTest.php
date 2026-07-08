<?php

namespace Tests\Feature;

use App\Enums\RetentionCategory;
use App\Filament\Resources\DisposalReviews\DisposalReviewResource;
use App\Filament\Resources\DisposalReviews\Pages\ListDisposalReviews;
use App\Models\DisposalReview;
use App\Models\Hold;
use App\Models\StudentProfile;
use App\Models\User;
use App\Policies\DisposalReviewPolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TAL-92E: Retention Categories + Disposal Review (Build).
 *
 * Owning contract: PRD `13_system_admin_reports_audit.md` §13.7 (Retention
 * and Disposal, including §13.7.5) and §13.8 "Retention/disposal review"
 * row. Direction A (confirmed 2026-07-08): disposal-review is an audited
 * ledger; it never physically deletes or purges any database record.
 */
final class TAL92ERetentionDisposalTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotContains(DB::connection()->getDatabaseName(), ['tala_db', 'tala_test_codex']);

        foreach ([...User::staffRoleNames(), 'student', 'applicant'] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function candidate_table_shows_only_archived_student_profiles_with_correct_retention_category(): void
    {
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $archivedCandidate = StudentProfile::factory()->create(['archived_at' => now()]);
        $activeNonCandidate = StudentProfile::factory()->create(['archived_at' => null]);

        $html = Livewire::test(ListDisposalReviews::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$archivedCandidate])
            ->assertCanNotSeeTableRecords([$activeNonCandidate])
            ->html();

        $this->assertStringContainsString(RetentionCategory::ShortOperational->label(), $html);
    }

    #[Test]
    public function reviewing_a_candidate_with_an_active_blocking_hold_is_blocked_and_logged(): void
    {
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $candidate = StudentProfile::factory()->create(['archived_at' => now()]);
        Hold::query()->create([
            'student_profile_id' => $candidate->id,
            'hold_type' => Hold::TypeRecordRelease,
            'blocking_level' => Hold::BlockingRecordRelease,
            'status' => Hold::StatusActive,
            'reason' => 'Active legal hold for testing.',
            'effective_at' => now()->subDay(),
        ]);

        $this->assertSame(0, DisposalReview::query()->count());
        $this->assertSame(0, Activity::query()->count());

        Livewire::test(ListDisposalReviews::class)
            ->assertOk()
            ->callTableAction('reviewDisposal', $candidate, data: [
                'attestation' => true,
                'reason' => 'Attempting disposal despite hold.',
            ])
            ->assertNotified();

        $this->assertSame(1, DisposalReview::query()->count());
        $review = DisposalReview::query()->sole();
        $this->assertSame(DisposalReview::DecisionBlockedByHold, $review->decision);
        $this->assertSame($candidate->id, $review->student_profile_id);
        $this->assertTrue($review->hold_check_result);
        $this->assertSame(1, Activity::query()->count());
        $this->assertSame('disposal_reviewed', Activity::query()->sole()->event);

        $this->assertDatabaseHas('student_profiles', ['id' => $candidate->id]);
    }

    #[Test]
    public function reviewing_a_candidate_with_no_hold_and_attestation_checked_clears_for_disposal(): void
    {
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $candidate = StudentProfile::factory()->create(['archived_at' => now()]);

        $this->assertSame(0, DisposalReview::query()->count());

        Livewire::test(ListDisposalReviews::class)
            ->assertOk()
            ->callTableAction('reviewDisposal', $candidate, data: [
                'attestation' => true,
                'reason' => 'No active holds found; clearing for disposal.',
            ])
            ->assertNotified();

        $this->assertSame(1, DisposalReview::query()->count());
        $review = DisposalReview::query()->sole();
        $this->assertSame(DisposalReview::DecisionClearedForDisposal, $review->decision);
        $this->assertFalse($review->hold_check_result);
        $this->assertTrue($review->legal_audit_attestation);
        $this->assertSame(1, Activity::query()->count());

        $this->assertDatabaseHas('student_profiles', ['id' => $candidate->id]);
    }

    #[Test]
    public function reviewing_without_checking_attestation_fails_validation_and_writes_no_review(): void
    {
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $candidate = StudentProfile::factory()->create(['archived_at' => now()]);

        Livewire::test(ListDisposalReviews::class)
            ->assertOk()
            ->callTableAction('reviewDisposal', $candidate, data: [
                'attestation' => false,
                'reason' => 'Missing attestation.',
            ])
            ->assertHasTableActionErrors(['attestation']);

        $this->assertSame(0, DisposalReview::query()->count());
        $this->assertDatabaseHas('student_profiles', ['id' => $candidate->id]);
    }

    #[Test]
    public function no_physical_deletion_of_any_student_profile_occurs_in_any_scenario(): void
    {
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $blockedCandidate = StudentProfile::factory()->create(['archived_at' => now()]);
        Hold::query()->create([
            'student_profile_id' => $blockedCandidate->id,
            'hold_type' => Hold::TypeRecordRelease,
            'blocking_level' => Hold::BlockingRecordRelease,
            'status' => Hold::StatusActive,
            'reason' => 'Active hold for testing.',
            'effective_at' => now()->subDay(),
        ]);
        $clearCandidate = StudentProfile::factory()->create(['archived_at' => now()]);

        $totalBefore = StudentProfile::query()->count();

        Livewire::test(ListDisposalReviews::class)
            ->callTableAction('reviewDisposal', $blockedCandidate, data: ['attestation' => true, 'reason' => 'Blocked scenario.'])
            ->callTableAction('reviewDisposal', $clearCandidate, data: ['attestation' => true, 'reason' => 'Cleared scenario.']);

        $this->assertSame($totalBefore, StudentProfile::query()->count());
        $this->assertDatabaseHas('student_profiles', ['id' => $blockedCandidate->id]);
        $this->assertDatabaseHas('student_profiles', ['id' => $clearCandidate->id]);
    }

    #[Test]
    public function only_system_super_admin_can_access_the_disposal_review_resource(): void
    {
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertTrue(DisposalReviewResource::canAccess());

        $policy = app(DisposalReviewPolicy::class);
        $this->assertTrue($policy->viewAny($superAdmin));
        $this->assertTrue($policy->create($superAdmin));
        $this->assertFalse($this->makeReviewUpdateCheck($policy, $superAdmin));

        Livewire::test(ListDisposalReviews::class)->assertOk();

        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleAccounting,
            User::StaffRoleAcademicHead,
            User::StaffRoleFaculty,
        ] as $deniedRole) {
            $denied = $this->staff($deniedRole);
            $this->actingAs($denied);

            $this->assertFalse(DisposalReviewResource::canAccess());
            $this->assertFalse($policy->viewAny($denied));

            Livewire::test(ListDisposalReviews::class)->assertForbidden();
        }
    }

    #[Test]
    public function table_filters_for_retention_category_and_decision_return_expected_rows(): void
    {
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $cleared = StudentProfile::factory()->create(['archived_at' => now()]);
        DisposalReview::factory()->create([
            'student_profile_id' => $cleared->id,
            'decision' => DisposalReview::DecisionClearedForDisposal,
        ]);

        $blocked = StudentProfile::factory()->create(['archived_at' => now()]);
        DisposalReview::factory()->blockedByHold()->create([
            'student_profile_id' => $blocked->id,
        ]);

        $notYetReviewed = StudentProfile::factory()->create(['archived_at' => now()]);

        Livewire::test(ListDisposalReviews::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$cleared, $blocked, $notYetReviewed])
            ->filterTable('decision', DisposalReview::DecisionBlockedByHold)
            ->assertCanSeeTableRecords([$blocked])
            ->assertCanNotSeeTableRecords([$cleared, $notYetReviewed])
            ->resetTableFilters()
            ->filterTable('retention_category', RetentionCategory::ShortOperational->value)
            ->assertCanSeeTableRecords([$cleared, $blocked, $notYetReviewed]);
    }

    private function makeReviewUpdateCheck(DisposalReviewPolicy $policy, User $user): bool
    {
        $review = DisposalReview::factory()->create([
            'student_profile_id' => StudentProfile::factory()->create()->id,
        ]);

        return $policy->update($user, $review) || $policy->delete($user, $review);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
