<?php

namespace Tests\Feature;

use App\Actions\StudentLifecycle\LifecycleAccountingReview;
use App\Actions\StudentLifecycle\LifecycleFinanceAction;
use App\Actions\StudentLifecycle\RecordLifecycleAccountingReview;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Filament\Student\Pages\Finance;
use App\Models\ApprovedCoverage;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\OperationalEvent;
use App\Models\Payment;
use App\Models\StudentLifecycleChange;
use App\Models\TermAccount;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LifecycleAccountingReviewTest extends TestCase
{
    use DatabaseTransactions;

    public function test_existing_older_and_multiple_events_stay_pending_with_exact_source_and_missing_account_recovery(): void
    {
        [$case, $account, $actor] = $this->fixture();
        $reviews = app(LifecycleAccountingReview::class);
        $sources = collect(range(1, 12))->map(fn () => $this->change($case, $actor));
        $foreign = Enrollment::factory()->create();
        $wrongTerm = $this->change($case, $actor);
        $wrongTerm->update(['term_id' => $foreign->term_id]);
        $wrongPerson = $this->change($case, $actor);
        $wrongPerson->update(['student_profile_id' => $foreign->student_profile_id]);
        $future = $this->change($case, $actor);
        $future->update(['state' => StudentLifecycleChange::StateRecordedApproved]);
        $cancelled = $this->change($case, $actor);
        $cancelled->update(['state' => StudentLifecycleChange::StateCancelled]);

        $this->assertSame(12, $reviews->forEnrollment($case)->count());
        $this->assertSame($sources->pluck('id')->all(), $reviews->forEnrollment($case)->get()->pluck('related_record_id')->all());
        $this->assertContains($case->id, $reviews->pendingEnrollmentIds());
        $account->delete();
        $case->update(['canonical_outcome' => Enrollment::OutcomeNotEnrolled]);
        $this->assertContains($case->id, $reviews->pendingEnrollmentIds());
        $this->assertSame(12, $reviews->forEnrollment($case)->count());
        $this->expectException(ValidationException::class);
        $reviews->snapshot($case, $reviews->forEnrollment($case)->first()->id);
    }

    public function test_missing_applied_source_stays_identifiable_but_cannot_be_reviewed(): void
    {
        [$case, $account, $actor] = $this->fixture();
        $change = $this->change($case, $actor);
        $event = app(LifecycleAccountingReview::class)->forEnrollment($case)->first();
        $change->delete();
        $this->assertContains($case->id, app(LifecycleAccountingReview::class)->pendingEnrollmentIds());
        $this->expectException(ValidationException::class);
        app(LifecycleAccountingReview::class)->snapshot($case, $event->id);
    }

    public function test_review_and_repeat_confirmation_record_one_attributable_result_without_any_financial_or_academic_effect(): void
    {
        [$case, $account, $actor] = $this->fixture();
        $change = $this->change($case, $actor);
        $reviews = app(LifecycleAccountingReview::class);
        $root = $reviews->forEnrollment($case)->first();
        $snapshot = $reviews->snapshot($case, $root->id);
        $before = [$case->fresh()->getAttributes(), $account->fresh()->getAttributes(), $change->fresh()->getAttributes(),
            Assessment::query()->get()->toArray(), Payment::query()->get()->toArray()];
        Mail::fake();
        $this->travelTo(now()->startOfSecond());
        $data = ['outcome' => LifecycleAccountingReview::NoAccountChange, 'reason' => 'External review completed; existing charges remain applicable.'];
        $result = app(RecordLifecycleAccountingReview::class)->execute($case, $actor, $snapshot, $data);
        $repeat = app(RecordLifecycleAccountingReview::class)->execute($case, $actor, $snapshot, $data);

        $this->assertSame($result->id, $repeat->id);
        $this->assertSame($actor->id, $result->user_id);
        $this->assertTrue($result->occurred_at->equalTo(now()));
        $this->assertSame($data['reason'], $result->payload['reason']);
        $this->assertSame(['pending' => 0, 'reviewed' => 1], $reviews->safeStatus($case));
        $this->assertNotContains($case->id, $reviews->pendingEnrollmentIds());
        $this->assertSame(OperationalEvent::StatusReviewRequired, $root->fresh()->status);
        $this->assertSame($before, [$case->fresh()->getAttributes(), $account->fresh()->getAttributes(), $change->fresh()->getAttributes(),
            Assessment::query()->get()->toArray(), Payment::query()->get()->toArray()]);
        $this->assertSame(1, $root->lifecycleReviewResults()->count());
        Mail::assertNothingOutgoing();
    }

    public function test_correction_and_reopening_append_successors_and_restore_the_same_pending_item(): void
    {
        [$case, $account, $actor] = $this->fixture();
        $this->change($case, $actor);
        $reviews = app(LifecycleAccountingReview::class);
        $root = $reviews->forEnrollment($case)->first();
        $action = app(RecordLifecycleAccountingReview::class);
        $first = $action->execute($case, $actor, $reviews->snapshot($case, $root->id), [
            'outcome' => LifecycleAccountingReview::NoAccountChange, 'reason' => 'Original external review.']);
        $second = $action->execute($case, $actor, $reviews->snapshot($case, $root->id), [
            'outcome' => LifecycleAccountingReview::Pending, 'reason' => 'Further external review is required.']);
        $this->assertSame($first->id, $second->payload['previous_result_id']);
        $this->assertSame(['pending' => 1, 'reviewed' => 0], $reviews->safeStatus($case));
        $this->assertContains($case->id, $reviews->pendingEnrollmentIds());
        $this->assertSame($second->id, $reviews->snapshot($case, $root->id)['current_result_id']);
        $this->assertSame([$second->id, $first->id], $reviews->history($case)->pluck('id')->all());
        $this->assertSame('Original external review.', $first->fresh()->payload['reason']);
    }

    public function test_financial_result_links_an_unchanged_same_account_record_and_requires_change_specific_explanation(): void
    {
        [$case, $account, $actor] = $this->fixture();
        $this->change($case, $actor);
        $payment = Payment::factory()->create(['term_account_id' => $account->id, 'student_profile_id' => $case->student_profile_id,
            'term_id' => $case->term_id, 'state' => Payment::StateReversal, 'verified_by' => $actor->id]);
        $reviews = app(LifecycleAccountingReview::class);
        $key = 'payment:'.$payment->id;
        $snapshot = $reviews->snapshot($case, $reviews->forEnrollment($case)->first()->id);
        $data = ['outcome' => LifecycleAccountingReview::FinancialActionRecorded, 'reason' => '', 'financial_source' => $key];
        try {
            app(RecordLifecycleAccountingReview::class)->execute($case, $actor, $snapshot, $data, [$key => $reviews->fingerprint($payment->fresh())]);
            $this->fail('An explanation linking the financial action to this change is required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }
        $before = $payment->fresh()->getAttributes();
        $result = app(RecordLifecycleAccountingReview::class)->execute($case, $actor, $snapshot,
            [...$data, 'reason' => 'This recorded reversal addresses the payment retained after this withdrawal.'], [$key => $reviews->fingerprint($payment->fresh())]);
        $this->assertSame(Payment::class, $result->payload['financial_record_type']);
        $this->assertSame($payment->id, $result->payload['financial_record_id']);
        $this->assertSame($before, $payment->fresh()->getAttributes());
    }

    #[DataProvider('invalidSources')]
    public function test_invalid_or_changed_sources_fail_atomically_and_preserve_pending_work(string $kind): void
    {
        [$case, $account, $actor] = $this->fixture();
        $change = $this->change($case, $actor);
        $reviews = app(LifecycleAccountingReview::class);
        $root = $reviews->forEnrollment($case)->first();
        $snapshot = $reviews->snapshot($case, $root->id);
        $data = ['outcome' => LifecycleAccountingReview::NoAccountChange, 'reason' => 'Review completed.'];
        if ($kind === 'stale_source') {
            $change->update(['effective_on' => now()->subDay()]);
        } elseif ($kind === 'foreign_account') {
            $snapshot['account_id'] = TermAccount::factory()->create()->id;
        } elseif ($kind === 'foreign_change') {
            $change->update(['student_profile_id' => Enrollment::factory()->create()->student_profile_id]);
        } elseif ($kind === 'unapplied') {
            $change->update(['state' => StudentLifecycleChange::StateRecordedApproved]);
        } else {
            $foreign = Payment::factory()->create(['term_account_id' => TermAccount::factory()->create()->id, 'state' => Payment::StatePosted]);
            $key = 'payment:'.$foreign->id;
            $data = ['outcome' => LifecycleAccountingReview::FinancialActionRecorded, 'reason' => 'Claimed relationship.', 'financial_source' => $key];
        }
        try {
            app(RecordLifecycleAccountingReview::class)->execute($case, $actor, $snapshot, $data);
            $this->fail('The invalid source must be rejected.');
        } catch (ValidationException) {
            $this->assertSame(0, $root->lifecycleReviewResults()->count());
        }
    }

    public static function invalidSources(): array
    {
        return array_combine(['stale_source', 'foreign_account', 'foreign_change', 'unapplied', 'unrelated_financial_record'],
            array_map(fn (string $kind): array => [$kind], ['stale_source', 'foreign_account', 'foreign_change', 'unapplied', 'unrelated_financial_record']));
    }

    public function test_competing_review_of_the_same_version_is_rejected_without_overwriting_the_first_result(): void
    {
        [$case, $account, $actor] = $this->fixture();
        $this->change($case, $actor);
        $reviews = app(LifecycleAccountingReview::class);
        $root = $reviews->forEnrollment($case)->first();
        $snapshot = $reviews->snapshot($case, $root->id);
        $action = app(RecordLifecycleAccountingReview::class);
        $first = $action->execute($case, $actor, $snapshot, ['outcome' => LifecycleAccountingReview::NoAccountChange, 'reason' => 'First reviewer result.']);
        try {
            $action->execute($case, $actor, $snapshot, ['outcome' => LifecycleAccountingReview::NoAccountChange, 'reason' => 'Competing review.']);
            $this->fail('A competing save of the previous version must fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Nothing was recorded', $exception->getMessage());
            $this->assertSame($first->id, $root->latestLifecycleReviewResult()->first()->id);
            $this->assertSame(1, $root->lifecycleReviewResults()->count());
        }
    }

    #[DataProvider('refusedRoles')]
    public function test_only_active_accounting_can_record_or_correct_a_result(string $role): void
    {
        [$case, $account, $actor] = $this->fixture();
        $this->change($case, $actor);
        $reviews = app(LifecycleAccountingReview::class);
        $snapshot = $reviews->snapshot($case, $reviews->forEnrollment($case)->first()->id);
        if ($role === 'disabled-accounting') {
            $actor->update(['status' => User::StatusDisabled]);
        } else {
            $actor->syncRoles([]);
            Role::findOrCreate($role, 'web');
            $actor->assignRole($role);
        }
        $this->expectException(AuthorizationException::class);
        app(RecordLifecycleAccountingReview::class)->execute($case, $actor, $snapshot, ['outcome' => LifecycleAccountingReview::NoAccountChange, 'reason' => 'Attempt.']);
    }

    public static function refusedRoles(): array
    {
        return array_map(fn (string $role): array => [$role], ['student', 'registrar', 'faculty', 'academic-head', 'system-super-admin', 'disabled-accounting']);
    }

    public function test_student_sees_safe_review_recovery_without_a_term_account(): void
    {
        [$case, $account, $actor] = $this->fixture();
        $change = $this->change($case, $actor);
        $change->update(['reason' => 'PRIVATE-MISSING-ACCOUNT-REASON']);
        $account->delete();
        $learner = $case->credentialUser;
        Role::findOrCreate('student', 'web');
        $learner->assignRole('student');
        $learner->update(['status' => User::StatusActive]);
        [$foreignCase, $foreignAccount, $foreignActor] = $this->fixture();
        $this->change($foreignCase, $foreignActor);
        $foreignAccount->delete();
        $foreignCase->term->update(['label' => 'FOREIGN-TERM-MUST-NOT-APPEAR']);
        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::actingAs($learner)->test(Finance::class)
            ->assertSee('Accounting is reviewing a student change')
            ->assertSee($case->term->label)
            ->assertSee('Account source unavailable')
            ->assertDontSee('PRIVATE-MISSING-ACCOUNT-REASON')
            ->assertDontSee('FOREIGN-TERM-MUST-NOT-APPEAR')
            ->assertDontSee('PHP 0.00');
    }

    public function test_financial_source_labels_present_human_details_without_leaking_internal_keys(): void
    {
        [$case, $account, $actor] = $this->fixture();
        $change = $this->change($case, $actor);
        $root = app(LifecycleAccountingReview::class)->forEnrollment($case)->firstOrFail();

        $payment = Payment::factory()->create([
            'term_account_id' => $account->id,
            'student_profile_id' => $case->student_profile_id,
            'term_id' => $case->term_id,
            'state' => Payment::StatePosted,
            'evidence_status' => 'verified',
            'verified_by' => $actor->id,
            'verified_at' => now(),
            'paid_at' => now(),
            'channel' => 'bank_transfer',
            'or_number' => 'OR-HUMAN-404',
            'amount' => '1500.00',
        ]);
        $coverage = ApprovedCoverage::factory()->create([
            'term_account_id' => $account->id,
            'state' => ApprovedCoverage::StateApplied,
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'authority_reference' => 'SCHOLAR-HUMAN-77',
            'amount' => '2500.00',
        ]);
        $assessment = Assessment::factory()->create([
            'term_account_id' => $account->id,
            'enrollment_id' => $case->id,
            'state' => Assessment::StateActive,
            'version' => 2,
            'authority_reference' => 'ASSESS-HUMAN-99',
            'total' => '5000.00',
            'subtotal' => '5000.00',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $reviews = app(LifecycleAccountingReview::class);
        $sources = $reviews->financialSources($case);

        $this->assertArrayHasKey("payment:{$payment->id}", $sources);
        $this->assertArrayHasKey("coverage:{$coverage->id}", $sources);
        $this->assertArrayHasKey("assessment:{$assessment->id}", $sources);

        $paymentLabel = $reviews->financialSourceLabel("payment:{$payment->id}", $payment);
        $coverageLabel = $reviews->financialSourceLabel("coverage:{$coverage->id}", $coverage);
        $assessmentLabel = $reviews->financialSourceLabel("assessment:{$assessment->id}", $assessment);

        $this->assertStringContainsString('Verified payment', $paymentLabel);
        $this->assertStringContainsString('OR-HUMAN-404', $paymentLabel);
        $this->assertStringNotContainsString("payment:{$payment->id}", $paymentLabel);
        $this->assertStringContainsString('Approved coverage', $coverageLabel);
        $this->assertStringContainsString('SCHOLAR-HUMAN-77', $coverageLabel);
        $this->assertStringNotContainsString("coverage:{$coverage->id}", $coverageLabel);
        $this->assertStringContainsString('Authorized assessment', $assessmentLabel);
        $this->assertStringContainsString('Version 2', $assessmentLabel);
        $this->assertStringContainsString('ASSESS-HUMAN-99', $assessmentLabel);
        $this->assertStringNotContainsString("assessment:{$assessment->id}", $assessmentLabel);

        $page = Livewire::actingAs($actor)->test(ViewEnrollment::class, ['record' => $case->id])
            ->mountAction('recordLifecycleReview', ['review' => $root->id]);

        $options = $page->instance()->lifecycleFinancialSourceOptions;
        $this->assertSame($paymentLabel, $options["payment:{$payment->id}"] ?? null);
        $this->assertSame($coverageLabel, $options["coverage:{$coverage->id}"] ?? null);
        $this->assertSame($assessmentLabel, $options["assessment:{$assessment->id}"] ?? null);
    }

    /** @return array{Enrollment,TermAccount,User} */
    private function fixture(): array
    {
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::selectOne('SELECT DATABASE() AS name')->name);
        $case = Enrollment::factory()->create();
        $account = TermAccount::factory()->create(['enrollment_id' => $case->id, 'credential_user_id' => $case->credential_user_id, 'term_id' => $case->term_id]);
        Role::findOrCreate(User::StaffRoleAccounting, 'web');
        $actor = User::factory()->create(['status' => User::StatusActive]);
        $actor->assignRole(User::StaffRoleAccounting);

        return [$case, $account, $actor];
    }

    private function change(Enrollment $case, User $actor): StudentLifecycleChange
    {
        $change = StudentLifecycleChange::factory()->create(['enrollment_id' => $case->id, 'student_profile_id' => $case->student_profile_id,
            'term_id' => $case->term_id, 'state' => StudentLifecycleChange::StateApplied]);
        app(LifecycleFinanceAction::class)->execute($change, $actor);

        return $change;
    }
}
