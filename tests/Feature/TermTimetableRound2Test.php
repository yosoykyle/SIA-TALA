<?php

namespace Tests\Feature;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Scheduling\AdjustCandidateMeeting;
use App\Actions\Scheduling\RevertCandidateRun;
use App\Filament\Pages\TermTimetable;
use App\Models\CandidateScheduleRow;
use App\Models\ScheduleGenerationRun;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\Issue58MultiProgramExplorationSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class TermTimetableRound2Test extends TestCase
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

        Role::findByName(User::StaffRoleRegistrar, 'web')
            ->givePermissionTo(Permission::findOrCreate('manage-schedules', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_revert_candidate_run_supports_audit_safe_undo_and_reset_to_solver(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixture = $this->seedMultiProgramFixture($registrar);

        $rootRun = ScheduleGenerationRun::query()->findOrFail($fixture['draft_run_id']);
        $originalRow = $rootRun->candidateRows()->orderBy('id')->firstOrFail();
        $originalRowCount = $rootRun->candidateRows()->count();

        $edit1 = app(AdjustCandidateMeeting::class)->execute(
            requestedRow: $originalRow,
            actor: $registrar,
            assignment: [
                'day_of_week' => 5,
                'starts_at' => '09:00:00',
                'ends_at' => '10:00:00',
            ],
            reason: 'First manual slot move',
        );

        $edit1Row = $edit1->candidateRows()
            ->where('scheduling_demand_id', $originalRow->scheduling_demand_id)
            ->where('meeting_sequence', $originalRow->meeting_sequence)
            ->firstOrFail();

        $edit2 = app(AdjustCandidateMeeting::class)->execute(
            requestedRow: $edit1Row,
            actor: $registrar,
            assignment: [
                'day_of_week' => 5,
                'starts_at' => '10:30:00',
                'ends_at' => '11:30:00',
            ],
            reason: 'Second manual slot move',
        );

        $this->assertSame(2, RevertCandidateRun::manualEditCount($edit2));
        $this->assertSame($rootRun->id, RevertCandidateRun::rootSolverRun($edit2)?->id);

        // Undo last change: edit2 -> edit1 (audit-safe immutable successor).
        $undone = app(RevertCandidateRun::class)->execute(
            currentRun: $edit2,
            targetRun: $edit1,
            actor: $registrar,
            event: RevertCandidateRun::EventUndo,
        );

        $this->assertSame(ScheduleGenerationRun::StatusSuperseded, $edit2->fresh()->status);
        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $undone->status);
        $this->assertSame(1, RevertCandidateRun::manualEditCount($undone));
        $this->assertSame($rootRun->id, RevertCandidateRun::parentRunId($undone));

        // Zero rows deleted on any earlier run.
        $this->assertSame($originalRowCount, $rootRun->candidateRows()->count());
        $this->assertSame($originalRowCount, $edit1->candidateRows()->count());
        $this->assertSame($originalRowCount, $edit2->candidateRows()->count());
        $this->assertSame($originalRowCount, $undone->candidateRows()->count());

        $undoneRow = $undone->candidateRows()
            ->where('scheduling_demand_id', $originalRow->scheduling_demand_id)
            ->where('meeting_sequence', $originalRow->meeting_sequence)
            ->firstOrFail();

        $this->assertSame(5, (int) $undoneRow->day_of_week);
        $this->assertSame('09:00:00', (string) $undoneRow->starts_at);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'scheduling',
            'subject_type' => ScheduleGenerationRun::class,
            'subject_id' => $undone->id,
            'event' => RevertCandidateRun::EventUndo,
            'causer_id' => $registrar->id,
        ]);

        // Reset to solver draft: undone -> rootRun.
        $reset = app(RevertCandidateRun::class)->execute(
            currentRun: $undone,
            targetRun: $rootRun,
            actor: $registrar,
            event: RevertCandidateRun::EventRevertedToSolver,
        );

        $this->assertSame(ScheduleGenerationRun::StatusSuperseded, $undone->fresh()->status);
        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $reset->status);
        $this->assertSame(0, RevertCandidateRun::manualEditCount($reset));
        $this->assertFalse(RevertCandidateRun::isLocalAdjustment($reset));

        $resetRow = $reset->candidateRows()
            ->where('scheduling_demand_id', $originalRow->scheduling_demand_id)
            ->where('meeting_sequence', $originalRow->meeting_sequence)
            ->firstOrFail();

        $this->assertSame((int) $originalRow->day_of_week, (int) $resetRow->day_of_week);
        $this->assertSame((string) $originalRow->starts_at, (string) $resetRow->starts_at);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'scheduling',
            'subject_type' => ScheduleGenerationRun::class,
            'subject_id' => $reset->id,
            'event' => RevertCandidateRun::EventRevertedToSolver,
            'causer_id' => $registrar->id,
        ]);
    }

    public function test_revert_candidate_run_blocks_closed_terms_and_non_ancestor_targets(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixture = $this->seedMultiProgramFixture($registrar);

        $term = Term::query()->findOrFail($fixture['term_id']);
        $rootRun = ScheduleGenerationRun::query()->findOrFail($fixture['draft_run_id']);
        $originalRow = $rootRun->candidateRows()->orderBy('id')->firstOrFail();

        $edit1 = app(AdjustCandidateMeeting::class)->execute(
            requestedRow: $originalRow,
            actor: $registrar,
            assignment: [
                'day_of_week' => 5,
                'starts_at' => '09:00:00',
                'ends_at' => '10:00:00',
            ],
            reason: 'Move before guard check',
        );

        // Non-ancestor run in the same term must be rejected.
        $unrelatedRun = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusSuperseded,
        ]);

        try {
            app(RevertCandidateRun::class)->execute($edit1, $unrelatedRun, $registrar);
            $this->fail('Expected ValidationException when reverting to a non-ancestor run.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('target_run', $exception->errors());
        }

        // Closed term must be rejected.
        $term->update(['state' => Term::StateClosed]);

        try {
            app(RevertCandidateRun::class)->execute($edit1, $rootRun, $registrar);
            $this->fail('Expected ValidationException when reverting a closed term.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('term', $exception->errors());
        }

        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $edit1->fresh()->status);
    }

    public function test_term_timetable_renders_run_scoped_wire_keys_lock_slot_wording_edited_draft_pill_and_decluttered_attempts(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixture = $this->seedMultiProgramFixture($registrar);

        $term = Term::query()->findOrFail($fixture['term_id']);
        $rootRun = ScheduleGenerationRun::query()->findOrFail($fixture['draft_run_id']);
        $originalRow = $rootRun->candidateRows()->orderBy('id')->firstOrFail();

        $edit1 = app(AdjustCandidateMeeting::class)->execute(
            requestedRow: $originalRow,
            actor: $registrar,
            assignment: [
                'day_of_week' => 5,
                'starts_at' => '09:00:00',
                'ends_at' => '10:00:00',
            ],
            reason: 'First interactive move',
        );

        $edit1Row = $edit1->candidateRows()
            ->where('scheduling_demand_id', $originalRow->scheduling_demand_id)
            ->where('meeting_sequence', $originalRow->meeting_sequence)
            ->firstOrFail();

        $edit2 = app(AdjustCandidateMeeting::class)->execute(
            requestedRow: $edit1Row,
            actor: $registrar,
            assignment: [
                'day_of_week' => 5,
                'starts_at' => '10:30:00',
                'ends_at' => '11:30:00',
            ],
            reason: 'Second interactive move',
        );

        /** @var CandidateScheduleRow $firstEdit2Row */
        $firstEdit2Row = $edit2->candidateRows()->orderBy('id')->firstOrFail();

        $page = Livewire::withQueryParams([
            'termId' => $term->id,
            'selectedRunId' => $edit2->id,
            'timetableState' => 'draft',
        ])->test(TermTimetable::class)->assertOk();

        $html = $page->html();

        // Vanishing-meetings fix: active run ID in <x-tala-timetable> wire:key & meeting IDs in week/list keys.
        $this->assertStringContainsString("wire:key=\"guide-week-{$term->id}-draft-{$edit2->id}\"", $html);
        $this->assertStringContainsString("wire:key=\"week-meeting-{$firstEdit2Row->id}\"", $html);
        $this->assertStringContainsString("wire:key=\"list-meeting-{$firstEdit2Row->id}\"", $html);

        // Lock slot terminology replaces Pin/Unpin wording.
        $page->assertSee('Lock slot')
            ->assertSee('Keeps this class fixed to this day, time, room, and teacher if you regenerate.')
            ->assertDontSee('Pin meeting')
            ->assertDontSee('Unpin meeting');

        // Plain-language clickable status pill + decluttered grouped attempt selector.
        $page->assertSee('Edited draft')
            ->assertSeeHtml('title="Click to inspect verification checks"')
            ->assertSee("Current draft · Attempt #{$edit2->id} (2 edits)")
            ->assertDontSeeHtml("value=\"draft:{$edit1->id}\"")
            ->assertActionVisible('undoLastChange')
            ->assertActionVisible('resetToSolverDraft')
            ->assertActionVisible('regenerateTimetable');

        // Clicking Undo last change advances to an immutable successor with updated wire:key.
        $page->callAction('undoLastChange')->assertHasNoActionErrors();
        $undoneRunId = (int) $page->get('selectedRunId');
        $this->assertNotSame($edit2->id, $undoneRunId);
        $this->assertStringContainsString("wire:key=\"guide-week-{$term->id}-draft-{$undoneRunId}\"", $page->html());
        $page->assertSee("Current draft · Attempt #{$undoneRunId} (1 edit)");

        // Official view uses concise "Revise schedule" button label.
        Livewire::withQueryParams([
            'termId' => $term->id,
            'timetableState' => 'official',
            'selectedVersionId' => $fixture['published_version_id'],
        ])->test(TermTimetable::class)
            ->assertOk()
            ->assertSee('Revise schedule');
    }

    /** @return array{term_id:int,draft_run_id:int,published_version_id:int} */
    private function seedMultiProgramFixture(User $registrar): array
    {
        $prefix = Issue58MultiProgramExplorationSeeder::Prefix.'-R2-'.Str::lower(Str::random(8));

        return app(Issue58MultiProgramExplorationSeeder::class)->ensure($registrar, $prefix);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $this->actingAs($user);
        session()->put(WorkspaceContextResolver::SessionKey, $role);

        return $user;
    }
}
