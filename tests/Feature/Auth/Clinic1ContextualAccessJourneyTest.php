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
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($user);
        $this->assertSame(User::StaffRoleRegistrar, session(WorkspaceContextResolver::SessionKey));
    }
}
