<?php

namespace Tests\Feature;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Scheduling\ReviewTimetableCandidate;
use App\Actions\Scheduling\ScheduleGenerationService;
use App\Actions\Scheduling\TermCoverageProjection;
use App\Filament\Pages\TermCalendar;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermOverview;
use App\Filament\Pages\TermPlanningWorkbench;
use App\Filament\Pages\TermResources;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Filament\Resources\FacultyQualifications\Pages\CreateFacultyQualification;
use App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ViewScheduleGenerationRun;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Filament\Resources\SchedulingDemands\SchedulingDemandResource;
use App\Filament\Resources\SectionMeetings\SectionMeetingResource;
use App\Filament\Resources\Sections\Pages\EditSection;
use App\Filament\Resources\Sections\Pages\ListSections;
use App\Filament\Resources\Sections\SectionResource;
use App\Filament\Resources\TermOfferings\Pages\ListTermOfferings;
use App\Filament\Resources\TermOfferings\TermOfferingResource;
use App\Filament\Resources\Terms\TermResource;
use App\Models\CandidateScheduleRow;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use App\Support\TimetablePresentation;
use Database\Seeders\Issue58MultiProgramExplorationSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class TermPlanningRedesignTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(config('database.test_database'), DB::connection()->getDatabaseName());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Mail::fake();

        foreach (User::staffRoleNames() as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_native_workspace_pages_render_exact_term_and_accessible_task_navigation(): void
    {
        $this->staff();
        $term = Term::factory()->create(['label' => 'Redesign exact Term']);
        foreach ([TermOverview::class, TermCalendar::class, TermClasses::class, TermResources::class, TermTimetable::class] as $page) {
            $component = Livewire::withQueryParams(['termId' => $term->id])->test($page)->assertSuccessful()
                ->assertSee('Redesign exact Term')->assertSee('Term summary')->assertSee('Calendar')
                ->assertSee('Classes & sections')->assertSee('Faculty & rooms')->assertSee('Timetable');
            $this->assertSame(1, substr_count($component->html(), 'wire:partial="action-modals"'));
        }
        Mail::assertNothingOutgoing();
    }

    public function test_three_programs_and_three_curriculum_years_are_counted_as_one_exact_term_scope(): void
    {
        $this->staff();
        $term = Term::factory()->create(['type' => Term::TypeFirstSemester]);
        $programIds = [];
        foreach (['HR', 'Education', 'Math'] as $name) {
            $program = Program::factory()->create(['name' => $name]);
            $programIds[] = $program->id;
            $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
            foreach (['First Year', 'Second Year', 'Third Year'] as $year) {
                $entry = CurriculumEntry::factory()->for($curriculum)->create(['year_level' => $year, 'term_type' => $term->type]);
                $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create();
                $class = Section::factory()->for($offering, 'termOffering')->create(['confirmed_at' => now()]);
                $cohort = TermCohort::factory()->for($term)->for($curriculum)->create(['program_id' => $program->id]);
                $class->cohorts()->attach($cohort, ['expected_count' => 30]);
                $group = SectionDeliveryGroup::factory()->for($class, 'section')->create();
                SchedulingDemand::factory()->for($group, 'sectionDeliveryGroup')->create(['term_offering_id' => $offering->id, 'meeting_count' => 2]);
            }
        }
        TermOffering::factory()->create();
        $projection = app(TermCoverageProjection::class)->forTerm($term);
        $this->assertCount(9, collect($projection['rows'])->whereIn('program_id', $programIds));
        $this->assertSame(9, $projection['summary']['class_count']);
        $this->assertSame(9, $projection['summary']['section_count']);
        $this->assertSame(18, $projection['summary']['meeting_count']);
        Livewire::withQueryParams(['termId' => $term->id])->test(TermOverview::class)
            ->assertSee('HR')->assertSee('Education')->assertSee('Math');
    }

    public function test_classes_program_and_year_presets_do_not_show_other_terms_or_other_years(): void
    {
        $this->staff();
        $term = Term::factory()->create();
        $curriculum = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateActive]);
        $entry = CurriculumEntry::factory()->for($curriculum)->create(['year_level' => 'Second Year', 'term_type' => $term->type]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create();
        $included = Section::factory()->for($offering, 'termOffering')->create();
        $otherYear = Section::factory()->for(TermOffering::factory()->for($term)->create(), 'termOffering')->create();
        $otherTerm = Section::factory()->create();
        Livewire::withQueryParams(['termId' => $term->id, 'programId' => $curriculum->program_id, 'yearLevel' => 'Second Year'])->test(TermClasses::class)
            ->assertCanSeeTableRecords([$included])->assertCanNotSeeTableRecords([$otherYear, $otherTerm]);
    }

    public function test_year_builder_presets_open_review_rows_and_class_creation_use_centered_dialogs(): void
    {
        $this->staff();
        $term = Term::factory()->create();
        $curriculum = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateActive]);
        $revision = CourseSpecification::factory()->create(['state' => CourseSpecification::StateActive]);
        CourseComponent::factory()->for($revision, 'courseSpecification')->create();
        CurriculumEntry::factory()->for($curriculum)->for($revision, 'courseSpecification')->create(['year_level' => 'Third Year', 'term_type' => $term->type]);
        $builder = Livewire::withQueryParams(['termId' => $term->id, 'programId' => $curriculum->program_id, 'curriculumVersionId' => $curriculum->id, 'yearLevel' => 'Third Year', 'action' => 'buildRegularOfferings'])
            ->test(ListTermOfferings::class)->assertSet('defaultAction', 'buildRegularOfferings')->assertSet('yearLevel', 'Third Year')
            ->mountAction('buildRegularOfferings')->assertActionMounted('buildRegularOfferings')
            ->assertActionDataSet(['term_id' => $term->id, 'curriculum_version_id' => $curriculum->id, 'year_level' => 'Third Year']);
        $rows = $builder->instance()->getSchema($builder->instance()->getMountedActionSchemaName())->getRawState()['rows'];
        $this->assertCount(1, $rows);
        $this->assertStringContainsString($revision->title, collect($rows)->first()['inherited_facts']);
        $this->assertFalse($builder->instance()->getMountedAction()->isModalSlideOver());
        $create = Livewire::withQueryParams(['termId' => $term->id, 'action' => 'create'])->test(ListSections::class)->assertSet('defaultAction', 'create')->mountAction('create')->assertActionMounted('create');
        $this->assertFalse($create->instance()->getMountedAction()->isModalSlideOver());
        $this->assertSame(0, TermOffering::query()->where('term_id', $term->id)->count());
    }

    public function test_old_links_route_to_the_correct_native_task_without_losing_term_or_official_selection(): void
    {
        $this->staff();
        $term = Term::factory()->create();
        foreach (['plan' => TermOverview::class, 'overview' => TermCalendar::class, 'classes' => TermClasses::class, 'resources' => TermResources::class, 'generate' => TermTimetable::class] as $tab => $page) {
            $this->assertSame($page::getUrl(['termId' => $term->id]), TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => $tab]));
        }
        $this->assertSame(TermTimetable::getUrl(['termId' => $term->id, 'timetableState' => 'official']), TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => 'published']));
        Livewire::withQueryParams(['termId' => $term->id, 'viewTab' => 'resources'])->test(TermPlanningWorkbench::class)
            ->assertRedirect(TermResources::getUrl(['termId' => $term->id, 'selectedRunId' => null, 'selectedVersionId' => null]));
    }

    public function test_wrong_term_version_is_rejected_and_academic_head_cannot_prepare_classes(): void
    {
        $this->staff(User::StaffRoleAcademicHead);
        $term = Term::factory()->create();
        $otherVersion = PublishedTimetableVersion::factory()->create();
        Livewire::withQueryParams(['termId' => $term->id, 'selectedVersionId' => $otherVersion->id])->test(TermTimetable::class)->assertSet('selectedVersionId', null);
        Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)->assertActionHidden('recordCohort')->assertActionHidden('prepareClassOffering');
        $this->staff(User::StaffRoleFaculty);
        $this->assertFalse(TermClasses::canAccess());
        $this->assertFalse(TermResources::canAccess());
    }

    public function test_overview_presets_can_be_changed_and_cleared_to_include_shared_classes_in_served_years(): void
    {
        $this->staff();
        $term = Term::factory()->create();
        $hr = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateActive]);
        $math = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateActive]);
        $revision = CourseSpecification::factory()->create(['state' => CourseSpecification::StateActive]);
        $hrEntry = CurriculumEntry::factory()->for($hr)->for($revision, 'courseSpecification')->create(['year_level' => 'First Year', 'term_type' => $term->type]);
        CurriculumEntry::factory()->for($math)->for($revision, 'courseSpecification')->create(['year_level' => 'Third Year', 'term_type' => $term->type]);
        $offering = TermOffering::factory()->for($term)->for($hrEntry, 'curriculumEntry')->create();
        $sharedClass = Section::factory()->for($offering, 'termOffering')->create(['course_specification_id' => $revision->id]);
        $cohort = TermCohort::factory()->for($term)->for($math)->create(['program_id' => $math->program_id]);
        $sharedClass->cohorts()->attach($cohort, ['expected_count' => 20]);
        $mathEntry = CurriculumEntry::factory()->for($math)->create(['year_level' => 'First Year', 'term_type' => $term->type]);
        $mathClass = Section::factory()->for(TermOffering::factory()->for($term)->for($mathEntry, 'curriculumEntry'), 'termOffering')->create();
        $otherTermClass = Section::factory()->create();

        $page = Livewire::withQueryParams(['termId' => $term->id, 'programId' => $hr->program_id, 'yearLevel' => 'First Year'])->test(TermClasses::class)
            ->assertCanSeeTableRecords([$sharedClass])->assertCanNotSeeTableRecords([$mathClass, $otherTermClass]);
        $this->assertArrayHasKey('Third Year', $page->instance()->getTable()->getFilter('year')->getOptions());
        $page->filterTable('program', $math->program_id)->filterTable('year', 'Third Year')
            ->assertCanSeeTableRecords([$sharedClass])->assertCanNotSeeTableRecords([$mathClass, $otherTermClass])
            ->removeTableFilters()->assertCanSeeTableRecords([$sharedClass, $mathClass])->assertCanNotSeeTableRecords([$otherTermClass]);
    }

    public function test_master_list_and_focused_section_show_the_same_served_year_meetings(): void
    {
        $registrar = $this->staff();
        $prefix = Issue58MultiProgramExplorationSeeder::Prefix.'-TEST-'.Str::lower(Str::random(8));
        $fixture = app(Issue58MultiProgramExplorationSeeder::class)->ensure($registrar, $prefix);
        $hrClass = Section::query()->where('code', $prefix.'-HR-2-CLASS')->sole();
        $mathCohort = TermCohort::query()->where('reference', $prefix.'-Math-2A')->sole();
        CurriculumEntry::query()->where('curriculum_version_id', $mathCohort->curriculum_version_id)
            ->where('course_specification_id', $hrClass->course_specification_id)->update(['year_level' => 'Third Year']);
        $run = ScheduleGenerationRun::findOrFail($fixture['draft_run_id']);
        $sharedIds = $run->candidateRows()->whereHas('schedulingDemand.sectionDeliveryGroup', fn ($query) => $query->where('section_id', $hrClass->id))->pluck('id')->map(fn ($id): string => (string) $id)->sort()->values()->all();
        $mathThird = Section::query()->where('code', $prefix.'-Math-3-CLASS')->sole();
        $thirdIds = $run->candidateRows()->whereHas('schedulingDemand.sectionDeliveryGroup', fn ($query) => $query->where('section_id', $mathThird->id))->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $expected = collect([...$sharedIds, ...$thirdIds])->sort()->values()->all();

        $page = Livewire::withQueryParams(['termId' => $fixture['term_id'], 'selectedRunId' => $run->id])->test(TermTimetable::class)
            ->set('programFilter', $mathCohort->program->name)->set('yearFilter', 'Third Year')
            ->assertViewHas('masterGroups', fn (array $groups): bool => collect($groups)->flatMap(fn (array $group): array => array_column($group['meetings'], 'id'))->unique()->sort()->values()->all() === $expected)
            ->assertViewHas('timetablePresentation', fn (array $presentation): bool => collect(array_column($presentation['meetings'], 'id'))->sort()->values()->all() === $expected);
        $this->assertSame(1, substr_count($page->html(), 'wire:partial="action-modals"'));
        $page->mountAction('expandTimetable')->assertMountedActionModalSee($mathCohort->reference)
            ->assertMountedActionModalDontSee($prefix.'-HR-2A')->unmountAction();
        $page->call('showTimetableView', 'list')->assertCanSeeTableRecords($expected);
        $this->assertSame(1, substr_count($page->html(), 'wire:partial="action-modals"'));
        $page->call('showTimetableView', 'section')->set('viewFilter', (string) $mathCohort->id)
            ->assertViewHas('timetablePresentation', fn (array $presentation): bool => collect(array_column($presentation['meetings'], 'id'))->sort()->values()->all() === $sharedIds);
        $this->assertSame(1, substr_count($page->html(), 'wire:partial="action-modals"'));
    }

    public function test_generation_from_official_view_selects_the_new_queued_draft_without_replacing_publication(): void
    {
        $registrar = $this->staff();
        $fixture = app(Issue58MultiProgramExplorationSeeder::class)->ensure($registrar, Issue58MultiProgramExplorationSeeder::Prefix.'-TEST-'.Str::lower(Str::random(8)));
        $term = Term::findOrFail($fixture['term_id']);
        app(ReviewTimetableCandidate::class)->reject(
            ScheduleGenerationRun::findOrFail($fixture['draft_run_id']),
            $registrar,
            'Reject the retained synthetic candidate before testing a new generation request.',
        );
        $queued = ScheduleGenerationRun::factory()->make(['term_id' => $term->id, 'requested_by' => $registrar->id, 'status' => ScheduleGenerationRun::StatusQueued]);
        $this->mock(ScheduleGenerationService::class)->shouldReceive('generate')->once()
            ->withArgs(fn (Term $selected, User $actor): bool => $selected->id === $term->id && $actor->id === $registrar->id)
            ->andReturnUsing(function () use ($queued): ScheduleGenerationRun {
                $queued->save();

                return $queued;
            });

        Livewire::withQueryParams(['termId' => $term->id, 'timetableState' => 'official', 'selectedVersionId' => $fixture['published_version_id']])->test(TermTimetable::class)
            ->set('programFilter', 'Previous program')->set('yearFilter', 'First Year')->set('viewFilter', 'Previous section')
            ->assertActionEnabled('generateTimetable')->callAction('generateTimetable')->assertHasNoActionErrors()
            ->assertSet('timetableState', 'draft')->assertSet('selectedRunId', $queued->id)
            ->assertSet('programFilter', null)->assertSet('yearFilter', null)->assertSet('viewFilter', null)
            ->assertViewHas('showOfficial', false)->assertSee('Generating this Term’s timetable')
            ->assertSee('wire:poll.5s.visible', false);
        $this->assertSame(PublishedTimetableVersion::StatePublished, PublishedTimetableVersion::findOrFail($fixture['published_version_id'])->state);
        Mail::assertNothingOutgoing();
    }

    public function test_native_legacy_resource_redirects_preserve_the_selected_term(): void
    {
        $this->staff();
        $term = Term::factory()->create();
        foreach ([
            'terms' => TermResource::class,
            'term-offerings' => TermOfferingResource::class,
            'sections' => SectionResource::class,
            'scheduling-demands' => SchedulingDemandResource::class,
            'rooms' => RoomResource::class,
            'faculty-qualifications' => FacultyQualificationResource::class,
            'faculty-term-load-overrides' => FacultyTermLoadOverrideResource::class,
            'calendar-events' => CalendarEventResource::class,
            'schedule-generation-runs' => ScheduleGenerationRunResource::class,
            'section-meetings' => SectionMeetingResource::class,
        ] as $slug => $resource) {
            $this->get('/admin/'.$slug.'?termId='.$term->id)->assertRedirect($resource::getUrl('index', ['termId' => $term->id]));
        }
    }

    public function test_main_navigation_routes_to_the_exact_term_and_marks_the_current_area_for_registrar_and_head(): void
    {
        foreach ([User::StaffRoleRegistrar, User::StaffRoleAcademicHead] as $role) {
            $this->staff($role);
            $term = Term::factory()->create();
            $areas = [
                'Term summary' => TermOverview::class,
                'Calendar' => TermCalendar::class,
                'Classes & sections' => TermClasses::class,
                'Faculty & rooms' => TermResources::class,
                'Timetable' => TermTimetable::class,
            ];
            $expectedUrls = [
                TermOverview::getUrl(['termId' => $term->id]),
                TermCalendar::getUrl(['termId' => $term->id]),
                TermClasses::getUrl(['termId' => $term->id]),
                TermResources::getUrl(['termId' => $term->id]),
                TermTimetable::getUrl(['termId' => $term->id]),
            ];

            foreach ($areas as $currentArea => $page) {
                $component = Livewire::withQueryParams(['termId' => $term->id])->test($page)->assertSuccessful();
                $navigation = collect($component->instance()->getWorkspaceNavigation());

                $this->assertSame(array_keys($areas), $navigation->map(fn ($item): string => $item->getLabel())->all());
                $this->assertSame($expectedUrls, $navigation->map(fn ($item): ?string => $item->getUrl())->all());
                $this->assertSame([$currentArea], $navigation->filter(fn ($item): bool => $item->isActive())->map(fn ($item): string => $item->getLabel())->values()->all());
            }
        }
    }

    public function test_official_timetable_output_returns_to_the_exact_version_for_authorized_staff(): void
    {
        foreach ([User::StaffRoleRegistrar, User::StaffRoleAcademicHead] as $role) {
            $this->staff($role);
            $version = PublishedTimetableVersion::factory()->create();
            PublishedTimetableMeeting::factory()->create(['published_timetable_version_id' => $version->id]);
            $returnUrl = TermTimetable::getUrl(['termId' => $version->term_id, 'selectedVersionId' => $version->id]);

            $this->get(route('timetable.version.print', $version))
                ->assertSee('Return to official timetable')
                ->assertSee($returnUrl)
                ->assertSee('Use Term Planning to confirm')
                ->assertDontSee('Use the Student Hub or Faculty Workspace');
        }
        $this->staff(User::StaffRoleFaculty);
        $this->get(route('timetable.version.print', $version))->assertForbidden();
    }

    public function test_timetable_evidence_returns_to_its_own_draft_or_official_version(): void
    {
        $this->staff();
        $term = Term::factory()->create();
        $draft = ScheduleGenerationRun::factory()->for($term)->create(['status' => ScheduleGenerationRun::StatusUnderReview]);
        $published = ScheduleGenerationRun::factory()->for($term)->create(['status' => ScheduleGenerationRun::StatusPublished]);
        $version = PublishedTimetableVersion::factory()->create(['term_id' => $term->id, 'schedule_run_id' => $published->id]);

        foreach ([$draft->id => ['selectedRunId' => $draft->id], $published->id => ['selectedVersionId' => $version->id]] as $runId => $selection) {
            $page = Livewire::withQueryParams(['termId' => $term->id])->test(ViewScheduleGenerationRun::class, ['record' => $runId]);
            $expected = TermTimetable::getUrl(['termId' => $term->id, ...$selection]);

            $this->assertSame($expected, $page->instance()->termPlanningReturnUrl());
            $this->assertSame('Timetable', $page->instance()->getBreadcrumbs()[$expected]);
            $items = collect($page->instance()->getWorkspaceNavigation());
            $this->assertSame($expected, $items->first(fn ($item): bool => $item->getLabel() === 'Timetable')->getUrl());
        }
    }

    public function test_explicit_official_entry_and_resource_tabs_preserve_term_scope(): void
    {
        $this->staff(User::StaffRoleAcademicHead);
        $term = Term::factory()->create();
        $version = PublishedTimetableVersion::factory()->create(['term_id' => $term->id]);
        Livewire::withQueryParams(['termId' => $term->id, 'selectedVersionId' => $version->id])->test(TermTimetable::class)
            ->assertSet('timetableState', 'official')->assertSet('selectedVersionId', $version->id);
        Livewire::withQueryParams(['termId' => $term->id])->test(TermResources::class)
            ->call('chooseResourceView', 'rooms')->assertSet('resourceView', 'rooms')->assertSet('termId', $term->id);
        Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)
            ->call('chooseClassesView', 'cohorts')->assertSet('classesView', 'cohorts')->assertSet('termId', $term->id);
    }

    public function test_head_inspects_exact_term_class_details_without_registrar_management_access(): void
    {
        $head = $this->staff(User::StaffRoleAcademicHead);
        $term = Term::factory()->create(['label' => 'Read-only class Term']);
        $offering = TermOffering::factory()->for($term)->create();
        $class = Section::factory()->for($offering, 'termOffering')->create(['code' => 'HEAD-READ-CLASS']);
        $otherClass = Section::factory()->create(['code' => 'OTHER-TERM-CLASS']);
        $group = SectionDeliveryGroup::factory()->for($class, 'section')->create(['name' => 'Shared lecture group']);
        SchedulingDemand::factory()->for($group, 'sectionDeliveryGroup')->create(['meeting_count' => 2, 'required_duration_minutes' => 60]);

        Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)
            ->assertCanSeeTableRecords([$class])->assertCanNotSeeTableRecords([$otherClass])
            ->assertTableActionHidden('manageClass', $class)
            ->mountTableAction('viewClass', $class)
            ->assertMountedActionModalSee('HEAD-READ-CLASS')
            ->assertMountedActionModalSee('Read-only class Term')
            ->assertMountedActionModalSee('Shared lecture group')
            ->assertMountedActionModalSee('2 × 60 minutes');

        $this->assertFalse($head->can('manage-schedules'));
        $this->assertFalse($head->can('update', $class));
        Livewire::test(EditSection::class, ['record' => $class->id])->assertForbidden();
        $this->assertSame('HEAD-READ-CLASS', $class->fresh()->code);
    }

    public function test_class_search_uses_the_visible_course_title_and_retains_exact_term_scope(): void
    {
        $this->staff(User::StaffRoleAcademicHead);
        $term = Term::factory()->create();
        $revision = CourseSpecification::factory()->create(['title' => 'Hospitality operations']);
        $offering = TermOffering::factory()->for($term)->create();
        $class = Section::factory()->for($offering, 'termOffering')->create(['code' => 'HR-CLASS', 'course_specification_id' => $revision->id]);
        $otherTerm = Section::factory()->create(['course_specification_id' => $revision->id]);
        Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)
            ->searchTable('Hospitality operations')->assertCanSeeTableRecords([$class])->assertCanNotSeeTableRecords([$otherTerm])
            ->searchTable('HR-CLASS')->assertCanSeeTableRecords([$class]);
    }

    public function test_linked_class_inspection_uses_all_term_scope_while_classes_filters_are_narrower(): void
    {
        $term = Term::factory()->create(['label' => 'Shared inspection Term']);
        $class = Section::factory()->for(TermOffering::factory()->for($term), 'termOffering')->create(['code' => 'SHARED-INSPECT']);
        $hr = TermCohort::factory()->for($term)->create(['reference' => 'HR-2A']);
        $math = TermCohort::factory()->for($term)->create(['reference' => 'Math-2A']);
        $class->cohorts()->attach([$hr->id => ['expected_count' => 18], $math->id => ['expected_count' => 18]]);
        $group = SectionDeliveryGroup::factory()->for($class, 'section')->create(['expected_count' => 36]);
        SchedulingDemand::factory()->for($group, 'sectionDeliveryGroup')->create(['meeting_count' => 2, 'required_duration_minutes' => 60]);
        $unrelatedProgram = Program::factory()->create();
        $otherTermClass = Section::factory()->create();

        foreach ([User::StaffRoleAcademicHead, User::StaffRoleRegistrar] as $role) {
            $this->staff($role);
            $page = Livewire::withQueryParams(['termId' => $term->id, 'programId' => $unrelatedProgram->id, 'classesView' => 'cohorts'])->test(TermClasses::class)
                ->assertCanNotSeeTableRecords([$class])->assertSee('HR-2A')->assertSee('Math-2A')
                ->mountAction('inspectLinkedClass', ['classId' => $class->id])
                ->assertMountedActionModalSee('SHARED-INSPECT')->assertMountedActionModalSee('HR-2A')->assertMountedActionModalSee('Math-2A')
                ->assertMountedActionModalSee('36 learners')->assertMountedActionModalSee('2 × 60 minutes');
            $page->unmountAction()->assertSet('classesView', 'cohorts')->assertSet('termId', $term->id)
                ->mountAction('inspectLinkedClass', ['classId' => $otherTermClass->id])->assertNotFound();
        }
    }

    public function test_qualification_course_picker_searches_approved_titles_and_preserves_course_identity(): void
    {
        $this->staff();
        $course = Course::factory()->create(['code' => 'QUAL-HR']);
        CourseSpecification::factory()->for($course)->create(['title' => 'Approved hospitality operations', 'state' => CourseSpecification::StateActive]);
        CourseSpecification::factory()->for($course)->create(['title' => 'Unapproved future hospitality title', 'state' => CourseSpecification::StateDraft]);
        $withoutApprovedTitle = Course::factory()->create(['code' => 'QUAL-DRAFT']);
        CourseSpecification::factory()->for($withoutApprovedTitle)->create(['title' => 'Draft-only picker title', 'state' => CourseSpecification::StateDraft]);
        Livewire::test(CreateFacultyQualification::class)
            ->fillForm(['course_id' => $course->id])
            ->assertFormFieldExists('course_id', function (Select $field) use ($course, $withoutApprovedTitle): bool {
                $this->assertSame([$course->id => 'Approved hospitality operations (QUAL-HR)'], $field->getSearchResults('Approved hospitality'));
                $this->assertSame('Approved hospitality operations (QUAL-HR)', $field->getOptionLabel());
                $this->assertArrayHasKey($withoutApprovedTitle->id, $field->getSearchResults('QUAL-DRAFT'));
                $this->assertSame([], $field->getSearchResults('Draft-only picker title'));

                return true;
            });
    }

    public function test_native_meeting_details_are_commands_without_a_false_toggle_state(): void
    {
        $row = new CandidateScheduleRow(['day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '09:00']);
        $row->setAttribute('id', 77)->setRelation('schedulingDemand', null);
        $run = new ScheduleGenerationRun;
        $presentation = TimetablePresentation::forCandidate($run, collect([$row]));
        $html = Blade::render('<x-tala-timetable :presentation="$presentation" controlled embedded native-details />', ['presentation' => $presentation]);
        $document = new \DOMDocument;
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $button = (new \DOMXPath($document))->query('//button[contains(concat(" ", normalize-space(@class), " "), " tala-timetable-meeting ")]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $button);
        $this->assertStringContainsString("mountAction('viewMeeting'", $button->getAttribute('wire:click'));
        $this->assertFalse($button->hasAttribute('x-bind:aria-pressed'));
        $this->assertFalse($button->hasAttribute('role'));
    }

    public function test_registrar_uses_the_same_class_details_dialog_and_separate_management_destination(): void
    {
        $this->staff();
        $term = Term::factory()->create();
        $class = Section::factory()->for(TermOffering::factory()->for($term), 'termOffering')->create(['code' => 'REG-CLASS-DETAIL']);
        Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)
            ->assertTableActionVisible('manageClass', $class)
            ->mountTableAction('viewClass', $class)->assertMountedActionModalSee('REG-CLASS-DETAIL');
    }

    private function staff(string $roleName = User::StaffRoleRegistrar): User
    {
        $role = Role::findOrCreate($roleName, 'web');
        if ($roleName === User::StaffRoleRegistrar) {
            $role->givePermissionTo(Permission::findOrCreate('manage-schedules', 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $this->actingAs($user)->withSession([WorkspaceContextResolver::SessionKey => $roleName]);

        return $user;
    }
}
