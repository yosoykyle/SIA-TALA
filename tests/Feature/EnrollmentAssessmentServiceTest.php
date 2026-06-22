<?php

namespace Tests\Feature;

use App\Actions\Enrollment\EnrollmentAssessmentService;
use App\Actions\Finance\PaymentConfirmationService;
use App\Models\Enrollment;
use App\Models\FeeTemplate;
use App\Models\LedgerEntry;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EnrollmentAssessmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_assessment_service_does_not_apply_unapproved_automatic_discounts(): void
    {
        $source = $this->source(EnrollmentAssessmentService::class);

        $this->assertStringNotContainsString('Automated Freshmen Discount', $source);
        $this->assertStringNotContainsString('multiplyPercent', $source);
    }

    public function test_assessment_service_is_idempotent_for_existing_assessment_entries(): void
    {
        $source = $this->source(EnrollmentAssessmentService::class);

        $this->assertStringContainsString('hasAssessmentEntries', $source);
        $this->assertStringContainsString('summaryForExistingAssessment', $source);
        $this->assertStringContainsString('summaryForExistingAssessment($enrollment, $studentProfile, true)', $source);
        $this->assertStringContainsString("'already_assessed' => \$alreadyAssessed", $source);
    }

    public function test_assessment_uses_most_specific_template_without_unapproved_discount_and_is_idempotent(): void
    {
        $term = Term::factory()->create();
        $program = Program::factory()->create(['department' => 'college']);
        $studentProfile = StudentProfile::factory()->create([
            'program_id' => $program->id,
            'year_level' => '1st Year',
            'current_balance' => '0.00',
        ]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $term->id,
            'student_type' => 'new',
            'year_level' => '1st Year',
        ]);
        $accounting = $this->userWithPermissions(['create-assessments']);

        FeeTemplate::factory()->create([
            'name' => 'Generic college',
            'program_id' => null,
            'year_level' => null,
            'tuition_fee' => '9000.00',
            'laboratory_fee' => '0.00',
            'misc_fee' => '0.00',
            'other_fee' => '0.00',
            'minimum_downpayment_percentage' => '20.00',
        ]);
        FeeTemplate::factory()->create([
            'name' => 'Program fallback',
            'program_id' => $program->id,
            'year_level' => null,
            'tuition_fee' => '8000.00',
            'laboratory_fee' => '0.00',
            'misc_fee' => '0.00',
            'other_fee' => '0.00',
            'minimum_downpayment_percentage' => '20.00',
        ]);
        $specificTemplate = FeeTemplate::factory()->create([
            'name' => 'Program first year',
            'program_id' => $program->id,
            'year_level' => '1st Year',
            'tuition_fee' => '1000.00',
            'laboratory_fee' => '200.00',
            'misc_fee' => '300.00',
            'other_fee' => '500.00',
            'minimum_downpayment_percentage' => '25.00',
        ]);

        $summary = app(EnrollmentAssessmentService::class)->assess($enrollment->id, $accounting);

        $this->assertSame($specificTemplate->id, $summary['fee_template_id']);
        $this->assertSame('2000.00', $summary['gross_assessment']);
        $this->assertSame('0.00', $summary['discount_amount']);
        $this->assertSame('2000.00', $summary['net_assessment']);
        $this->assertSame('2000.00', $summary['current_balance']);
        $this->assertFalse($summary['already_assessed']);
        $this->assertSame('2000.00', $studentProfile->fresh()->current_balance);
        $this->assertSame(4, LedgerEntry::query()->where('enrollment_id', $enrollment->id)->count());
        $this->assertDatabaseMissing(LedgerEntry::class, [
            'enrollment_id' => $enrollment->id,
            'entry_type' => 'discount',
        ]);

        $secondSummary = app(EnrollmentAssessmentService::class)->assess($enrollment->id, $accounting);

        $this->assertTrue($secondSummary['already_assessed']);
        $this->assertSame($summary['current_balance'], $secondSummary['current_balance']);
        $this->assertSame(4, LedgerEntry::query()->where('enrollment_id', $enrollment->id)->count());
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Enrollment::class,
            'subject_id' => $enrollment->id,
            'event' => 'enrollment_assessed',
        ]);
    }

    public function test_configured_minimum_downpayment_controls_finance_clearance(): void
    {
        $term = Term::factory()->create();
        $program = Program::factory()->create(['department' => 'college']);
        $studentProfile = StudentProfile::factory()->create([
            'program_id' => $program->id,
            'year_level' => '2nd Year',
            'current_balance' => '0.00',
        ]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $term->id,
            'student_type' => 'regular',
            'year_level' => '2nd Year',
        ]);
        $accounting = $this->userWithPermissions(['create-assessments', 'process-payments']);

        FeeTemplate::factory()->create([
            'program_id' => $program->id,
            'year_level' => '2nd Year',
            'tuition_fee' => '1000.00',
            'laboratory_fee' => '0.00',
            'misc_fee' => '0.00',
            'other_fee' => '0.00',
            'minimum_downpayment_percentage' => '25.00',
        ]);

        app(EnrollmentAssessmentService::class)->assess($enrollment->id, $accounting);

        $firstPayment = app(PaymentConfirmationService::class)->confirmManualPayment(
            enrollmentId: $enrollment->id,
            amount: '249.99',
            channel: 'cash',
            paymentReference: 'OR-24999',
            actor: $accounting,
        );

        $this->assertSame('250.00', $firstPayment['minimum_required_payment']);
        $this->assertSame('249.99', $firstPayment['total_confirmed_payments']);
        $this->assertFalse($firstPayment['finance_cleared']);
        $this->assertSame('pending_payment', $enrollment->fresh()->status);

        $secondPayment = app(PaymentConfirmationService::class)->confirmManualPayment(
            enrollmentId: $enrollment->id,
            amount: '0.01',
            channel: 'cash',
            paymentReference: 'OR-00001',
            actor: $accounting,
        );

        $this->assertSame('250.00', $secondPayment['minimum_required_payment']);
        $this->assertSame('250.00', $secondPayment['total_confirmed_payments']);
        $this->assertTrue($secondPayment['finance_cleared']);
        $this->assertSame('pre_enrolled', $enrollment->fresh()->status);
        $this->assertTrue($studentProfile->user->fresh()->hasRole('student'));
    }

    private function source(string $class): string
    {
        $reflection = new \ReflectionClass($class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);

        return $source;
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userWithPermissions(array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission));
        }

        return $user;
    }
}
