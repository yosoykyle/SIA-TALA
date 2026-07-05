<?php

namespace Tests\Feature;

use App\Actions\Applicants\HandOverApprovedApplicant;
use App\Actions\Enrollment\StartEnrollment;
use App\Actions\Enrollment\StudentEnrollmentService;
use App\Actions\Registrar\CorVerificationLifecycleService;
use App\Models\ApplicantIntake;
use App\Models\Assessment;
use App\Models\CorVerification;
use App\Models\CourseEnrollment;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\EnrollmentGateResult;
use App\Models\EnrollmentSeatReservation;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\StudentScheduleBinding;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL87AEnrollmentSourceRecordBaselineTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_test_codex', DB::connection()->getDatabaseName());

        foreach (['student', 'applicant', User::StaffRoleRegistrar] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_clean_enrollment_source_records_replace_direct_enrollment_sectioning(): void
    {
        $this->assertTrue(Schema::hasColumns('enrollments', [
            'student_profile_id',
            'term_id',
            'status',
            'student_type',
            'registered_at',
            'officially_enrolled_at',
        ]));
        $this->assertTrue(Schema::hasColumns('course_enrollments', [
            'enrollment_id',
            'term_offering_id',
            'status',
            'units_snapshot',
        ]));
        $this->assertTrue(Schema::hasColumns('enrollment_seat_reservations', [
            'enrollment_id',
            'course_enrollment_id',
            'section_id',
            'status',
        ]));
        $this->assertTrue(Schema::hasColumns('student_schedule_bindings', [
            'course_enrollment_id',
            'section_meeting_id',
            'is_active',
            'source',
        ]));
        $this->assertTrue(Schema::hasColumns('enrollment_gate_results', [
            'enrollment_id',
            'gate_type',
            'result',
            'source_type',
            'source_id',
        ]));

        foreach ([
            'section_id',
            'section_delivery_group_id',
            'year_level',
            'modality',
            'lis_status',
            'is_late_enrollment',
        ] as $staleColumn) {
            $this->assertFalse(Schema::hasColumn('enrollments', $staleColumn));
        }

        $this->assertFalse(method_exists(Enrollment::class, 'section'));
        $this->assertFalse(method_exists(Enrollment::class, 'sectionDeliveryGroup'));
        $this->assertFileDoesNotExist(app_path('Actions/Enrollment/EnrollmentSectioningService.php'));
        $this->assertFalse(method_exists(StudentEnrollmentService::class, 'startFromApprovedApplicant'));
        $this->assertFalse(method_exists(StudentEnrollmentService::class, 'startRegularEnrollment'));
    }

    public function test_approved_applicant_handover_starts_one_clean_pending_review_enrollment(): void
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        $applicant = User::factory()->create(['status' => User::StatusApplicantApproved]);
        $applicant->assignRole('applicant');

        $program = Program::factory()->create();
        $term = Term::factory()->create(['state' => Term::StateActive]);
        CurriculumVersion::factory()->create([
            'program_id' => $program->id,
            'state' => CurriculumVersion::StateActive,
        ]);
        $intake = ApplicantIntake::factory()->approved($registrar)->create([
            'user_id' => $applicant->id,
            'program_id' => $program->id,
            'term_id' => $term->id,
            'admission_category' => ApplicantIntake::AdmissionCategoryTransfer,
            'credential_basis' => ApplicantIntake::CredentialBasisTransferCredentials,
        ]);

        $action = app(HandOverApprovedApplicant::class);
        $firstProfile = $action->execute($intake, $registrar);
        $secondProfile = $action->execute($intake->fresh(), $registrar);

        $this->assertTrue($firstProfile->is($secondProfile));
        $enrollment = Enrollment::query()
            ->where('student_profile_id', $firstProfile->id)
            ->where('term_id', $term->id)
            ->sole();
        $directlyReusedEnrollment = app(StartEnrollment::class)->execute(
            $firstProfile,
            $term,
            'transferee',
            $registrar,
        );

        $this->assertTrue($enrollment->is($directlyReusedEnrollment));
        $this->assertSame('pending_review', $enrollment->status);
        $this->assertSame('transferee', $enrollment->student_type);
        $this->assertNull($enrollment->registered_at);
        $this->assertNull($enrollment->officially_enrolled_at);
        $this->assertSame(1, Enrollment::query()->where('student_profile_id', $firstProfile->id)->where('term_id', $term->id)->count());
        $this->assertSame(0, CourseEnrollment::query()->count());
        $this->assertSame(0, EnrollmentSeatReservation::query()->count());
        $this->assertSame(0, StudentScheduleBinding::query()->count());
        $this->assertSame(0, EnrollmentGateResult::query()->count());
        $this->assertSame(0, Assessment::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_unauthorized_actor_cannot_start_an_enrollment_directly(): void
    {
        $studentProfile = StudentProfile::factory()->create();
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $unauthorizedActor = User::factory()->create(['status' => User::StatusActive]);

        try {
            app(StartEnrollment::class)->execute(
                $studentProfile,
                $term,
                'regular',
                $unauthorizedActor,
            );
            $this->fail('Unauthorized direct enrollment start was not rejected.');
        } catch (AuthorizationException) {
            $this->assertSame(0, Enrollment::query()
                ->where('student_profile_id', $studentProfile->id)
                ->where('term_id', $term->id)
                ->count());
        }
    }

    public function test_finance_cleared_handover_activates_the_clean_student_number_account(): void
    {
        $user = User::factory()->create([
            'status' => User::StatusApplicantApproved,
            'username' => 'applicant-login',
        ]);
        $user->assignRole('applicant');

        $studentProfile = StudentProfile::factory()
            ->for($user)
            ->create(['student_number' => 'SIA-2026-8701']);
        $enrollment = Enrollment::factory()
            ->for($studentProfile)
            ->create(['status' => 'pre_enrolled']);

        $result = app(StudentEnrollmentService::class)->completeFinanceClearedHandover(
            $enrollment,
            clearedAt: CarbonImmutable::parse('2026-07-06 09:00:00'),
        );

        $this->assertSame('pre_enrolled', $result->status);
        $this->assertSame('SIA-2026-8701', $user->refresh()->username);
        $this->assertSame(User::StatusActive, $user->status);
        $this->assertTrue($user->hasRole('student'));
        $this->assertFalse($user->hasRole('applicant'));
    }

    public function test_cor_readiness_uses_active_course_enrollments_and_schedule_bindings(): void
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole('student');

        $studentProfile = StudentProfile::factory()->for($user)->create();
        $studentProfile->setRelation('user', $user->refresh());

        $binding = new StudentScheduleBinding(['is_active' => true]);
        $courseEnrollment = new CourseEnrollment(['status' => CourseEnrollment::StatusActive]);
        $courseEnrollment->setRelation('scheduleBindings', new EloquentCollection([$binding]));

        $enrollment = new Enrollment(['status' => 'officially_enrolled']);
        $enrollment->setRelation('studentProfile', $studentProfile);
        $enrollment->setRelation('courseEnrollments', new EloquentCollection([$courseEnrollment]));

        $readiness = app(StudentEnrollmentService::class)->corReadiness($enrollment);

        $this->assertTrue($readiness['ready']);
        $this->assertSame([], $readiness['blockers']);
    }

    public function test_cor_compatibility_payload_uses_clean_student_and_term_fields(): void
    {
        $studentProfile = new StudentProfile([
            'student_number' => 'SIA-2026-8702',
        ]);
        $term = new Term(['label' => 'First Semester 2026-2027']);
        $enrollment = new Enrollment(['status' => 'officially_enrolled']);
        $verification = new CorVerification([
            'status' => CorVerification::StatusValid,
            'issued_at' => now(),
        ]);
        $verification->setRelation('studentProfile', $studentProfile);
        $verification->setRelation('term', $term);
        $verification->setRelation('enrollment', $enrollment);

        $publicPayload = new \ReflectionMethod(CorVerificationLifecycleService::class, 'publicPayload');
        $result = $publicPayload->invoke(
            app(CorVerificationLifecycleService::class),
            CorVerification::StatusValid,
            'Valid.',
            $verification,
        );

        $this->assertSame('SIA-2026-8702', $result['student_id']);
        $this->assertSame('First Semester 2026-2027', $result['term']);
    }
}
