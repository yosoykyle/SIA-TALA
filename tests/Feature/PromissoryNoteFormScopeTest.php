<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\PromissoryNote;
use App\Models\StudentProfile;
use App\Models\Term;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PromissoryNoteFormScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_options_are_scoped_to_selected_student_and_term(): void
    {
        $student = StudentProfile::factory()->create();
        $otherStudent = StudentProfile::factory()->create();
        $term = Term::factory()->create(['term_name' => 'First Semester 2026']);
        $otherTerm = Term::factory()->create();
        $matchingEnrollment = Enrollment::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
            'status' => 'pending_payment',
            'year_level' => '1st Year',
        ]);
        $sameStudentDifferentTerm = Enrollment::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $otherTerm->id,
        ]);
        $differentStudentSameTerm = Enrollment::factory()->create([
            'student_profile_id' => $otherStudent->id,
            'term_id' => $term->id,
        ]);

        $options = PromissoryNote::enrollmentOptionsFor($student->id, $term->id);

        $this->assertArrayHasKey($matchingEnrollment->id, $options);
        $this->assertArrayNotHasKey($sameStudentDifferentTerm->id, $options);
        $this->assertArrayNotHasKey($differentStudentSameTerm->id, $options);
        $this->assertStringContainsString('First Semester 2026', $options[$matchingEnrollment->id]);
        $this->assertNotSame((string) $matchingEnrollment->id, $options[$matchingEnrollment->id]);
    }

    public function test_ledger_entry_options_are_scoped_to_selected_student_term_and_enrollment(): void
    {
        $student = StudentProfile::factory()->create();
        $term = Term::factory()->create();
        $otherTerm = Term::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
        ]);
        $otherEnrollment = Enrollment::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $otherTerm->id,
        ]);
        $matchingLedgerEntry = LedgerEntry::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'description' => 'Final assessment balance',
            'amount' => '2500.00',
            'running_balance' => '1500.00',
        ]);
        $differentEnrollmentLedgerEntry = LedgerEntry::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
            'enrollment_id' => $otherEnrollment->id,
        ]);

        $options = PromissoryNote::ledgerEntryOptionsFor($student->id, $term->id, $enrollment->id);

        $this->assertArrayHasKey($matchingLedgerEntry->id, $options);
        $this->assertArrayNotHasKey($differentEnrollmentLedgerEntry->id, $options);
        $this->assertStringContainsString('Final assessment balance', $options[$matchingLedgerEntry->id]);
        $this->assertStringContainsString('Amount: 2,500.00', $options[$matchingLedgerEntry->id]);
        $this->assertStringContainsString('Balance: 1,500.00', $options[$matchingLedgerEntry->id]);
    }

    public function test_accounting_scope_validation_rejects_cross_student_enrollment(): void
    {
        $student = StudentProfile::factory()->create();
        $otherStudent = StudentProfile::factory()->create();
        $term = Term::factory()->create();
        $otherStudentEnrollment = Enrollment::factory()->create([
            'student_profile_id' => $otherStudent->id,
            'term_id' => $term->id,
        ]);

        try {
            PromissoryNote::validateAccountingScopeData([
                'student_profile_id' => $student->id,
                'term_id' => $term->id,
                'enrollment_id' => $otherStudentEnrollment->id,
                'ledger_entry_id' => null,
            ]);

            $this->fail('Cross-student enrollment was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('enrollment_id', $exception->errors());
        }
    }

    public function test_accounting_scope_validation_rejects_ledger_from_different_enrollment(): void
    {
        $student = StudentProfile::factory()->create();
        $term = Term::factory()->create();
        $otherTerm = Term::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
        ]);
        $otherEnrollment = Enrollment::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $otherTerm->id,
        ]);
        $ledgerEntry = LedgerEntry::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
            'enrollment_id' => $otherEnrollment->id,
        ]);

        try {
            PromissoryNote::validateAccountingScopeData([
                'student_profile_id' => $student->id,
                'term_id' => $term->id,
                'enrollment_id' => $enrollment->id,
                'ledger_entry_id' => $ledgerEntry->id,
            ]);

            $this->fail('Cross-enrollment ledger entry was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ledger_entry_id', $exception->errors());
        }
    }

    public function test_accounting_scope_validation_rejects_malformed_optional_ids(): void
    {
        $student = StudentProfile::factory()->create();

        try {
            PromissoryNote::validateAccountingScopeData([
                'student_profile_id' => $student->id,
                'term_id' => null,
                'enrollment_id' => null,
                'ledger_entry_id' => 'not-a-ledger-id',
            ]);

            $this->fail('Malformed ledger entry ID was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ledger_entry_id', $exception->errors());
        }
    }
}
