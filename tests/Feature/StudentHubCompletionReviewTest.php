<?php

namespace Tests\Feature;

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

final class StudentHubCompletionReviewTest extends TestCase
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
    public function completion_page_shows_empty_state_until_registrar_exposes_a_snapshot(): void
    {
        $student = $this->student();
        $profile = StudentProfile::factory()->create(['user_id' => $student->id]);
        $member = $this->member($profile);
        GraduationSnapshot::factory()->create([
            'graduation_review_member_id' => $member->id,
            'made_visible_at' => null,
            'evaluation_snapshot' => $this->snapshotPayload('Blocked: Missing Requirement'),
        ]);
        $this->actingAs($student);
        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::test(Completion::class)
            ->assertSee('No completion review is visible yet')
            ->assertDontSee('Blocked: Missing Requirement');
    }

    #[Test]
    public function student_sees_only_their_latest_visible_student_safe_snapshot(): void
    {
        $student = $this->student();
        $profile = StudentProfile::factory()->create(['user_id' => $student->id]);
        $otherProfile = StudentProfile::factory()->create();
        $member = $this->member($profile);
        $otherMember = $this->member($otherProfile);
        GraduationSnapshot::factory()->create([
            'graduation_review_member_id' => $member->id,
            'version' => 1,
            'result_status' => 'Blocked: Missing Requirement',
            'made_visible_at' => now()->subDay(),
            'evaluation_snapshot' => $this->snapshotPayload('Blocked: Missing Requirement', 'Private staff evidence 1'),
        ]);
        GraduationSnapshot::factory()->create([
            'graduation_review_member_id' => $member->id,
            'version' => 2,
            'result_status' => 'Blocked: Pending Grade',
            'made_visible_at' => now(),
            'evaluation_snapshot' => $this->snapshotPayload('Blocked: Pending Grade', 'Private staff evidence 2'),
        ]);
        GraduationSnapshot::factory()->create([
            'graduation_review_member_id' => $otherMember->id,
            'result_status' => 'Complete',
            'made_visible_at' => now(),
            'evaluation_snapshot' => $this->snapshotPayload('Complete', 'Other student evidence'),
        ]);
        $this->actingAs($student);
        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::test(Completion::class)
            ->assertSee('Blocked: Pending Grade')
            ->assertSee('Pending Grade')
            ->assertSee('Please contact the Registrar')
            ->assertSee('Registrar Office')
            ->assertDontSee('Blocked: Missing Requirement')
            ->assertDontSee('Private staff evidence')
            ->assertDontSee('Other student evidence')
            ->assertDontSee('Complete');
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
        ]);
    }

    private function snapshotPayload(string $status, string $privateEvidence = 'Private evidence'): array
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
            'missing_requirements' => [['course_code' => 'CAP-101', 'title' => 'Capstone 1']],
            'failed_requirements' => [],
            'pending_grade_requirements' => [['course_code' => 'CAP-102', 'title' => 'Capstone 2']],
            'inc_requirements' => [],
            'withdrawn_or_dropped_requirements' => [],
            'accepted_credits' => [],
            'approved_exceptions' => [],
            'active_holds' => [['label' => 'Registrar Hold', 'student_message' => 'Please contact the Registrar']],
            'clearance_blockers' => [],
            'remaining_units' => 3.0,
            'source_references' => [['type' => 'private_note', 'label' => $privateEvidence]],
            'student_projection' => [
                'result_status' => $status,
                'remaining_requirements' => ['CAP-101 Capstone 1'],
                'pending_grade_blockers' => ['CAP-102 Capstone 2'],
                'inc_blockers' => [],
                'hold_or_clearance_labels' => ['Registrar Hold'],
                'required_action' => 'Please contact the Registrar',
                'office_to_contact' => 'Registrar Office',
            ],
        ];
    }
}
