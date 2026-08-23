<?php

namespace Tests\Feature\Academics;

use App\Actions\Academics\RecordExternalCompetencyResult;
use App\Actions\Completion\CompletionReadinessProjection;
use App\Actions\Completion\CorrectDegreeConferral;
use App\Actions\Completion\IssueTranscript;
use App\Actions\Completion\RecordDegreeConferral;
use App\Actions\Completion\RecordTranscriptRequest;
use App\Actions\Completion\ReplaceTranscript;
use App\Actions\Completion\SubmitGraduationApplication;
use App\Actions\Completion\VoidTranscript;
use App\Actions\Completion\WithdrawGraduationApplication;
use App\Actions\Finance\RecordOfficialOutputPaymentClearance;
use App\Actions\Grades\ManageTeachingAssignment;
use App\Actions\Grades\PostAndReleaseGradeRoster;
use App\Actions\Grades\SaveFinalGradeResult;
use App\Actions\Grades\SubmitGradeRoster;
use App\Actions\Grades\SynchronizeOfficialGradeRoster;
use App\Mail\AcademicRecordChangedMail;
use App\Models\CalendarEvent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\ExternalCompetencyRequirement;
use App\Models\ExternalCompetencyResult;
use App\Models\GraduationApplication;
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

class CompletionToStandardTorJourneyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
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
    public function readiness_application_and_conferral_use_current_attributable_sources(): void
    {
        $fixture = $this->fixture();
        $studentUser = $fixture['student']->user;
        $studentUser->assignRole('student');
        $projection = app(CompletionReadinessProjection::class)->forStudent($fixture['student']);

        $this->assertSame(CompletionReadinessProjection::EligibleToApply, $projection['state']);
        $this->assertSame('Registrar and Faculty', $projection['blockers'][0]['owner']);

        $tracked = ExternalCompetencyRequirement::factory()->create([
            'curriculum_version_id' => $fixture['student']->curriculum_version_id,
            'treatment' => ExternalCompetencyRequirement::TreatmentTrackedOnly,
            'state' => 'ACTIVE',
        ]);
        $this->assertSame(CompletionReadinessProjection::EligibleToApply, app(CompletionReadinessProjection::class)->forStudent($fixture['student'])['state']);

        $required = ExternalCompetencyRequirement::factory()->create([
            'curriculum_version_id' => $fixture['student']->curriculum_version_id,
            'treatment' => ExternalCompetencyRequirement::TreatmentCompletionRequired,
            'state' => 'ACTIVE',
        ]);
        $this->assertSame(CompletionReadinessProjection::NotEligible, app(CompletionReadinessProjection::class)->forStudent($fixture['student'])['state']);
        app(RecordExternalCompetencyResult::class)->execute(
            $required,
            $fixture['student'],
            ExternalCompetencyResult::OutcomeCompetent,
            'SYNTH-COMPETENCY-EVIDENCE',
            'SYNTH-COMPETENCY-AUTHORITY',
            today(),
            $fixture['registrar'],
        );

        $application = app(SubmitGraduationApplication::class)->execute($fixture['student'], $studentUser);
        $this->assertSame($application->id, app(SubmitGraduationApplication::class)->execute($fixture['student'], $studentUser)->id);
        $this->assertSame(CompletionReadinessProjection::AwaitingResultsOrClearance, app(CompletionReadinessProjection::class)->forStudent($fixture['student'])['state']);

        app(WithdrawGraduationApplication::class)->execute($application, $studentUser, 'I need to correct my completion intent.');
        $reapplication = app(SubmitGraduationApplication::class)->execute($fixture['student'], $studentUser);
        $this->assertSame(2, $reapplication->version);
        $this->assertSame($application->id, $reapplication->supersedes_application_id);

        $this->releaseResult($fixture, '2.00');
        $this->assertSame(CompletionReadinessProjection::ReadyForConferral, app(CompletionReadinessProjection::class)->forStudent($fixture['student'])['state']);

        $conferral = app(RecordDegreeConferral::class)->execute(
            $fixture['student'],
            $fixture['registrar'],
            'Bachelor of Science in Information Technology',
            '2028-06-30',
            'SYNTH-CONFERRAL-AUTHORITY',
        );
        $this->assertSame($conferral->id, app(RecordDegreeConferral::class)->execute(
            $fixture['student'],
            $fixture['registrar'],
            'Bachelor of Science in Information Technology',
            '2028-06-30',
            'SYNTH-CONFERRAL-AUTHORITY',
        )->id);
        $this->assertSame(StudentProfile::LifecycleCompleted, $fixture['student']->fresh()->lifecycle_status);
        $this->assertSame(CompletionReadinessProjection::Conferred, app(CompletionReadinessProjection::class)->forStudent($fixture['student'])['state']);
        $this->assertDatabaseCount('degree_conferrals', 1);
        $this->assertDatabaseHas('student_lifecycle_changes', [
            'student_profile_id' => $fixture['student']->id,
            'type' => 'COMPLETION',
        ]);
        $this->assertSame(3, OperationalEvent::query()
            ->where('related_record_type', GraduationApplication::class)
            ->count());
        $this->assertSame(1, OperationalEvent::query()
            ->where('related_record_type', StudentLifecycleChange::class)
            ->count());
        Mail::assertQueued(AcademicRecordChangedMail::class, function (AcademicRecordChangedMail $mail): bool {
            return $mail->attachments() === []
                && ! str_contains($mail->changeLabel, 'Bachelor')
                && ! str_contains($mail->changeLabel, '2.00');
        });
        $this->assertTrue($tracked->fresh()->exists);
    }

    #[Test]
    public function tor_request_clearance_preview_issuance_void_and_replacement_are_request_bound(): void
    {
        $fixture = $this->fixture();
        $fixture['student']->user->assignRole('student');
        $creditedSpecification = CourseSpecification::factory()->create([
            'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
            'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
        ]);
        $creditedEntry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $fixture['student']->curriculum_version_id,
            'course_specification_id' => $creditedSpecification->id,
        ]);
        $creditAuthority = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $fixture['student']->id,
            'term_id' => $fixture['term']->id,
            'type' => StudentLifecycleChange::TypeProgramShift,
            'state' => StudentLifecycleChange::StateApplied,
        ]);
        ProgramShiftCreditEntry::factory()->create([
            'student_lifecycle_change_id' => $creditAuthority->id,
            'curriculum_entry_id' => $creditedEntry->id,
            'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
            'state' => ProgramShiftCreditEntry::StateRecorded,
            'numeric_grade' => '2.25',
        ]);
        $this->releaseResult($fixture, '1.75');
        app(SubmitGraduationApplication::class)->execute($fixture['student'], $fixture['student']->user);
        $conferral = app(RecordDegreeConferral::class)->execute(
            $fixture['student'], $fixture['registrar'], 'Bachelor of Science in Information Technology',
            '2028-06-30', 'SYNTH-CONFERRAL-AUTHORITY',
        );
        $request = app(RecordTranscriptRequest::class)->execute(
            $conferral,
            $fixture['registrar'],
            'EXT-TOR-0001',
            '2028-07-01',
            'Synthetic Registrar',
            'College Registrar',
            TranscriptRequest::SealPlacementInstruction,
            sealPlacementInstruction: 'Apply the controlled seal in the marked certification area.',
        );

        $this->assertSame('2028-07-31', $request->due_on->toDateString());
        $this->assertSame(OfficialOutputPaymentClearance::StateActionNeeded, $request->clearanceState());
        try {
            app(IssueTranscript::class)->execute($request, $fixture['registrar'], 'SYNTH-ISSUANCE-AUTHORITY');
            $this->fail('Issuance must fail before exact-request Accounting clearance.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('transcript_snapshots', 0);
            $this->assertDatabaseCount('transcript_issuance_events', 0);
        }

        try {
            app(RecordOfficialOutputPaymentClearance::class)->execute(
                $request,
                $fixture['accounting'],
                OfficialOutputPaymentClearance::StateCleared,
                'SYNTH-ACCOUNTING-AUTHORITY',
                'Reject an invalid negative required amount.',
                '-1.00',
            );
            $this->fail('A negative request-specific amount must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('official_output_payment_clearances', 0);
        }

        app(RecordOfficialOutputPaymentClearance::class)->execute(
            $request,
            $fixture['accounting'],
            OfficialOutputPaymentClearance::StateNotRequired,
            'SYNTH-ACCOUNTING-AUTHORITY',
            'The synthetic acceptance request has no collectible transcript fee.',
        );
        $this->assertSame(OfficialOutputPaymentClearance::StateNotRequired, $request->fresh()->clearanceState());

        config(['institution.address' => null]);
        try {
            app(IssueTranscript::class)->execute($request, $fixture['registrar'], 'SYNTH-ISSUANCE-AUTHORITY');
            $this->fail('Rendering failure must not create an official transcript artifact.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('transcript_snapshots', 0);
            $this->assertDatabaseCount('transcript_issuance_events', 0);
        }
        config(['institution.address' => 'Synthetic Servitech Campus, Philippines']);

        $preview = $this->actingAs($fixture['registrar'])->get(route('transcripts.preview', $request));
        $preview->assertOk()
            ->assertSee('PREVIEW')
            ->assertSee('EXT-TOR-0001')
            ->assertSee('1.75')
            ->assertSee('Attempt 1')
            ->assertSee('Approved credit/equivalency')
            ->assertSee('term units')
            ->assertSee('cumulative earned units')
            ->assertSee('TALA-GEN-')
            ->assertSee('size: A4 portrait', escape: false)
            ->assertDontSee('Cumulative GWA')
            ->assertDontSee('LRN')
            ->assertDontSee('Raw score')
            ->assertDontSee('Faculty')
            ->assertDontSee('Schedule')
            ->assertDontSee('Financial');
        $this->assertDatabaseCount('transcript_issuance_events', 0);

        try {
            app(IssueTranscript::class)->execute(
                $request,
                $fixture['registrar'],
                'SYNTH-ISSUANCE-AUTHORITY',
                'TOR-STALE-REFERENCE',
            );
            $this->fail('A stale resulting reference must create no official transcript artifact.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('transcript_snapshots', 0);
            $this->assertDatabaseCount('transcript_issuance_events', 0);
        }

        $snapshot = app(IssueTranscript::class)->execute($request, $fixture['registrar'], 'SYNTH-ISSUANCE-AUTHORITY');
        $this->assertSame($snapshot->id, app(IssueTranscript::class)->execute($request, $fixture['registrar'], 'SYNTH-ISSUANCE-AUTHORITY')->id);
        $this->assertDatabaseCount('transcript_snapshots', 1);
        $this->assertDatabaseCount('transcript_issuance_events', 1);
        $this->assertSame($snapshot->reference, data_get($snapshot->content, 'document.reference'));
        $this->assertSame(
            'TALA-GEN-'.strtoupper(substr($snapshot->source_fingerprint, 0, 16)),
            data_get($snapshot->content, 'document.generation_reference'),
        );
        $this->assertDatabaseHas('output_access_logs', [
            'source_record_type' => TranscriptSnapshot::class,
            'source_record_id' => $snapshot->id,
            'action' => 'issued',
        ]);

        $this->actingAs($fixture['student']->user)->get(route('transcript-snapshots.show', $snapshot))->assertForbidden();
        $this->actingAs($fixture['registrar'])->get(route('transcript-snapshots.show', $snapshot))
            ->assertOk()->assertSee($snapshot->reference)->assertSee('ISSUED');

        try {
            app(ReplaceTranscript::class)->execute($snapshot, $fixture['registrar'], 'SYNTH-REPLACEMENT-AUTHORITY', 'Replacement before void is forbidden.');
            $this->fail('Replacement must require a recorded void predecessor.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('transcript_snapshots', 1);
        }

        $void = app(VoidTranscript::class)->execute($snapshot, $fixture['registrar'], 'SYNTH-VOID-AUTHORITY', 'Incorrect signatory placement.');
        $replacement = app(ReplaceTranscript::class)->execute($snapshot, $fixture['registrar'], 'SYNTH-REPLACEMENT-AUTHORITY', 'Issue the corrected successor.');
        $this->assertSame($snapshot->id, $replacement->supersedes_snapshot_id);
        $this->assertSame($void->id, TranscriptIssuanceEvent::query()->where('transcript_snapshot_id', $replacement->id)->sole()->predecessor_event_id);
        $this->assertSame(2, TranscriptSnapshot::query()->count());

        $corrected = app(CorrectDegreeConferral::class)->execute(
            $conferral,
            $fixture['registrar'],
            'Bachelor of Science in Information Systems',
            '2028-07-02',
            'SYNTH-CONFERRAL-CORRECTION-AUTHORITY',
            'Correct the externally authorized degree title and date.',
        );
        $this->assertSame($conferral->id, $corrected->supersedes_conferral_id);
        $this->assertSame(2, TranscriptIssuanceEvent::query()->where('type', TranscriptIssuanceEvent::TypeSuperseded)->count());
        try {
            app(RecordTranscriptRequest::class)->execute(
                $conferral,
                $fixture['registrar'],
                'EXT-TOR-STALE',
                '2028-07-03',
                'Synthetic Registrar',
                'College Registrar',
                TranscriptRequest::SealPlacementInstruction,
                sealPlacementInstruction: 'Apply the controlled seal in the marked certification area.',
            );
            $this->fail('A superseded conferral must not accept a new TOR request.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('transcript_requests', ['external_request_reference' => 'EXT-TOR-STALE']);
        }
    }

    /** @return array{registrar: User, accounting: User, faculty: User, student: StudentProfile, term: Term, section: Section} */
    private function fixture(): array
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $faculty = $this->staff(User::StaffRoleFaculty);
        Role::query()->firstOrCreate(['name' => User::StaffRoleFaculty, 'guard_name' => 'web']);
        $faculty->assignRole(User::StaffRoleFaculty);
        $term = Term::factory()->create([
            'starts_on' => '2028-01-08',
            'ends_on' => '2028-05-31',
            'state' => Term::StateActive,
        ]);
        $student = StudentProfile::factory()->create();
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
        CalendarEvent::factory()->create([
            'term_id' => $term->id,
            'process_key' => 'grade_entry',
            'state' => CalendarEvent::StateActive,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);

        return compact('registrar', 'accounting', 'faculty', 'student', 'term', 'section');
    }

    /** @param array{registrar: User, accounting: User, faculty: User, student: StudentProfile, term: Term, section: Section} $fixture */
    private function releaseResult(array $fixture, string $result): void
    {
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'SYNTH-COMPLETION-ASSIGNMENT');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        app(SaveFinalGradeResult::class)->execute($roster->rows->sole(), $result, null, $fixture['faculty']);
        $submitted = app(SubmitGradeRoster::class)->execute($roster, $fixture['faculty']);
        app(PostAndReleaseGradeRoster::class)->execute($submitted, $fixture['registrar'], 'SYNTH-COMPLETION-RELEASE');
    }

    private function staff(string $role): User
    {
        Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
