<?php

namespace Tests\Feature;

use App\Actions\Enrollment\StartRegistrationCase;
use App\Models\ApplicantIntake;
use App\Models\Assessment;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
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
        $this->assertStringStartsWith('test_tala_db', DB::connection()->getDatabaseName());
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
        $this->assertFalse(Schema::hasTable('student_schedule_bindings'));
        $this->assertFalse(Schema::hasTable('enrollment_gate_results'));

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
        $this->assertFileDoesNotExist(app_path('Actions/Enrollment/StudentEnrollmentService.php'));
        $this->assertFileDoesNotExist(app_path('Actions/Applicants/HandOverApprovedApplicant.php'));
    }

    public function test_admission_cannot_start_enrollment_through_the_retired_handover_path(): void
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        $applicant = User::factory()->create(['status' => User::StatusActive]);
        $applicant->assignRole('applicant');
        $intake = ApplicantIntake::factory()->approved($registrar)->create([
            'user_id' => $applicant->id,
        ]);

        $this->assertFalse(class_exists('App\\Actions\\Applicants\\HandOverApprovedApplicant'));
        $this->assertFalse(class_exists('App\\Actions\\Enrollment\\StudentEnrollmentService'));

        $this->assertSame(0, Enrollment::query()->count());
        $this->assertSame(0, StudentProfile::query()->where('applicant_intake_id', $intake->id)->count());
        $this->assertSame(0, CourseEnrollment::query()->count());
        $this->assertSame(0, EnrollmentSeatReservation::query()->count());
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
            app(StartRegistrationCase::class)->forContinuingStudent(
                $studentProfile,
                $term,
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
}
