<?php

namespace Tests\Feature;

use App\Actions\Grades\GradeCorrectionService;
use App\Enums\GradeCorrectionStatus;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\Grade;
use App\Models\GradeCorrection;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GradeCorrectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_college_grade_correction_resolution_derives_final_grade_from_period_scores(): void
    {
        [$grade, $correction] = $this->gradeCorrectionContext('college', 'college');
        [$registrar, $academicHead] = $this->gradeCorrectionStaff();

        app(GradeCorrectionService::class)->approveOfficialGradeChange(
            correction: $correction,
            academicHead: $academicHead,
            approvalReason: 'Academic Head approved corrected raw period scores.',
        );

        app(GradeCorrectionService::class)->resolveWithGradeChange(
            correction: $correction,
            registrar: $registrar,
            gradeAttributes: [
                'college_prelim' => '99',
                'college_midterm' => '99',
                'college_final' => '99',
            ],
            resolutionNotes: 'Registrar recorded the approved official correction.',
        );

        $grade->refresh();
        $correction->refresh();

        $this->assertSame('99.00', $grade->prelim_grade);
        $this->assertSame('99.00', $grade->midterm_grade);
        $this->assertSame('99.00', $grade->final_grade);
        $this->assertSame('1.00', $grade->grade);
        $this->assertSame('passed', $grade->remarks);
        $this->assertSame(GradeCorrectionStatus::Resolved, $correction->status);
        $this->assertTrue($correction->hasAcademicHeadApproval());
        $this->assertSame($academicHead->id, $correction->academic_head_reviewed_by);
    }

    public function test_shs_grade_correction_resolution_derives_final_grade_from_quarter_grades(): void
    {
        [$grade, $correction] = $this->gradeCorrectionContext('shs', 'shs');
        [$registrar, $academicHead] = $this->gradeCorrectionStaff();

        app(GradeCorrectionService::class)->approveOfficialGradeChange(
            correction: $correction,
            academicHead: $academicHead,
            approvalReason: 'Academic Head approved corrected SHS quarter grades.',
        );

        app(GradeCorrectionService::class)->resolveWithGradeChange(
            correction: $correction,
            registrar: $registrar,
            gradeAttributes: [
                'shs_q1' => '80',
                'shs_q2' => '90',
            ],
            resolutionNotes: 'Registrar recorded the approved official correction.',
        );

        $grade->refresh();
        $correction->refresh();

        $this->assertSame('80.00', $grade->prelim_grade);
        $this->assertSame('90.00', $grade->midterm_grade);
        $this->assertSame('85.00', $grade->final_grade);
        $this->assertSame('85.00', $grade->grade);
        $this->assertSame('passed', $grade->remarks);
        $this->assertSame(GradeCorrectionStatus::Resolved, $correction->status);
    }

    public function test_grade_correction_resolution_rejects_direct_final_grade_override_payloads(): void
    {
        [, $correction] = $this->gradeCorrectionContext('college', 'college');
        [$registrar, $academicHead] = $this->gradeCorrectionStaff();

        app(GradeCorrectionService::class)->approveOfficialGradeChange(
            correction: $correction,
            academicHead: $academicHead,
            approvalReason: 'Academic Head approved a correction.',
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unsupported grade override fields were provided for this grading scheme.');

        app(GradeCorrectionService::class)->resolveWithGradeChange(
            correction: $correction,
            registrar: $registrar,
            gradeAttributes: [
                'grade' => '1.00',
            ],
            resolutionNotes: 'Registrar recorded the approved official correction.',
        );
    }

    public function test_registrar_cannot_apply_official_grade_change_without_in_system_academic_head_approval(): void
    {
        [, $correction] = $this->gradeCorrectionContext('college', 'college');
        [$registrar] = $this->gradeCorrectionStaff();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Academic Head approval is required before the Registrar can apply an official grade change.');

        app(GradeCorrectionService::class)->resolveWithGradeChange(
            correction: $correction,
            registrar: $registrar,
            gradeAttributes: [
                'college_prelim' => '99',
                'college_midterm' => '99',
                'college_final' => '99',
            ],
            resolutionNotes: 'Registrar tried to bypass Academic Head approval.',
        );
    }

    public function test_academic_head_rejection_blocks_official_grade_change_and_rejects_correction(): void
    {
        [, $correction] = $this->gradeCorrectionContext('college', 'college');
        [$registrar, $academicHead] = $this->gradeCorrectionStaff();

        app(GradeCorrectionService::class)->rejectOfficialGradeChange(
            correction: $correction,
            academicHead: $academicHead,
            rejectionReason: 'Insufficient basis for an official grade correction.',
        );

        $correction->refresh();

        $this->assertSame(GradeCorrectionStatus::Rejected, $correction->status);
        $this->assertTrue($correction->hasAcademicHeadRejection());
        $this->assertSame($academicHead->id, $correction->academic_head_reviewed_by);
        $this->assertNotNull($correction->resolved_at);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Only under review grade corrections can be resolved.');

        app(GradeCorrectionService::class)->resolveWithGradeChange(
            correction: $correction,
            registrar: $registrar,
            gradeAttributes: [
                'college_prelim' => '99',
                'college_midterm' => '99',
                'college_final' => '99',
            ],
            resolutionNotes: 'Registrar cannot apply a rejected correction.',
        );
    }

    /**
     * @return array{Grade, GradeCorrection}
     */
    private function gradeCorrectionContext(string $educationLevel, string $department): array
    {
        $student = User::factory()->create();
        $program = Program::factory()->create([
            'department' => $department,
        ]);
        $studentProfile = StudentProfile::factory()
            ->for($student, 'user')
            ->for($program)
            ->create([
                'education_level' => $educationLevel,
            ]);
        $term = Term::factory()->create();
        $subject = Subject::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $term->id,
            'status' => 'enrolled',
        ]);
        $enrollmentSubject = EnrollmentSubject::query()->create([
            'enrollment_id' => $enrollment->id,
            'subject_id' => $subject->id,
            'units' => '3.00',
            'lec_hours' => '3.00',
            'status' => 'enrolled',
            'is_dropped' => false,
        ]);
        $grade = Grade::query()->create([
            'enrollment_id' => $enrollment->id,
            'enrollment_subject_id' => $enrollmentSubject->id,
            'subject_id' => $subject->id,
            'term_id' => $term->id,
            'prelim_grade' => '75.00',
            'midterm_grade' => '75.00',
            'final_grade' => '75.00',
            'grade' => $educationLevel === 'shs' ? '75.00' : '3.00',
            'remarks' => 'passed',
            'is_finalized' => true,
        ]);
        $correction = GradeCorrection::factory()->create([
            'user_id' => $student->id,
            'grade_id' => $grade->id,
            'subject_id' => $subject->id,
            'term_id' => $term->id,
            'current_grade' => $grade->grade,
            'status' => GradeCorrectionStatus::UnderReview,
        ]);

        return [$grade, $correction];
    }

    /**
     * @return array{User, User}
     */
    private function gradeCorrectionStaff(): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $registrar = User::factory()->create();
        $academicHead = User::factory()->create();

        Role::findOrCreate('registrar');
        Role::findOrCreate('academic-head');
        Permission::findOrCreate('manage-grade-corrections');
        Permission::findOrCreate('authorize-overrides');

        $registrar->assignRole('registrar');
        $registrar->givePermissionTo('manage-grade-corrections');
        $academicHead->assignRole('academic-head');
        $academicHead->givePermissionTo('authorize-overrides');

        return [$registrar, $academicHead];
    }
}
