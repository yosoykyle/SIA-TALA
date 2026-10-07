<?php

namespace Tests\Feature;

use App\Actions\Admissions\SaveAdmissionApplication;
use App\Filament\Pages\AssistedAdmissionApplication;
use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\User;
use App\Queries\Admissions\AssistedDraftApplicantQuery;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Issue57AssistedDraftEligibilityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_active_verified_owner_can_start_and_resume_an_unsubmitted_draft(): void
    {
        $this->freezeTime();
        $applicant = $this->applicant();
        $cycle = AdmissionCycle::factory()->published()->create();
        $query = app(AssistedDraftApplicantQuery::class);
        $this->assertTrue($query->eligible(cycleId: $cycle->id)->whereKey($applicant->id)->exists());

        $draft = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, ['first_name' => 'Assisted'], assistedBy: $this->registrar(), assistanceReason: 'Desk intake');
        $this->assertSame($applicant->id, $draft->user_id);
        $this->assertSame(AdmissionApplication::StateDraft, $draft->application_state);
        $this->assertNull($draft->current_submission_version_id);
        $this->assertTrue($query->eligible($draft->id, $cycle->id)->whereKey($applicant->id)->exists());
        $this->assertDatabaseHas('activity_log', ['subject_id' => $draft->id, 'event' => 'admission_assisted_draft_saved']);
    }

    public function test_submitted_action_needed_and_admitted_cases_cannot_receive_assistance_in_their_cycle(): void
    {
        foreach ([AdmissionApplication::StateSubmitted, AdmissionApplication::StateActionNeeded, AdmissionApplication::StateAdmitted] as $state) {
            $applicant = $this->applicant();
            $cycle = AdmissionCycle::factory()->published()->create();
            $application = AdmissionApplication::factory()->create(['user_id' => $applicant->id, 'admission_cycle_id' => $cycle->id, 'term_id' => $cycle->term_id, 'application_state' => $state]);
            $query = app(AssistedDraftApplicantQuery::class);
            $this->assertFalse($query->eligible($application->id)->whereKey($applicant->id)->exists());
            $this->assertFalse($query->eligible(cycleId: $cycle->id)->whereKey($applicant->id)->exists());
        }
    }

    public function test_prior_closed_terminal_case_leaves_new_open_intake_available_and_source_scope_exact(): void
    {
        $this->freezeTime();
        $applicant = $this->applicant();
        $oldCycle = AdmissionCycle::factory()->published()->create(['closes_at' => now()->subMinute()]);
        $old = AdmissionApplication::factory()->create(['user_id' => $applicant->id, 'admission_cycle_id' => $oldCycle->id, 'term_id' => $oldCycle->term_id, 'application_state' => AdmissionApplication::StateNotAdmitted]);
        $newCycle = AdmissionCycle::factory()->published()->create();
        $query = app(AssistedDraftApplicantQuery::class);
        $this->assertTrue($query->eligible(cycleId: $newCycle->id)->whereKey($applicant->id)->exists());
        $this->assertFalse($query->eligible($old->id, $newCycle->id)->whereKey($applicant->id)->exists());
        $otherOwner = $this->applicant();
        $foreign = AdmissionApplication::factory()->create(['user_id' => $otherOwner->id, 'admission_cycle_id' => $newCycle->id, 'term_id' => $newCycle->term_id]);
        $this->assertFalse($query->eligible($foreign->id)->whereKey($applicant->id)->exists());
    }

    public function test_closed_draft_remains_inspectable_but_assisted_save_obeys_cycle_boundary(): void
    {
        $this->freezeTime();
        $applicant = $this->applicant();
        $cycle = AdmissionCycle::factory()->published()->create(['closes_at' => now()]);
        $draft = AdmissionApplication::factory()->create(['user_id' => $applicant->id, 'admission_cycle_id' => $cycle->id, 'term_id' => $cycle->term_id]);
        $query = app(AssistedDraftApplicantQuery::class);
        $this->assertFalse($query->eligible()->whereKey($applicant->id)->exists());
        $this->assertFalse($query->eligible($draft->id)->whereKey($applicant->id)->exists());
        $this->assertTrue($query->eligible($draft->id, includeClosedDrafts: true)->whereKey($applicant->id)->exists());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::withQueryParams(['applicant' => $applicant->id, 'application' => $draft->id])
            ->actingAs($this->registrar())->test(AssistedAdmissionApplication::class)
            ->assertDontSee('Assisted entry unavailable for this applicant')
            ->assertSee('Discard draft');
        try {
            app(SaveAdmissionApplication::class)->execute($applicant, $cycle, ['first_name' => 'Changed'], $draft, $this->registrar(), 'Desk intake');
            $this->fail('Closed-cycle draft changes must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('application_state', $exception->errors());
        }
        $this->assertSame($draft->first_name, $draft->fresh()->first_name);
    }

    public function test_stale_draft_submitted_after_selection_cannot_be_overwritten_or_logged_as_assistance(): void
    {
        $applicant = $this->applicant();
        $cycle = AdmissionCycle::factory()->published()->create();
        $draft = AdmissionApplication::factory()->create(['user_id' => $applicant->id, 'admission_cycle_id' => $cycle->id, 'term_id' => $cycle->term_id]);
        AdmissionApplication::query()->whereKey($draft->id)->update(['application_state' => AdmissionApplication::StateSubmitted]);
        try {
            app(SaveAdmissionApplication::class)->execute($applicant, $cycle, ['first_name' => 'Changed'], $draft, $this->registrar(), 'Desk intake');
            $this->fail('A stale selected Draft must not overwrite submitted facts.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('application_state', $exception->errors());
        }
        $this->assertSame($draft->first_name, $draft->fresh()->first_name);
        $this->assertSame(AdmissionApplication::StateSubmitted, $draft->fresh()->application_state);
        $this->assertDatabaseMissing('activity_log', ['subject_id' => $draft->id, 'event' => 'admission_assisted_draft_saved']);
    }

    public function test_inactive_and_unverified_accounts_are_excluded_and_forged_picker_choice_is_rejected(): void
    {
        $cycle = AdmissionCycle::factory()->published()->create();
        $inactive = $this->applicant();
        $inactive->update(['status' => User::StatusInactive]);
        $unverified = $this->applicant();
        $unverified->forceFill(['email_verified_at' => null])->save();
        $this->assertNull($unverified->fresh()->email_verified_at);
        foreach ([$inactive, $unverified] as $applicant) {
            $this->assertFalse(app(AssistedDraftApplicantQuery::class)->eligible(cycleId: $cycle->id)->whereKey($applicant->id)->exists());
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($this->registrar())->test(ListAdmissionApplications::class)
            ->callAction('prepareAssistedDraft', data: ['applicant_id' => $unverified->id])
            ->assertHasActionErrors(['applicant_id']);
    }

    public function test_direct_assisted_route_with_action_needed_source_withholds_draft_form(): void
    {
        $applicant = $this->applicant();
        $application = AdmissionApplication::factory()->create(['user_id' => $applicant->id, 'application_state' => AdmissionApplication::StateActionNeeded]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::withQueryParams(['applicant' => $applicant->id, 'application' => $application->id])
            ->actingAs($this->registrar())->test(AssistedAdmissionApplication::class)
            ->assertSee('Assisted entry unavailable for this applicant')
            ->assertDontSee('Save Applicant Draft');
    }

    public function test_exact_submitted_source_redirects_to_registrar_review_without_attempting_draft_resolution(): void
    {
        $applicant = $this->applicant();
        $application = AdmissionApplication::factory()->submitted()->create(['user_id' => $applicant->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::withQueryParams(['applicant' => $applicant->id, 'application' => $application->id])
            ->actingAs($this->registrar())->test(AssistedAdmissionApplication::class)
            ->assertRedirect(AdmissionApplicationResource::getUrl('view', ['record' => $application]));
        $this->assertSame(AdmissionApplication::StateSubmitted, $application->fresh()->application_state);
    }

    public function test_default_selection_honors_latest_editable_case_while_explicit_draft_keeps_its_source(): void
    {
        $applicant = $this->applicant();
        $cycle = AdmissionCycle::factory()->published()->create();
        $draft = AdmissionApplication::factory()->create(['user_id' => $applicant->id, 'admission_cycle_id' => $cycle->id, 'term_id' => $cycle->term_id]);
        AdmissionApplication::factory()->create(['user_id' => $applicant->id, 'application_state' => AdmissionApplication::StateActionNeeded]);
        $query = app(AssistedDraftApplicantQuery::class);
        $this->assertFalse($query->eligible()->whereKey($applicant->id)->exists());
        $this->assertTrue($query->eligible($draft->id)->whereKey($applicant->id)->exists());
    }

    public function test_picker_rejects_account_that_becomes_ineligible_after_selection(): void
    {
        AdmissionCycle::factory()->published()->create();
        $applicant = $this->applicant();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($this->registrar())->test(ListAdmissionApplications::class)
            ->mountAction('prepareAssistedDraft')
            ->fillForm(['applicant_id' => $applicant->id]);
        $applicant->forceFill(['email_verified_at' => null])->save();
        $this->assertNull($applicant->fresh()->email_verified_at);
        $component->callMountedAction()->assertHasActionErrors(['applicant_id']);
    }

    private function applicant(): User
    {
        Role::findOrCreate('applicant', 'web');
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole('applicant');

        return $user;
    }

    private function registrar(): User
    {
        $role = Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $role->givePermissionTo(Permission::findOrCreate('approve-documents', 'web'));
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $user->saveAppAuthenticationSecret('base32secret3232');
        $user->acknowledgeRecoveryCodeStorage();

        return $user;
    }
}
