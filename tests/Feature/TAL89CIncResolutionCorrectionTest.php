<?php

namespace Tests\Feature;

use App\Actions\Grades\AmendIncDeadline;
use App\Actions\Grades\IncDeadlineService;
use App\Actions\Grades\RecordApprovedGradeCorrection;
use App\Actions\Grades\ReleaseIncCompletion;
use App\Actions\Grades\ReturnIncCompletion;
use App\Actions\Grades\SubmitIncCompletion;
use App\Filament\Pages\FacultyGradeRoster;
use App\Filament\Resources\GradeRosters\Pages\ViewGradeRoster;
use App\Filament\Resources\GradeRosters\RelationManagers\RowsRelationManager;
use App\Filament\Resources\GradeRosters\Tables\ReleasedResultsTable;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\IncCompletionSubmission;
use App\Models\IncDeadlineAmendment;
use App\Models\OperationalEvent;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL89CIncResolutionCorrectionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_test_codex', DB::connection()->getDatabaseName());

        foreach ([
            'student',
            User::StaffRoleRegistrar,
            User::StaffRoleFaculty,
            User::StaffRoleAcademicHead,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function faculty_submits_and_registrar_releases_inc_completion_with_successor_audit(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, 'INC', GradeRosterRow::CategoryIncomplete);
        $row = $roster->rows()->sole();
        $nextCase = $this->pendingRegistrationCaseFor($roster);

        $incomplete = $row->outcomeEvents()->sole();
        $submission = app(SubmitIncCompletion::class)->execute(
            $incomplete,
            '3.00',
            'Completed removal exam.',
            $faculty,
        );
        $released = app(ReleaseIncCompletion::class)->execute($submission, $registrar);

        $row->refresh();

        $this->assertSame('3.00', $row->current_outcome_code);
        $this->assertSame(GradeRosterRow::CategoryPassing, $row->current_outcome_category);
        $this->assertDatabaseHas('grade_outcome_events', [
            'grade_roster_row_id' => $row->id,
            'event_type' => GradeOutcomeEvent::TypeIncResolution,
            'predecessor_event_id' => $incomplete->id,
            'previous_category' => GradeRosterRow::CategoryIncomplete,
            'new_category' => GradeRosterRow::CategoryPassing,
            'authority' => null,
            'reason' => 'Completed removal exam.',
            'recorded_by' => $registrar->id,
        ]);
        $this->assertSame($released->id, $submission->fresh()->released_event_id);
        $this->assertDatabaseHas('registration_case_events', [
            'enrollment_id' => $nextCase->id,
            'event_type' => 'AcademicResultImpactReviewOpened',
            'actor_id' => $registrar->id,
        ]);
    }

    #[Test]
    public function overdue_inc_remains_inc_and_cannot_be_completed_without_an_amendment(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, 'INC', GradeRosterRow::CategoryIncomplete);
        $row = $roster->rows()->sole();
        $incomplete = $row->outcomeEvents()->sole();
        $incomplete->update(['deadline' => today()->subDay()]);

        try {
            app(SubmitIncCompletion::class)->execute($incomplete->fresh(), '5.00', 'Late work.', $faculty);
            $this->fail('Deadline passage must not permit completion or convert the INC automatically.');
        } catch (RuntimeException) {
            $this->assertSame('INC', $row->fresh()->current_outcome_code);
            $this->assertSame(1, GradeOutcomeEvent::query()->where('grade_roster_row_id', $row->id)->count());
            $this->assertDatabaseCount('inc_completion_submissions', 0);
        }
    }

    #[Test]
    public function submitted_inc_completion_becomes_stale_when_deadline_authority_changes(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, 'INC', GradeRosterRow::CategoryIncomplete);
        $row = $roster->rows()->sole();
        $incomplete = $row->outcomeEvents()->sole();
        $submission = app(SubmitIncCompletion::class)->execute($incomplete, '3.00', 'Completed work.', $faculty);

        app(AmendIncDeadline::class)->execute(
            $incomplete,
            today()->addYears(2),
            'Authorized extension after the submitted completion.',
            $registrar,
        );

        try {
            app(ReleaseIncCompletion::class)->execute($submission, $registrar);
            $this->fail('A deadline amendment must invalidate an earlier completion submission.');
        } catch (RuntimeException) {
            $this->assertSame(IncCompletionSubmission::StateSubmitted, $submission->fresh()->state);
            $this->assertSame('INC', $row->fresh()->current_outcome_code);
            $this->assertSame(1, $row->outcomeEvents()->count());
        }
    }

    #[Test]
    public function submitted_inc_completion_becomes_stale_when_a_correction_supersedes_the_inc(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, 'INC', GradeRosterRow::CategoryIncomplete);
        $row = $roster->rows()->sole();
        $incomplete = $row->outcomeEvents()->sole();
        $submission = app(SubmitIncCompletion::class)->execute($incomplete, '3.00', 'Completed work.', $faculty);

        app(RecordApprovedGradeCorrection::class)->execute($row, '2.75', 'Registrar corrected the controlling released result.', $registrar, 'corr-inc-supersession');

        try {
            app(ReleaseIncCompletion::class)->execute($submission, $registrar);
            $this->fail('A successor result must invalidate an earlier INC completion submission.');
        } catch (RuntimeException) {
            $this->assertSame(IncCompletionSubmission::StateSubmitted, $submission->fresh()->state);
            $this->assertSame('2.75', $row->fresh()->current_outcome_code);
            $this->assertSame(2, $row->outcomeEvents()->count());
        }
    }

    #[Test]
    public function inc_completion_on_non_inc_row_is_rejected(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, '1.75', GradeRosterRow::CategoryPassing);
        $row = $roster->rows()->sole();

        try {
            app(SubmitIncCompletion::class)->execute(
                $row->outcomeEvents()->sole(), '3.00', 'Completion must require an INC.', $faculty,
            );
            $this->fail('INC completion on a non-INC row should be rejected.');
        } catch (RuntimeException) {
            $row->refresh();
            $this->assertSame('1.75', $row->current_outcome_code);
            $this->assertSame(1, GradeOutcomeEvent::query()->where('grade_roster_row_id', $row->id)->count());
            $this->assertSame(0, IncCompletionSubmission::query()->count());
        }
    }

    #[Test]
    public function registrar_records_posted_correction_on_released_row_and_preserves_previous_grade(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, '1.75', GradeRosterRow::CategoryPassing);
        $row = $roster->rows()->sole();
        $nextCase = $this->pendingRegistrationCaseFor($roster);

        app(RecordApprovedGradeCorrection::class)->execute($row, '2.75', 'Physical correction approved.', $registrar);

        $row->refresh();

        $this->assertSame('2.75', $row->current_outcome_code);
        $this->assertSame(GradeRosterRow::CategoryPassing, $row->current_outcome_category);
        $this->assertDatabaseHas('grade_outcome_events', [
            'grade_roster_row_id' => $row->id,
            'event_type' => GradeOutcomeEvent::TypePostedCorrection,
            'previous_value' => '1.7500',
            'previous_category' => GradeRosterRow::CategoryPassing,
            'evidence_reference' => null,
            'recorded_by' => $registrar->id,
        ]);
        $this->assertDatabaseHas('registration_case_events', [
            'enrollment_id' => $nextCase->id,
            'event_type' => 'AcademicResultImpactReviewOpened',
            'actor_id' => $registrar->id,
        ]);
    }

    #[Test]
    public function correction_a_to_b_to_a_preserves_both_successors_and_exact_retry_is_idempotent(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $row = $this->releasedRosterWithRow($faculty, '1.75', GradeRosterRow::CategoryPassing)->rows()->sole();
        $action = app(RecordApprovedGradeCorrection::class);

        $action->execute($row, '2.75', 'Corrected to B.', $registrar, 'command-b');
        $action->execute($row->fresh(), '1.75', 'Corrected back to A.', $registrar, 'command-a2');
        $action->execute($row->fresh(), '1.75', 'Corrected back to A.', $registrar, 'command-a2');

        $events = $row->outcomeEvents()->orderBy('id')->get();
        $this->assertCount(3, $events);
        $this->assertSame(['1.75', '2.75', '1.75'], $events->pluck('result_code')->all());
        $this->assertSame($events[0]->id, $events[1]->predecessor_event_id);
        $this->assertSame($events[1]->id, $events[2]->predecessor_event_id);
        $this->assertSame('1.75', $row->fresh()->current_outcome_code);
    }

    #[Test]
    #[DataProvider('unchangedCorrectionResults')]
    public function unchanged_correction_preserves_the_result_and_records_no_downstream_work(string $currentCode, string $submittedCode): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $category = $currentCode === 'INC' ? GradeRosterRow::CategoryIncomplete : GradeRosterRow::CategoryPassing;
        $roster = $this->releasedRosterWithRow($faculty, $currentCode, $category);
        $row = $roster->rows()->sole();
        $original = $row->outcomeEvents()->sole();
        $this->pendingRegistrationCaseFor($roster);
        $notificationCount = OperationalEvent::query()->count();
        $registrationEventCount = DB::table('registration_case_events')->count();

        try {
            app(RecordApprovedGradeCorrection::class)->execute($row, $submittedCode, 'Checked against the class record.', $registrar, 'unchanged-result', $original->id);
            $this->fail('An unchanged grade must not create a correction or downstream work.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Choose a different final result. The selected result is already the official result.', $exception->getMessage());
        }

        $this->assertSame($currentCode, $row->fresh()->current_outcome_code);
        $this->assertSame([$original->id], $row->outcomeEvents()->pluck('id')->all());
        $this->assertSame($original->deadline?->toDateString(), $original->fresh()->deadline?->toDateString());
        $this->assertSame($notificationCount, OperationalEvent::query()->count());
        $this->assertSame($registrationEventCount, DB::table('registration_case_events')->count());
    }

    /** @return array<string, array{string, string}> */
    public static function unchangedCorrectionResults(): array
    {
        return [
            'numeric normalization' => ['1.75', '1.750'],
            'incomplete normalization' => ['INC', ' inc '],
        ];
    }

    #[Test]
    public function posted_correction_on_unreleased_row_is_rejected(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->rosterWithRow($faculty, GradeRoster::StateSubmitted, submittedBy: $faculty);
        $row = $roster->rows()->sole();

        try {
            app(RecordApprovedGradeCorrection::class)->execute($row, '2.75', 'Reason', $registrar);
            $this->fail('Posted correction on an unreleased row should be rejected.');
        } catch (RuntimeException) {
            $row->refresh();
            $this->assertNull($row->released_at);
            $this->assertSame(0, GradeOutcomeEvent::query()->where('grade_roster_row_id', $row->id)->count());
        }
    }

    #[Test]
    public function non_registrar_staff_cannot_release_inc_completion_through_the_service(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $roster = $this->releasedRosterWithRow($faculty, 'INC', GradeRosterRow::CategoryIncomplete);
        $row = $roster->rows()->sole();
        $submission = app(SubmitIncCompletion::class)->execute(
            $row->outcomeEvents()->sole(), '3.00', 'Completed authorized work.', $faculty,
        );

        foreach ([$faculty, $academicHead] as $actor) {
            try {
                app(ReleaseIncCompletion::class)->execute($submission->fresh(), $actor);
                $this->fail('Only Registrar staff should release INC completion.');
            } catch (AuthorizationException) {
                $this->assertSame('INC', $row->fresh()->current_outcome_code);
                $this->assertSame(1, GradeOutcomeEvent::query()->where('grade_roster_row_id', $row->id)->count());
            }
        }
    }

    #[Test]
    public function non_registrar_staff_cannot_record_posted_correction_through_the_service(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $roster = $this->releasedRosterWithRow($faculty, '1.75', GradeRosterRow::CategoryPassing);
        $row = $roster->rows()->sole();

        foreach ([$faculty, $academicHead] as $actor) {
            try {
                app(RecordApprovedGradeCorrection::class)->execute($row->fresh(), '2.75', 'Reason', $actor);
                $this->fail('Only Registrar staff should record posted corrections.');
            } catch (AuthorizationException) {
                $this->assertSame('1.75', $row->fresh()->current_outcome_code);
                $this->assertSame(1, GradeOutcomeEvent::query()->where('grade_roster_row_id', $row->id)->count());
            }
        }
    }

    #[Test]
    public function rows_relation_manager_is_visible_to_registrar_on_released_roster_only(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $releasedRoster = $this->releasedRosterWithRow($faculty, 'INC', GradeRosterRow::CategoryIncomplete);
        $submittedRoster = $this->rosterWithRow($faculty, GradeRoster::StateSubmitted, submittedBy: $faculty);

        $this->actingAs($registrar);
        $this->assertTrue(RowsRelationManager::canViewForRecord($releasedRoster, ViewGradeRoster::class));
        $this->assertFalse(RowsRelationManager::canViewForRecord($submittedRoster, ViewGradeRoster::class));

        $this->actingAs($academicHead);
        $this->assertTrue(RowsRelationManager::canViewForRecord($releasedRoster, ViewGradeRoster::class));
    }

    #[Test]
    public function registrar_releases_submitted_inc_completion_through_relation_manager_action(): void
    {
        $this->freezeTime();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, 'INC', GradeRosterRow::CategoryIncomplete);
        $row = $roster->rows()->sole();
        app(SubmitIncCompletion::class)->execute(
            $row->outcomeEvents()->sole(), '3.00', 'Completed removal exam.', $faculty,
        );

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::test(RowsRelationManager::class, [
            'ownerRecord' => $roster,
            'pageClass' => ViewGradeRoster::class,
        ])
            ->assertTableActionVisible('releaseIncCompletion', $row)
            ->assertTableActionVisible('recordCorrection', $row)
            ->mountTableAction('releaseIncCompletion', $row)
            ->assertSchemaStateSet([
                'completion_result' => 'INC → 3.00',
                'completion_note' => 'Completed removal exam.',
                'completion_submitted_at' => now('Asia/Manila')->format('M j, Y · g:i A'),
            ]);
        $schema = $page->instance()->{$page->instance()->getMountedActionSchemaName()};
        $this->assertStringContainsString($row->courseEnrollment->enrollment->studentProfile->student_number, (string) $schema->toHtml());
        $this->assertStringContainsString($roster->termOffering->curriculumEntry->courseSpecification->title, (string) $schema->toHtml());
        $this->assertStringContainsString('Academic averages and curriculum readiness will recalculate', (string) $page->instance()->getMountedAction()->getModalDescription());

        $page->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            ->assertNotified('INC completion released');

        $row->refresh();
        $this->assertSame('3.00', $row->current_outcome_code);
        $this->assertDatabaseHas('grade_outcome_events', [
            'grade_roster_row_id' => $row->id,
            'event_type' => GradeOutcomeEvent::TypeIncResolution,
            'authority' => null,
            'recorded_by' => $registrar->id,
            'released_at' => now(),
        ]);
    }

    #[Test]
    public function registrar_records_correction_through_relation_manager_action(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, '1.75', GradeRosterRow::CategoryPassing);
        $row = $roster->rows()->sole();

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(RowsRelationManager::class, [
            'ownerRecord' => $roster,
            'pageClass' => ViewGradeRoster::class,
        ])
            ->assertTableActionVisible('recordCorrection', $row)
            ->assertTableActionHidden('releaseIncCompletion', $row)
            ->callTableAction('recordCorrection', $row, data: [
                'corrected_code' => '2.75',
                'reason' => 'The recorded result did not match the checked class record.',
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Authorized correction recorded');

        $row->refresh();
        $this->assertSame('2.75', $row->current_outcome_code);
        $this->assertDatabaseHas('grade_outcome_events', [
            'grade_roster_row_id' => $row->id,
            'event_type' => GradeOutcomeEvent::TypePostedCorrection,
            'authority' => null,
            'reason' => 'The recorded result did not match the checked class record.',
            'evidence_reference' => null,
            'recorded_by' => $registrar->id,
        ]);
    }

    #[Test]
    public function an_unreleased_successor_keeps_inc_open_until_the_successor_is_released(): void
    {
        $this->travelTo(Carbon::parse('2026-05-01 12:00:00', 'Asia/Manila'));
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, 'INC', GradeRosterRow::CategoryIncomplete);
        $incomplete = $roster->rows()->sole()->outcomeEvents()->sole();
        $successor = GradeOutcomeEvent::factory()->create([
            'grade_roster_row_id' => $incomplete->grade_roster_row_id,
            'event_type' => GradeOutcomeEvent::TypePendingReplacement,
            'predecessor_event_id' => $incomplete->id,
            'result_code' => '3.00',
            'recorded_by' => $faculty->id,
            'released_at' => null,
        ]);
        $deadlines = app(IncDeadlineService::class);

        $this->assertSame(IncDeadlineService::StateCompletionOpen, $deadlines->state($incomplete));
        $this->assertSame('INC', $incomplete->fresh()->result_code);
        $this->assertSame(2, $roster->rows()->sole()->outcomeEvents()->count());

        $successor->update(['released_at' => now()]);

        $this->assertSame(IncDeadlineService::StateResolved, $deadlines->state($incomplete));
        $this->assertSame('INC', $incomplete->fresh()->result_code);
    }

    #[Test]
    #[DataProvider('inclusiveManilaDeadlineInstants')]
    public function inc_deadline_uses_the_inclusive_manila_day_for_utc_and_local_instants(string $instant, string $timezone, string $expectedState): void
    {
        $this->travelTo(Carbon::parse('2026-05-01 12:00:00', 'Asia/Manila'));
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, 'INC', GradeRosterRow::CategoryIncomplete);
        $incomplete = $roster->rows()->sole()->outcomeEvents()->sole();
        $incomplete->update(['deadline' => '2026-05-31']);
        $at = Carbon::parse($instant, $timezone);
        $originalInstant = $at->toIso8601String();

        $state = app(IncDeadlineService::class)->state($incomplete->fresh(), $at);

        $this->assertSame($expectedState, $state);
        $this->assertSame($originalInstant, $at->toIso8601String());
        $this->assertSame('INC', $incomplete->fresh()->result_code);
        $this->assertSame(1, $roster->rows()->sole()->outcomeEvents()->count());
    }

    /** @return array<string, array{string, string, string}> */
    public static function inclusiveManilaDeadlineInstants(): array
    {
        return [
            'UTC inclusive final second' => ['2026-05-31 15:59:59', 'UTC', IncDeadlineService::StateCompletionOpen],
            'UTC next Manila day' => ['2026-05-31 16:00:00', 'UTC', IncDeadlineService::StateCompletionOverdue],
            'Manila inclusive final microsecond' => ['2026-05-31 23:59:59.999999', 'Asia/Manila', IncDeadlineService::StateCompletionOpen],
            'Manila next day' => ['2026-06-01 00:00:00', 'Asia/Manila', IncDeadlineService::StateCompletionOverdue],
        ];
    }

    #[Test]
    public function stale_correction_source_cannot_overwrite_a_later_released_result_and_exact_retry_stays_idempotent(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, '1.75', GradeRosterRow::CategoryPassing);
        $row = $roster->rows()->sole();
        $reviewedEvent = $row->outcomeEvents()->sole();
        $corrections = app(RecordApprovedGradeCorrection::class);
        $corrections->execute($row, '2.75', 'The approved correction replaces the reviewed result.', $registrar, 'correction-current', $reviewedEvent->id);
        $notificationCount = OperationalEvent::query()->count();

        try {
            $corrections->execute($row, '3.00', 'This old form must not replace a later result.', $registrar, 'correction-stale', $reviewedEvent->id);
            $this->fail('A correction must reject a result changed after the Registrar reviewed it.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The released result changed. Reload and review the current result before recording a correction.', $exception->getMessage());
        }

        $corrections->execute($row, '2.75', 'The approved correction replaces the reviewed result.', $registrar, 'correction-current', $reviewedEvent->id);
        $this->assertSame('2.75', $row->fresh()->current_outcome_code);
        $this->assertSame(2, $row->outcomeEvents()->count());
        $this->assertSame($reviewedEvent->id, $row->outcomeEvents()->latest('id')->firstOrFail()->predecessor_event_id);
        $this->assertSame($notificationCount, OperationalEvent::query()->count());
    }

    #[Test]
    public function stale_deadline_source_records_no_amendment_and_exact_retry_reuses_the_recorded_amendment(): void
    {
        $this->freezeTime();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, 'INC', GradeRosterRow::CategoryIncomplete);
        $incomplete = $roster->rows()->sole()->outcomeEvents()->sole();
        $reviewedDeadline = app(IncDeadlineService::class)->currentDeadline($incomplete)->toDateString();
        $amendments = app(AmendIncDeadline::class);
        $first = $amendments->execute($incomplete, today()->addYears(2), 'The institution approved a new completion deadline.', $registrar, $reviewedDeadline);
        $this->assertNull($first->fresh()->getRawOriginal('authority_date'));
        $this->assertNull($first->fresh()->getRawOriginal('authority_reference'));
        $this->assertSame($registrar->id, $first->recorded_by);
        $notificationCount = OperationalEvent::query()->count();

        try {
            $amendments->execute($incomplete, today()->addYears(3), 'This old form must not supersede the newer deadline.', $registrar, $reviewedDeadline);
            $this->fail('An amendment must reject a deadline changed after the Registrar reviewed it.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The INC deadline changed. Reload and review the current deadline before recording an amendment.', $exception->getMessage());
        }

        $retry = $amendments->execute($incomplete, today()->addYears(2), 'The institution approved a new completion deadline.', $registrar, $reviewedDeadline);
        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, IncDeadlineAmendment::query()->where('grade_outcome_event_id', $incomplete->id)->count());
        $this->assertSame(today()->addYears(2)->toDateString(), app(IncDeadlineService::class)->currentDeadline($incomplete)->toDateString());
        $this->assertSame($notificationCount, OperationalEvent::query()->count());
        $this->assertSame('INC', $incomplete->fresh()->result_code);
    }

    #[Test]
    public function registrar_changes_an_inc_deadline_with_only_the_new_date_and_reason(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $roster = $this->releasedRosterWithRow($this->staff(User::StaffRoleFaculty), 'INC', GradeRosterRow::CategoryIncomplete);
        $row = $roster->rows()->sole();
        $inc = $row->outcomeEvents()->sole();
        $previousDeadline = app(IncDeadlineService::class)->currentDeadline($inc)->toDateString();
        $newDeadline = today()->addYears(2)->toDateString();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(RowsRelationManager::class, ['ownerRecord' => $roster, 'pageClass' => ViewGradeRoster::class])
            ->callTableAction('amendIncDeadline', $row, data: [
                'new_deadline' => $newDeadline,
                'reason' => 'More time is needed to complete the final project.',
            ])
            ->assertHasNoTableActionErrors();

        $amendment = $inc->deadlineAmendments()->sole();
        $this->assertSame($previousDeadline, $amendment->previous_deadline->toDateString());
        $this->assertSame($newDeadline, $amendment->new_deadline->toDateString());
        $this->assertSame($registrar->id, $amendment->recorded_by);
        $this->assertNotNull($amendment->recorded_at);
        $this->assertNull($amendment->fresh()->getRawOriginal('authority_date'));
        $this->assertNull($amendment->fresh()->getRawOriginal('authority_reference'));
    }

    #[Test]
    public function deadline_amendment_still_rejects_a_blank_reason_and_an_unauthorized_actor_without_paper_references(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $roster = $this->releasedRosterWithRow($faculty, 'INC', GradeRosterRow::CategoryIncomplete);
        $inc = $roster->rows()->sole()->outcomeEvents()->sole();

        try {
            app(AmendIncDeadline::class)->execute($inc, today()->addYears(2), '   ', $registrar);
            $this->fail('A deadline change needs a reason.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('reason', $exception->getMessage());
        }

        try {
            app(AmendIncDeadline::class)->execute($inc, today()->addYears(2), 'More time is needed to complete the project.', $faculty);
            $this->fail('Faculty cannot change an INC deadline.');
        } catch (AuthorizationException $exception) {
            $this->assertStringContainsString('Registrar', $exception->getMessage());
        }

        $this->assertSame(0, $inc->deadlineAmendments()->count());
    }

    #[Test]
    public function deadline_amendment_rejects_a_reviewed_source_after_the_date_changes_away_and_back(): void
    {
        $this->freezeTime();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $roster = $this->releasedRosterWithRow($this->staff(User::StaffRoleFaculty), 'INC', GradeRosterRow::CategoryIncomplete);
        $inc = $roster->rows()->sole()->outcomeEvents()->sole();
        $originalDeadline = app(IncDeadlineService::class)->currentDeadline($inc);
        $amendments = app(AmendIncDeadline::class);
        $first = $amendments->execute($inc, $originalDeadline->copy()->addMonth(), 'Approved first deadline extension.', $registrar, $originalDeadline->toDateString(), expectedIncEventId: $inc->id, expectedAmendmentId: null);
        $second = $amendments->execute($inc, $originalDeadline, 'Approved correction back to the original date.', $registrar, $first->new_deadline->toDateString(), expectedIncEventId: $inc->id, expectedAmendmentId: $first->id);
        $notificationCount = OperationalEvent::query()->count();

        try {
            $amendments->execute($inc, $originalDeadline->copy()->addMonths(2), 'This old approval must not replace the newer authority.', $registrar, $originalDeadline->toDateString(), expectedIncEventId: $inc->id, expectedAmendmentId: null);
            $this->fail('A matching date must not conceal an intervening deadline amendment.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('changed', $exception->getMessage());
        }

        $this->assertSame(2, $inc->deadlineAmendments()->count());
        $this->assertSame($second->id, $inc->deadlineAmendments()->latest('id')->firstOrFail()->id);
        $this->assertSame($originalDeadline->toDateString(), app(IncDeadlineService::class)->currentDeadline($inc)->toDateString());
        $this->assertSame($notificationCount, OperationalEvent::query()->count());

        $third = $amendments->execute($inc, $first->new_deadline, $first->reason, $registrar, $originalDeadline->toDateString(), expectedIncEventId: $inc->id, expectedAmendmentId: $second->id);
        $this->assertNotSame($first->id, $third->id);
        $this->assertSame(3, $inc->deadlineAmendments()->count());
        $this->assertSame($first->new_deadline->toDateString(), app(IncDeadlineService::class)->currentDeadline($inc)->toDateString());
        $retry = $amendments->execute($inc, $first->new_deadline, $first->reason, $registrar, $originalDeadline->toDateString(), expectedIncEventId: $inc->id, expectedAmendmentId: $second->id);
        $this->assertSame($third->id, $retry->id);
        $this->assertSame(3, $inc->deadlineAmendments()->count());
    }

    #[Test]
    public function deadline_amendment_rejects_an_old_inc_identity_even_when_the_reviewed_date_matches(): void
    {
        $this->freezeTime();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $roster = $this->releasedRosterWithRow($this->staff(User::StaffRoleFaculty), 'INC', GradeRosterRow::CategoryIncomplete);
        $row = $roster->rows()->sole();
        $reviewedInc = $row->outcomeEvents()->sole();
        app(RecordApprovedGradeCorrection::class)->execute($row, '3.00', 'The checked result replaces the original incomplete result.', $registrar, 'inc-source-completed', $reviewedInc->id);
        $completed = $row->outcomeEvents()->latest('id')->firstOrFail();
        app(RecordApprovedGradeCorrection::class)->execute($row->fresh(), 'INC', 'The approved correction restores an incomplete result.', $registrar, 'inc-source-successor', $completed->id);
        $successor = $row->outcomeEvents()->latest('id')->firstOrFail();
        $deadline = app(IncDeadlineService::class)->currentDeadline($successor);
        $notificationCount = OperationalEvent::query()->count();

        try {
            app(AmendIncDeadline::class)->execute($successor, $deadline->copy()->addMonth(), 'This approval was prepared for the previous incomplete result.', $registrar, $deadline->toDateString(), expectedIncEventId: $reviewedInc->id, expectedAmendmentId: null);
            $this->fail('A matching date must not permit approval for a different INC event.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('changed', $exception->getMessage());
        }

        $this->assertSame(0, $successor->deadlineAmendments()->count());
        $this->assertSame(3, $row->outcomeEvents()->count());
        $this->assertSame($notificationCount, OperationalEvent::query()->count());
    }

    #[Test]
    #[DataProvider('invalidDeadlineReasonLengths')]
    public function deadline_amendment_rejects_reason_lengths_outside_the_authorized_contract(int $length): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $roster = $this->releasedRosterWithRow($this->staff(User::StaffRoleFaculty), 'INC', GradeRosterRow::CategoryIncomplete);
        $inc = $roster->rows()->sole()->outcomeEvents()->sole();
        $deadline = app(IncDeadlineService::class)->currentDeadline($inc);

        try {
            app(AmendIncDeadline::class)->execute($inc, $deadline->copy()->addMonth(), str_repeat('a', $length), $registrar);
            $this->fail('Deadline reasons must contain 10 to 1000 characters.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('10', $exception->getMessage());
            $this->assertStringContainsString('1000', $exception->getMessage());
        }

        $this->assertSame(0, $inc->deadlineAmendments()->count());
        $this->assertDatabaseCount('operational_events', 0);
    }

    #[Test]
    #[DataProvider('validDeadlineReasonLengths')]
    public function deadline_amendment_accepts_the_inclusive_reason_length_boundaries(int $length): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $roster = $this->releasedRosterWithRow($this->staff(User::StaffRoleFaculty), 'INC', GradeRosterRow::CategoryIncomplete);
        $inc = $roster->rows()->sole()->outcomeEvents()->sole();
        $deadline = app(IncDeadlineService::class)->currentDeadline($inc);
        $reason = str_repeat('a', $length);

        $amendment = app(AmendIncDeadline::class)->execute($inc, $deadline->copy()->addMonth(), $reason, $registrar, $deadline->toDateString(), expectedIncEventId: $inc->id, expectedAmendmentId: null);

        $this->assertSame($reason, $amendment->reason);
        $this->assertSame($deadline->copy()->addMonth()->toDateString(), app(IncDeadlineService::class)->currentDeadline($inc)->toDateString());
        $this->assertSame(1, $inc->deadlineAmendments()->count());
    }

    /** @return array<string, array{int}> */
    public static function invalidDeadlineReasonLengths(): array
    {
        return ['too short' => [9], 'too long' => [1001]];
    }

    /** @return array<string, array{int}> */
    public static function validDeadlineReasonLengths(): array
    {
        return ['minimum' => [10], 'maximum' => [1000]];
    }

    #[Test]
    #[DataProvider('blankCorrectionReasons')]
    public function a_correction_requires_a_reason_before_writing(string $authority, string $reason): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, '1.75', GradeRosterRow::CategoryPassing);
        $row = $roster->rows()->sole();

        try {
            app(RecordApprovedGradeCorrection::class)->execute($row, '2.75', $reason, $registrar);
            $this->fail('A correction must explain why the released result is changing.');
        } catch (RuntimeException $exception) {
            $this->assertSame('A reason for the grade correction is required.', $exception->getMessage());
        }

        $this->assertSame('1.75', $row->fresh()->current_outcome_code);
        $this->assertSame(1, $row->outcomeEvents()->count());
        $this->assertDatabaseCount('operational_events', 0);
    }

    /** @return array<string, array{string, string}> */
    public static function blankCorrectionReasons(): array
    {
        return [
            'without an external reference' => ['', '  '],
            'with a legacy reference' => ['CORR-AUTHORITY-001', " \t "],
        ];
    }

    #[Test]
    public function released_result_inspector_keeps_selection_inside_the_visible_class_query(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, '1.75', GradeRosterRow::CategoryPassing);
        $row = $roster->rows()->sole();
        $foreign = $this->releasedRosterWithRow($faculty, '2.75', GradeRosterRow::CategoryPassing)->rows()->sole();

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(RowsRelationManager::class, [
            'ownerRecord' => $roster,
            'pageClass' => ViewGradeRoster::class,
        ])
            ->assertViewHas('selected', fn (GradeRosterRow $selected): bool => $selected->is($row))
            ->call('selectResult', $foreign->id)
            ->assertSet('selectedResultId', null)
            ->assertViewHas('selected', fn (GradeRosterRow $selected): bool => $selected->is($row))
            ->set('tableSearch', 'unmatched-student-number')
            ->assertViewHas('selected', null)
            ->assertSee('No matching students')
            ->assertDontSee('Current released result')
            ->set('tableSearch', $row->courseEnrollment->enrollment->studentProfile->student_number)
            ->assertViewHas('selected', fn (GradeRosterRow $selected): bool => $selected->is($row));
    }

    #[Test]
    public function head_inspector_shows_released_history_without_private_successors_or_write_actions(): void
    {
        $head = $this->staff(User::StaffRoleAcademicHead);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, '1.75', GradeRosterRow::CategoryPassing);
        $row = $roster->rows()->sole();
        $privateEvent = $row->outcomeEvents()->sole()->replicate();
        $privateEvent->fill([
            'result_code' => '2.50',
            'source_key' => 'private-inspector-successor:'.$row->id,
            'released_at' => null,
            'event_type' => GradeOutcomeEvent::TypePendingReplacement,
        ])->save();

        $this->actingAs($head);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(RowsRelationManager::class, [
            'ownerRecord' => $roster,
            'pageClass' => ViewGradeRoster::class,
        ])
            ->assertViewHas('history', fn ($history): bool => $history->count() === 1 && ! $history->contains('id', $privateEvent->id))
            ->assertSee('1.75')
            ->assertDontSee('2.50')
            ->assertDontSee('Record authorized correction')
            ->assertDontSee('Release INC completion')
            ->assertDontSee('Change INC deadline')
            ->assertTableActionHidden('recordCorrection', $row)
            ->assertTableActionHidden('releaseIncCompletion', $row)
            ->assertTableActionHidden('amendIncDeadline', $row);
    }

    #[Test]
    public function loaded_inc_follow_up_distinguishes_awaiting_faculty_and_submitted_work_without_per_row_queries(): void
    {
        $this->freezeTime();
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, 'INC', GradeRosterRow::CategoryIncomplete);
        $row = $roster->rows()->sole();
        $incomplete = $row->outcomeEvents()->sole();
        $waiting = ReleasedResultsTable::followUp($row);
        $this->assertSame('Faculty', $waiting['owner']);
        $this->assertSame('Awaiting Faculty completion', $waiting['completion_status']);
        $this->assertFalse($waiting['can_release']);
        app(SubmitIncCompletion::class)->execute($incomplete, '3.00', 'Completed the outstanding work.', $faculty);
        $row->load(['outcomeEvents.completionSubmissions', 'outcomeEvents.deadlineAmendments']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $followUp = ReleasedResultsTable::followUp($row);
            ReleasedResultsTable::followUp($row);
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame('Registrar', $followUp['owner']);
        $this->assertSame('Ready for Registrar review', $followUp['completion_status']);
        $this->assertTrue($followUp['can_release']);
        $this->assertSame($incomplete->deadline->format('M j, Y'), $followUp['deadline']);
        $this->assertSame($incomplete->inc_completion_note, $followUp['note']);
    }

    #[Test]
    #[DataProvider('staleCompletionPresentationCases')]
    public function stale_or_overdue_submitted_completion_is_hidden_and_shows_the_current_recovery_owner(string $change): void
    {
        $this->freezeTime();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, 'INC', GradeRosterRow::CategoryIncomplete);
        $row = $roster->rows()->sole();
        $incomplete = $row->outcomeEvents()->sole();
        $submission = app(SubmitIncCompletion::class)->execute($incomplete, '3.00', 'Completed the outstanding work.', $faculty);

        if ($change === 'expiry') {
            $this->travelTo($incomplete->deadline->copy()->addDay()->setHour(12));
        } elseif ($change === 'amendment') {
            app(AmendIncDeadline::class)->execute($incomplete, today()->addYears(2), 'Authorized extension after Faculty submission.', $registrar);
        } else {
            GradeOutcomeEvent::factory()->create([
                'grade_roster_row_id' => $row->id,
                'event_type' => GradeOutcomeEvent::TypePendingReplacement,
                'predecessor_event_id' => $incomplete->id,
                'result_code' => '2.00',
                'recorded_by' => $registrar->id,
                'released_at' => null,
            ]);
        }

        $row->load(['outcomeEvents.completionSubmissions', 'outcomeEvents.deadlineAmendments']);
        $followUp = ReleasedResultsTable::followUp($row);

        $this->assertSame('Registrar', $followUp['owner']);
        $this->assertSame('Submitted completion is stale', $followUp['completion_status']);
        $this->assertFalse($followUp['can_release']);
        $this->assertStringContainsString($change === 'expiry' ? 'authorized future deadline extension' : 'Return the stale completion', $followUp['guidance']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)->test(RowsRelationManager::class, ['ownerRecord' => $roster, 'pageClass' => ViewGradeRoster::class])
            ->assertTableActionHidden('releaseIncCompletion', $row)
            ->assertTableActionVisible('amendIncDeadline', $row)
            ->assertSee($change === 'expiry'
                ? 'Ordinary completion is closed.'
                : 'Submit a replacement against the current result and open deadline.')
            ->assertDontSee('Submit completion of the remaining course work before the deadline.');
        $this->assertSame(IncCompletionSubmission::StateSubmitted, $submission->fresh()->state);
        $this->assertSame('INC', $row->fresh()->current_outcome_code);
    }

    /** @return array<string, array{string}> */
    public static function staleCompletionPresentationCases(): array
    {
        return ['expired' => ['expiry'], 'changed deadline authority' => ['amendment'], 'pending replacement result' => ['result']];
    }

    #[Test]
    public function registrar_returns_stale_completion_then_faculty_resubmits_against_current_authority_for_release(): void
    {
        $this->freezeTime();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, 'INC', GradeRosterRow::CategoryIncomplete);
        $row = $roster->rows()->sole();
        $row->courseEnrollment->update(['section_id' => $roster->section_id, 'is_current' => true]);
        $incomplete = $row->outcomeEvents()->sole();
        $original = app(SubmitIncCompletion::class)->execute($incomplete, '3.00', 'Original Faculty proposal.', $faculty);
        $amendment = app(AmendIncDeadline::class)->execute($incomplete, today()->addYears(2), 'Authorized extension requires a new source review.', $registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)->test(RowsRelationManager::class, ['ownerRecord' => $roster, 'pageClass' => ViewGradeRoster::class])
            ->assertTableActionHidden('releaseIncCompletion', $row)
            ->callTableAction('returnIncCompletion', $row, data: ['reason' => 'Resubmit against the amended completion deadline.'])
            ->assertHasNoTableActionErrors()->assertNotified('INC completion returned for changes');

        $returned = $original->fresh();
        $this->assertSame(IncCompletionSubmission::StateReturned, $returned->state);
        $this->assertSame($registrar->id, $returned->reviewed_by);
        $this->assertSame('Resubmit against the amended completion deadline.', $returned->return_reason);
        $this->assertSame('3.00', $returned->proposed_result);
        $this->assertSame('Original Faculty proposal.', $returned->completion_note);
        $this->assertNull($returned->controlling_deadline_amendment_id);
        $this->assertSame('INC', $row->fresh()->current_outcome_code);
        $this->assertSame(1, $row->outcomeEvents()->count());

        $this->travel(1)->hours();
        $duplicate = app(ReturnIncCompletion::class)->execute($original, $this->staff(User::StaffRoleRegistrar), 'A different explanation must not overwrite review.');
        $this->assertSame($returned->reviewed_by, $duplicate->reviewed_by);
        $this->assertSame($returned->reviewed_at->toISOString(), $duplicate->reviewed_at->toISOString());
        $this->assertSame($returned->return_reason, $duplicate->return_reason);

        Livewire::actingAs($faculty)->test(FacultyGradeRoster::class, ['rosterId' => $roster->id])
            ->assertActionVisible('submitIncCompletion')
            ->assertSee('Resubmit against the amended completion deadline.')
            ->callAction('submitIncCompletion', ['row_id' => $row->id, 'proposed_result' => '2.75', 'completion_note' => 'Reviewed under the amended deadline.'])
            ->assertHasNoActionErrors()->assertNotified('INC completion submitted for Registrar review')
            ->assertActionHidden('submitIncCompletion')
            ->assertSee('Ready for Registrar review')
            ->assertSee('Review and release the Faculty completion, or return it with a reason.')
            ->assertDontSee('Ask the Registrar about the calendar or a scoped late-entry approval.');
        $replacement = IncCompletionSubmission::query()->where('grade_outcome_event_id', $incomplete->id)->latest('id')->firstOrFail();
        $this->assertNotSame($original->id, $replacement->id);
        $this->assertSame($amendment->id, $replacement->controlling_deadline_amendment_id);
        Livewire::actingAs($registrar)->test(RowsRelationManager::class, ['ownerRecord' => $roster, 'pageClass' => ViewGradeRoster::class])
            ->callTableAction('releaseIncCompletion', $row, data: ['authority_reference' => 'INC-REVIEW-RELEASE'])
            ->assertHasNoTableActionErrors()->assertNotified('INC completion released');
        $this->assertSame('2.75', $row->fresh()->current_outcome_code);
        $this->assertSame(2, $row->outcomeEvents()->count());
        $this->assertSame(IncCompletionSubmission::StateReturned, $original->fresh()->state);
        $this->assertSame(IncCompletionSubmission::StateReleased, $replacement->fresh()->state);
        Livewire::actingAs($faculty)->test(FacultyGradeRoster::class, ['rosterId' => $roster->id])
            ->assertSet('data.rows.0.final_result', '2.75')
            ->assertActionHidden('submitIncCompletion');
    }

    #[Test]
    #[DataProvider('completionReviewActions')]
    public function an_old_completion_dialog_cannot_review_a_replacement_submission(string $action): void
    {
        $this->freezeTime();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, 'INC', GradeRosterRow::CategoryIncomplete);
        $row = $roster->rows()->sole();
        $incomplete = $row->outcomeEvents()->sole();
        $original = app(SubmitIncCompletion::class)->execute($incomplete, '3.00', 'First reviewed proposal.', $faculty);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($registrar)->test(RowsRelationManager::class, ['ownerRecord' => $roster, 'pageClass' => ViewGradeRoster::class])
            ->mountTableAction($action, $row)
            ->assertTableActionDataSet(['expected_submission_id' => $original->id]);
        app(ReturnIncCompletion::class)->execute($original, $registrar, 'The first proposal was returned from another review.');
        $replacement = app(SubmitIncCompletion::class)->execute($incomplete, '2.25', 'A new unreviewed proposal.', $faculty);

        $page->setTableActionData($action === 'releaseIncCompletion'
            ? ['authority_reference' => 'STALE-DIALOG-RELEASE']
            : ['reason' => 'This reason belongs to the previous proposal.'])
            ->callMountedTableAction()
            ->assertNotified($action === 'releaseIncCompletion' ? 'Change was not recorded' : 'Completion was not returned');

        $this->assertSame(IncCompletionSubmission::StateSubmitted, $replacement->fresh()->state);
        $this->assertNull($replacement->fresh()->reviewed_by);
        $this->assertSame('INC', $row->fresh()->current_outcome_code);
        $this->assertSame(1, $row->outcomeEvents()->count());
    }

    /** @return array<string, array{string}> */
    public static function completionReviewActions(): array
    {
        return ['release' => ['releaseIncCompletion'], 'return' => ['returnIncCompletion']];
    }

    #[Test]
    public function returning_an_overdue_completion_preserves_the_closed_deadline_and_official_inc(): void
    {
        $this->freezeTime();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, 'INC', GradeRosterRow::CategoryIncomplete);
        $row = $roster->rows()->sole();
        $row->courseEnrollment->update(['section_id' => $roster->section_id, 'is_current' => true]);
        $incomplete = $row->outcomeEvents()->sole();
        $submission = app(SubmitIncCompletion::class)->execute($incomplete, '3.00', 'Submitted before expiry.', $faculty);
        $this->travelTo($incomplete->deadline->copy()->addDay()->setHour(12));

        app(ReturnIncCompletion::class)->execute($submission, $registrar, 'Coordinate the current deadline authority before resubmitting.');

        $this->assertSame(IncDeadlineService::StateCompletionOverdue, app(IncDeadlineService::class)->state($incomplete));
        $this->assertSame('INC', $row->fresh()->current_outcome_code);
        $this->assertSame(1, $row->outcomeEvents()->count());
        Livewire::actingAs($faculty)->test(FacultyGradeRoster::class, ['rosterId' => $roster->id])->assertActionHidden('submitIncCompletion');
    }

    #[Test]
    #[DataProvider('unauthorizedCompletionReviewers')]
    public function only_an_active_registrar_can_return_a_completion(string $role, string $status): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, 'INC', GradeRosterRow::CategoryIncomplete);
        $submission = app(SubmitIncCompletion::class)->execute($roster->rows()->sole()->outcomeEvents()->sole(), '3.00', 'Submitted Faculty proposal.', $faculty);
        $actor = $this->staff($role);
        $actor->update(['status' => $status]);

        try {
            app(ReturnIncCompletion::class)->execute($submission, $actor, 'A review explanation cannot grant Registrar authority.');
            $this->fail('An unauthorized actor returned an INC completion.');
        } catch (AuthorizationException) {
            $this->assertSame(IncCompletionSubmission::StateSubmitted, $submission->fresh()->state);
            $this->assertNull($submission->fresh()->reviewed_by);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function unauthorizedCompletionReviewers(): array
    {
        return [
            'Faculty' => [User::StaffRoleFaculty, User::StatusActive],
            'Academic Head' => [User::StaffRoleAcademicHead, User::StatusActive],
            'inactive Registrar' => [User::StaffRoleRegistrar, User::StatusInactive],
        ];
    }

    #[Test]
    public function a_legitimate_pending_completion_cannot_be_replaced_without_a_registrar_return(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, 'INC', GradeRosterRow::CategoryIncomplete);
        $incomplete = $roster->rows()->sole()->outcomeEvents()->sole();
        $submission = app(SubmitIncCompletion::class)->execute($incomplete, '3.00', 'Submitted Faculty proposal.', $faculty);

        try {
            app(SubmitIncCompletion::class)->execute($incomplete, '2.00', 'An unreviewed replacement.', $faculty);
            $this->fail('A legitimate pending completion was overwritten.');
        } catch (RuntimeException $exception) {
            $this->assertSame('An INC completion is already awaiting Registrar review.', $exception->getMessage());
            $this->assertSame('3.00', $submission->fresh()->proposed_result);
            $this->assertSame(1, $incomplete->completionSubmissions()->count());
        }
    }

    #[Test]
    #[DataProvider('invalidCompletionReturnReasonLengths')]
    public function a_completion_return_requires_a_bounded_explanation_without_changing_the_proposal(int $length): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, 'INC', GradeRosterRow::CategoryIncomplete);
        $submission = app(SubmitIncCompletion::class)->execute($roster->rows()->sole()->outcomeEvents()->sole(), '3.00', 'Submitted Faculty proposal.', $faculty);

        try {
            app(ReturnIncCompletion::class)->execute($submission, $registrar, str_repeat('x', $length));
            $this->fail('A completion was returned without a valid explanation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('An INC completion return reason between 10 and 1000 characters is required.', $exception->getMessage());
            $this->assertSame(IncCompletionSubmission::StateSubmitted, $submission->fresh()->state);
            $this->assertNull($submission->fresh()->reviewed_by);
        }
    }

    /** @return array<string, array{int}> */
    public static function invalidCompletionReturnReasonLengths(): array
    {
        return ['empty' => [0], 'too short' => [9], 'too long' => [1001]];
    }

    private function releasedRosterWithRow(User $faculty, string $code, string $category): GradeRoster
    {
        $roster = $this->rosterWithRow($faculty, GradeRoster::StateReleased, submittedBy: $faculty);
        $roster->update([
            'reviewed_by' => $faculty->id,
            'reviewed_at' => now(),
            'released_by' => $faculty->id,
            'released_at' => now(),
        ]);

        $row = $roster->rows()->sole();
        $row->update([
            'current_outcome_code' => $code,
            'current_outcome_category' => $category,
            'released_at' => now(),
        ]);
        GradeOutcomeEvent::query()->create([
            'grade_roster_row_id' => $row->id,
            'event_type' => GradeOutcomeEvent::TypeInitialRelease,
            'result_code' => $code,
            'source_term_ends_on' => $roster->termOffering->term->ends_on,
            'previous_category' => null,
            'new_category' => $category,
            'new_value' => is_numeric($code) ? (float) $code : null,
            'deadline' => $code === 'INC' ? today()->addYear()->toDateString() : null,
            'inc_completion_note' => $code === 'INC' ? 'Complete the remaining authorized work.' : null,
            'authority' => 'TEST-INITIAL-RELEASE',
            'reason' => 'Synthetic released-result fixture.',
            'recorded_by' => $faculty->id,
            'released_at' => now(),
            'source_key' => 'test-initial-release:'.$row->id,
        ]);

        return $roster->fresh(['rows']);
    }

    private function rosterWithRow(
        User $faculty,
        string $state,
        ?User $submittedBy = null,
        string $studentLastName = 'Student',
    ): GradeRoster {
        $termOffering = TermOffering::factory()->create(['state' => TermOffering::StateScheduled]);
        $section = Section::factory()->create(['term_offering_id' => $termOffering->id]);
        $student = User::factory()->create([
            'status' => User::StatusActive,
            'last_name' => $studentLastName,
        ]);
        $student->assignRole('student');
        $profile = StudentProfile::factory()->create([
            'user_id' => $student->id,
            'program_id' => $termOffering->curriculumEntry->curriculumVersion->program_id,
            'curriculum_version_id' => $termOffering->curriculumEntry->curriculum_version_id,
            'last_name' => $studentLastName,
        ]);
        $enrollment = Enrollment::factory()->create([
            'credential_user_id' => $student->id,
            'student_profile_id' => $profile->id,
            'term_id' => $termOffering->term_id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now(),
        ]);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $termOffering->id,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => 3,
            'added_at' => now(),
        ]);
        $assignment = ClassOfferingTeachingAssignment::query()->create([
            'term_offering_id' => $termOffering->id,
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'role' => ClassOfferingTeachingAssignment::RoleDesignated,
            'state' => ClassOfferingTeachingAssignment::StateActive,
            'authority_reference' => 'TEST-TEACHING-ASSIGNMENT',
            'assigned_by' => $faculty->id,
            'effective_at' => now(),
        ]);
        $roster = GradeRoster::factory()->create([
            'term_offering_id' => $termOffering->id,
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'teaching_assignment_id' => $assignment->id,
            'state' => $state,
            'submitted_by' => $submittedBy?->id,
            'submitted_at' => $submittedBy === null ? null : now(),
        ]);

        GradeRosterRow::query()->create([
            'grade_roster_id' => $roster->id,
            'course_enrollment_id' => $courseEnrollment->id,
        ]);

        return $roster;
    }

    private function pendingRegistrationCaseFor(GradeRoster $roster): Enrollment
    {
        $source = $roster->rows()->sole()->courseEnrollment->enrollment;
        $sourceCourse = $roster->termOffering->curriculumEntry->courseSpecification->course;
        $dependentTerm = Term::factory()->create([
            'starts_on' => $source->term->starts_on->addMonths(5),
            'ends_on' => $source->term->ends_on->addMonths(5),
        ]);
        $dependentSpecification = CourseSpecification::factory()->create([
            'state' => CourseSpecification::StateActive,
        ]);
        CourseRequirement::factory()->create([
            'course_specification_id' => $dependentSpecification->id,
            'related_course_id' => $sourceCourse->id,
            'rule_type' => CourseRequirement::TypePrerequisite,
            'state' => CourseRequirement::StateActive,
        ]);
        $dependentEntry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $source->studentProfile->curriculum_version_id,
            'course_specification_id' => $dependentSpecification->id,
        ]);
        $dependentOffering = TermOffering::factory()->create([
            'term_id' => $dependentTerm->id,
            'curriculum_entry_id' => $dependentEntry->id,
            'state' => TermOffering::StateScheduled,
        ]);

        $nextCase = Enrollment::factory()->create([
            'credential_user_id' => $source->credential_user_id,
            'student_profile_id' => $source->student_profile_id,
            'term_id' => $dependentTerm->id,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
            'status' => 'pending',
        ]);
        CourseEnrollment::query()->create([
            'enrollment_id' => $nextCase->id,
            'term_offering_id' => $dependentOffering->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => 3,
            'added_at' => now(),
        ]);

        return $nextCase;
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
