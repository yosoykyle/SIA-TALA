<?php

namespace Tests\Feature;

use App\Actions\Enrollment\EnrollmentAssessmentService;
use App\Actions\Enrollment\StudentEnrollmentService;
use App\Actions\Finance\PaymentConfirmationService;
use App\Models\ApplicantIntake;
use App\Models\Curriculum;
use App\Models\DeliveryPattern;
use App\Models\DocumentUpload;
use App\Models\Enrollment;
use App\Models\FeeTemplate;
use App\Models\Program;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StudentEnrollmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_applicant_starts_pending_payment_enrollment_with_profile_and_section_assignment(): void
    {
        [$term, $program, $curriculum] = $this->academicContext();
        $section = $this->section($term, $program, $curriculum, ['max_seats' => 2]);
        $group = $this->group($section, ['capacity' => 2, 'modality' => 'online']);
        $intake = $this->approvedIntake($term, $program, [
            'preferred_modality' => 'online',
        ]);
        $document = $this->applicantDocument($intake);
        $registrar = $this->userWithPermissions(['approve-documents', 'manage-sections']);

        $enrollment = app(StudentEnrollmentService::class)->startFromApprovedApplicant($intake, $registrar, [
            'section_id' => $section->id,
            'section_delivery_group_id' => $group->id,
        ]);

        $studentProfile = StudentProfile::query()->where('user_id', $intake->user_id)->firstOrFail();

        $this->assertSame('pending_payment', $enrollment->status);
        $this->assertSame(ApplicantIntake::ApplicantTypeNew, $enrollment->student_type);
        $this->assertSame($studentProfile->id, $enrollment->student_profile_id);
        $this->assertSame($section->id, $enrollment->section_id);
        $this->assertSame($group->id, $enrollment->section_delivery_group_id);
        $this->assertSame('online', $enrollment->modality);
        $this->assertStringStartsWith('TALA-', $studentProfile->student_id);
        $this->assertSame($intake->lrn, $studentProfile->lrn);
        $this->assertSame($studentProfile->id, $document->fresh()->student_profile_id);
        $this->assertSame(User::StatusApplicantApproved, $intake->user->refresh()->status);
        $this->assertTrue($intake->user->hasRole('applicant'));
        $this->assertFalse($intake->user->hasRole('student'));
        $this->assertSame(1, $section->fresh()->enrolled_count);
        $this->assertSame(1, $group->fresh()->assigned_count);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Enrollment::class,
            'subject_id' => $enrollment->id,
            'event' => 'student_enrollment_started_from_applicant',
        ]);
    }

    public function test_regular_enrollment_auto_assigns_best_compatible_delivery_group(): void
    {
        [$term, $program, $curriculum] = $this->academicContext();
        $section = $this->section($term, $program, $curriculum);
        $group = $this->group($section, ['capacity' => 2, 'modality' => 'on_site']);
        $studentProfile = StudentProfile::factory()->create([
            'program_id' => $program->id,
            'year_level' => '1st Year',
            'modality' => 'on_site',
            'current_balance' => '0.00',
        ]);
        $registrar = $this->userWithPermissions(['manage-sections']);

        $enrollment = app(StudentEnrollmentService::class)->startRegularEnrollment($studentProfile, $term, $registrar);

        $this->assertSame('pending_payment', $enrollment->status);
        $this->assertSame('regular', $enrollment->student_type);
        $this->assertSame($section->id, $enrollment->section_id);
        $this->assertSame($group->id, $enrollment->section_delivery_group_id);
        $this->assertSame(1, $section->fresh()->enrolled_count);
        $this->assertSame(1, $group->fresh()->assigned_count);
    }

    public function test_regular_enrollment_blocks_students_with_outstanding_balance(): void
    {
        [$term, $program, $curriculum] = $this->academicContext();
        $section = $this->section($term, $program, $curriculum);
        $group = $this->group($section);
        $studentProfile = StudentProfile::factory()->create([
            'program_id' => $program->id,
            'year_level' => '1st Year',
            'current_balance' => '500.00',
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(StudentEnrollmentService::class)->startRegularEnrollment(
                $studentProfile,
                $term,
                $this->userWithPermissions(['manage-sections']),
                [
                    'section_id' => $section->id,
                    'section_delivery_group_id' => $group->id,
                ],
            );
        } finally {
            $this->assertDatabaseMissing('enrollments', [
                'student_profile_id' => $studentProfile->id,
                'term_id' => $term->id,
            ]);
            $this->assertSame(0, $section->fresh()->enrolled_count);
            $this->assertSame(0, $group->fresh()->assigned_count);
        }
    }

    public function test_finance_cleared_handover_activates_student_role_and_cor_readiness(): void
    {
        [$term, $program, $curriculum] = $this->academicContext();
        $section = $this->section($term, $program, $curriculum);
        $group = $this->group($section);
        $intake = $this->approvedIntake($term, $program);
        $registrar = $this->userWithPermissions(['approve-documents', 'manage-sections']);
        $service = app(StudentEnrollmentService::class);
        $enrollment = $service->startFromApprovedApplicant($intake, $registrar, [
            'section_id' => $section->id,
            'section_delivery_group_id' => $group->id,
        ]);

        try {
            $service->completeFinanceClearedHandover($enrollment, $registrar);
            $this->fail('Expected pending-payment handover to remain blocked.');
        } catch (ValidationException) {
            $this->assertSame(['finance_not_cleared', 'account_not_active', 'student_role_missing'], $service->corReadiness($enrollment->fresh())['blockers']);
        }

        $enrollment->forceFill([
            'status' => 'pre_enrolled',
            'pre_enrolled_at' => now(),
        ])->save();

        $handedOver = $service->completeFinanceClearedHandover($enrollment->fresh(), $registrar);
        $user = $intake->user->refresh();

        $this->assertSame(User::StatusActive, $user->status);
        $this->assertSame($handedOver->studentProfile->student_id, $user->username);
        $this->assertTrue($user->hasRole('student'));
        $this->assertFalse($user->hasRole('applicant'));
        $this->assertSame(['ready' => true, 'blockers' => []], $service->corReadiness($handedOver));
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Enrollment::class,
            'subject_id' => $handedOver->id,
            'event' => 'student_account_handover_completed',
        ]);
    }

    public function test_manual_payment_finance_clearance_runs_account_handover_in_same_flow(): void
    {
        [$term, $program, $curriculum] = $this->academicContext();
        $section = $this->section($term, $program, $curriculum);
        $group = $this->group($section);
        $intake = $this->approvedIntake($term, $program);
        $registrar = $this->userWithPermissions(['approve-documents', 'manage-sections']);
        $accounting = $this->userWithPermissions(['create-assessments', 'process-payments']);
        $enrollment = app(StudentEnrollmentService::class)->startFromApprovedApplicant($intake, $registrar, [
            'section_id' => $section->id,
            'section_delivery_group_id' => $group->id,
        ]);

        FeeTemplate::factory()->create([
            'program_id' => $program->id,
            'year_level' => '1st Year',
            'tuition_fee' => '1000.00',
            'laboratory_fee' => '0.00',
            'misc_fee' => '0.00',
            'other_fee' => '0.00',
            'minimum_downpayment_percentage' => '20.00',
        ]);

        app(EnrollmentAssessmentService::class)->assess($enrollment->id, $accounting);

        $summary = app(PaymentConfirmationService::class)->confirmManualPayment(
            enrollmentId: $enrollment->id,
            amount: '200.00',
            channel: 'cash',
            paymentReference: 'OR-1001',
            actor: $accounting,
        );

        $cleared = $enrollment->fresh(['studentProfile.user']);
        $user = $intake->user->refresh();

        $this->assertTrue($summary['finance_cleared']);
        $this->assertSame('pre_enrolled', $cleared->status);
        $this->assertSame(User::StatusActive, $user->status);
        $this->assertSame($cleared->studentProfile->student_id, $user->username);
        $this->assertTrue($user->hasRole('student'));
        $this->assertFalse($user->hasRole('applicant'));
        $this->assertSame(['ready' => true, 'blockers' => []], app(StudentEnrollmentService::class)->corReadiness($cleared));
    }

    public function test_full_delivery_group_blocks_applicant_enrollment_and_rolls_back_profile_creation(): void
    {
        [$term, $program, $curriculum] = $this->academicContext();
        $section = $this->section($term, $program, $curriculum, ['max_seats' => 2]);
        $group = $this->group($section, [
            'capacity' => 1,
            'assigned_count' => 1,
        ]);
        $intake = $this->approvedIntake($term, $program);

        $this->expectException(ValidationException::class);

        try {
            app(StudentEnrollmentService::class)->startFromApprovedApplicant(
                $intake,
                $this->userWithPermissions(['approve-documents', 'manage-sections']),
                [
                    'section_id' => $section->id,
                    'section_delivery_group_id' => $group->id,
                ],
            );
        } finally {
            $this->assertDatabaseMissing('student_profiles', [
                'user_id' => $intake->user_id,
            ]);
            $this->assertDatabaseMissing('enrollments', [
                'term_id' => $term->id,
            ]);
            $this->assertSame(0, $section->fresh()->enrolled_count);
            $this->assertSame(1, $group->fresh()->assigned_count);
        }
    }

    public function test_start_from_approved_applicant_is_idempotent_and_does_not_double_count_capacity(): void
    {
        [$term, $program, $curriculum] = $this->academicContext();
        $section = $this->section($term, $program, $curriculum, ['max_seats' => 2]);
        $group = $this->group($section, ['capacity' => 2]);
        $intake = $this->approvedIntake($term, $program);
        $registrar = $this->userWithPermissions(['approve-documents', 'manage-sections']);
        $service = app(StudentEnrollmentService::class);
        $payload = [
            'section_id' => $section->id,
            'section_delivery_group_id' => $group->id,
        ];

        $first = $service->startFromApprovedApplicant($intake, $registrar, $payload);
        $second = $service->startFromApprovedApplicant($intake->fresh(), $registrar, $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, StudentProfile::query()->where('user_id', $intake->user_id)->count());
        $this->assertSame(1, Enrollment::query()->where('student_profile_id', $first->student_profile_id)->where('term_id', $term->id)->count());
        $this->assertSame(1, $section->fresh()->enrolled_count);
        $this->assertSame(1, $group->fresh()->assigned_count);
        $this->assertSame(1, DB::table('activity_log')
            ->where('subject_type', Enrollment::class)
            ->where('subject_id', $first->id)
            ->where('event', 'student_enrollment_started_from_applicant')
            ->count());
    }

    /**
     * @return array{Term, Program, Curriculum}
     */
    private function academicContext(): array
    {
        $term = Term::factory()->create();
        $program = Program::factory()->create(['department' => 'college']);
        $curriculum = Curriculum::factory()->create(['program_id' => $program->id]);

        return [$term, $program, $curriculum];
    }

    private function approvedIntake(Term $term, Program $program, array $overrides = []): ApplicantIntake
    {
        Role::findOrCreate('applicant', 'web');

        $user = User::factory()->create([
            'status' => User::StatusApplicantApproved,
        ]);
        $user->assignRole('applicant');

        return ApplicantIntake::factory()->create([
            'user_id' => $user->id,
            'term_id' => $term->id,
            'program_id' => $program->id,
            'year_level' => '1st Year',
            'applicant_type' => ApplicantIntake::ApplicantTypeNew,
            'preferred_modality' => 'on_site',
            'status' => ApplicantIntake::StatusApproved,
            'duplicate_check_status' => ApplicantIntake::DuplicateStatusClear,
            'approved_at' => now(),
            ...$overrides,
        ]);
    }

    private function applicantDocument(ApplicantIntake $intake): DocumentUpload
    {
        return DocumentUpload::query()->create([
            'applicant_intake_id' => $intake->id,
            'student_profile_id' => null,
            'user_id' => $intake->user_id,
            'term_id' => $intake->term_id,
            'document_type' => 'psa_birth_certificate',
            'file_disk' => 'local',
            'file_path' => 'applicant-documents/psa.jpg',
            'file_name' => 'psa.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'upload_status' => 'uploaded',
            'review_status' => DocumentUpload::ReviewStatusRegistrarApproved,
            'student_confirmed_payload' => [],
        ]);
    }

    private function section(Term $term, Program $program, Curriculum $curriculum, array $overrides = []): Section
    {
        return Section::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => '1st Year',
            'curriculum_period' => '1st Semester',
            ...$overrides,
        ]);
    }

    private function group(Section $section, array $overrides = []): SectionDeliveryGroup
    {
        $modality = $overrides['modality'] ?? 'on_site';
        $pattern = DeliveryPattern::factory()->create([
            'modality' => $modality,
            'default_room_required' => SectionDeliveryGroup::modalityRequiresRoom($modality),
        ]);

        return SectionDeliveryGroup::factory()->create([
            'section_id' => $section->id,
            'delivery_pattern_id' => $pattern->id,
            'modality' => $modality,
            'room_required' => SectionDeliveryGroup::modalityRequiresRoom($modality),
            'room' => SectionDeliveryGroup::modalityRequiresRoom($modality) ? 'R-101' : null,
            ...$overrides,
        ]);
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
