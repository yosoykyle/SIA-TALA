<?php

namespace Tests\Feature;

use App\Actions\Admissions\PublishAdmissionCycle;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Filament\Resources\AdmissionCycles\Pages\CreateAdmissionCycle;
use App\Filament\Resources\AdmissionCycles\Pages\EditAdmissionCycle;
use App\Filament\Resources\AdmissionCycles\Pages\ListAdmissionCycles;
use App\Filament\Resources\AdmissionCycles\Pages\ViewAdmissionCycle;
use App\Filament\Resources\AdmissionCycles\Schemas\AdmissionCycleInfolist;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\Program;
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

    public function test_named_cycle_draft_defaults_owner_and_institutional_guidance_and_validates_upfront(): void
    {
        $registrar = $this->registrar();
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $term = Term::factory()->create();
        $program = Program::factory()->create(['is_active' => true]);

        Livewire::test(CreateAdmissionCycle::class)
            ->fillForm([
                'code' => 'ROUND3-PARTIAL',
                'label' => 'Incomplete intake',
                'term_id' => $term->id,
                'opens_at' => '2027-01-02 08:00:00',
                'closes_at' => '2027-01-10 17:00:00',
                'correction_closes_at' => '2027-01-12 17:00:00',
                'requirements_due_at' => '2027-01-20 17:00:00',
                'payment_due_at' => '2027-01-25 17:00:00',
                'period_ends_at' => '2027-01-31 17:00:00',
                'accepted_paths' => [AdmissionCycle::PathFirstYear],
                'programs' => [$program->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $cycle = AdmissionCycle::query()->where('code', 'ROUND3-PARTIAL')->firstOrFail();
        $this->assertSame(AdmissionCycle::StateDraft, $cycle->state);
        $this->assertSame($registrar->id, $cycle->registrar_owner_id);
        $this->assertNotEmpty($cycle->privacy_notice_reference);
        $this->assertNotEmpty($cycle->support_contact);
        $this->assertNotEmpty($cycle->applicant_instructions);
        $this->assertSame(1, $cycle->programs()->count());
        $this->assertSame(0, $cycle->events()->count());
    }

    public function test_incomplete_saved_draft_cannot_be_published_even_with_an_approval_reference(): void
    {
        $registrar = $this->registrar();
        $cycle = AdmissionCycle::factory()->create(['opens_at' => null, 'closes_at' => null, 'correction_closes_at' => null]);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ViewAdmissionCycle::class, ['record' => $cycle->id])
            ->assertActionDisabled('publish')
            ->assertSeeInOrder(['Publication checks', 'items to fix before this cycle can be published.',
                'The target term, opening, public closing, or correction boundary is invalid.', 'Owner: Registrar', 'To fix', 'Correct the cycle term and dates.'])
            ->assertDontSee('Admission Cycle and active academic term →')
            ->assertSeeHtml(e(AdmissionCycleResource::getUrl('edit', ['record' => $cycle])))
            ->assertSee('Fix term and dates');
        $this->assertSame(
            ['label' => 'Set requirements', 'url' => AdmissionCycleResource::getUrl('edit', ['record' => $cycle, 'step' => 'form.programs-student-types::data::wizard-step'])],
            AdmissionCycleInfolist::fixLink(['code' => 'requirement_set_transferee'], $cycle),
        );
        $this->assertNull(AdmissionCycleInfolist::fixLink(['code' => 'private_evidence_storage'], $cycle));

        try {
            app(PublishAdmissionCycle::class)->execute($cycle, $registrar, 'ROUND3-APPROVED-SOURCE');
            $this->fail('An incomplete cycle must remain a draft.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('readiness', $exception->errors());
        }

        $this->assertSame(AdmissionCycle::StateDraft, $cycle->fresh()->state);
        $this->assertSame(0, $cycle->events()->count());
    }

    public function test_deadlines_action_is_only_offered_once_the_cycle_is_no_longer_an_editable_draft(): void
    {
        $this->actingAs($this->registrar());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $draft = AdmissionCycle::factory()->create();
        $published = AdmissionCycle::factory()->create(['state' => AdmissionCycle::StatePublished]);

        Livewire::test(ViewAdmissionCycle::class, ['record' => $draft->id])->assertActionHidden('setDeadlines');
        Livewire::test(ViewAdmissionCycle::class, ['record' => $published->id])->assertActionVisible('setDeadlines');
    }

    public function test_resuming_and_saving_draft_keeps_exact_existing_boundary_seconds(): void
    {
        $this->actingAs($this->registrar());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $program = Program::factory()->create(['is_active' => true]);
        $cycle = AdmissionCycle::factory()->create([
            'opens_at' => '2027-01-02 00:10:37',
            'closes_at' => '2027-01-04 11:20:43',
            'correction_closes_at' => '2027-01-06 13:30:59',
            'requirements_due_at' => '2027-01-10 17:00:00',
            'payment_due_at' => '2027-01-15 17:00:00',
            'period_ends_at' => '2027-01-20 17:00:00',
        ]);
        $cycle->programs()->attach($program->id, [
            'accepts_first_year' => true,
            'accepts_transferee' => true,
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
