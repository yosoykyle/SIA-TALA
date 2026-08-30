<?php

namespace Tests\Feature\Academics;

use App\Actions\Academics\AcademicRecordNotificationService;
use App\Actions\Completion\CompletionReadinessProjection;
use App\Actions\Completion\IssueTranscript;
use App\Actions\Completion\RecordDegreeConferral;
use App\Actions\Completion\RecordTranscriptRequest;
use App\Actions\Completion\ReplaceTranscript;
use App\Actions\Completion\SubmitGraduationApplication;
use App\Actions\Completion\TranscriptLifecycleProjection;
use App\Actions\Completion\VoidTranscript;
use App\Actions\Finance\RecordOfficialOutputPaymentClearance;
use App\Actions\StudentLifecycle\CreateHold;
use App\Actions\StudentLifecycle\ResolveHold;
use App\Filament\Resources\TranscriptRequests\TranscriptRequestResource;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\GraduationApplication;
use App\Models\Hold;
use App\Models\OfficialOutputPaymentClearance;
use App\Models\OperationalEvent;
use App\Models\ProgramShiftCreditEntry;
use App\Models\Section;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\TranscriptIssuanceEvent;
use App\Models\TranscriptRequest;
use App\Models\TranscriptSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HumanCenteredCompletionAndTorAcceptanceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        foreach (['student', User::StaffRoleRegistrar, User::StaffRoleAccounting, User::StaffRoleAcademicHead] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        config([
            'institution.address' => 'Synthetic Servitech Campus, Philippines',
            'institution.public.support_phone' => '0947 737 9208',
        ]);
        Mail::fake();
    }

    #[Test]
    public function fully_credited_completion_is_eligible_without_inventing_a_final_term(): void
    {
        $fixture = $this->creditedFixture();
        $projection = app(CompletionReadinessProjection::class)->forStudent($fixture['student']);

        $this->assertSame(CompletionReadinessProjection::EligibleToApply, $projection['state']);
        $this->assertSame([], $projection['blockers']);

        $application = app(SubmitGraduationApplication::class)->execute($fixture['student'], $fixture['student']->user);
        $this->assertNull($application->term_id);
        $this->assertDatabaseMissing('operational_events', [
            'related_record_type' => GraduationApplication::class,
            'related_record_id' => $application->id,
        ]);

        $conferral = app(RecordDegreeConferral::class)->execute(
            $fixture['student'], $fixture['registrar'], 'Bachelor of Science in Information Technology',
            '2028-06-30', 'SYNTH-CONFERRAL-AUTHORITY',
        );
        $this->assertSame(CompletionReadinessProjection::Conferred, app(CompletionReadinessProjection::class)->forStudent($fixture['student'])['state']);
        $this->assertDatabaseHas('student_lifecycle_changes', [
            'student_profile_id' => $fixture['student']->id,
            'term_id' => null,
            'type' => StudentLifecycleChange::TypeCompletion,
        ]);
        $this->assertSame($conferral->id, app(RecordDegreeConferral::class)->execute(
            $fixture['student'], $fixture['registrar'], 'Bachelor of Science in Information Technology',
            '2028-06-30', 'SYNTH-CONFERRAL-AUTHORITY',
        )->id);

        $this->expectException(ValidationException::class);
        app(RecordDegreeConferral::class)->execute(
            $fixture['student'], $fixture['registrar'], 'A conflicting degree',
            '2028-06-30', 'SYNTH-CONFERRAL-AUTHORITY',
        );
    }

    #[Test]
    public function completion_notification_ledger_records_only_actionable_blocker_deltas(): void
    {
        $fixture = $this->inProgressFixture();
        $application = app(SubmitGraduationApplication::class)->execute($fixture['student'], $fixture['student']->user);
        $projection = app(CompletionReadinessProjection::class)->forStudent($fixture['student']);
        $this->assertSame(CompletionReadinessProjection::AwaitingResultsOrClearance, $projection['state']);
        $this->assertArrayHasKey('source_ref', $projection['blockers'][0]);
        $this->assertArrayHasKey('source_as_of', $projection['blockers'][0]);
        $this->assertArrayHasKey('consequence', $projection['blockers'][0]);
        $this->assertDatabaseMissing('operational_events', [
            'related_record_type' => GraduationApplication::class,
            'related_record_id' => $application->id,
        ]);

        $hold = app(CreateHold::class)->execute($fixture['student'], [
            'hold_type' => Hold::TypeDocumentary,
            'blocking_level' => Hold::BlockingGraduationEligibility,
            'reason' => 'A required completion document needs review.',
            'student_message' => 'Registrar must verify the required completion document.',
            'resolution_requirement' => 'Submit the named document through the authorized Registrar path.',
        ], $fixture['registrar']);

        $events = OperationalEvent::query()->where('event_type', OperationalEvent::TypeCompletionRequiresActionEmail)->get();
        $this->assertCount(1, $events);
        app(CompletionReadinessProjection::class)->persist($fixture['student'], $fixture['registrar']);
        $this->assertSame(1, OperationalEvent::query()->where('event_type', OperationalEvent::TypeCompletionRequiresActionEmail)->count());

        app(ResolveHold::class)->execute($hold, $fixture['registrar'], 'SYNTH-RESOLUTION-EVIDENCE');
        $this->assertSame(1, OperationalEvent::query()->where('event_type', OperationalEvent::TypeCompletionRequiresActionEmail)->count());

        $event = $events->sole();
        $externalId = $event->external_id;
        $event->update(['status' => OperationalEvent::StatusFailed]);
        $resent = app(AcademicRecordNotificationService::class)->resend($event, $fixture['student']->user);
        $this->assertSame($event->id, $resent->id);
        $this->assertSame($externalId, $resent->external_id);
        $this->assertSame(1, OperationalEvent::query()->where('external_id', $externalId)->count());
    }

    #[Test]
    public function issuance_and_replacement_require_current_attributable_preview_bindings(): void
    {
        $fixture = $this->creditedFixture();
        app(SubmitGraduationApplication::class)->execute($fixture['student'], $fixture['student']->user);
        $conferral = app(RecordDegreeConferral::class)->execute(
            $fixture['student'], $fixture['registrar'], 'Bachelor of Science in Information Technology',
            '2028-06-30', 'SYNTH-CONFERRAL-AUTHORITY',
        );
        $request = app(RecordTranscriptRequest::class)->execute(
            $conferral, $fixture['registrar'], 'EXT-TOR-HUMAN-CENTERED', '2028-07-01',
            'Synthetic Registrar', 'College Registrar', TranscriptRequest::SealPlacementInstruction,
            sealPlacementInstruction: 'Apply the controlled seal in the marked certification area.',
        );
        app(RecordOfficialOutputPaymentClearance::class)->execute(
            $request, $fixture['accounting'], OfficialOutputPaymentClearance::StateNotRequired,
            'SYNTH-CLEARANCE-AUTHORITY', 'No collectible transcript fee applies to this synthetic request.',
        );

        $preview = $this->actingAs($fixture['registrar'])->get(route('transcripts.preview', $request));
        $preview->assertOk();
        $confirmation = (string) $preview->headers->get('X-TALA-Preview-Confirmation');
        $this->assertNotEmpty($confirmation);
        $this->assertDatabaseHas('output_access_logs', [
            'source_record_type' => TranscriptRequest::class,
            'source_record_id' => $request->id,
            'action' => 'preview',
            'status' => 'generated',
        ]);

        $request->update(['signatory_title' => 'Acting College Registrar']);
        try {
            app(IssueTranscript::class)->execute($request, $fixture['registrar'], 'SYNTH-ISSUANCE-AUTHORITY', $confirmation);
            $this->fail('Changed preview inputs must invalidate issuance confirmation.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('transcript_snapshots', 0);
        }

        $preview = $this->actingAs($fixture['registrar'])->get(route('transcripts.preview', $request->fresh()));
        $confirmation = (string) $preview->headers->get('X-TALA-Preview-Confirmation');
        $snapshot = app(IssueTranscript::class)->execute($request->fresh(), $fixture['registrar'], 'SYNTH-ISSUANCE-AUTHORITY', $confirmation);
        $this->assertSame($snapshot->id, app(IssueTranscript::class)->execute($request->fresh(), $fixture['registrar'], 'SYNTH-ISSUANCE-AUTHORITY', $confirmation)->id);
        $this->assertSame(TranscriptIssuanceEvent::TypeIssued, app(TranscriptLifecycleProjection::class)->statusForRequest($request->fresh()));

        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $this->actingAs($academicHead)->get(route('transcript-snapshots.show', $snapshot))->assertForbidden();
        $this->assertDatabaseHas('output_access_logs', [
            'source_record_type' => TranscriptSnapshot::class,
            'source_record_id' => $snapshot->id,
            'actor_user_id' => $academicHead->id,
            'action' => 'denied',
            'status' => 'denied',
        ]);
        $this->assertFalse(TranscriptRequestResource::canAccess());

        app(VoidTranscript::class)->execute($snapshot, $fixture['registrar'], 'SYNTH-VOID-AUTHORITY', 'The signatory title requires replacement.');
        $replacementPreview = $this->actingAs($fixture['registrar'])->get(route('transcripts.preview', [
            'transcriptRequest' => $request,
            'operation' => 'replacement',
            'predecessor' => $snapshot->id,
        ]));
        $replacementConfirmation = (string) $replacementPreview->headers->get('X-TALA-Preview-Confirmation');
        $replacement = app(ReplaceTranscript::class)->execute(
            $snapshot, $fixture['registrar'], 'SYNTH-REPLACEMENT-AUTHORITY',
            'Record the corrected immutable successor.', $replacementConfirmation,
        );

        $this->assertSame($snapshot->id, $replacement->supersedes_snapshot_id);
        $this->assertSame(TranscriptIssuanceEvent::TypeReplacement, app(TranscriptLifecycleProjection::class)->statusForRequest($request->fresh()));
        $this->assertSame($replacement->id, app(ReplaceTranscript::class)->execute(
            $snapshot, $fixture['registrar'], 'SYNTH-REPLACEMENT-AUTHORITY',
            'Record the corrected immutable successor.', $replacementConfirmation,
        )->id);
    }

    /** @return array{registrar: User, accounting: User, student: StudentProfile, term: Term} */
    private function creditedFixture(): array
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $student = StudentProfile::factory()->create();
        $student->user->assignRole('student');
        $term = Term::factory()->create();
        $specification = CourseSpecification::factory()->create([
            'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
            'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
        ]);
        $entry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $student->curriculum_version_id,
            'course_specification_id' => $specification->id,
        ]);
        $authority = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
            'type' => StudentLifecycleChange::TypeProgramShift,
            'state' => StudentLifecycleChange::StateApplied,
        ]);
        ProgramShiftCreditEntry::factory()->create([
            'student_lifecycle_change_id' => $authority->id,
            'curriculum_entry_id' => $entry->id,
            'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
            'state' => ProgramShiftCreditEntry::StateRecorded,
            'numeric_grade' => '2.00',
        ]);

        return compact('registrar', 'accounting', 'student', 'term');
    }

    /** @return array{registrar: User, student: StudentProfile} */
    private function inProgressFixture(): array
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $student = StudentProfile::factory()->create();
        $student->user->assignRole('student');
        $term = Term::factory()->create();
        $specification = CourseSpecification::factory()->create([
            'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
            'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
        ]);
        $entry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $student->curriculum_version_id,
            'course_specification_id' => $specification->id,
        ]);
        $offering = TermOffering::factory()->create([
            'term_id' => $term->id,
            'curriculum_entry_id' => $entry->id,
            'state' => TermOffering::StateScheduled,
        ]);
        $section = Section::factory()->create(['term_offering_id' => $offering->id, 'state' => Section::StateOpen]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $student->id,
            'credential_user_id' => $student->user_id,
            'term_id' => $term->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now(),
        ]);
        CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => $specification->credit_units,
            'added_at' => now(),
        ]);

        return compact('registrar', 'student');
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
