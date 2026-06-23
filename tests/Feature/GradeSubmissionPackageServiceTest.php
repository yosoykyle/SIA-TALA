<?php

namespace Tests\Feature;

use App\Actions\Grades\GradeEncodingService;
use App\Actions\Grades\GradeSubmissionPackageService;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\GradeSubmissionPackage;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GradeSubmissionPackageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_faculty_submission_snapshots_complete_class_grades_and_locks_edits_until_returned(): void
    {
        $context = $this->gradePackageContext();
        $this->encodeAll($context);

        $package = app(GradeSubmissionPackageService::class)->submit(
            termId: $context['term']->id,
            sectionId: $context['section']->id,
            subjectId: $context['subject']->id,
            faculty: $context['faculty'],
        );

        $this->assertSame(GradeSubmissionPackage::StateSubmitted, $package->state);
        $this->assertCount(2, $package->items);
        $this->assertNotEmpty($package->roster_snapshot_checksum);
        $this->assertSame(['prelim', 'midterm', 'final'], $package->grading_profile_snapshot['periods']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Submitted grade packages cannot be edited until Registrar returns them.');

        app(GradeEncodingService::class)->encode(
            enrollmentSubjectId: $context['enrollmentSubjects'][0]->id,
            periodGrades: ['prelim' => '95', 'midterm' => '95', 'final' => '95'],
            actor: $context['faculty'],
        );
    }

    public function test_registrar_can_return_submitted_package_without_finalizing_grades(): void
    {
        $context = $this->gradePackageContext();
        $this->encodeAll($context);

        $package = app(GradeSubmissionPackageService::class)->submit(
            termId: $context['term']->id,
            sectionId: $context['section']->id,
            subjectId: $context['subject']->id,
            faculty: $context['faculty'],
        );

        $returned = app(GradeSubmissionPackageService::class)->returnForRevision(
            package: $package,
            registrar: $context['registrar'],
            reason: 'One encoded row needs source-record checking.',
        );

        $this->assertSame(GradeSubmissionPackage::StateReturned, $returned->state);
        $this->assertSame($context['registrar']->id, $returned->registrar_reviewed_by);
        $this->assertSame('One encoded row needs source-record checking.', $returned->return_reason);

        $grade = $context['enrollmentSubjects'][0]->grade()->firstOrFail();

        $this->assertFalse($grade->is_finalized);

        app(GradeEncodingService::class)->encode(
            enrollmentSubjectId: $context['enrollmentSubjects'][0]->id,
            periodGrades: ['prelim' => '95', 'midterm' => '95', 'final' => '95'],
            actor: $context['faculty'],
        );

        $this->assertSame('1.25', $grade->fresh()->grade);
    }

    public function test_registrar_verification_finalizes_all_package_grades_atomically(): void
    {
        $context = $this->gradePackageContext();
        $this->encodeAll($context);

        $package = app(GradeSubmissionPackageService::class)->submit(
            termId: $context['term']->id,
            sectionId: $context['section']->id,
            subjectId: $context['subject']->id,
            faculty: $context['faculty'],
        );

        $verified = app(GradeSubmissionPackageService::class)->verifyAndFinalize(
            package: $package,
            registrar: $context['registrar'],
        );

        $this->assertSame(GradeSubmissionPackage::StateVerifiedFinalized, $verified->state);
        $this->assertSame($context['registrar']->id, $verified->registrar_reviewed_by);
        $this->assertNotNull($verified->finalized_at);

        foreach ($context['enrollmentSubjects'] as $enrollmentSubject) {
            $grade = $enrollmentSubject->grade()->firstOrFail();

            $this->assertTrue($grade->is_finalized);
            $this->assertSame($context['registrar']->id, $grade->finalized_by);
            $this->assertNotNull($grade->finalized_at);
        }
    }

    public function test_submission_requires_every_active_student_row_to_have_a_complete_grade_or_inc(): void
    {
        $context = $this->gradePackageContext();

        app(GradeEncodingService::class)->encode(
            enrollmentSubjectId: $context['enrollmentSubjects'][0]->id,
            periodGrades: ['prelim' => '90', 'midterm' => '90', 'final' => '90'],
            actor: $context['faculty'],
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('All enrolled students in the package must have an encoded grade or INC before submission.');

        app(GradeSubmissionPackageService::class)->submit(
            termId: $context['term']->id,
            sectionId: $context['section']->id,
            subjectId: $context['subject']->id,
            faculty: $context['faculty'],
        );
    }

    public function test_only_registrar_with_verification_permission_can_verify_grade_packages(): void
    {
        $context = $this->gradePackageContext();
        $this->encodeAll($context);

        $package = app(GradeSubmissionPackageService::class)->submit(
            termId: $context['term']->id,
            sectionId: $context['section']->id,
            subjectId: $context['subject']->id,
            faculty: $context['faculty'],
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Only Registrar staff can verify or return grade submissions.');

        app(GradeSubmissionPackageService::class)->verifyAndFinalize(
            package: $package,
            registrar: $context['faculty'],
        );
    }

    /**
     * @return array{
     *     faculty:User,
     *     registrar:User,
     *     term:Term,
     *     section:Section,
     *     subject:Subject,
     *     enrollmentSubjects:list<EnrollmentSubject>
     * }
     */
    private function gradePackageContext(): array
    {
        $this->seedGradePackagePermissions();

        $faculty = User::factory()->create();
        $registrar = User::factory()->create();

        $faculty->assignRole('faculty');
        $faculty->givePermissionTo(['encode-grades', 'finalize-grades', 'view-class-list']);

        $registrar->assignRole('registrar');
        $registrar->givePermissionTo('verify-grade-submissions');

        $program = Program::factory()->create(['department' => 'college']);
        $term = Term::factory()->create();
        $section = Section::factory()->create([
            'program_id' => $program->id,
            'term_id' => $term->id,
        ]);
        $subject = Subject::factory()->create(['department' => 'college']);

        DB::table('section_teacher')->insert([
            'section_id' => $section->id,
            'user_id' => $faculty->id,
            'subject_id' => $subject->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollmentSubjects = [];

        for ($index = 0; $index < 2; $index++) {
            $student = User::factory()->create();
            $studentProfile = StudentProfile::factory()
                ->for($student, 'user')
                ->for($program)
                ->create();
            $enrollment = Enrollment::factory()->create([
                'student_profile_id' => $studentProfile->id,
                'term_id' => $term->id,
                'section_id' => $section->id,
                'status' => 'enrolled',
            ]);

            $enrollmentSubjects[] = EnrollmentSubject::query()->create([
                'enrollment_id' => $enrollment->id,
                'subject_id' => $subject->id,
                'units' => '3.00',
                'lec_hours' => '3.00',
                'status' => 'enrolled',
                'is_dropped' => false,
            ]);
        }

        return [
            'faculty' => $faculty,
            'registrar' => $registrar,
            'term' => $term,
            'section' => $section,
            'subject' => $subject,
            'enrollmentSubjects' => $enrollmentSubjects,
        ];
    }

    /**
     * @param  array{faculty:User,enrollmentSubjects:list<EnrollmentSubject>}  $context
     */
    private function encodeAll(array $context): void
    {
        foreach ($context['enrollmentSubjects'] as $enrollmentSubject) {
            app(GradeEncodingService::class)->encode(
                enrollmentSubjectId: $enrollmentSubject->id,
                periodGrades: ['prelim' => '90', 'midterm' => '90', 'final' => '90'],
                actor: $context['faculty'],
            );
        }
    }

    private function seedGradePackagePermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['faculty', 'registrar'] as $role) {
            Role::findOrCreate($role);
        }

        foreach (['encode-grades', 'finalize-grades', 'view-class-list', 'verify-grade-submissions'] as $permission) {
            Permission::findOrCreate($permission);
        }
    }
}
