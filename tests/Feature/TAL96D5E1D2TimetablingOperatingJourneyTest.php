<?php

namespace Tests\Feature;

use App\Filament\Pages\TermOverview;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ListScheduleGenerationRuns;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ViewScheduleGenerationRun;
use App\Filament\Resources\SectionMeetings\Pages\ListSectionMeetings;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL96D5E1D2TimetablingOperatingJourneyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        Role::query()->firstOrCreate([
            'name' => User::StaffRoleRegistrar,
            'guard_name' => 'web',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function term_planning_workbench_preserves_the_selected_term_across_classes_tab(): void
    {
        $registrar = $this->registrar();
        $term = Term::factory()->create([
            'label' => 'Selected Operating Term',
            'state' => Term::StateActive,
        ]);

        $this->actingAs($registrar)
            ->get(TermOverview::getUrl(['termId' => $term->id]))
            ->assertOk()
            ->assertSee('Selected Operating Term');
    }

    #[Test]
    public function an_open_active_generation_request_refreshes_until_it_reaches_review(): void
    {
        $registrar = $this->registrar();
        $run = $this->scheduleRun(
            Term::factory()->create(),
            ScheduleGenerationRun::StatusQueued,
        );

        $component = Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $run->getRouteKey()])
            ->assertSeeHtml('wire:poll.5s.visible');

        $run->update(['status' => ScheduleGenerationRun::StatusUnderReview]);
        $component->call('refreshScheduleRun')
            ->assertDontSeeHtml('wire:poll.5s.visible');

        $this->assertSame(
            ScheduleGenerationRun::StatusUnderReview,
            $component->get('record')->status,
        );
    }

    #[Test]
    public function generated_timetables_can_be_narrowed_to_the_operating_term(): void
    {
        $registrar = $this->registrar();
        $selected = $this->scheduleRun(Term::factory()->create());
        $other = $this->scheduleRun(Term::factory()->create());

        Livewire::actingAs($registrar)
            ->test(ListScheduleGenerationRuns::class)
            ->assertTableFilterExists('term_id')
            ->filterTable('term_id', $selected->term_id)
            ->assertCanSeeTableRecords([$selected])
            ->assertCanNotSeeTableRecords([$other]);
    }

    #[Test]
    public function published_timetable_supports_term_day_and_section_operating_filters(): void
    {
        $registrar = $this->registrar();
        $selectedRun = $this->scheduleRun(Term::factory()->create(), ScheduleGenerationRun::StatusPublished);
        $otherRun = $this->scheduleRun(Term::factory()->create(), ScheduleGenerationRun::StatusPublished);
        $selectedDemand = SchedulingDemand::factory()->create();
        $otherDemand = SchedulingDemand::factory()->create();
        $faculty = User::factory()->create();
        $selected = $this->officialMeeting($selectedRun, $selectedDemand, $faculty, 1);
        $other = $this->officialMeeting($otherRun, $otherDemand, $faculty, 2);

        Livewire::actingAs($registrar)
            ->test(ListSectionMeetings::class)
            ->assertTableFilterExists('term_id')
            ->assertTableFilterExists('day_of_week')
            ->assertTableFilterExists('section_id')
            ->filterTable('term_id', $selectedRun->term_id)
            ->assertCanSeeTableRecords([$selected])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::actingAs($registrar)
            ->test(ListSectionMeetings::class)
            ->filterTable('day_of_week', 1)
            ->assertCanSeeTableRecords([$selected])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::actingAs($registrar)
            ->test(ListSectionMeetings::class)
            ->filterTable('section_id', $selectedDemand->sectionDeliveryGroup->section_id)
            ->assertCanSeeTableRecords([$selected])
            ->assertCanNotSeeTableRecords([$other]);
    }

    private function registrar(): User
    {
        $registrar = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $registrar->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $registrar->saveAppAuthenticationRecoveryCodes(['stored-code']);

        return $registrar;
    }

    private function scheduleRun(
        Term $term,
        string $status = ScheduleGenerationRun::StatusUnderReview,
    ): ScheduleGenerationRun {
        return ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => $status,
            'requested_by' => null,
            'input_snapshot' => [],
            'input_hash' => hash('sha256', (string) Str::uuid()),
            'solver_version' => 'tal96d5e1d2-test-solver',
        ]);
    }

    private function officialMeeting(
        ScheduleGenerationRun $run,
        SchedulingDemand $demand,
        User $faculty,
        int $dayOfWeek,
    ): SectionMeeting {
        return SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $faculty->id,
            'room_id' => null,
            'day_of_week' => $dayOfWeek,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'modality' => TermOffering::ModalityOnline,
            'state' => SectionMeeting::StateActive,
            'published_at' => now(),
        ]);
    }
}
