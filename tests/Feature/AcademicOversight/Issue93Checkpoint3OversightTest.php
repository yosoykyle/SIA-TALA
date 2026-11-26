<?php

namespace Tests\Feature\AcademicOversight;

use App\Actions\AcademicSetup\AcademicReadinessService;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Grades\CurrentClassRosterOutput;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeclaration;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\CatalogCurriculaWorkbench;
use App\Filament\Pages\CompletionAndTor;
use App\Filament\Pages\TermOverview;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\AcademicYears\Pages\ListAcademicYears;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Filament\Resources\GradeRosters\Pages\ListGradeRosters;
use App\Filament\Resources\GradeRosters\Pages\ViewGradeRoster;
use App\Filament\Resources\StudentProfiles\Pages\ListStudentProfiles;
use App\Filament\Resources\StudentProfiles\Pages\ViewStudentProfile;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\Program;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;
use App\Models\TermTeachingGridRow;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Issue93Checkpoint3OversightTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame(config('database.test_database'), DB::connection()->getDatabaseName());

        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleFaculty,
            User::StaffRoleAccounting,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function academic_readiness_service_prioritizes_active_curriculum_and_separates_successor_draft_status(): void
    {
        $programWithBoth = Program::factory()->create(['code' => 'CP3-BOTH', 'name' => 'BS Information Systems']);
        $active = CurriculumVersion::factory()->for($programWithBoth)->create([
            'name' => 'BSIS 2024 Active',
            'state' => CurriculumVersion::StateActive,
            'created_at' => now()->subDays(5),
        ]);
        $activeSpec = $this->readySpecification();
        CurriculumEntry::factory()->for($active)->for($activeSpec)->create(['year_level' => '1']);

        $successorDraft = CurriculumVersion::factory()->for($programWithBoth)->create([
            'name' => 'BSIS 2027 Successor Draft',
            'state' => CurriculumVersion::StateDraft,
            'created_at' => now(),
        ]);

        $draftOnlyProgram = Program::factory()->create(['code' => 'CP3-DRAFT', 'name' => 'BS Data Science']);
        $draftOnly = CurriculumVersion::factory()->for($draftOnlyProgram)->create([
            'name' => 'BSDS 2027 Draft',
            'state' => CurriculumVersion::StateDraft,
        ]);

        $service = app(AcademicReadinessService::class);

        $this->assertSame($active->id, $service->currentCurriculum($programWithBoth)?->id);
        $this->assertSame($successorDraft->id, $service->successorDraft($programWithBoth)?->id);

        $readinessBoth = $service->programReadiness($programWithBoth);
        $this->assertSame('Active', $readinessBoth['status']);
        $this->assertSame('success', $readinessBoth['color']);
        $this->assertNotNull($readinessBoth['successor_draft']);
        $this->assertSame($successorDraft->id, $readinessBoth['successor_draft']['id']);
        $this->assertSame('BSIS 2027 Successor Draft', $readinessBoth['successor_draft']['name']);
        $this->assertSame('Draft needs subjects', $readinessBoth['successor_draft']['status']);

        $this->assertSame($draftOnly->id, $service->currentCurriculum($draftOnlyProgram)?->id);
        $this->assertNull($service->successorDraft($draftOnlyProgram));

        $readinessDraftOnly = $service->programReadiness($draftOnlyProgram);
        $this->assertSame('Draft needs subjects', $readinessDraftOnly['status']);
        $this->assertNull($readinessDraftOnly['successor_draft']);
    }

    #[Test]
    public function academic_approvals_places_attention_first_surfaces_term_blockers_and_links_completion_and_tor(): void
    {
        $head = $this->staff([User::StaffRoleAcademicHead]);
        $registrar = $this->staff([User::StaffRoleRegistrar]);
        $faculty = $this->staff([User::StaffRoleFaculty], 'Prof. Clara');

        $term = Term::factory()->create(['label' => '1st Sem CP3']);
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'classes_start_on' => '2026-08-17',
            'classes_end_on' => '2026-12-11',
        ]);
        TermTeachingGridRow::query()->create([
            'term_calendar_package_id' => $package->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '17:00:00',
            'breaks' => [],
        ]);
        TermTeachingGridRow::query()->create([
            'term_calendar_package_id' => $package->id,
            'day_of_week' => 2,
            'starts_at' => '08:00:00',
            'ends_at' => '17:00:00',
            'breaks' => [],
        ]);

        // Program with BOTH Active curriculum + Successor Draft
        $program = Program::factory()->create(['code' => 'CP3-BSIT', 'name' => 'BS Information Technology']);
        $activeCurriculum = CurriculumVersion::factory()->for($program)->create([
            'name' => 'BSIT Active 2025',
            'state' => CurriculumVersion::StateActive,
        ]);
        $spec = $this->readySpecification();
        $entry = CurriculumEntry::factory()->for($activeCurriculum)->for($spec)->create(['year_level' => '1']);
        $successorDraft = CurriculumVersion::factory()->for($program)->create([
            'name' => 'BSIT Successor 2027',
            'state' => CurriculumVersion::StateDraft,
        ]);

        // Selected-term returned and overdue grade rosters
        $offering = TermOffering::factory()->for($term)->create([
            'curriculum_entry_id' => $entry->id,
        ]);
        GradeRoster::factory()->for($offering, 'termOffering')->create([
            'state' => GradeRoster::StateReturned,
        ]);
        GradeRoster::factory()->for($offering, 'termOffering')->create([
            'state' => GradeRoster::StateLateNotSubmitted,
        ]);

        // Official timetable + late faculty availability revision impact
        $publishedRun = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'requested_by' => $registrar->id,
            'input_snapshot' => [],
            'input_hash' => hash('sha256', 'cp3-pub-'.uniqid()),
            'solver_version' => 'cp3-test',
            'published_by' => $registrar->id,
            'published_at' => now()->subDay(),
            'publication_version' => 1,
        ]);
        $publishedVersion = PublishedTimetableVersion::query()->create([
            'term_id' => $term->id,
            'schedule_run_id' => $publishedRun->id,
            'version' => 1,
            'state' => PublishedTimetableVersion::StatePublished,
            'authority_reference' => 'CP3-PUB-1',
            'publication_reason' => 'Initial publication',
            'source_versions' => [],
            'impact_summary' => [],
            'content_hash' => hash('sha256', 'cp3-ver-'.uniqid()),
            'published_by' => $registrar->id,
            'published_at' => now()->subDay(),
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create(['code' => 'BSIT-1A']);
        $group = SectionDeliveryGroup::query()->create([
            'section_id' => $section->id,
            'name' => 'Whole section',
            'expected_count' => 30,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $component = $spec->components->firstOrFail();
        $demand = SchedulingDemand::factory()
            ->for($offering, 'termOffering')
            ->for($group, 'sectionDeliveryGroup')
            ->for($component, 'courseComponent')
            ->create([
                'modality' => TermOffering::ModalityFaceToFace,
            ]);
        $room = Room::factory()->create();
        SectionMeeting::query()->create([
            'schedule_run_id' => $publishedRun->id,
            'published_timetable_version_id' => $publishedVersion->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'day_of_week' => 2,
            'starts_at' => '10:00:00',
            'ends_at' => '11:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionMeeting::StateActive,
            'published_at' => now()->subDay(),
        ]);
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $faculty,
            actor: $faculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeWindows,
            teachingWindows: [['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00']],
        );

        // Latest timetable generation run is blocked
        ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusBlocked,
            'requested_by' => $registrar->id,
            'input_snapshot' => [],
            'input_hash' => hash('sha256', 'cp3-blk-'.uniqid()),
            'solver_version' => 'cp3-test',
            'diagnostics' => ['solver_result' => ['solver_status' => 'infeasible']],
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($head)->test(AcademicApprovals::class, ['termId' => $term->id])
            ->assertOk()
            ->assertSee('Calendar')
            ->assertSee('Classes & sections')
            ->assertSee('Faculty & rooms')
            ->assertSee('Timetable')
            ->assertSee('Programs & Curricula')
            ->assertSee('Lifecycle & Completion')
            ->assertSee('Completion & TOR')
            ->assertSee('Class rosters')
            ->assertSee(CompletionAndTor::getUrl(['oversightTermId' => $term->id]), false)
            ->assertSee('BSIT Active 2025')
            ->assertSee('BSIT Successor 2027')
            ->assertViewHas('curriculumSummary', fn (array $summary): bool => $summary['ready'] >= 1 && $summary['successor_drafts'] >= 1)
            ->assertViewHas('attention', function (array $findings) use ($term, $successorDraft, $publishedVersion): bool {
                $collection = collect($findings);

                $hasReturnedRoster = $collection->contains(fn (array $f): bool => $f['scope'] === 'Selected term'
                    && str_contains($f['title'], 'returned class roster')
                    && $f['owner'] === 'Faculty'
                    && str_contains($f['url'], 'oversightTermId='.$term->id));

                $hasOverdueRoster = $collection->contains(fn (array $f): bool => $f['scope'] === 'Selected term'
                    && str_contains($f['title'], 'overdue class roster')
                    && str_contains($f['owner'], 'Registrar'));

                $hasBlockedRun = $collection->contains(fn (array $f): bool => $f['scope'] === 'Selected term'
                    && $f['title'] === 'Latest timetable generation run blocked'
                    && $f['owner'] === 'Registrar'
                    && $f['url'] === TermTimetable::getUrl(['termId' => $term->id]));

                $hasLateAvailability = $collection->contains(fn (array $f): bool => $f['scope'] === 'Selected term'
                    && str_contains($f['title'], 'Faculty availability conflict with published schedule')
                    && $f['owner'] === 'Registrar'
                    && $f['url'] === TermTimetable::getUrl([
                        'termId' => $term->id,
                        'timetableState' => 'official',
                        'selectedVersionId' => $publishedVersion->id,
                    ]));

                $hasSuccessorDraftFinding = $collection->contains(fn (array $f): bool => $f['scope'] === 'Institution-wide'
                    && str_contains($f['title'], 'CP3-BSIT · Successor draft')
                    && $f['url'] === CurriculumVersionResource::getUrl('review', [
                        'record' => $successorDraft,
                        'oversightTermId' => $term->id,
                    ]));

                $marksProgramInactive = $collection->contains(fn (array $f): bool => $f['title'] === 'CP3-BSIT · Draft needs subjects');

                return $hasReturnedRoster
                    && $hasOverdueRoster
                    && $hasBlockedRun
                    && $hasLateAvailability
                    && $hasSuccessorDraftFinding
                    && ! $marksProgramInactive;
            });

        // Attention MUST appear before Calendar, Enrollment, Grades, Curricula, and Lifecycle in DOM order
        $html = $page->html();
        $attentionPos = strpos($html, 'id="oversight-attention-title"');
        $calendarPos = strpos($html, 'id="oversight-calendar-title"');
        $lifecyclePos = strpos($html, 'id="oversight-lifecycle-title"');

        $this->assertIsInt($attentionPos);
        $this->assertIsInt($calendarPos);
        $this->assertIsInt($lifecyclePos);
        $this->assertLessThan($calendarPos, $attentionPos);
        $this->assertLessThan($lifecyclePos, $calendarPos);
    }

    #[Test]
    public function all_four_academic_oversight_return_paths_preserve_term_and_filter_context(): void
    {
        $head = $this->staff([User::StaffRoleAcademicHead]);
        $dualRole = $this->staff([User::StaffRoleRegistrar, User::StaffRoleAcademicHead]);
        $term = Term::factory()->create(['label' => '2nd Sem CP3']);
        $oversightUrl = AcademicApprovals::getUrl(['termId' => $term->id]);
        $program = Program::factory()->create(['code' => 'CP3-RET']);
        $student = StudentProfile::factory()->for($program)->create([
            'first_name' => 'Elena',
            'last_name' => 'Reyes',
        ]);
        $offering = TermOffering::factory()->for($term)->create();
        $roster = GradeRoster::factory()->for($offering, 'termOffering')->create([
            'state' => GradeRoster::StateSubmitted,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // 1. StudentProfileResource::academicRecordUrl() + ViewStudentProfile
        $studentList = Livewire::actingAs($head)
            ->test(ListStudentProfiles::class, ['oversightTermId' => $term->id, 'viewTab' => 'academic-progress'])
            ->filterTable('program', $program->id)
            ->searchTable('Elena');

        $recordUrl = StudentProfileResource::academicRecordUrl($student, $studentList->instance());
        parse_str((string) parse_url($recordUrl, PHP_URL_QUERY), $studentQuery);
        $this->assertSame((string) $term->id, $studentQuery['oversightTermId'] ?? null);
        $this->assertSame('academic-progress', $studentQuery['viewTab'] ?? null);
        $this->assertSame('Elena', $studentQuery['queue']['search'] ?? null);
        $this->assertSame((string) $program->id, (string) ($studentQuery['queue']['filters']['program']['value'] ?? ''));

        $studentView = Livewire::actingAs($head)
            ->withQueryParams([
                'oversightTermId' => $term->id,
                'viewTab' => 'academic-progress',
                'queue' => [
                    'filters' => ['program' => ['value' => $program->id]],
                    'search' => 'Elena',
                ],
            ])
            ->test(ViewStudentProfile::class, ['record' => $student->getRouteKey()])
            ->assertActionExists('returnToOversight', fn (Action $action): bool => $action->getUrl() === $oversightUrl);

        $studentBreadcrumbs = $studentView->instance()->getBreadcrumbs();
        $this->assertSame('Academic Oversight', $studentBreadcrumbs[$oversightUrl] ?? null);
        $expectedListUrl = StudentProfileResource::getUrl('index', [
            'oversightTermId' => $term->id,
            'viewTab' => 'academic-progress',
            'filters' => ['program' => ['value' => $program->id]],
            'search' => 'Elena',
        ]);
        $this->assertSame('Student records', $studentBreadcrumbs[$expectedListUrl] ?? null);

        // 2. ListGradeRosters + ViewGradeRoster (oversightTermId + returnFilters + returnSearch)
        $rosterList = Livewire::actingAs($head)
            ->test(ListGradeRosters::class, ['oversightTermId' => $term->id])
            ->filterTable('term', $term->id)
            ->filterTable('state', GradeRoster::StateSubmitted)
            ->searchTable($roster->section->code);

        $rosterListBreadcrumbs = $rosterList->instance()->getBreadcrumbs();
        $this->assertSame('Academic Oversight', $rosterListBreadcrumbs[$oversightUrl] ?? null);
        $this->assertContains('Class rosters', $rosterListBreadcrumbs);

        $rosterViewUrl = $rosterList->instance()->headRosterUrl($roster);
        parse_str((string) parse_url($rosterViewUrl, PHP_URL_QUERY), $rosterQuery);
        $this->assertSame((string) $term->id, $rosterQuery['oversightTermId'] ?? null);
        $this->assertSame($roster->section->code, $rosterQuery['returnSearch'] ?? null);
        $this->assertSame(GradeRoster::StateSubmitted, $rosterQuery['returnFilters']['state']['value'] ?? null);

        $returnFilters = ['term' => ['value' => $term->id], 'state' => ['value' => GradeRoster::StateSubmitted]];
        $expectedRosterIndexUrl = GradeRosterResource::getUrl('index', [
            'oversightTermId' => $term->id,
            'filters' => $returnFilters,
            'search' => $roster->section->code,
        ]);

        $rosterView = Livewire::actingAs($head)
            ->test(ViewGradeRoster::class, [
                'record' => $roster->getRouteKey(),
                'oversightTermId' => $term->id,
                'returnFilters' => $returnFilters,
                'returnSearch' => $roster->section->code,
            ])
            ->assertActionExists('backToClassRosters', fn (Action $action): bool => $action->getUrl() === $expectedRosterIndexUrl);

        $rosterBreadcrumbs = $rosterView->instance()->getBreadcrumbs();
        $this->assertSame('Academic Oversight', $rosterBreadcrumbs[$oversightUrl] ?? null);
        $this->assertSame('Class rosters', $rosterBreadcrumbs[$expectedRosterIndexUrl] ?? null);

        // 3. HasAcademicSetupBreadcrumbs (Academic Head preserves oversightTermId; dual-role Registrar retains Term planning)
        $headAcademicYears = Livewire::actingAs($head)
            ->test(ListAcademicYears::class, ['oversightTermId' => $term->id]);
        $this->assertSame('Academic Oversight', $headAcademicYears->instance()->getBreadcrumbs()[$oversightUrl] ?? null);

        session()->put(WorkspaceContextResolver::SessionKey, User::StaffRoleRegistrar);
        $dualAcademicYears = Livewire::actingAs($dualRole)
            ->test(ListAcademicYears::class, ['oversightTermId' => $term->id]);
        $this->assertSame('Term Planning', $dualAcademicYears->instance()->getBreadcrumbs()[TermOverview::getUrl()] ?? null);
        session()->forget(WorkspaceContextResolver::SessionKey);

        // 4. CompletionAndTor (preserves oversightTermId in breadcrumb + header action, stays read-only)
        $completionPage = Livewire::actingAs($head)
            ->test(CompletionAndTor::class, ['oversightTermId' => $term->id])
            ->assertOk()
            ->assertSet('completionView', 'graduation')
            ->assertActionExists('returnToOversight', fn (Action $action): bool => $action->getUrl() === $oversightUrl);

        $this->assertSame('Academic Oversight', $completionPage->instance()->getBreadcrumbs()[$oversightUrl] ?? null);
    }

    #[Test]
    public function catalog_curricula_workbench_unlocks_read_only_draft_inspection_for_head_and_respects_dual_role_context(): void
    {
        $head = $this->staff([User::StaffRoleAcademicHead]);
        $dualRole = $this->staff([User::StaffRoleRegistrar, User::StaffRoleAcademicHead]);
        $term = Term::factory()->create();
        $program = Program::factory()->create(['code' => 'CP3-CAT']);
        $active = CurriculumVersion::factory()->for($program)->create([
            'name' => 'Active Study Plan CP3',
            'state' => CurriculumVersion::StateActive,
        ]);
        $draft = CurriculumVersion::factory()->for($program)->create([
            'name' => 'Draft Study Plan CP3',
            'state' => CurriculumVersion::StateDraft,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // Head-only: preserves title, shows Head-appropriate subheading, defaults to Active, can inspect Drafts read-only
        Livewire::actingAs($head)
            ->test(CatalogCurriculaWorkbench::class, ['oversightTermId' => $term->id])
            ->assertOk()
            ->assertSee('Programs & Curricula')
            ->assertSee('Read-only oversight of active and draft program study plans maintained by the Registrar.')
            ->assertDontSee('Prepare each program’s study plan and activate it for incoming students.')
            ->assertSet('activeTab', 'active')
            ->assertSee($program->code)
            ->assertSee('2 curricula')
            ->assertSee('1 draft')
            ->assertActionVisible('returnToOversight')
            ->assertActionHasUrl('returnToOversight', AcademicApprovals::getUrl(['termId' => $term->id]))
            ->assertActionHidden(TestAction::make('createProgram')->table())
            ->call('switchTab', 'draft')
            ->assertSet('activeTab', 'draft')
            ->call('setStatusFilter', 'has_drafts')
            ->assertSet('statusFilter', 'has_drafts')
            ->assertSee($program->code)
            ->assertTableActionHidden('emptyCreateProgram');

        // Dual-role Registrar+Head in Registrar context retains full Registrar workbench
        session()->put(WorkspaceContextResolver::SessionKey, User::StaffRoleRegistrar);
        Livewire::actingAs($dualRole)
            ->test(CatalogCurriculaWorkbench::class)
            ->assertOk()
            ->assertSee('Prepare each program’s study plan and activate it for incoming students.')
            ->assertSet('activeTab', 'draft')
            ->assertSee($program->code)
            ->assertSee('1 draft')
            ->assertActionHidden('returnToOversight');

        // Dual-role Registrar+Head switched to Academic Head context becomes strictly read-only
        session()->put(WorkspaceContextResolver::SessionKey, User::StaffRoleAcademicHead);
        Livewire::actingAs($dualRole)
            ->test(CatalogCurriculaWorkbench::class, ['oversightTermId' => $term->id])
            ->assertOk()
            ->assertSee('Read-only oversight of active and draft program study plans maintained by the Registrar.')
            ->assertActionVisible('returnToOversight')
            ->assertActionHidden(TestAction::make('createProgram')->table());
        session()->forget(WorkspaceContextResolver::SessionKey);
    }

    #[Test]
    public function read_only_hardening_blocks_head_mutations_and_class_roster_csv_while_dual_role_registrar_retains_access(): void
    {
        $head = $this->staff([User::StaffRoleAcademicHead]);
        $dualRole = $this->staff([User::StaffRoleRegistrar, User::StaffRoleAcademicHead]);
        $faculty = $this->staff([User::StaffRoleFaculty]);
        $term = Term::factory()->create();
        $program = Program::factory()->create(['code' => 'CP3-LOCK']);
        $student = StudentProfile::factory()->for($program)->create();
        $offering = TermOffering::factory()->for($term)->create();
        $section = Section::factory()->for($offering, 'termOffering')->create(['code' => 'LOCK-1A']);
        ClassOfferingTeachingAssignment::query()->create([
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'role' => ClassOfferingTeachingAssignment::RoleDesignated,
            'state' => ClassOfferingTeachingAssignment::StateActive,
            'authority_reference' => 'CP3-TA',
            'assigned_by' => $dualRole->id,
            'effective_at' => now(),
        ]);
        $roster = GradeRoster::factory()->for($offering, 'termOffering')->create([
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'state' => GradeRoster::StateSubmitted,
        ]);
        $enrollment = Enrollment::factory()->for($student, 'studentProfile')->for($term)->create([
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
        ]);
        $courseEnrollment = CourseEnrollment::factory()->for($enrollment)->for($offering, 'termOffering')->create([
            'section_id' => $section->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
        ]);
        GradeRosterRow::factory()->for($roster, 'roster')->for($courseEnrollment)->create([
            'is_current_membership' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // Head-only blocked from CSV download and all mutation actions on ViewGradeRoster / ViewStudentProfile / CompletionAndTor
        $this->actingAs($head)->get(route('grade-rosters.csv', $roster))->assertForbidden();

        Livewire::actingAs($head)
            ->test(ViewGradeRoster::class, ['record' => $roster->getRouteKey(), 'oversightTermId' => $term->id])
            ->assertActionHidden('exportRoster')
            ->assertActionHidden('reassignFaculty')
            ->assertActionHidden('return')
            ->assertActionHidden('postAndRelease');

        Livewire::actingAs($head)
            ->test(ViewStudentProfile::class, ['record' => $student->getRouteKey(), 'oversightTermId' => $term->id])
            ->assertActionHidden('recordCorrection')
            ->assertActionHidden('recordAcademicDecision')
            ->assertActionHidden('recordStudentSection');

        Livewire::actingAs($head)
            ->test(CompletionAndTor::class, ['oversightTermId' => $term->id])
            ->assertActionHidden('recordGraduation')
            ->assertActionHidden('correctGraduation')
            ->assertActionDoesNotExist('recordTorRequest');

        // Dual-role Registrar+Head in Academic Head context: blocked from CSV download & Registrar mutations
        $this->actingAs($dualRole)
            ->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleAcademicHead])
            ->get(route('grade-rosters.csv', $roster))
            ->assertForbidden();

        session()->put(WorkspaceContextResolver::SessionKey, User::StaffRoleAcademicHead);
        Livewire::actingAs($dualRole)
            ->test(ViewGradeRoster::class, ['record' => $roster->getRouteKey(), 'oversightTermId' => $term->id])
            ->assertActionHidden('exportRoster')
            ->assertActionHidden('reassignFaculty')
            ->assertActionHidden('return')
            ->assertActionHidden('postAndRelease');

        // Membership snapshot still works for Academic Head display
        $snapshot = app(CurrentClassRosterOutput::class)->membershipSnapshot($roster, $head);
        $this->assertTrue($snapshot['isCurrent']);
        $this->assertSame(1, $snapshot['count']);

        // Dual-role Registrar+Head in Registrar context: retains CSV download & Registrar actions
        session()->put(WorkspaceContextResolver::SessionKey, User::StaffRoleRegistrar);
        $this->actingAs($dualRole)
            ->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleRegistrar])
            ->get(route('grade-rosters.csv', $roster))
            ->assertOk();

        Livewire::actingAs($dualRole)
            ->test(ViewGradeRoster::class, ['record' => $roster->getRouteKey()])
            ->assertActionVisible('exportRoster')
            ->assertActionVisible('reassignFaculty')
            ->assertActionVisible('return')
            ->assertActionVisible('postAndRelease');

        Livewire::actingAs($dualRole)
            ->test(ViewStudentProfile::class, ['record' => $student->getRouteKey()])
            ->assertActionVisible('recordCorrection');

        session()->forget(WorkspaceContextResolver::SessionKey);
    }

    /** @param list<string> $roles */
    private function staff(array $roles, ?string $firstName = null): User
    {
        $user = User::factory()->create($firstName !== null ? [
            'first_name' => $firstName,
            'last_name' => 'Staff',
            'name' => $firstName.' Staff',
        ] : []);
        $user->assignRole($roles);

        return $user;
    }

    private function readySpecification(): CourseSpecification
    {
        $spec = CourseSpecification::factory()->create([
            'credit_units' => 3,
            'grading_profile_key' => CourseSpecification::GradingProfileServitechV1,
            'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
            'state' => CourseSpecification::StateActive,
        ]);
        CourseComponent::factory()->for($spec)->create();

        return $spec->fresh(['components', 'course']);
    }
}
