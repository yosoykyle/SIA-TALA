<?php

namespace Tests\Feature;

use App\Actions\Admissions\PublishAdmissionCycle;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Filament\Resources\AdmissionCycles\Pages\CreateAdmissionCycle;
use App\Filament\Resources\AdmissionCycles\Pages\EditAdmissionCycle;
use App\Filament\Resources\AdmissionCycles\Pages\ListAdmissionCycles;
use App\Filament\Resources\AdmissionCycles\Pages\ViewAdmissionCycle;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\Term;
use App\Models\User;
use App\Policies\AdmissionCyclePolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Issue57AdmissionCycleDraftWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_cycle_list_search_finds_an_exact_code_without_matching_an_unrelated_cycle(): void
    {
        $this->actingAs($this->registrar());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $matching = AdmissionCycle::factory()->create(['code' => 'ROUND5-CODE-SEARCH', 'label' => 'October intake']);
        $unrelated = AdmissionCycle::factory()->create(['code' => 'ROUND5-OTHER', 'label' => 'December intake']);

        Livewire::test(ListAdmissionCycles::class)
            ->searchTable('ROUND5-CODE-SEARCH')
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$unrelated]);
    }

    public function test_cycle_overview_and_list_close_at_the_exact_boundary_and_do_not_invent_a_missing_window(): void
    {
        $this->actingAs($this->registrar());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->travelTo(now()->startOfSecond());
        $closed = AdmissionCycle::factory()->create([
            'state' => AdmissionCycle::StatePublished,
            'opens_at' => now()->subDay(),
            'closes_at' => now(),
            'correction_closes_at' => now()->addDay(),
        ]);
        $missing = AdmissionCycle::factory()->create([
            'state' => AdmissionCycle::StatePublished, 'opens_at' => null, 'closes_at' => null,
        ]);

        Livewire::test(ViewAdmissionCycle::class, ['record' => $closed->id])
            ->assertSee('Closed — existing reviews continue');
        Livewire::test(ViewAdmissionCycle::class, ['record' => $missing->id])
            ->assertSee('Application window unavailable — review the cycle dates');
        Livewire::test(ListAdmissionCycles::class)
            ->assertTableColumnStateSet('availability', 'Closed', $closed)
            ->assertTableColumnStateSet('availability', 'Window unavailable', $missing);
        $this->travelBack();
    }

    public function test_named_cycle_draft_can_be_saved_before_publication_setup_is_complete(): void
    {
        $this->actingAs($this->registrar());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $term = Term::factory()->create();

        Livewire::test(CreateAdmissionCycle::class)
            ->fillForm(['code' => 'ROUND3-PARTIAL', 'label' => 'Incomplete intake', 'term_id' => $term->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $cycle = AdmissionCycle::query()->where('code', 'ROUND3-PARTIAL')->firstOrFail();
        $this->assertSame(AdmissionCycle::StateDraft, $cycle->state);
        $this->assertNull($cycle->opens_at);
        $this->assertNull($cycle->registrar_owner_id);
        $this->assertSame(0, $cycle->programs()->count());
        $this->assertSame(0, $cycle->events()->count());
    }

    public function test_incomplete_saved_draft_cannot_be_published_even_with_an_approval_reference(): void
    {
        $registrar = $this->registrar();
        $cycle = AdmissionCycle::factory()->create(['opens_at' => null, 'closes_at' => null, 'correction_closes_at' => null]);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ViewAdmissionCycle::class, ['record' => $cycle->id])
            ->assertActionDisabled('publish');

        try {
            app(PublishAdmissionCycle::class)->execute($cycle, $registrar, 'ROUND3-APPROVED-SOURCE');
            $this->fail('An incomplete cycle must remain a draft.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('readiness', $exception->errors());
        }

        $this->assertSame(AdmissionCycle::StateDraft, $cycle->fresh()->state);
        $this->assertSame(0, $cycle->events()->count());
    }

    public function test_resuming_and_saving_draft_keeps_exact_existing_boundary_seconds(): void
    {
        $this->actingAs($this->registrar());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $cycle = AdmissionCycle::factory()->create([
            'opens_at' => '2027-01-02 00:10:37',
            'closes_at' => '2027-01-04 11:20:43',
            'correction_closes_at' => '2027-01-06 13:30:59',
        ]);

        Livewire::test(EditAdmissionCycle::class, ['record' => $cycle->getRouteKey()])
            ->fillForm(['label' => 'Resumed intake'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(AdmissionCycleResource::getUrl('view', ['record' => $cycle]));

        $cycle->refresh();
        $this->assertSame('2027-01-02 00:10:37', $cycle->opens_at->format('Y-m-d H:i:s'));
        $this->assertSame('2027-01-04 11:20:43', $cycle->closes_at->format('Y-m-d H:i:s'));
        $this->assertSame('2027-01-06 13:30:59', $cycle->correction_closes_at->format('Y-m-d H:i:s'));
        $this->assertSame(AdmissionCycle::StateDraft, $cycle->state);
    }

    public function test_draft_discard_is_denied_once_an_application_references_it(): void
    {
        $registrar = $this->registrar();
        $cycle = AdmissionCycle::factory()->create();
        $policy = app(AdmissionCyclePolicy::class);
        $this->assertTrue($policy->delete($registrar, $cycle));
        AdmissionApplication::factory()->create(['admission_cycle_id' => $cycle->id, 'term_id' => $cycle->term_id]);

        $this->assertFalse($policy->delete($registrar, $cycle));
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(EditAdmissionCycle::class, ['record' => $cycle->getRouteKey()])
            ->assertActionHidden('delete');
        $this->assertModelExists($cycle);
    }

    private function registrar(): User
    {
        $role = Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $role->givePermissionTo(Permission::findOrCreate('manage-admission-setup', 'web'));
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $user->saveAppAuthenticationSecret('base32secret3232');
        $user->acknowledgeRecoveryCodeStorage();

        return $user;
    }
}
