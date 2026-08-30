<?php

namespace Tests\Feature;

use App\Models\SchedulingDemand;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class TAL96D1CrossRoleAuditHarnessTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertSame(Command::SUCCESS, Artisan::call('acceptance:seed-client-baseline'), Artisan::output());
    }

    public function test_client_baseline_supports_all_seven_representative_workspaces(): void
    {
        $exitCode = Artisan::call('acceptance:seed-client-baseline', ['--check' => true]);
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode, $output);
        $this->assertStringContainsString('outcome=inspection_only', $output);
        $this->assertStringContainsString('baseline_state=complete', $output);
        $this->assertStringContainsString('readiness=PASS', $output);
        $this->assertStringContainsString('scenario_anchors=10/10', $output);
        $this->assertStringContainsString('standing_irregular=2', $output);
        $this->assertStringContainsString('downstream_state=EMPTY', $output);
        $this->assertSame(47, StudentProfile::query()->count());
        $this->assertSame(54, SchedulingDemand::query()->count());

        foreach (['schedule_runs', 'section_meetings', 'enrollments', 'assessments', 'ledger_entries', 'payments', 'payment_attempts', 'webhook_calls'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }

        foreach ($this->representativeWorkspaces() as $workspace) {
            $user = User::query()->where('email', $workspace['email'])->sole();

            $this->assertTrue($user->hasVerifiedEmail(), $workspace['email']);
            $this->assertTrue($user->canAuthenticate(), $workspace['email']);

            foreach (['applicant', 'student', 'admin'] as $panelId) {
                $panel = Filament::getPanel($panelId);

                $this->assertSame(
                    $panelId === $workspace['panel'],
                    $user->canAccessPanel($panel),
                    $workspace['email'].' access to '.$panelId,
                );
            }
            $panel = Filament::getPanel($workspace['panel']);

            Filament::setCurrentPanel($panel);

            $this->followingRedirects()
                ->actingAs($user)
                ->get(route($workspace['route']))
                ->assertOk();
        }

        $applicant = User::query()->where('email', 'applicant.demo@example.test')->sole();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $this->followingRedirects()
            ->actingAs($applicant)
            ->get(route('filament.applicant.pages.application'))
            ->assertOk();

        $student = User::query()->where('email', 'student.demo@example.test')->sole();
        Filament::setCurrentPanel(Filament::getPanel('student'));

        foreach ([
            'filament.student.pages.schedule-view',
            'filament.student.pages.finance',
            'filament.student.pages.cor-view',
            'filament.student.pages.grades-view',
            'filament.student.pages.holds-view',
            'filament.student.pages.lifecycle-view',
        ] as $routeName) {
            $this->followingRedirects()
                ->actingAs($student)
                ->get(route($routeName))
                ->assertOk();
        }

        $this->assertFalse(Route::has('filament.student.pages.completion'));

        $this->followingRedirects()
            ->actingAs($student)
            ->get(route('filament.student.pages.academics'))
            ->assertOk();
    }

    /**
     * @return list<array{email: string, panel: 'admin'|'applicant'|'student', route: string}>
     */
    private function representativeWorkspaces(): array
    {
        return [
            ['email' => 'applicant.demo@example.test', 'panel' => 'applicant', 'route' => 'filament.applicant.pages.dashboard'],
            ['email' => 'student.demo@example.test', 'panel' => 'student', 'route' => 'filament.student.pages.dashboard'],
            ['email' => 'registrar.demo@example.test', 'panel' => 'admin', 'route' => 'filament.admin.pages.dashboard'],
            ['email' => 'accounting.demo@example.test', 'panel' => 'admin', 'route' => 'filament.admin.pages.dashboard'],
            ['email' => 'faculty.demo@example.test', 'panel' => 'admin', 'route' => 'filament.admin.pages.dashboard'],
            ['email' => 'academic-head.demo@example.test', 'panel' => 'admin', 'route' => 'filament.admin.pages.dashboard'],
            ['email' => 'system-admin.demo@example.test', 'panel' => 'admin', 'route' => 'filament.admin.pages.dashboard'],
        ];
    }
}
