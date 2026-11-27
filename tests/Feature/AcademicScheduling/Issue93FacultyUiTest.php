<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\Calendar\ActivateTermCalendarPackage;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeclaration;
use App\Filament\Pages\FacultySchedule;
use App\Filament\Pages\MyAvailability;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\ScheduleRevisionEvent;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Issue93FacultyUiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame(config('database.test_database'), DB::connection()->getDatabaseName());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function faculty_can_declare_positive_teaching_windows_inline_when_core_calendar_is_active(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $inactiveTerm = Term::factory()->create();

        Livewire::actingAs($faculty)
            ->test(MyAvailability::class, ['termId' => $inactiveTerm->id])
            ->assertSee('Teaching calendar is not active yet')
            ->assertDontSee('Save availability')
            ->assertDontSee('Calendar and deadline reference');

        $package = $this->activePackage($registrar, [
            ['day_of_week' => 1, 'starts_at' => '07:30:00', 'ends_at' => '19:00:00', 'breaks' => [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']]],
            ['day_of_week' => 3, 'starts_at' => '07:30:00', 'ends_at' => '19:00:00', 'breaks' => [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']]],
        ]);

        $component = Livewire::actingAs($faculty)
            ->test(MyAvailability::class, ['termId' => $package->term_id])
            ->assertSee('Not submitted yet')
            ->assertSee('School teaching hours:')
            ->assertSee('Mon, Wed 7:30 AM–7:00 PM (Break 12:00 PM–1:00 PM)')
            ->assertSee('Specific weekly times')
            ->assertSee('Any school teaching time')
            ->assertSee('Unavailable this term')
            ->assertDontSee('Calendar and deadline reference')
            ->set('availabilityMode', FacultyAvailabilityDeclaration::ModeWindows)
            ->call('toggleDay', 1)
            ->assertSet('enabledDays.1', true)
            ->assertSet('dayWindows.1.0', ['start_time' => '07:30', 'end_time' => '19:00']);

        // Strictly filters end_options to > start_time and rejects break-only range inline.
        $component
            ->set('dayWindows.1.0.start_time', '12:00')
            ->set('dayWindows.1.0.end_time', '13:00')
            ->call('saveAvailability')
            ->assertHasErrors(['teachingWindows']);

        // Valid split-day selection + copy/save inline.
        $component
            ->set('dayWindows.1.0.start_time', '08:00')
            ->set('dayWindows.1.0.end_time', '12:00')
            ->call('toggleDay', 3)
            ->set('dayWindows.3.0.start_time', '13:00')
            ->set('dayWindows.3.0.end_time', '17:00')
            ->call('saveAvailability')
            ->assertHasNoErrors()
            ->assertNotified('Availability response saved');

        $saved = FacultyAvailabilityDeclaration::query()
            ->where('term_id', $package->term_id)
            ->where('faculty_user_id', $faculty->id)
            ->sole();

        $this->assertSame(FacultyAvailabilityDeclaration::ModeWindows, $saved->availability_mode);
        $this->assertSame([
            ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00'],
            ['day_of_week' => 3, 'start_time' => '13:00', 'end_time' => '17:00'],
        ], $saved->normalizedTeachingWindows());

        Livewire::actingAs($faculty)
            ->test(MyAvailability::class, ['termId' => $package->term_id])
            ->assertSee('Available on selected times')
            ->assertSee('Availability submitted and locked for this term. Contact the Registrar if changes are needed.')
            ->assertDontSee('Choose teaching times')
            ->assertDontSee('Save availability')
            ->assertDontSee('Save update')
            ->assertDontSee('Reason for update')
            ->assertSee('8 hours/week across 2 days (excluding breaks)')
            ->assertSee('Monday')
            ->assertSee('Wednesday')
            ->assertDontSee('Availability history')
            ->assertDontSee('Version 1 · Available on selected times')
            ->assertDontSee('Term #'.$package->term_id)
            ->assertDontSee('<details', false)
            ->call('toggleDay', 1)
            ->assertSet('enabledDays.1', true)
            ->set('availabilityMode', FacultyAvailabilityDeclaration::ModeCannotTeach)
            ->call('saveAvailability')
            ->assertStatus(409);

        $this->assertSame(1, FacultyAvailabilityDeclaration::query()
            ->where('term_id', $package->term_id)
            ->where('faculty_user_id', $faculty->id)
            ->count());
    }

    #[Test]
    public function my_availability_auto_resolves_the_active_term_and_rolls_over_when_a_new_term_activates(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);

        $firstPackage = $this->activePackage($registrar);
        $firstTerm = $firstPackage->term;
        $firstTerm->update(['label' => 'Prior Active Term']);

        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $firstTerm,
            faculty: $faculty,
            actor: $faculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
        );

        Livewire::actingAs($faculty)
            ->test(MyAvailability::class)
            ->assertSet('termId', $firstTerm->id)
            ->assertSee('Prior Active Term')
            ->assertSee('Available during all teaching hours')
            ->assertSee('Availability submitted and locked for this term. Contact the Registrar if changes are needed.')
            ->assertDontSee('Choose a term to view your availability');

        $firstTerm->update(['state' => Term::StateClosed]);
        $secondPackage = $this->activePackage($registrar);
        $secondTerm = $secondPackage->term;
        $secondTerm->update(['label' => 'Next Active Term']);

        Livewire::actingAs($faculty)
            ->test(MyAvailability::class)
            ->assertSet('termId', $secondTerm->id)
            ->assertSee('Next Active Term')
            ->assertSee('Not submitted yet')
            ->assertSee('Save availability')
            ->assertDontSee('Available during all teaching hours')
            ->assertDontSee('Prior Active Term');
    }

    #[Test]
    public function inline_editor_supports_split_ranges_copy_to_weekdays_and_cancel(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $package = $this->activePackage($registrar, [
            ['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '17:00:00', 'breaks' => [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']]],
            ['day_of_week' => 2, 'starts_at' => '08:00:00', 'ends_at' => '17:00:00', 'breaks' => [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']]],
            ['day_of_week' => 3, 'starts_at' => '08:00:00', 'ends_at' => '17:00:00', 'breaks' => [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']]],
        ]);

        $page = Livewire::actingAs($faculty)
            ->test(MyAvailability::class, ['termId' => $package->term_id])
            ->assertSee('Mon–Wed 8:00 AM–5:00 PM (Break 12:00 PM–1:00 PM)')
            ->set('availabilityMode', FacultyAvailabilityDeclaration::ModeWindows)
            ->call('toggleDay', 1)
            ->set('dayWindows.1.0.end_time', '12:00')
            ->call('addDayRange', 1)
            ->assertSet('dayWindows.1.1', ['start_time' => '13:00', 'end_time' => '17:00'])
            ->call('copyDayToWeekdays', 1)
            ->assertSet('enabledDays.2', true)
            ->assertSet('enabledDays.3', true)
            ->assertSet('dayWindows.2', [
                ['start_time' => '08:00', 'end_time' => '12:00'],
                ['start_time' => '13:00', 'end_time' => '17:00'],
            ]);

        // End options strictly exclude <= start_time.
        $rows = collect($page->viewData('editorDayRows'));
        $mondayRange2EndOptions = array_keys($rows->firstWhere('day_of_week', 1)['ranges'][1]['end_options']);
        $this->assertNotContains('13:00', $mondayRange2EndOptions);
        $this->assertContains('13:30', $mondayRange2EndOptions);

        // Cancel resets unsaved draft cleanly.
        $page->call('cancelEdits')
            ->assertSet('availabilityMode', null)
            ->assertSet('enabledDays', []);
    }

    #[Test]
    public function legacy_unavailability_declarations_stay_readable_locked_for_faculty_and_reflect_registrar_updates(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $package = $this->activePackage($registrar);

        FacultyAvailabilityDeclaration::factory()->create([
            'term_id' => $package->term_id,
            'faculty_user_id' => $faculty->id,
            'version' => 1,
            'declaration' => FacultyAvailabilityDeclaration::DeclarationAvailable,
            'availability_mode' => null,
            'hard_unavailability' => [
                ['day_of_week' => 1, 'starts_at' => '13:00:00', 'ends_at' => '15:00:00'],
            ],
        ]);

        Livewire::actingAs($faculty)
            ->test(MyAvailability::class, ['termId' => $package->term_id])
            ->assertSee('Available except recorded unavailable times')
            ->assertSee('Recorded unavailable times')
            ->assertSee('1:00 PM–3:00 PM')
            ->assertSee('Availability submitted and locked for this term. Contact the Registrar if changes are needed.')
            ->assertDontSee('Save availability')
            ->assertDontSee('Save update')
            ->assertDontSee('Reason for update')
            ->set('availabilityMode', FacultyAvailabilityDeclaration::ModeAnyApproved)
            ->set('correctionReason', 'Attempted self-edit after submission')
            ->call('saveAvailability')
            ->assertStatus(409);

        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $package->term,
            faculty: $faculty,
            actor: $faculty,
            correctionReason: 'Registrar-applied update to full approved teaching hours',
            availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
        );

        Livewire::actingAs($faculty)
            ->test(MyAvailability::class, ['termId' => $package->term_id])
            ->assertSee('Available during all teaching hours')
            ->assertSee('Available across all approved school teaching hours.')
            ->assertSee('Registrar-applied update to full approved teaching hours')
            ->assertDontSee('Save availability');
    }

    #[Test]
    public function grid_change_surfaces_review_needed_banner_while_locked_and_reflects_updated_windows(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $package = $this->activePackage($registrar, [
            ['day_of_week' => 4, 'starts_at' => '07:30:00', 'ends_at' => '21:00:00', 'breaks' => [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']]],
        ]);

        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $package->term,
            faculty: $faculty,
            actor: $faculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeWindows,
            teachingWindows: [
                ['day_of_week' => 4, 'start_time' => '13:00', 'end_time' => '20:00'],
            ],
        );

        // Registrar narrows Thursday approved hours to end at 7:00 PM (19:00).
        $package->teachingGridRows()->where('day_of_week', 4)->update(['ends_at' => '19:00:00']);

        $page = Livewire::actingAs($faculty)
            ->test(MyAvailability::class, ['termId' => $package->term_id])
            ->assertSee('School teaching hours changed')
            ->assertSee('Some of your recorded teaching windows fall outside the updated school hours. Contact the Registrar if your availability needs adjustment.')
            ->assertSee('Outside approved hours')
            ->assertSee('Availability submitted and locked for this term. Contact the Registrar if changes are needed.')
            ->assertSee('1:00 PM–8:00 PM')
            ->assertDontSee('Save availability')
            ->assertSet('dayWindows.4.0', ['start_time' => '13:00', 'end_time' => '20:00']);

        $page
            ->set('dayWindows.4.0.end_time', '19:00')
            ->set('correctionReason', 'Attempted self-edit after grid change')
            ->call('saveAvailability')
            ->assertStatus(409);

        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $package->term,
            faculty: $faculty,
            actor: $faculty,
            correctionReason: 'Fitted Thursday window to the updated 7:00 PM closing hour',
            availabilityMode: FacultyAvailabilityDeclaration::ModeWindows,
            teachingWindows: [
                ['day_of_week' => 4, 'start_time' => '13:00', 'end_time' => '19:00'],
            ],
        );

        Livewire::actingAs($faculty)
            ->test(MyAvailability::class, ['termId' => $package->term_id])
            ->assertDontSee('School teaching hours changed')
            ->assertSee('1:00 PM–7:00 PM');
    }

    #[Test]
    public function faculty_schedule_uses_sentence_case_inline_exam_tray_clean_banners_and_no_clunky_dropdowns(): void
    {
        $this->assertSame('My schedule', FacultySchedule::getNavigationLabel());
        $this->assertSame('My availability', MyAvailability::getNavigationLabel());

        $fixture = $this->scheduleFixture();
        $faculty = $fixture['faculty'];
        $registrar = $fixture['registrar'];
        $term = $fixture['term'];
        $meeting = $fixture['meeting'];

        TermCalendarWindow::factory()->for($fixture['package'], 'package')->create([
            'window_type' => 'ExaminationPeriod',
            'opens_on' => '2026-04-20',
            'closes_on' => '2026-04-24',
        ]);

        // Initial availability, then late change conflicting with Monday 8:00 AM–11:00 AM meeting.
        $record = app(RecordFacultyAvailabilityDeclaration::class);
        $record->execute(
            term: $term,
            faculty: $faculty,
            actor: $faculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
        );
        $record->execute(
            term: $term,
            faculty: $faculty,
            actor: $faculty,
            correctionReason: 'Graduate classes on Monday mornings',
            availabilityMode: FacultyAvailabilityDeclaration::ModeCannotTeach,
        );

        // Also record a published schedule revision event affecting the same Faculty member.
        ScheduleRevisionEvent::query()->create([
            'term_id' => $term->id,
            'section_meeting_id' => $meeting->id,
            'change_type' => ScheduleRevisionEvent::ChangeTime,
            'reason' => 'Moved to align with lab availability.',
            'effective_date' => '2026-08-24',
            'changed_by' => $registrar->id,
            'old_snapshot_json' => [
                'faculty_user_id' => $faculty->id,
                'day_of_week' => 3,
                'starts_at' => '13:30:00',
                'ends_at' => '15:00:00',
            ],
            'new_snapshot_json' => [
                'faculty_user_id' => $faculty->id,
                'day_of_week' => 1,
                'starts_at' => '08:00:00',
                'ends_at' => '11:00:00',
            ],
        ]);

        Livewire::actingAs($faculty)
            ->test(MyAvailability::class, ['termId' => $term->id])
            ->assertSee('Some current classes conflict with your updated availability')
            ->assertSee('Continue teaching these classes until the Registrar publishes an updated schedule.')
            ->assertSee($fixture['course']->code)
            ->assertSee($fixture['section']->code)
            ->assertSee('View my schedule');

        $schedule = Livewire::actingAs($faculty)
            ->test(FacultySchedule::class, ['termId' => $term->id])
            ->assertSee('Affected by recent change')
            ->assertSee('Current classes stay as scheduled until the Registrar publishes an update.')
            ->assertSee('Under review')
            ->assertSee('Time Change')
            ->assertSee($fixture['course']->code)
            ->assertSee($fixture['section']->code)
            ->assertSee('Was Wednesday · 1:30 PM–3:00 PM → Now Monday · 8:00 AM–11:00 AM')
            ->assertSee('Effective Aug 24, 2026')
            ->assertSee('View availability')
            ->assertSee('Exam period')
            ->assertSee('Apr 20–Apr 24, 2026')
            ->assertSee('Arrange exam times directly with your classes.')
            ->assertSee('Weekly')
            ->assertSee('List')
            ->assertSee('Print schedule')
            ->assertDontSee('Examination source details')
            ->assertDontSee('Synthetic review data')
            ->assertDontSee('Recorded teaching window')
            ->assertDontSee('Schedule & Availability')
            ->assertDontSee('View My availability');

        $schedule->assertSeeHtml('href="'.e(MyAvailability::getUrl()).'"')
            ->assertSeeHtml('tala-timetable-tile-class')
            ->assertSeeHtml('>'.$fixture['section']->code.'</span>');

        // Filtering exposes Print filtered alongside Print all without a dropdown menu.
        $schedule->filterTable('day_of_week', 1)
            ->assertSee('Print filtered')
            ->assertSee('Print all');
        $schedule->call('clearScheduleFilters');

        // Meeting details modal displays course & class code directly without a <details> disclosure.
        $schedule->call('mountAction', 'viewMeeting', [], ['table' => true, 'recordKey' => (string) $meeting->id])
            ->assertSet('mountedActions.0.name', 'viewMeeting');
        $modalHtml = $schedule->instance()->getMountedAction()->getModalContent()->render();
        $this->assertStringContainsString($fixture['course']->code.' · '.$fixture['section']->code, $modalHtml);
        $this->assertStringNotContainsString('Record identifiers', $modalHtml);
        $this->assertStringNotContainsString('<details', $modalHtml);

        // Print output uses Faculty-accurate copy without Student Hub / bound-to jargon.
        $this->actingAs($faculty)
            ->get(route('faculty.schedule.print', ['timetable_version' => $fixture['timetable']->id]))
            ->assertOk()
            ->assertSee('Official teaching schedule for')
            ->assertSee('Check My schedule in TALA for any later updates.')
            ->assertDontSee('Student Hub')
            ->assertDontSee('bound to');
    }

    private function staff(string $role): User
    {
        $roleRecord = Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        if ($role === User::StaffRoleRegistrar) {
            $roleRecord->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'manage-schedules', 'guard_name' => 'web']));
        }

        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @param  list<array{day_of_week:int,starts_at:string,ends_at:string,breaks:list<array{starts_at:string,ends_at:string}>}>|null  $rows
     */
    private function activePackage(User $registrar, ?array $rows = null): TermCalendarPackage
    {
        $package = TermCalendarPackage::factory()->create();
        $rows ??= [
            ['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '17:00:00', 'breaks' => [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']]],
        ];

        foreach ($rows as $row) {
            $package->teachingGridRows()->create($row);
        }

        return app(ActivateTermCalendarPackage::class)->execute($package, $registrar);
    }

    /** @return array<string, mixed> */
    private function scheduleFixture(): array
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $package = $this->activePackage($registrar);
        $term = $package->term;
        $program = Program::factory()->create();
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => 'IT 312']);
        $specification = CourseSpecification::factory()->for($course)->create([
            'title' => 'Systems Integration and Architecture 1',
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);
        $component = CourseComponent::factory()->for($specification)->create();
        $entry = CurriculumEntry::factory()->for($curriculum)->for($specification, 'courseSpecification')->create();
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => TermOffering::StateScheduled,
            'expected_count' => 30,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'BSIT-3A',
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'expected_count' => 30,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        FacultyQualification::factory()->for($faculty, 'faculty')->for($course)->create();
        $demand = SchedulingDemand::factory()->for($offering)->for($component)->for($group)->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'validation_state' => SchedulingDemand::ValidationReadyForReview,
        ]);
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'requested_by' => $registrar->id,
            'input_snapshot' => ['scheduling_demands' => [['scheduling_demand_id' => $demand->id]]],
            'input_hash' => hash('sha256', 'cp1-faculty-ui-'.uniqid()),
            'solver_version' => 'cp1-faculty-ui-test',
            'published_by' => $registrar->id,
            'published_at' => now(),
            'publication_version' => 1,
        ]);
        $room = Room::factory()->create();
        $timetable = PublishedTimetableVersion::query()->create([
            'term_id' => $term->id,
            'schedule_run_id' => $run->id,
            'version' => 1,
            'state' => PublishedTimetableVersion::StatePublished,
            'authority_reference' => 'CP1-FACULTY-UI-OFFICIAL',
            'publication_reason' => 'CP1 Faculty UI test fixture.',
            'source_versions' => [],
            'impact_summary' => [],
            'content_hash' => hash('sha256', 'cp1-timetable-'.$run->id),
            'published_by' => $registrar->id,
            'published_at' => now(),
        ]);
        $meeting = SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'published_timetable_version_id' => $timetable->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '11:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionMeeting::StateActive,
            'published_at' => now(),
        ]);
        PublishedTimetableMeeting::query()->create([
            'published_timetable_version_id' => $timetable->id,
            'section_id' => $section->id,
            'scheduling_demand_id' => $demand->id,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'meeting_sequence' => 1,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '11:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'location_label' => $room->code,
        ]);

        return compact('registrar', 'faculty', 'package', 'term', 'course', 'section', 'meeting', 'timetable', 'run');
    }
}
