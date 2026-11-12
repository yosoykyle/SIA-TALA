<?php

namespace Tests\Feature;

use App\Models\StudentProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SessionInactivityCountdownTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());
        Role::query()->firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
    }

    public function test_signed_in_panel_page_carries_the_countdown_with_the_student_idle_limit(): void
    {
        $this->actingAs($this->student())
            ->get(Filament::getPanel('student')->getUrl())
            ->assertOk()
            ->assertSee('id="tala-idle"', false)
            ->assertSee('data-idle-seconds="7200"', false)
            ->assertSee(route('session.continue'), false)
            ->assertSee('Stay signed in');
    }

    public function test_sign_in_page_has_no_countdown_for_a_guest(): void
    {
        $this->get(Filament::getPanel('student')->getLoginUrl())
            ->assertDontSee('id="tala-idle"', false);
    }

    #[TestWith(['student', 'student', 7200])]
    #[TestWith(['applicant', 'applicant', 7200])]
    #[TestWith([User::StaffRoleRegistrar, 'admin', 1800])]
    public function test_shared_warning_uses_the_role_limit_and_workspace_recovery(string $role, string $panel, int $seconds): void
    {
        Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $this->actingAs($user);
        $this->travelTo(now()->startOfMinute());
        session()->put('tala.last_activity_at', now()->subSeconds(40)->timestamp);

        $markup = Blade::render('@include("filament.components.idle-countdown", ["panelId" => $panel])', ['panel' => $panel]);

        $this->assertStringContainsString('data-idle-seconds="'.$seconds.'"', $markup);
        $this->assertStringContainsString('data-idle-age="40"', $markup);
        $this->assertStringContainsString('data-login-url="'.e(Filament::getPanel($panel)->getLoginUrl()).'"', $markup);
    }

    public function test_continuing_records_renewed_activity(): void
    {
        $this->travelTo(now()->startOfMinute());
        $earlier = now()->subMinutes(100)->timestamp;

        $this->actingAs($this->student())
            ->withSession(['tala.last_activity_at' => $earlier])
            ->post(route('session.continue'))
            ->assertNoContent()
            ->assertSessionHas('tala.last_activity_at', now()->timestamp);
        $this->assertAuthenticated();
    }

    public function test_continuing_cannot_revive_a_session_past_its_idle_limit(): void
    {
        $this->actingAs($this->student())
            ->withSession(['tala.last_activity_at' => now()->subMinutes(121)->timestamp])
            ->post(route('session.continue'))
            ->assertRedirect(route('home'));
        $this->assertGuest();
    }

    public function test_continuing_requires_a_signed_in_person(): void
    {
        $this->post(route('session.continue'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_countdown_end_signs_out_and_returns_to_the_workspace_sign_in_page(): void
    {
        $this->actingAs($this->student())
            ->post(route('session.expire'), ['panel' => 'student'])
            ->assertRedirect(Filament::getPanel('student')->getLoginUrl())
            ->assertSessionHas('filament.notifications', fn (array $notifications): bool => collect($notifications)
                ->contains(fn (array $notification): bool => $notification['title'] === 'Your session ended due to inactivity'));
        $this->assertGuest();
    }

    public function test_countdown_end_with_an_unknown_workspace_returns_to_the_public_home(): void
    {
        $this->actingAs($this->student())
            ->post(route('session.expire'), ['panel' => 'https://example.test/elsewhere'])
            ->assertRedirect(route('home'));
        $this->assertGuest();
    }

    private function student(): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole('student');
        StudentProfile::factory()->create(['user_id' => $user->id]);

        return $user;
    }
}
