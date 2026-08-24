<?php

namespace Tests\Feature;

use App\Actions\SystemAdministration\SystemHealthPresenter;
use App\Filament\Pages\SystemHealth;
use App\Models\OperationalEvent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL92DIntegrationMonitoringTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (User::staffRoleNames() as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function system_health_is_the_only_registered_integration_monitor_and_never_renders_secrets(): void
    {
        Config::set('tala_integrations.payments.driver', 'paymongo');
        Config::set('tala_integrations.payments.paymongo.public_key', 'pk_test_hidden');
        Config::set('tala_integrations.payments.paymongo.secret_key', 'sk_test_hidden');
        Config::set('tala_integrations.payments.paymongo.webhook_signature', 'whsec_hidden');

        $admin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($admin);

        $component = Livewire::test(SystemHealth::class)->assertOk();
        $html = $component->html();

        $this->assertStringNotContainsString('pk_test_hidden', $html);
        $this->assertStringNotContainsString('sk_test_hidden', $html);
        $this->assertStringNotContainsString('whsec_hidden', $html);
        $this->assertStringContainsString('Not checked by TALA', $html);
        $this->assertFileDoesNotExist(app_path('Filament/Pages/IntegrationStatus.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Resources/OperationalEvents/OperationalEventResource.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Resources/SystemSettings/SystemSettingResource.php'));
    }

    #[Test]
    public function self_test_is_limited_to_the_signed_in_administrator_and_safe_event_fields(): void
    {
        Mail::fake();
        $admin = $this->staff(User::StaffRoleSystemSuperAdmin);
        RateLimiter::clear('tala:system-health:mail-self-test:'.$admin->getKey());
        $this->actingAs($admin);

        Livewire::test(SystemHealth::class)
            ->callAction('sendTestEmail')
            ->callAction('sendTestEmail');

        Mail::assertSentCount(1);
        $event = OperationalEvent::query()->where('event_type', 'mail_self_test_accepted')->sole();
        $this->assertSame($admin->getKey(), $event->user_id);
        $this->assertNull($event->diagnostics);
        $this->assertNull($event->payload);
        $this->assertNull($event->recipient_snapshot);
    }

    #[Test]
    public function every_projected_row_uses_the_fixed_health_vocabulary(): void
    {
        $rows = collect(app(SystemHealthPresenter::class)->capture()['rows']);

        $this->assertSame([], $rows->pluck('status')->diff([
            SystemHealthPresenter::Available,
            SystemHealthPresenter::Attention,
            SystemHealthPresenter::Unavailable,
            SystemHealthPresenter::Unknown,
        ])->values()->all());
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
