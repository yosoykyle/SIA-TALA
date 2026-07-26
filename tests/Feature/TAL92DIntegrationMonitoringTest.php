<?php

namespace Tests\Feature;

use App\Filament\Pages\IntegrationStatus;
use App\Filament\Resources\OperationalEvents\OperationalEventResource;
use App\Filament\Resources\OperationalEvents\Pages\ListOperationalEvents;
use App\Filament\Resources\OperationalEvents\Pages\ViewOperationalEvent;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Filament\Resources\SystemSettings\Pages\ListSystemSettings;
use App\Filament\Resources\SystemSettings\SystemSettingResource;
use App\Models\OperationalEvent;
use App\Models\ScheduleGenerationRun;
use App\Models\SystemSetting;
use App\Models\Term;
use App\Models\User;
use App\Policies\SystemSettingPolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TAL-92D: Integration Status & Operational Monitoring.
 *
 * Owning contract: PRD `13_system_admin_reports_audit.md` §13.5 (Integration
 * Settings and Operational Monitoring), §13.8 ("Integration settings" and
 * "Integration events and failures" rows), and §13.2 (Notifications, for
 * context only). Covers: (1) `SystemSettingResource` read-only acceptance,
 * (2) the new `IntegrationStatus` page (status view + safe test-email
 * action), (3) the new `OperationalEventResource` monitor, and the explicit
 * no-secret-rendered guarantee required by this slice.
 */
