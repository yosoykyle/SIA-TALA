<?php

namespace Tests\Feature;

use App\Actions\Reports\OperationalReportService;
use App\Actions\SystemAdministration\IntegrationHealthPresenter;
use App\Filament\Pages\IntegrationStatus;
use App\Filament\Widgets\StaffRoleWorkspaceOverviewWidget;
use App\Models\OperationalEvent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL96D5E1D6D2SystemAdministrationHealthPresentationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotContains(DB::connection()->getDatabaseName(), ['tala_db', 'tala_test_codex']);

        foreach (User::staffRoleNames() as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        OperationalEvent::query()->delete();
        Config::set('mail.default', 'array');
        Config::set('tala_integrations.scheduling_solver.driver', 'local_stub');
    }

    #[Test]
    public function paymongo_test_mode_separates_local_configuration_from_observed_evidence(): void
    {
        $this->configurePayMongo('http://127.0.0.1:8000');

        $localStatus = $this->integration('Payments (PayMongo)');

        $this->assertSame('Test mode', $localStatus['mode_label']);
        $this->assertFalse($localStatus['configured']);
        $this->assertSame('Local configuration incomplete', $localStatus['configuration_label']);
        $this->assertSame('Not yet observed', $localStatus['evidence_label']);
        $this->assertSame('http://127.0.0.1:8000/api/webhooks/paymongo', $localStatus['reference']['Public webhook URL']);
        $this->assertSame('Not ready', $localStatus['reference']['Public HTTPS callback']);

        $this->configurePayMongo('https://tala-demo.example.com');

        $publicStatus = $this->integration('Payments (PayMongo)');
        $serialized = json_encode($publicStatus, JSON_THROW_ON_ERROR);

        $this->assertTrue($publicStatus['configured']);
        $this->assertSame('Local configuration complete', $publicStatus['configuration_label']);
        $this->assertSame('https://tala-demo.example.com/api/webhooks/paymongo', $publicStatus['reference']['Public webhook URL']);
        $this->assertSame('Ready', $publicStatus['reference']['Public HTTPS callback']);
        $this->assertSame(
            'payment.paid, checkout_session.payment.paid, payment.failed',
            $publicStatus['reference']['Test acceptance events'],
        );
        $this->assertSame('Not checked by TALA', $publicStatus['reference']['PayMongo dashboard registration']);
        $this->assertStringNotContainsString('sk_test_D6D2_SECRET', $serialized);
        $this->assertStringNotContainsString('pk_test_D6D2_PUBLIC_REFERENCE', $serialized);
        $this->assertStringNotContainsString('whsec_D6D2_SECRET', $serialized);
    }

    #[Test]
    public function unresolved_provider_evidence_names_the_owner_and_next_action_without_rendering_diagnostics(): void
    {
        $this->configurePayMongo('https://tala-demo.example.com');
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

        Livewire::test(IntegrationStatus::class)
            ->assertOk()
            ->assertSee('Test mode')
            ->assertSee('Local configuration complete')
            ->assertSee('Evidence: Attention required')
            ->assertSee('System Administrator for configuration; Accounting for payment exceptions')
            ->assertSee('Review Operational Events, correct integration failures, and route payment-evidence exceptions to Accounting.')
            ->assertSee('PayMongo dashboard registration')
            ->assertSee('Not checked by TALA')
            ->assertDontSee('must-not-render')
            ->assertDontSee('Practice / mock mode')
            ->assertDontSee('Safe readiness');
    }

    #[Test]
    public function dashboard_health_summary_uses_shared_configuration_and_evidence_states(): void
    {
        $this->configurePayMongo('https://tala-demo.example.com');
        $this->actingAs($this->staff(User::StaffRoleSystemSuperAdmin));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(StaffRoleWorkspaceOverviewWidget::class)
            ->assertOk()
            ->assertSee('3. System Health')
            ->assertSee('Ready for verification')
            ->assertDontSee('Safe readiness');

        OperationalEvent::factory()->failed()->create([
            'event_domain' => OperationalEvent::DomainIntegration,
            'integration' => OperationalEvent::IntegrationSchedulingSolver,
            'event_type' => OperationalEvent::TypeSolverDispatchAttempt,
            'status' => OperationalEvent::StatusFailed,
            'failed_at' => now(),
        ]);

        Livewire::test(StaffRoleWorkspaceOverviewWidget::class)
            ->assertOk()
            ->assertSee('Attention required')
            ->assertSee('Review unresolved operational evidence for Scheduler (CP-SAT solver).');
    }

    #[Test]
    public function system_health_and_governance_evidence_remain_system_super_admin_only(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->actingAs($registrar);

        $this->assertFalse(IntegrationStatus::canAccess());

        $systemAdministrator = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($systemAdministrator);

        $this->assertTrue(IntegrationStatus::canAccess());

        $reports = app(OperationalReportService::class)->optionsFor($systemAdministrator);

        foreach ([
            OperationalReportService::UserRole,
            OperationalReportService::ActivityLog,
            OperationalReportService::GeneratedOutput,
            OperationalReportService::ReportExport,
            OperationalReportService::IntegrationEvent,
            OperationalReportService::PayMongoWebhookEvent,
        ] as $reportKey) {
            $this->assertArrayHasKey($reportKey, $reports);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function integration(string $name): array
    {
        return collect(app(IntegrationHealthPresenter::class)->integrations())
            ->firstWhere('name', $name)
            ?? $this->fail("Integration status [{$name}] was not found.");
    }

    private function configurePayMongo(string $applicationUrl): void
    {
        Config::set('app.url', $applicationUrl);
        Config::set('tala_integrations.payments.driver', 'paymongo');
        Config::set('tala_integrations.payments.paymongo.secret_key', 'sk_test_D6D2_SECRET');
        Config::set('tala_integrations.payments.paymongo.public_key', 'pk_test_D6D2_PUBLIC_REFERENCE');
        Config::set('tala_integrations.payments.paymongo.webhook_signature', 'whsec_D6D2_SECRET');
        Config::set('tala_integrations.payments.paymongo.livemode', false);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
