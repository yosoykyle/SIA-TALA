<?php

namespace Tests\Feature;

use App\Actions\Scheduling\BuildOfficialScheduleOutput;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeclaration;
use App\Actions\Scheduling\ScheduleGenerationService;
use App\Filament\Pages\FacultySchedule;
use App\Filament\Pages\MyAvailability;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ListScheduleGenerationRuns;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ViewScheduleGenerationRun;
use App\Filament\Resources\SchedulingDemands\Pages\ListSchedulingDemands;
use App\Filament\Student\Pages\ScheduleView;
use App\Models\CorVersion;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationProposalVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\StudentScheduleBinding;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL96D3AMasterScheduleFunctionalHardeningTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame(env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());

        foreach ([
            'student',
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleFaculty,
            User::StaffRoleSystemSuperAdmin,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function scheduling_authority_keeps_registrar_mutations_academic_head_read_only_and_system_admin_on_integration_operations(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $systemAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $run = $this->createRun($registrar);

        $this->assertTrue(Gate::forUser($registrar)->allows('viewAny', SchedulingDemand::class));
        $this->assertTrue(Gate::forUser($registrar)->allows('create', SchedulingDemand::class));
        $this->assertTrue(Gate::forUser($registrar)->allows('viewAny', ScheduleGenerationRun::class));
        $this->assertTrue(Gate::forUser($registrar)->allows('create', ScheduleGenerationRun::class));
        $this->assertTrue(Gate::forUser($registrar)->allows('reviewCandidates', $run));

        $this->assertTrue(Gate::forUser($academicHead)->allows('viewAny', SchedulingDemand::class));
        $this->assertTrue(Gate::forUser($academicHead)->allows('viewAny', ScheduleGenerationRun::class));
        $this->assertFalse(Gate::forUser($academicHead)->allows('create', SchedulingDemand::class));
        $this->assertFalse(Gate::forUser($academicHead)->allows('create', ScheduleGenerationRun::class));
        $this->assertFalse(Gate::forUser($academicHead)->allows('reviewCandidates', $run));

        $this->assertFalse(Gate::forUser($systemAdmin)->allows('viewAny', SchedulingDemand::class));
        $this->assertFalse(Gate::forUser($systemAdmin)->allows('create', SchedulingDemand::class));
        $this->assertFalse(Gate::forUser($systemAdmin)->allows('viewAny', ScheduleGenerationRun::class));
        $this->assertFalse(Gate::forUser($systemAdmin)->allows('create', ScheduleGenerationRun::class));
        $this->assertFalse(Gate::forUser($systemAdmin)->allows('reviewCandidates', $run));
    }

    #[Test]
    public function unexpected_generation_and_dispatch_failures_do_not_disclose_internal_exception_messages(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();

        $this->app->instance(GenerateSchedulingDemand::class, new class extends GenerateSchedulingDemand
        {
            public function forTerm(User $actor, Term $term): array
            {
                throw new RuntimeException('C:\\private\\solver-token.json');
            }
        });

        Livewire::actingAs($registrar)
            ->test(ListSchedulingDemands::class)
            ->callAction(TestAction::make('generateForTerm')->table(), ['term_id' => $term->id])
            ->assertNotified(
                Notification::make()
                    ->title('Schedule requirement generation failed')
                    ->body('TALA could not generate the schedule requirements. Try again or ask the System Administrator to review the application log.')
                    ->danger()
                    ->persistent(),
            )
            ->assertDontSee('solver-token.json');

        $this->mock(ScheduleGenerationService::class)
            ->shouldReceive('generate')->once()
            ->andThrow(new RuntimeException('https://private-solver.example.test'));

        Livewire::actingAs($registrar)
            ->test(ListScheduleGenerationRuns::class)
            ->callAction('dispatchSolverRun', data: ['term_id' => $term->id])
            ->assertNotified(
                Notification::make()
                    ->title('Timetable generation failed')
                    ->body('TALA could not queue the timetable request. Try again or ask the System Administrator to review the application log.')
                    ->danger()
                    ->persistent(),
            )
            ->assertDontSee('private-solver.example.test');
    }

    #[Test]
    public function expected_readiness_and_duplicate_run_validation_remains_actionable(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();

        $this->app->instance(GenerateSchedulingDemand::class, new class extends GenerateSchedulingDemand
        {
            public function forTerm(User $actor, Term $term): array
            {
                throw ValidationException::withMessages([
                    'term_id' => 'Complete the Term scheduling window before generating demand.',
                ]);
            }
        });

        Livewire::actingAs($registrar)
            ->test(ListSchedulingDemands::class)
            ->callAction(TestAction::make('generateForTerm')->table(), ['term_id' => $term->id])
            ->assertNotified(
                Notification::make()
                    ->title('Schedule requirement generation blocked')
                    ->body('Complete the Term scheduling window before generating demand.')
                    ->danger()
                    ->persistent(),
            );

        $this->mock(ScheduleGenerationService::class)
            ->shouldReceive('generate')->once()
            ->andThrow(ValidationException::withMessages([
                'term_id' => 'Another queued or dispatching solver run already exists for this term.',
            ]));

        Livewire::actingAs($registrar)
            ->test(ListScheduleGenerationRuns::class)
            ->callAction('dispatchSolverRun', data: ['term_id' => $term->id])
            ->assertNotified(
                Notification::make()
                    ->title('Timetable generation blocked')
                    ->body('Another queued or dispatching solver run already exists for this term.')
                    ->danger()
                    ->persistent(),
            );
    }

    #[Test]
    public function run_view_explains_solution_quality_without_presenting_predictive_accuracy(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $run = $this->createRun($registrar, [
            'solver_result' => [
                'solver_status' => 'feasible',
                'summary' => [
                    'assigned_count' => 18,
                    'unassigned_count' => 2,
                    'hard_violation_count' => 0,
                    'warning_count' => 1,
                ],
                'solver_statistics' => [
                    'objective_value' => 12.5,
                    'best_objective_bound' => 10.0,
                    'relative_optimality_gap' => 0.2,
                    'wall_time_seconds' => 8.75,
                ],
            ],
        ]);

        Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $run->getRouteKey()])
            ->assertOk()
            ->assertSee('Schedule quality')
            ->assertSee('Feasible')
            ->assertSee('Independent completeness not established')
            ->assertDontSee('18 of 20 demands assigned')
            ->assertSee('The recorded outcome does not establish a usable current candidate. Inspect independent validation and source findings before review or publication.')
            ->assertSee('20.00%')
            ->assertSee('8.75 seconds')
            ->assertSee('optimization-quality measures')
            ->assertSee('not predictive accuracy or an accuracy score');
    }

    #[Test]
    public function faculty_and_student_can_print_only_their_current_official_schedule_and_access_is_logged(): void
    {
        $fixture = $this->scheduleFixture();
        $other = $this->scheduleFixture();

        Livewire::actingAs($fixture['faculty'])
            ->test(FacultySchedule::class)
            ->assertActionExists('printSchedule');

        $this->actingAs($fixture['faculty'])
            ->get(route('faculty.schedule.print'))
            ->assertOk()
            ->assertSee($fixture['course']->code)
            ->assertDontSee($other['course']->code)
            ->assertSee('Print or save PDF');

        $this->assertDatabaseHas('output_access_logs', [
            'output_type' => 'FACULTY_SCHEDULE',
            'source_record_type' => User::class,
            'source_record_id' => $fixture['faculty']->id,
            'actor_user_id' => $fixture['faculty']->id,
            'actor_role' => User::StaffRoleFaculty,
            'action' => 'PRINT',
            'copy_context' => 'FACULTY_COPY',
            'row_count' => 1,
            'status' => 'logged',
        ]);

        Livewire::actingAs($fixture['student'])
            ->test(ScheduleView::class)
            ->assertActionExists('printSchedule');

        $this->actingAs($fixture['student'])
            ->get(route('student.schedule.print'))
            ->assertOk()
            ->assertSee($fixture['course']->code)
            ->assertDontSee($other['course']->code)
            ->assertSee('Print or save PDF');

        $this->assertDatabaseHas('output_access_logs', [
            'output_type' => 'STUDENT_SCHEDULE',
            'source_record_type' => Enrollment::class,
            'source_record_id' => $fixture['enrollment']->id,
            'student_profile_id' => $fixture['profile']->id,
            'actor_user_id' => $fixture['student']->id,
            'actor_role' => 'student',
            'action' => 'PRINT',
            'copy_context' => 'STUDENT_COPY',
            'row_count' => 1,
            'status' => 'logged',
        ]);

        $this->actingAs($other['student'])
            ->get(route('faculty.schedule.print'))
            ->assertForbidden();
        $this->actingAs($other['faculty'])
            ->get(route('student.schedule.print'))
            ->assertForbidden();
    }

    #[Test]
    public function empty_schedule_print_outputs_are_clear_and_do_not_create_access_logs(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $student = $this->user('student');

        $this->actingAs($faculty)
            ->get(route('faculty.schedule.print'))
            ->assertOk()
            ->assertSee('No schedule is available for this exact timetable version.');

        $this->actingAs($student)
            ->get(route('student.schedule.print'))
            ->assertOk()
            ->assertSee('No schedule is available for this exact timetable version.');

        $this->assertDatabaseMissing('output_access_logs', [
            'actor_user_id' => $faculty->id,
            'output_type' => 'FACULTY_SCHEDULE',
            'action' => 'PRINT',
        ]);
        $this->assertDatabaseMissing('output_access_logs', [
            'actor_user_id' => $student->id,
            'output_type' => 'STUDENT_SCHEDULE',
            'action' => 'PRINT',
        ]);
    }

    #[Test]
    public function faculty_week_list_and_filtered_print_share_owned_criteria_and_full_print_keeps_all_meetings(): void
    {
        $fixture = $this->scheduleFixture();
        $other = $this->scheduleFixture();
        $tuesday = $fixture['meeting']->replicate();
        $tuesday->fill(['meeting_sequence' => 2, 'day_of_week' => 2])->save();
        $criteria = ['course_id' => $fixture['course']->id, 'class_id' => $fixture['section']->id, 'room_id' => $fixture['room']->id, 'day_of_week' => 2, 'modality' => TermOffering::ModalityFaceToFace, 'search' => $fixture['course']->code];
        $page = Livewire::actingAs($fixture['faculty'])->test(FacultySchedule::class)
            ->set('tableSearch', $criteria['search']);
        foreach (array_diff_key($criteria, ['search' => true]) as $field => $value) {
            $page->filterTable($field, $value);
        }
        $this->assertSame([$tuesday->id], $page->instance()->getFilteredTableQuery()->pluck('id')->all());
        $this->assertFalse($page->instance()->getTable()->isPaginated());
        $this->assertNotNull($page->instance()->getTable()->getContent());
        $page->assertSee('Tuesday')->callTableAction('listView')->assertSet('scheduleView', 'list')->assertCanSeeTableRecords([$tuesday])->assertCanNotSeeTableRecords([$fixture['meeting'], $other['meeting']]);
        $this->assertTrue($page->instance()->getTable()->isPaginated());
        $this->assertNull($page->instance()->getTable()->getContent());
        $this->assertSame([$tuesday->id], $page->instance()->getFilteredTableQuery()->pluck('id')->all());
        $page->callTableAction('weekView')->assertSet('scheduleView', 'week');
        $this->assertSame([$tuesday->id], $page->instance()->getFilteredTableQuery()->pluck('id')->all());
        $this->assertStringContainsString('day_of_week=2', $page->instance()->printFilteredScheduleAction()->getUrl());
        parse_str(parse_url($page->instance()->printScheduleAction()->getUrl(), PHP_URL_QUERY), $fullPrintContext);
        $this->assertSame('2', $fullPrintContext['return_filters']['day_of_week']);
        $this->assertSame($criteria['search'], $fullPrintContext['return_filters']['search']);
        $fullFromFilteredView = app(BuildOfficialScheduleOutput::class)->forFaculty($fixture['faculty'], Request::create('/', 'GET', $fullPrintContext));
        $this->assertCount(2, $fullFromFilteredView['rows']);
        $this->assertNull($fullFromFilteredView['filter_description']);
        parse_str(parse_url($fullFromFilteredView['back_url'], PHP_URL_QUERY), $fullReturnContext);
        $this->assertSame('2', $fullReturnContext['tableFilters']['day_of_week']['value']);
        $this->assertSame($criteria['search'], $fullReturnContext['tableSearch']);
        $this->assertSame('week', $fullReturnContext['scheduleView']);
        $restoredFull = Livewire::withQueryParams($fullReturnContext)->actingAs($fixture['faculty'])->test(FacultySchedule::class);
        $this->assertSame([$tuesday->id], $restoredFull->instance()->getFilteredTableQuery()->pluck('id')->all());
        $page->call('mountAction', 'viewMeeting', [], ['table' => true, 'recordKey' => (string) $tuesday->id])->assertSet('mountedActions.0.name', 'viewMeeting');
        $this->assertSame('Class meeting', $page->instance()->getMountedAction()->getModalHeading());
        $this->assertStringContainsString('Location', $page->instance()->getMountedAction()->getModalContent()->render());
        $page->call('unmountAction');
        $page->call('mountAction', 'viewMeeting', [], ['table' => true, 'recordKey' => (string) $other['meeting']->id])->assertSet('mountedActions', []);
        $page->filterTable('day_of_week', 7)->assertSee('No meetings match your filters')->assertSee('Clear filters and search');
        $page->call('clearScheduleFilters');
        $this->assertCount(2, $page->instance()->getFilteredTableQuery()->get());

        $output = app(BuildOfficialScheduleOutput::class)->forFaculty($fixture['faculty'], Request::create('/', 'GET', ['timetable_version' => $fixture['timetable']->id, 'scope' => 'filtered', 'view' => 'list', ...$criteria]));
        $this->assertCount(1, $output['rows']);
        $this->assertSame('Tuesday', $output['rows'][0]['day']);
        $this->assertSame($fixture['course']->code, $output['rows'][0]['course']);
        $this->assertSame('Faculty Filtered Schedule', $output['title']);
        $this->assertStringContainsString('Day: Tuesday', $output['filter_description']);
        parse_str(parse_url($output['back_url'], PHP_URL_QUERY), $returnContext);
        $this->assertSame((string) $fixture['term']->id, $returnContext['termId']);
        $this->assertSame('2', $returnContext['tableFilters']['day_of_week']['value']);
        $this->assertSame($criteria['search'], $returnContext['tableSearch']);
        $restored = Livewire::withQueryParams($returnContext)->actingAs($fixture['faculty'])->test(FacultySchedule::class)->assertSet('scheduleView', 'list');
        $this->assertSame([$tuesday->id], $restored->instance()->getFilteredTableQuery()->pluck('id')->all());
        $this->assertSame('Back to My Schedule', $output['back_label']);
        $this->assertDatabaseHas('output_access_logs', ['output_type' => 'FACULTY_SCHEDULE', 'actor_user_id' => $fixture['faculty']->id, 'row_count' => 1]);

        $full = app(BuildOfficialScheduleOutput::class)->forFaculty($fixture['faculty'], Request::create('/', 'GET', ['timetable_version' => $fixture['timetable']->id]));
        $this->assertCount(2, $full['rows']);
        $this->assertNull($full['filter_description']);
        $tampered = app(BuildOfficialScheduleOutput::class)->forFaculty($fixture['faculty'], Request::create('/', 'GET', ['timetable_version' => $other['timetable']->id, 'scope' => 'filtered', 'faculty_user_id' => $other['faculty']->id, 'meeting_ids' => [$other['meeting']->id]]));
        $this->assertSame([], $tampered['rows']);
        $this->actingAs($fixture['faculty'])->getJson(route('faculty.schedule.print', ['scope' => 'filtered', 'day_of_week' => 8]))->assertUnprocessable();
        $this->actingAs($fixture['faculty'])->getJson(route('faculty.schedule.print', ['timetable_version' => $fixture['timetable']->id, 'return_filters' => ['day_of_week' => 8]]))->assertUnprocessable();
    }

    #[Test]
    public function faculty_schedule_distinguishes_unselected_and_unassigned_terms_and_keeps_figures_unfiltered(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $emptyTerm = Term::factory()->create();

        Livewire::actingAs($faculty)->test(FacultySchedule::class)
            ->assertSee('Choose a Term to view your schedule')
            ->assertDontSee('No official meetings assigned in this Term')
            ->assertDontSee('Search courses, classes or rooms')
            ->call('selectTerm', $emptyTerm->id)
            ->assertSee('No official meetings assigned in this Term')
            ->assertDontSee('Weekly meetings');

        $fixture = $this->scheduleFixture();
        $tuesday = $fixture['meeting']->replicate();
        $tuesday->fill(['meeting_sequence' => 2, 'day_of_week' => 2])->save();

        Livewire::actingAs($fixture['faculty'])->test(FacultySchedule::class, ['termId' => $fixture['term']->id])
            ->filterTable('day_of_week', 2)
            ->assertSeeHtml('<dt>Classes</dt><dd>1</dd>')
            ->assertSeeHtml('<dt>Weekly meetings</dt><dd>2</dd>')
            ->assertSeeHtml('<dt>Teaching days</dt><dd>2</dd>')
            ->assertSee('Showing selected meetings:')
            ->assertSee('Tuesday');
    }

    #[Test]
    public function faculty_course_title_search_matches_linked_specification_and_same_owned_output(): void
    {
        $fixture = $this->scheduleFixture();
        $other = $this->scheduleFixture();
        $specification = $fixture['meeting']->schedulingDemand->termOffering->curriculumEntry->courseSpecification;
        $specification->update(['title' => 'Applied Research 100%_Studio']);
        CourseSpecification::factory()->for($fixture['course'])->create(['title' => 'Unassociated special title']);
        $second = $fixture['meeting']->replicate();
        $second->fill(['meeting_sequence' => 2, 'day_of_week' => 2])->save();
        $wrongActor = $fixture['meeting']->replicate();
        $wrongActor->fill(['meeting_sequence' => 3, 'faculty_user_id' => $other['faculty']->id])->save();
        $other['meeting']->update(['faculty_user_id' => $fixture['faculty']->id]);
        $other['meeting']->schedulingDemand->termOffering->curriculumEntry->courseSpecification->update(['title' => 'Applied Research 100%_Studio']);

        $page = Livewire::actingAs($fixture['faculty'])->test(FacultySchedule::class, ['termId' => $fixture['term']->id])->set('tableSearch', 'Applied Research');
        $this->assertEqualsCanonicalizing([$fixture['meeting']->id, $second->id], $page->instance()->getFilteredTableQuery()->pluck('id')->all());
        $page->callTableAction('listView')->assertCanSeeTableRecords([$fixture['meeting'], $second])->assertCanNotSeeTableRecords([$wrongActor, $other['meeting']]);
        $output = app(BuildOfficialScheduleOutput::class)->forFaculty($fixture['faculty'], Request::create('/', 'GET', ['timetable_version' => $fixture['timetable']->id, 'scope' => 'filtered', 'search' => 'Applied Research']));
        $this->assertCount(2, $output['rows']);
        $this->assertStringContainsString('Search: Applied Research', $output['filter_description']);
        $page->set('tableSearch', '100%_');
        $this->assertCount(2, $page->instance()->getFilteredTableQuery()->get());
        $page->set('tableSearch', 'Unassociated special title');
        $this->assertSame([], $page->instance()->getFilteredTableQuery()->pluck('id')->all());
        $unlinkedOutput = app(BuildOfficialScheduleOutput::class)->forFaculty($fixture['faculty'], Request::create('/', 'GET', ['timetable_version' => $fixture['timetable']->id, 'scope' => 'filtered', 'search' => 'Unassociated special title']));
        $this->assertSame([], $unlinkedOutput['rows']);
    }

    #[Test]
    public function faculty_whole_term_unavailability_flags_only_own_exact_term_official_assignments(): void
    {
        $fixture = $this->scheduleFixture();
        $other = $this->scheduleFixture();
        $other['meeting']->update(['faculty_user_id' => $fixture['faculty']->id]);
        $sameTermOtherFaculty = $fixture['meeting']->replicate();
        $sameTermOtherFaculty->fill(['faculty_user_id' => $other['faculty']->id, 'meeting_sequence' => 3])->save();
        $pending = ScheduleGenerationRun::factory()->create(['term_id' => $fixture['term']->id, 'status' => ScheduleGenerationRun::StatusUnderReview]);
        $record = app(RecordFacultyAvailabilityDeclaration::class);
        $record->execute($fixture['term'], $fixture['faculty'], $fixture['faculty'], FacultyAvailabilityDeclaration::DeclarationAvailable, []);
        $corrected = $record->execute($fixture['term'], $fixture['faculty'], $fixture['faculty'], FacultyAvailabilityDeclaration::DeclarationUnavailable, [], 'Cannot teach this Term');

        $impact = DB::table('activity_log')->where('event', 'faculty_availability_revision_required')->where('subject_id', $corrected->id)->first();
        $this->assertNotNull($impact);
        $properties = json_decode($impact->properties, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([$fixture['meeting']->id], $properties['affected_section_meeting_ids']);
        $this->assertSame('Stale', $pending->fresh()->candidate_state);
        $this->assertTrue(SectionMeeting::query()->activeOfficial()->whereKey($fixture['meeting']->id)->exists());
        $this->assertModelExists($other['meeting']);
        $this->assertModelExists($sameTermOtherFaculty);
        Livewire::actingAs($fixture['faculty'])->test(MyAvailability::class, ['termId' => $fixture['term']->id])->assertSee('Official timetable review required.');
        $record->execute($fixture['term'], $fixture['faculty'], $fixture['faculty'], FacultyAvailabilityDeclaration::DeclarationAvailable, [], 'Can teach after all');
        Livewire::actingAs($fixture['faculty'])->test(MyAvailability::class, ['termId' => $fixture['term']->id])->assertDontSee('Official timetable review required.');
        $record->execute($fixture['term'], $fixture['faculty'], $fixture['faculty'], FacultyAvailabilityDeclaration::DeclarationUnavailable, [], 'Cannot teach this Term again');
        $fixture['timetable']->update(['state' => PublishedTimetableVersion::StateSuperseded]);
        Livewire::actingAs($fixture['faculty'])->test(MyAvailability::class, ['termId' => $fixture['term']->id])->assertDontSee('Official timetable review required.');
        $this->assertDatabaseHas('activity_log', ['event' => 'faculty_availability_revision_required', 'subject_id' => $corrected->id]);
    }

    private function createRun(User $registrar, array $diagnostics = []): ScheduleGenerationRun
    {
        return ScheduleGenerationRun::query()->create([
            'term_id' => Term::factory()->create()->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'requested_by' => $registrar->id,
            'input_snapshot' => ['contract_version' => 'tal94-demand-v2'],
            'input_hash' => hash('sha256', 'tal-96d3a-'.uniqid()),
            'solver_version' => 'tal-96d3a-test',
            'runtime_ms' => 8750,
            'objective_value' => 12.5,
            'diagnostics' => $diagnostics,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleFixture(): array
    {
        $student = $this->user('student');
        $faculty = $this->staff(User::StaffRoleFaculty);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create();
        $profile = StudentProfile::factory()->for($student)->for($program)->create();
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'state' => CurriculumVersion::StateActive,
        ]);
        $profile->update(['curriculum_version_id' => $curriculum->id]);
        $course = Course::factory()->create();
        $specification = CourseSpecification::factory()->for($course)->create([
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);
        $component = CourseComponent::factory()->for($specification)->create();
        $entry = CurriculumEntry::factory()
            ->for($curriculum)
            ->for($specification, 'courseSpecification')
            ->create();
        $offering = TermOffering::factory()
            ->for($term)
            ->for($entry, 'curriculumEntry')
            ->create([
                'modality' => TermOffering::ModalityFaceToFace,
                'state' => TermOffering::StateScheduled,
                'expected_count' => 30,
            ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'expected_count' => 30,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $enrollment = Enrollment::factory()->for($profile)->for($term)->create([
            'status' => 'officially_enrolled',
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'registered_at' => now(),
            'officially_enrolled_at' => now(),
        ]);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);
        FacultyQualification::factory()->for($faculty, 'faculty')->for($course)->create();
        $demand = SchedulingDemand::factory()
            ->for($offering)
            ->for($component)
            ->for($group)
            ->create([
                'modality' => TermOffering::ModalityFaceToFace,
                'validation_state' => SchedulingDemand::ValidationReadyForReview,
            ]);
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'requested_by' => $registrar->id,
            'input_snapshot' => ['scheduling_demands' => [['scheduling_demand_id' => $demand->id]]],
            'input_hash' => hash('sha256', 'print-'.uniqid()),
            'solver_version' => 'tal-96d3a-test',
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
            'authority_reference' => 'TAL-96D3A-PUBLISHED-SCHEDULE',
            'publication_reason' => 'Canonical functional hardening fixture.',
            'source_versions' => [],
            'impact_summary' => [],
            'content_hash' => hash('sha256', 'tal-96d3a-'.$run->id),
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
        $courseEnrollment->update([
            'section_id' => $section->id,
            'published_timetable_version_id' => $timetable->id,
            'is_current' => true,
        ]);
        StudentScheduleBinding::query()->create([
            'course_enrollment_id' => $courseEnrollment->id,
            'section_meeting_id' => $meeting->id,
            'is_active' => true,
            'effective_from' => now()->toDateString(),
            'source' => StudentScheduleBinding::SourceRegistrarPlacement,
        ]);
        $proposal = RegistrationProposalVersion::factory()->for($enrollment)->create([
            'state' => RegistrationProposalVersion::StateConfirmed,
            'published_timetable_version_id' => $timetable->id,
            'curriculum_version_id' => $curriculum->id,
            'prepared_by' => $registrar->id,
        ]);
        $cor = CorVersion::factory()->for($enrollment)->create([
            'registration_proposal_version_id' => $proposal->id,
            'published_timetable_version_id' => $timetable->id,
            'issued_by' => $registrar->id,
            'snapshot' => [
                'student_number' => $profile->student_number,
                'student_name' => $student->name,
                'program_id' => $program->id,
                'program_code' => $program->code,
                'curriculum_version_id' => $curriculum->id,
                'term_label' => $term->label,
                'published_timetable_version_id' => $timetable->id,
                'fees' => [],
                'courses' => [],
            ],
        ]);
        $enrollment->update([
            'current_proposal_version_id' => $proposal->id,
            'current_cor_version_id' => $cor->id,
        ]);

        return compact(
            'student',
            'faculty',
            'profile',
            'term',
            'course',
            'enrollment',
            'meeting',
            'timetable',
            'room',
            'section',
        );
    }

    private function staff(string $role): User
    {
        return $this->user($role);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
