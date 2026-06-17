<?php

namespace Tests\Feature;

use App\Actions\Enrollment\EnrollmentAssessmentService;
use App\Actions\Enrollment\StudentEnrollmentService;
use App\Models\Curriculum;
use App\Models\DeliveryPattern;
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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PayMongoWebhookFinanceClearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_paid_webhook_finance_clears_real_enrollment_and_runs_student_handover(): void
    {
        config([
            'tala_integrations.payments.paymongo.webhook_signature' => null,
            'paymongo.webhook_signature' => null,
            'paymongo.livemode' => false,
            'paymongo.signature_header_name' => 'paymongo-signature',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('applicant', 'web');

        $term = Term::factory()->create();
        $program = Program::factory()->create(['department' => 'college']);
        $curriculum = Curriculum::factory()->create(['program_id' => $program->id]);
        $studentUser = User::factory()->create([
            'status' => User::StatusApplicantApproved,
            'username' => 'online-payer@example.test',
        ]);
        $studentUser->assignRole('applicant');

        $studentProfile = StudentProfile::factory()->create([
            'user_id' => $studentUser->id,
            'student_id' => 'TALA-2026-0100',
            'education_level' => 'college',
            'program_id' => $program->id,
            'year_level' => '1st Year',
            'modality' => 'on_site',
            'current_balance' => '0.00',
        ]);

        $section = Section::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => '1st Year',
            'curriculum_period' => '1st Semester',
            'max_seats' => 30,
            'enrolled_count' => 1,
            'modality' => 'on_site',
        ]);
        $pattern = DeliveryPattern::factory()->create([
            'modality' => 'on_site',
            'default_room_required' => true,
        ]);
        $group = SectionDeliveryGroup::factory()->create([
            'section_id' => $section->id,
            'delivery_pattern_id' => $pattern->id,
            'modality' => 'on_site',
            'capacity' => 30,
            'assigned_count' => 1,
            'room_required' => true,
            'room' => 'R-101',
        ]);

        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $term->id,
            'section_id' => $section->id,
            'section_delivery_group_id' => $group->id,
            'status' => 'pending_payment',
            'student_type' => 'new',
            'year_level' => '1st Year',
            'modality' => 'on_site',
        ]);

        FeeTemplate::factory()->create([
            'education_level' => 'college',
            'program_id' => $program->id,
            'year_level' => '1st Year',
            'tuition_fee' => '1000.00',
            'laboratory_fee' => '0.00',
            'misc_fee' => '0.00',
            'other_fee' => '0.00',
            'minimum_downpayment_percentage' => '20.00',
        ]);

        $accounting = User::factory()->create();
        $accounting->givePermissionTo(Permission::findOrCreate('create-assessments'));
        app(EnrollmentAssessmentService::class)->assess($enrollment->id, $accounting);

        $this->assertSame('500.00', number_format((float) $studentProfile->refresh()->current_balance, 2, '.', ''));

        $attemptId = (int) DB::table('payment_attempts')->insertGetId([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'ledger_entry_id' => null,
            'channel' => 'paymongo',
            'status' => 'pending',
            'provider' => 'paymongo',
            'provider_event_id' => null,
            'provider_checkout_session_id' => 'cs_clearance_123',
            'provider_payment_id' => null,
            'provider_payment_intent_id' => null,
            'amount' => '100.00',
            'meta' => json_encode(['checkout_url' => 'https://checkout.paymongo.test/cs_clearance_123'], JSON_UNESCAPED_SLASHES),
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/webhooks/paymongo', $this->checkoutPaidPayload(
            eventId: 'evt_clearance_123',
            checkoutSessionId: 'cs_clearance_123',
            amountCentavos: 10000,
        ))->assertAccepted();

        $this->assertDatabaseHas('payment_attempts', [
            'id' => $attemptId,
            'status' => 'paid',
            'provider_event_id' => 'evt_clearance_123',
        ]);

        $clearedEnrollment = $enrollment->fresh(['studentProfile.user']);
        $user = $studentUser->refresh();

        $this->assertSame('400.00', number_format((float) $studentProfile->refresh()->current_balance, 2, '.', ''));
        $this->assertSame('pre_enrolled', $clearedEnrollment->status);
        $this->assertNotNull($clearedEnrollment->pre_enrolled_at);
        $this->assertSame(User::StatusActive, $user->status);
        $this->assertSame($studentProfile->student_id, $user->username);
        $this->assertTrue($user->hasRole('student'));
        $this->assertFalse($user->hasRole('applicant'));
        $this->assertSame(['ready' => true, 'blockers' => []], app(StudentEnrollmentService::class)->corReadiness($clearedEnrollment));
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Enrollment::class,
            'subject_id' => $enrollment->id,
            'event' => 'student_account_handover_completed',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutPaidPayload(string $eventId, string $checkoutSessionId, int $amountCentavos): array
    {
        return [
            'data' => [
                'id' => $eventId,
                'type' => 'event',
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'data' => [
                        'id' => $checkoutSessionId,
                        'type' => 'checkout_session',
                        'attributes' => [
                            'amount_paid' => $amountCentavos,
                            'currency' => 'PHP',
                            'payment_intent_id' => 'pi_'.$checkoutSessionId,
                            'status' => 'paid',
                        ],
                    ],
                ],
            ],
        ];
    }
}
