<?php

namespace Tests\Feature;

use App\Actions\Scheduling\ReadyTermPlanningProjection;
use App\Filament\Pages\TermTimetable;
use App\Models\PublishedTimetableVersion;
use App\Models\ScheduleGenerationRun;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Issue58TimetableExplorationSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Issue58TimetableExplorationSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_synthetic_overlay_is_valid_populated_idempotent_and_preserves_existing_terms(): void
    {
        $this->seed(DatabaseSeeder::class);
        Http::preventStrayRequests();
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $registrar = User::factory()->create();
        $registrar->assignRole(User::StaffRoleRegistrar);
        $retained = Term::factory()->create();
        $attributes = $retained->fresh()->getAttributes();
        $prefix = 'SYNTH58-TEST-'.$registrar->id;
        $seeder = app(Issue58TimetableExplorationSeeder::class);

        $ids = $seeder->ensure($registrar, $prefix);

        $populated = Term::query()->findOrFail($ids['populated']);
        $this->assertTrue(app(ReadyTermPlanningProjection::class)->forTerm($populated)['ready']);
        $runs = ScheduleGenerationRun::query()->where('term_id', $populated->id)->orderBy('id')->get();
        $this->assertCount(3, $runs);
        $this->assertSame(ScheduleGenerationRun::StatusPublished, $runs[0]->status);
        $this->assertSame(ScheduleGenerationRun::StatusBlocked, $runs[1]->status);
        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $runs[2]->status);
        $this->assertSame(0, $runs[1]->candidateRows()->count());
        $this->assertNotEmpty(data_get($runs[1]->diagnostics, 'solver_result.findings'));
        $this->assertSame(6, $runs[2]->candidateRows()->count());
        $version = PublishedTimetableVersion::query()->where('term_id', $populated->id)->sole();
        $this->assertSame(6, $version->meetings()->count());
        $this->assertStringContainsString('SYNTHETIC', $version->authority_reference);
        $this->assertSame(1, TermCalendarPackage::query()->where('term_id', $populated->id)->where('state', TermCalendarPackage::StateClosed)->count());
        $special = Term::query()->findOrFail($ids['core_only']);
        $this->assertSame(Term::StateActive, $special->state);
        $this->assertSame(Term::TypeSpecialTerm, $special->type);
        $specialCore = TermCalendarPackage::query()->where('term_id', $special->id)->sole();
        $this->assertSame(0, $specialCore->windows()->count());
        $this->assertNull($specialCore->facultyAvailabilityDueAt());
        $this->assertSame(Term::StateDraft, Term::query()->findOrFail($ids['draft'])->state);
        $this->assertSame($attributes, $retained->fresh()->getAttributes());
        $runAttributes = $runs->map->getAttributes()->all();
        $usersBefore = User::query()->count();

        $this->assertSame($ids, $seeder->ensure($registrar, $prefix));

        $this->assertSame($usersBefore, User::query()->count());
        $this->assertSame($runAttributes, ScheduleGenerationRun::query()->where('term_id', $populated->id)->orderBy('id')->get()->map->getAttributes()->all());

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($registrar);
        Livewire::withQueryParams(['termId' => $populated->id, 'viewTab' => 'generate'])
            ->test(TermTimetable::class)
            ->assertSuccessful()
            ->assertSee('Programming fundamentals')
            ->call('showTimetableView', 'list')
            ->assertViewHas('timetablePresentation', fn (array $presentation): bool => collect($presentation['meetings'])->contains('courseCode', $prefix.'-C1'))
            ->assertSee('Synthetic Faculty Alex')
            ->call('selectRun', $runs[1]->id)
            ->assertViewHas('solverFailure', fn (array $failure): bool => $failure['code'] === 'SYNTHETIC_VALIDATION_BLOCKED')
            ->assertSee('No solver was run.');
        Livewire::withQueryParams(['termId' => $populated->id, 'timetableState' => 'official'])
            ->test(TermTimetable::class)
            ->assertSuccessful()
            ->assertSee('Print whole Term')
            ->assertSee($prefix.'-SYNTHETIC-SIGNOFF');
    }

    public function test_seeder_refuses_non_testing_environment_before_writing(): void
    {
        $prior = app()->environment();
        $count = Term::query()->count();
        app()->instance('env', 'production');
        try {
            app(Issue58TimetableExplorationSeeder::class)->run();
            $this->fail('Non-testing seed was allowed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('testing/mysql/test_tala_db', $exception->getMessage());
            $this->assertSame($count, Term::query()->count());
        } finally {
            app()->instance('env', $prior);
        }
    }
}
