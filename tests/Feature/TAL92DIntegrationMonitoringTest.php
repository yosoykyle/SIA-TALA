<?php

namespace Tests\Feature;

use App\Filament\Pages\IntegrationStatus;
use App\Filament\Resources\OperationalEvents\OperationalEventResource;
use App\Filament\Resources\OperationalEvents\Pages\ListOperationalEvents;
use App\Filament\Resources\OperationalEvents\Pages\ViewOperationalEvent;
use App\Filament\Resources\SystemSettings\Pages\ListSystemSettings;
use App\Filament\Resources\SystemSettings\SystemSettingResource;
use App\Models\OperationalEvent;
use App\Models\SystemSetting;
use App\Models\User;
use App\Policies\SystemSettingPolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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

        Livewire::test(ListSystemSettings::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$setting]);
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
        Config::set('tala_integrations.payments.paymongo.livemode', false);
        Config::set('tala_integrations.payments.paymongo.base_url', 'https://api.paymongo.com/v1');
        Config::set('tala_integrations.payments.paymongo.payment_method_types', ['gcash', 'card']);

        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $html = Livewire::test(IntegrationStatus::class)->assertOk()->html();

        $this->assertStringNotContainsString('sk_test_ABSOLUTELY_SECRET_VALUE', $html);
        $this->assertStringNotContainsString('pk_test_PUBLIC_BUT_NOT_RENDERED', $html);
        $this->assertStringContainsString('Configured ✓', $html);
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

        $unconfiguredHtml = Livewire::test(IntegrationStatus::class)->assertOk()->html();
        $this->assertStringContainsString('Not configured ✗', $unconfiguredHtml);

        Config::set('tala_integrations.payments.paymongo.secret_key', 'sk_test_present');
        Config::set('tala_integrations.payments.paymongo.public_key', 'pk_test_present');

        $configuredHtml = Livewire::test(IntegrationStatus::class)->assertOk()->html();
        $this->assertStringContainsString('Configured ✓', $configuredHtml);
        $this->assertStringNotContainsString('sk_test_present', $configuredHtml);
    }

    #[Test]
    public function send_test_email_action_targets_only_the_acting_admin_and_logs_one_operational_event(): void
    {
        Mail::fake();

        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertSame(0, OperationalEvent::query()->count());

        Livewire::test(IntegrationStatus::class)
            ->assertOk()
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

        Livewire::test(ListOperationalEvents::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$event]);

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

        Livewire::test(ListOperationalEvents::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$processed, $failed])
            ->filterTable('status', 'FAILED')
            ->assertCanSeeTableRecords([$failed])
            ->assertCanNotSeeTableRecords([$processed]);
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

        $html = Livewire::test(ViewOperationalEvent::class, ['record' => $event->getRouteKey()])
            ->assertOk()
            ->html();

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
