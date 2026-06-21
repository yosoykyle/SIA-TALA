<?php

namespace Tests\Feature;

use App\Actions\Finance\ExamAccessAccommodationService;
use App\Actions\Finance\ExamAccessDecisionService;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\ExamAccessAccommodation;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ExamAccessDecisionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('student');
    }

    public function test_zero_balance_allows_exam_access_without_a_promissory_or_accommodation(): void
    {
        [$student, $term, $enrollment] = $this->context('0.00');

        $decision = app(ExamAccessDecisionService::class)->decide(
            $student,
            $term,
            $enrollment,
            CarbonImmutable::parse('2026-09-15'),
        );

        $this->assertTrue($decision['allowed']);
        $this->assertSame('fully_paid', $decision['basis']);
        $this->assertNull($decision['accommodation_id']);
    }

    public function test_unpaid_student_is_denied_without_an_approved_current_accommodation(): void
    {
        [$student, $term, $enrollment] = $this->context('5000.00');

        $decision = app(ExamAccessDecisionService::class)->decide(
            $student,
            $term,
            $enrollment,
            CarbonImmutable::parse('2026-09-15'),
        );

        $this->assertFalse($decision['allowed']);
        $this->assertSame('outstanding_balance', $decision['basis']);
    }

    public function test_approved_ra11984_accommodation_allows_exam_access_without_finance_clearance(): void
    {
        [$student, $term, $enrollment] = $this->context('5000.00');
        $reviewer = $this->reviewer();
        $service = app(ExamAccessAccommodationService::class);
        $request = $service->submit([
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'basis' => ExamAccessAccommodation::BasisRa11984Certification,
            'certifying_office' => 'City Social Welfare and Development Office',
            'certification_reference' => 'CSWDO-2026-0042',
            'certified_at' => '2026-09-01',
            'evidence_disk' => 'local',
            'evidence_path' => 'exam-accommodations/private/certification.pdf',
            'evidence_file_name' => 'certification.pdf',
            'evidence_mime_type' => 'application/pdf',
            'valid_from' => '2026-09-01',
            'valid_until' => '2026-12-31',
        ], $student->user);
        $approved = $service->approve($request, $reviewer, 'Certification verified against the issuing office.');

        $decision = app(ExamAccessDecisionService::class)->decide(
            $student,
            $term,
            $enrollment,
            CarbonImmutable::parse('2026-09-15'),
        );

        $this->assertTrue($decision['allowed']);
        $this->assertSame(ExamAccessAccommodation::BasisRa11984Certification, $decision['basis']);
        $this->assertSame($approved->id, $decision['accommodation_id']);
        $this->assertSame('5000.00', $student->refresh()->current_balance);
    }

    public function test_college_accommodation_is_scoped_to_the_selected_term(): void
    {
        [$student, $firstTerm, $firstEnrollment, $academicYear] = $this->context('5000.00');
        $secondTerm = Term::factory()->create([
            'academic_year_id' => $academicYear->id,
            'term_name' => 'Second Semester',
            'term_start_date' => '2027-01-05',
            'term_end_date' => '2027-05-20',
        ]);
        $secondEnrollment = Enrollment::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $secondTerm->id,
        ]);
        $service = app(ExamAccessAccommodationService::class);
        $request = $service->submit([
            'student_profile_id' => $student->id,
            'term_id' => $firstTerm->id,
            'enrollment_id' => $firstEnrollment->id,
            'basis' => ExamAccessAccommodation::BasisRa11984Certification,
            'certifying_office' => 'Provincial Social Welfare and Development Office',
            'certification_reference' => 'PSWDO-2026-0099',
            'certified_at' => '2026-08-20',
            'evidence_disk' => 'local',
            'evidence_path' => 'exam-accommodations/private/certification.pdf',
            'evidence_file_name' => 'certification.pdf',
            'evidence_mime_type' => 'application/pdf',
        ], $student->user);
        $approved = $service->approve($request, $this->reviewer(), 'Verified statutory certification.');

        $this->assertSame(ExamAccessAccommodation::ScopeTerm, $approved->scope);
        $this->assertNull($approved->academic_year_id);
        $this->assertSame($firstTerm->id, $approved->term_id);

        $decision = app(ExamAccessDecisionService::class)->decide(
            $student,
            $secondTerm,
            $secondEnrollment,
            CarbonImmutable::parse('2027-02-15'),
        );

        $this->assertFalse($decision['allowed']);
    }

    public function test_college_accommodation_does_not_leak_to_another_term(): void
    {
        [$student, $firstTerm, $firstEnrollment, $academicYear] = $this->context('5000.00');
        $secondTerm = Term::factory()->create(['academic_year_id' => $academicYear->id]);
        $secondEnrollment = Enrollment::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $secondTerm->id,
        ]);
        $service = app(ExamAccessAccommodationService::class);
        $request = $service->submit([
            'student_profile_id' => $student->id,
            'term_id' => $firstTerm->id,
            'enrollment_id' => $firstEnrollment->id,
            'basis' => ExamAccessAccommodation::BasisInstitutionalDiscretion,
            'request_reason' => 'Documented emergency while certification is being processed.',
            'valid_from' => now()->subDay()->toDateString(),
            'valid_until' => now()->addMonth()->toDateString(),
        ], $student->user);
        $service->approve($request, $this->reviewer(), 'One-term institutional accommodation approved.');

        $decision = app(ExamAccessDecisionService::class)->decide($student, $secondTerm, $secondEnrollment);

        $this->assertFalse($decision['allowed']);
    }

    public function test_statutory_basis_requires_private_certification_evidence_or_reference(): void
    {
        [$student, $term, $enrollment] = $this->context('5000.00');

        try {
            app(ExamAccessAccommodationService::class)->submit([
                'student_profile_id' => $student->id,
                'term_id' => $term->id,
                'enrollment_id' => $enrollment->id,
                'basis' => ExamAccessAccommodation::BasisRa11984Certification,
            ], $student->user);

            $this->fail('Statutory accommodation without certification evidence was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('certification_reference', $exception->errors());
        }
    }

    public function test_private_evidence_fields_are_hidden_from_serialized_model_output(): void
    {
        $accommodation = new ExamAccessAccommodation([
            'evidence_disk' => 'local',
            'evidence_path' => 'private/certification.pdf',
            'evidence_file_name' => 'certification.pdf',
            'evidence_mime_type' => 'application/pdf',
        ]);

        $serialized = $accommodation->toArray();

        $this->assertArrayNotHasKey('evidence_disk', $serialized);
        $this->assertArrayNotHasKey('evidence_path', $serialized);
        $this->assertArrayNotHasKey('evidence_file_name', $serialized);
        $this->assertArrayNotHasKey('evidence_mime_type', $serialized);
    }

    /**
     * @return array{StudentProfile, Term, Enrollment, AcademicYear}
     */
    private function context(string $balance): array
    {
        $academicYear = AcademicYear::factory()->create([
            'school_year_start_date' => '2026-08-01',
            'school_year_end_date' => '2027-05-31',
        ]);
        $term = Term::factory()->create([
            'academic_year_id' => $academicYear->id,
            'term_start_date' => '2026-08-01',
            'term_end_date' => '2026-12-31',
        ]);
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole('student');
        $student = StudentProfile::factory()->for($user, 'user')->create([
            'current_balance' => $balance,
        ]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
        ]);

        return [$student->load('user'), $term, $enrollment, $academicYear];
    }

    private function reviewer(): User
    {
        Permission::findOrCreate('approve-promissory-notes');
        $user = User::factory()->create();
        $user->givePermissionTo('approve-promissory-notes');

        return $user;
    }
}
