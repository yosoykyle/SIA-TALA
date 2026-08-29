<?php

namespace Tests\Feature\Auth;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Pages\Auth\ContextualLogin;
use App\Models\AdmissionApplication;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Clinic1ContextualAccessJourneyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['applicant', 'student', ...User::staffRoleNames()] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_single_context_routes_directly_and_selected_entry_never_grants_access(): void
    {
        $user = User::factory()->create();
        $user->assignRole('applicant');

        $resolver = app(WorkspaceContextResolver::class);

        $this->assertSame(['applicant'], array_keys($resolver->availableContexts($user)));
        $this->assertSame('/applicant', $resolver->destinationFor($user, 'applicant'));
        $this->assertNull($resolver->destinationFor($user, 'student'));
    }

    #[DataProvider('staffEntryDestinations')]
    public function test_each_staff_context_enters_its_first_canonical_destination(string $role, string $expected): void
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->assertSame($expected, app(WorkspaceContextResolver::class)->destinationFor($user, $role));
    }

    public static function staffEntryDestinations(): array
    {
        return [
            'Registrar' => [User::StaffRoleRegistrar, '/admin/admission-applications'],
            'Accounting' => [User::StaffRoleAccounting, '/admin/fee-plans'],
            'Faculty' => [User::StaffRoleFaculty, '/admin/my-availability'],
            'Academic Head' => [User::StaffRoleAcademicHead, '/admin/academic-approvals'],
            'System Administrator' => [User::StaffRoleSystemSuperAdmin, '/admin/users'],
        ];
    }

    public function test_multi_role_account_chooses_one_context_without_merging_staff_authority(): void
    {
        $user = User::factory()->create();
        $user->assignRole(['student', User::StaffRoleFaculty, User::StaffRoleAccounting]);
        StudentProfile::factory()->create(['user_id' => $user->id]);

        $resolver = app(WorkspaceContextResolver::class);

        $this->assertSame(
            ['student', User::StaffRoleAccounting, User::StaffRoleFaculty],
            array_keys($resolver->availableContexts($user)),
        );

        $this->actingAs($user);
        $resolver->select($user, User::StaffRoleFaculty);

        $this->assertSame(User::StaffRoleFaculty, session(WorkspaceContextResolver::SessionKey));
        $this->assertTrue($resolver->isSelected($user, User::StaffRoleFaculty));
        $this->assertFalse($resolver->isSelected($user, User::StaffRoleAccounting));
        $this->assertTrue($user->hasRole(User::StaffRoleFaculty));
        $this->assertFalse($user->hasRole(User::StaffRoleAccounting));
    }

    public function test_completed_applicant_context_is_omitted_after_student_activation_but_active_journey_is_kept(): void
    {
        $user = User::factory()->create();
        $user->assignRole(['applicant', 'student']);
        StudentProfile::factory()->create(['user_id' => $user->id]);

        $resolver = app(WorkspaceContextResolver::class);

        $this->assertSame(['student'], array_keys($resolver->availableContexts($user)));

        AdmissionApplication::factory()->create([
            'user_id' => $user->id,
            'application_state' => AdmissionApplication::StateActionNeeded,
        ]);

        $this->assertSame(['applicant', 'student'], array_keys($resolver->availableContexts($user->fresh())));
    }

    public function test_revoked_context_cannot_be_reused_from_session(): void
    {
        $user = User::factory()->create();
        $user->assignRole(User::StaffRoleFaculty);

        $this->actingAs($user);
        $resolver = app(WorkspaceContextResolver::class);
        $resolver->select($user, User::StaffRoleFaculty);

        $user->syncRoles([]);

        $this->assertNull($resolver->selected($user->fresh()));
        $this->assertNull(session(WorkspaceContextResolver::SessionKey));
    }

    public function test_wrong_context_credentials_recover_to_the_authorized_workspace_without_role_disclosure(): void
    {
        $user = User::factory()->create([
            'email' => 'registrar@example.test',
            'password' => 'a secure password 2026',
        ]);
        $user->assignRole(User::StaffRoleRegistrar);
        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::test(ContextualLogin::class)
            ->set('data.email', 'registrar@example.test')
            ->set('data.password', 'a secure password 2026')
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertRedirect('/admin/admission-applications');

        $this->assertAuthenticatedAs($user);
        $this->assertSame(User::StaffRoleRegistrar, session(WorkspaceContextResolver::SessionKey));
        $this->assertSame('You are signed in. The selected entry is unavailable for this account. Use one of your authorized workspaces.', session('tala.context_entry_notice'));
    }

    public function test_wrong_entry_explanation_is_shown_once_in_the_authorized_workspace(): void
    {
        $user = User::factory()->create(['password' => 'a secure password 2026']);
        $user->assignRole('applicant');
        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::test(ContextualLogin::class)
            ->set('data.email', $user->email)
            ->set('data.password', 'a secure password 2026')
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertRedirect('/applicant');

        $this->get('/applicant')->assertOk()
            ->assertSee('The selected entry is unavailable for this account.')
            ->assertSessionMissing('tala.context_entry_notice');
        $this->get('/applicant')->assertOk()
            ->assertDontSee('The selected entry is unavailable for this account.');
    }

    public function test_matching_entry_does_not_show_a_wrong_entry_explanation(): void
    {
        $user = User::factory()->create(['password' => 'a secure password 2026']);
        $user->assignRole('applicant');
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        session()->put(WorkspaceContextResolver::EntryNoticeSessionKey, 'Previous entry notice');

        Livewire::test(ContextualLogin::class)
            ->set('data.email', $user->email)
            ->set('data.password', 'a secure password 2026')
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertRedirect('/applicant');

        $this->assertFalse(session()->has('tala.context_entry_notice'));
    }

    public function test_wrong_entry_explanation_is_available_in_the_workspace_chooser(): void
    {
        $user = User::factory()->create(['password' => 'a secure password 2026']);
        $user->assignRole([User::StaffRoleRegistrar, User::StaffRoleAccounting]);
        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::test(ContextualLogin::class)
            ->set('data.email', $user->email)
            ->set('data.password', 'a secure password 2026')
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertRedirect(route('workspace-chooser'));

        $this->get(route('workspace-chooser'))->assertOk()
            ->assertSee('The selected entry is unavailable for this account.')
            ->assertSessionMissing(WorkspaceContextResolver::EntryNoticeSessionKey);
        $this->assertNull(session(WorkspaceContextResolver::SessionKey));
    }

    public function test_fortify_login_explains_an_unavailable_entry_after_authentication(): void
    {
        $user = User::factory()->create(['password' => 'a secure password 2026']);
        $user->assignRole('applicant');

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'a secure password 2026',
            'context' => 'student',
        ])->assertRedirect('/applicant')
            ->assertSessionHas(WorkspaceContextResolver::EntryNoticeSessionKey);

        $this->assertAuthenticatedAs($user);
    }

    public function test_failed_authentication_does_not_explain_or_disclose_an_available_context(): void
    {
        $user = User::factory()->create(['password' => 'a secure password 2026']);
        $user->assignRole('applicant');
        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::test(ContextualLogin::class)
            ->set('data.email', $user->email)
            ->set('data.password', 'an incorrect password')
            ->call('authenticate')
            ->assertHasErrors('data.email');

        $this->assertGuest();
        $this->assertFalse(session()->has(WorkspaceContextResolver::EntryNoticeSessionKey));
    }

    public function test_generic_staff_entry_clears_a_previously_requested_learner_context(): void
    {
        session()->put('tala.requested_context', 'student');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ContextualLogin::class)
            ->assertSet('requestedContext', null);

        $this->assertFalse(session()->has('tala.requested_context'));
    }
}
