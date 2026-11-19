<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\CreateContextualFinanceExport;
use App\Actions\Finance\RecordApprovedCoverage;
use App\Actions\Finance\TermAccountProjection;
use App\Actions\StudentLifecycle\LifecycleAccountingReview;
use App\Actions\StudentLifecycle\LifecycleFinanceAction;
use App\Actions\StudentLifecycle\RecordLifecycleAccountingReview;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Enrollments\Pages\AccountingAccountInspector;
use App\Filament\Resources\Enrollments\Pages\ListEnrollments;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Filament\Resources\StudentLifecycleChanges\Pages\ListStudentLifecycleChanges;
use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use App\Models\AdmissionApplication;
use App\Models\ApprovedCoverage;
use App\Models\Assessment;
use App\Models\AssessmentEffectRevalidation;
use App\Models\AssessmentObligation;
use App\Models\CorVersion;
use App\Models\CourseDropRecord;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\FinanceExport;
use App\Models\OperationalEvent;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentEvidenceVersion;
use App\Models\Program;
use App\Models\RegistrationProposalConfirmation;
use App\Models\RegistrationProposalVersion;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermAccount;
use App\Models\TranscriptRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StudentAccountsWorkbenchAcceptanceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());

        Role::query()->firstOrCreate([
            'name' => User::StaffRoleAccounting,
            'guard_name' => 'web',
        ]);
    }

    public function test_accounting_workbench_discovers_separate_assessment_and_proof_work_without_a_tor_approval_queue(): void
    {
        $accounting = $this->accounting();
        $ordinary = $this->accountFixture('ordinary@example.test');
        $proof = $this->accountFixture('proof@example.test');
        $assessment = $this->accountFixture('assessment@example.test');
        $assessment['assessment']->update(['state' => Assessment::StateSuperseded]);
        $clearance = $this->accountFixture('clearance@example.test');
        PaymentEvidenceVersion::factory()->create([
            'term_account_id' => $proof['account']->id,
            'state' => PaymentEvidenceVersion::StateSubmitted,
        ]);
        PaymentEvidenceVersion::factory()->create([
            'term_account_id' => $proof['account']->id,
            'version' => 2,
            'state' => PaymentEvidenceVersion::StateRejected,
        ]);
        TranscriptRequest::factory()->create([
            'student_profile_id' => $clearance['enrollment']->student_profile_id,
            'external_request_reference' => 'TOR-SYNTH-WORKBENCH',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($accounting)
            ->test(ListEnrollments::class)
            ->assertSet('activeTab', 'overview')
            ->assertSee('2 accounts need review')
            ->assertSee('Across all active and prior terms')
            ->assertSee('Open term accounts')
            ->assertDontSee('Active term:')
            ->assertSee('Review fee plans')
            ->assertSee('Review assessments')
            ->assertSee('Review receipts')
            ->assertSee('Review changes')
            ->assertSee('Find a student')
            ->assertSee('All student accounts')
            ->assertSee('TOR restrictions')
            ->assertDontSee('TOR Clearance');

        $this->assertSame('Accounting overview', $component->instance()->getTitle());
        $this->assertSame(['assessment' => 1, 'proof' => 1, 'student_changes' => 0], $component->instance()->accountingWorkCounts());
        $this->assertArrayNotHasKey('tor_clearance', $component->instance()->getTabs());

        $component
            ->set('activeTab', 'accounts')
            ->assertSee('Choose a term or student')
            ->assertCanNotSeeTableRecords([$ordinary['enrollment'], $proof['enrollment'], $assessment['enrollment'], $clearance['enrollment']])
            ->call('showAllAccountingTerms')
            ->assertCanSeeTableRecords([$ordinary['enrollment'], $proof['enrollment'], $assessment['enrollment'], $clearance['enrollment']])
            ->set('activeTab', 'assessment_required')
            ->assertCanSeeTableRecords([$assessment['enrollment']])
            ->assertCanNotSeeTableRecords([$ordinary['enrollment'], $proof['enrollment'], $clearance['enrollment']])
            ->set('activeTab', 'payment_proof')
            ->assertCanSeeTableRecords([$proof['enrollment']])
            ->assertCanNotSeeTableRecords([$ordinary['enrollment'], $assessment['enrollment'], $clearance['enrollment']]);

        $this->assertNotContains(
            'App\\Filament\\Resources\\Payments\\PaymentResource',
            Filament::getPanel('admin')->getResources(),
        );
        $this->assertFalse(Route::has('filament.admin.resources.payments.index'));
        $this->assertFalse(Route::has('filament.admin.resources.payments.view'));
    }

    public function test_account_records_search_matches_multiple_name_tokens_and_preserves_later_fees_when_nothing_is_due_now(): void
    {
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('later-fees@example.test', 'SYN-LATER-0123');
        $fixture['learner']->update(['first_name' => 'Mira', 'middle_name' => null, 'last_name' => 'Santos']);
        $other = $this->accountFixture('other-search@example.test', 'SYN-OTHER-0456');
        $fixture['assessment']->obligations()->update(['due_at' => now()->addMonth()]);
        $fixture['enrollment']->update(['canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($accounting)->test(ListEnrollments::class)
            ->set('activeTab', 'accounts')->searchTable('Santos Mira')
            ->assertCanSeeTableRecords([$fixture['enrollment']])
            ->assertCanNotSeeTableRecords([$other['enrollment']])
            ->assertSee('No payment due now')->assertSeeInOrder(['Later fees', 'PHP 12,000.00'])
            ->assertSee('Current work')
            ->searchTable('SYN-LATER-0123')->assertCanSeeTableRecords([$fixture['enrollment']])
            ->searchTable('unmatched student')->assertSee('No matching accounts');
    }

    public function test_filtered_queue_absence_does_not_claim_that_pending_work_is_clear(): void
    {
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('filter-recovery@example.test');
        $otherTerm = Term::factory()->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($accounting)->test(ListEnrollments::class)
            ->set('activeTab', 'accounts')
            ->call('selectAccountingTerm', $fixture['enrollment']->term_id)
            ->call('selectAccountingRecord', $fixture['enrollment']->id)
            ->assertSet('selectedAccountingId', $fixture['enrollment']->id)
            ->filterTable('term', $otherTerm->id)
            ->assertCanNotSeeTableRecords([$fixture['enrollment']])
            ->assertSee('No matching accounts')
            ->assertSee('clear the search and filters')
            ->assertSet('selectedAccountingId', null);
    }

    public function test_explicit_account_and_assessment_destinations_preserve_intent_when_payment_work_overlaps(): void
    {
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('overlapping-work@example.test');
        PaymentEvidenceVersion::factory()->create([
            'term_account_id' => $fixture['account']->id,
            'assessment_id' => $fixture['assessment']->id,
            'state' => PaymentEvidenceVersion::StateSubmitted,
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        foreach (['account' => 'accounting-account', 'assessment' => 'accounting-account', 'proof' => 'accounting-proof'] as $view => $expected) {
            $page = Livewire::actingAs($accounting)->withQueryParams(['view' => $view, 'queue' => 'accounts', 'returnSearch' => 'Santos', 'returnFilters' => ['term' => ['value' => $fixture['term']->id]]])
                ->test(ViewEnrollment::class, ['record' => $fixture['enrollment']->id]);
            $this->assertSame($expected, $page->instance()->focusedAccountingView());
            $page->assertSee(e(EnrollmentResource::getUrl('index', ['tab' => 'accounts', 'search' => 'Santos', 'filters' => ['term' => ['value' => $fixture['term']->id]]])), false);
            $this->assertSame(EnrollmentResource::getUrl('index', [
                'tab' => 'accounts', 'account' => $fixture['enrollment']->id, 'search' => 'Santos',
                'filters' => ['term' => ['value' => $fixture['term']->id]], 'view' => $view,
            ]), $page->instance()->enrollmentUrl());
        }
    }

    public function test_proof_comparison_keeps_exact_targets_and_workspace_choice_and_blocks_a_changed_source(): void
    {
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('proof-comparison@example.test');
        $fixture['assessment']->update(['subtotal' => '12000.30', 'total' => '12000.30']);
        $firstTarget = $fixture['assessment']->obligations()->sole();
        $firstTarget->update(['amount' => '3000.10']);
        $secondTarget = AssessmentObligation::factory()->create([
            'assessment_id' => $fixture['assessment']->id, 'sequence' => 2, 'code' => 'LATER',
            'label' => 'Later term payment', 'amount' => '9000.20', 'due_at' => now()->addMonth(),
        ]);
        $proof = PaymentEvidenceVersion::factory()->create([
            'term_account_id' => $fixture['account']->id, 'assessment_id' => $fixture['assessment']->id,
            'claimed_amount' => '1000.00', 'channel' => 'bank_transfer', 'paid_at' => now()->subMinute(),
            'obligation_snapshot' => [['id' => $firstTarget->id, 'label' => $firstTarget->label], ['id' => $secondTarget->id, 'label' => $secondTarget->label]],
        ]);
        $other = PaymentEvidenceVersion::factory()->create([
            'term_account_id' => $fixture['account']->id, 'assessment_id' => $fixture['assessment']->id, 'version' => 2,
            'obligation_snapshot' => [['id' => $firstTarget->id, 'label' => $firstTarget->label]],
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::actingAs($accounting)->test(AccountingAccountInspector::class, [
            'record' => $fixture['enrollment']->id, 'returnQueue' => 'payment_proof',
            'paymentProofId' => $proof->id, 'enrollmentView' => 'proof', 'returnSearch' => 'Santos',
        ])->assertSee('Compare submitted receipt')->assertSee('On the submitted receipt')
            ->assertSee('Current unpaid targets')->assertSee('PHP 12,000.30')
            ->assertSee(e(EnrollmentResource::getUrl('index', [
                'tab' => 'payment_proof', 'account' => $fixture['enrollment']->id,
                'search' => 'Santos', 'proof' => $other->id, 'view' => 'proof',
            ])), false);
        $this->assertSame('12000.30', $page->instance()->paymentProofViewData()['targetBalance']);

        $page->mountAction('verifyPaymentEvidence')->assertMountedActionModalSee('Record verified payment')
            ->unmountAction()->assertHasNoActionErrors();
        $fixture['assessment']->update(['state' => Assessment::StateSuperseded]);
        $page->call('$refresh')->assertSee('Payment target changed');
        $this->assertNull($page->instance()->paymentProofViewData()['targetBalance']);
        $this->assertSame(PaymentEvidenceVersion::StateSubmitted, $proof->fresh()->state);
        $this->assertSame(0, Payment::query()->where('term_account_id', $fixture['account']->id)->count());
    }

    public function test_current_work_keeps_overlapping_reviews_visible_across_work_filters(): void
    {
        $actor = $this->accounting();
        $fixture = $this->accountFixture('unified-work@example.test');
        PaymentEvidenceVersion::factory()->create([
            'term_account_id' => $fixture['account']->id,
            'assessment_id' => $fixture['assessment']->id,
            'state' => PaymentEvidenceVersion::StateSubmitted,
        ]);
        $change = StudentLifecycleChange::factory()->create([
            'enrollment_id' => $fixture['enrollment']->id,
            'student_profile_id' => $fixture['enrollment']->student_profile_id,
            'term_id' => $fixture['term']->id,
            'state' => StudentLifecycleChange::StateApplied,
        ]);
        app(LifecycleFinanceAction::class)->execute($change, $actor);

        foreach (['accounts' => 'overview', 'payment_proof' => 'proof', 'student_changes' => 'changes'] as $filter => $view) {
            $page = Livewire::actingAs($actor)->withQueryParams(['tab' => $filter, 'account' => $fixture['enrollment']->id])
                ->test(ListEnrollments::class)
                ->assertDontSee('Account work filters')
                ->assertSeeLivewire(AccountingAccountInspector::class);
            $this->assertSame('Student accounts', $page->instance()->getTitle());

            Livewire::actingAs($actor)->test(AccountingAccountInspector::class, [
                'record' => $fixture['enrollment']->id, 'enrollmentView' => $view, 'returnQueue' => $filter,
            ])->assertSee('Review student status changes')->assertSee('Compare submitted receipt')
                ->assertSee('Charges and payment dates');
        }

        $page->set('activeTab', 'assessment_required')->assertSet('selectedAccountingId', null)
            ->assertSee('No assessments waiting for review');
        $this->assertNull($page->instance()->selectedAccountingRecord());
        $this->assertSame(0, $fixture['account']->payments()->count());
    }

    public function test_completed_student_change_reviews_remain_reachable_in_account_history_with_correction_and_tor_context(): void
    {
        $actor = $this->accounting();
        $fixture = $this->accountFixture('review-history-recovery@example.test');
        $case = $fixture['enrollment'];
        $change = StudentLifecycleChange::factory()->create([
            'enrollment_id' => $case->id, 'student_profile_id' => $case->student_profile_id,
            'term_id' => $case->term_id, 'state' => StudentLifecycleChange::StateApplied,
        ]);
        app(LifecycleFinanceAction::class)->execute($change, $actor);
        $reviews = app(LifecycleAccountingReview::class);
        $root = $reviews->forEnrollment($case)->firstOrFail();
        app(RecordLifecycleAccountingReview::class)->execute($case, $actor, $reviews->snapshot($case, $root->id), [
            'outcome' => LifecycleAccountingReview::NoAccountChange, 'reason' => 'Recorded review remains available after leaving pending work.',
        ]);
        TranscriptRequest::factory()->create(['student_profile_id' => $case->student_profile_id]);

        Livewire::actingAs($actor)->test(AccountingAccountInspector::class, [
            'record' => $case->id, 'enrollmentView' => 'history', 'returnQueue' => 'accounts',
        ])->assertSee('Recorded review remains available after leaving pending work.')
            ->assertSee('Correct review result')->assertSee('Review TOR restrictions');
        $this->assertNotContains($case->id, $reviews->pendingEnrollmentIds());
    }

    public function test_current_account_separates_due_now_from_later_term_fees_and_hides_registrar_controls(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00:00', 'Asia/Manila'));
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('current-account@example.test');
        $fixture['assessment']->obligations()->firstOrFail()->update(['amount' => '3000.00']);
        AssessmentObligation::factory()->create([
            'assessment_id' => $fixture['assessment']->id,
            'sequence' => 2,
            'code' => 'LATER',
            'label' => 'Later term payment',
            'amount' => '9000.00',
            'due_at' => now()->addMonth(),
            'required_for_enrollment' => false,
        ]);
        OperationalEvent::factory()->failed()->forUser($fixture['learner'])->create([
            'event_type' => OperationalEvent::TypeOfficialEnrollmentEmail,
            'related_record_type' => Enrollment::class,
            'related_record_id' => $fixture['enrollment']->id,
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($accounting)->test(ViewEnrollment::class, ['record' => $fixture['enrollment']->id])
            ->assertSeeInOrder(['Due now', 'PHP 3,000.00'])
            ->assertSeeInOrder(['Later fees', 'PHP 9,000.00'])
            ->assertSee('Enrollment payment pending')
            ->assertSee('Later term payment')
            ->assertSee('Account history')
            ->assertSee(e(EnrollmentResource::getUrl('index', ['tab' => 'accounts', 'account' => $fixture['enrollment']->id, 'view' => 'history'])), false)
            ->assertDontSee('No payment due now')
            ->assertDontSee('Fully paid')
            ->assertDontSee('Print current COR')
            ->assertDontSee('Print COR history')
            ->assertDontSee('Recent student changes')
            ->assertDontSee('Resend failed enrollment email');

        $this->assertSame('accounting-account', $component->instance()->focusedAccountingView());
    }

    public function test_account_opens_all_pending_exact_enrollment_changes_without_exposing_private_sources_or_changing_finance(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00', 'Asia/Manila'));
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('lifecycle-source@example.test');
        $case = $fixture['enrollment'];
        $attributes = [
            'student_profile_id' => $case->student_profile_id,
            'term_id' => $case->term_id,
            'enrollment_id' => $case->id,
            'type' => StudentLifecycleChange::TypeLeaveOfAbsence,
            'reason' => 'PRIVATE-LIFECYCLE-REASON',
            'state' => StudentLifecycleChange::StateApplied,
            'private_source_reference' => 'PRIVATE-LIFECYCLE-REFERENCE',
            'impact_snapshot' => ['finance_effect' => ['mode' => 'accounting_review_required']],
        ];
        $sources = StudentLifecycleChange::factory()->count(6)->create($attributes);
        foreach ($sources as $source) {
            app(LifecycleFinanceAction::class)->execute($source, $accounting);
        }
        $eventless = StudentLifecycleChange::factory()->create($attributes);
        $unrelated = [
            StudentLifecycleChange::factory()->create([...$attributes, 'enrollment_id' => null]),
            StudentLifecycleChange::factory()->create([...$attributes, 'student_profile_id' => StudentProfile::factory()->create()->id]),
            StudentLifecycleChange::factory()->create([...$attributes, 'term_id' => Term::factory()->create()->id]),
        ];
        foreach ($unrelated as $source) {
            OperationalEvent::factory()->create([
                'event_domain' => OperationalEvent::DomainOperations,
                'event_type' => OperationalEvent::TypeLifecycleAccountingReview,
                'related_record_type' => StudentLifecycleChange::class,
                'related_record_id' => $source->id,
            ]);
        }
        $before = app(TermAccountProjection::class)->forAccount($fixture['account']);
        $eventCount = OperationalEvent::query()->count();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($accounting)->test(ViewEnrollment::class, ['record' => $case->id])
            ->assertSee('Student status changes')
            ->assertSee('Pending student status changes')
            ->assertSee('Record review result')
            ->assertDontSee('PRIVATE-LIFECYCLE-REASON')
            ->assertDontSee('PRIVATE-LIFECYCLE-REFERENCE');
        $this->assertSame(6, $component->instance()->accountingViewData()['lifecycleStatus']['pending']);
        $component = Livewire::actingAs($accounting)->withQueryParams(['view' => 'changes'])->test(ViewEnrollment::class, ['record' => $case->id])
            ->assertSee('Pending student status changes')
            ->assertSee('Leave of Absence')
            ->assertSee(e(StudentLifecycleChangeResource::getUrl('index', ['change' => $sources->last()->id, 'account' => $case->id, 'queue' => 'student_changes'])), false)
            ->assertDontSee(StudentLifecycleChangeResource::getUrl('index', ['change' => $eventless->id]), false)
            ->assertDontSee('PRIVATE-LIFECYCLE-REASON')
            ->assertDontSee('PRIVATE-LIFECYCLE-REFERENCE');

        $context = $component->instance()->lifecycleReviewData();
        $this->assertSame($sources->pluck('id')->all(), $context['pendingReviews']->getCollection()->pluck('related_record_id')->all());
        $this->assertSame('ordinary', $component->instance()->accountingViewData()['reviewState']);
        $this->assertSame($before, app(TermAccountProjection::class)->forAccount($fixture['account']->fresh()));
        $this->assertSame($eventCount, OperationalEvent::query()->count());
        $this->assertSame('12000.00', $fixture['assessment']->fresh()->total);

        Livewire::actingAs($accounting)->withQueryParams(['change' => $sources->last()->id])
            ->test(ListStudentLifecycleChanges::class)
            ->assertSet('selectedChangeId', $sources->last()->id)
            ->assertSee('PRIVATE-LIFECYCLE-REASON')
            ->assertSee('PRIVATE-LIFECYCLE-REFERENCE')
            ->assertSee('Student accounts · Financial review')
            ->assertSee('Open student account')
            ->assertSee(e(EnrollmentResource::getUrl('index', ['tab' => 'student_changes', 'account' => $case->id, 'view' => 'changes'])), false)
            ->assertDontSee('Open full record');
        Livewire::withQueryParams([]);
    }

    public function test_accounting_records_and_corrects_a_review_through_the_native_dialog_with_cancel_and_stale_recovery(): void
    {
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('review-dialog@example.test');
        $case = $fixture['enrollment'];
        $source = StudentLifecycleChange::factory()->create(['enrollment_id' => $case->id, 'student_profile_id' => $case->student_profile_id,
            'term_id' => $case->term_id, 'state' => StudentLifecycleChange::StateApplied]);
        $event = app(LifecycleFinanceAction::class)->execute($source, $accounting);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($accounting)->withQueryParams(['view' => 'changes'])->test(ViewEnrollment::class, ['record' => $case->id]);
        $component->mountAction('recordLifecycleReview', ['review' => $event->id])->assertMountedActionModalSee('Review result')->unmountAction();
        $this->assertSame(0, $event->lifecycleReviewResults()->count());
        $component->callAction('recordLifecycleReview', ['outcome' => LifecycleAccountingReview::NoAccountChange, 'reason' => 'Private external review explanation.'], ['review' => $event->id])
            ->assertHasNoActionErrors()->assertSee('No student status changes waiting for review')->assertSee('Private external review explanation.');
        $this->assertSame(1, $event->lifecycleReviewResults()->count());
        $component->mountAction('recordLifecycleReview', ['review' => $event->id])->assertMountedActionModalSee('Correct review result');
        $latest = $event->lifecycleReviewResults()->latest('id')->first();
        app(RecordLifecycleAccountingReview::class)->execute($case, $accounting,
            app(LifecycleAccountingReview::class)->snapshot($case, $event->id), ['outcome' => LifecycleAccountingReview::Pending, 'reason' => 'A second reviewer reopened this change.']);
        $component->setActionData(['outcome' => LifecycleAccountingReview::NoAccountChange, 'reason' => 'Stale correction.'])->callMountedAction()
            ->assertHasErrors(['review'])->assertNotified('Review result was not recorded');
        $this->assertSame(2, $event->lifecycleReviewResults()->count());
        $this->assertSame('Private external review explanation.', $latest->fresh()->payload['reason']);
        Livewire::withQueryParams([]);
    }

    public function test_current_account_preserves_later_fees_after_enrollment_payment_and_opens_retained_history(): void
    {
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00:00', 'Asia/Manila'));
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('satisfied-account@example.test');
        $obligation = $fixture['assessment']->obligations()->firstOrFail();
        $obligation->update(['amount' => '3000.00']);
        AssessmentObligation::factory()->create([
            'assessment_id' => $fixture['assessment']->id,
            'sequence' => 2,
            'code' => 'LATER',
            'label' => 'Later term payment',
            'amount' => '9000.00',
            'due_at' => now()->addMonth(),
            'required_for_enrollment' => false,
        ]);
        $payment = Payment::factory()->create([
            'student_profile_id' => $fixture['enrollment']->student_profile_id,
            'term_account_id' => $fixture['account']->id,
            'term_id' => $fixture['term']->id,
            'amount' => '3000.00',
            'state' => Payment::StatePosted,
            'provider_reference' => 'ACCOUNT-HISTORY-PAYMENT',
            'verified_by' => $accounting->id,
        ]);
        PaymentAllocation::factory()->create([
            'payment_id' => $payment->id,
            'assessment_line_id' => null,
            'assessment_obligation_id' => $obligation->id,
            'sequence' => 1,
            'amount' => '3000.00',
        ]);
        $fixture['assessment']->update(['version' => 2]);
        Assessment::factory()->create([
            'enrollment_id' => $fixture['enrollment']->id,
            'term_account_id' => $fixture['account']->id,
            'version' => 1,
            'state' => Assessment::StateSuperseded,
            'authority_reference' => 'RETAINED-ASSESSMENT-AUTHORITY',
        ]);
        $ownExport = app(CreateContextualFinanceExport::class)->createVerifiedPayments($accounting, $fixture['account'], '', []);
        $otherExport = app(CreateContextualFinanceExport::class)->createVerifiedPayments($this->accounting(), $fixture['account'], '', []);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($accounting)->test(ViewEnrollment::class, ['record' => $fixture['enrollment']->id])
            ->assertSee('No payment due now')
            ->assertSee('Enrollment payment satisfied')
            ->assertSeeInOrder(['Later fees', 'PHP 9,000.00'])
            ->assertSee('PHP 9,000.00 remaining')
            ->assertSee('Open acknowledgment')
            ->assertDontSee('Fully paid');

        $history = Livewire::actingAs($accounting)->withQueryParams(['view' => 'history'])
            ->test(ViewEnrollment::class, ['record' => $fixture['enrollment']->id])
            ->assertSee('ACCOUNT-HISTORY-PAYMENT')
            ->assertSee('RETAINED-ASSESSMENT-AUTHORITY')
            ->assertSee('SYNTH-FEE-AUTHORITY')
            ->assertSee(route('finance.payments.acknowledgement', $payment), false)
            ->assertSee(route('finance.statement', $fixture['assessment']), false)
            ->assertSee(route('finance.exports.download', $ownExport), false)
            ->assertDontSee(route('finance.exports.download', $otherExport), false)
            ->assertDontSee('Print current COR')
            ->assertDontSee('Resend failed enrollment email');

        $this->actingAs($accounting)->get(route('finance.exports.download', $otherExport))->assertForbidden();

        $this->assertSame('accounting-history', $history->instance()->focusedAccountingView());
        $this->assertSame('Account history', $history->instance()->getTitle());
    }

    public function test_account_history_paginates_source_records_without_hiding_older_assessments(): void
    {
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('history-pagination@example.test');
        PaymentEvidenceVersion::factory()->create(['term_account_id' => $fixture['account']->id, 'payment_reference' => 'RECEIPT-KEPT-WHILE-PAGING']);
        PaymentEvidenceVersion::factory()->create(['payment_reference' => 'FOREIGN-HISTORY-SOURCE']);
        foreach (range(2, 13) as $version) {
            Assessment::factory()->create([
                'term_account_id' => $fixture['account']->id,
                'version' => $version,
                'authority_reference' => 'HIST-ASSESSMENT-'.$version,
                'state' => Assessment::StateSuperseded,
                'created_at' => now()->subDays($version),
            ]);
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $history = Livewire::actingAs($accounting)->withQueryParams(['view' => 'history'])
            ->test(ViewEnrollment::class, ['record' => $fixture['enrollment']->id])
            ->assertDontSee('HIST-ASSESSMENT-13')->assertSee('RECEIPT-KEPT-WHILE-PAGING')->assertDontSee('FOREIGN-HISTORY-SOURCE')
            ->assertDontSee('Export verified payments')->assertDontSee('View source details');
        $this->assertSame(13, $history->instance()->accountingHistoryGroups()['assessment']['records']->total());
        $this->assertCount(10, $history->instance()->accountingHistoryGroups()['assessment']['records']);

        $history->call('gotoPage', 2, 'accountHistoryAssessmentPage')->assertSee('HIST-ASSESSMENT-13')->assertSee('Earlier assessment')->assertSee('RECEIPT-KEPT-WHILE-PAGING');
        $this->assertSame(1, $history->instance()->accountingHistoryGroups()['proof']['records']->currentPage());
        $this->assertCount(3, $history->instance()->accountingHistoryGroups()['assessment']['records']);
        $history->call('gotoPage', 100, 'accountHistoryAssessmentPage')->assertSee('HIST-ASSESSMENT-13');
        $this->assertSame(2, $history->instance()->accountingHistoryGroups()['assessment']['records']->currentPage());
    }

    public function test_current_account_requires_available_enrollment_payment_authority(): void
    {
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('missing-enrollment-requirement@example.test');
        $fixture['assessment']->obligations()->update(['required_for_enrollment' => false]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($accounting)->test(ViewEnrollment::class, ['record' => $fixture['enrollment']->id]);

        $this->assertSame('Unavailable', $component->instance()->accountingViewData()['requirement']['state']);
        $this->assertSame('unavailable', $component->instance()->accountingViewData()['reviewState']);
        $component->assertSee('No active charges recorded yet')
            ->assertSee('this is not a zero or cleared balance')
            ->assertSee('Recorded charges')
            ->assertDontSee('No payment due now')
            ->assertDontSee('Enrollment payment satisfied')
            ->assertDontSee('Enrollment payment pending');
    }

    public function test_accounting_missing_source_view_distinguishes_waiting_ended_and_official_enrollments_without_financial_actions(): void
    {
        $accounting = $this->accounting();
        $program = Program::factory()->create();
        $term = Term::factory()->create();
        $createEnrollment = function (string $suffix, array $attributes = []) use ($program, $term): Enrollment {
            $learner = User::factory()->create(['email' => 'missing-source-'.$suffix.'@example.test']);
            $profile = StudentProfile::factory()->create([
                'user_id' => $learner->id,
                'program_id' => $program->id,
                'student_number' => 'SYN-MISSING-'.$suffix,
            ]);

            return Enrollment::factory()->create([
                'credential_user_id' => $learner->id,
                'student_profile_id' => $profile->id,
                'term_id' => $term->id,
                ...$attributes,
            ]);
        };
        $waiting = $createEnrollment('WAITING', ['case_reference' => 'REG-WAITING-SOURCE']);
        $ended = $createEnrollment('ENDED', [
            'case_reference' => 'REG-ENDED-SOURCE',
            'canonical_outcome' => Enrollment::OutcomeCancelledByRegistrar,
        ]);
        $official = $createEnrollment('OFFICIAL', [
            'case_reference' => 'REG-OFFICIAL-SOURCE',
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $waitingView = Livewire::actingAs($accounting)->test(ViewEnrollment::class, ['record' => $waiting->id])
            ->assertSee('Waiting for the Registrar')
            ->assertSee('subjects and reserved classes need a Registrar update')
            ->assertSee('Registrar')->assertSee('Prepare subjects')->assertSee('Accounting')->assertSee('Prepare charges')
            ->assertSee($program->name)->assertSee($term->label)->assertSee('REG-WAITING-SOURCE')
            ->assertSee('Enrollment details')->assertActionHidden('createAssessment')->assertActionHidden('recordIndividualAssessment');
        $this->assertSame('accounting-awaiting-source', $waitingView->instance()->focusedAccountingView());

        $endedView = Livewire::actingAs($accounting)->test(ViewEnrollment::class, ['record' => $ended->id])
            ->assertSee('Enrollment ended')->assertSee('Cancelled by Registrar')
            ->assertDontSee('Waiting for the Registrar')->assertDontSee('Prepare subjects')->assertDontSee('Prepare charges')
            ->assertActionHidden('createAssessment')->assertActionHidden('recordIndividualAssessment');
        $this->assertSame('accounting-awaiting-source', $endedView->instance()->focusedAccountingView());

        $officialView = Livewire::actingAs($accounting)->test(ViewEnrollment::class, ['record' => $official->id])
            ->assertSee('Account review needed')->assertSee('enrollment is official')
            ->assertDontSee('Waiting for the Registrar')->assertDontSee('Prepare subjects')->assertDontSee('Prepare charges')
            ->assertActionHidden('createAssessment')->assertActionHidden('recordIndividualAssessment');
        $this->assertSame('accounting-awaiting-source', $officialView->instance()->focusedAccountingView());
        $this->assertSame(0, TermAccount::query()->whereIn('enrollment_id', [$waiting->id, $ended->id, $official->id])->count());
        $this->assertSame(0, Assessment::query()->whereIn('enrollment_id', [$waiting->id, $ended->id, $official->id])->count());
        $this->assertSame(0, Payment::query()->whereIn('term_id', [$term->id])->count());
    }

    public function test_selected_workspace_retains_ended_and_unavailable_account_sources_without_assuming_a_balance(): void
    {
        $actor = $this->accounting();
        $fixture = $this->accountFixture('workspace-source-states@example.test');
        $case = $fixture['enrollment'];
        $case->update(['canonical_outcome' => Enrollment::OutcomeCancelledByRegistrar]);

        Livewire::actingAs($actor)->test(AccountingAccountInspector::class, ['record' => $case->id])
            ->assertSee('Registration ended')->assertSee('Review financial history')
            ->assertSee('Recorded charges')->assertDontSee('No payment due now');

        $case->update(['canonical_outcome' => Enrollment::OutcomeInProgress]);
        $fixture['assessment']->update(['state' => Assessment::StateSuperseded]);
        Livewire::actingAs($actor)->test(AccountingAccountInspector::class, ['record' => $case->id])
            ->assertSee('No active charges recorded yet')->assertSee('Recorded charges')
            ->assertSee('Current payment dates are unavailable')->assertDontSee('No payment due now');
        $this->assertSame(0, $fixture['account']->payments()->count());
    }

    public function test_coverage_form_excludes_committed_fees_from_new_coverage_but_preserves_same_fee_replacement(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00:00', 'Asia/Manila'));
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('coverage-replacement@example.test');
        $obligation = $fixture['assessment']->obligations()->firstOrFail();
        $previous = app(RecordApprovedCoverage::class)->execute($fixture['account'], $obligation, [
            ...$this->coverageData('ORIGINAL-COVERAGE'), 'amount' => '12000.00', 'effective_date' => '2026-11-01',
        ], $accounting);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($accounting)->test(ViewEnrollment::class, ['record' => $fixture['enrollment']->id])
            ->assertActionVisible('recordApprovedCoverage')->mountAction('recordApprovedCoverage')
            ->assertActionDataSet(['mode' => 'replace'])
            ->assertFormFieldExists('obligation_id', fn (Select $field): bool => $field->getOptions() === [])
            ->assertFormFieldExists('supersedes_coverage_id', fn (Select $field): bool => array_keys($field->getOptions()) === [$previous->id])
            ->setActionData(['supersedes_coverage_id' => $previous->id])
            ->assertMountedActionModalSee('The replacement remains on this fee.')
            ->setActionData([...$this->coverageData('REPLACEMENT-COVERAGE'), 'amount' => '12000.00'])
            ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Approved coverage recorded')
            ->assertRedirect(EnrollmentResource::getUrl('index', ['tab' => 'accounts', 'account' => $fixture['enrollment']->id, 'view' => 'work']));

        $replacement = ApprovedCoverage::query()->where('authority_reference', 'REPLACEMENT-COVERAGE')->sole();
        $this->assertSame($obligation->id, $replacement->assessment_obligation_id);
        $this->assertSame($previous->id, $replacement->supersedes_coverage_id);
        $this->assertSame(ApprovedCoverage::StateSuperseded, $previous->fresh()->state);
        $this->assertSame('0.00', app(TermAccountProjection::class)->forAccount($fixture['account'])['current_due']);
        $this->assertSame(2, $fixture['account']->coverages()->count());
    }

    public function test_coverage_form_records_new_coverage_and_recomputes_the_account_balance(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00:00', 'Asia/Manila'));
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('new-coverage@example.test');
        $obligation = $fixture['assessment']->obligations()->firstOrFail();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($accounting)->test(ViewEnrollment::class, ['record' => $fixture['enrollment']->id])
            ->mountAction('recordApprovedCoverage')->assertActionDataSet(['mode' => 'new'])
            ->assertFormFieldExists('obligation_id', fn (Select $field): bool => array_keys($field->getOptions()) === [$obligation->id])
            ->setActionData([...$this->coverageData('NEW-COVERAGE'), 'obligation_id' => $obligation->id])
            ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Approved coverage recorded');

        $coverage = $fixture['account']->coverages()->sole();
        $this->assertSame($obligation->id, $coverage->assessment_obligation_id);
        $this->assertNull($coverage->supersedes_coverage_id);
        $this->assertSame('1000.00', $coverage->amount);
        $this->assertSame('11000.00', app(TermAccountProjection::class)->forAccount($fixture['account'])['current_due']);
        Livewire::actingAs($accounting)->test(ViewEnrollment::class, ['record' => $fixture['enrollment']->id])
            ->assertSeeInOrder(['Due now', 'PHP 11,000.00'])->assertSee('PHP 1,000.00 approved coverage');
    }

    public function test_coverage_form_excludes_predecessors_from_superseded_assessments(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00:00', 'Asia/Manila'));
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('old-coverage@example.test');
        $oldObligation = $fixture['assessment']->obligations()->firstOrFail();
        $oldCoverage = app(RecordApprovedCoverage::class)->execute($fixture['account'], $oldObligation, $this->coverageData('OLD-ASSESSMENT-COVERAGE'), $accounting);
        $fixture['assessment']->update(['state' => Assessment::StateSuperseded]);
        $current = Assessment::factory()->create([
            'enrollment_id' => $fixture['enrollment']->id, 'term_account_id' => $fixture['account']->id,
            'version' => 2, 'state' => Assessment::StateActive, 'total' => '12000.00',
        ]);
        $currentObligation = AssessmentObligation::factory()->create([
            'assessment_id' => $current->id, 'amount' => '12000.00', 'due_at' => now()->subDay(), 'required_for_enrollment' => true,
        ]);
        AssessmentEffectRevalidation::query()->create([
            'term_account_id' => $fixture['account']->id, 'assessment_id' => $current->id, 'version' => 1,
            'obligation_mapping' => [['source_obligation_id' => $oldObligation->id, 'target_obligation_id' => $currentObligation->id, 'payment_amount' => '0.00', 'coverage_amount' => '1000.00']],
            'authority_reference' => 'RETAINED-COVERAGE-MAPPING', 'recorded_by' => $accounting->id, 'recorded_at' => now(),
        ]);
        $currentCoverage = app(RecordApprovedCoverage::class)->execute($fixture['account'], $currentObligation, $this->coverageData('CURRENT-ASSESSMENT-COVERAGE'), $accounting);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($accounting)->test(ViewEnrollment::class, ['record' => $fixture['enrollment']->id])
            ->mountAction('recordApprovedCoverage')->setActionData(['mode' => 'replace'])
            ->assertFormFieldExists('supersedes_coverage_id', fn (Select $field): bool => array_keys($field->getOptions()) === [$currentCoverage->id]);

        $this->assertSame(ApprovedCoverage::StateApplied, $oldCoverage->fresh()->state);
    }

    public function test_coverage_form_rejects_a_changed_current_assessment_without_writing_coverage(): void
    {
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('stale-coverage@example.test');
        $obligation = $fixture['assessment']->obligations()->firstOrFail();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($accounting)->test(ViewEnrollment::class, ['record' => $fixture['enrollment']->id])
            ->mountAction('recordApprovedCoverage')
            ->assertActionDataSet(['reviewed_assessment_id' => $fixture['assessment']->id])
            ->setActionData([...$this->coverageData('STALE-COVERAGE'), 'obligation_id' => $obligation->id]);
        $fixture['assessment']->update(['state' => Assessment::StateSuperseded]);
        $successor = Assessment::factory()->create([
            'enrollment_id' => $fixture['enrollment']->id, 'term_account_id' => $fixture['account']->id,
            'version' => 2, 'state' => Assessment::StateActive,
        ]);
        $successorObligation = AssessmentObligation::factory()->create([
            'assessment_id' => $successor->id, 'amount' => '1000.00', 'required_for_enrollment' => true,
        ]);

        $component->setActionData(['obligation_id' => $successorObligation->id])
            ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Coverage was not recorded');

        $this->assertSame(0, $fixture['account']->coverages()->count());
        $this->assertSame(Assessment::StateActive, $successor->fresh()->state);
    }

    /** @return array{category:string,safe_source_description:string,amount:string,authority_reference:string,authority_date:string,effective_date:string} */
    private function coverageData(string $reference): array
    {
        return [
            'category' => ApprovedCoverage::CategoryScholarship, 'safe_source_description' => 'Approved scholarship funding',
            'amount' => '1000.00', 'authority_reference' => $reference, 'authority_date' => '2026-10-01', 'effective_date' => '2026-10-18',
        ];
    }

    public function test_account_status_export_uses_the_fixed_private_contract_and_records_no_rows_without_a_file(): void
    {
        Storage::fake('local');
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('formula@example.test', '=FORMULA');
        $asOf = CarbonImmutable::parse('2026-08-25 18:00:00', 'Asia/Manila');

        $export = app(CreateContextualFinanceExport::class)->createAccountStatus(
            $accounting,
            'Reconcile the visible Student Accounts queue.',
            new Collection([$fixture['enrollment']]),
            [
                'active_tab' => 'accounts',
                'filters' => ['term' => ['value' => $fixture['term']->id]],
                'search' => '=FORMULA',
                'sort' => ['column' => 'updated_at', 'direction' => 'desc'],
            ],
            $asOf,
        );

        $this->assertSame(FinanceExport::TypeAccountStatus, $export->type);
        $this->assertSame(FinanceExport::OutcomeGenerated, $export->outcome);
        $this->assertSame('tala-account-status-20260825-180000-PHT.csv', $export->downloadFilename());
        Storage::disk('local')->assertExists($export->path);

        $contents = Storage::disk('local')->get($export->path);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contents);
        $this->assertStringContainsString("\r\n", $contents);
        $lines = explode("\r\n", substr($contents, 3));
        $this->assertSame([
            'Account reference',
            'Student / applicant number',
            'Student / applicant name',
            'Program',
            'Term',
            'Assessed charges (PHP)',
            'Verified payments applied (PHP)',
            'Approved coverage applied (PHP)',
            'Due now (PHP)',
            'Later fees (PHP)',
            'Remaining term fees (PHP)',
            'Account status',
            'Assessment version',
            'Assessment basis',
            'Approval reference',
            'As of (Asia/Manila)',
        ], str_getcsv($lines[0], ',', '"', '\\'));
        $this->assertStringContainsString("'=FORMULA", $contents);

        $this->actingAs($accounting)
            ->get(route('finance.exports.download', $export))
            ->assertOk()
            ->assertDownload($export->downloadFilename());
        $this->actingAs($fixture['learner'])
            ->get(route('finance.exports.download', $export))
            ->assertForbidden();

        $noRows = app(CreateContextualFinanceExport::class)->createAccountStatus(
            $accounting,
            'Confirm an empty filtered queue.',
            new Collection,
            ['active_tab' => 'accounts', 'filters' => ['state' => ['value' => 'missing']]],
            $asOf,
        );

        $this->assertSame(FinanceExport::OutcomeNoRows, $noRows->outcome);
        $this->assertSame(0, $noRows->row_count);
        $this->assertNull($noRows->path);
        $this->assertNull($noRows->checksum);
    }

    public function test_account_status_preview_and_csv_share_current_legal_identity_and_net_due_and_later_balances(): void
    {
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00:00', 'Asia/Manila'));
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('account-position@example.test', 'STU-POSITION');
        $fixture['enrollment']->studentProfile->update(['first_name' => 'Mira', 'middle_name' => null, 'last_name' => 'Santos']);
        $obligation = $fixture['assessment']->obligations()->firstOrFail();
        $obligation->update(['amount' => '3000.00']);
        $later = AssessmentObligation::factory()->create([
            'assessment_id' => $fixture['assessment']->id, 'sequence' => 2, 'amount' => '9000.00',
            'due_at' => now()->addMonth(), 'required_for_enrollment' => false,
        ]);
        $payment = Payment::factory()->create([
            'student_profile_id' => $fixture['enrollment']->student_profile_id,
            'term_account_id' => $fixture['account']->id, 'term_id' => $fixture['term']->id,
            'amount' => '1000.00', 'state' => Payment::StatePosted,
        ]);
        PaymentAllocation::factory()->create([
            'payment_id' => $payment->id, 'assessment_line_id' => null,
            'assessment_obligation_id' => $obligation->id, 'sequence' => 1, 'amount' => '1000.00',
        ]);
        app(RecordApprovedCoverage::class)->execute($fixture['account'], $later, [
            ...$this->coverageData('LATER-FEE-COVERAGE'), 'amount' => '2000.00',
        ], $accounting);
        $service = app(CreateContextualFinanceExport::class);
        $enrollments = new Collection([$fixture['enrollment']]);

        $preview = $service->accountStatusPreview($accounting, $enrollments);
        $export = $service->createAccountStatus($accounting, 'Caller text', $enrollments, []);

        $row = $this->accountStatusCsvRows($export)[0];
        $this->assertSame(array_map(fn (mixed $value): string => (string) $value, $preview['rows'][0]), $row);
        $this->assertSame('Mira Santos', $row['Student / applicant name']);
        $this->assertSame('STU-POSITION', $row['Student / applicant number']);
        $this->assertSame('1', $preview['rows'][0]['Assessment version']);
        $this->assertSame('12000.00', $row['Assessed charges (PHP)']);
        $this->assertSame('1000.00', $row['Verified payments applied (PHP)']);
        $this->assertSame('2000.00', $row['Approved coverage applied (PHP)']);
        $this->assertSame('2000.00', $row['Due now (PHP)']);
        $this->assertSame('7000.00', $row['Later fees (PHP)']);
        $this->assertSame('9000.00', $row['Remaining term fees (PHP)']);
        $this->assertSame('Payment due', $row['Account status']);
        $this->assertSame('Published Fee Plan', $row['Assessment basis']);
        $this->assertSame('2026-10-18T10:00:00+08:00', $row['As of (Asia/Manila)']);
        $this->assertSame(CreateContextualFinanceExport::AccountStatusPurpose, $export->purpose);

        $obligation->update(['due_at' => now()->addMonth(), 'required_for_enrollment' => false]);
        $updated = $service->createAccountStatus($accounting, 'Caller text', $enrollments, []);
        $row = $this->accountStatusCsvRows($updated)[0];
        $this->assertSame('No payment due now', $row['Account status']);
        $this->assertSame('0.00', $row['Due now (PHP)']);
        $this->assertSame('9000.00', $row['Later fees (PHP)']);
    }

    public function test_account_status_keeps_recorded_authority_but_blanks_unavailable_or_pending_change_figures(): void
    {
        Storage::fake('local');
        $accounting = $this->accounting();
        $unassessed = $this->accountFixture('unassessed-export@example.test');
        $unassessed['assessment']->update(['state' => Assessment::StateSuperseded]);
        $pending = $this->accountFixture('pending-change-export@example.test');
        $pending['enrollment']->update(['canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled]);
        $pending['assessment']->obligations()->update(['due_at' => now()->addMonth()]);
        $proposal = RegistrationProposalVersion::factory()->create([
            'enrollment_id' => $pending['enrollment']->id, 'purpose' => RegistrationProposalVersion::PurposeAdjustment,
            'state' => RegistrationProposalVersion::StateConfirmed,
        ]);
        RegistrationProposalConfirmation::factory()->create([
            'registration_proposal_version_id' => $proposal->id, 'learner_user_id' => $pending['learner']->id,
        ]);
        $pending['enrollment']->update(['current_proposal_version_id' => $proposal->id]);
        $stale = $this->accountFixture('stale-source-export@example.test');
        $staleProposal = RegistrationProposalVersion::factory()->create(['enrollment_id' => $stale['enrollment']->id]);
        $stale['enrollment']->update(['current_proposal_version_id' => $staleProposal->id]);

        $export = app(CreateContextualFinanceExport::class)->createAccountStatus($accounting, 'Review',
            new Collection([$pending['enrollment'], $unassessed['enrollment'], $stale['enrollment']]), []);

        $rows = $this->accountStatusCsvRows($export);
        $this->assertSame(['TERM-ACCOUNT-'.$pending['account']->id, 'TERM-ACCOUNT-'.$unassessed['account']->id, 'TERM-ACCOUNT-'.$stale['account']->id], array_column($rows, 'Account reference'));
        foreach ($rows as $row) {
            $this->assertSame('Needs review', $row['Account status']);
            foreach (['Verified payments applied (PHP)', 'Approved coverage applied (PHP)', 'Due now (PHP)', 'Later fees (PHP)', 'Remaining term fees (PHP)'] as $column) {
                $this->assertSame('', $row[$column]);
            }
        }
        $this->assertSame('12000.00', $rows[0]['Assessed charges (PHP)']);
        $this->assertSame('1', $rows[0]['Assessment version']);
        $this->assertSame('SYNTH-FEE-AUTHORITY', $rows[0]['Approval reference']);
        $this->assertSame('', $rows[1]['Assessed charges (PHP)']);
        $this->assertSame('', $rows[1]['Assessment version']);
    }

    public function test_account_status_uses_applicant_identity_or_credential_name_without_exporting_email_as_a_number(): void
    {
        Storage::fake('local');
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('private-identity@example.test');
        $application = AdmissionApplication::factory()->create([
            'user_id' => $fixture['learner']->id, 'program_id' => $fixture['enrollment']->studentProfile->program_id,
            'term_id' => $fixture['term']->id, 'application_reference' => 'APP-EXPORT-IDENTITY',
            'first_name' => '=Mira', 'middle_name' => null, 'last_name' => 'Santos', 'extension_name' => 'Jr.',
        ]);
        $fixture['enrollment']->update(['student_profile_id' => null, 'admission_application_id' => $application->id]);
        $enrollments = new Collection([$fixture['enrollment']]);
        $service = app(CreateContextualFinanceExport::class);

        $export = $service->createAccountStatus($accounting, 'Review', $enrollments, []);

        $row = $this->accountStatusCsvRows($export)[0];
        $this->assertSame('APP-EXPORT-IDENTITY', $row['Student / applicant number']);
        $this->assertSame("'=Mira Santos Jr.", $row['Student / applicant name']);
        $fixture['enrollment']->update(['admission_application_id' => null]);
        $export = $service->createAccountStatus($accounting, 'Review', $enrollments, []);
        $row = $this->accountStatusCsvRows($export)[0];
        $this->assertSame('', $row['Student / applicant number']);
        $this->assertSame($fixture['learner']->fresh()->name, $row['Student / applicant name']);
        $this->assertStringNotContainsString('private-identity@example.test', Storage::disk('local')->get($export->path));
    }

    public function test_account_status_marks_a_recorded_course_drop_for_review_until_its_assessment_exists(): void
    {
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('drop-export@example.test');
        $enrollment = $fixture['enrollment'];
        $proposal = RegistrationProposalVersion::factory()->for($enrollment)->recycle($fixture['term'])->create([
            'state' => RegistrationProposalVersion::StateConfirmed,
        ]);
        $course = CourseEnrollment::factory()->for($enrollment)->recycle([$proposal->curriculumVersion, $fixture['term'], $proposal->timetableVersion])->create([
            'is_current' => false, 'status' => CourseEnrollment::StatusDropped, 'dropped_at' => now(),
        ]);
        $drop = CourseDropRecord::factory()->for($enrollment)->for($course, 'courseEnrollment')->create();
        $remainingCourse = CourseEnrollment::factory()->for($enrollment)->recycle([$proposal->curriculumVersion, $fixture['term'], $proposal->timetableVersion])->create();
        $cor = CorVersion::factory()->for($enrollment)->for($proposal, 'proposalVersion')->for($proposal->timetableVersion, 'timetableVersion')->for($fixture['assessment'])->create([
            'snapshot' => ['program_id' => $enrollment->studentProfile->program_id,
                'courses' => [['course_enrollment_id' => $remainingCourse->id]],
                'change' => ['type' => 'CourseDrop', 'record_id' => $drop->id]],
        ]);
        $enrollment->update(['canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'current_proposal_version_id' => $proposal->id, 'current_cor_version_id' => $cor->id]);
        $service = app(CreateContextualFinanceExport::class);

        $pending = $service->accountStatusPreview($accounting, new Collection([$enrollment]))['rows'][0];

        $this->assertSame('Needs review', $pending['Account status']);
        $this->assertNull($pending['Due now (PHP)']);
        $this->assertSame('12000.00', $pending['Assessed charges (PHP)']);
        $fixture['assessment']->update(['source_snapshot' => ['course_drop' => ['id' => $drop->id]]]);
        $assessed = $service->accountStatusPreview($accounting, new Collection([$enrollment]))['rows'][0];
        $this->assertSame('Payment due', $assessed['Account status']);
        $this->assertSame('12000.00', $assessed['Due now (PHP)']);
    }

    /** @return list<array<string,string>> */
    private function accountStatusCsvRows(FinanceExport $export): array
    {
        $lines = explode("\r\n", substr(Storage::disk('local')->get($export->path), 3));
        $headers = str_getcsv(array_shift($lines), ',', '"', '\\');

        return array_map(fn (string $line): array => array_combine($headers, str_getcsv($line, ',', '"', '\\')), array_values(array_filter($lines)));
    }

    public function test_verified_payments_export_is_fixed_to_one_selected_account_and_current_filters(): void
    {
        Storage::fake('local');
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('learner@example.test', 'STU-EXPORT-001');
        $other = $this->accountFixture('other@example.test', 'STU-EXPORT-002');
        $postedAt = CarbonImmutable::parse('2026-08-25 18:30:00', 'Asia/Manila');
        $payment = Payment::factory()->create([
            'term_account_id' => $fixture['account']->id,
            'term_id' => $fixture['term']->id,
            'provider_reference' => 'PAYMENT-SYNTH-001',
            'external_check_reference' => 'BANK-EXTERNAL-12345678',
            'amount' => '3000.00',
            'channel' => 'bank_transfer',
            'state' => Payment::StatePosted,
            'evidence_status' => 'verified',
            'verification_basis' => 'IndependentSourceCheck',
            'verified_at' => $postedAt->utc(),
        ]);
        Payment::factory()->create([
            'term_account_id' => $other['account']->id,
            'term_id' => $other['term']->id,
            'provider_reference' => 'PAYMENT-OTHER-001',
            'amount' => '9000.00',
            'state' => Payment::StatePosted,
            'verified_at' => $postedAt->utc(),
        ]);

        $export = app(CreateContextualFinanceExport::class)->createVerifiedPayments(
            $accounting,
            $fixture['account'],
            'Verify postings for the selected account.',
            ['state' => Payment::StatePosted, 'from' => '2026-08-25', 'until' => '2026-08-25'],
            $postedAt,
        );

        $this->assertSame(FinanceExport::TypeVerifiedPayments, $export->type);
        $this->assertSame('tala-verified-payments-20260825-183000-PHT.csv', $export->downloadFilename());
        $contents = Storage::disk('local')->get($export->path);
        $lines = explode("\r\n", substr($contents, 3));
        $this->assertSame([
            'Payment reference',
            'Account reference',
            'Student / applicant number',
            'Student / applicant name',
            'Term',
            'Entry type',
            'Amount (PHP)',
            'Channel',
            'Masked external reference',
            'Payment or refund at (Asia/Manila)',
            'Verified at (Asia/Manila)',
            'Reverses payment reference',
            'Verification basis',
        ], str_getcsv($lines[0], ',', '"', '\\'));
        $this->assertStringContainsString($payment->provider_reference, $contents);
        $this->assertStringContainsString('••••5678', $contents);
        $this->assertStringNotContainsString('PAYMENT-OTHER-001', $contents);
        $this->assertStringContainsString('3000.00', $contents);
        $this->assertStringContainsString('2026-08-25T18:30:00+08:00', $contents);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($accounting)->test(ViewEnrollment::class, ['record' => $fixture['enrollment']->id])
            ->mountAction('exportTermAccount')
            ->assertMountedActionModalSee('Accounting verified-payment reconciliation')
            ->setActionData(['state' => Payment::StatePosted, 'from' => '2026-08-25', 'until' => '2026-08-25'])
            ->assertMountedActionModalSee('PAYMENT-SYNTH-001')
            ->assertMountedActionModalDontSee('PAYMENT-OTHER-001');
        $component->callMountedAction()->assertHasNoActionErrors();
        $generated = FinanceExport::query()->where('initiated_by', $accounting->id)->latest('id')->firstOrFail();
        $this->assertSame(CreateContextualFinanceExport::VerifiedPaymentsPurpose, $generated->purpose);
        $this->assertSame(Payment::StatePosted, $generated->normalized_scope['state']);
        $this->assertSame('2026-08-25', $generated->normalized_scope['from']);
        $this->assertSame('2026-08-25', $generated->normalized_scope['until']);
        $this->assertSame(['PAYMENT-SYNTH-001'], array_column($this->accountStatusCsvRows($generated), 'Payment reference'));
    }

    public function test_account_status_modal_exports_its_current_filtered_preview_without_a_purpose_input_or_preparation_cases(): void
    {
        Storage::fake('local');
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('modal-export@example.test', 'MATCH-MODAL-001');
        $other = $this->accountFixture('other-modal-export@example.test', 'OTHER-MODAL-002');
        $preparation = Enrollment::factory()->create([
            'credential_user_id' => User::factory()->create()->id, 'term_id' => $fixture['term']->id,
            'student_profile_id' => StudentProfile::factory()->create(['student_number' => 'MATCH-MODAL-PREP'])->id,
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($accounting)->test(ListEnrollments::class)
            ->set('activeTab', 'accounts')->searchTable('MATCH-MODAL')->mountAction('exportAccountStatus')
            ->assertMountedActionModalSee('Accounting account-position review')->assertMountedActionModalSee('MATCH-MODAL-001');
        $preview = $component->instance()->accountStatusExportData();
        $this->assertSame(1, $preview['count']);
        $this->assertSame(1, $preview['excluded']);
        $this->assertSame(['TERM-ACCOUNT-'.$fixture['account']->id], array_column($preview['rows'], 'Account reference'));

        $component->callMountedAction()->assertHasNoActionErrors();

        $export = FinanceExport::query()->where('initiated_by', $accounting->id)->latest('id')->firstOrFail();
        $this->assertSame(CreateContextualFinanceExport::AccountStatusPurpose, $export->purpose);
        $this->assertSame('MATCH-MODAL', $export->normalized_scope['search']);
        $this->assertSame(1, $export->normalized_scope['preparation_cases_excluded']);
        $this->assertSame(['MATCH-MODAL-001'], array_column($this->accountStatusCsvRows($export), 'Student / applicant number'));
    }

    public function test_verified_payments_preview_and_csv_resolve_reversal_sources_outside_dates_and_keep_distinct_times(): void
    {
        Storage::fake('local');
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('payment-identity@example.test', 'OLD-NUMBER');
        $application = AdmissionApplication::factory()->create([
            'user_id' => $fixture['learner']->id, 'application_reference' => '=APP-RECONCILE',
            'first_name' => 'Mira', 'middle_name' => null, 'last_name' => 'Santos', 'extension_name' => 'Jr.',
        ]);
        $fixture['enrollment']->update(['student_profile_id' => null, 'admission_application_id' => $application->id]);
        $original = Payment::factory()->create([
            'term_account_id' => $fixture['account']->id, 'term_id' => $fixture['term']->id,
            'provider_reference' => 'PAY-ORIGINAL', 'verified_at' => '2026-10-16 15:59:59',
        ]);
        $refund = Payment::factory()->create([
            'term_account_id' => $fixture['account']->id, 'term_id' => $fixture['term']->id,
            'provider_reference' => null, 'state' => Payment::StateReversal, 'amount' => '750.00',
            'reverses_payment_id' => $original->id, 'channel' => 'bank_transfer',
            'external_check_reference' => 'SECRET-BANK-12345678',
            'external_refund_at' => '2026-10-17 01:00:00', 'paid_at' => '2026-10-16 01:00:00',
            'verified_at' => '2026-10-17 16:00:00', 'verification_basis' => 'IndependentSourceCheck',
        ]);
        Payment::factory()->create([
            'term_account_id' => $fixture['account']->id, 'term_id' => $fixture['term']->id,
            'verified_at' => '2026-10-18 16:00:00', 'state' => Payment::StateReversal,
        ]);
        $filters = ['state' => Payment::StateReversal, 'from' => '2026-10-18', 'until' => '2026-10-18'];
        $service = app(CreateContextualFinanceExport::class);

        $preview = $service->verifiedPaymentsPreview($accounting, $fixture['account'], $filters);
        $this->assertSame(0, FinanceExport::query()->where('initiated_by', $accounting->id)->count());
        $export = $service->createVerifiedPayments($accounting, $fixture['account'], '', $filters);

        $this->assertSame(1, $preview['count']);
        $this->assertSame('=APP-RECONCILE', $preview['identity']['number']);
        $this->assertSame('Mira Santos Jr.', $preview['identity']['name']);
        $raw = $preview['rows'][0];
        $this->assertSame('PAYMENT-'.$refund->id, $raw['Payment reference']);
        $this->assertSame('750.00', $raw['Amount (PHP)']);
        $this->assertSame('Reversal', $raw['Entry type']);
        $this->assertSame('PAY-ORIGINAL', $raw['Reverses payment reference']);
        $this->assertSame('2026-10-17T09:00:00+08:00', $raw['Payment or refund at (Asia/Manila)']);
        $this->assertSame('2026-10-18T00:00:00+08:00', $raw['Verified at (Asia/Manila)']);
        $this->assertSame('Independent Source Check', $raw['Verification basis']);
        $this->assertSame('••••5678', $raw['Masked external reference']);
        $row = $this->accountStatusCsvRows($export)[0];
        $this->assertSame("'=APP-RECONCILE", $row['Student / applicant number']);
        $this->assertSame($raw['Verified at (Asia/Manila)'], $row['Verified at (Asia/Manila)']);
        $this->assertSame(CreateContextualFinanceExport::VerifiedPaymentsPurpose, $export->purpose);
        $this->assertStringNotContainsString('SECRET-BANK', Storage::disk('local')->get($export->path));
        $empty = $service->verifiedPaymentsPreview($accounting, $fixture['account'], ['from' => '2027-01-01']);
        $this->assertSame(0, $empty['count']);
        $this->assertSame($preview['identity'], $empty['identity']);
        $noRows = $service->createVerifiedPayments($accounting, $fixture['account'], '', ['from' => '2027-01-01']);
        $this->assertSame(FinanceExport::OutcomeNoRows, $noRows->outcome);
        $this->assertNull($noRows->path);
    }

    public function test_verified_payments_preview_samples_five_rows_and_rejects_invalid_filters_before_writing(): void
    {
        Storage::fake('local');
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('payment-sample@example.test');
        Payment::factory()->count(6)->create([
            'term_account_id' => $fixture['account']->id, 'term_id' => $fixture['term']->id,
            'verified_at' => '2026-10-18 00:00:00', 'state' => Payment::StatePosted,
        ]);
        $service = app(CreateContextualFinanceExport::class);
        $preview = $service->verifiedPaymentsPreview($accounting, $fixture['account'], []);
        $this->assertSame(6, $preview['count']);
        $this->assertCount(5, $preview['rows']);
        foreach ([['state' => 'Pending'], ['from' => '2026-02-30'], ['from' => '2026-10-18', 'until' => '2026-10-17']] as $filters) {
            try {
                $service->createVerifiedPayments($accounting, $fixture['account'], '', $filters);
                $this->fail('Invalid filter accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertSame(0, FinanceExport::query()->where('initiated_by', $accounting->id)->count());
        Storage::disk('local')->assertDirectoryEmpty('finance-exports');
    }

    public function test_account_status_rejects_more_than_ten_thousand_rows_before_creating_an_export(): void
    {
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('bounded-export@example.test');
        try {
            app(CreateContextualFinanceExport::class)->createAccountStatus($accounting, '', new Collection(array_fill(0, 10001, $fixture['enrollment'])), []);
            $this->fail('An over-limit export was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame('A contextual export requires a purpose and no more than 10,000 rows.', $exception->errors()['export'][0]);
        }
        $this->assertSame(0, FinanceExport::query()->where('initiated_by', $accounting->id)->count());
    }

    public function test_account_status_export_failure_keeps_the_search_and_allows_a_fresh_retry(): void
    {
        Storage::fake('local');
        $accounting = $this->accounting();
        $this->accountFixture('retry-status@example.test', 'RETRY-STATUS-001');
        $this->failFirstExportWrite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($accounting)->test(ListEnrollments::class)
            ->set('activeTab', 'accounts')->searchTable('RETRY-STATUS')->mountAction('exportAccountStatus')
            ->callMountedAction()->assertActionMounted('exportAccountStatus')
            ->assertSet('financeExportFailed', true)->assertSet('tableSearch', 'RETRY-STATUS')
            ->assertMountedActionModalSee('CSV could not be created');

        $failed = FinanceExport::query()->where('initiated_by', $accounting->id)->firstOrFail();
        $this->assertSame(FinanceExport::OutcomeFailed, $failed->outcome);
        $this->assertNull($failed->path);

        $component->callMountedAction()->assertHasNoActionErrors()->assertActionNotMounted('exportAccountStatus');
        $retry = FinanceExport::query()->where('initiated_by', $accounting->id)->latest('id')->firstOrFail();
        $this->assertNotSame($failed->id, $retry->id);
        $this->assertSame(FinanceExport::OutcomeGenerated, $retry->outcome);
        $this->assertSame('RETRY-STATUS', $retry->normalized_scope['search']);
        Storage::disk('local')->assertExists($retry->path);
    }

    public function test_verified_payment_export_failure_keeps_the_filters_and_allows_a_fresh_retry(): void
    {
        Storage::fake('local');
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('retry-payments@example.test');
        Payment::factory()->create([
            'term_account_id' => $fixture['account']->id, 'term_id' => $fixture['term']->id,
            'state' => Payment::StatePosted, 'verified_at' => CarbonImmutable::parse('2026-08-25 12:00:00'),
        ]);
        $this->failFirstExportWrite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $filters = ['state' => Payment::StatePosted, 'from' => '2026-08-01', 'until' => '2026-08-31'];

        $component = Livewire::actingAs($accounting)->test(ViewEnrollment::class, ['record' => $fixture['enrollment']->id])
            ->mountAction('exportTermAccount')->setActionData($filters)
            ->callMountedAction()->assertActionMounted('exportTermAccount')->assertActionDataSet($filters)
            ->assertSet('financeExportFailed', true)->assertMountedActionModalSee('CSV could not be created');

        $failed = FinanceExport::query()->where('initiated_by', $accounting->id)->firstOrFail();
        $this->assertSame(FinanceExport::OutcomeFailed, $failed->outcome);
        $this->assertNull($failed->path);

        $component->callMountedAction()->assertHasNoActionErrors()->assertActionNotMounted('exportTermAccount');
        $retry = FinanceExport::query()->where('initiated_by', $accounting->id)->latest('id')->firstOrFail();
        $this->assertSame(FinanceExport::OutcomeGenerated, $retry->outcome);
        $this->assertEquals($filters, $retry->normalized_scope);
        $this->assertSame($fixture['account']->id, $retry->term_account_id);
        Storage::disk('local')->assertExists($retry->path);
    }

    private function failFirstExportWrite(): void
    {
        $realDisk = Storage::disk('local');
        $disk = \Mockery::mock($realDisk)->makePartial();
        $disk->shouldReceive('put')->once()->ordered()->andReturnFalse();
        $disk->shouldReceive('put')->once()->ordered()->andReturnUsing(fn (string $path, string $contents): bool => $realDisk->put($path, $contents));
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
    }

    public function test_export_storage_failure_records_failed_attempt_and_removes_the_partial_private_file(): void
    {
        Storage::fake('local');
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('failed-export@example.test');
        $realDisk = Storage::disk('local');
        $disk = \Mockery::mock($realDisk)->makePartial();
        $disk->shouldReceive('put')->once()->andReturnUsing(function (string $path, string $contents) use ($realDisk): bool {
            $realDisk->put($path, substr($contents, 0, 32));

            return false;
        });
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        try {
            app(CreateContextualFinanceExport::class)->createAccountStatus($accounting, '', new Collection([$fixture['enrollment']]), ['search' => 'failed-export']);
            $this->fail('A partial file was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame('The export could not be stored.', $exception->errors()['export'][0]);
        }
        $failed = FinanceExport::query()->where('initiated_by', $accounting->id)->firstOrFail();
        $this->assertSame(FinanceExport::OutcomeFailed, $failed->outcome);
        $this->assertSame('failed-export', $failed->normalized_scope['search']);
        $this->assertNull($failed->path);
        $this->assertNull($failed->checksum);
        $this->assertFalse($disk->exists('finance-exports/'.$failed->reference.'.csv'));
    }

    /** @return array{learner:User,enrollment:Enrollment,account:TermAccount,assessment:Assessment,term:Term} */
    private function accountFixture(string $email, ?string $studentNumber = null): array
    {
        $learner = User::factory()->create([
            'email' => $email,
            'status' => User::StatusActive,
        ]);
        $program = Program::factory()->create();
        $profile = StudentProfile::factory()->create(array_filter([
            'user_id' => $learner->id,
            'program_id' => $program->id,
            'student_number' => $studentNumber,
        ], fn (mixed $value): bool => $value !== null));
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'credential_user_id' => $learner->id,
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
        ]);
        $account = TermAccount::factory()->create([
            'enrollment_id' => $enrollment->id,
            'credential_user_id' => $learner->id,
            'term_id' => $term->id,
        ]);
        $assessment = Assessment::factory()->create([
            'enrollment_id' => $enrollment->id,
            'term_account_id' => $account->id,
            'assessment_basis' => 'PublishedFeePlan',
            'authority_reference' => 'SYNTH-FEE-AUTHORITY',
            'total' => '12000.00',
            'subtotal' => '12000.00',
            'state' => Assessment::StateActive,
        ]);
        AssessmentObligation::factory()->create([
            'assessment_id' => $assessment->id,
            'sequence' => 1,
            'code' => 'ENROLLMENT',
            'label' => 'Enrollment obligation',
            'purpose' => 'Enrollment',
            'amount' => '12000.00',
            'due_at' => '2026-08-25 17:00:00',
            'required_for_enrollment' => true,
        ]);

        return compact('learner', 'enrollment', 'account', 'assessment', 'term');
    }

    private function accounting(): User
    {
        $accounting = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $accounting->assignRole(User::StaffRoleAccounting);

        return $accounting;
    }
}
