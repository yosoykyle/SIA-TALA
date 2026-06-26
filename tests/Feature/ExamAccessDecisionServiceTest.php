<?php

namespace Tests\Feature;

use App\Actions\Finance\ExamAccessDecisionService;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\Term;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamAccessDecisionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_balance_allows_exam_access(): void
    {
        [$student, $term, $enrollment] = $this->context('0.00');

        $decision = app(ExamAccessDecisionService::class)->decide(
            $student,
            $term,
            $enrollment,
            CarbonImmutable::parse('2026-09-15'),
        );

        $this->assertTrue($decision['allowed']);
        $this->assertSame('ra11984_finance_never_blocks_exams', $decision['basis']);
        $this->assertNull($decision['accommodation_id']);
    }

    public function test_outstanding_balance_allows_exam_access_without_accommodation(): void
    {
        [$student, $term, $enrollment] = $this->context('5000.00');

        $decision = app(ExamAccessDecisionService::class)->decide(
            $student,
            $term,
            $enrollment,
            CarbonImmutable::parse('2026-09-15'),
        );

        $this->assertTrue($decision['allowed']);
        $this->assertSame('ra11984_finance_never_blocks_exams', $decision['basis']);
        $this->assertNull($decision['accommodation_id']);
    }

    /**
     * @return array{StudentProfile, Term, Enrollment}
     */
    private function context(string $balance): array
    {
        $term = Term::factory()->create();
        $student = StudentProfile::factory()->create([
            'current_balance' => $balance,
        ]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
        ]);

        return [$student, $term, $enrollment];
    }
}
