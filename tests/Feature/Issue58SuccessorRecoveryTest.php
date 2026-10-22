<?php

namespace Tests\Feature;

use App\Actions\Calendar\ActivateTermCalendarPackage;
use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\FacultyAvailabilityRequestService;
use App\Actions\Scheduling\ReadyTermPlanningProjection;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeadline;
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
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        $draft = TermCalendarPackage::factory()->for($active->term)->create(['version' => 2, 'faculty_availability_due_at' => null]);
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
        Livewire::actingAs($faculty)->test(MyAvailability::class)->call('selectTerm', $package->term_id)->assertActionVisible('declareAvailability');
    }

    public function test_deadline_modal_displays_manila_time_and_round_trips_the_stored_instant(): void
    {
        $registrar = $this->staff();
        foreach ([false, true] as $hasSeparateSource) {
            $package = $this->activePackage($registrar);
            if ($hasSeparateSource) {
                $prior = app(RecordFacultyAvailabilityDeadline::class)->execute($package->term, $registrar, '2026-10-31 17:00:00', 'Approved deadline', null, 0);
            } else {
                $package->update(['faculty_availability_due_at' => '2026-10-31 17:00:00']);
                $prior = null;
            }
            $packageBefore = $package->fresh()->getAttributes();

            $component = Livewire::actingAs($registrar)->test(TermResources::class, ['termId' => $package->term_id])
                ->assertSee('Nov 1, 2026 1:00 AM (Asia/Manila)')
                ->mountAction('recordFacultyDeadline');
            $component->setActionData(['authority_reference' => 'Deadline reviewed without time change', 'correction_reason' => 'Confirm the existing approved deadline']);
            $schema = $component->instance()->getSchema($component->instance()->getMountedActionSchemaName());
            $this->assertSame('2026-11-01 01:00', $schema->getRawState()['due_at']);
            $state = $schema->getState();
            $this->assertSame('2026-10-31 17:00', $state['due_at']);
            $component->callMountedAction()->assertHasNoActionErrors();

            $latest = FacultyAvailabilityDeadline::query()->where('term_id', $package->term_id)->latest('id')->firstOrFail();
            $this->assertSame('2026-10-31 17:00:00', $latest->due_at->format('Y-m-d H:i:s'));
            $this->assertSame('2026-11-01 01:00:00', $latest->due_at->copy()->timezone('Asia/Manila')->format('Y-m-d H:i:s'));
            $this->assertSame($packageBefore, $package->fresh()->getAttributes());
            if ($prior !== null) {
                $this->assertSame('2026-10-31 17:00:00', $prior->fresh()->due_at->format('Y-m-d H:i:s'));
            }
        }
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

        Mail::assertQueued(FacultyAvailabilityRequestedMail::class, fn (FacultyAvailabilityRequestedMail $mail): bool => $mail->operationalEventId === $event->id && $mail->dueAt === 'Aug 15, 2026 5:00 PM Asia/Manila');
        $this->assertSame('2026-08-15T09:00:00+00:00', $event->payload['due_at']);
        app(RecordFacultyAvailabilityDeadline::class)->execute($package->term, $registrar, '2026-08-20 09:00:00', 'Corrected memo', 'Approved extension', $deadline->id);
        $event->update(['status' => OperationalEvent::StatusFailed]);
        Mail::fake();

        app(FacultyAvailabilityRequestService::class)->resend($event->fresh(), $registrar);

        Mail::assertQueued(FacultyAvailabilityRequestedMail::class, fn (FacultyAvailabilityRequestedMail $mail): bool => $mail->operationalEventId === $event->id && $mail->dueAt === 'Aug 15, 2026 5:00 PM Asia/Manila');
        $this->assertSame('2026-08-15T09:00:00+00:00', $event->fresh()->payload['due_at']);
    }

    public function test_mounted_request_rejects_a_corrected_deadline_without_any_event_or_mail(): void
    {
        $registrar = $this->staff();
        $faculty = $this->staff(User::StaffRoleFaculty);
        $package = $this->activePackage($registrar);
        $deadline = app(RecordFacultyAvailabilityDeadline::class)->execute($package->term, $registrar, '2026-08-15 09:00:00', 'Approved memo', null, 0);
        $component = Livewire::actingAs($registrar)->test(TermResources::class, ['termId' => $package->term_id])
            ->mountAction('requestFacultyAvailability')->setActionData(['faculty_user_ids' => [$faculty->id]]);
        app(RecordFacultyAvailabilityDeadline::class)->execute($package->term, $registrar, '2026-08-20 09:00:00', 'Corrected memo', 'Approved extension', $deadline->id);
        Mail::fake();

        $component->callMountedAction()->assertNotified('Cannot request Faculty availability');

        $this->assertSame(0, OperationalEvent::query()->where('related_record_type', TermCalendarPackage::class)->where('related_record_id', $package->id)->where('event_type', OperationalEvent::TypeFacultyAvailabilityRequestedEmail)->count());
        Mail::assertNothingQueued();
    }

    public function test_faculty_correction_prefills_own_term_and_clears_weekly_times_when_unavailable_for_the_term(): void
    {
        $registrar = $this->staff();
        $faculty = $this->staff(User::StaffRoleFaculty);
        $package = $this->activePackage($registrar);
        $intervals = [['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '11:00:00']];
        $prior = FacultyAvailabilityDeclaration::factory()->create([
            'term_id' => $package->term_id,
            'faculty_user_id' => $faculty->id,
            'version' => 1,
            'declaration' => FacultyAvailabilityDeclaration::DeclarationAvailable,
            'hard_unavailability' => $intervals,
            'correction_reason' => 'Earlier explanation',
        ]);

        $component = Livewire::actingAs($faculty)->test(MyAvailability::class, ['termId' => $package->term_id]);
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($component->html());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($document);
        $outletSelector = "@*[name()='wire:partial']='action-modals'";
        $this->assertSame(1, $xpath->query("//*[$outletSelector]")->length);
        $this->assertSame(0, $xpath->query("//details//*[$outletSelector]")->length, 'Availability dialogs must remain reachable while history is collapsed.');

        $component
            ->mountAction('declareAvailability')
            ->assertActionDataSet([
                'form_term_id' => $package->term_id,
                'declaration' => FacultyAvailabilityDeclaration::DeclarationAvailable,
                'correction_reason' => null,
            ])
            ->setActionData(['declaration' => FacultyAvailabilityDeclaration::DeclarationUnavailable, 'correction_reason' => 'Cannot teach this Term'])
            ->callMountedAction()->assertHasNoActionErrors();

        $latest = FacultyAvailabilityDeclaration::query()->where('faculty_user_id', $faculty->id)->latest('version')->firstOrFail();
        $this->assertSame(2, $latest->version);
        $this->assertSame(FacultyAvailabilityDeclaration::DeclarationUnavailable, $latest->declaration);
        $this->assertSame([], $latest->hard_unavailability);
        $this->assertEquals($intervals, $prior->fresh()->hard_unavailability);
    }

    public function test_a_mounted_faculty_declaration_cannot_follow_a_changed_term_selection(): void
    {
        $registrar = $this->staff();
        $faculty = $this->staff(User::StaffRoleFaculty);
        $package = $this->activePackage($registrar);
        $other = $this->activePackage($registrar);
        app(RecordFacultyAvailabilityDeadline::class)->execute($package->term, $registrar, '2026-08-15 09:00:00', 'Source58', null, 0);
        app(RecordFacultyAvailabilityDeadline::class)->execute($other->term, $registrar, '2026-08-15 09:00:00', 'Other source58', null, 0);
        Mail::fake();
        app(FacultyAvailabilityRequestService::class)->request($package, [$faculty->id], $registrar);
        app(FacultyAvailabilityRequestService::class)->request($other, [$faculty->id], $registrar);

        Livewire::actingAs($faculty)->test(MyAvailability::class, ['termId' => $package->term_id])
            ->mountAction('declareAvailability')->setActionData(['declaration' => 'Available', 'hard_unavailability' => []])
            ->call('selectTerm', $other->term_id)->callMountedAction()->assertNotified('Term selection changed');

        $this->assertSame(0, FacultyAvailabilityDeclaration::query()->where('faculty_user_id', $faculty->id)->count());
    }

    public function test_owned_history_details_show_complete_response_and_reject_other_records(): void
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
        $latest = FacultyAvailabilityDeclaration::factory()->create(['term_id' => $term->id, 'faculty_user_id' => $faculty->id, 'version' => 2, 'declaration' => 'Unavailable', 'hard_unavailability' => [], 'correction_reason' => 'Changed teaching commitment']);
        $foreign = FacultyAvailabilityDeclaration::factory()->create(['term_id' => $term->id, 'faculty_user_id' => $colleague->id, 'correction_reason' => 'Private colleague reason']);
        $crossTerm = FacultyAvailabilityDeclaration::factory()->create(['term_id' => $otherTerm->id, 'faculty_user_id' => $faculty->id, 'correction_reason' => 'Other Term reason']);

        $page = Livewire::actingAs($faculty)->test(MyAvailability::class, ['termId' => $term->id]);
        $page->call('mountAction', 'viewAvailability', [], ['table' => true, 'recordKey' => (string) $initial->id])
            ->assertSet('mountedActions.0.name', 'viewAvailability');
        $details = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName())->toHtml();
        foreach (['Complete weekly unavailable times', 'Monday', 'Thursday', '8:00 AM', '3:30 PM', 'Term #'.$term->id, 'Initial response'] as $text) {
            $this->assertStringContainsString($text, $details);
        }
        $this->assertStringNotContainsString('Private colleague reason', $details);
        $this->assertStringNotContainsString('Other Term reason', $details);
        $this->assertNull($page->instance()->getMountedAction()->getModalSubmitAction());
        $page->call('unmountAction')->call('mountAction', 'viewAvailability', [], ['table' => true, 'recordKey' => (string) $latest->id]);
        $latestDetails = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName())->toHtml();
        $this->assertStringContainsString('Changed teaching commitment', $latestDetails);
        $this->assertStringContainsString('Unavailable for the whole Term', $latestDetails);
        $page->call('selectTerm', $otherTerm->id)->assertSet('mountedActions', [])->assertDontSee('Changed teaching commitment');
        $page->call('selectTerm', $term->id);
        foreach ([$foreign->id, $crossTerm->id, 2147483647] as $id) {
            $page->call('mountAction', 'viewAvailability', [], ['table' => true, 'recordKey' => (string) $id])->assertSet('mountedActions', []);
        }
        $this->assertEquals($intervals, $initial->fresh()->hard_unavailability);
        $this->assertSame(2, FacultyAvailabilityDeclaration::query()->where('term_id', $term->id)->where('faculty_user_id', $faculty->id)->count());
    }

    public function test_history_content_re_resolves_selected_term_after_mount(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $owned = FacultyAvailabilityDeclaration::factory()->create(['faculty_user_id' => $faculty->id]);
        $otherTerm = Term::factory()->create();
        $page = Livewire::actingAs($faculty)->test(MyAvailability::class, ['termId' => $owned->term_id]);
        $action = $page->instance()->viewAvailabilityAction()->record($owned);
        $page->instance()->termId = $otherTerm->id;

        $this->expectException(ModelNotFoundException::class);
        $action->getSchema(Schema::make($page->instance()));
    }

    public function test_history_content_re_resolves_actor_after_mount(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $colleague = $this->staff(User::StaffRoleFaculty);
        $owned = FacultyAvailabilityDeclaration::factory()->create(['faculty_user_id' => $faculty->id]);
        $page = Livewire::actingAs($faculty)->test(MyAvailability::class, ['termId' => $owned->term_id]);
        $action = $page->instance()->viewAvailabilityAction()->record($owned);
        $this->actingAs($colleague);

        $this->expectException(ModelNotFoundException::class);
        $action->getSchema(Schema::make($page->instance()));
    }

    public function test_optional_weekly_times_preserve_drafts_and_empty_rows_clear_constraints(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $term = Term::factory()->create();
        $prior = FacultyAvailabilityDeclaration::factory()->create(['term_id' => $term->id, 'faculty_user_id' => $faculty->id, 'hard_unavailability' => []]);
        $page = Livewire::actingAs($faculty)->test(MyAvailability::class, ['termId' => $term->id])->mountAction('declareAvailability')
            ->assertActionDataSet(['hard_unavailability' => [], 'correction_reason' => null]);
        $intervals = [['day_of_week' => 2, 'starts_at' => '10:00', 'ends_at' => '11:00']];
        $page->setActionData(['hard_unavailability' => $intervals]);
        $this->assertSame($intervals, array_values($page->get('mountedActions.0.data.hard_unavailability')));
        $page->setActionData(['hard_unavailability' => [], 'correction_reason' => 'No recurring restriction now'])
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame([], $prior->fresh()->hard_unavailability);
        $this->assertSame([], FacultyAvailabilityDeclaration::query()->where('faculty_user_id', $faculty->id)->latest('version')->firstOrFail()->hard_unavailability);
    }

    public function test_initial_response_accepts_optional_weekly_times_and_requires_valid_added_intervals(): void
    {
        $registrar = $this->staff();
        $faculty = $this->staff(User::StaffRoleFaculty);
        $package = $this->activePackage($registrar);
        app(RecordFacultyAvailabilityDeadline::class)->execute($package->term, $registrar, '2026-08-15 09:00:00', 'Progressive response deadline', null, 0);
        Mail::fake();
        app(FacultyAvailabilityRequestService::class)->request($package, [$faculty->id], $registrar);
        $page = Livewire::actingAs($faculty)->test(MyAvailability::class, ['termId' => $package->term_id])->mountAction('declareAvailability')
            ->assertActionDataSet(['declaration' => null, 'hard_unavailability' => []]);
        $page->setActionData(['declaration' => 'Available', 'hard_unavailability' => [['day_of_week' => 1, 'starts_at' => '09:00', 'ends_at' => null]]])
            ->callMountedAction()->assertHasActionErrors();
        $intervals = [['day_of_week' => 1, 'starts_at' => '09:00', 'ends_at' => '10:00']];
        $page->setActionData(['hard_unavailability' => $intervals])->callMountedAction()->assertHasNoActionErrors();
        $response = FacultyAvailabilityDeclaration::query()->where('faculty_user_id', $faculty->id)->sole();
        $this->assertEquals([['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '10:00:00']], $response->hard_unavailability);
        Livewire::actingAs($faculty)->test(MyAvailability::class, ['termId' => $package->term_id])->mountAction('declareAvailability')
            ->assertActionDataSet(['correction_reason' => null])
            ->callMountedAction()->assertHasActionErrors(['correction_reason' => 'required']);
        Mail::assertQueued(FacultyAvailabilityRequestedMail::class, 1);
    }

    public function test_availability_view_distinguishes_no_request_from_a_recorded_response(): void
    {
        $registrar = $this->staff();
        $faculty = $this->staff(User::StaffRoleFaculty);
        $package = $this->activePackage($registrar);
        app(RecordFacultyAvailabilityDeadline::class)->execute($package->term, $registrar, '2026-08-15 09:00:00', 'Recorded deadline', null, 0);

        Livewire::actingAs($faculty)->test(MyAvailability::class, ['termId' => $package->term_id])
            ->assertSee('No availability request for this Term')
            ->assertDontSee('Response due:')
            ->assertDontSee('Availability history');

        FacultyAvailabilityDeclaration::factory()->create([
            'term_id' => $package->term_id,
            'faculty_user_id' => $faculty->id,
            'declaration' => FacultyAvailabilityDeclaration::DeclarationAvailable,
            'hard_unavailability' => [['day_of_week' => 1, 'starts_at' => '09:00', 'ends_at' => '11:00']],
        ]);

        Livewire::actingAs($faculty)->test(MyAvailability::class, ['termId' => $package->term_id])
            ->assertSee('Can teach this Term')
            ->assertSee('Weekly unavailable times')
            ->assertSee('Monday')
            ->assertSee('Availability history');
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
            ->mountAction('recordCalendarPackage')->callMountedAction()->assertHasNoActionErrors();

        $draft = TermCalendarPackage::query()->where('term_id', $package->term_id)->where('state', TermCalendarPackage::StateDraft)->sole();
        $this->assertSame('23:59:59', $draft->windows()->sole()->cutoff_at);
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
        $package = TermCalendarPackage::factory()->create(['faculty_availability_due_at' => null]);
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
        $component = Livewire::actingAs($registrar)->test(TermCalendar::class, ['termId' => $package->term_id])->mountAction('recordCalendarPackage');
        $schema = $component->instance()->getSchema($component->instance()->getMountedActionSchemaName());
        $state = $schema->getState();
        $state['authority_reference'] = 'Approved operational dates successor';
        $state['windows'] = [['window_type' => TermCalendarWindow::TypeEnrollment, 'opens_on' => '2026-08-01', 'closes_on' => '2026-08-15', 'cutoff_at' => null]];
        if ($gridRows !== null) {
            $state['teaching_grid_rows'] = $gridRows;
        }
        $component->setActionData($state)->callMountedAction()->assertHasNoActionErrors();
        $draft = TermCalendarPackage::query()->where('term_id', $package->term_id)->where('state', TermCalendarPackage::StateDraft)->sole();
        Livewire::actingAs($registrar)->test(TermCalendar::class, ['termId' => $package->term_id])
            ->mountAction('activateCalendarPackage')->callMountedAction()->assertHasNoActionErrors();

        return $draft->fresh(['windows', 'teachingGridRows', 'datedExceptions']);
    }
}
