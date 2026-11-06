<?php

namespace Tests\Feature;

use App\Actions\Grades\AuthorizeLateGradeEncoding;
use App\Actions\Grades\GradeWindowService;
use App\Filament\Resources\GradeRosters\Pages\ViewGradeRoster;
use App\Models\GradeRoster;
use App\Models\LateGradeAuthorization;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $this->assertSame('testing', app()->environment());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());

        foreach ([User::StaffRoleRegistrar, User::StaffRoleAcademicHead, User::StaffRoleFaculty] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function faculty_entry_guidance_uses_grade_entry_or_scoped_authority_instead_of_exam_dates(): void
    {
        $roster = GradeRoster::factory()->create(['state' => GradeRoster::StateReturned]);
        $package = TermCalendarPackage::factory()->create(['term_id' => $roster->termOffering->term_id, 'state' => TermCalendarPackage::StateActive]);
        TermCalendarWindow::factory()->for($package, 'package')->create(['window_type' => TermCalendarWindow::TypeExaminationPeriod, 'opens_on' => today()->subDay(), 'closes_on' => today()->addDay()]);
        $windows = app(GradeWindowService::class);
        $this->assertFalse($windows->entryContext($roster)['is_open']);
        $this->assertSame('Grade Entry dates not configured', $windows->entryContext($roster)['basis']);

        $entry = TermCalendarWindow::factory()->for($package, 'package')->create(['window_type' => TermCalendarWindow::TypeGradeEntry, 'opens_on' => today()->subDays(3), 'closes_on' => today()->subDay()]);
        $this->assertSame('Grade Entry window closed', $windows->entryContext($roster)['basis']);
        app(AuthorizeLateGradeEncoding::class)->execute($roster, 'final', now()->subMinute(), now()->addHour(), 'Approved scoped late entry.', $this->staff(User::StaffRoleRegistrar));
        $context = $windows->entryContext($roster);
        $this->assertTrue($context['is_open']);
        $this->assertSame('Authorized late grade entry', $context['basis']);
        $this->assertNull($context['reference']);
        $this->assertTrue($windows->isOpen($roster, 'final'));
        $entry->update(['closes_on' => today()->addDay()]);
        $this->assertSame('Grade Entry window', $windows->entryContext($roster)['basis']);
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
        $this->assertSame($roster->id, $authority->grade_roster_id);
        $this->assertSame($roster->faculty_user_id, $authority->faculty_user_id);
        $this->assertSame($roster->term_offering_id, $authority->term_offering_id);
    }

    #[Test]
    public function registrar_can_reopen_grade_entry_without_an_external_reference(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $roster = GradeRoster::factory()->create(['state' => GradeRoster::StateLateNotSubmitted]);

        $authority = app(AuthorizeLateGradeEncoding::class)->execute(
            $roster, LateGradeAuthorization::PeriodFinal, now()->subMinute(), now()->addHour(),
            'Allow the designated Faculty member to complete this class roster.', $registrar,
        );

        $this->assertNull($authority->fresh()->getRawOriginal('authority_reference'));
        $this->assertSame($registrar->id, $authority->approved_by);
        $this->assertTrue(app(GradeWindowService::class)->isOpen($roster, LateGradeAuthorization::PeriodFinal));
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
        $package = TermCalendarPackage::factory()->create([
            'term_id' => $roster->termOffering->term_id,
            'state' => TermCalendarPackage::StateActive,
        ]);
        TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeGradeEntry,
            'opens_on' => now('Asia/Manila')->toDateString(),
            'closes_on' => now('Asia/Manila')->toDateString(),
            'cutoff_at' => null,
        ]);
        $windows = app(GradeWindowService::class);

        $this->assertTrue($windows->isOpen($roster, LateGradeAuthorization::PeriodFinal));
        $this->assertFalse($windows->isOpen($roster, LateGradeAuthorization::PeriodPrelim));
        $this->assertFalse($windows->isOpen($roster, LateGradeAuthorization::PeriodMidterm));
    }

    #[Test]
    public function a_stale_roster_state_records_no_late_authority(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $roster = GradeRoster::factory()->create(['state' => GradeRoster::StateReturned]);
        GradeRoster::query()->whereKey($roster->id)->update(['state' => GradeRoster::StateReleased]);

        try {
            app(AuthorizeLateGradeEncoding::class)->execute(
                $roster, LateGradeAuthorization::PeriodFinal, now(), now()->addHour(),
                'Only the current roster can receive the scoped approval.', $registrar,
            );
            $this->fail('The authoritative roster state must be rechecked before writing late authority.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The roster has changed. Reload it before recording late Grade Entry authority.', $exception->getMessage());
            $this->assertDatabaseCount('late_grade_authorizations', 0);
            $this->assertSame(GradeRoster::StateReleased, $roster->fresh()->state);
        }
    }

    #[Test]
    #[DataProvider('calendarAvailability')]
    public function recorded_registrar_grants_reopen_missing_or_closed_grade_entry(bool $closedWindow): void
    {
        $this->travelTo(Carbon::parse('2026-08-10 12:00:00', 'Asia/Manila'));
        $roster = GradeRoster::factory()->create(['state' => GradeRoster::StateLateNotSubmitted]);
        $grant = LateGradeAuthorization::factory()->for($roster, 'roster')->create([
            'approved_by' => $this->staff(User::StaffRoleRegistrar)->id,
        ]);
        if ($closedWindow) {
            $package = TermCalendarPackage::factory()->create([
                'term_id' => $roster->termOffering->term_id,
                'state' => TermCalendarPackage::StateActive,
            ]);
            TermCalendarWindow::factory()->for($package, 'package')->create([
                'window_type' => TermCalendarWindow::TypeGradeEntry,
                'opens_on' => '2026-08-08',
                'closes_on' => '2026-08-09',
                'cutoff_at' => '23:59:59',
            ]);
        }

        $open = app(GradeWindowService::class)->isOpen($roster, LateGradeAuthorization::PeriodFinal);

        $this->assertTrue($open);
        $this->assertSame(LateGradeAuthorization::StateActive, $grant->fresh()->state);
        $this->assertDatabaseCount('late_grade_authorizations', 1);
    }

    /** @return array<string, array{bool}> */
    public static function calendarAvailability(): array
    {
        return [
            'no calendar window' => [false],
            'closed calendar window' => [true],
        ];
    }

    #[Test]
    #[DataProvider('unauthorizedApproverRoles')]
    public function late_grants_require_a_recorded_registrar_approver(?string $role): void
    {
        $roster = GradeRoster::factory()->create(['state' => GradeRoster::StateLateNotSubmitted]);
        $approver = $role === null ? User::factory()->create() : $this->staff($role);
        LateGradeAuthorization::factory()->for($roster, 'roster')->create([
            'approved_by' => $approver->id,
        ]);

        $this->assertFalse(app(GradeWindowService::class)->isOpen($roster, LateGradeAuthorization::PeriodFinal));
    }

    /** @return array<string, array{?string}> */
    public static function unauthorizedApproverRoles(): array
    {
        return [
            'unassigned account' => [null],
            'Faculty' => [User::StaffRoleFaculty],
            'Academic Head' => [User::StaffRoleAcademicHead],
        ];
    }

    #[Test]
    #[DataProvider('invalidGrantScopes')]
    public function registrar_grants_require_the_exact_active_window(string $scope): void
    {
        $this->travelTo(Carbon::parse('2026-08-10 12:00:00', 'Asia/Manila'));
        $roster = GradeRoster::factory()->create(['state' => GradeRoster::StateLateNotSubmitted]);
        $attributes = match ($scope) {
            'roster' => ['grade_roster_id' => GradeRoster::factory()->create()->id],
            'term offering' => ['term_offering_id' => GradeRoster::factory()->create()->term_offering_id],
            'Faculty' => ['faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id],
            'period' => ['grading_period' => LateGradeAuthorization::PeriodMidterm],
            'revoked' => ['state' => LateGradeAuthorization::StateRevoked],
            'not started' => ['opens_at' => now()->addHour()],
            'closed' => ['closes_at' => now()->subMinute()],
        };
        LateGradeAuthorization::factory()->for($roster, 'roster')->create([
            'approved_by' => $this->staff(User::StaffRoleRegistrar)->id,
            ...$attributes,
        ]);

        $this->assertFalse(app(GradeWindowService::class)->isOpen($roster, LateGradeAuthorization::PeriodFinal));
    }

    /** @return array<string, array{string}> */
    public static function invalidGrantScopes(): array
    {
        return [
            'different roster' => ['roster'],
            'different term offering' => ['term offering'],
            'different Faculty' => ['Faculty'],
            'retired period' => ['period'],
            'revoked grant' => ['revoked'],
            'future opening' => ['not started'],
            'past closing' => ['closed'],
        ];
    }

    #[Test]
    public function late_authority_form_stores_manila_times_in_utc_and_preserves_input_on_overlap(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 08:00:00', 'UTC'));
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $roster = GradeRoster::factory()->create(['state' => GradeRoster::StateReturned]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $data = [
            'opens_at' => '2026-10-26 17:00:00',
            'closes_at' => '2026-10-26 18:00:00',
            'reason' => 'Approved evening grade entry for this class.',
        ];
        $page = Livewire::actingAs($registrar)->test(ViewGradeRoster::class, ['record' => $roster->id]);

        $page->mountAction('authorizeLateGradeEncoding')->fillForm($data)
            ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Grade entry reopening recorded');

        $authority = $roster->lateAuthorizations()->sole();
        $this->assertSame('2026-10-26 09:00:00', $authority->opens_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-26 10:00:00', $authority->closes_at->format('Y-m-d H:i:s'));
        $this->assertNull($authority->fresh()->getRawOriginal('authority_reference'));
        $this->assertSame($registrar->id, $authority->approved_by);

        $page->mountAction('authorizeLateGradeEncoding')->fillForm($data)
            ->callMountedAction()->assertNotified('Grade entry was not reopened')
            ->assertActionMounted('authorizeLateGradeEncoding');
        $this->assertSame(1, $roster->lateAuthorizations()->count());
        $this->assertSame(GradeRoster::StateReturned, $roster->fresh()->state);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