final class TAL92DIntegrationMonitoringTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotContains(DB::connection()->getDatabaseName(), ['tala_db', 'tala_test_codex']);

        foreach ([...User::staffRoleNames(), 'student', 'applicant'] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    // ------------------------------------------------------------------
    // Task 1 — SystemSettingResource acceptance (no source change).
    // ------------------------------------------------------------------

    #[Test]
    public function system_setting_resource_is_super_admin_scoped_and_fully_read_only(): void
    {
        $this->assertFalse(SystemSettingResource::canCreate());

        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $policy = app(SystemSettingPolicy::class);
        $setting = SystemSetting::query()->forceCreate([
            'key' => 'maintenance_mode',
            'value' => 'false',
            'value_type' => SystemSetting::ValueTypeBoolean,
            'effective_from' => now(),
            'version' => 1,
            'status' => 'active',
        ]);

        $this->assertTrue($policy->viewAny($superAdmin));
        $this->assertTrue($policy->view($superAdmin, $setting));
        $this->assertFalse($policy->create($superAdmin));
        $this->assertFalse($policy->update($superAdmin, $setting));
        $this->assertFalse($policy->delete($superAdmin, $setting));
        $this->assertFalse($policy->restore($superAdmin, $setting));
        $this->assertFalse($policy->forceDelete($superAdmin, $setting));

        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleAccounting,
            User::StaffRoleAcademicHead,
            User::StaffRoleFaculty,
        ] as $deniedRole) {
            $denied = $this->staff($deniedRole);
            $this->assertFalse($policy->viewAny($denied));
        }

        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::test(ListSystemSettings::class);

        $component->assertOk();
        $component->assertCanSeeTableRecords([$setting]);
    }

    // ------------------------------------------------------------------
    // Task 2 — Integration Status page.
    // ------------------------------------------------------------------

    #[Test]
    public function integration_status_page_is_explicitly_registered_and_super_admin_only(): void
    {
        $this->assertContains(IntegrationStatus::class, Filament::getPanel('admin')->getPages());

        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(IntegrationStatus::class)->assertOk();
        $this->assertTrue(IntegrationStatus::canAccess());

        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleAccounting,
            User::StaffRoleAcademicHead,
            User::StaffRoleFaculty,
        ] as $deniedRole) {
            $denied = $this->staff($deniedRole);
            $this->actingAs($denied);
            $this->assertFalse(IntegrationStatus::canAccess());
        }
    }

    #[Test]
    public function integration_status_page_never_renders_a_configured_secret_value(): void
    {
        Config::set('tala_integrations.payments.driver', 'paymongo');
        Config::set('tala_integrations.payments.paymongo.secret_key', 'sk_test_ABSOLUTELY_SECRET_VALUE');
        Config::set('tala_integrations.payments.paymongo.public_key', 'pk_test_PUBLIC_BUT_NOT_RENDERED');
        Config::set('tala_integrations.payments.paymongo.webhook_signature', 'whsec_ABSOLUTELY_SECRET_VALUE');
        Config::set('tala_integrations.payments.paymongo.livemode', false);
        Config::set('tala_integrations.payments.paymongo.base_url', 'https://api.paymongo.com');
        Config::set('tala_integrations.payments.paymongo.payment_method_types', ['gcash', 'card']);

        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::test(IntegrationStatus::class);

        $component->assertOk();
        $html = $component->html();

        $this->assertStringNotContainsString('sk_test_ABSOLUTELY_SECRET_VALUE', $html);
        $this->assertStringNotContainsString('pk_test_PUBLIC_BUT_NOT_RENDERED', $html);
        $this->assertStringNotContainsString('whsec_ABSOLUTELY_SECRET_VALUE', $html);
        $this->assertStringContainsString('Configured ✓', $html);
    }

    #[Test]
    public function paymongo_status_reports_local_readiness_and_observed_webhook_health_without_claiming_provider_status(): void
    {
        Config::set('tala_integrations.payments.driver', 'paymongo');
        Config::set('tala_integrations.payments.paymongo.secret_key', 'sk_test_present');
        Config::set('tala_integrations.payments.paymongo.public_key', 'pk_test_present');
        Config::set('tala_integrations.payments.paymongo.webhook_signature', 'whsec_present');
        Config::set('tala_integrations.payments.paymongo.livemode', false);

        OperationalEvent::factory()->create([
            'event_domain' => OperationalEvent::DomainIntegration,
            'integration' => OperationalEvent::IntegrationPayMongo,
            'channel' => OperationalEvent::ChannelWebhook,
            'direction' => OperationalEvent::DirectionInbound,
            'event_type' => 'checkout_session.payment.paid',
            'status' => OperationalEvent::StatusProcessed,
            'processed_at' => now()->subMinute(),
        ]);
        OperationalEvent::factory()->failed()->create([
            'event_domain' => OperationalEvent::DomainIntegration,
            'integration' => OperationalEvent::IntegrationPayMongo,
            'channel' => OperationalEvent::ChannelWebhook,
            'direction' => OperationalEvent::DirectionInbound,
            'event_type' => 'checkout_session.payment.paid',
            'status' => OperationalEvent::StatusReviewRequired,
            'failed_at' => now(),
            'diagnostics' => ['reason' => 'must-not-render'],
        ]);

        $this->actingAs($this->staff(User::StaffRoleSystemSuperAdmin));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::test(IntegrationStatus::class);

        $component->assertOk();
        $html = $component->html();

        $this->assertStringContainsString('Local PayMongo readiness', $html);
        $this->assertStringContainsString('Ready', $html);
        $this->assertStringContainsString('Open local exceptions', $html);
        $this->assertStringContainsString('1', $html);
        $this->assertStringContainsString('Provider dashboard state', $html);
        $this->assertStringContainsString('Not checked by TALA', $html);
        $this->assertStringNotContainsString('must-not-render', $html);
        $this->assertStringNotContainsString('Enabled in PayMongo', $html);
    }

    #[Test]
    public function configured_status_reflects_config_presence_and_absence(): void
    {
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Config::set('tala_integrations.payments.driver', 'paymongo');
        Config::set('tala_integrations.payments.paymongo.secret_key', null);
        Config::set('tala_integrations.payments.paymongo.public_key', null);
        Config::set('tala_integrations.payments.paymongo.webhook_signature', null);

        $unconfiguredComponent = Livewire::test(IntegrationStatus::class);

        $unconfiguredComponent->assertOk();
        $unconfiguredHtml = $unconfiguredComponent->html();
        $this->assertStringContainsString('Not configured ✗', $unconfiguredHtml);

        Config::set('tala_integrations.payments.paymongo.secret_key', 'sk_test_present');
        Config::set('tala_integrations.payments.paymongo.public_key', 'pk_test_present');
        Config::set('tala_integrations.payments.paymongo.webhook_signature', 'whsec_present');

        $configuredComponent = Livewire::test(IntegrationStatus::class);

        $configuredComponent->assertOk();
        $configuredHtml = $configuredComponent->html();
        $this->assertStringContainsString('Configured ✓', $configuredHtml);
        $this->assertStringNotContainsString('sk_test_present', $configuredHtml);
    }

    #[Test]
    public function scheduler_status_distinguishes_stub_local_and_private_cloud_run_modes(): void
    {
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Config::set('tala_integrations.scheduling_solver.driver', 'local_stub');
        Config::set('tala_integrations.scheduling_solver.url', null);
        $stubComponent = Livewire::test(IntegrationStatus::class);

        $stubComponent->assertOk();
        $stubHtml = $stubComponent->html();
        $this->assertStringContainsString('Stub', $stubHtml);
        $this->assertStringContainsString('Configured ✓', $stubHtml);

        Config::set('tala_integrations.scheduling_solver.driver', 'local_http');
        Config::set('tala_integrations.scheduling_solver.url', 'http://127.0.0.1:8080');
        Config::set('tala_integrations.scheduling_solver.audience', null);
        Config::set('tala_integrations.scheduling_solver.credentials_path', null);
        $localComponent = Livewire::test(IntegrationStatus::class);

        $localComponent->assertOk();
        $localHtml = $localComponent->html();
        $this->assertStringContainsString('Local CP-SAT', $localHtml);
        $this->assertStringContainsString('http://127.0.0.1:8080', $localHtml);
        $this->assertStringContainsString('Configured ✓', $localHtml);

        Config::set('tala_integrations.scheduling_solver.driver', 'cloud_run');
        Config::set('tala_integrations.scheduling_solver.url', 'https://solver.example.test');
        Config::set('tala_integrations.scheduling_solver.audience', 'https://solver.example.test');
        Config::set('tala_integrations.scheduling_solver.credentials_path', __FILE__);
        $cloudComponent = Livewire::test(IntegrationStatus::class);

        $cloudComponent->assertOk();
        $cloudHtml = $cloudComponent->html();
        $this->assertStringContainsString('Private Cloud Run', $cloudHtml);
        $this->assertStringContainsString('Configured ✓', $cloudHtml);
        $this->assertStringNotContainsString(__FILE__, $cloudHtml);
    }

    #[Test]
    public function scheduler_status_rejects_remote_local_http_and_incomplete_cloud_run_configuration(): void
    {
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Config::set('tala_integrations.scheduling_solver.driver', 'local_http');
        Config::set('tala_integrations.scheduling_solver.url', 'http://192.168.1.10:8080');
        $remoteLocalComponent = Livewire::test(IntegrationStatus::class);

        $remoteLocalComponent->assertOk();
        $remoteLocalHtml = $remoteLocalComponent->html();
        $this->assertStringContainsString('Not configured ✗', $remoteLocalHtml);

        Config::set('tala_integrations.scheduling_solver.driver', 'cloud_run');
        Config::set('tala_integrations.scheduling_solver.url', 'https://solver.example.test');
        Config::set('tala_integrations.scheduling_solver.audience', 'https://solver.example.test');
        Config::set('tala_integrations.scheduling_solver.credentials_path', null);
        $missingCredentialsComponent = Livewire::test(IntegrationStatus::class);

        $missingCredentialsComponent->assertOk();
        $missingCredentialsHtml = $missingCredentialsComponent->html();
        $this->assertStringContainsString('Not configured ✗', $missingCredentialsHtml);
    }

    #[Test]
    public function send_test_email_action_targets_only_the_acting_admin_and_logs_one_operational_event(): void
    {
        Mail::fake();

        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertSame(0, OperationalEvent::query()->count());

        $component = Livewire::test(IntegrationStatus::class);

        $component->assertOk();
        $component
            ->assertActionExists('sendTestEmail')
            ->callAction('sendTestEmail')
            ->assertNotified();

        Mail::assertSent(function (Mailable $mailable) use ($superAdmin): bool {
            return $mailable->hasTo($superAdmin->email);
        });

        $this->assertSame(1, OperationalEvent::query()->count());

        $event = OperationalEvent::query()->sole();
        $this->assertSame($superAdmin->id, $event->user_id);
        $this->assertSame('notifications', $event->event_domain);
        $this->assertSame('mail', $event->integration);
        $this->assertSame('test_email_sent', $event->event_type);
        $this->assertSame('PROCESSED', $event->status);
        $this->assertNull($event->related_record_type);
        $this->assertNull($event->related_record_id);
    }

    #[Test]
    public function send_test_email_action_only_ever_sends_to_the_acting_admins_own_address(): void
    {
        Mail::fake();

        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(IntegrationStatus::class)
            ->callAction('sendTestEmail');

        Mail::assertSentCount(1);
        Mail::assertSent(function (Mailable $mailable) use ($superAdmin): bool {
            return $mailable->hasTo($superAdmin->email);
        });
    }

    // ------------------------------------------------------------------
    // Task 3 — OperationalEventResource monitor.
    // ------------------------------------------------------------------

    #[Test]
    public function operational_event_resource_is_super_admin_only_and_read_only(): void
    {
        $this->assertFalse(OperationalEventResource::canCreate());

        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $event = OperationalEvent::factory()->create();

        $component = Livewire::test(ListOperationalEvents::class);

        $component->assertOk();
        $component->assertCanSeeTableRecords([$event]);

        Livewire::test(ViewOperationalEvent::class, ['record' => $event->getRouteKey()])
            ->assertOk();

        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleAccounting,
            User::StaffRoleAcademicHead,
            User::StaffRoleFaculty,
        ] as $deniedRole) {
            $denied = $this->staff($deniedRole);
            $this->actingAs($denied);

            Livewire::test(ListOperationalEvents::class)->assertForbidden();
        }
    }

    #[Test]
    public function operational_event_table_filters_return_expected_rows(): void
    {
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $processed = OperationalEvent::factory()->create(['status' => 'PROCESSED', 'event_domain' => 'notifications']);
        $failed = OperationalEvent::factory()->failed()->create(['event_domain' => 'notifications']);

        $component = Livewire::test(ListOperationalEvents::class);

        $component->assertOk();
        $component
            ->assertCanSeeTableRecords([$processed, $failed])
            ->filterTable('status', 'FAILED')
            ->assertCanSeeTableRecords([$failed])
            ->assertCanNotSeeTableRecords([$processed]);
    }

    #[Test]
    public function scheduling_solver_events_name_the_source_run_without_bypassing_academic_authorization(): void
    {
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $term = Term::factory()->create();
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusFailed,
            'requested_by' => $superAdmin->id,
            'input_snapshot' => ['contract_version' => 'tal94-demand-v2'],
            'input_hash' => hash('sha256', (string) Str::uuid()),
            'solver_version' => 'test-solver',
            'diagnostics' => [],
        ]);
        $solverEvent = OperationalEvent::factory()->failed()->create([
            'event_domain' => OperationalEvent::DomainIntegration,
            'integration' => OperationalEvent::IntegrationSchedulingSolver,
            'event_type' => OperationalEvent::TypeSolverDispatchAttempt,
            'related_record_type' => ScheduleGenerationRun::class,
            'related_record_id' => $run->id,
        ]);
        $mailEvent = OperationalEvent::factory()->create([
            'event_domain' => 'notifications',
            'integration' => 'mail',
        ]);

        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::test(ListOperationalEvents::class);

        $component->assertOk();
        $component
            ->filterTable('integration', OperationalEvent::IntegrationSchedulingSolver)
            ->assertCanSeeTableRecords([$solverEvent])
            ->assertCanNotSeeTableRecords([$mailEvent]);

        $viewComponent = Livewire::test(ViewOperationalEvent::class, ['record' => $solverEvent->getRouteKey()]);

        $viewComponent->assertOk();
        $html = $viewComponent->html();

        $this->assertStringContainsString('Schedule Run #'.$run->id, $html);
        $this->assertStringNotContainsString(
            ScheduleGenerationRunResource::getUrl('view', ['record' => $run]),
            $html,
        );
    }

    #[Test]
    public function no_unexpected_secret_substrings_appear_in_a_rendered_operational_event_payload(): void
    {
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $event = OperationalEvent::factory()->create([
            'event_domain' => 'notifications',
            'integration' => 'mail',
            'event_type' => 'test_email_sent',
            'payload' => ['note' => 'test-email payload for '.$superAdmin->email],
        ]);

        $component = Livewire::test(ViewOperationalEvent::class, ['record' => $event->getRouteKey()]);

        $component->assertOk();
        $html = $component->html();

        $this->assertStringNotContainsString('sk_live_', $html);
        $this->assertStringNotContainsString('sk_test_', $html);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
