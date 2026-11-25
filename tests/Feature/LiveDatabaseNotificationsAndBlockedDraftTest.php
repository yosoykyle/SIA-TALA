<?php

namespace Tests\Feature;

use App\Actions\Scheduling\ScheduleGenerationService;
use App\Filament\Components\LiveDatabaseNotifications;
use App\Filament\Pages\TermTimetable;
use App\Models\AcademicYear;
use App\Models\CandidateScheduleRow;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LiveDatabaseNotificationsAndBlockedDraftTest extends TestCase
{
    use DatabaseTransactions;

    public function test_live_database_notifications_surface_only_newly_arrived_notifications_as_toasts_without_deleting_inbox_rows(): void
    {
        $user = User::factory()->create();

        // Pre-existing unread notification created before mount should NOT spam a toast on initial load.
        Notification::make()
            ->title('Older unread inbox item')
            ->body('Already waiting in inbox.')
            ->info()
            ->sendToDatabase($user);

        $olderId = (string) $user->notifications()->latest('created_at')->value('id');
        $user->notifications()->where('id', $olderId)->update(['created_at' => now()->subMinutes(5)]);

        $component = Livewire::actingAs($user)
            ->test(LiveDatabaseNotifications::class)
            ->assertNotNotified();

        // A new background notification arrives (e.g. timetable generation finished).
        Notification::make()
            ->title('Draft timetable ready for Second Semester')
            ->body('Attempt #31 placed 6 meetings with zero conflicts.')
            ->success()
            ->sendToDatabase($user);

        $freshId = (string) $user->notifications()->where('id', '!=', $olderId)->value('id');
        $this->assertNotSame('', $freshId);

        // Next poll dispatches a native floating toast via Filament's Notifications session pipeline
        // with a non-UUID id and auto-dismiss duration.
        $component->call('$refresh')
            ->assertDispatched('notificationsSent');

        $claimed = session()->pull('filament.claimed_notifications') ?? [];
        $this->assertCount(1, $claimed);
        $this->assertSame('live-toast-'.$freshId, $claimed[0]['id'] ?? null);
        $this->assertSame('Draft timetable ready for Second Semester', $claimed[0]['title'] ?? null);
        $this->assertSame(6000, $claimed[0]['duration'] ?? null);

        // Subsequent poll does NOT duplicate the same toast.
        $component->call('$refresh')
            ->assertNotDispatched('notificationsSent');

        // Closing the floating toast does NOT delete the database notification from the user's inbox.
        $component->call('removeNotification', 'live-toast-'.$freshId);
        $this->assertDatabaseHas('notifications', ['id' => $freshId]);
        $this->assertDatabaseHas('notifications', ['id' => $olderId]);
    }

    public function test_blocked_retained_draft_modal_exposes_open_and_discard_and_generate_actions(): void
    {
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $registrar = User::factory()->create();
        $registrar->assignRole(User::StaffRoleRegistrar);

        $year = AcademicYear::factory()->create();
        $term = Term::factory()->for($year, 'academicYear')->create();

        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        $program = Program::factory()->create();
        $curriculumVersion = CurriculumVersion::factory()->for($program)->create();
        $courseSpecification = CourseSpecification::factory()->create();
        $curriculumEntry = CurriculumEntry::factory()
            ->for($curriculumVersion, 'curriculumVersion')
            ->for($courseSpecification, 'courseSpecification')
            ->create();
        $offering = TermOffering::factory()
            ->for($term)
            ->for($curriculumEntry, 'curriculumEntry')
            ->create();
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'term_calendar_package_id' => $package->id,
            'confirmed_at' => now(),
        ]);
        $group = SectionDeliveryGroup::factory()->for($section, 'section')->create();
        $demand = SchedulingDemand::factory()->for($offering, 'termOffering')->for($group, 'sectionDeliveryGroup')->create();
        $room = Room::factory()->create(['is_active' => true]);

        $failedRun = ScheduleGenerationRun::factory()->create([
            'term_id' => $term->id,
            'requested_by' => $registrar->id,
            'status' => ScheduleGenerationRun::StatusFailed,
            'candidate_state' => null,
        ]);

        $retainedDraft = ScheduleGenerationRun::factory()->create([
            'term_id' => $term->id,
            'requested_by' => $registrar->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'candidate_state' => null,
        ]);
        CandidateScheduleRow::query()->create([
            'schedule_run_id' => $retainedDraft->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $registrar->id,
            'room_id' => $room->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:30:00',
            'time_block_key' => 'mon-0800-0930',
            'status' => CandidateScheduleRow::StatusOk,
        ]);

        $freshRunId = null;

        // First call is blocked by the retained draft; second call (after discard) succeeds.
        $this->mock(ScheduleGenerationService::class)
            ->shouldReceive('generate')
            ->once()
            ->andThrow(ValidationException::withMessages([
                'generation' => 'Review or reject the retained candidate, or change its source facts before generating again.',
            ]))
            ->shouldReceive('generate')
            ->once()
            ->andReturnUsing(function () use ($term, $registrar, &$freshRunId): ScheduleGenerationRun {
                $run = ScheduleGenerationRun::factory()->create([
                    'term_id' => $term->id,
                    'requested_by' => $registrar->id,
                    'status' => ScheduleGenerationRun::StatusQueued,
                    'candidate_state' => null,
                ]);
                $freshRunId = $run->id;

                return $run;
            });

        $component = Livewire::actingAs($registrar)
            ->test(TermTimetable::class, [
                'termId' => $term->id,
                'timetableState' => 'draft',
                'selectedRunId' => $failedRun->id,
                'viewTab' => 'generate',
            ]);

        $component->callAction('generateTimetable')
            ->assertDispatched('open-modal', id: 'timetable-generation-failure');

        $failure = $component->get('generationFailure');
        $this->assertSame($retainedDraft->id, $failure['blockingRunId']);

        // User can open that blocking draft directly from the modal.
        $component->call('openBlockingDraft', $retainedDraft->id)
            ->assertDispatched('close-modal', id: 'timetable-generation-failure')
            ->assertSet('selectedRunId', $retainedDraft->id)
            ->assertSet('timetableState', 'draft');

        // User can also discard the blocking draft and immediately generate a fresh attempt.
        $component->call('discardBlockingDraftAndGenerate', $retainedDraft->id)
            ->assertDispatched('close-modal', id: 'timetable-generation-failure')
            ->assertSet('selectedRunId', $freshRunId)
            ->assertSet('timetableState', 'draft')
            ->assertNotified('Regenerating draft timetable');

        $this->assertNotNull($freshRunId);
        $this->assertSame('Rejected', $retainedDraft->fresh()->candidate_state);
    }
}
