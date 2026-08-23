<?php

namespace Tests\Feature;

use App\Actions\Completion\CompletionReadinessProjection;
use App\Filament\Pages\CompletionAndTor;
use App\Filament\Student\Pages\Academics;
use App\Filament\Student\Pages\Completion;
use App\Models\GraduationReviewBatch;
use App\Models\GraduationReviewMember;
use App\Models\GraduationSnapshot;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL96D5E1D6CCompletionEligibilityReviewTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        foreach (['student', User::StaffRoleRegistrar, User::StaffRoleAcademicHead] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function canonical_completion_surfaces_replace_the_dormant_student_and_batch_pages(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->assertTrue(CompletionAndTor::canAccess());

        $student = $this->staff('student');
        StudentProfile::factory()->create(['user_id' => $student->id]);
        $this->actingAs($student);
        Filament::setCurrentPanel(Filament::getPanel('student'));
        $this->assertTrue(Academics::canAccess());
        $this->assertFalse(Completion::canAccess());
    }

    #[Test]
    public function legacy_completion_evidence_remains_historical_without_setting_current_readiness(): void
    {
        $student = StudentProfile::factory()->create();
        $batch = GraduationReviewBatch::factory()->create();
        $member = GraduationReviewMember::factory()->create([
            'graduation_review_batch_id' => $batch->id,
            'student_profile_id' => $student->id,
        ]);
        $snapshot = GraduationSnapshot::factory()->create(['graduation_review_member_id' => $member->id]);

        $projection = app(CompletionReadinessProjection::class)->forStudent($student);
        $this->assertNotSame(CompletionReadinessProjection::Conferred, $projection['state']);
        $this->assertDatabaseHas('graduation_snapshots', ['id' => $snapshot->id]);
        $this->assertDatabaseCount('degree_conferrals', 0);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
