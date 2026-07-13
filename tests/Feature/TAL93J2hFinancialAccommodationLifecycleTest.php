<?php

namespace Tests\Feature;

use App\Actions\Finance\FinancialAccommodationLifecycleService;
use App\Actions\StudentLifecycle\HoldEvaluationService;
use App\Filament\Resources\FinancialAccommodations\Pages\CreateFinancialAccommodation;
use App\Filament\Resources\FinancialAccommodations\Pages\ViewFinancialAccommodation;
use App\Models\Enrollment;
use App\Models\FinancialAccommodation;
use App\Models\Hold;
use App\Models\PaymentScheduleRow;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL93J2hFinancialAccommodationLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-10 09:00:00', config('app.timezone')));

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotContains(DB::connection()->getDatabaseName(), ['tala_db', 'tala_test_codex']);

        foreach ([User::StaffRoleAccounting, User::StaffRoleRegistrar] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function allowedTransitions(): iterable
    {
        yield 'pending to active' => [FinancialAccommodation::StatusPending, FinancialAccommodation::StatusActive];
        yield 'pending to cancelled' => [FinancialAccommodation::StatusPending, FinancialAccommodation::StatusCancelled];
        yield 'active to fulfilled' => [FinancialAccommodation::StatusActive, FinancialAccommodation::StatusFulfilled];
        yield 'active to defaulted' => [FinancialAccommodation::StatusActive, FinancialAccommodation::StatusDefaulted];
        yield 'active to expired' => [FinancialAccommodation::StatusActive, FinancialAccommodation::StatusExpired];
        yield 'active to cancelled' => [FinancialAccommodation::StatusActive, FinancialAccommodation::StatusCancelled];
    }

    #[Test]
    #[DataProvider('allowedTransitions')]
    public function accounting_can_apply_every_allowed_transition_with_atomic_audit_evidence(string $from, string $to): void
    {
        $actor = $this->staff(User::StaffRoleAccounting);
        $accommodation = $this->accommodation($from, ['recorded_by' => $actor->id]);
        $recordedBy = $accommodation->recorded_by;
        $transitionedAt = CarbonImmutable::parse('2026-07-12 14:30:00', config('app.timezone'));

        $result = app(FinancialAccommodationLifecycleService::class)->transition(
            $accommodation,
            $to,
            '  Approved lifecycle result after Accounting review.  ',
            $actor,
            $transitionedAt,
        );

        $this->assertSame($to, $result->status);
        $this->assertSame($recordedBy, $result->recorded_by);

        $activity = Activity::query()->where('event', 'financial_accommodation_transitioned')->sole();
        $this->assertSame(FinancialAccommodation::class, $activity->subject_type);
        $this->assertSame($accommodation->id, $activity->subject_id);
        $this->assertSame($actor->id, $activity->causer_id);
        $this->assertSame($from, data_get($activity->properties, 'status_before'));
        $this->assertSame($to, data_get($activity->properties, 'status_after'));
        $this->assertSame('Approved lifecycle result after Accounting review.', data_get($activity->properties, 'reason'));
        $this->assertSame($transitionedAt->toIso8601String(), data_get($activity->properties, 'transitioned_at'));
        $this->assertSame($accommodation->student_profile_id, data_get($activity->properties, 'student_profile_id'));
        $this->assertSame($accommodation->term_id, data_get($activity->properties, 'term_id'));
        $this->assertSame($recordedBy, data_get($activity->properties, 'recorded_by'));
    }

    #[Test]
    public function invalid_terminal_repeat_blank_or_oversized_reason_and_premature_expiry_are_rejected(): void
    {
        $actor = $this->staff(User::StaffRoleAccounting);
        $service = app(FinancialAccommodationLifecycleService::class);

        foreach ([
            [FinancialAccommodation::StatusPending, FinancialAccommodation::StatusFulfilled, 'Not a permitted jump.'],
            [FinancialAccommodation::StatusFulfilled, FinancialAccommodation::StatusCancelled, 'Terminal records stay terminal.'],
            [FinancialAccommodation::StatusActive, FinancialAccommodation::StatusActive, 'No same-state transition.'],
            [FinancialAccommodation::StatusActive, FinancialAccommodation::StatusCancelled, '   '],
            [FinancialAccommodation::StatusActive, FinancialAccommodation::StatusCancelled, str_repeat('x', 1001)],
        ] as [$from, $to, $reason]) {
            try {
                $service->transition($this->accommodation($from), $to, $reason, $actor);
                $this->fail("Expected {$from} to {$to} to be rejected.");
            } catch (RuntimeException) {
                $this->assertDatabaseCount('activity_log', 0);
            }
        }

        $expiring = $this->accommodation(FinancialAccommodation::StatusActive, [
            'expires_on' => '2026-07-20',
        ]);

        $this->expectException(RuntimeException::class);
        $service->transition(
            $expiring,
            FinancialAccommodation::StatusExpired,
            'Expiry is not effective yet.',
            $actor,
            CarbonImmutable::parse('2026-07-19 23:59:59', config('app.timezone')),
        );
    }

    #[Test]
    public function expired_transition_requires_an_expiry_date(): void
    {
        $actor = $this->staff(User::StaffRoleAccounting);
        $accommodation = $this->accommodation(FinancialAccommodation::StatusActive, ['expires_on' => null]);

        $this->expectException(RuntimeException::class);

        app(FinancialAccommodationLifecycleService::class)->transition(
            $accommodation,
            FinancialAccommodation::StatusExpired,
            'Cannot expire without an approved expiry date.',
            $actor,
        );
    }

    #[Test]
    public function only_accounting_can_use_the_dedicated_transition_ability(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accommodation = $this->accommodation(FinancialAccommodation::StatusPending);

        $this->assertTrue(Gate::forUser($accounting)->allows('transition', $accommodation));
        $this->assertFalse(Gate::forUser($registrar)->allows('transition', $accommodation));
        $this->assertFalse(Gate::forUser($accounting)->allows('update', $accommodation));
        $this->assertFalse(Gate::forUser($accounting)->allows('delete', $accommodation));
        $this->assertFalse(Gate::forUser($accounting)->allows('restore', $accommodation));
        $this->assertFalse(Gate::forUser($accounting)->allows('forceDelete', $accommodation));

        $this->expectException(AuthorizationException::class);
        app(FinancialAccommodationLifecycleService::class)->transition(
            $accommodation,
            FinancialAccommodation::StatusActive,
            'Registrar must not transition Accounting records.',
            $registrar,
        );
    }

    #[Test]
    public function creation_form_accepts_only_pending_or_active_status(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        foreach ([
            FinancialAccommodation::StatusFulfilled,
            FinancialAccommodation::StatusDefaulted,
            FinancialAccommodation::StatusExpired,
            FinancialAccommodation::StatusCancelled,
        ] as $terminalStatus) {
            Livewire::actingAs($accounting)
                ->test(CreateFinancialAccommodation::class)
                ->fillForm($this->creationData($terminalStatus))
                ->call('create')
                ->assertHasFormErrors(['status']);
        }

        $this->assertDatabaseCount('financial_accommodations', 0);
    }

    #[Test]
    public function view_page_action_offers_valid_targets_transitions_and_disappears_for_terminal_records(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $pending = $this->accommodation(FinancialAccommodation::StatusPending);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($accounting)
            ->test(ViewFinancialAccommodation::class, ['record' => $pending->getRouteKey()])
            ->assertActionVisible('transitionStatus')
            ->callAction('transitionStatus', [
                'status' => FinancialAccommodation::StatusActive,
                'reason' => 'Approved accommodation activated by Accounting.',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Financial accommodation status updated');

        $this->assertSame(FinancialAccommodation::StatusActive, $pending->refresh()->status);

        $terminal = $this->accommodation(FinancialAccommodation::StatusFulfilled);
        Livewire::actingAs($accounting)
            ->test(ViewFinancialAccommodation::class, ['record' => $terminal->getRouteKey()])
            ->assertActionHidden('transitionStatus');
    }

    #[Test]
    public function terminal_transition_removes_active_only_downstream_effect_without_mutating_flags_or_schedule_history(): void
    {
        $actor = $this->staff(User::StaffRoleAccounting);
        $profile = StudentProfile::factory()->create();
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
        ]);
        $hold = Hold::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'hold_type' => Hold::TypeFinancial,
            'blocking_level' => Hold::BlockingEnrollment,
        ]);
        $accommodation = $this->accommodation(FinancialAccommodation::StatusActive, [
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'allows_finance_gate' => true,
        ]);
        $schedule = PaymentScheduleRow::query()->create([
            'financial_accommodation_id' => $accommodation->id,
            'sequence' => 1,
            'category' => PaymentScheduleRow::CategoryDownpayment,
            'due_date' => today()->addWeek(),
            'amount' => '1000.00',
            'state' => PaymentScheduleRow::StateDue,
        ]);

        $service = app(HoldEvaluationService::class);
        $this->assertFalse($service->activeBlockingHolds($profile, [Hold::BlockingEnrollment], $enrollment)->contains('id', $hold->id));

        app(FinancialAccommodationLifecycleService::class)->transition(
            $accommodation,
            FinancialAccommodation::StatusCancelled,
            'Accommodation cancelled under approved Accounting result.',
            $actor,
        );

        $this->assertTrue($service->activeBlockingHolds($profile, [Hold::BlockingEnrollment], $enrollment)->contains('id', $hold->id));
        $this->assertTrue((bool) $accommodation->refresh()->allows_finance_gate);
        $this->assertDatabaseHas('payment_schedule_rows', [
            'id' => $schedule->id,
            'financial_accommodation_id' => $accommodation->id,
            'state' => PaymentScheduleRow::StateDue,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function accommodation(string $status, array $overrides = []): FinancialAccommodation
    {
        return FinancialAccommodation::query()->create([
            'student_profile_id' => StudentProfile::factory()->create()->id,
            'term_id' => Term::factory()->create()->id,
            'balance_snapshot' => '8500.00',
            'covered_amount' => '2500.00',
            'basis' => FinancialAccommodation::BasisInstitutionalAccommodation,
            'promissory_required' => false,
            'allows_finance_gate' => false,
            'allows_next_term_enrollment' => false,
            'allows_reactivation' => false,
            'allows_record_release' => false,
            'waives_downpayment' => false,
            'authority' => 'Accounting Director',
            'status' => $status,
            'effective_from' => '2026-07-01',
            'expires_on' => '2026-07-12',
            ...$overrides,
        ]);
    }

    /** @return array<string, mixed> */
    private function creationData(string $status): array
    {
        return [
            'student_profile_id' => StudentProfile::factory()->create()->id,
            'term_id' => Term::factory()->create()->id,
            'balance_snapshot' => '8500.00',
            'covered_amount' => '2500.00',
            'basis' => FinancialAccommodation::BasisInstitutionalAccommodation,
            'status' => $status,
            'effective_from' => today()->toDateString(),
            'authority' => 'Accounting Director',
        ];
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
