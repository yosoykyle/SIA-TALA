<?php

namespace Tests\Feature\Academics;

use App\Actions\Academics\AcademicRecordNotificationService;
use App\Actions\Completion\CompletionReadinessProjection;
use App\Actions\Completion\CorrectDegreeConferral;
use App\Actions\Completion\IssueTranscript;
use App\Actions\Completion\OfficialOutputRestrictionService;
use App\Actions\Completion\RecordDegreeConferral;
use App\Actions\Completion\RecordTranscriptRequest;
use App\Actions\Completion\ReplaceTranscript;
use App\Actions\Completion\TranscriptLifecycleProjection;
use App\Actions\Completion\VoidTranscript;
use App\Actions\StudentLifecycle\CreateHold;
use App\Actions\StudentLifecycle\ResolveHold;
use App\Filament\Pages\CompletionAndTor;
use App\Filament\Resources\TranscriptRequests\Pages\ViewTranscriptRequest;
use App\Filament\Resources\TranscriptRequests\TranscriptRequestResource;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\DegreeConferral;
use App\Models\Enrollment;
use App\Models\GraduationApplication;
use App\Models\Hold;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HumanCenteredCompletionAndTorAcceptanceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2028-07-15 10:00:00', 'Asia/Manila'));

        $this->assertSame(config('database.test_database'), DB::connection()->getDatabaseName());
        foreach (['student', User::StaffRoleRegistrar, User::StaffRoleAccounting, User::StaffRoleAcademicHead, User::StaffRoleFaculty, User::StaffRoleSystemSuperAdmin, 'admin'] as $role) {
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

        $this->assertSame(CompletionReadinessProjection::ReadyForConferral, $projection['state']);
        $this->assertSame([], $projection['blockers']);

        $this->assertNull($projection['application']);
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
    public function schema_hardening_migration_refuses_rollback_when_contextual_records_exist(): void
    {
        $migration = require database_path('migrations/2026_08_30_063407_make_graduation_application_term_contextual.php');
        Schema::shouldReceive('table')->never();

        $application = GraduationApplication::factory()->create(['term_id' => null]);
        try {
            $migration->down();
            $this->fail('Expected RuntimeException refusing rollback when graduation applications without term exist.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Cannot restore required completion Terms while contextual applications or lifecycle evidence without a Term exist.', $e->getMessage());
        } finally {
            $application->delete();
        }

        $lifecycleChange = StudentLifecycleChange::factory()->create(['term_id' => null]);
        try {
            $migration->down();
            $this->fail('Expected RuntimeException refusing rollback when student lifecycle changes without term exist.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Cannot restore required completion Terms while contextual applications or lifecycle evidence without a Term exist.', $e->getMessage());
        } finally {
            $lifecycleChange->delete();
        }

        // Existing-data compatibility evidence: records with null term_id and contextual records can coexist and query compatibly
        $contextualApp = GraduationApplication::factory()->create(['term_id' => null]);
        $withTermApp = GraduationApplication::factory()->create(['term_id' => Term::factory()->create()->id]);
        $this->assertNotNull(GraduationApplication::query()->find($contextualApp->id));
        $this->assertNull(GraduationApplication::query()->find($contextualApp->id)->term_id);
        $this->assertNotNull(GraduationApplication::query()->find($withTermApp->id)->term_id);
        $contextualApp->delete();
        $withTermApp->delete();
    }

    #[Test]
    public function completion_queue_search_shows_empty_results_and_recovers(): void
    {
        $registrar = User::factory()->create();
        $registrar->assignRole(User::StaffRoleRegistrar);
        $student = StudentProfile::factory()->create();
        GraduationApplication::factory()->create(['student_profile_id' => $student->id]);

        Livewire::actingAs($registrar)
            ->test(CompletionAndTor::class, ['completionView' => 'graduation'])
            ->assertCanSeeTableRecords([$student])
            ->searchTable('QUERY_NO_MATCH_TALA_9999')
            ->assertCanNotSeeTableRecords([$student])
            ->assertSee('No matching students')
            ->assertSee('Clear the search or adjust the filters to find a student.')
            ->searchTable('')
            ->assertCanSeeTableRecords([$student]);
    }

    #[Test]
    public function completion_notification_ledger_records_only_actionable_blocker_deltas(): void
    {
        $fixture = $this->inProgressFixture();
        app(CompletionReadinessProjection::class)->persist($fixture['student'], $fixture['registrar']);
        $projection = app(CompletionReadinessProjection::class)->forStudent($fixture['student']);
        $this->assertSame(1, OperationalEvent::query()->where('event_type', OperationalEvent::TypeCompletionRequiresActionEmail)->count());
        $this->assertSame(CompletionReadinessProjection::AwaitingResultsOrClearance, $projection['state']);
        $this->assertArrayHasKey('source_ref', $projection['blockers'][0]);
        $this->assertArrayHasKey('source_as_of', $projection['blockers'][0]);
        $this->assertArrayHasKey('consequence', $projection['blockers'][0]);
        $this->assertDatabaseMissing('operational_events', [
            'related_record_type' => GraduationApplication::class,
        ]);

        $hold = app(CreateHold::class)->execute($fixture['student'], [
            'hold_type' => Hold::TypeDocumentary,
            'blocking_level' => Hold::BlockingGraduationEligibility,
            'reason' => 'A required completion document needs review.',
            'student_message' => 'Registrar must verify the required completion document.',
            'resolution_requirement' => 'Submit the named document through the authorized Registrar path.',
        ], $fixture['registrar']);

        $events = OperationalEvent::query()->where('event_type', OperationalEvent::TypeCompletionRequiresActionEmail)->get();
        $this->assertCount(2, $events);
        app(CompletionReadinessProjection::class)->persist($fixture['student'], $fixture['registrar']);
        $this->assertSame(2, OperationalEvent::query()->where('event_type', OperationalEvent::TypeCompletionRequiresActionEmail)->count());

        app(ResolveHold::class)->execute($hold, $fixture['registrar'], 'SYNTH-RESOLUTION-EVIDENCE');
        $this->assertSame(2, OperationalEvent::query()->where('event_type', OperationalEvent::TypeCompletionRequiresActionEmail)->count());

        $event = $events->last();
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
        $conferral = app(RecordDegreeConferral::class)->execute(
            $fixture['student'], $fixture['registrar'], 'Bachelor of Science in Information Technology',
            '2028-06-30', 'SYNTH-CONFERRAL-AUTHORITY',
        );
        $request = app(RecordTranscriptRequest::class)->execute(
            $conferral, $fixture['registrar'], 'EXT-TOR-HUMAN-CENTERED', '2028-07-01',
            'Synthetic Registrar', 'College Registrar', TranscriptRequest::SealPlacementInstruction,
            sealPlacementInstruction: 'Apply the controlled seal in the marked certification area.',
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

    #[Test]
    public function duplicate_and_stale_conferral_attempts_are_prevented(): void
    {
        $fixture = $this->creditedFixture();

        // 1. Initial conferral succeeds
        $conferral1 = app(RecordDegreeConferral::class)->execute(
            $fixture['student'], $fixture['registrar'], 'Bachelor of Science in Information Technology',
            '2028-06-30', 'SYNTH-CONFERRAL-AUTH-001',
        );
        $this->assertSame(1, $conferral1->version);
        $this->assertNotNull($conferral1->active_scope_key);

        // 2. Duplicate exact call is idempotent and returns the same conferral without creating a duplicate record
        $conferralDuplicate = app(RecordDegreeConferral::class)->execute(
            $fixture['student'], $fixture['registrar'], 'Bachelor of Science in Information Technology',
            '2028-06-30', 'SYNTH-CONFERRAL-AUTH-001',
        );
        $this->assertSame($conferral1->id, $conferralDuplicate->id);
        $this->assertSame(1, DegreeConferral::query()->where('student_profile_id', $fixture['student']->id)->count());

        // 3. Duplicate call with conflicting degree throws ValidationException
        try {
            app(RecordDegreeConferral::class)->execute(
                $fixture['student'], $fixture['registrar'], 'Bachelor of Science in Computer Science',
                '2028-06-30', 'SYNTH-CONFERRAL-AUTH-001',
            );
            $this->fail('Conflicting degree conferral must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('conferral', $e->errors());
        }

        // 4. Stale/ineligible student cannot be conferred
        $unreadyFixture = $this->inProgressFixture();
        try {
            app(RecordDegreeConferral::class)->execute(
                $unreadyFixture['student'], $fixture['registrar'], 'Bachelor of Science in Information Technology',
                '2028-06-30', 'SYNTH-CONFERRAL-AUTH-002',
            );
            $this->fail('Ineligible student conferral must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('conferral', $e->errors());
        }
    }

    #[Test]
    public function academic_and_financial_source_changes_after_preview_block_issuance(): void
    {
        $fixture = $this->creditedFixture();
        $conferral = app(RecordDegreeConferral::class)->execute(
            $fixture['student'], $fixture['registrar'], 'Bachelor of Science in Information Technology',
            '2028-06-30', 'SYNTH-CONFERRAL-017',
        );
        $request = app(RecordTranscriptRequest::class)->execute(
            $conferral, $fixture['registrar'], 'EXT-TOR-017', '2028-07-01',
            'College Registrar', 'Registrar', TranscriptRequest::SealPlacementInstruction,
            sealPlacementInstruction: 'Affix dry seal bottom center.',
        );

        // Preview confirmation obtained
        $preview = $this->actingAs($fixture['registrar'])->get(route('transcripts.preview', $request));
        $preview->assertOk();
        $confirmation = (string) $preview->headers->get('X-TALA-Preview-Confirmation');

        $restriction = app(OfficialOutputRestrictionService::class)->record($fixture['student'], $fixture['accounting'], 'A newly recorded TOR restriction');
        app(OfficialOutputRestrictionService::class)->resolve($restriction, $fixture['accounting'], 'Accounting resolved the restriction.');

        // A changed Accounting source requires a fresh preview.

        // Confirmation is invalidated by the Accounting restriction source change
        try {
            app(IssueTranscript::class)->execute($request, $fixture['registrar'], 'AUTH-ISSUE-017', $confirmation);
            $this->fail('Issuance must fail when Accounting restriction sources changed after preview.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('preview', $e->errors());
            $this->assertDatabaseCount('transcript_snapshots', 0);
        }

        // Academic change: Refresh preview for the current Accounting source, then change student academic profile
        $preview2 = $this->actingAs($fixture['registrar'])->get(route('transcripts.preview', $request->fresh()));
        $confirmation2 = (string) $preview2->headers->get('X-TALA-Preview-Confirmation');

        $fixture['student']->update(['last_name' => 'ChangedNameAfterPreview']);
        try {
            app(IssueTranscript::class)->execute($request->fresh(), $fixture['registrar'], 'AUTH-ISSUE-017', $confirmation2);
            $this->fail('Issuance must fail when academic source data changed after preview.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('preview', $e->errors());
            $this->assertDatabaseCount('transcript_snapshots', 0);
        }
    }

    #[Test]
    public function conferral_correction_a_to_b_to_a_preserves_every_version(): void
    {
        $fixture = $this->creditedFixture();

        // Initial conferral A (v1)
        $conferralA1 = app(RecordDegreeConferral::class)->execute(
            $fixture['student'], $fixture['registrar'], 'Bachelor of Science in Information Technology',
            '2028-06-30', 'AUTH-CONF-A1',
        );
        $this->assertSame(1, $conferralA1->version);
        $this->assertNotNull($conferralA1->active_scope_key);

        // Issue a TOR snapshot under conferral A1
        $request = app(RecordTranscriptRequest::class)->execute(
            $conferralA1, $fixture['registrar'], 'EXT-TOR-025', '2028-07-01',
            'College Registrar', 'Registrar', TranscriptRequest::SealPlacementInstruction,
            sealPlacementInstruction: 'Affix dry seal.',
        );
        $preview1 = $this->actingAs($fixture['registrar'])->get(route('transcripts.preview', $request));
        $confirmation1 = (string) $preview1->headers->get('X-TALA-Preview-Confirmation');
        $snapshot1 = app(IssueTranscript::class)->execute($request, $fixture['registrar'], 'AUTH-ISS-025', $confirmation1);
        $this->assertSame(TranscriptSnapshot::StatusIssued, $snapshot1->status);

        // Correction to B (v2)
        $conferralB = app(CorrectDegreeConferral::class)->execute(
            $conferralA1, $fixture['registrar'], 'Bachelor of Science in Computer Science',
            '2028-06-30', 'AUTH-CONF-B', 'Curriculum alignment correction.',
        );
        $this->assertSame(2, $conferralB->version);
        $this->assertSame($conferralA1->id, $conferralB->supersedes_conferral_id);
        $this->assertNull($conferralA1->fresh()->active_scope_key);
        $this->assertNotNull($conferralB->fresh()->active_scope_key);

        // Assert initial TOR snapshot is preserved as Superseded upon conferral correction
        $this->assertSame(TranscriptIssuanceEvent::TypeSuperseded, app(TranscriptLifecycleProjection::class)->statusForRequest($request->fresh()));
        $this->assertDatabaseHas('transcript_issuance_events', [
            'transcript_snapshot_id' => $snapshot1->id,
            'type' => TranscriptIssuanceEvent::TypeSuperseded,
        ]);

        // Correction back to A (v3)
        $conferralA2 = app(CorrectDegreeConferral::class)->execute(
            $conferralB, $fixture['registrar'], 'Bachelor of Science in Information Technology',
            '2028-06-30', 'AUTH-CONF-A2', 'Revert to original program conferral.',
        );
        $this->assertSame(3, $conferralA2->version);
        $this->assertSame($conferralB->id, $conferralA2->supersedes_conferral_id);
        $this->assertNull($conferralB->fresh()->active_scope_key);
        $this->assertNotNull($conferralA2->fresh()->active_scope_key);

        // Assert all 3 versions are preserved in degree_conferrals
        $all = DegreeConferral::query()->where('student_profile_id', $fixture['student']->id)->orderBy('version')->get();
        $this->assertCount(3, $all);
        $this->assertSame('Bachelor of Science in Information Technology', $all[0]->degree_name);
        $this->assertSame('Bachelor of Science in Computer Science', $all[1]->degree_name);
        $this->assertSame('Bachelor of Science in Information Technology', $all[2]->degree_name);
        $this->assertNull($all[0]->supersedes_conferral_id);
        $this->assertSame($all[0]->id, $all[1]->supersedes_conferral_id);
        $this->assertSame($all[1]->id, $all[2]->supersedes_conferral_id);

        // Assert earlier transcript snapshot remains preserved and not overwritten
        $snapshotRecord = TranscriptSnapshot::query()->findOrFail($snapshot1->id);
        $this->assertSame($conferralA1->id, $snapshotRecord->degree_conferral_id);
        $this->assertSame(1, $snapshotRecord->version);
        $this->assertSame($snapshot1->reference, $snapshotRecord->reference);

        // Idempotent replay on predecessor returns successor
        $replayed = app(CorrectDegreeConferral::class)->execute(
            $conferralA1, $fixture['registrar'], 'Bachelor of Science in Computer Science',
            '2028-06-30', 'AUTH-CONF-B', 'Curriculum alignment correction.',
        );
        $this->assertSame($conferralB->id, $replayed->id);
    }

    #[Test]
    public function serialized_transcript_operations_prevent_conflicting_active_states(): void
    {
        $fixture = $this->creditedFixture();
        $conferral = app(RecordDegreeConferral::class)->execute(
            $fixture['student'], $fixture['registrar'], 'Bachelor of Science in Information Technology',
            '2028-06-30', 'AUTH-CONF-026',
        );
        $request = app(RecordTranscriptRequest::class)->execute(
            $conferral, $fixture['registrar'], 'EXT-TOR-026', '2028-07-01',
            'College Registrar', 'Registrar', TranscriptRequest::SealPlacementInstruction,
            sealPlacementInstruction: 'Affix dry seal.',
        );

        // 1. Issue initial TOR
        $preview1 = $this->actingAs($fixture['registrar'])->get(route('transcripts.preview', $request));
        $confirmation1 = (string) $preview1->headers->get('X-TALA-Preview-Confirmation');
        $snapshot1 = app(IssueTranscript::class)->execute($request, $fixture['registrar'], 'AUTH-ISS-026', $confirmation1);
        $this->assertSame(TranscriptSnapshot::StatusIssued, $snapshot1->status);

        // Cannot issue second TOR without replacement/void
        try {
            $previewDup = $this->actingAs($fixture['registrar'])->get(route('transcripts.preview', $request));
            $confirmationDup = (string) $previewDup->headers->get('X-TALA-Preview-Confirmation');
            app(IssueTranscript::class)->execute($request, $fixture['registrar'], 'AUTH-ISS-DUP', $confirmationDup);
            $this->fail('Direct duplicate issuance on active TOR must fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('issuance', $e->errors());
        }

        // 2. Void TOR before replacement
        $voidEvent = app(VoidTranscript::class)->execute($snapshot1, $fixture['registrar'], 'AUTH-VOID-026A', 'Voided for typographical correction.');
        $this->assertSame(TranscriptIssuanceEvent::TypeVoided, $voidEvent->type);
        $this->assertNull(app(TranscriptLifecycleProjection::class)->currentSnapshot($request->fresh()));
        $this->assertSame(TranscriptIssuanceEvent::TypeVoided, app(TranscriptLifecycleProjection::class)->statusForRequest($request->fresh()));

        // 3. Replace TOR
        $previewRepl = $this->actingAs($fixture['registrar'])->get(route('transcripts.preview', [
            'transcriptRequest' => $request,
            'operation' => 'replacement',
            'predecessor' => $snapshot1->id,
        ]));
        $confirmationRepl = (string) $previewRepl->headers->get('X-TALA-Preview-Confirmation');
        $snapshot2 = app(ReplaceTranscript::class)->execute(
            $snapshot1, $fixture['registrar'], 'AUTH-REPL-026',
            'Update typographical correction.', $confirmationRepl,
        );

        $this->assertSame($snapshot1->id, $snapshot2->supersedes_snapshot_id);
        $this->assertSame($snapshot2->id, app(TranscriptLifecycleProjection::class)->currentSnapshot($request->fresh())->id);
        $this->assertSame(TranscriptIssuanceEvent::TypeReplacement, app(TranscriptLifecycleProjection::class)->statusForRequest($request->fresh()));

        // 4. Void replacement TOR
        app(VoidTranscript::class)->execute($snapshot2, $fixture['registrar'], 'AUTH-VOID-026B', 'Voided upon request.');
        $this->assertNull(app(TranscriptLifecycleProjection::class)->currentSnapshot($request->fresh()));
        $this->assertSame(TranscriptIssuanceEvent::TypeVoided, app(TranscriptLifecycleProjection::class)->statusForRequest($request->fresh()));
    }

    #[Test]
    public function failed_tor_generation_validation_storage_or_rendering_creates_no_issuance_event(): void
    {
        $fixture = $this->creditedFixture();
        $conferral = app(RecordDegreeConferral::class)->execute(
            $fixture['student'], $fixture['registrar'], 'Bachelor of Science in Information Technology',
            '2028-06-30', 'AUTH-CONF-027',
        );
        $request = app(RecordTranscriptRequest::class)->execute(
            $conferral, $fixture['registrar'], 'EXT-TOR-027', '2028-07-01',
            'College Registrar', 'Registrar', TranscriptRequest::SealPlacementInstruction,
            sealPlacementInstruction: 'Affix dry seal.',
        );

        // 1. Preview confirmation with invalid token fails validation
        try {
            app(IssueTranscript::class)->execute($request, $fixture['registrar'], 'AUTH-ISS-027', 'INVALID-CONFIRMATION-TOKEN');
            $this->fail('Invalid confirmation token must fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('preview', $e->errors());
        }

        // 2. Omitting the reviewed preview fails even though external issuance authority is optional.
        try {
            app(IssueTranscript::class)->execute($request, $fixture['registrar']);
            $this->fail('Issuance still requires an attributable reviewed preview.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('preview', $e->errors());
        }

        // 3. Template rendering exception fails closed
        $preview = $this->actingAs($fixture['registrar'])->get(route('transcripts.preview', $request));
        $validConfirmation = (string) $preview->headers->get('X-TALA-Preview-Confirmation');

        $failView = true;
        view()->composer('outputs.tala-standard-tor', function () use (&$failView): void {
            if ($failView) {
                throw new \RuntimeException('Simulated rendering failure during transcript generation.');
            }
        });

        try {
            app(IssueTranscript::class)->execute($request, $fixture['registrar'], 'AUTH-ISS-027', $validConfirmation);
            $this->fail('Rendering exception must abort issuance.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated rendering failure during transcript generation.', $e->getMessage());
        } finally {
            $failView = false;
        }

        // Fails closed: absolutely no snapshot, issuance event, or issued output access log created
        $this->assertDatabaseCount('transcript_snapshots', 0);
        $this->assertDatabaseCount('transcript_issuance_events', 0);
        $this->assertDatabaseMissing('output_access_logs', ['action' => 'issued']);
        $this->assertDatabaseMissing('output_access_logs', ['source_record_type' => TranscriptSnapshot::class]);

        // 4. Storage and persistence failure simulation
        $failStorage = true;
        TranscriptSnapshot::saving(function () use (&$failStorage): bool {
            if ($failStorage) {
                throw new \RuntimeException('Simulated storage persistence failure during snapshot creation.');
            }

            return true;
        });

        $preview2 = $this->actingAs($fixture['registrar'])->get(route('transcripts.preview', $request));
        $validConfirmation2 = (string) $preview2->headers->get('X-TALA-Preview-Confirmation');

        try {
            app(IssueTranscript::class)->execute($request, $fixture['registrar'], 'AUTH-ISS-027', $validConfirmation2);
            $this->fail('Storage persistence failure must abort issuance.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated storage persistence failure during snapshot creation.', $e->getMessage());
        } finally {
            $failStorage = false;
        }

        // Fails closed: request state, references, and artifacts remain completely unchanged
        $this->assertSame(TranscriptRequest::StateOpen, $request->fresh()->state, 'Request state must remain unchanged after storage failure.');
        $this->assertDatabaseCount('transcript_snapshots', 0);
        $this->assertDatabaseCount('transcript_issuance_events', 0);
        $this->assertDatabaseMissing('output_access_logs', ['action' => 'issued']);
        $this->assertDatabaseMissing('output_access_logs', ['source_record_type' => TranscriptSnapshot::class]);
        $this->assertEmpty(
            collect(Storage::disk('local')->allFiles())->filter(fn (string $f) => str_contains($f, 'TOR-') || str_contains($f, 'transcript')),
            'No partial TOR artifact must exist in storage following persistence failure.'
        );

        // 5. Storage and persistence failure simulation during ReplaceTranscript
        $issuedSnapshot = app(IssueTranscript::class)->execute($request, $fixture['registrar'], 'AUTH-ISS-027', $validConfirmation2);
        app(VoidTranscript::class)->execute($issuedSnapshot, $fixture['registrar'], 'AUTH-VOID-027', 'Voided for replacement test.');

        $previewRepl = $this->actingAs($fixture['registrar'])->get(route('transcripts.preview', [
            'transcriptRequest' => $request,
            'operation' => 'replacement',
            'predecessor' => $issuedSnapshot->id,
        ]));
        $validConfirmationRepl = (string) $previewRepl->headers->get('X-TALA-Preview-Confirmation');

        // Validation failures on replacement
        try {
            app(ReplaceTranscript::class)->execute($issuedSnapshot, $fixture['registrar'], 'AUTH-REPL-027', '   ', $validConfirmationRepl);
            $this->fail('Blank replacement reason must fail validation.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('replacement', $e->errors());
        }

        // Persistence failure during replacement snapshot creation
        $failStorage = true;
        try {
            app(ReplaceTranscript::class)->execute($issuedSnapshot, $fixture['registrar'], 'AUTH-REPL-027', 'Typographical correction.', $validConfirmationRepl);
            $this->fail('Storage persistence failure during replacement must abort.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated storage persistence failure during snapshot creation.', $e->getMessage());
        } finally {
            $failStorage = false;
        }

        // Fails closed on replacement failure: predecessor remains voided, no new snapshot, no conflicting reference, no partial artifact
        $this->assertSame(TranscriptIssuanceEvent::TypeVoided, app(TranscriptLifecycleProjection::class)->statusForSnapshot($issuedSnapshot->fresh()));
        $this->assertNull(app(TranscriptLifecycleProjection::class)->currentSnapshot($request->fresh()));
        $this->assertSame(TranscriptIssuanceEvent::TypeVoided, app(TranscriptLifecycleProjection::class)->statusForRequest($request->fresh()));
        $this->assertDatabaseCount('transcript_snapshots', 1);
        $this->assertDatabaseCount('transcript_issuance_events', 2); // only Issued and Voided
        $this->assertDatabaseMissing('transcript_issuance_events', ['type' => TranscriptIssuanceEvent::TypeReplacement]);
        $this->assertDatabaseMissing('output_access_logs', ['action' => 'replacement']);
        $this->assertFalse(
            TranscriptSnapshot::query()->where('transcript_request_id', $request->id)->where('version', '>', 1)->exists(),
            'No conflicting replacement snapshot reference must exist after persistence failure.'
        );
        $this->assertEmpty(
            collect(Storage::disk('local')->allFiles())->filter(fn (string $f) => str_contains($f, 'TOR-') || str_contains($f, 'transcript')),
            'No partial TOR artifact must exist in storage following replacement persistence failure.'
        );
    }

    #[Test]
    public function registrar_is_the_only_actor_authorized_for_tor_routes(): void
    {
        $fixture = $this->creditedFixture();
        $conferral = app(RecordDegreeConferral::class)->execute(
            $fixture['student'], $fixture['registrar'], 'Bachelor of Science in Information Technology',
            '2028-06-30', 'AUTH-CONF-033',
        );
        $request = app(RecordTranscriptRequest::class)->execute(
            $conferral, $fixture['registrar'], 'EXT-TOR-033', '2028-07-01',
            'College Registrar', 'Registrar', TranscriptRequest::SealPlacementInstruction,
            sealPlacementInstruction: 'Affix dry seal.',
        );

        $previewResponse = $this->actingAs($fixture['registrar'])->get(route('transcripts.preview', $request));
        $previewResponse->assertOk();
        $confirmation = (string) $previewResponse->headers->get('X-TALA-Preview-Confirmation');
        $snapshot = app(IssueTranscript::class)->execute($request, $fixture['registrar'], 'AUTH-ISS-033', $confirmation);

        // Registrar can access both
        $this->actingAs($fixture['registrar'])->get(route('transcripts.preview', $request))->assertOk();
        $this->actingAs($fixture['registrar'])->get(route('transcript-snapshots.show', $snapshot))->assertOk();

        // 1. Unauthenticated requests (guests) are denied across preview, output, history, and consequential entry points
        auth()->logout();
        $this->get(route('transcripts.preview', $request))->assertRedirect();
        $this->get(route('transcript-snapshots.show', $snapshot))->assertRedirect();
        $this->getJson(route('transcripts.preview', $request))->assertUnauthorized();
        $this->getJson(route('transcript-snapshots.show', $snapshot))->assertUnauthorized();
        $this->get(TranscriptRequestResource::getUrl('index'))->assertRedirect();
        $this->get(TranscriptRequestResource::getUrl('view', ['record' => $request]))->assertRedirect();

        try {
            app(IssueTranscript::class)->execute($request, new User, 'AUTH-GUEST-DENIED');
            $this->fail('Unauthenticated guest must be denied IssueTranscript.');
        } catch (AuthorizationException) {
            // Expected denial
        }
        try {
            app(VoidTranscript::class)->execute($snapshot, new User, 'AUTH-GUEST-DENIED', 'Reason');
            $this->fail('Unauthenticated guest must be denied VoidTranscript.');
        } catch (AuthorizationException) {
            // Expected denial
        }
        try {
            app(ReplaceTranscript::class)->execute($snapshot, new User, 'AUTH-GUEST-DENIED', 'Reason');
            $this->fail('Unauthenticated guest must be denied ReplaceTranscript.');
        } catch (AuthorizationException) {
            // Expected denial
        }

        // 2. Unrelated user (authenticated without roles)
        $unrelatedUser = User::factory()->create();

        // 3. Unauthorized roles receive 403 and denial is logged; denied at every entry point including consequential actions and history
        $unauthorizedUsers = [
            'student' => $fixture['student']->user,
            'accounting' => $fixture['accounting'],
            'faculty' => $this->staff(User::StaffRoleFaculty),
            'academic_head' => $this->staff(User::StaffRoleAcademicHead),
            'admin' => $this->staff(User::StaffRoleSystemSuperAdmin),
            'unrelated' => $unrelatedUser,
        ];

        foreach ($unauthorizedUsers as $roleName => $user) {
            $this->actingAs($user)->get(route('transcripts.preview', $request))->assertForbidden();
            $this->actingAs($user)->get(route('transcript-snapshots.show', $snapshot))->assertForbidden();
            $this->assertDatabaseHas('output_access_logs', [
                'source_record_type' => TranscriptRequest::class,
                'source_record_id' => $request->id,
                'actor_user_id' => $user->id,
                'action' => 'denied',
                'status' => 'denied',
            ]);
            $this->assertDatabaseHas('output_access_logs', [
                'source_record_type' => TranscriptSnapshot::class,
                'source_record_id' => $snapshot->id,
                'actor_user_id' => $user->id,
                'action' => 'denied',
                'status' => 'denied',
            ]);

            // Non-accounting unauthorized users cannot access TOR history / resource entry points
            if ($roleName !== 'accounting') {
                $this->assertFalse(TranscriptRequestResource::canAccess());
                $indexResponse = $this->get(TranscriptRequestResource::getUrl('index'));
                $this->assertTrue(in_array($indexResponse->getStatusCode(), [403, 302], true), "Actor {$roleName} must be denied index.");
                $viewResponse = $this->get(TranscriptRequestResource::getUrl('view', ['record' => $request]));
                $this->assertTrue(in_array($viewResponse->getStatusCode(), [403, 302], true), "Actor {$roleName} must be denied view.");
            }

            // Denied at consequential actions: issue, void, replace
            try {
                app(IssueTranscript::class)->execute($request, $user, 'AUTH-TEST-DENIED');
                $this->fail("Actor {$roleName} must be denied execution of IssueTranscript.");
            } catch (AuthorizationException) {
                // Expected denial
            }

            try {
                app(VoidTranscript::class)->execute($snapshot, $user, 'AUTH-TEST-DENIED', 'Reason');
                $this->fail("Actor {$roleName} must be denied execution of VoidTranscript.");
            } catch (AuthorizationException) {
                // Expected denial
            }

            try {
                app(ReplaceTranscript::class)->execute($snapshot, $user, 'AUTH-TEST-DENIED', 'Reason');
                $this->fail("Actor {$roleName} must be denied execution of ReplaceTranscript.");
            } catch (AuthorizationException) {
                // Expected denial
            }
        }

        // 4. Consequential Livewire actions on ViewTranscriptRequest: Accounting can access its restriction actions, while TOR output/lifecycle actions remain denied
        $this->actingAs($fixture['accounting']);
        $this->assertTrue(TranscriptRequestResource::canAccess());
        Livewire::actingAs($fixture['accounting'])
            ->test(ViewTranscriptRequest::class, ['record' => $request->id])
            ->assertActionHidden('preview')
            ->assertActionHidden('previewReplacement')
            ->assertActionHidden('issue')
            ->assertActionHidden('voidLatest')
            ->assertActionHidden('replaceLatest')
            ->assertActionVisible('recordRestriction');

        // Registrar has TOR actions but cannot record Accounting restrictions
        $this->actingAs($fixture['registrar']);
        $this->assertTrue(TranscriptRequestResource::canAccess());
        Livewire::actingAs($fixture['registrar'])
            ->test(CompletionAndTor::class, ['torRequestId' => $request->id])
            ->assertActionHidden('recordRestriction');
    }

    #[Test]
    public function registrar_completion_and_tor_table_filters_ordering_and_actions(): void
    {
        $fixture = $this->creditedFixture();

        // Registrar has full access and header actions
        $this->actingAs($fixture['registrar']);
        $livewire = Livewire::actingAs($fixture['registrar'])
            ->test(CompletionAndTor::class, ['completionView' => 'graduation'])
            ->assertCanSeeTableRecords([$fixture['student']])
            ->assertTableActionVisible('selectGraduation', $fixture['student'])
            ->call('selectGraduationStudent', $fixture['student']->id)
            ->assertActionVisible('recordGraduation')
            ->assertActionHidden('recordTorRequest')
            ->assertSee('Graduation review');

        // Test filtering by readiness
        $livewire->filterTable('graduation', 'recorded')
            ->assertCanNotSeeTableRecords([$fixture['student']])
            ->filterTable('graduation', null)
            ->assertCanSeeTableRecords([$fixture['student']]);

        // Academic Head can access table but does not have mutating actions
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $this->actingAs($academicHead);
        $this->assertTrue(CompletionAndTor::canAccess());
        Livewire::actingAs($academicHead)
            ->test(CompletionAndTor::class)
            ->assertTableActionVisible('selectGraduation', $fixture['student'])
            ->assertActionHidden('recordGraduation')
            ->assertActionDoesNotExist('recordTorRequest')
            ->assertActionDoesNotExist('correctConferral')
            ->assertActionDoesNotExist('correctApplication');

        // Student and Accounting cannot access page
        $this->actingAs($fixture['student']->user);
        $this->assertFalse(CompletionAndTor::canAccess());
        $this->actingAs($fixture['accounting']);
        $this->assertFalse(CompletionAndTor::canAccess());
    }

    #[Test]
    public function completion_and_tor_queries_are_bounded_and_paginated(): void
    {
        $fixture = $this->creditedFixture();
        $unappliedStudent = StudentProfile::factory()->create();
        $testable = Livewire::actingAs($fixture['registrar'])->test(CompletionAndTor::class, ['completionView' => 'graduation', 'graduationStudentId' => $fixture['student']->id])
            ->assertSuccessful()->assertCanSeeTableRecords([$fixture['student'], $unappliedStudent]);
        $table = $testable->instance()->getTable();
        $this->assertTrue($table->isPaginated());
        $this->assertContains('program', array_keys($table->getQuery()->getEagerLoads()));
        $this->assertContains('curriculumVersion', array_keys($table->getQuery()->getEagerLoads()));
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($fixture['registrar'])->test(CompletionAndTor::class, ['completionView' => 'graduation', 'graduationStudentId' => $fixture['student']->id]);
        $queriesBefore = count(DB::getQueryLog());
        $this->creditedFixture();
        DB::flushQueryLog();
        Livewire::actingAs($fixture['registrar'])->test(CompletionAndTor::class, ['completionView' => 'graduation', 'graduationStudentId' => $fixture['student']->id]);
        $queriesAfter = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($queriesBefore, $queriesAfter, 'Showing another Student must not repeat the complete readiness evaluation per row.');
        StudentProfile::factory()->count(55)->sequence(fn ($sequence): array => [
            'program_id' => $fixture['student']->program_id,
            'curriculum_version_id' => $fixture['student']->curriculum_version_id,
            'email' => sprintf('scaled.student.%04d@example.test', $sequence->index),
            'student_number' => sprintf('SCALED-%04d', $sequence->index),
            'last_name' => sprintf('StudentScaled%04d', $sequence->index),
        ])->create();
        $page = new CompletionAndTor;
        $this->assertCount(50, $page->studentOptions());
        $searched = $page->studentOptions('SCALED-0025');
        $this->assertCount(1, $searched);
        $this->assertStringContainsString('SCALED-0025', reset($searched));
        $scaled = Livewire::actingAs($fixture['registrar'])->test(CompletionAndTor::class, ['completionView' => 'graduation', 'graduationStudentId' => $fixture['student']->id])->assertSuccessful();
        $records = $scaled->instance()->getTable()->getRecords();
        $this->assertCount(10, $records);
        $this->assertGreaterThanOrEqual(57, $records->total());
        $scaled->call('gotoGraduationPage', 2)->assertSet('graduationStudentId', null)->assertSee('Page 2 of');
        $this->assertSame(2, $scaled->instance()->getTableRecords()->currentPage());
    }

    #[Test]
    public function replacement_owned_cleanup_preserves_historical_snapshots_and_reproducibility(): void
    {
        $fixture = $this->creditedFixture();
        $conferral = app(RecordDegreeConferral::class)->execute(
            $fixture['student'], $fixture['registrar'], 'Bachelor of Science in Information Technology',
            '2028-06-30', 'AUTH-CONF-047',
        );
        $request = app(RecordTranscriptRequest::class)->execute(
            $conferral, $fixture['registrar'], 'EXT-TOR-047', '2028-07-01',
            'College Registrar', 'Registrar', TranscriptRequest::SealPlacementInstruction,
            sealPlacementInstruction: 'Affix dry seal.',
        );

        $preview1 = $this->actingAs($fixture['registrar'])->get(route('transcripts.preview', $request));
        $confirmation1 = (string) $preview1->headers->get('X-TALA-Preview-Confirmation');
        $snapshot1 = app(IssueTranscript::class)->execute($request, $fixture['registrar'], 'AUTH-ISS-047', $confirmation1);

        // Void snapshot1 before replacement
        app(VoidTranscript::class)->execute($snapshot1, $fixture['registrar'], 'AUTH-VOID-047', 'Typographical error requires replacement.');

        // Replace snapshot1 with snapshot2
        $previewRepl = $this->actingAs($fixture['registrar'])->get(route('transcripts.preview', [
            'transcriptRequest' => $request,
            'operation' => 'replacement',
            'predecessor' => $snapshot1->id,
        ]));
        $confirmationRepl = (string) $previewRepl->headers->get('X-TALA-Preview-Confirmation');
        $snapshot2 = app(ReplaceTranscript::class)->execute(
            $snapshot1, $fixture['registrar'], 'AUTH-REPL-047',
            'Corrected typographical note.', $confirmationRepl,
        );

        // Historical snapshot is preserved in database
        $this->assertDatabaseHas('transcript_snapshots', ['id' => $snapshot1->id]);
        $this->assertDatabaseHas('transcript_snapshots', ['id' => $snapshot2->id, 'supersedes_snapshot_id' => $snapshot1->id]);

        // Historical snapshot remains renderable and auditable
        $this->actingAs($fixture['registrar'])->get(route('transcript-snapshots.show', $snapshot1))->assertOk();
        $this->actingAs($fixture['registrar'])->get(route('transcript-snapshots.show', $snapshot2))->assertOk();

        // Lifecycle projection preserves all events
        $events = TranscriptIssuanceEvent::query()->where('transcript_request_id', $request->id)->orderBy('id')->get();
        $this->assertCount(3, $events);
        $this->assertSame(TranscriptIssuanceEvent::TypeIssued, $events[0]->type);
        $this->assertSame(TranscriptIssuanceEvent::TypeVoided, $events[1]->type);
        $this->assertSame(TranscriptIssuanceEvent::TypeReplacement, $events[2]->type);
        $this->assertSame($snapshot1->id, $snapshot2->supersedes_snapshot_id);
        $this->assertSame($events[1]->id, $events[2]->predecessor_event_id);
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
