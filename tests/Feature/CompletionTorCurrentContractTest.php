<?php

namespace Tests\Feature;

use App\Actions\Completion\IssueTranscript;
use App\Actions\Completion\OfficialOutputRestrictionService;
use App\Actions\Completion\RecordTranscriptRequest;
use App\Actions\Completion\ReplaceTranscript;
use App\Actions\Completion\SupersedeTranscriptSnapshots;
use App\Actions\Completion\TranscriptLifecycleProjection;
use App\Actions\Completion\TranscriptPreview;
use App\Actions\Completion\TranscriptSignatory;
use App\Filament\Pages\CompletionAndTor;
use App\Filament\Resources\TranscriptRequests\Pages\ListTranscriptRequests;
use App\Filament\Resources\TranscriptRequests\Pages\ViewTranscriptRequest;
use App\Filament\Resources\TranscriptRequests\TranscriptRequestResource;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\DegreeConferral;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\OfficialOutputRestriction;
use App\Models\Section;
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
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompletionTorCurrentContractTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame(config('database.test_database'), DB::connection()->getDatabaseName());
        $this->travelTo(Carbon::parse('2028-07-15 10:00:00', 'Asia/Manila'));
        config(['institution.name' => 'Synthetic Servitech Campus', 'institution.address' => 'Synthetic campus, Philippines', 'institution.public.support_phone' => '0947 737 9208']);
    }

    #[Test]
    public function graduation_history_pages_recorded_versions_and_resets_for_another_student(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $student = StudentProfile::factory()->create();
        $other = StudentProfile::factory()->create();
        $first = DegreeConferral::factory()->create(['student_profile_id' => $student->id]);
        foreach (range(2, 11) as $version) {
            $copy = $first->replicate();
            $copy->version = $version;
            $copy->save();
        }
        $page = Livewire::actingAs($registrar)->test(CompletionAndTor::class)
            ->call('selectCompletionView', 'graduation')->call('selectGraduationStudent', $student->id);
        $history = $page->instance()->graduationDetail()['history'];
        $this->assertSame(11, $history->total());
        $this->assertCount(10, $history);
        $this->assertSame(11, $history->first()->version);
        $page->call('gotoGraduationHistoryPage', 2);
        $this->assertSame(1, $page->instance()->graduationDetail()['history']->first()->version);
        $page->call('gotoGraduationHistoryPage', 999);
        $this->assertSame(2, $page->instance()->graduationDetail()['history']->currentPage());
        $page->call('selectGraduationStudent', $other->id)->assertSee('No graduation has been recorded for this student.');
        $this->assertSame(1, $page->instance()->graduationDetail()['history']->currentPage());
        $this->assertSame(0, $page->instance()->graduationDetail()['history']->total());
    }

    #[Test]
    public function request_history_pages_events_and_intake_without_leaking_other_requests(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $request = app(RecordTranscriptRequest::class)->execute(StudentProfile::factory()->create(), $registrar, 'HISTORY-PAGED', '2028-07-01');
        $other = app(RecordTranscriptRequest::class)->execute(StudentProfile::factory()->create(), $registrar, 'HISTORY-OTHER', '2028-07-02');
        $events = TranscriptIssuanceEvent::factory()->count(11)->create([
            'transcript_request_id' => $request->id,
            'transcript_snapshot_id' => null,
            'recorded_by' => $registrar->id,
        ]);
        TranscriptIssuanceEvent::factory()->create([
            'transcript_request_id' => $other->id,
            'transcript_snapshot_id' => null,
            'recorded_by' => $registrar->id,
            'reason' => 'Another request private history',
        ]);
        $page = Livewire::actingAs($registrar)->test(CompletionAndTor::class)
            ->call('selectTorRequest', $request->id)
            ->assertDontSee('Another request private history');
        $history = $page->instance()->torHistory;
        $this->assertSame(12, $history->total());
        $this->assertCount(10, $history);
        $this->assertSame($events->last()->id, $history->first()['event']->id);
        $page->call('gotoTorHistoryPage', 2);
        $history = $page->instance()->torHistory;
        $this->assertCount(2, $history);
        $this->assertSame($events->first()->id, $history->first()['event']->id);
        $this->assertNull($history->last()['event']);
        $page->call('gotoTorHistoryPage', 999);
        $this->assertSame(2, $page->instance()->torHistory->currentPage());
        $page->call('selectTorRequest', $other->id);
        $this->assertSame(1, $page->instance()->torHistory->currentPage());
        $this->assertSame(2, $page->instance()->torHistory->total());
    }

    #[Test]
    public function completion_navigation_preserves_role_boundaries_and_rejects_unknown_work(): void
    {
        Livewire::actingAs($this->staff(User::StaffRoleRegistrar))->test(CompletionAndTor::class)
            ->call('selectCompletionView', 'graduation')->assertSet('completionView', 'graduation')
            ->call('selectCompletionView', 'tor')->assertSet('completionView', 'tor');
        Livewire::actingAs($this->staff(User::StaffRoleAcademicHead))->test(CompletionAndTor::class)
            ->assertSet('completionView', 'graduation')->call('selectCompletionView', 'tor')->assertForbidden();
        Livewire::actingAs($this->staff(User::StaffRoleRegistrar))->test(CompletionAndTor::class)
            ->call('selectCompletionView', 'unknown')->assertNotFound();
    }

    #[Test]
    public function school_signatory_is_reused_without_changing_request_clocks(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $first = app(RecordTranscriptRequest::class)->execute(StudentProfile::factory()->create(), $registrar, 'WORKSPACE-FIRST', '2028-07-01');
        $second = app(RecordTranscriptRequest::class)->execute(StudentProfile::factory()->create(), $registrar, 'WORKSPACE-SECOND', '2028-07-02');
        Livewire::actingAs($registrar)->test(CompletionAndTor::class)
            ->assertSuccessful()->assertSee('Set the name printed on the TOR')
            ->callAction('setTorSignatory', data: ['name' => 'Synthetic Registrar', 'title' => 'College Registrar'])
            ->assertHasNoActionErrors()->assertNotified('TOR signatory saved')
            ->call('selectTorRequest', $second->id)->assertSet('torRequestId', $second->id)
            ->assertSee('Synthetic Registrar')->assertDontSee('Seal placement instruction');
        $this->assertNull($first->fresh()->signatory_name);
        $this->assertNull($second->fresh()->signatory_name);
        $this->assertSame('2028-07-31', $first->fresh()->due_on->toDateString());
        $this->assertSame('2028-08-01', $second->fresh()->due_on->toDateString());
        $this->assertSame('Synthetic Registrar', app(TranscriptSignatory::class)->current()['name']);
    }

    #[Test]
    public function signatory_changes_preserve_versions_and_reject_other_roles(): void
    {
        $service = app(TranscriptSignatory::class);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $service->save($registrar, 'First synthetic signatory', 'Registrar');
        $service->save($registrar, 'First synthetic signatory', 'Registrar');
        $service->save($registrar, 'Second synthetic signatory', 'Registrar');
        $this->assertSame(2, $service->current()['source_version']);
        $this->assertDatabaseHas('system_settings', ['key' => $service::SettingKey, 'version' => 1, 'status' => 'Superseded']);
        $this->expectException(AuthorizationException::class);
        $service->save($this->staff(User::StaffRoleAccounting), 'Unpermitted name', 'Registrar');
    }

    #[Test]
    public function intake_uses_a_generated_reference_and_native_dialog_without_document_setup_fields(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $student = StudentProfile::factory()->create();
        $page = Livewire::actingAs($registrar)->test(CompletionAndTor::class)
            ->mountAction('recordTorRequest')->assertActionMounted('recordTorRequest')
            ->assertDontSee('Seal placement instruction')
            ->fillForm(['student_profile_id' => $student->id, 'requested_on' => '2028-07-02'])
            ->callMountedAction()->assertHasNoActionErrors()->assertNotified('TOR request recorded');
        $request = TranscriptRequest::query()->where('student_profile_id', $student->id)->sole();
        $this->assertMatchesRegularExpression('/^TR-2028-[A-Z0-9]{10}$/', $request->external_request_reference);
        $this->assertSame(TranscriptRequest::TemplateServitechV2, $request->template_version);
        $this->assertSame('2028-08-01', $request->due_on->toDateString());
        $page->assertSet('torRequestId', $request->id)->set('torSearch', 'no-matching-request')
            ->assertSee('No matching requests')
            ->assertDontSeeHtml('aria-label="Selected TOR request"')
            ->assertDontSee('Choose a request')
            ->assertDontSeeHtml('servitech-empty-state__art')
            ->call('clearTorFilters')
            ->assertSet('torSearch', '')->assertSet('torScope', '')
            ->assertSeeHtml('aria-label="Selected TOR request"');
    }

    #[Test]
    public function empty_tor_desk_keeps_intake_available_without_an_empty_inspector(): void
    {
        $page = Livewire::actingAs($this->staff(User::StaffRoleRegistrar))->test(CompletionAndTor::class)
            ->assertSee('No TOR requests yet')
            ->assertSeeHtml('images/servitech-empty-state.webp')
            ->assertDontSeeHtml('aria-label="Selected TOR request"')
            ->assertDontSee('Search TOR requests')
            ->assertDontSee('Choose a request');

        $page->mountAction('recordTorRequest')->assertActionMounted('recordTorRequest');
    }

    #[Test]
    public function split_workspace_selection_is_scoped_to_the_current_server_page(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $requests = collect(range(1, 11))->map(fn (int $index) => app(RecordTranscriptRequest::class)->execute(
            StudentProfile::factory()->create(), $registrar, 'PAGE-'.$index, '2028-07-01',
        ));
        Livewire::actingAs($registrar)->test(CompletionAndTor::class)
            ->call('selectTorRequest', $requests->last()->id)->assertStatus(404);
        Livewire::actingAs($registrar)->test(CompletionAndTor::class)
            ->call('gotoTorPage', 2)->call('selectTorRequest', $requests->last()->id)
            ->assertSet('torRequestId', $requests->last()->id)->assertSuccessful();
    }

    #[Test]
    public function registrar_legacy_request_routes_return_to_one_contextual_desk(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $request = app(RecordTranscriptRequest::class)->execute(StudentProfile::factory()->create(), $registrar, 'LEGACY-LINK', '2028-07-01');
        Livewire::actingAs($registrar)->test(ViewTranscriptRequest::class, ['record' => $request->id])
            ->assertRedirect(CompletionAndTor::getUrl(['request' => $request->id, 'find' => 'LEGACY-LINK']));
        Livewire::actingAs($registrar)->test(ListTranscriptRequests::class)->assertRedirect(CompletionAndTor::getUrl());
    }

    #[Test]
    public function accounting_records_and_resolves_actual_restrictions_through_its_native_request_actions(): void
    {
        $student = StudentProfile::factory()->create();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $request = app(RecordTranscriptRequest::class)->execute($student, $registrar, 'EXT-UI-ACCOUNTING', '2028-07-01');
        $page = Livewire::actingAs($accounting)->test(ViewTranscriptRequest::class, ['record' => $request->id])
            ->assertSuccessful()->assertActionVisible('recordRestriction')
            ->assertActionHidden('resolveRestriction')->assertActionHidden('prepare')
            ->assertActionHidden('preview')->assertActionHidden('issue')
            ->assertActionDoesNotExist('recordClearance')
            ->callAction('recordRestriction', data: ['reason' => 'An actual Accounting TOR restriction'])
            ->assertHasNoActionErrors()->assertNotified('TOR restriction recorded');
        $restriction = OfficialOutputRestriction::query()->where('student_profile_id', $student->id)->sole();
        $this->assertSame($accounting->id, $restriction->recorded_by);
        $this->assertNull($restriction->source_reference);
        $this->assertNull($restriction->resolved_at);
        $page->assertActionVisible('resolveRestriction')
            ->callAction('resolveRestriction', data: ['restriction_id' => $restriction->id, 'resolution_reason' => 'Accounting verified that issuance is now allowed.'])
            ->assertHasNoActionErrors()->assertNotified('TOR restriction resolved')
            ->assertActionHidden('resolveRestriction');
        $resolved = $restriction->fresh();
        $this->assertSame($accounting->id, $resolved->resolved_by);
        $this->assertSame('Accounting verified that issuance is now allowed.', $resolved->resolution_reason);
        $this->assertNotNull($resolved->resolved_at);
        $this->assertSame('2028-07-31', $request->fresh()->due_on->toDateString());
        $this->assertSame(0, $request->clearances()->count());

        Livewire::actingAs($accounting)->test(ListTranscriptRequests::class)
            ->assertSuccessful()->assertSee('TOR restrictions')->assertCanSeeTableRecords([$request]);
    }

    #[Test]
    #[DataProvider('rolesWithoutTorResourceAccess')]
    public function head_and_student_cannot_open_the_restricted_tor_request_resource(string $role): void
    {
        $student = StudentProfile::factory()->create();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $request = app(RecordTranscriptRequest::class)->execute($student, $registrar, 'EXT-UI-DENIED', '2028-07-01');
        $actor = $this->staff($role);

        Livewire::actingAs($actor)->test(ViewTranscriptRequest::class, ['record' => $request->id])->assertForbidden();
        $this->assertSame(0, $request->snapshots()->count());
    }

    public static function rolesWithoutTorResourceAccess(): array
    {
        return ['Academic Head' => [User::StaffRoleAcademicHead], 'Student' => ['student']];
    }

    #[Test]
    public function intake_preserves_actual_request_clock_without_graduation_or_issuance_inputs(): void
    {
        $student = StudentProfile::factory()->create();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $request = app(RecordTranscriptRequest::class)->execute($student, $registrar, 'EXT-INTAKE', '2028-07-01');
        $this->assertNull($request->degree_conferral_id);
        $this->assertNull($request->signatory_name);
        $this->assertNull($request->seal_input_type);
        $this->assertSame('2028-07-01', $request->requested_on->toDateString());
        $this->assertSame('2028-07-31', $request->due_on->toDateString());
        $this->assertSame($registrar->id, $request->recorded_by);
        $this->actingAs($registrar)->get(route('transcripts.preview', $request))
            ->assertRedirect(TranscriptRequestResource::getUrl('view', ['record' => $request]));
        $this->assertDatabaseHas('output_access_logs', ['source_record_type' => TranscriptRequest::class, 'source_record_id' => $request->id, 'action' => 'preview_failed', 'status' => 'failed']);
        $this->assertSame('2028-07-31', $request->fresh()->due_on->toDateString());
        $this->assertSame(0, $request->snapshots()->count());
    }

    #[Test]
    public function repeated_intake_is_idempotent_but_reference_cannot_identify_another_student_or_date(): void
    {
        $student = StudentProfile::factory()->create();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $service = app(RecordTranscriptRequest::class);
        $request = $service->execute($student, $registrar, 'EXT-REPEAT', '2028-07-01');
        $this->assertSame($request->id, $service->execute($student, $registrar, ' EXT-REPEAT ', '2028-07-01')->id);
        foreach ([[$student, '2028-07-02'], [StudentProfile::factory()->create(), '2028-07-01']] as [$otherStudent, $date]) {
            try {
                $service->execute($otherStudent, $registrar, 'EXT-REPEAT', $date);
                $this->fail('A reference must not identify a different intake.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('different Student or request date', $exception->getMessage());
            }
        }
        $this->assertSame(1, TranscriptRequest::query()->where('external_request_reference', 'EXT-REPEAT')->count());
        $this->assertSame('2028-07-01', $request->fresh()->requested_on->toDateString());
    }

    #[Test]
    public function future_intake_date_is_rejected_without_creating_a_request(): void
    {
        $student = StudentProfile::factory()->create();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        try {
            app(RecordTranscriptRequest::class)->execute($student, $registrar, 'EXT-FUTURE', '2028-07-16');
            $this->fail('An actual request date cannot be in the future.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('requested_on', $exception->errors());
            $this->assertDatabaseMissing('transcript_requests', ['external_request_reference' => 'EXT-FUTURE']);
        }
    }

    #[Test]
    public function preparing_inputs_preserves_the_request_clock_and_cannot_mutate_an_issued_tor(): void
    {
        $fixture = $this->releasedFixture();
        $request = $this->preparedRequest($fixture);
        $this->assertSame('2028-07-01', $request->requested_on->toDateString());
        $this->assertSame('2028-07-31', $request->due_on->toDateString());
        $this->assertSame('Synthetic Registrar', $request->signatory_name);
        $snapshot = app(IssueTranscript::class)->execute($request, $fixture['registrar'], previewConfirmation: $this->preview($request, $fixture['registrar']));
        try {
            app(RecordTranscriptRequest::class)->prepare($request, $fixture['registrar'], [...$this->signatoryInputs(), 'signatory_name' => 'Another signatory']);
            $this->fail('Issued content must remain immutable.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
            $this->assertSame('Synthetic Registrar', $snapshot->fresh()->content['certification']['signatory_name']);
        }
    }

    #[Test]
    public function non_graduate_tor_preserves_inc_and_issues_without_per_request_clearance_or_typed_authority(): void
    {
        $fixture = $this->releasedFixture('INC');
        $request = $this->preparedRequest($fixture);
        $content = app(TranscriptPreview::class)->forRequest($request);
        $this->assertSame([], $content['conferral']);
        $this->assertSame('INC', collect($content['academic_years'])->flatten(1)->sole()['result']);
        $this->assertSame(0, $request->clearances()->count());
        $response = $this->actingAs($fixture['registrar'])->get(route('transcripts.preview', $request));
        $response->assertOk()->assertSee('INC')->assertDontSee('Completion and conferral');
        $confirmation = (string) $response->headers->get('X-TALA-Preview-Confirmation');
        $snapshot = app(IssueTranscript::class)->execute($request, $fixture['registrar'], previewConfirmation: $confirmation);
        $this->assertNull($snapshot->degree_conferral_id);
        $this->assertSame([], $snapshot->content['conferral']);
        $this->assertSame('INC', collect($snapshot->content['academic_years'])->flatten(1)->sole()['result']);
        $event = $snapshot->events()->where('type', TranscriptIssuanceEvent::TypeIssued)->sole();
        $this->assertNull($event->authority_reference);
        $this->assertSame($fixture['registrar']->id, $event->recorded_by);
        $this->assertNotNull($event->recorded_at);
        $this->assertSame(TranscriptRequest::StateIssued, $request->fresh()->state);
        $this->assertSame($snapshot->id, app(IssueTranscript::class)->execute($request, $fixture['registrar'], previewConfirmation: $confirmation)->id);
        $this->assertSame(1, $request->snapshots()->count());
        Livewire::test(CompletionAndTor::class, ['torRequestId' => $request->id])
            ->assertActionVisible('viewIssued')
            ->assertActionHidden('issue');
    }

    #[Test]
    public function accounting_restriction_blocks_issuance_and_resolution_requires_a_fresh_preview_without_resetting_due_date(): void
    {
        $fixture = $this->releasedFixture();
        $request = $this->preparedRequest($fixture);
        $confirmation = $this->preview($request, $fixture['registrar']);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $service = app(OfficialOutputRestrictionService::class);
        $restriction = $service->record($fixture['student'], $accounting, 'Actual outstanding TOR restriction');
        try {
            app(IssueTranscript::class)->execute($request, $fixture['registrar'], previewConfirmation: $confirmation);
            $this->fail('Active Accounting restriction must block issuance.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Accounting must resolve', $exception->getMessage());
            $this->assertSame(0, $request->snapshots()->count());
            $this->assertSame('2028-07-31', $request->fresh()->due_on->toDateString());
        }
        $resolved = $service->resolve($restriction, $accounting, 'The recorded restriction has been settled.');
        $this->assertSame($accounting->id, $resolved->recorded_by);
        $this->assertSame($accounting->id, $resolved->resolved_by);
        $this->assertNotNull($resolved->resolved_at);
        $this->assertSame('Actual outstanding TOR restriction', $resolved->reason);
        $this->assertSame('The recorded restriction has been settled.', $resolved->resolution_reason);
        $this->assertCount(0, $service->activeFor($fixture['student']));
        try {
            app(IssueTranscript::class)->execute($request, $fixture['registrar'], previewConfirmation: $confirmation);
            $this->fail('Resolving a restriction must not resurrect an older preview.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('sources changed', $exception->getMessage());
            $this->assertSame(0, $request->snapshots()->count());
        }
        $renewed = $this->preview($request->fresh(), $fixture['registrar']);
        $this->assertInstanceOf(TranscriptSnapshot::class, app(IssueTranscript::class)->execute($request->fresh(), $fixture['registrar'], previewConfirmation: $renewed));
    }

    #[Test]
    public function accounting_can_record_a_restriction_before_any_request_without_duplicate_active_facts(): void
    {
        $student = StudentProfile::factory()->create();
        $accounting = $this->staff(User::StaffRoleAccounting);
        $service = app(OfficialOutputRestrictionService::class);
        $restriction = $service->record($student, $accounting, 'Actual TOR restriction', 'FIN-REF');
        $this->assertSame($restriction->id, $service->record($student, $accounting, 'Actual TOR restriction', 'FIN-REF')->id);
        $this->assertSame(OfficialOutputRestriction::OutputTor, $restriction->output_type);
        $this->assertSame(0, TranscriptRequest::query()->where('student_profile_id', $student->id)->count());
        $this->assertDatabaseHas('official_output_restrictions', ['id' => $restriction->id, 'source_reference' => 'FIN-REF', 'resolved_at' => null]);
    }

    #[Test]
    #[DataProvider('nonRegistrarRoles')]
    public function other_roles_cannot_intake_or_prepare_requests(string $role): void
    {
        $student = StudentProfile::factory()->create();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $request = app(RecordTranscriptRequest::class)->execute($student, $registrar, 'EXT-PERMISSION', '2028-07-01');
        $actor = $this->staff($role);
        try {
            app(RecordTranscriptRequest::class)->execute($student, $actor, 'EXT-DENIED', '2028-07-01');
            $this->fail('Only Registrar may record intake.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('transcript_requests', ['external_request_reference' => 'EXT-DENIED']);
        }
        $this->expectException(AuthorizationException::class);
        app(RecordTranscriptRequest::class)->prepare($request, $actor, $this->signatoryInputs());
    }

    #[Test]
    #[DataProvider('nonAccountingRoles')]
    public function other_roles_cannot_record_or_resolve_accounting_restrictions(string $role): void
    {
        $student = StudentProfile::factory()->create();
        $accounting = $this->staff(User::StaffRoleAccounting);
        $actor = $this->staff($role);
        $service = app(OfficialOutputRestrictionService::class);
        $restriction = $service->record($student, $accounting, 'Actual restriction');
        try {
            $service->record($student, $actor, 'Unauthorized restriction');
            $this->fail('Only Accounting may record its restriction.');
        } catch (AuthorizationException) {
            $this->assertCount(1, $service->activeFor($student));
        }
        $this->expectException(AuthorizationException::class);
        $service->resolve($restriction, $actor, 'Unauthorized resolution');
    }

    #[Test]
    public function inactive_registrar_cannot_issue_an_already_previewed_tor(): void
    {
        $fixture = $this->releasedFixture();
        $request = $this->preparedRequest($fixture);
        $confirmation = $this->preview($request, $fixture['registrar']);
        $fixture['registrar']->update(['status' => User::StatusDisabled]);
        $this->expectException(AuthorizationException::class);
        app(IssueTranscript::class)->execute($request, $fixture['registrar']->fresh(), previewConfirmation: $confirmation);
    }

    public static function nonRegistrarRoles(): array
    {
        return ['Accounting' => [User::StaffRoleAccounting], 'Faculty' => [User::StaffRoleFaculty], 'Head' => [User::StaffRoleAcademicHead], 'Student' => ['student']];
    }

    public static function nonAccountingRoles(): array
    {
        return ['Registrar' => [User::StaffRoleRegistrar], 'Faculty' => [User::StaffRoleFaculty], 'Head' => [User::StaffRoleAcademicHead], 'Student' => ['student']];
    }

    /** @return array{signatory_name: string, signatory_title: string, seal_placement_instruction: string} */
    #[Test]
    public function shared_signatory_preview_requires_fresh_review_and_retains_issued_certification(): void
    {
        $fixture = $this->releasedFixture('INC');
        $fixture['student']->update(['prior_identifier' => 'PRIVATE-PRIOR-IDENTIFIER']);
        $request = app(RecordTranscriptRequest::class)->execute($fixture['student'], $fixture['registrar'], 'SHARED-SIGNATORY', '2028-07-01');
        $request->update(['template_version' => TranscriptRequest::TemplateServitechV2]);
        $signatory = app(TranscriptSignatory::class);
        $signatory->save($fixture['registrar'], 'First synthetic signatory', 'Registrar');
        $response = $this->actingAs($fixture['registrar'])->get(route('transcripts.preview', $request));
        $response->assertOk()->assertSee('Continue to issuance')->assertSee('Transcript document')->assertSee('Institutional seal')->assertDontSee('PRIVATE-PRIOR-IDENTIFIER');
        $confirmation = (string) $response->headers->get('X-TALA-Preview-Confirmation');
        $signatory->save($fixture['registrar'], 'Second synthetic signatory', 'Registrar');
        try {
            app(IssueTranscript::class)->execute($request, $fixture['registrar'], previewConfirmation: $confirmation);
            $this->fail('A changed signatory requires reviewing the updated document.');
        } catch (ValidationException) {
            $this->assertSame(0, $request->snapshots()->count());
        }
        $freshConfirmation = $this->preview($request->fresh(), $fixture['registrar']);
        Livewire::actingAs($fixture['registrar'])->test(CompletionAndTor::class, ['torRequestId' => $request->id, 'torConfirmation' => 'issue'])
            ->call('confirmReviewedTor')->assertActionMounted('issue')->unmountAction();
        $snapshot = app(IssueTranscript::class)->execute($request->fresh(), $fixture['registrar'], previewConfirmation: $freshConfirmation);
        $this->assertSame('Second synthetic signatory', $snapshot->content['certification']['signatory_name']);
        $this->assertNull($request->fresh()->seal_input_type);
        $signatory->save($fixture['registrar'], 'Third synthetic signatory', 'Registrar');
        $this->actingAs($fixture['registrar'])->get(route('transcript-snapshots.show', $snapshot))
            ->assertOk()->assertSee('Second synthetic signatory')->assertDontSee('Third synthetic signatory')->assertDontSee('Continue to issuance');
        $this->assertSame('2028-07-31', $request->fresh()->due_on->toDateString());
    }

    #[Test]
    public function void_and_replacement_keep_accountability_without_a_typed_paper_reference(): void
    {
        $fixture = $this->releasedFixture();
        $request = $this->preparedRequest($fixture);
        $request->update(['template_version' => TranscriptRequest::TemplateServitechV2]);
        $confirmation = $this->preview($request, $fixture['registrar']);
        $snapshot = app(IssueTranscript::class)->execute($request, $fixture['registrar'], previewConfirmation: $confirmation);
        $page = Livewire::actingAs($fixture['registrar'])->test(CompletionAndTor::class, ['torRequestId' => $request->id])
            ->callAction('voidLatest', data: ['reason' => 'Synthetic issuance mistake.'])
            ->assertHasNoActionErrors()->assertNotified('TOR void recorded')->assertActionDisabled('replaceLatest');
        $void = $snapshot->events()->where('type', TranscriptIssuanceEvent::TypeVoided)->sole();
        $this->assertNull($void->authority_reference);
        $this->assertSame($fixture['registrar']->id, $void->recorded_by);
        $response = $this->actingAs($fixture['registrar'])->get(route('transcripts.preview', [
            'transcriptRequest' => $request, 'operation' => 'replacement', 'predecessor' => $snapshot->id,
        ]));
        $response->assertOk()->assertSee('Continue to replacement');
        $replacement = app(ReplaceTranscript::class)->execute($snapshot, $fixture['registrar'], null, 'Synthetic replacement after review.', $response->headers->get('X-TALA-Preview-Confirmation'));
        $this->assertSame(2, $replacement->version);
        $this->assertSame(2, $request->snapshots()->count());
        $this->assertNull($replacement->events()->where('type', TranscriptIssuanceEvent::TypeReplacement)->sole()->authority_reference);
        $this->actingAs($fixture['registrar'])->get(route('transcript-snapshots.show', $snapshot))->assertOk()->assertSee('Voided');
        $this->actingAs($fixture['registrar'])->get(route('transcript-snapshots.show', $replacement))->assertOk()->assertSee('Replacement');
    }

    #[Test]
    public function superseded_document_has_a_guarded_updated_issuance_path_in_the_same_request(): void
    {
        $fixture = $this->releasedFixture();
        $request = $this->preparedRequest($fixture);
        $request->update(['template_version' => TranscriptRequest::TemplateServitechV2]);
        $firstConfirmation = $this->preview($request, $fixture['registrar']);
        $first = app(IssueTranscript::class)->execute($request, $fixture['registrar'], previewConfirmation: $firstConfirmation);
        app(SupersedeTranscriptSnapshots::class)->execute($fixture['student'], $fixture['registrar'], null, 'Synthetic authorized source correction.');
        Livewire::actingAs($fixture['registrar'])->test(CompletionAndTor::class, ['torRequestId' => $request->id])
            ->assertActionVisible('reviewTor')->assertActionVisible('issue')->assertActionDisabled('issue')
            ->set('torConfirmation', 'issue')->call('confirmReviewedTor')->assertNotified('Review the TOR first');
        $this->preview($request->fresh(), $fixture['registrar']);
        Livewire::actingAs($fixture['registrar'])->test(CompletionAndTor::class, ['torRequestId' => $request->id])
            ->assertActionEnabled('issue')->callAction('issue')->assertHasNoActionErrors();
        $this->assertSame(2, $request->snapshots()->count());
        $this->assertSame('Superseded', app(TranscriptLifecycleProjection::class)->statusForSnapshot($first->fresh()));
        $this->assertSame('2028-07-31', $request->fresh()->due_on->toDateString());
    }

    private function signatoryInputs(): array
    {
        return ['signatory_name' => 'Synthetic Registrar', 'signatory_title' => 'College Registrar', 'seal_placement_instruction' => 'Apply the school seal in the marked certification area.'];
    }

    /** @param array{student: StudentProfile, registrar: User} $fixture */
    private function preparedRequest(array $fixture): TranscriptRequest
    {
        $service = app(RecordTranscriptRequest::class);
        $request = $service->execute($fixture['student'], $fixture['registrar'], 'EXT-TOR-CURRENT', '2028-07-01');

        return $service->prepare($request, $fixture['registrar'], $this->signatoryInputs());
    }

    private function preview(TranscriptRequest $request, User $actor): string
    {
        $response = $this->actingAs($actor)->get(route('transcripts.preview', $request));
        $response->assertOk();
        $confirmation = (string) $response->headers->get('X-TALA-Preview-Confirmation');
        $this->assertNotEmpty($confirmation);

        return $confirmation;
    }

    /** @return array{student: StudentProfile, registrar: User} */
    private function releasedFixture(string $result = '2.00'): array
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $student = StudentProfile::factory()->create();
        $specification = CourseSpecification::factory()->create(['academic_classification' => CourseSpecification::AcademicClassificationOrdinary]);
        $entry = CurriculumEntry::factory()->create(['curriculum_version_id' => $student->curriculum_version_id, 'course_specification_id' => $specification->id]);
        $term = Term::factory()->create();
        $offering = TermOffering::factory()->create(['term_id' => $term->id, 'curriculum_entry_id' => $entry->id]);
        $section = Section::factory()->create(['term_offering_id' => $offering->id]);
        $enrollment = Enrollment::factory()->create(['student_profile_id' => $student->id, 'credential_user_id' => $student->user_id, 'term_id' => $term->id, 'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled, 'status' => 'officially_enrolled', 'officially_enrolled_at' => now()]);
        $course = CourseEnrollment::factory()->create(['enrollment_id' => $enrollment->id, 'term_offering_id' => $offering->id, 'section_id' => $section->id, 'published_timetable_version_id' => null, 'units_snapshot' => $specification->credit_units]);
        $roster = GradeRoster::factory()->create(['term_offering_id' => $offering->id, 'section_id' => $section->id, 'state' => GradeRoster::StateReleased]);
        $row = GradeRosterRow::factory()->create(['grade_roster_id' => $roster->id, 'course_enrollment_id' => $course->id, 'current_outcome_code' => $result, 'current_outcome_category' => $result === 'INC' ? GradeRosterRow::CategoryIncomplete : GradeRosterRow::CategoryPassing, 'released_at' => now()]);
        GradeOutcomeEvent::factory()->create(['grade_roster_row_id' => $row->id, 'result_code' => $result, 'new_category' => $row->current_outcome_category, 'source_term_ends_on' => $term->ends_on, 'deadline' => $result === 'INC' ? today()->addYear() : null, 'recorded_by' => $registrar->id, 'released_at' => now()]);

        return compact('student', 'registrar');
    }

    private function staff(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
