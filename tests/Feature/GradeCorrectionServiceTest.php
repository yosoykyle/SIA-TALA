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

        app(GradeCorrectionService::class)->resolveWithGradeChange(
            correction: $correction,
            registrar: $registrar,
            academicHead: $academicHead,
            gradeAttributes: [
                'college_prelim' => '99',
                'college_midterm' => '99',
                'college_final' => '99',
            ],
            approvalReason: 'Academic Head approved corrected raw period scores.',
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
    }

    public function test_shs_grade_correction_resolution_derives_final_grade_from_quarter_grades(): void
    {
        [$grade, $correction] = $this->gradeCorrectionContext('shs', 'shs');
        [$registrar, $academicHead] = $this->gradeCorrectionStaff();

        app(GradeCorrectionService::class)->resolveWithGradeChange(
            correction: $correction,
            registrar: $registrar,
            academicHead: $academicHead,
            gradeAttributes: [
                'shs_q1' => '80',
                'shs_q2' => '90',
            ],
            approvalReason: 'Academic Head approved corrected SHS quarter grades.',
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

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unsupported grade override fields were provided for this grading scheme.');

        app(GradeCorrectionService::class)->resolveWithGradeChange(
            correction: $correction,
            registrar: $registrar,
            academicHead: $academicHead,
            gradeAttributes: [
                'grade' => '1.00',
            ],
            approvalReason: 'Academic Head approved a correction.',
            resolutionNotes: 'Registrar recorded the approved official correction.',
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
