<?php

namespace Tests\Feature;

use App\Actions\Grades\AuthorizeLateGradeEncoding;
use App\Actions\Grades\GradeWindowService;
use App\Models\CalendarEvent;
use App\Models\GradeRoster;
use App\Models\LateGradeAuthorization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL89BLateGradeAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([User::StaffRoleRegistrar, User::StaffRoleAcademicHead, User::StaffRoleFaculty] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function registrar_can_open_one_roster_scoped_final_grade_entry_authority(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $roster = GradeRoster::factory()->create(['state' => GradeRoster::StateReturned]);

        $authority = app(AuthorizeLateGradeEncoding::class)->execute(
            $roster,
            LateGradeAuthorization::PeriodFinal,
            now()->subMinute(),
            now()->addHour(),
            'Approved late completion of the official roster.',
            $registrar,
        );

        $this->assertSame(LateGradeAuthorization::PeriodFinal, $authority->grading_period);
        $this->assertSame($registrar->id, $authority->approved_by);
    }

    #[Test]
    public function period_scoped_and_academic_head_late_authority_are_retired(): void
    {
        $roster = GradeRoster::factory()->create(['state' => GradeRoster::StateReturned]);

        foreach ([LateGradeAuthorization::PeriodPrelim, LateGradeAuthorization::PeriodMidterm] as $retiredPeriod) {
            try {
                app(AuthorizeLateGradeEncoding::class)->execute(
                    $roster, $retiredPeriod, now(), now()->addHour(), 'Retired period authority.', $this->staff(User::StaffRoleRegistrar),
                );
                $this->fail('Expected retired period authority to be rejected.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(AuthorizationException::class);
        app(AuthorizeLateGradeEncoding::class)->execute(
            $roster,
            LateGradeAuthorization::PeriodFinal,
            now(),
            now()->addHour(),
            'Academic Head is read-only for Grade Entry authority.',
            $this->staff(User::StaffRoleAcademicHead),
        );
    }

    #[Test]
    public function overlapping_final_grade_entry_authority_is_rejected(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $roster = GradeRoster::factory()->create(['state' => GradeRoster::StateReturned]);
        $service = app(AuthorizeLateGradeEncoding::class);
        $service->execute($roster, LateGradeAuthorization::PeriodFinal, now(), now()->addHours(2), 'First authority.', $registrar);

        $this->expectException(RuntimeException::class);
        $service->execute($roster, LateGradeAuthorization::PeriodFinal, now()->addHour(), now()->addHours(3), 'Overlapping authority.', $registrar);
    }

    #[Test]
    public function the_shared_grade_entry_window_never_reopens_retired_period_entry(): void
    {
        $roster = GradeRoster::factory()->create();
        CalendarEvent::factory()->create([
            'term_id' => $roster->termOffering->term_id,
            'process_key' => 'grade_entry',
            'state' => CalendarEvent::StateActive,
            'start_at' => now()->subMinute(),
            'end_at' => now()->addMinute(),
        ]);
        $windows = app(GradeWindowService::class);

        $this->assertTrue($windows->isOpen($roster, LateGradeAuthorization::PeriodFinal));
        $this->assertFalse($windows->isOpen($roster, LateGradeAuthorization::PeriodPrelim));
        $this->assertFalse($windows->isOpen($roster, LateGradeAuthorization::PeriodMidterm));
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
