<?php

namespace Tests\Feature;

use App\Actions\Academics\AcademicRecordNotificationService;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Filament\Resources\GradeRosters\Pages\ListGradeRosters;
use App\Filament\Resources\GradeRosters\Pages\ViewGradeRoster;
use App\Filament\Resources\GradeRosters\RelationManagers\RowsRelationManager;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterReturnedRow;
use App\Models\GradeRosterRow;
use App\Models\GradeRosterVersion;
use App\Models\OperationalEvent;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HistoryNavigationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {

        parent::setUp();

        $this->assertSame('testing', app()->environment());

        $this->assertSame(config('database.test_database'), DB::connection()->getDatabaseName());

    }

    #[Test]
    public function class_grades_keeps_all_roster_states_and_earlier_terms_available(): void
    {

        $fixture = $this->fixture([GradeRoster::StateDraft, GradeRoster::StateSubmitted, GradeRoster::StateReturned, GradeRoster::StateReleased, GradeRoster::StateLateNotSubmitted]);

        Livewire::actingAs($fixture['registrar'])->withQueryParams(['viewTab' => 'grade-review'])

            ->test(GradesAndCompletion::class)->assertSuccessful()

            ->filterTable('term', $fixture['term']->id)

            ->searchTable($fixture['rosters']->first()->termOffering->curriculumEntry->courseSpecification->course->code)

            ->assertCanSeeTableRecords($fixture['rosters']);

    }

    #[Test]
    public function class_card_preserves_the_selected_term_state_search_sort_and_page(): void
    {

        $fixture = $this->fixture(array_fill(0, 12, GradeRoster::StateReleased));

        $page = Livewire::actingAs($fixture['registrar'])->withQueryParams(['viewTab' => 'grade-review'])

            ->test(GradesAndCompletion::class)

            ->filterTable('term', $fixture['term']->id)->filterTable('state', GradeRoster::StateReleased)

            ->searchTable('History navigation course')->sortTable('id', 'asc')

            ->set('tableRecordsPerPage', 6)->call('gotoPage', 2);

        $record = $page->instance()->getTableRecords()->first();

        $this->assertInstanceOf(GradeRoster::class, $record);

        $queue = ['filters' => ['term' => ['value' => $fixture['term']->id], 'state' => ['value' => GradeRoster::StateReleased]], 'search' => 'History navigation course', 'sort' => 'id:asc', 'page' => 2];

        $expected = GradeRosterResource::getUrl('view', ['record' => $record, 'viewTab' => 'grade-review', 'queue' => $queue]);

        $page->assertTableActionHasUrl('reviewRoster', $expected, $record);

        $this->assertSame($expected, $page->instance()->getTable()->getRecordUrl($record));

    }

    #[Test]
    public function class_context_survives_search_without_reintroducing_a_history_destination(): void
    {

        $fixture = $this->fixture([GradeRoster::StateReleased]);

        $page = Livewire::actingAs($fixture['registrar'])->withQueryParams(['viewTab' => 'grade-review'])

            ->test(GradesAndCompletion::class);

        app()->instance('originalRequest', Request::create('/admin/grade-rosters'));

        $page->searchTable('History navigation course')->assertSet('viewTab', 'grade-review')

            ->assertSee('Class grades')->assertDontSee('Open class history');

        $record = $fixture['rosters']->sole();

        $url = $page->instance()->getTable()->getRecordUrl($record);

        parse_str(parse_url($url, PHP_URL_QUERY), $parameters);

        $this->assertSame('grade-review', $parameters['viewTab']);

        $this->assertSame('History navigation course', $parameters['queue']['search']);

    }

    #[Test]
    public function legacy_selected_history_keeps_the_record_and_returns_to_class_grades_with_safe_scope(): void
    {

        $fixture = $this->fixture([GradeRoster::StateReleased]);

        $record = $fixture['rosters']->sole();

        $valid = ['filters' => ['term' => ['value' => $fixture['term']->id], 'state' => ['value' => GradeRoster::StateReleased]], 'search' => 'History navigation course', 'sort' => 'id:asc', 'page' => 2];

        $untrusted = [...$valid, 'redirect' => 'https://example.test/unrelated', 'role' => 'admin'];

        $untrusted['filters']['unknown'] = ['value' => 'unexpected'];

        $page = Livewire::actingAs($fixture['registrar'])->withQueryParams(['viewTab' => 'history', 'queue' => $untrusted])

            ->test(ViewGradeRoster::class, ['record' => $record->id])

            ->assertSuccessful()->assertSet('viewTab', 'grade-review')->assertSet('submissionHistoryOpen', true)->assertSet('queue', $valid);

        $expected = GradesAndCompletion::getUrl(['viewTab' => 'grade-review', ...$valid]);

        $this->assertArrayHasKey($expected, $page->instance()->getBreadcrumbs());

        $this->assertSame('Class grades', $page->instance()->getBreadcrumbs()[$expected]);

    }

    #[Test]
    public function old_history_collection_links_recover_into_class_grades_without_a_second_menu(): void
    {

        $fixture = $this->fixture([GradeRoster::StateReleased]);

        $scope = ['filters' => ['term' => ['value' => $fixture['term']->id], 'state' => ['value' => GradeRoster::StateReleased]], 'search' => 'GE101', 'sort' => 'id:asc', 'page' => 2];

        $untrusted = [...$scope, 'redirect' => 'https://example.test/unrelated', 'role' => 'admin'];

        $expected = GradesAndCompletion::getUrl(['viewTab' => 'grade-review', ...$scope]);

        Livewire::actingAs($fixture['registrar'])->withQueryParams(['viewTab' => 'history', ...$untrusted])

            ->test(GradesAndCompletion::class)->assertRedirect($expected);

        Livewire::actingAs($fixture['registrar'])->withQueryParams(['viewTab' => 'history', ...$untrusted])

            ->test(ListGradeRosters::class)->assertRedirect($expected);

        Livewire::actingAs($fixture['registrar'])->withQueryParams(['viewTab' => 'overview'])

            ->test(GradesAndCompletion::class)->assertSee('Find any class or earlier term')

            ->assertDontSeeHtml('viewTab=history');

        $this->assertArrayNotHasKey('history', GradesAndCompletion::tabs());

    }

    #[Test]
    public function released_student_collection_pager_keeps_its_own_page_name(): void
    {

        $fixture = $this->fixture([GradeRoster::StateReleased]);

        $roster = $fixture['rosters']->sole();

        for ($index = 0; $index < 11; $index++) {

            $enrollment = CourseEnrollment::factory()->create(['term_offering_id' => $roster->term_offering_id, 'section_id' => $roster->section_id]);

            GradeRosterRow::factory()->create([

                'grade_roster_id' => $roster->id,

                'course_enrollment_id' => $enrollment->id,

                'is_current_membership' => true,

                'current_outcome_code' => '2.00',

                'released_at' => now(),

            ]);

        }

        $page = Livewire::actingAs($fixture['registrar'])->test(RowsRelationManager::class, [

            'ownerRecord' => $roster,

            'pageClass' => ViewGradeRoster::class,

        ])->assertSee('1–10 of 11 students')->assertSee('Page 1 of 2');

        $pageName = $page->instance()->getTableRecords()->getPageName();

        $page->assertSeeHtml(e('gotoPage(2, '.json_encode($pageName).')'))

            ->call('gotoPage', 2, $pageName)

            ->assertSee('11–11 of 11 students')

            ->assertSee('Page 2 of 2');

    }

    #[Test]
    public function malformed_queue_values_do_not_become_navigation_parameters(): void
    {

        $fixture = $this->fixture([GradeRoster::StateReleased]);

        $page = Livewire::actingAs($fixture['registrar'])->withQueryParams(['viewTab' => 'history', 'queue' => [

            'filters' => ['term' => ['value' => ['unexpected']], 'state' => ['value' => GradeRoster::StateReleased]],

            'search' => ['unexpected'], 'sort' => ['unexpected'], 'page' => -7, 'redirect' => 'elsewhere',

        ]])->test(ViewGradeRoster::class, ['record' => $fixture['rosters']->sole()->id])->assertSuccessful();

        $this->assertSame(['filters' => ['state' => ['value' => GradeRoster::StateReleased]]], $page->get('queue'));

    }

    #[Test]
    public function earlier_returns_remain_attributable_and_version_paging_is_independent_of_students(): void
    {

        $fixture = $this->fixture([GradeRoster::StateReleased]);

        $roster = $fixture['rosters']->sole();

        $faculty = User::findOrFail($roster->faculty_user_id);

        $registrarName = "Maribel O'Neil";
        $facultyName = "Alex D'Angelo";
        $fixture['registrar']->update(User::staffNamePayload('Maribel', null, "O'Neil"));
        $faculty->update(User::staffNamePayload('Alex', null, "D'Angelo"));

        $assignment = ClassOfferingTeachingAssignment::query()->create([

            'term_offering_id' => $roster->term_offering_id, 'section_id' => $roster->section_id,

            'faculty_user_id' => $faculty->id, 'role' => ClassOfferingTeachingAssignment::RoleDesignated,

            'state' => ClassOfferingTeachingAssignment::StateActive, 'assigned_by' => $fixture['registrar']->id,

            'authority_reference' => 'HISTORY-TEST-ASSIGNMENT',

            'effective_at' => now(),

        ]);

        $enrollment = CourseEnrollment::factory()->create(['term_offering_id' => $roster->term_offering_id, 'section_id' => $roster->section_id]);

        $row = GradeRosterRow::factory()->create(['grade_roster_id' => $roster->id, 'course_enrollment_id' => $enrollment->id, 'is_current_membership' => true, 'current_outcome_code' => '2.00', 'released_at' => now()]);

        for ($number = 1; $number <= 11; $number++) {

            $version = GradeRosterVersion::query()->create([

                'grade_roster_id' => $roster->id, 'version_number' => $number,

                'teaching_assignment_id' => $assignment->id, 'membership_signature' => 'history-fixture',

                'state' => $number === 11 ? GradeRosterVersion::StateReleased : GradeRosterVersion::StateReturned,

                'submitted_by' => $faculty->id, 'submitted_at' => '2026-10-01 01:00:00',

                'reviewed_by' => $fixture['registrar']->id, 'reviewed_at' => '2026-10-02 02:00:00',

                'released_by' => $number === 11 ? $fixture['registrar']->id : null,

                'released_at' => $number === 11 ? '2026-10-03 03:00:00' : null,

            ]);

            if ($number === 1) {

                GradeRosterReturnedRow::query()->create([

                    'grade_roster_version_id' => $version->id, 'grade_roster_row_id' => $row->id,

                    'reason' => 'Check the original assessment <script>alert(1)</script>',

                    'returned_by' => $fixture['registrar']->id, 'returned_at' => '2026-10-02 02:00:00',

                    'resolved_at' => '2026-10-03 03:00:00',

                ]);

            }

        }

        $page = Livewire::actingAs($fixture['registrar'])->withQueryParams(['viewTab' => 'history'])->test(ViewGradeRoster::class, [

            'record' => $roster->id,

        ])->assertSet('defaultAction', 'classActivity')->mountAction('classActivity')->assertActionMounted('classActivity');

        $modal = fn (): string => $page->instance()->getMountedAction()->getModalContent()->render();

        $this->assertStringContainsString('Version 11', $modal());

        $this->assertStringNotContainsString('Version 1 ·', $modal());

        $this->assertStringContainsString('Released</strong> Oct 3, 2026 · 11:00 AM · '.e($registrarName), $modal());

        app()->instance('originalRequest', Request::create('/admin/grade-rosters/2'));

        $page->call('gotoSubmissionHistoryPage', 2)->assertSet('submissionHistoryOpen', true);

        $this->assertStringContainsString('Version 1 · Returned', $modal());

        $this->assertStringContainsString('Submitted</strong> Oct 1, 2026 · 9:00 AM · '.e($facultyName), $modal());

        $this->assertStringContainsString('Returned for changes</strong> Oct 2, 2026 · 10:00 AM · '.e($registrarName), $modal());

        $this->assertStringContainsString('Check the original assessment', $modal());

        $this->assertStringNotContainsString('<script>alert(1)</script>', $modal());

        $page->call('gotoSubmissionHistoryPage', 999);

        $this->assertStringContainsString('Version 1 · Returned', $modal());

        $page->call('gotoSubmissionHistoryPage', -3);

        $this->assertStringContainsString('Version 11', $modal());

        $this->assertSame(GradeRoster::StateReleased, $roster->fresh()->state);

        $roster->update(['state' => GradeRoster::StateReturned, 'current_version_number' => 1, 'teaching_assignment_id' => $assignment->id]);

        $notice = OperationalEvent::factory()->create([

            'event_type' => OperationalEvent::TypeGradeSubmissionRequiredEmail,

            'user_id' => $faculty->id, 'status' => OperationalEvent::StatusFailed,

            'related_record_type' => $roster->getMorphClass(), 'related_record_id' => $roster->id,

        ]);

        $page = Livewire::actingAs($fixture['registrar'])->withQueryParams(['viewTab' => 'history'])
            ->test(ViewGradeRoster::class, ['record' => $roster->id])
            ->assertActionVisible('resendAcademicNotice')
            ->mountAction('classActivity')
            ->assertActionMounted('classActivity');

        $modal = fn (): string => $page->instance()->getMountedAction()->getModalContent()->render();
        $this->assertStringContainsString('Version 11', $modal());

        app()->instance('originalRequest', Request::create('/admin/grade-rosters/2'));
        $page->call('gotoSubmissionHistoryPage', 2);
        $this->assertStringContainsString('Version 1 · Returned', $modal());
        $this->assertStringContainsString('Check the original assessment', $modal());

        Mail::fake();

        app(AcademicRecordNotificationService::class)->resend($notice, $fixture['registrar']);

        $this->assertSame(OperationalEvent::StatusPending, $notice->fresh()->status);

    }

    #[Test]
    public function result_history_explains_corrections_and_does_not_invent_a_missing_release_event(): void
    {

        $fixture = $this->fixture([GradeRoster::StateReleased]);

        $roster = $fixture['rosters']->sole();

        $rows = collect();

        foreach (range(1, 2) as $index) {

            $enrollment = CourseEnrollment::factory()->create(['term_offering_id' => $roster->term_offering_id, 'section_id' => $roster->section_id]);

            $rows->push(GradeRosterRow::factory()->create(['grade_roster_id' => $roster->id, 'course_enrollment_id' => $enrollment->id, 'is_current_membership' => true, 'current_outcome_code' => '2.00', 'released_at' => now()]));

        }

        GradeOutcomeEvent::factory()->create([

            'grade_roster_row_id' => $rows->first()->id, 'event_type' => GradeOutcomeEvent::TypePostedCorrection,

            'result_code' => '2.00', 'recorded_by' => $fixture['registrar']->id, 'released_at' => now(),

            'reason' => 'Corrected assessment total <script>alert(2)</script>',

        ]);

        $rows->last()->update(['final_result' => '1.75']);

        Livewire::actingAs($fixture['registrar'])->test(RowsRelationManager::class, [

            'ownerRecord' => $roster, 'pageClass' => ViewGradeRoster::class,

        ])->call('selectResult', $rows->first()->id)

            ->assertSee('Posted correction')->assertSee('Corrected assessment total')->assertDontSeeHtml('<script>alert(2)</script>')

            ->call('selectResult', $rows->last()->id)->assertSee('Release history unavailable')

            ->assertDontSee('Corrected assessment total')->assertDontSee('Class release')->assertDontSeeText('1.75')

            ->searchTable('No student matches this history search')

            ->assertSee('No matching students')->assertDontSee('No result selected')->assertDontSee('Current released result')

            ->call('resetTableSearch')->assertSee('Current released result');

    }

    #[Test]
    public function result_history_is_bounded_and_resets_for_another_student_in_both_workspaces(): void
    {

        $fixture = $this->fixture([GradeRoster::StateReleased]);

        $roster = $fixture['rosters']->sole();

        $rows = collect();

        foreach (range(1, 2) as $index) {

            $enrollment = CourseEnrollment::factory()->create(['term_offering_id' => $roster->term_offering_id, 'section_id' => $roster->section_id]);

            $rows->push(GradeRosterRow::factory()->create(['grade_roster_id' => $roster->id, 'course_enrollment_id' => $enrollment->id, 'is_current_membership' => true, 'current_outcome_code' => '2.00', 'released_at' => now()]));

        }

        foreach (range(1, 12) as $index) {

            GradeOutcomeEvent::factory()->create([

                'grade_roster_row_id' => $rows->first()->id, 'event_type' => GradeOutcomeEvent::TypePostedCorrection,

                'result_code' => '2.00', 'recorded_by' => $fixture['registrar']->id, 'released_at' => now()->subDays(12 - $index),

                'reason' => 'Result history entry '.$index.' end.',

            ]);

        }

        GradeOutcomeEvent::factory()->create(['grade_roster_row_id' => $rows->first()->id, 'released_at' => null, 'reason' => 'Not a released result']);

        GradeOutcomeEvent::factory()->create(['grade_roster_row_id' => $rows->last()->id, 'released_at' => now(), 'reason' => 'Other student result']);

        foreach ([RowsRelationManager::class, GradesAndCompletion::class] as $component) {

            $isClass = $component === RowsRelationManager::class;

            $page = Livewire::actingAs($fixture['registrar'])->withQueryParams([

                'viewTab' => 'inc-corrections',

                'filters' => ['term' => ['value' => $fixture['term']->id], 'result_scope' => ['value' => 'all']],

            ])->test($component, $isClass ? ['ownerRecord' => $roster, 'pageClass' => ViewGradeRoster::class] : []);

            $select = $isClass ? 'selectResult' : 'selectIncResult';

            $page->call($select, $rows->first()->id)->assertSee('Result history entry 12 end.')

                ->assertDontSee('Result history entry 2 end.')->assertDontSee('Not a released result');

            $history = $page->instance()->resultHistoryFor($rows->first()->id);

            $this->assertSame(12, $history->total());

            $this->assertCount(10, $history->items());

            $this->assertCount(1, $page->instance()->getTableRecord((string) $rows->first()->id)->outcomeEvents);

            $studentPage = $page->instance()->getTablePage();

            $page->call('gotoResultHistoryPage', 2)->assertSee('Result history entry 2 end.')

                ->assertDontSee('Result history entry 12 end.')->assertSee('Released '.now('Asia/Manila')->format('M j, Y'));

            $this->assertSame($studentPage, $page->instance()->getTablePage());

            $page->call('gotoResultHistoryPage', 999)->assertSet('resultHistoryPage', 2)

                ->call('gotoResultHistoryPage', -1)->assertSet('resultHistoryPage', 1);

            $page->call('gotoResultHistoryPage', 2)->call($select, $rows->last()->id)

                ->assertSet('resultHistoryPage', 1)->assertSee('Other student result')->assertDontSee('Result history entry 2 end.');

        }

    }

    #[Test]

    #[DataProvider('obsoleteReminderStates')]
    public function completed_submission_tasks_cannot_be_retried_from_history_or_the_service(string $state): void
    {

        Mail::fake();

        $fixture = $this->fixture([$state]);

        $roster = $fixture['rosters']->sole();

        $notice = OperationalEvent::factory()->create([

            'event_type' => OperationalEvent::TypeGradeSubmissionRequiredEmail,

            'user_id' => $roster->faculty_user_id, 'status' => OperationalEvent::StatusFailed,

            'related_record_type' => $roster->getMorphClass(), 'related_record_id' => $roster->id,

        ]);

        Livewire::actingAs($fixture['registrar'])->test(ViewGradeRoster::class, ['record' => $roster->id])

            ->assertActionHidden('resendAcademicNotice');

        try {

            app(AcademicRecordNotificationService::class)->resend($notice, $fixture['registrar']);

            $this->fail('An obsolete submission reminder must not be queued.');

        } catch (ValidationException $exception) {

            $this->assertSame('This class no longer needs this submission reminder. Open its current roster instead.', $exception->errors()['notification'][0]);

        }

        $this->assertSame(OperationalEvent::StatusFailed, $notice->fresh()->status);

        $this->assertSame($state, $roster->fresh()->state);

        Mail::assertNothingQueued();

    }

    public static function obsoleteReminderStates(): array
    {

        return ['submitted' => [GradeRoster::StateSubmitted], 'released' => [GradeRoster::StateReleased]];

    }

    #[Test]

    #[DataProvider('deniedRoles')]
    public function unrelated_roles_cannot_open_the_history_list_or_a_class_record(string $role): void
    {

        $fixture = $this->fixture([GradeRoster::StateReleased]);

        $actor = $this->staff($role);

        Livewire::actingAs($actor)->withQueryParams(['viewTab' => 'history'])->test(ListGradeRosters::class)->assertForbidden();

        Livewire::actingAs($actor)->withQueryParams(['viewTab' => 'history'])

            ->test(ViewGradeRoster::class, ['record' => $fixture['rosters']->sole()->id])->assertForbidden();

    }

    #[Test]
    public function head_reader_keeps_oversight_navigation_and_cannot_mutate_the_roster(): void
    {

        $fixture = $this->fixture([GradeRoster::StateSubmitted]);

        $head = $this->staff(User::StaffRoleAcademicHead);

        $record = $fixture['rosters']->sole();

        $page = Livewire::actingAs($head)->withQueryParams(['viewTab' => 'history', 'oversightTermId' => $fixture['term']->id])

            ->test(ViewGradeRoster::class, ['record' => $record->id])->assertSuccessful()

            ->assertActionHidden('postAndRelease')->assertActionHidden('return')->assertActionHidden('authorizeLateGradeEncoding');

        $this->assertContains('Academic Oversight', $page->instance()->getBreadcrumbs());

        $this->assertSame(GradeRoster::StateSubmitted, $record->fresh()->state);

    }

    #[Test]
    public function unassigned_faculty_cannot_reach_other_classes_through_history(): void
    {

        $fixture = $this->fixture([GradeRoster::StateReleased]);

        $faculty = $this->staff(User::StaffRoleFaculty);

        Livewire::actingAs($faculty)->withQueryParams(['viewTab' => 'history'])

            ->test(ListGradeRosters::class)->assertCanNotSeeTableRecords($fixture['rosters']);

        Livewire::actingAs($faculty)->withQueryParams(['viewTab' => 'history'])

            ->test(ViewGradeRoster::class, ['record' => $fixture['rosters']->sole()->id])->assertNotFound();

    }

    public static function deniedRoles(): array
    {

        return ['Student' => ['student'], 'Accounting' => [User::StaffRoleAccounting]];

    }

    /** @param list<string> $states

     * @return array{registrar: User, term: Term, rosters: Collection<int, GradeRoster>}
     */
    private function fixture(array $states): array
    {

        $registrar = $this->staff(User::StaffRoleRegistrar);

        $faculty = $this->staff(User::StaffRoleFaculty);

        $term = Term::factory()->create();

        $specification = CourseSpecification::factory()->create(['title' => 'History navigation course']);

        $entry = CurriculumEntry::factory()->create(['course_specification_id' => $specification->id]);

        $offering = TermOffering::factory()->create(['term_id' => $term->id, 'curriculum_entry_id' => $entry->id]);

        $rosters = new Collection;

        foreach ($states as $state) {

            $section = Section::factory()->create(['term_offering_id' => $offering->id]);

            $rosters->push(GradeRoster::factory()->create(['term_offering_id' => $offering->id, 'section_id' => $section->id, 'faculty_user_id' => $faculty->id, 'state' => $state]));

        }

        return compact('registrar', 'term', 'rosters');

    }

    private function staff(string $role): User
    {

        Role::findOrCreate($role, 'web');

        $actor = User::factory()->create(['status' => User::StatusActive]);

        $actor->assignRole($role);

        return $actor;

    }
}
