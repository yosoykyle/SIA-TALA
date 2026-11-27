<?php

namespace Tests\Feature\SystemAdministration;

use App\Actions\Authentication\TalaAppAuthentication;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\SystemAdministration\GovernanceEvidenceProjection;
use App\Actions\SystemAdministration\UserAccessService;
use App\Filament\Pages\GovernanceAudit;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\FaqEntry;
use App\Models\OperationalEvent;
use App\Models\PublicNotice;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\NavigationBadgeCounts;
use App\Support\TalaPanelTheme;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UsersAndAccessLifecycleJourneyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([...User::staffRoleNames(), 'student', 'applicant'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_disable_and_reactivate_preserve_roles_and_end_sessions(): void
    {
        $actor = $this->administrator();
        $target = User::factory()->create();
        $target->assignRole([User::StaffRoleFaculty, User::StaffRoleAccounting]);
        DB::table('sessions')->insert([
            'id' => 'clinic1-lifecycle-session',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $service = app(UserAccessService::class);
        $service->disable($actor, $target, 'Access suspended after an approved separation review.', 'HR separation notice 2026-08');

        $target->refresh();
        $this->assertSame(User::StatusDisabled, $target->status);
        $this->assertEqualsCanonicalizing(
            [User::StaffRoleFaculty, User::StaffRoleAccounting],
            $target->roles->pluck('name')->all(),
        );
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);

        $service->reactivate($actor, $target, 'Access restored after approved return to service.', 'HR return notice 2026-09');

        $this->assertSame(User::StatusActive, $target->fresh()->status);
        $this->assertEqualsCanonicalizing(
            [User::StaffRoleFaculty, User::StaffRoleAccounting],
            $target->fresh()->roles->pluck('name')->all(),
        );
    }

    public function test_self_disable_and_final_administrator_removal_are_rejected_without_change(): void
    {
        $actor = $this->administrator();
        $service = app(UserAccessService::class);

        try {
            $service->disable($actor, $actor, 'Attempt to disable the current administrator.', 'Invalid self action');
            $this->fail('Self-disable must fail.');
        } catch (AuthorizationException) {
            $this->assertSame(User::StatusActive, $actor->fresh()->status);
        }

        try {
            $service->changeStaffRoles(
                $actor,
                $actor,
                [User::StaffRoleRegistrar],
                'Attempt to remove the final administrator role.',
                'Invalid self action',
            );
            $this->fail('Final administrator removal must fail.');
        } catch (ValidationException) {
            $this->assertTrue($actor->fresh()->hasRole(User::StaffRoleSystemSuperAdmin));
        }
    }

    public function test_multi_role_change_is_transactional_and_audited(): void
    {
        $actor = $this->administrator();
        $target = User::factory()->create();
        $target->assignRole(User::StaffRoleFaculty);

        app(UserAccessService::class)->changeStaffRoles(
            $actor,
            $target,
            [User::StaffRoleFaculty, User::StaffRoleAcademicHead],
            'Approved additional academic oversight responsibility.',
            'Academic council resolution 2026-04',
        );

        $this->assertEqualsCanonicalizing(
            [User::StaffRoleFaculty, User::StaffRoleAcademicHead],
            $target->fresh()->roles->pluck('name')->all(),
        );
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => User::class,
            'subject_id' => $target->id,
            'event' => 'staff_access_changed',
        ]);
        $activity = Activity::query()->where('subject_type', User::class)->where('subject_id', $target->id)->where('event', 'staff_access_changed')->sole();
        $this->assertEqualsCanonicalizing([User::StaffRoleFaculty], $activity->properties->get('before_contexts'));
        $this->assertEqualsCanonicalizing([User::StaffRoleFaculty, User::StaffRoleAcademicHead], $activity->properties->get('after_contexts'));
        $projected = app(GovernanceEvidenceProjection::class)->paginate(
            GovernanceEvidenceProjection::InstitutionalChanges,
            1,
            25,
            'staff_access_changed',
            [],
        );
        $this->assertCount(1, $projected->items());
    }

    public function test_access_change_remains_authoritative_when_notification_dispatch_fails(): void
    {
        $this->mock(Dispatcher::class, function ($mock): void {
            $mock->shouldReceive('send')->once()->andThrow(new \RuntimeException('Synthetic mail dispatch failure.'));
        });
        $actor = $this->administrator();
        $target = User::factory()->create();
        $target->assignRole(User::StaffRoleFaculty);

        app(UserAccessService::class)->disable(
            $actor,
            $target,
            'Access suspended after an approved separation review.',
            'HR separation notice 2026-08',
        );

        $this->assertSame(User::StatusDisabled, $target->fresh()->status);
        $this->assertDatabaseHas('operational_events', [
            'event_domain' => OperationalEvent::DomainNotifications,
            'event_type' => 'staff_access_change',
            'user_id' => $target->id,
            'status' => OperationalEvent::StatusFailed,
        ]);
    }

    public function test_staff_email_change_keeps_the_current_address_until_successor_verification(): void
    {
        $actor = $this->administrator();
        $actor->forceFill(['password' => Hash::make('administrator password')])->save();
        $target = User::factory()->create(['email' => 'current@example.test']);
        $target->assignRole(User::StaffRoleFaculty);

        $change = app(UserAccessService::class)->requestStaffEmailChange(
            $actor,
            $target,
            'successor@example.test',
            'administrator password',
            'Approved correction of the Staff sign-in address.',
            'Staff identity record review 2026-08',
        );

        $this->assertSame('current@example.test', $target->fresh()->email);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => User::class,
            'subject_id' => $target->id,
            'event' => 'staff_email_change_requested',
        ]);

        $token = str_repeat('a', 64);
        $change->update(['token_digest' => hash('sha256', $token)]);

        $this->get(route('staff-email-changes.verify', ['change' => $change, 'token' => $token]))
            ->assertRedirect(route('filament.admin.auth.login'));

        $this->assertSame('successor@example.test', $target->fresh()->email);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => User::class,
            'subject_id' => $target->id,
            'event' => 'staff_email_changed',
        ]);
    }

    public function test_direct_staff_create_edit_role_and_archive_routes_are_retired(): void
    {
        $administrator = $this->administrator();
        $administrator->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $administrator->saveAppAuthenticationRecoveryCodes(['stored-code']);
        $this->actingAs($administrator)
            ->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleSystemSuperAdmin]);

        $this->get('/admin/users/create')->assertNotFound();
        $this->get('/admin/users/1/edit')->assertNotFound();
        $this->get('/admin/roles')->assertNotFound();
    }

    public function test_account_detail_is_read_only_ordered_and_secret_free(): void
    {
        $administrator = $this->administrator();
        $administrator->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $administrator->saveAppAuthenticationRecoveryCodes(['administrator-recovery']);
        $target = User::factory()->create();
        $target->assignRole(User::StaffRoleFaculty);
        $target->staffAccessProfile()->create([
            'staff_identifier' => 'C1-FACULTY-01',
            'first_name' => 'Ada',
            'middle_name' => null,
            'last_name' => 'Lovelace',
            'suffix' => null,
        ]);
        $target->forceFill([
            'two_factor_secret' => 'forbidden-mfa-secret',
            'two_factor_recovery_codes' => 'forbidden-recovery-codes',
        ])->save();
        $this->actingAs($administrator)
            ->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleSystemSuperAdmin]);

        $this->get(route('filament.admin.resources.users.view', ['record' => $target]))
            ->assertOk()
            ->assertSeeInOrder(['Access & Institutional Context', 'Security & Sign-In', 'Recent Activity'])
            ->assertSee('C1-FACULTY-01')
            ->assertSee('IT Staff sends a reset link instead of setting passwords.')
            ->assertDontSee('forbidden-mfa-secret')
            ->assertDontSee('forbidden-recovery-codes')
            ->assertDontSee('Password')
            ->assertDontSee('<details', false);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ViewUser::class, ['record' => $target->getRouteKey()])
            ->assertDontSee('—')
            ->assertDontSee('<details', false);
    }

    public function test_consequential_access_actions_name_effect_owner_and_recovery_before_submission(): void
    {
        $administrator = $this->administrator();
        $provider = app(TalaAppAuthentication::class);
        $provider->saveSecret($administrator, $provider->generateSecret());
        $administrator->forceFill(['two_factor_recovery_codes_acknowledged_at' => now()])->save();

        $target = User::factory()->create();
        $target->assignRole(User::StaffRoleFaculty);
        $provider->saveSecret($target, $provider->generateSecret());

        $this->actingAs($administrator)
            ->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleSystemSuperAdmin]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $expectations = [
            'sendRecoveryLink' => ['does not change the password, roles, or account state', 'account owner completes recovery'],
            'editRoleAndDetails' => ['current verified email remains active', 'cancel to preserve the current Staff access'],
            'resetMfa' => ['ends active sessions', 'Cancel to preserve the current factor'],
            'disable' => ['blocks every authorized workspace', 'authorized reactivation is the recovery path'],
        ];

        foreach ($expectations as $action => [$effect, $recovery]) {
            Livewire::test(ViewUser::class, ['record' => $target->getRouteKey()])
                ->mountAction($action)
                ->assertMountedActionModalSee($effect)
                ->assertMountedActionModalSee($recovery);
        }

        // Self-disable action must never be visible on the signed-in admin's own profile.
        Livewire::test(ViewUser::class, ['record' => $administrator->getRouteKey()])
            ->assertActionHidden('disable');

        $target->forceFill(['status' => User::StatusDisabled])->save();

        Livewire::test(ListUsers::class)
            ->mountAction(TestAction::make('reactivate')->table($target))
            ->assertMountedActionModalSee('preserved roles and linked records')
            ->assertMountedActionModalSee('disable again through the recorded process');

        $invitee = User::factory()->create(['status' => User::StatusInvitationPending]);
        $invitee->assignRole(User::StaffRoleFaculty);

        Livewire::test(ListUsers::class)
            ->mountAction(TestAction::make('resendInvitation')->table($invitee))
            ->assertMountedActionModalSee('invalidate the prior activation link')
            ->assertMountedActionModalSee('resend again or contact the account owner');
    }

    public function test_edit_role_and_details_updates_roles_and_staff_identifier_with_audit(): void
    {
        $administrator = $this->administrator();
        $administrator->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $administrator->saveAppAuthenticationRecoveryCodes(['admin-code']);

        $target = User::factory()->create(User::staffNamePayload('Clara', null, 'Reyes'));
        $target->assignRole(User::StaffRoleFaculty);
        $target->staffAccessProfile()->create([
            'staff_identifier' => 'STF-OLD-01',
            'first_name' => 'Clara',
            'middle_name' => null,
            'last_name' => 'Reyes',
            'suffix' => null,
        ]);

        $this->actingAs($administrator)
            ->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleSystemSuperAdmin]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ViewUser::class, ['record' => $target->getRouteKey()])
            ->callAction('editRoleAndDetails', data: [
                'roles' => [User::StaffRoleFaculty, User::StaffRoleAcademicHead],
                'staff_identifier' => 'STF-2026-777',
                'reason' => 'Updated faculty ID and assigned academic oversight responsibilities.',
                'authority' => 'Memo 2026-099',
            ])
            ->assertHasNoActionErrors();

        $target->refresh();
        $this->assertSame('STF-2026-777', $target->staffAccessProfile?->staff_identifier);
        $this->assertEqualsCanonicalizing(
            [User::StaffRoleFaculty, User::StaffRoleAcademicHead],
            $target->roles->pluck('name')->all(),
        );
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => User::class,
            'subject_id' => $target->id,
            'event' => 'staff_identifier_updated',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => User::class,
            'subject_id' => $target->id,
            'event' => 'staff_access_changed',
        ]);
    }

    public function test_users_table_formats_role_labels_truthful_learner_mfa_and_supports_queue_filtering(): void
    {
        $administrator = $this->administrator();
        $administrator->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $administrator->saveAppAuthenticationRecoveryCodes(['admin-code']);

        $staffEnrolled = User::factory()->create(['name' => 'Elena Academic']);
        $staffEnrolled->assignRole(User::StaffRoleAcademicHead);
        $staffEnrolled->staffAccessProfile()->create([
            'staff_identifier' => 'STF-2026-101',
            'first_name' => 'Elena',
            'middle_name' => null,
            'last_name' => 'Academic',
            'suffix' => null,
        ]);
        $staffEnrolled->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $staffEnrolled->saveAppAuthenticationRecoveryCodes(['staff-code']);

        $staffPendingInvite = User::factory()->create([
            'name' => 'Pending Faculty',
            'status' => User::StatusInvitationPending,
            'email_verified_at' => null,
            'password' => null,
        ]);
        $staffPendingInvite->assignRole(User::StaffRoleFaculty);

        $learner = User::factory()->create([
            'name' => 'Lia Student',
            'status' => User::StatusActive,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ]);
        $learner->assignRole('student');
        StudentProfile::factory()->for($learner)->create([
            'student_number' => '2026-00988',
            'lifecycle_status' => StudentProfile::LifecycleActive,
        ]);

        $unverifiedLearner = User::factory()->create([
            'name' => 'Marco Unverified',
            'status' => User::StatusVerificationRequired,
            'email_verified_at' => null,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ]);
        $unverifiedLearner->assignRole('applicant');
        $cycle = AdmissionCycle::factory()->create();
        AdmissionApplication::factory()->create([
            'user_id' => $unverifiedLearner->id,
            'admission_cycle_id' => $cycle->id,
            'application_reference' => 'APP-2026-8811',
            'application_state' => AdmissionApplication::StateSubmitted,
        ]);

        $this->actingAs($administrator)
            ->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleSystemSuperAdmin]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $list = Livewire::test(ListUsers::class)
            ->set('tableRecordsPerPage', 25)
            ->assertSeeInOrder(['All accounts', 'Needs attention', 'Staff accounts', 'Multi-role', 'Learners'])
            ->assertDontSee('ring-amber-', false)
            ->assertDontSee('border-amber-', false)
            ->assertSee('Academic Head')
            ->assertSee('System Administrator')
            ->assertSee('Student')
            ->assertSee('Not required')
            ->assertSee('Setup required')
            ->assertSee('Enrolled')
            ->assertSee('Staff ID: STF-2026-101')
            ->assertSee('Student No.: 2026-00988')
            ->assertSee('App Ref: APP-2026-8811')
            ->assertDontSeeHtml('>academic-head<')
            ->assertDontSeeHtml('>system-super-admin<')
            ->assertDontSee('—')
            ->assertTableColumnStateSet('two_factor_confirmed_at', 'Not required', $learner)
            ->assertTableColumnStateSet('two_factor_confirmed_at', 'Enrolled', $staffEnrolled)
            ->assertTableColumnStateSet('two_factor_confirmed_at', 'Setup required', $staffPendingInvite)
            ->assertTableActionVisible('resendVerification', $unverifiedLearner)
            ->assertTableActionHidden('resendVerification', $staffPendingInvite)
            ->assertTableActionVisible('correctPendingEmail', $staffPendingInvite)
            ->assertTableActionVisible('cancelInvitation', $staffPendingInvite)
            ->assertTableActionHidden('cancelInvitation', $staffEnrolled)
            ->assertTableActionVisible('changeSignInEmail', $learner)
            ->assertTableActionHidden('changeSignInEmail', $staffEnrolled)
            ->assertTableActionHidden('disable', $administrator)
            ->assertTableActionVisible('disable', $staffEnrolled)
            ->mountAction(TestAction::make('inviteStaff')->table())
            ->assertMountedActionModalSee('TALA emails an activation link so this staff member sets their own password and MFA.')
            ->assertMountedActionModalDontSee('Approval memo or reference')
            ->assertMountedActionModalDontSee('Evidence reference');

        $table = $list->instance()->getTable();
        $this->assertSame(FiltersLayout::Dropdown, $table->getFiltersLayout());
        $this->assertFalse($table->hasToggleableColumns());
        $this->assertCount(5, $table->getColumns());

        Livewire::test(ListUsers::class)
            ->filterTable('role', ['student', 'applicant'])
            ->assertCanSeeTableRecords([$learner, $unverifiedLearner])
            ->assertCanNotSeeTableRecords([$staffEnrolled, $staffPendingInvite]);

        Livewire::test(ListUsers::class, ['queue' => 'attention'])
            ->assertCanSeeTableRecords([$staffPendingInvite, $unverifiedLearner])
            ->assertCanNotSeeTableRecords([$staffEnrolled, $learner]);

        Livewire::test(ListUsers::class, ['queue' => 'staff'])
            ->assertCanSeeTableRecords([$administrator, $staffEnrolled, $staffPendingInvite])
            ->assertCanNotSeeTableRecords([$learner, $unverifiedLearner]);

        Livewire::test(ListUsers::class, ['queue' => 'learners'])
            ->assertCanSeeTableRecords([$learner, $unverifiedLearner])
            ->assertCanNotSeeTableRecords([$administrator, $staffEnrolled, $staffPendingInvite]);

        $multiRoleUser = User::factory()->create(['name' => 'Multi Role Staff']);
        $multiRoleUser->assignRole([User::StaffRoleFaculty, User::StaffRoleRegistrar]);

        Livewire::test(ListUsers::class, ['queue' => 'multi-role'])
            ->assertCanSeeTableRecords([$multiRoleUser])
            ->assertCanNotSeeTableRecords([$staffEnrolled, $learner]);
    }

    public function test_account_detail_displays_profile_sections_linked_profiles_recent_audit_and_activity_log_link(): void
    {
        $administrator = $this->administrator();
        $administrator->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $administrator->saveAppAuthenticationRecoveryCodes(['admin-code']);

        $target = User::factory()->create([
            'name' => 'Rafael Mendoza',
            'status' => User::StatusActive,
        ]);
        $target->assignRole([User::StaffRoleAcademicHead, 'student', 'applicant']);
        $target->staffAccessProfile()->create([
            'staff_identifier' => 'STF-2026-009',
            'first_name' => 'Rafael',
            'middle_name' => null,
            'last_name' => 'Mendoza',
            'suffix' => null,
        ]);

        StudentProfile::factory()->for($target)->create([
            'student_number' => '2026-00421',
            'lifecycle_status' => StudentProfile::LifecycleActive,
        ]);

        $cycle = AdmissionCycle::factory()->create();
        AdmissionApplication::factory()->create([
            'user_id' => $target->id,
            'admission_cycle_id' => $cycle->id,
            'application_reference' => 'APP-2026-0099',
            'application_state' => AdmissionApplication::StateSubmitted,
        ]);

        activity()
            ->performedOn($target)
            ->causedBy($administrator)
            ->event('staff_access_changed')
            ->log('Staff access changed');

        $this->actingAs($administrator)
            ->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleSystemSuperAdmin]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $expectedAuditUrl = GovernanceAudit::getUrl(['actor' => (string) $target->getKey()]);

        $this->get(route('filament.admin.resources.users.view', ['record' => $target]))
            ->assertOk()
            ->assertSeeInOrder([
                'Access & Institutional Context',
                'Security & Sign-In',
                'Recent Activity',
            ])
            ->assertSee('Staff ID: STF-2026-009')
            ->assertSee('Academic Head')
            ->assertDontSee('academic-head')
            ->assertSee('2026-00421')
            ->assertSee('APP-2026-0099')
            ->assertSee('Staff roles updated')
            ->assertSee('View full activity log')
            ->assertSee($expectedAuditUrl, false)
            ->assertDontSee('Password');

        Livewire::test(ViewUser::class, ['record' => $target->getRouteKey()])
            ->assertDontSee('—')
            ->assertActionExists('viewFullActivityLog')
            ->assertActionHasUrl('viewFullActivityLog', $expectedAuditUrl)
            ->assertActionExists('editRoleAndDetails')
            ->assertActionExists('disable');

        // Learner-only profile hides irrelevant staff noise and uses clean fallback breadcrumb when unnamed.
        $unnamedApplicant = User::factory()->create([
            'name' => '',
            'first_name' => null,
            'last_name' => null,
            'email' => 'very.long.applicant.email.address@example.test',
        ]);
        $unnamedApplicant->assignRole('applicant');

        $this->get(route('filament.admin.resources.users.view', ['record' => $unnamedApplicant]))
            ->assertOk()
            ->assertSee('Applicant account #'.$unnamedApplicant->getKey())
            ->assertDontSee('No staff profile recorded');

        $unnamedView = Livewire::test(ViewUser::class, ['record' => $unnamedApplicant->getRouteKey()])
            ->assertDontSee('—');

        $this->assertSame([
            UserResource::getUrl('index') => 'Users & Access',
            'Applicant account #'.$unnamedApplicant->getKey(),
        ], $unnamedView->instance()->getBreadcrumbs());
    }

    public function test_system_admin_navigation_exposes_expandable_sub_options_and_memoized_badges(): void
    {
        $administrator = $this->administrator();
        $administrator->givePermissionTo([
            Permission::findOrCreate('manage-faqs', 'web'),
            Permission::findOrCreate('manage-public-notices', 'web'),
        ]);
        $administrator->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $administrator->saveAppAuthenticationRecoveryCodes(['admin-code']);

        $pendingStaff = User::factory()->create(['status' => User::StatusInvitationPending]);
        $pendingStaff->assignRole(User::StaffRoleFaculty);

        PublicNotice::factory()->create(['state' => 'Draft', 'ever_published' => false]);
        FaqEntry::factory()->create(['is_published' => false, 'ever_published' => false]);

        $counts = new NavigationBadgeCounts;
        $userMetrics = $counts->systemAdminUsers();
        $contentMetrics = $counts->systemAdminPublicContent();
        $healthMetrics = $counts->systemAdminHealth();

        $this->assertGreaterThanOrEqual(1, $userMetrics['attention']);
        $this->assertGreaterThanOrEqual(1, $userMetrics['active_staff']);
        $this->assertGreaterThanOrEqual(1, $userMetrics['staff_mfa_enrolled']);
        $this->assertGreaterThanOrEqual(1, $contentMetrics['notices_draft']);
        $this->assertGreaterThanOrEqual(1, $contentMetrics['faqs_draft']);
        $this->assertArrayHasKey('attention', $healthMetrics);

        $this->actingAs($administrator)
            ->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleSystemSuperAdmin]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::getCurrentPanel()->boot();

        $topLevel = collect(Filament::getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->values();

        $this->assertSame(
            ['Users & Access', 'Public Content', 'System Health', 'Governance & Audit'],
            $topLevel->map->getLabel()->all(),
        );

        $usersNav = $topLevel->first(fn ($item): bool => $item->getLabel() === 'Users & Access');
        $contentNav = $topLevel->first(fn ($item): bool => $item->getLabel() === 'Public Content');
        $healthNav = $topLevel->first(fn ($item): bool => $item->getLabel() === 'System Health');
        $governanceNav = $topLevel->first(fn ($item): bool => $item->getLabel() === 'Governance & Audit');

        $this->assertNotNull($usersNav);
        $this->assertSame((string) $userMetrics['attention'], $usersNav->getBadge());
        $this->assertSame(
            ['All accounts', 'Needs attention', 'Staff accounts', 'Multi-role', 'Learners'],
            collect($usersNav->getChildItems())->map->getLabel()->values()->all(),
        );

        $this->assertNotNull($contentNav);
        $this->assertSame(
            ['Announcements', 'FAQs'],
            collect($contentNav->getChildItems())->map->getLabel()->values()->all(),
        );

        $this->assertNotNull($healthNav);
        $this->assertSame([], collect($healthNav->getChildItems())->all());

        $this->assertNotNull($governanceNav);
        $this->assertSame([], collect($governanceNav->getChildItems())->all());

        // Pure System Administrator hides the always-empty Notifications drawer; multi-role admin keeps it.
        $this->assertFalse(Filament::getCurrentPanel()->hasDatabaseNotifications());

        $administrator->assignRole(User::StaffRoleRegistrar);
        $administrator->unsetRelation('roles');
        $this->assertTrue(Filament::getCurrentPanel()->hasDatabaseNotifications());
    }

    public function test_it_staff_can_request_verified_sign_in_email_replacement_for_student_account(): void
    {
        $administrator = $this->administrator();
        $administrator->forceFill(['password' => Hash::make('administrator password')])->save();
        $administrator->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $administrator->saveAppAuthenticationRecoveryCodes(['admin-code']);

        $student = User::factory()->create([
            'name' => 'Aaron Sarmiento',
            'email' => 'aaron.old@example.test',
            'status' => User::StatusActive,
        ]);
        $student->assignRole('student');

        $this->actingAs($administrator)
            ->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleSystemSuperAdmin]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ViewUser::class, ['record' => $student->getRouteKey()])
            ->assertActionVisible('changeSignInEmail')
            ->assertActionHidden('editRoleAndDetails')
            ->callAction('changeSignInEmail', data: [
                'new_email' => 'aaron.new@example.test',
                'current_password' => 'administrator password',
                'reason' => 'Student lost access to their original personal email inbox.',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('aaron.old@example.test', $student->fresh()->email);
        $this->assertDatabaseHas('pending_email_changes', [
            'user_id' => $student->id,
            'new_email' => 'aaron.new@example.test',
        ]);
    }

    public function test_form_bearing_modals_prevent_backdrop_close_while_read_only_modals_allow_it(): void
    {
        $formAction = Action::make('editForm')
            ->schema([TextInput::make('label')->required()]);
        TalaPanelTheme::configureActionModal($formAction);
        $this->assertFalse($formAction->isModalClosedByClickingAway());

        $readOnlyAction = Action::make('previewContent')
            ->modalSubmitAction(false);
        TalaPanelTheme::configureActionModal($readOnlyAction);
        $this->assertTrue($readOnlyAction->isModalClosedByClickingAway());
    }

    private function administrator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(User::StaffRoleSystemSuperAdmin);

        return $user;
    }
}
