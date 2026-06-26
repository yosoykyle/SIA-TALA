<?php

namespace Tests\Feature;

use App\Actions\StudentLifecycle\HoldEvaluationService;
use App\Models\Enrollment;
use App\Models\Hold;
use App\Models\PromissoryNote;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HoldEvaluationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_financial_enrollment_hold_blocks_without_promissory_note(): void
    {
        [$student, $enrollment] = $this->context();
        Hold::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $enrollment->term_id,
            'enrollment_id' => $enrollment->id,
            'hold_type' => Hold::TypeFinancial,
            'blocking_level' => Hold::BlockingEnrollment,
            'status' => Hold::StatusActive,
        ]);

        $holds = app(HoldEvaluationService::class)->activeBlockingHolds(
            $student,
            [Hold::BlockingEnrollment],
            $enrollment,
        );

        $this->assertCount(1, $holds);
        $this->assertTrue(app(HoldEvaluationService::class)->hasActiveBlockingHold(
            $student,
            [Hold::BlockingEnrollment],
            $enrollment,
        ));
    }

    public function test_active_promissory_note_bypasses_finance_enrollment_and_record_release_holds(): void
    {
        [$student, $enrollment] = $this->context();
        Hold::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $enrollment->term_id,
            'enrollment_id' => $enrollment->id,
            'hold_type' => Hold::TypeFinancial,
            'blocking_level' => Hold::BlockingEnrollment,
        ]);
        Hold::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $enrollment->term_id,
            'hold_type' => Hold::TypeFinancial,
            'blocking_level' => Hold::BlockingRecordRelease,
        ]);
        PromissoryNote::query()->create([
            'student_profile_id' => $student->id,
            'term_id' => $enrollment->term_id,
            'enrollment_id' => $enrollment->id,
            'amount' => '3000.00',
            'due_date' => now()->addMonth()->toDateString(),
            'status' => PromissoryNote::StatusApproved,
            'reason' => 'Approved payment arrangement.',
            'approved_by' => User::factory()->create()->id,
            'approved_at' => now(),
        ]);

        $holds = app(HoldEvaluationService::class)->activeBlockingHolds(
            $student,
            [Hold::BlockingEnrollment, Hold::BlockingRecordRelease],
            $enrollment,
        );

        $this->assertCount(0, $holds);
    }

    public function test_promissory_note_does_not_bypass_documentary_or_disciplinary_holds(): void
    {
        [$student, $enrollment] = $this->context();
        Hold::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $enrollment->term_id,
            'hold_type' => Hold::TypeDocumentary,
            'blocking_level' => Hold::BlockingEnrollment,
        ]);
        Hold::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $enrollment->term_id,
            'hold_type' => Hold::TypeDisciplinary,
            'blocking_level' => Hold::BlockingRecordRelease,
        ]);
        PromissoryNote::query()->create([
            'student_profile_id' => $student->id,
            'term_id' => $enrollment->term_id,
            'enrollment_id' => $enrollment->id,
            'amount' => '3000.00',
            'due_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
            'reason' => 'Active payment arrangement.',
        ]);

        $holds = app(HoldEvaluationService::class)->activeBlockingHolds(
            $student,
            [Hold::BlockingEnrollment, Hold::BlockingRecordRelease],
            $enrollment,
        );

        $this->assertSame(
            [Hold::TypeDocumentary, Hold::TypeDisciplinary],
            $holds->pluck('hold_type')->all(),
        );
    }

    /**
     * @return array{StudentProfile, Enrollment}
     */
    private function context(): array
    {
        $term = Term::factory()->create();
        $student = StudentProfile::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
        ]);

        return [$student, $enrollment];
    }
}
