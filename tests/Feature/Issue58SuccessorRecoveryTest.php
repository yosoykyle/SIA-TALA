<?php

namespace Tests\Feature;

use App\Actions\Calendar\ActivateTermCalendarPackage;
use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\FacultyAvailabilityRequestService;
use App\Actions\Scheduling\ReadyTermPlanningProjection;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeadline;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeclaration;
use App\Filament\Pages\MyAvailability;
use App\Filament\Pages\TermCalendar;
use App\Filament\Pages\TermResources;
use App\Mail\FacultyAvailabilityRequestedMail;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\FacultyAvailabilityDeadline;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\OperationalEvent;
use App\Models\Room;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Issue58SuccessorRecoveryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());
        Filament::setCurrentPanel(Filament::getPanel('staff'));
    }

    public function test_operational_successor_form_preserves_breaks_and_existing_class_sources(): void
    {
        $registrar = $this->staff();
        $package = $this->activePackage($registrar);
        $section = Section::factory()->create(['term_calendar_package_id' => $package->id, 'confirmed_at' => now()]);
        $package->update(['activated_at' => null]);
        Room::factory()->create(['is_active' => true]);

        $successor = $this->recordAndActivateSuccessor($package, $registrar);

        $this->assertSame(TermCalendarPackage::StateClosed, $package->fresh()->state);
        $this->assertSame(Term::StateActive, $package->term->fresh()->state);
        $this->assertSame($package->fresh()->coreSignature(), $successor->coreSignature());
        $this->assertSame($package->id, $section->fresh()->term_calendar_package_id);
        $this->assertTrue(app(ReadyTermPlanningProjection::class)->forTerm($package->term->fresh())['ready']);
        $this->assertSame('12:00:00', $package->fresh()->teachingGridRows->sole()->breaks[0]['starts_at']);
    }

    public function test_never_activated_draft_cannot_confirm_a_class_despite_matching_the_active_core(): void
    {
        $registrar = $this->staff();
        $active = $this->activePackage($registrar);
        $draft = TermCalendarPackage::factory()->for($active->term)->create(['version' => 2]);
        $draft->teachingGridRows()->create(['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '17:00:00', 'breaks' => [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']]]);
        $section = Section::factory()->for(TermOffering::factory()->for($draft->term), 'termOffering')->create(['term_calendar_package_id' => $draft->id]);
        $this->assertSame($active->coreSignature(), $draft->fresh()->coreSignature());

        try {
            app(ConfirmClassOffering::class)->execute($section, $registrar, []);
            $this->fail('An unapproved Draft source cannot confirm a Class Offering.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('calendar_package', $exception->errors());
        }
        $this->assertNull($section->fresh()->confirmed_at);
        $this->assertSame(TermCalendarPackage::StateDraft, $draft->fresh()->state);
    }

    public function test_class_confirmation_succeeds_against_the_activated_closed_same_core_predecessor(): void
    {
        $this->freezeTime();
        $registrar = $this->staff();
        $predecessor = $this->activePackage($registrar);
        [$section, $cohort] = $this->classAndCohort($predecessor);
        $successor = $this->recordAndActivateSuccessor($predecessor, $registrar);
        $this->assertSame(TermCalendarPackage::StateClosed, $predecessor->fresh()->state);
        $this->assertNotNull($predecessor->fresh()->activated_at);
        $this->assertSame(TermCalendarPackage::StateActive, $successor->state);

        $confirmed = app(ConfirmClassOffering::class)->execute($section, $registrar, [$cohort->id => 12]);

        $this->assertSame($predecessor->id, $confirmed->term_calendar_package_id);
        $this->assertSame($registrar->id, $confirmed->confirmed_by);
        $this->assertNotNull($confirmed->confirmed_at);
        $this->assertSame([$cohort->id], $confirmed->cohorts->modelKeys());
        $this->assertSame(12, $confirmed->cohorts->sole()->pivot->expected_count);
        $this->assertSame(TermCalendarPackage::StateClosed, $predecessor->fresh()->state);
    }

    public function test_class_confirmation_rejects_the_closed_predecessor_when_the_active_core_changed(): void
    {
        $registrar = $this->staff();
        $predecessor = $this->activePackage($registrar);
        [$section, $cohort] = $this->classAndCohort($predecessor);
        $this->recordAndActivateSuccessor($predecessor, $registrar, [
            ['day_of_week' => 2, 'starts_at' => '10:00', 'ends_at' => '16:00', 'breaks' => []],
        ]);

        try {
            app(ConfirmClassOffering::class)->execute($section, $registrar, [$cohort->id => 12]);
            $this->fail('A changed approved core must not confirm a class against its superseded source.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('calendar_package', $exception->errors());
        }
        $this->assertNull($section->fresh()->confirmed_at);
        $this->assertSame(0, $section->cohorts()->count());
    }

    public function test_same_core_predecessor_confirmation_rejects_cross_term_cohorts_without_partial_sync(): void
    {
        $registrar = $this->staff();
        $predecessor = $this->activePackage($registrar);
        [$section, $cohort] = $this->classAndCohort($predecessor);
        $crossTerm = TermCohort::factory()->create([
            'program_id' => $cohort->program_id,
            'curriculum_version_id' => $cohort->curriculum_version_id,
        ]);
        $this->recordAndActivateSuccessor($predecessor, $registrar);

        try {
            app(ConfirmClassOffering::class)->execute($section, $registrar, [$cohort->id => 6, $crossTerm->id => 6]);
            $this->fail('Cross-Term cohort evidence must not confirm or partially sync a class.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cohorts', $exception->errors());
        }
        $this->assertNull($section->fresh()->confirmed_at);
        $this->assertSame(0, $section->cohorts()->count());
    }

    public function test_same_core_predecessor_request_remains_usable_without_a_duplicate_email(): void
    {
        $registrar = $this->staff();
        $faculty = $this->staff(User::StaffRoleFaculty);
        $package = $this->activePackage($registrar);
        app(RecordFacultyAvailabilityDeadline::class)->execute($package->term, $registrar, '2026-08-15 09:00:00', 'Approved availability memo', null, 0);
        Mail::fake();
        $event = app(FacultyAvailabilityRequestService::class)->request($package->fresh(), [$faculty->id], $registrar)->sole();
        $successor = $this->recordAndActivateSuccessor($package, $registrar);

        $retained = app(FacultyAvailabilityRequestService::class)->request($successor, [$faculty->id], $registrar);

        $this->assertSame([$event->id], $retained->modelKeys());
        $this->assertSame(1, OperationalEvent::query()->where('user_id', $faculty->id)->where('event_type', OperationalEvent::TypeFacultyAvailabilityRequestedEmail)->count());
        Mail::assertQueued(FacultyAvailabilityRequestedMail::class, 1);
        Livewire::actingAs($faculty)->test(MyAvailability::class, ['termId' => $package->term_id])->assertSee('Save availability');
    }

    public function test_deadline_modal_displays_manila_time_and_round_trips_the_stored_instant(): void
    {
        Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $registrar = $this->staff();
        $package = $this->activePackage($registrar);
        $prior = app(RecordFacultyAvailabilityDeadline::class)->execute($package->term, $registrar, '2026-10-31 17:00:00', 'Approved deadline', null, 0);
        $packageBefore = $package->fresh()->getAttributes();

        $component = Livewire::actingAs($registrar)->test(TermResources::class, ['termId' => $package->term_id])
            ->assertSee('Nov 1, 2026 · 1:00 AM')
            ->mountAction('sendAvailabilityReminder');
        $component->setActionData(['faculty_user_ids' => [], 'authority_reference' => 'Deadline reviewed without time change', 'correction_reason' => 'Confirm the existing approved deadline']);
        $schema = $component->instance()->getSchema($component->instance()->getMountedActionSchemaName());
        $this->assertSame('2026-11-01 01:00', $schema->getRawState()['due_at']);
        $state = $schema->getState();
        $this->assertSame('2026-10-31 17:00', $state['due_at']);
        $component->callMountedAction()->assertHasNoActionErrors();

        $latest = FacultyAvailabilityDeadline::query()->where('term_id', $package->term_id)->latest('id')->firstOrFail();
        $this->assertSame('2026-10-31 17:00:00', $latest->due_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 01:00:00', $latest->due_at->copy()->timezone('Asia/Manila')->format('Y-m-d H:i:s'));
        $this->assertSame($packageBefore, $package->fresh()->getAttributes());
        $this->assertSame('2026-10-31 17:00:00', $prior->fresh()->due_at->format('Y-m-d H:i:s'));
    }

    public function test_new_request_and_failed_resend_use_the_retained_deadline_in_the_actual_mail(): void
    {
        $this->travelTo(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        $registrar = $this->staff();
        $faculty = $this->staff(User::StaffRoleFaculty);
        $package = $this->activePackage($registrar);
        $deadline = app(RecordFacultyAvailabilityDeadline::class)->execute($package->term, $registrar, '2026-08-15 09:00:00', 'Approved availability memo', null, 0);
        Mail::fake();

        $event = app(FacultyAvailabilityRequestService::class)->request($package->fresh(), [$faculty->id], $registrar)->sole();

        Mail::assertQueued(FacultyAvailabilityRequestedMail::class, fn (FacultyAvailabilityRequestedMail $mail): bool => $mail->operationalEventId === $event->id && $mail->dueAt === 'Aug 15, 2026 5:00 PM');
        $this->assertSame('2026-08-15T09:00:00+00:00', $event->payload['due_at']);
        app(RecordFacultyAvailabilityDeadline::class)->execute($package->term, $registrar, '2026-08-20 09:00:00', 'Corrected memo', 'Approved extension', $deadline->id);
        $event->update(['status' => OperationalEvent::StatusFailed]);
        Mail::fake();

        app(FacultyAvailabilityRequestService::class)->resend($event->fresh(), $registrar);

        Mail::assertQueued(FacultyAvailabilityRequestedMail::class, fn (FacultyAvailabilityRequestedMail $mail): bool => $mail->operationalEventId === $event->id && $mail->dueAt === 'Aug 15, 2026 5:00 PM');
        $this->assertSame('2026-08-15T09:00:00+00:00', $event->fresh()->payload['due_at']);
    }

    public function test_mounted_request_rejects_a_corrected_deadline_without_any_event_or_mail(): void
    {
        $registrar = $this->staff();
        $faculty = $this->staff(User::StaffRoleFaculty);
        $package = $this->activePackage($registrar);
        $deadline = app(RecordFacultyAvailabilityDeadline::class)->execute($package->term, $registrar, '2026-08-15 09:00:00', 'Approved memo', null, 0);
        $component = Livewire::actingAs($registrar)->test(TermResources::class, ['termId' => $package->term_id])
            ->mountAction('sendAvailabilityReminder')->setActionData(['faculty_user_ids' => [$faculty->id]]);
        app(RecordFacultyAvailabilityDeadline::class)->execute($package->term, $registrar, '2026-08-20 09:00:00', 'Corrected memo', 'Approved extension', $deadline->id);
        Mail::fake();

        $component->callMountedAction()
            ->assertHasErrors(['calendar_package'])
            ->assertNotified('Cannot send availability reminder');

        $this->assertSame(0, OperationalEvent::query()->where('related_record_type', TermCalendarPackage::class)->where('related_record_id', $package->id)->where('event_type', OperationalEvent::TypeFacultyAvailabilityRequestedEmail)->count());
        $this->assertSame(2, FacultyAvailabilityDeadline::query()->whereBelongsTo($package->term)->count());
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    public function test_faculty_correction_prefills_own_term_and_clears_weekly_times_when_unavailable_for_the_term(): void
    {
        $registrar = $this->staff();
        $faculty = $this->staff(User::StaffRoleFaculty);
        $package = $this->activePackage($registrar);
        $windows = [['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '11:00']];
        $prior = FacultyAvailabilityDeclaration::factory()->create([
            'term_id' => $package->term_id,
            'faculty_user_id' => $faculty->id,
            'version' => 1,
            'declaration' => FacultyAvailabilityDeclaration::DeclarationAvailable,
            'availability_mode' => FacultyAvailabilityDeclaration::ModeWindows,
            'teaching_windows' => $windows,
            'hard_unavailability' => [],
            'correction_reason' => 'Earlier explanation',
        ]);

        Livewire::actingAs($faculty)
            ->test(MyAvailability::class, ['termId' => $package->term_id])
            ->assertSet('editorTermId', $package->term_id)
            ->assertSet('availabilityMode', FacultyAvailabilityDeclaration::ModeWindows)
            ->assertSet('enabledDays.1', true)
            ->assertSet('dayWindows.1.0', ['start_time' => '08:00', 'end_time' => '11:00'])
            ->assertSet('correctionReason', '')
            ->assertDontSee('Save availability')
            ->assertDontSee('Save update')
            ->assertDontSee('Reason for update')
            ->set('availabilityMode', FacultyAvailabilityDeclaration::ModeCannotTeach)
            ->set('correctionReason', 'Cannot teach this term')
            ->call('saveAvailability')
            ->assertStatus(409);

        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $package->term,
            faculty: $faculty,
            actor: $faculty,
            correctionReason: 'Cannot teach this term',
            availabilityMode: FacultyAvailabilityDeclaration::ModeCannotTeach,
        );

        $latest = FacultyAvailabilityDeclaration::query()->where('faculty_user_id', $faculty->id)->latest('version')->firstOrFail();
        $this->assertSame(2, $latest->version);
        $this->assertSame(FacultyAvailabilityDeclaration::DeclarationUnavailable, $latest->declaration);
        $this->assertSame(FacultyAvailabilityDeclaration::ModeCannotTeach, $latest->availability_mode);
        $this->assertNull($latest->teaching_windows);
        $this->assertSame([], $latest->hard_unavailability);
        $this->assertEquals($windows, $prior->fresh()->teaching_windows);

        Livewire::actingAs($faculty)
            ->test(MyAvailability::class, ['termId' => $package->term_id])
            ->assertSee('Unavailable this term')
            ->assertSee('Cannot teach this term');
    }

    public function test_a_mounted_faculty_declaration_cannot_follow_a_changed_term_selection(): void
    {
        $registrar = $this->staff();
        $faculty = $this->staff(User::StaffRoleFaculty);
        $package = $this->activePackage($registrar);
        $other = $this->activePackage($registrar);

        Livewire::actingAs($faculty)->test(MyAvailability::class, ['termId' => $package->term_id])
            ->set('availabilityMode', FacultyAvailabilityDeclaration::ModeAnyApproved)
            ->set('editorTermId', $other->term_id)
            ->call('saveAvailability')
            ->assertNotified('Term selection changed');

        $this->assertSame(0, FacultyAvailabilityDeclaration::query()->where('faculty_user_id', $faculty->id)->count());
    }

    public function test_owned_history_is_hidden_from_faculty_ui_while_backend_versions_and_isolation_remain_intact(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $colleague = $this->staff(User::StaffRoleFaculty);
        $term = Term::factory()->create();
        $otherTerm = Term::factory()->create();
        $intervals = [
            ['day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '09:30'],
            ['day_of_week' => 4, 'starts_at' => '14:00', 'ends_at' => '15:30'],
        ];
        $initial = FacultyAvailabilityDeclaration::factory()->create(['term_id' => $term->id, 'faculty_user_id' => $faculty->id, 'version' => 1, 'hard_unavailability' => $intervals, 'correction_reason' => null]);
        FacultyAvailabilityDeclaration::factory()->create(['term_id' => $term->id, 'faculty_user_id' => $faculty->id, 'version' => 2, 'declaration' => 'Unavailable', 'hard_unavailability' => [], 'correction_reason' => 'Changed teaching commitment']);
        FacultyAvailabilityDeclaration::factory()->create(['term_id' => $term->id, 'faculty_user_id' => $colleague->id, 'correction_reason' => 'Private colleague reason']);
        FacultyAvailabilityDeclaration::factory()->create(['term_id' => $otherTerm->id, 'faculty_user_id' => $faculty->id, 'correction_reason' => 'Other Term reason']);

        $page = Livewire::actingAs($faculty)->test(MyAvailability::class, ['termId' => $term->id]);
        $page
            ->assertSee('Unavailable this term')
            ->assertSee('Changed teaching commitment')
            ->assertDontSee('Availability history')
            ->assertDontSee('Version 1')
            ->assertDontSee('Version 2')
            ->assertDontSee('Private colleague reason')
            ->assertDontSee('Other Term reason')
            ->assertDontSee('Term #'.$term->id);

        Livewire::actingAs($faculty)->test(MyAvailability::class, ['termId' => $otherTerm->id])
            ->assertSee('Other Term reason')
            ->assertDontSee('Changed teaching commitment')
            ->assertDontSee('Private colleague reason');

        $this->assertEquals($intervals, $initial->fresh()->hard_unavailability);
        $this->assertSame(2, FacultyAvailabilityDeclaration::query()->where('term_id', $term->id)->where('faculty_user_id', $faculty->id)->count());
    }

    public function test_history_content_re_resolves_selected_term_after_mount(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $owned = FacultyAvailabilityDeclaration::factory()->create(['faculty_user_id' => $faculty->id, 'correction_reason' => 'Owned term secret']);
        $otherTerm = Term::factory()->create();

        Livewire::actingAs($faculty)
            ->test(MyAvailability::class, ['termId' => $owned->term_id])
            ->assertSee('Owned term secret');

        Livewire::actingAs($faculty)
            ->test(MyAvailability::class, ['termId' => $otherTerm->id])
            ->assertDontSee('Owned term secret');
    }

    public function test_history_content_re_resolves_actor_after_mount(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $colleague = $this->staff(User::StaffRoleFaculty);
        $owned = FacultyAvailabilityDeclaration::factory()->create(['faculty_user_id' => $faculty->id, 'correction_reason' => 'Faculty only secret']);

        Livewire::actingAs($faculty)
            ->test(MyAvailability::class, ['termId' => $owned->term_id])
            ->assertSee('Faculty only secret');

        Livewire::actingAs($colleague)
            ->test(MyAvailability::class, ['termId' => $owned->term_id])
            ->assertDontSee('Faculty only secret');
    }

    public function test_optional_weekly_times_preserve_drafts_and_empty_rows_clear_constraints(): void
    {
        $registrar = $this->staff();
        $faculty = $this->staff(User::StaffRoleFaculty);
        $package = $this->activePackage($registrar);
        $page = Livewire::actingAs($faculty)
            ->test(MyAvailability::class, ['termId' => $package->term_id])
            ->assertSet('availabilityMode', null)
            ->assertSet('enabledDays', [])
            ->assertSet('correctionReason', '')
            ->set('availabilityMode', FacultyAvailabilityDeclaration::ModeWindows)
            ->call('toggleDay', 1)
            ->set('dayWindows.1.0', ['start_time' => '09:00', 'end_time' => '11:00']);

        $this->assertSame([['start_time' => '09:00', 'end_time' => '11:00']], $page->get('dayWindows.1'));

        $page
            ->set('availabilityMode', FacultyAvailabilityDeclaration::ModeAnyApproved)
            ->call('saveAvailability')
            ->assertHasNoErrors();

        $latest = FacultyAvailabilityDeclaration::query()->where('faculty_user_id', $faculty->id)->latest('version')->firstOrFail();
        $this->assertSame(FacultyAvailabilityDeclaration::ModeAnyApproved, $latest->availability_mode);
        $this->assertNull($latest->teaching_windows);

        Livewire::actingAs($faculty)
            ->test(MyAvailability::class, ['termId' => $package->term_id])
            ->call('saveAvailability')
            ->assertStatus(409);
    }

    public function test_initial_response_accepts_optional_weekly_times_and_requires_valid_added_intervals(): void
    {
        $registrar = $this->staff();
        $faculty = $this->staff(User::StaffRoleFaculty);
        $package = $this->activePackage($registrar);
        $page = Livewire::actingAs($faculty)
            ->test(MyAvailability::class, ['termId' => $package->term_id])
            ->assertSet('availabilityMode', null)
            ->assertSet('enabledDays', []);

        $page
            ->set('availabilityMode', FacultyAvailabilityDeclaration::ModeWindows)
            ->call('saveAvailability')
            ->assertHasErrors(['teachingWindows']);

        $page
            ->call('toggleDay', 1)
            ->set('dayWindows.1.0', ['start_time' => '09:00', 'end_time' => '11:00'])
            ->call('saveAvailability')
            ->assertHasNoErrors();

        $response = FacultyAvailabilityDeclaration::query()->where('faculty_user_id', $faculty->id)->sole();
        $this->assertSame(FacultyAvailabilityDeclaration::ModeWindows, $response->availability_mode);
        $this->assertEquals([['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '11:00']], $response->teaching_windows);

        Livewire::actingAs($faculty)
            ->test(MyAvailability::class, ['termId' => $package->term_id])
            ->assertSee('Availability submitted and locked for this term. Contact the Registrar if changes are needed.')
            ->assertDontSee('Reason for update')
            ->call('saveAvailability')
            ->assertStatus(409);
    }

    public function test_availability_view_distinguishes_no_request_from_a_recorded_response(): void
    {
        $registrar = $this->staff();
        $faculty = $this->staff(User::StaffRoleFaculty);
        $inactiveTerm = Term::factory()->create();

        Livewire::actingAs($faculty)->test(MyAvailability::class, ['termId' => $inactiveTerm->id])
            ->assertSee('Teaching calendar is not active yet')
            ->assertDontSee('Save availability')
            ->assertDontSee('Availability history');

        $package = $this->activePackage($registrar);

        Livewire::actingAs($faculty)->test(MyAvailability::class, ['termId' => $package->term_id])
            ->assertSee('Not submitted yet')
            ->assertSee('Save availability')
            ->assertDontSee('Availability history');

        FacultyAvailabilityDeclaration::factory()->create([
            'term_id' => $package->term_id,
            'faculty_user_id' => $faculty->id,
            'declaration' => FacultyAvailabilityDeclaration::DeclarationAvailable,
            'availability_mode' => null,
            'hard_unavailability' => [['day_of_week' => 1, 'starts_at' => '09:00', 'ends_at' => '11:00']],
        ]);

        Livewire::actingAs($faculty)->test(MyAvailability::class, ['termId' => $package->term_id])
            ->assertSee('Available except recorded unavailable times')
            ->assertSee('Recorded unavailable times')
            ->assertSee('Monday')
            ->assertSee('Availability submitted and locked for this term. Contact the Registrar if changes are needed.')
            ->assertDontSee('Availability history');
    }

    public function test_declared_recipient_rejects_the_entire_request_without_partial_mail(): void
    {
        $registrar = $this->staff();
        $faculty = $this->staff(User::StaffRoleFaculty);
        $other = $this->staff(User::StaffRoleFaculty);
        $package = $this->activePackage($registrar);
        app(RecordFacultyAvailabilityDeadline::class)->execute($package->term, $registrar, '2026-08-15 09:00:00', 'Source58', null, 0);
        FacultyAvailabilityDeclaration::factory()->create(['term_id' => $package->term_id, 'faculty_user_id' => $faculty->id]);
        Mail::fake();
        try {
            app(FacultyAvailabilityRequestService::class)->request($package, [$other->id, $faculty->id], $registrar);
            $this->fail('An ineligible recipient must reject all request mutations.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('faculty_user_ids', $exception->errors());
        }
        $this->assertSame(0, OperationalEvent::query()->where('related_record_id', $package->id)->where('event_type', OperationalEvent::TypeFacultyAvailabilityRequestedEmail)->count());
        Mail::assertNothingQueued();
    }

    public function test_recording_a_successor_preserves_an_existing_approved_cutoff_to_the_second(): void
    {
        $registrar = $this->staff();
        $package = $this->activePackage($registrar);
        $window = TermCalendarWindow::factory()->for($package, 'package')->create(['window_type' => TermCalendarWindow::TypeGradeEntry, 'cutoff_at' => '23:59:59']);

        Livewire::actingAs($registrar)->test(TermCalendar::class, ['termId' => $package->term_id])
            ->mountAction('saveAndActivateCalendar')->callMountedAction()->assertHasNoActionErrors();

        $successor = TermCalendarPackage::query()->where('term_id', $package->term_id)->where('state', TermCalendarPackage::StateActive)->sole();
        $this->assertNotSame($package->id, $successor->id);
        $this->assertSame('23:59:59', $successor->windows()->sole()->cutoff_at);
        $this->assertSame('23:59:59', $window->fresh()->cutoff_at);
    }

    private function staff(string $role = User::StaffRoleRegistrar): User
    {
        $roleRecord = Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        if ($role === User::StaffRoleRegistrar) {
            $roleRecord->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'manage-schedules', 'guard_name' => 'web']));
        }
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }

    private function activePackage(User $registrar): TermCalendarPackage
    {
        $package = TermCalendarPackage::factory()->create();
        $package->teachingGridRows()->create(['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '17:00:00', 'breaks' => [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']]]);

        return app(ActivateTermCalendarPackage::class)->execute($package, $registrar);
    }

    /** @return array{Section, TermCohort} */
    private function classAndCohort(TermCalendarPackage $package): array
    {
        $revision = CourseSpecification::factory()->create(['state' => CourseSpecification::StateActive]);
        $curriculum = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateActive]);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($revision, 'courseSpecification')->create();
        $cohort = TermCohort::factory()->for($package->term)->create([
            'program_id' => $curriculum->program_id,
            'curriculum_version_id' => $curriculum->id,
        ]);
        $section = Section::factory()->create([
            'term_offering_id' => TermOffering::factory()->for($package->term)->for($entry, 'curriculumEntry'),
            'term_calendar_package_id' => $package->id,
            'course_specification_id' => $revision->id,
            'confirmed_at' => null,
        ]);

        return [$section, $cohort];
    }

    /** @param list<array{day_of_week:int,starts_at:string,ends_at:string,breaks:array}>|null $gridRows */
    private function recordAndActivateSuccessor(TermCalendarPackage $package, User $registrar, ?array $gridRows = null): TermCalendarPackage
    {
        $component = Livewire::actingAs($registrar)->test(TermCalendar::class, ['termId' => $package->term_id])->mountAction('saveAndActivateCalendar');
        $schema = $component->instance()->getSchema($component->instance()->getMountedActionSchemaName());
        $state = $schema->getState();
        $state['authority_reference'] = 'Approved operational dates successor';
        $state['windows'] = [['window_type' => TermCalendarWindow::TypeEnrollment, 'opens_on' => '2026-08-01', 'closes_on' => '2026-08-15', 'cutoff_at' => null]];
        if ($gridRows !== null) {
            $state['teaching_grid_rows'] = $gridRows;
        }
        $component->setActionData($state)->callMountedAction()->assertHasNoActionErrors();
        $successor = TermCalendarPackage::query()->where('term_id', $package->term_id)->where('state', TermCalendarPackage::StateActive)->latest('version')->firstOrFail();

        return $successor->fresh(['windows', 'teachingGridRows', 'datedExceptions']);
    }
}
