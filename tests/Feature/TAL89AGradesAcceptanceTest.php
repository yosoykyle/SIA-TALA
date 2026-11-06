<?php

namespace Tests\Feature;

use App\Actions\Grades\ManageTeachingAssignment;
use App\Actions\Grades\PostAndReleaseGradeRoster;
use App\Actions\Grades\SaveGradeRosterDraft;
use App\Actions\Grades\SubmitGradeRoster;
use App\Actions\Grades\SynchronizeOfficialGradeRoster;
use App\Filament\Pages\FacultyGradeRoster;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Filament\Resources\GradeRosters\Pages\ViewGradeRoster;
use App\Filament\Resources\GradeRosters\RelationManagers\RowsRelationManager;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\InteractsWithGradeRosterDrafts;
use Tests\TestCase;

class TAL89AGradesAcceptanceTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithGradeRosterDrafts;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['student', User::StaffRoleRegistrar, User::StaffRoleFaculty, User::StaffRoleAcademicHead] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function designated_faculty_receives_one_final_result_surface_without_period_or_p_controls(): void
    {
        [$faculty, $row] = $this->rosterFixture();

        $component = Livewire::actingAs($faculty)
            ->test(FacultyGradeRoster::class)
            ->assertActionVisible('submit')
            ->assertSee('Draft saved')
            ->assertDontSee('Save draft')
            ->assertSee('Final result')
            ->assertDontSee('Prelim')
            ->assertDontSee('Midterm')
            ->assertDontSee('Set P / INC');
        $this->assertSame(
            $row->courseEnrollment->enrollment->studentProfile->student_number,
            collect($component->instance()->data['rows'])->first()['student_number'],
        );
    }

    #[Test]
    public function view_only_co_faculty_can_open_and_export_but_cannot_edit_or_submit(): void
    {
        [$faculty, $row, $registrar, $section] = $this->rosterFixture();
        $coFaculty = $this->staff(User::StaffRoleFaculty);
        app(ManageTeachingAssignment::class)->addCoFaculty($section, $coFaculty, $registrar, 'REG-T03-CO-001');

        $component = Livewire::actingAs($coFaculty)
            ->test(FacultyGradeRoster::class)
            ->assertActionVisible('printRoster')
            ->assertActionVisible('downloadRoster')
            ->assertActionHidden('submit')
            ->assertDontSee('Save draft');
        $this->assertSame(
            $row->courseEnrollment->enrollment->studentProfile->student_number,
            collect($component->instance()->data['rows'])->first()['student_number'],
        );

        $this->assertSame($faculty->id, $row->roster->teachingAssignment->faculty_user_id);
    }

    #[Test]
    public function draft_save_is_atomic_and_rejects_stale_lock_or_membership_evidence(): void
    {
        [$faculty, $row] = $this->rosterFixture();
        $roster = $row->roster;
        $action = app(SaveGradeRosterDraft::class);
        $originalLockVersion = $roster->lock_version;

        $saved = $action->execute(
            $roster,
            [['id' => $row->id, 'final_result' => '4.00', 'inc_completion_note' => null]],
            $originalLockVersion,
            $roster->membership_signature,
            $faculty,
        );

        $this->assertSame('4.00', $row->fresh()->final_result);
        $this->assertSame(1, $row->fresh()->row_revision);
        $this->assertSame($originalLockVersion + 1, $saved->lock_version);

        $unchanged = $action->execute($saved, [['id' => $row->id, 'final_result' => '4.00', 'inc_completion_note' => null]], $saved->lock_version, $saved->membership_signature, $faculty);
        $this->assertSame($saved->lock_version, $unchanged->lock_version);
        $this->assertSame(1, $row->fresh()->row_revision);

        try {
            $action->execute(
                $saved,
                [['id' => $row->id, 'final_result' => '2.00', 'inc_completion_note' => null]],
                $originalLockVersion,
                $saved->membership_signature,
                $faculty,
            );
            $this->fail('A stale roster lock must not overwrite a newer draft.');
        } catch (RuntimeException) {
            $this->assertSame('4.00', $row->fresh()->final_result);
        }

        try {
            $action->execute(
                $saved->fresh(),
                [['id' => $row->id, 'final_result' => '2.00', 'inc_completion_note' => null]],
                $saved->fresh()->lock_version,
                'stale-membership-signature',
                $faculty,
            );
            $this->fail('Stale membership evidence must not overwrite a current draft.');
        } catch (RuntimeException) {
            $this->assertSame('4.00', $row->fresh()->final_result);
            $this->assertSame(GradeRoster::StateDraft, $roster->fresh()->state);
        }
    }

    #[Test]
    public function registrar_releases_a_submitted_roster_after_the_grade_entry_window_closes(): void
    {
        $this->freezeTime();
        [$faculty, $row, $registrar] = $this->rosterFixture();
        $this->saveGradeRosterDraft($row->roster, $faculty, [
            $row->id => ['final_result' => '1.75', 'inc_completion_note' => null],
        ]);
        $submitted = app(SubmitGradeRoster::class)->execute($row->roster, $faculty);
        $this->travel(3)->days();

        $released = app(PostAndReleaseGradeRoster::class)->execute($submitted, $registrar);

        $this->assertSame(GradeRoster::StateReleased, $released->state);
        $this->assertSame('1.75', $row->fresh()->current_outcome_code);
        $this->assertSame(1, $row->outcomeEvents()->count());
    }

    #[Test]
    public function incomplete_inc_drafts_are_private_and_cannot_be_submitted_without_a_note(): void
    {
        [$faculty, $row] = $this->rosterFixture();
        $roster = $row->roster;
        $saved = app(SaveGradeRosterDraft::class)->execute($roster, [
            ['id' => $row->id, 'final_result' => 'INC', 'inc_completion_note' => '   '],
        ], $roster->lock_version, $roster->membership_signature, $faculty);

        $this->assertSame('INC', $row->fresh()->final_result);
        $this->assertNull($row->fresh()->inc_completion_note);
        try {
            app(SubmitGradeRoster::class)->execute($saved, $faculty);
            $this->fail('An incomplete INC draft was submitted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Each INC result requires a safe completion note before submission.', $exception->getMessage());
        }
        $this->assertSame(GradeRoster::StateDraft, $roster->fresh()->state);
        $this->assertSame(0, $roster->versions()->count());
        $this->assertSame(0, $row->outcomeEvents()->count());
    }

    #[Test]
    public function autosave_preserves_a_stale_tabs_edits_and_refuses_to_overwrite_the_newer_draft(): void
    {
        [$faculty, $row] = $this->rosterFixture();
        $first = Livewire::actingAs($faculty)->test(FacultyGradeRoster::class);
        $stale = Livewire::actingAs($faculty)->test(FacultyGradeRoster::class);
        $key = array_key_first($first->get('data.rows'));

        $first->set('data.rows.'.$key.'.final_result', '2.00')->assertSet('draftSaveError', null);
        $stale->set('data.rows.'.$key.'.final_result', '1.75')
            ->assertSet('draftSaveError', 'The roster changed while this page was open. Refresh and review the current membership before saving.')
            ->assertSet('data.rows.'.$key.'.final_result', '1.75');
        $this->assertSame('2.00', $row->fresh()->final_result);
        $stale->call('saveDraft')->assertSet('data.rows.'.$key.'.final_result', '1.75');
        $this->assertSame('2.00', $row->fresh()->final_result);
        $this->assertSame(0, $row->roster->versions()->count());
    }

    #[Test]
    public function a_failed_autosave_keeps_the_edit_available_for_retry(): void
    {
        [$faculty, $row] = $this->rosterFixture();
        $save = app(SaveGradeRosterDraft::class);
        $page = Livewire::actingAs($faculty)->test(FacultyGradeRoster::class);
        $key = array_key_first($page->get('data.rows'));
        $this->mock(SaveGradeRosterDraft::class)->shouldReceive('execute')->once()->andThrow(new RuntimeException('Saving is temporarily unavailable.'));

        $page->set('data.rows.'.$key.'.final_result', '2.25')
            ->assertSet('draftSaveError', 'Saving is temporarily unavailable.')
            ->assertSet('data.rows.'.$key.'.final_result', '2.25');
        $this->assertNull($row->fresh()->final_result);
        $this->app->instance(SaveGradeRosterDraft::class, $save);
        $page->call('saveDraft')->assertSet('draftSaveError', null)->assertSee('Draft saved');
        $this->assertSame('2.25', $row->fresh()->final_result);
        $this->assertSame(0, $row->roster->versions()->count());
    }

    #[Test]
    public function registrar_failed_return_keeps_the_selected_rows_and_reason_in_the_open_dialog(): void
    {
        [$faculty, $row, $registrar] = $this->rosterFixture();
        $this->saveGradeRosterDraft($row->roster, $faculty, [
            $row->id => ['final_result' => '2.00', 'inc_completion_note' => null],
        ]);
        $roster = app(SubmitGradeRoster::class)->execute($row->roster, $faculty);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($registrar)->test(ViewGradeRoster::class, ['record' => $roster->id])
            ->mountAction('return')
            ->setActionData(['row_ids' => [$row->id], 'reason' => 'Review the final source assessment again.']);
        $roster->increment('current_version_number');

        $page->callMountedAction()->assertActionMounted('return')->assertNotified('Roster was not returned')
            ->assertActionDataSet(['row_ids' => [$row->id], 'reason' => 'Review the final source assessment again.']);

        $this->assertSame(GradeRoster::StateSubmitted, $roster->fresh()->state);
        $this->assertNull($row->fresh()->released_at);
    }

    #[Test]
    public function registrar_failed_release_keeps_the_review_confirmation_open_without_publishing_results(): void
    {
        [$faculty, $row, $registrar] = $this->rosterFixture();
        $this->saveGradeRosterDraft($row->roster, $faculty, [
            $row->id => ['final_result' => '2.00', 'inc_completion_note' => null],
        ]);
        $roster = app(SubmitGradeRoster::class)->execute($row->roster, $faculty);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($registrar)->test(ViewGradeRoster::class, ['record' => $roster->id])
            ->mountAction('postAndRelease');
        $roster->increment('current_version_number');

        $page->callMountedAction()->assertActionMounted('postAndRelease')->assertNotified('Roster was not released');

        $this->assertSame(GradeRoster::StateSubmitted, $roster->fresh()->state);
        $this->assertNull($row->fresh()->released_at);
        $this->assertSame(0, $row->outcomeEvents()->count());
    }

    #[Test]
    public function class_review_links_and_breadcrumb_restore_the_queue_search_filters_sort_and_page(): void
    {
        [, $row, $registrar] = $this->rosterFixture();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $context = [
            'filters' => ['term' => ['value' => (string) $row->roster->termOffering->term_id], 'state' => ['value' => GradeRoster::StateDraft]],
            'search' => $row->roster->faculty->name,
            'sort' => 'submitted_at:asc',
            'page' => 2,
        ];
        $queue = Livewire::actingAs($registrar)->withQueryParams(['viewTab' => 'grade-review', ...$context])
            ->test(GradesAndCompletion::class)
            ->assertSet('tableSearch', $context['search'])
            ->assertSet('tableSort', $context['sort'])
            ->assertSet('paginators.page', 2);
        $expectedReviewUrl = GradeRosterResource::getUrl('view', ['record' => $row->roster, 'viewTab' => 'grade-review', 'queue' => $context]);

        $this->assertSame($expectedReviewUrl, $queue->instance()->getTable()->getRecordUrl($row->roster));
        $queue->assertTableActionHasUrl('reviewRoster', $expectedReviewUrl, $row->roster);
        $review = Livewire::actingAs($registrar)->withQueryParams(['queue' => $context])->test(ViewGradeRoster::class, ['record' => $row->grade_roster_id]);
        $this->assertArrayHasKey(GradesAndCompletion::getUrl(['viewTab' => 'grade-review', ...$context]), $review->instance()->getBreadcrumbs());
    }

    #[Test]
    public function class_queue_shows_all_states_and_clears_filtered_empty_results(): void
    {
        [, $row, $registrar] = $this->rosterFixture();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $queue = Livewire::actingAs($registrar)->test(GradesAndCompletion::class, ['viewTab' => 'grade-review'])
            ->assertCanSeeTableRecords([$row->roster])
            ->assertSet('tableFilters.state.value', null)
            ->assertDontSee('Reset search and filters')
            ->filterTable('state', GradeRoster::StateReturned)
            ->assertSee('No classes match your search or filters')
            ->assertDontSee('No class rosters yet')
            ->searchTable('No matching faculty')
            ->callAction(TestAction::make('resetClassQueue')->table())
            ->assertSet('tableSearch', '')
            ->assertSet('tableFilters.state.value', null)
            ->assertCanSeeTableRecords([$row->roster]);

        $queue->filterTable('state', GradeRoster::StateDraft)->assertCanSeeTableRecords([$row->roster]);
    }

    #[Test]
    public function class_cards_paginate_six_at_a_time_and_search_across_pages(): void
    {
        [$faculty, $row, $registrar, $section] = $this->rosterFixture();
        $section->update(['code' => 'QA-COMPACT-0']);
        foreach (range(1, 7) as $index) {
            $class = Section::factory()->create([
                'term_offering_id' => $row->roster->term_offering_id,
                'code' => 'QA-COMPACT-'.$index,
            ]);
            GradeRoster::factory()->create([
                'term_offering_id' => $row->roster->term_offering_id,
                'section_id' => $class->id,
                'faculty_user_id' => $faculty->id,
                'state' => $index === 7 ? GradeRoster::StateReturned : GradeRoster::StateDraft,
            ]);
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $queue = Livewire::actingAs($registrar)->test(GradesAndCompletion::class, ['viewTab' => 'grade-review'])
            ->searchTable('QA-COMPACT')->assertSeeHtml('aria-label="Next page"');
        $firstPage = $queue->instance()->getTableRecords();
        $this->assertSame(6, $firstPage->perPage());
        $this->assertSame(8, $firstPage->total());
        $this->assertCount(6, $firstPage->items());
        $firstIds = $firstPage->getCollection()->modelKeys();

        $queue->call('nextPage')->assertSeeHtml('aria-label="Previous page"');
        $secondPage = $queue->instance()->getTableRecords();
        $this->assertSame(2, $secondPage->currentPage());
        $this->assertCount(2, $secondPage->items());
        $this->assertSame([], array_values(array_intersect($firstIds, $secondPage->getCollection()->modelKeys())));

        $queue->searchTable('QA-COMPACT-7')->assertSet('paginators.page', 1);
        $this->assertSame(1, $queue->instance()->getTableRecords()->total());
        $queue->searchTable('QA-COMPACT')->filterTable('state', GradeRoster::StateReturned);
        $this->assertSame(1, $queue->instance()->getTableRecords()->total());

        $queue->call('clearClassQueue')->searchTable('QA-COMPACT')->set('tableRecordsPerPage', 12);
        $this->assertCount(8, $queue->instance()->getTableRecords()->items());
    }

    #[Test]
    #[DataProvider('classReviewDecisions')]
    public function registrar_decisions_keep_the_original_queue_context_and_discard_redirect_injection(string $action): void
    {
        $this->freezeTime();
        [$faculty, $row, $registrar] = $this->rosterFixture();
        $this->saveGradeRosterDraft($row->roster, $faculty, [
            $row->id => ['final_result' => '2.00', 'inc_completion_note' => null],
        ]);
        $roster = app(SubmitGradeRoster::class)->execute($row->roster, $faculty);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $context = [
            'filters' => ['term' => ['value' => (string) $roster->termOffering->term_id], 'state' => ['value' => GradeRoster::StateSubmitted]],
            'search' => $faculty->name,
            'sort' => 'submitted_at:desc',
            'page' => 2,
        ];
        $untrusted = $context + ['redirect' => 'https://example.com', 'record' => 99999, 'viewTab' => 'history'];
        $untrusted['filters']['unrelated'] = ['value' => 'injected'];
        $page = Livewire::actingAs($registrar)->withQueryParams(['queue' => $untrusted])
            ->test(ViewGradeRoster::class, ['record' => $roster->id])
            ->assertSet('queue', $context);
        $this->assertArrayHasKey(GradesAndCompletion::getUrl(['viewTab' => 'grade-review', ...$context]), $page->instance()->getBreadcrumbs());

        $page->callAction($action, $action === 'return' ? ['row_ids' => [$row->id], 'reason' => 'Review the final assessment result.'] : [])
            ->assertHasNoActionErrors()
            ->assertRedirect(GradeRosterResource::getUrl('view', ['record' => $roster, 'viewTab' => 'grade-review', 'queue' => $context]));

        $this->assertSame($action === 'return' ? GradeRoster::StateReturned : GradeRoster::StateReleased, $roster->fresh()->state);
    }

    /** @return array<string, array{string}> */
    public static function classReviewDecisions(): array
    {
        return ['return' => ['return'], 'release' => ['postAndRelease']];
    }

    /** @return array{User, GradeRosterRow, User, Section} */
    #[Test]
    public function inc_result_link_preserves_its_queue_and_targets_the_selected_student(): void
    {
        [, $row, $registrar] = $this->rosterFixture();
        $roster = $row->roster;
        $roster->update(['state' => GradeRoster::StateReleased, 'released_at' => now()]);
        $row->update(['current_outcome_code' => 'INC', 'released_at' => now()]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $context = [
            'filters' => ['term' => ['value' => (string) $roster->termOffering->term_id], 'result_scope' => ['value' => 'all']],
            'search' => $row->courseEnrollment->enrollment->studentProfile->student_number,
            'page' => 2,
        ];
        $page = Livewire::actingAs($registrar)->withQueryParams(['viewTab' => 'inc-corrections', ...$context])->test(GradesAndCompletion::class);
        $expected = GradeRosterResource::getUrl('view', ['record' => $roster, 'viewTab' => 'inc-corrections', 'result' => $row->id, 'queue' => $context]);
        $page->assertTableActionHasUrl('viewClass', $expected, $row);

        $review = Livewire::actingAs($registrar)->withQueryParams(['viewTab' => 'inc-corrections', 'queue' => [...$context, 'redirect' => 'https://example.invalid']])
            ->test(ViewGradeRoster::class, ['record' => $roster->id])->assertSet('queue', $context);
        $this->assertArrayHasKey(GradesAndCompletion::getUrl(['viewTab' => 'inc-corrections', ...$context]), $review->instance()->getBreadcrumbs());
    }

    #[Test]
    public function result_link_selects_only_a_visible_row_of_its_class(): void
    {
        [, $row, $registrar] = $this->rosterFixture();
        $row->roster->update(['state' => GradeRoster::StateReleased, 'released_at' => now()]);
        $row->update(['current_outcome_code' => '2.00', 'released_at' => now()]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)->withQueryParams(['result' => $row->id])
            ->test(RowsRelationManager::class, ['ownerRecord' => $row->roster->fresh(), 'pageClass' => ViewGradeRoster::class])
            ->assertViewHas('selected', fn (GradeRosterRow $selected): bool => $selected->is($row));
        Livewire::actingAs($registrar)->withQueryParams(['result' => $row->id + 1000000])
            ->test(RowsRelationManager::class, ['ownerRecord' => $row->roster->fresh(), 'pageClass' => ViewGradeRoster::class])
            ->assertViewHas('selected', fn (GradeRosterRow $selected): bool => $selected->is($row));
    }

    #[Test]
    public function inc_workspace_selection_stays_within_visible_released_results(): void
    {
        [, $row, $registrar] = $this->rosterFixture();
        $row->roster->update(['state' => GradeRoster::StateReleased, 'released_at' => now()]);
        $row->update(['current_outcome_code' => 'INC', 'released_at' => now()]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::actingAs($registrar)->test(GradesAndCompletion::class, ['viewTab' => 'inc-corrections'])
            ->assertSeeHtml('aria-label="Selected course result"')
            ->call('selectIncResult', $row->id)
            ->assertSet('selectedIncResultId', $row->id)
            ->call('selectIncResult', $row->id + 1000000)
            ->assertSet('selectedIncResultId', $row->id)
            ->assertTableActionVisible('recordCorrection', $row)
            ->mountTableAction('recordCorrection', $row)
            ->assertActionMounted(TestAction::make('recordCorrection')->table($row));

        $page->assertMountedActionModalSee('Record correction · '.$row->courseEnrollment->enrollment->studentProfile->student_number)
            ->assertMountedActionModalSeeHtml('role="dialog"')
            ->call('unmountAction')
            ->searchTable('No matching student')
            ->assertSee('No released course results match')
            ->assertDontSeeHtml('aria-label="Selected course result"')
            ->call('selectIncResult', $row->id)
            ->call('resetTableSearch');

        $row->update(['current_outcome_code' => '2.00']);
        $page->filterTable('result_scope', 'all')
            ->assertSee('Record a correction only when')
            ->filterTable('result_scope', 'inc')
            ->assertSee('No released course results match')
            ->assertDontSee('Record a correction only when');
    }

    private function rosterFixture(): array
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $offering = TermOffering::factory()->create();
        $section = Section::factory()->create(['term_offering_id' => $offering->id]);
        $student = StudentProfile::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $student->id,
            'credential_user_id' => $student->user_id,
            'term_id' => $offering->term_id,
            'officially_enrolled_at' => now(),
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
        ]);
        CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => 3,
            'added_at' => now(),
        ]);
        $package = TermCalendarPackage::factory()->create([
            'term_id' => $offering->term_id,
            'state' => TermCalendarPackage::StateActive,
        ]);
        TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeGradeEntry,
            'opens_on' => now('Asia/Manila')->subDay()->toDateString(),
            'closes_on' => now('Asia/Manila')->addDay()->toDateString(),
            'cutoff_at' => null,
        ]);
        app(ManageTeachingAssignment::class)->designate($section, $faculty, $registrar, 'REG-T03-DESIGNATED-001');
        $row = app(SynchronizeOfficialGradeRoster::class)->execute($section, $registrar)->rows->sole();

        return [$faculty, $row, $registrar, $section];
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
