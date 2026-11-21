<?php

namespace Tests\Feature;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\PrepareClassOffering;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeclaration;
use App\Actions\Scheduling\RecordTermCohort;
use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermOverview;
use App\Filament\Pages\TermResources;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\FacultyQualifications\Pages\ListFacultyQualifications;
use App\Filament\Resources\Terms\Pages\ViewTerm;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\OperationalEvent;
use App\Models\Program;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\TermTeachingGridRow;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class Issue93RegistrarTermPlanningUiTest extends TestCase
{
    use DatabaseTransactions;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_term_overview_leads_with_late_impact_per_class_blocker_grid_review_and_calm_reassurance(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $calendar = $this->activeCalendar($term);
        $room = Room::factory()->create(['is_active' => true, 'room_type' => Room::TypeLectureRoom, 'capacity' => 50]);

        [$courseA, $offeringA, $cohortA] = $this->courseAndCohort($term, 'IT 312', 'Systems Integration 1', 'BSIT 3-A');
        [$courseB, $offeringB, $cohortB] = $this->courseAndCohort($term, 'IT 314', 'Web Systems 2', 'BSIT 3-B');

        $lateTeacher = $this->faculty('Maria Santos');
        $coveredTeacher = $this->faculty('Ana Dela Cruz');
        $missingTeacher = $this->faculty('Jose Reyes');
        $nonEssentialTeacher = $this->faculty('Noel Castillo');

        FacultyQualification::factory()->create(['faculty_user_id' => $lateTeacher->id, 'course_id' => $courseA->id]);
        FacultyQualification::factory()->create(['faculty_user_id' => $coveredTeacher->id, 'course_id' => $courseA->id]);
        FacultyQualification::factory()->create(['faculty_user_id' => $missingTeacher->id, 'course_id' => $courseB->id]);

        $nonEssentialCourse = Course::factory()->create(['code' => 'GE 109']);
        $nonEssentialSpec = CourseSpecification::factory()->for($nonEssentialCourse)->create(['state' => CourseSpecification::StateActive]);
        $nonEssentialEntry = CurriculumEntry::factory()->for($offeringA->curriculumEntry->curriculumVersion, 'curriculumVersion')->for($nonEssentialSpec, 'courseSpecification')->create(['term_type' => $term->type]);
        TermOffering::factory()->for($term)->for($nonEssentialEntry, 'curriculumEntry')->create(['state' => TermOffering::StatePendingScheduling]);
        FacultyQualification::factory()->create(['faculty_user_id' => $nonEssentialTeacher->id, 'course_id' => $nonEssentialCourse->id]);

        $classA = $this->confirmedClass($term, $offeringA, $cohortA, $registrar, 'IT312-A', $coveredTeacher);
        $classB = $this->confirmedClass($term, $offeringB, $cohortB, $registrar, 'IT314-B', $missingTeacher);

        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $coveredTeacher,
            actor: $coveredTeacher,
            availabilityMode: FacultyAvailabilityDeclaration::ModeWindows,
            teachingWindows: [['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00']],
        );

        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $lateTeacher,
            actor: $lateTeacher,
            availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
        );

        $run = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusPublished,
            'published_at' => now(),
            'published_by' => $registrar->id,
        ]);
        $officialVersion = PublishedTimetableVersion::factory()->for($term)->create([
            'schedule_run_id' => $run->id,
            'state' => PublishedTimetableVersion::StatePublished,
            'published_at' => now(),
        ]);

        $demandA = $classA->deliveryGroups()->firstOrFail()->schedulingDemands()->firstOrFail();
        SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'published_timetable_version_id' => $officialVersion->id,
            'scheduling_demand_id' => $demandA->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $lateTeacher->id,
            'room_id' => $room->id,
            'day_of_week' => 2,
            'starts_at' => '15:00:00',
            'ends_at' => '16:30:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionMeeting::StateActive,
            'published_at' => now(),
        ]);

        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $lateTeacher,
            actor: $lateTeacher,
            correctionReason: 'Graduate school classes on Tuesday afternoons',
            availabilityMode: FacultyAvailabilityDeclaration::ModeWindows,
            teachingWindows: [['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00']],
        );

        // Shrink Monday hours so coveredTeacher's window falls outside updated grid.
        $calendar->teachingGridRows()->where('day_of_week', 1)->update(['ends_at' => '11:00:00']);

        $officialRevisionUrl = TermTimetable::getUrl([
            'termId' => $term->id,
            'timetableState' => 'official',
            'selectedVersionId' => $officialVersion->id,
        ]);

        $overview = Livewire::withQueryParams(['termId' => $term->id])->test(TermOverview::class);
        $overview->assertSee('Needs your action')
            ->assertSee('Maria Santos changed availability after publication')
            ->assertSee('Graduate school classes on Tuesday afternoons')
            ->assertSee('IT 312')
            ->assertSee('Start timetable revision')
            ->assertSeeHtml('href="'.e($officialRevisionUrl).'"')
            ->assertSee('Class IT314-B (IT 314) requires Jose Reyes, who has No response for this Term.')
            ->assertSee('Teaching times outside updated hours for Ana Dela Cruz')
            ->assertSee('2 of 4')
            ->assertSee('faculty replied')
            ->assertSeeHtml('href="'.e(TermResources::getUrl(['termId' => $term->id])).'"')
            ->assertSeeHtml('href="'.e(TermClasses::getUrl(['termId' => $term->id])).'"');

        // Resolve blockers so only non-essential teacher remains No response -> calm reassurance appears.
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $missingTeacher,
            actor: $missingTeacher,
            availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
        );
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $coveredTeacher,
            actor: $coveredTeacher,
            correctionReason: 'Fit to updated hours',
            availabilityMode: FacultyAvailabilityDeclaration::ModeWindows,
            teachingWindows: [['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '11:00']],
        );
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $lateTeacher,
            actor: $lateTeacher,
            correctionReason: 'Fit to updated hours',
            availabilityMode: FacultyAvailabilityDeclaration::ModeWindows,
            teachingWindows: [['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '11:00']],
        );
        SectionMeeting::query()->where('faculty_user_id', $lateTeacher->id)->update(['state' => SectionMeeting::StateCancelled]);

        Livewire::withQueryParams(['termId' => $term->id])->test(TermOverview::class)
            ->assertSee('No blockers in classes or faculty replies')
            ->assertSee('Teachers with no response will not be scheduled for new assignments until they reply.')
            ->assertDontSee('Needs your action');
    }

    public function test_faculty_and_rooms_shows_unified_strip_collapsed_no_response_modes_cues_and_merged_reminder(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $calendar = $this->activeCalendar($term);
        [$course] = $this->courseAndCohort($term, 'CC 101', 'Computing 1', 'BSIT 1-A');

        $windowsFaculty = $this->faculty('Clara Windows');
        $anyFaculty = $this->faculty('Bram Any');
        $cannotFaculty = $this->faculty('Dante Cannot');
        $queuedNoReply = $this->faculty('Elena Queued');
        $failedNoReply = $this->faculty('Felix Failed');
        $notSentNoReply = $this->faculty('Gina Unsent');

        foreach ([$windowsFaculty, $anyFaculty, $cannotFaculty, $queuedNoReply, $failedNoReply, $notSentNoReply] as $f) {
            FacultyQualification::factory()->create(['faculty_user_id' => $f->id, 'course_id' => $course->id]);
        }

        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $windowsFaculty,
            actor: $windowsFaculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeWindows,
            teachingWindows: [['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00']],
        );
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $anyFaculty,
            actor: $anyFaculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
        );
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $cannotFaculty,
            actor: $cannotFaculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeCannotTeach,
        );

        // Shrink grid to trigger Review needed cue for Clara Windows.
        $calendar->teachingGridRows()->where('day_of_week', 1)->update(['ends_at' => '11:00:00']);

        OperationalEvent::factory()->create([
            'event_type' => OperationalEvent::TypeFacultyAvailabilityRequestedEmail,
            'related_record_type' => TermCalendarPackage::class,
            'related_record_id' => $calendar->id,
            'user_id' => $queuedNoReply->id,
            'status' => OperationalEvent::StatusPending,
        ]);
        OperationalEvent::factory()->create([
            'event_type' => OperationalEvent::TypeFacultyAvailabilityRequestedEmail,
            'related_record_type' => TermCalendarPackage::class,
            'related_record_id' => $calendar->id,
            'user_id' => $failedNoReply->id,
            'status' => OperationalEvent::StatusFailed,
            'failed_at' => now(),
        ]);

        $page = Livewire::withQueryParams(['termId' => $term->id])->test(TermResources::class)
            ->set('tableRecordsPerPage', 10);
        $page->assertSee('3 of 6')
            ->assertSee('faculty replied')
            ->assertSee('3 replied · 3 no response · 1 cannot teach · 1 review needed')
            ->assertSee('Faculty can already answer anytime while the teaching calendar is active.')
            ->assertSee('Choose teaching times')
            ->assertSee('Mon 8:00 AM–12:00 PM')
            ->assertSee('Any approved teaching time')
            ->assertSee('Cannot teach this Term')
            ->assertSee('Review needed')
            ->assertSee('No response')
            ->assertSee('Reminder sent')
            ->assertSee('Reminder failed')
            ->assertSee('Not sent')
            ->assertDontSee('Not requested')
            ->assertDontSee('Request queued')
            ->assertDontSee('Awaiting response')
            ->assertDontSee('Request failed')
            ->assertActionEnabled('sendAvailabilityReminder');

        // Filter by Review needed.
        $page->filterTable('availability_status', 'needs_review')
            ->assertSee('Clara Windows')
            ->assertDontSee('Bram Any');
        $page->filterTable('availability_status', 'all');

        // Merged Send reminder & due date works immediately without a prior deadline block!
        Mail::fake();
        $page->mountAction('sendAvailabilityReminder')
            ->assertMountedActionModalSee('Send reminder & due date')
            ->setActionData([
                'faculty_user_ids' => [$notSentNoReply->id],
                'due_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
                'authority_reference' => 'CP1 merged reminder test',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Due date saved and availability reminders queued');

        $this->assertDatabaseHas('faculty_availability_deadlines', [
            'term_id' => $term->id,
            'authority_reference' => 'CP1 merged reminder test',
        ]);
        $this->assertDatabaseHas('operational_events', [
            'event_type' => OperationalEvent::TypeFacultyAvailabilityRequestedEmail,
            'user_id' => $notSentNoReply->id,
            'related_record_id' => $calendar->id,
        ]);
        Mail::assertQueuedCount(1);
    }

    public function test_naming_and_term_infolist_history_cleanup(): void
    {
        $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $this->activeCalendar($term);

        $navLabels = collect(TermPlanningCluster::workspaceNavigation($term->id, 'classes'))
            ->map(fn ($item): string => $item->getLabel())
            ->all();
        $this->assertContains('Classes & sections', $navLabels);
        $this->assertNotContains('Sections & classes', $navLabels);

        $resourceList = Livewire::withQueryParams([
            'termId' => $term->id,
            'viewTab' => 'resources',
            'resourceView' => 'faculty',
        ])->test(ListFacultyQualifications::class)->instance();
        $breadcrumbs = $resourceList->getBreadcrumbs();
        $this->assertContains('Faculty & rooms', $breadcrumbs);
        $this->assertNotContains('Faculty availability', $breadcrumbs);

        Livewire::test(ViewTerm::class, ['record' => $term->id])
            ->assertSee('History')
            ->assertDontSee('Historical settings')
            ->assertDontSee('Calendar history');
    }

    private function staff(string $roleName): User
    {
        Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $role = Role::findOrCreate($roleName, 'web');
        if ($roleName === User::StaffRoleRegistrar) {
            $role->syncPermissions([
                Permission::findOrCreate('manage-schedules', 'web'),
                Permission::findOrCreate('approve-documents', 'web'),
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $this->actingAs($user)->withSession([WorkspaceContextResolver::SessionKey => $roleName]);

        return $user;
    }

    private function faculty(string $name): User
    {
        Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $parts = explode(' ', $name, 2);
        $user = User::factory()->create([
            ...User::staffNamePayload($parts[0], null, $parts[1] ?? 'Faculty'),
            'status' => User::StatusActive,
        ]);
        $user->assignRole(User::StaffRoleFaculty);

        return $user;
    }

    private function activeCalendar(Term $term): TermCalendarPackage
    {
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        foreach ([1, 2, 3, 4, 5] as $day) {
            TermTeachingGridRow::factory()->create([
                'term_calendar_package_id' => $package->id,
                'day_of_week' => $day,
                'starts_at' => '08:00:00',
                'ends_at' => '17:00:00',
                'breaks' => [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']],
            ]);
        }

        return $package;
    }

    /** @return array{0: Course, 1: TermOffering, 2: TermCohort} */
    private function courseAndCohort(Term $term, string $courseCode, string $courseTitle, string $cohortRef): array
    {
        $this->seq++;
        $program = Program::factory()->create(['code' => 'P-'.$this->seq]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => $courseCode]);
        $spec = CourseSpecification::factory()->for($course)->create(['state' => CourseSpecification::StateActive, 'title' => $courseTitle]);
        CourseComponent::factory()->for($spec)->create([
            'meeting_pattern' => '1x90m',
            'weekly_contact_hours' => 1.5,
            'room_type_default' => Room::TypeLectureRoom,
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($spec, 'courseSpecification')->create(['term_type' => $term->type]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create(['state' => TermOffering::StatePendingScheduling]);
        $cohort = app(RecordTermCohort::class)->execute($term, auth()->user(), [
            'curriculum_version_id' => $curriculum->id,
            'reference' => $cohortRef,
            'confirmed_count' => 30,
        ]);

        return [$course, $offering, $cohort];
    }

    private function confirmedClass(Term $term, TermOffering $offering, TermCohort $cohort, User $actor, string $code, User $fixedTeacher): Section
    {
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => $code,
            'capacity' => 30,
            'state' => Section::StatePlanned,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'Group '.$code,
            'expected_count' => 25,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $section = app(PrepareClassOffering::class)->execute($term, $section, $actor, [
            'class_reference' => $code,
            'source' => Section::SourceRegular,
            'delivery_mode' => TermOffering::ModalityFaceToFace,
        ]);
        $section = app(ConfirmClassOffering::class)->execute($section, $actor, [
            $cohort->id => 25,
        ]);
        $group->fresh()->update(['delivery_override' => ['fixed_faculty_user_id' => $fixedTeacher->id]]);
        app(GenerateSchedulingDemand::class)->forTerm($actor, $term);

        return $section->fresh();
    }
}
