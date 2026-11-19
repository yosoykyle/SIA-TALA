<?php

namespace Tests\Feature;

use App\Actions\StudentLifecycle\LifecycleAccountingReview;
use App\Actions\StudentLifecycle\LifecycleFinanceAction;
use App\Actions\StudentLifecycle\RecordLifecycleAccountingReview;
use App\Filament\Pages\PaymentChannels;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Enrollments\Pages\AccountingAccountInspector;
use App\Filament\Resources\Enrollments\Pages\ListEnrollments;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Filament\Resources\Enrollments\Tables\StudentAccountsTable;
use App\Filament\Resources\FeePlans\FeePlanResource;
use App\Filament\Resources\FeePlans\Pages\ListFeePlans;
use App\Filament\Resources\FeePlans\Pages\ViewFeePlan;
use App\Filament\Resources\StudentLifecycleChanges\Pages\ListStudentLifecycleChanges;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\FeePlan;
use App\Models\PaymentEvidenceVersion;
use App\Models\Program;
use App\Models\StudentLifecycleChange;
use App\Models\Term;
use App\Models\TermAccount;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountingWorkspaceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_selection_and_search_keep_the_inspector_inside_the_visible_collection(): void
    {
        $actor = $this->accounting();
        $first = Enrollment::factory()->create(['case_reference' => 'SYN-WORKSPACE-FIRST']);
        $second = Enrollment::factory()->create(['case_reference' => 'SYN-WORKSPACE-SECOND']);

        $page = Livewire::actingAs($actor)->withQueryParams(['tab' => 'accounts', 'account' => $first->id, 'search' => 'SYN-WORKSPACE-'])
            ->test(ListEnrollments::class)
            ->assertSeeLivewire(AccountingAccountInspector::class)
            ->assertDontSee('Student account section')
            ->call('selectAccountingRecord', $second->id)
            ->assertSet('selectedAccountingId', $second->id);
        $this->assertSame($second->id, $page->instance()->selectedAccountingRecord()->id);

        $page->searchTable('SYN-WORKSPACE-FIRST')->assertSet('selectedAccountingId', null);
        $this->assertSame($first->id, $page->instance()->selectedAccountingRecord()->id);
        $page->searchTable('SYN-WORKSPACE-NO-MATCH')->assertSee('No matching accounts');
        $this->assertNull($page->instance()->selectedAccountingRecord());
        $page->call('clearAccountingFilters')->assertSet('tableSearch', '')->assertSet('selectedAccountingId', null);
    }

    public function test_account_sections_change_inside_the_selected_workspace_and_reject_unknown_sections(): void
    {
        $actor = $this->accounting();
        $case = Enrollment::factory()->create();
        TermAccount::factory()->create(['enrollment_id' => $case->id]);

        Livewire::actingAs($actor)->withQueryParams(['tab' => 'accounts', 'account' => $case->id])
            ->test(ListEnrollments::class)
            ->assertSee('Current work')->assertSee('History')
            ->call('showAccountingSection', 'history')->assertSet('accountingSection', 'history')
            ->assertSet('selectedAccountingId', $case->id)->assertSeeLivewire(AccountingAccountInspector::class)
            ->call('showAccountingSection', 'work')->assertSet('accountingSection', 'work')
            ->call('showAccountingSection', 'unsupported')->assertNotFound();
    }

    public function test_selected_account_on_a_later_page_is_reachable_and_cannot_be_selected_from_another_page(): void
    {
        $actor = $this->accounting();
        $oldest = Enrollment::factory()->create(['case_reference' => 'SYN-PAGED-WORKSPACE-OLDEST', 'updated_at' => now()->subDay()]);
        foreach (range(1, 10) as $index) {
            Enrollment::factory()->create(['case_reference' => 'SYN-PAGED-WORKSPACE-'.$index]);
        }

        $page = Livewire::actingAs($actor)->withQueryParams(['tab' => 'accounts', 'account' => $oldest->id, 'search' => 'SYN-PAGED-WORKSPACE-'])
            ->test(ListEnrollments::class);
        $this->assertSame(3, $page->instance()->getPage());
        $this->assertSame($oldest->id, $page->instance()->selectedAccountingRecord()->id);

        $page->call('gotoPage', 1)->assertSet('selectedAccountingId', null);
        $this->assertNotSame($oldest->id, $page->instance()->selectedAccountingRecord()->id);
        $page->call('selectAccountingRecord', $oldest->id)->assertNotFound();
    }

    public function test_nested_review_reuses_the_guarded_dialog_and_cancels_without_recording_a_result(): void
    {
        $actor = $this->accounting();
        $case = Enrollment::factory()->create();
        $account = TermAccount::factory()->create(['enrollment_id' => $case->id]);
        $change = StudentLifecycleChange::factory()->create([
            'enrollment_id' => $case->id, 'student_profile_id' => $case->student_profile_id,
            'term_id' => $case->term_id, 'state' => StudentLifecycleChange::StateApplied,
        ]);
        app(LifecycleFinanceAction::class)->execute($change, $actor);
        $review = app(LifecycleAccountingReview::class)->forEnrollment($case)->firstOrFail();

        Livewire::actingAs($actor)->test(AccountingAccountInspector::class, ['record' => $case->id])
            ->assertSee('Review student status changes')->assertSee('Charges and payment dates')
            ->mountAction('recordLifecycleReview', ['review' => $review->id])
            ->assertSchemaStateSet(['outcome' => null], 'mountedActionSchema0')
            ->unmountAction()->assertHasNoActionErrors();
        $this->assertSame(0, $review->lifecycleReviewResults()->count());
        $this->assertSame(TermAccount::StateOpen, $account->fresh()->state);
    }

    public function test_source_breadcrumbs_are_available_before_the_table_boots_and_return_to_the_account_workspace(): void
    {
        $actor = $this->accounting();
        $case = Enrollment::factory()->create();
        $change = StudentLifecycleChange::factory()->create([
            'enrollment_id' => $case->id, 'student_profile_id' => $case->student_profile_id,
            'term_id' => $case->term_id, 'state' => StudentLifecycleChange::StateApplied,
        ]);
        $this->actingAs($actor);
        $source = new ListStudentLifecycleChanges;
        $source->accountingEnrollmentId = $case->id;
        $source->selectedChangeId = $change->id;

        $this->assertSame(EnrollmentResource::getUrl('index', ['tab' => 'student_changes', 'account' => $case->id, 'view' => 'changes']), $source->accountingReturnUrl());
        $this->assertContains($case->credentialUser->getFilamentName(), $source->getBreadcrumbs());
        $this->assertContains('Original student change', $source->getBreadcrumbs());
        $source->selectedChangeId = StudentLifecycleChange::factory()->create()->id;
        $this->assertNull($source->accountingReturnUrl());
    }

    public function test_the_embedded_account_inspector_is_restricted_to_accounting(): void
    {
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $actor = User::factory()->create(['status' => User::StatusActive]);
        $actor->assignRole(User::StaffRoleRegistrar);
        $case = Enrollment::factory()->create();

        Livewire::actingAs($actor)->test(AccountingAccountInspector::class, ['record' => $case->id])->assertForbidden();
    }

    public function test_history_keeps_the_selected_proof_and_ignores_a_foreign_url_source(): void
    {
        $actor = $this->accounting();
        $case = Enrollment::factory()->create();
        $account = TermAccount::factory()->create(['enrollment_id' => $case->id]);
        $proof = PaymentEvidenceVersion::factory()->create(['term_account_id' => $account->id]);
        $foreign = PaymentEvidenceVersion::factory()->create(['original_name' => 'FOREIGN-PRIVATE-RECEIPT.png']);

        $page = Livewire::actingAs($actor)->withQueryParams(['proof' => $foreign->id])
            ->test(AccountingAccountInspector::class, [
                'record' => $case->id, 'returnQueue' => 'payment_proof',
                'enrollmentView' => 'history', 'paymentProofId' => $proof->id,
            ])->assertSee('Account history')->assertSee('Receipts submitted')->assertSee('No verified payments recorded.')
            ->assertDontSee('Export verified payments')->assertDontSee('FOREIGN-PRIVATE-RECEIPT.png');
        $this->assertSame($proof->id, $page->instance()->paymentProofId);
        $this->assertSame(EnrollmentResource::getUrl('index', [
            'tab' => 'payment_proof', 'account' => $case->id, 'proof' => $proof->id, 'view' => 'work',
        ]), $page->instance()->enrollmentUrl(['view' => 'work']));
    }

    public function test_an_empty_account_history_omits_absent_categories_and_export_controls(): void
    {
        $actor = $this->accounting();
        $case = Enrollment::factory()->create();
        TermAccount::factory()->create(['enrollment_id' => $case->id]);

        $page = Livewire::actingAs($actor)->test(AccountingAccountInspector::class, [
            'record' => $case->id, 'enrollmentView' => 'history',
        ])->assertSee('No account history yet')->assertDontSee('Receipts submitted')
            ->assertDontSee('Assessed charges')->assertDontSee('Export verified payments')->assertDontSee('Corrections');

        $this->assertSame([], $page->instance()->accountingHistoryGroups());
    }

    public function test_unscoped_accounts_tab_gates_multi_term_rows_until_scoped_and_preserves_deep_link_bypasses(): void
    {
        $actor = $this->accounting();
        $olderTerm = Term::factory()->create(['state' => Term::StateClosed, 'label' => 'SYN Gate Older Term', 'starts_on' => now()->subYear()]);
        $activeTerm = Term::factory()->create(['state' => Term::StateActive, 'label' => 'SYN Gate Active Term', 'starts_on' => now()]);
        $olderCase = Enrollment::factory()->create(['term_id' => $olderTerm->id, 'case_reference' => 'SYN-GATE-OLDER']);
        $activeCase = Enrollment::factory()->create(['term_id' => $activeTerm->id, 'case_reference' => 'SYN-GATE-ACTIVE']);
        $proofAccount = TermAccount::factory()->create(['enrollment_id' => $olderCase->id, 'credential_user_id' => $olderCase->credential_user_id, 'term_id' => $olderTerm->id]);
        PaymentEvidenceVersion::factory()->create(['term_account_id' => $proofAccount->id, 'state' => PaymentEvidenceVersion::StateSubmitted]);
        $change = StudentLifecycleChange::factory()->create([
            'enrollment_id' => $olderCase->id,
            'student_profile_id' => $olderCase->student_profile_id,
            'term_id' => $olderTerm->id,
            'state' => StudentLifecycleChange::StateApplied,
        ]);
        app(LifecycleFinanceAction::class)->execute($change, $actor);

        $gated = Livewire::actingAs($actor)->withQueryParams(['tab' => 'accounts'])
            ->test(ListEnrollments::class)
            ->assertSet('activeTab', 'accounts')
            ->assertSee('Choose a term or student')
            ->assertSee('tala-gate-attention', false)
            ->assertSeeInOrder(['Review receipts', '1', 'Student status changes', '1'])
            ->assertActionHidden('accountingAllTerms')
            ->assertActionHidden('accountingChangeTerm')
            ->assertActionDoesNotExist('accountingClearFilters')
            ->assertActionHidden('exportAccountStatus')
            ->assertCanNotSeeTableRecords([$olderCase, $activeCase]);
        $this->assertTrue($gated->instance()->accountingTermGateActive());

        $gated->filterTable('term', $activeTerm->id)
            ->assertDontSee('Choose a term or student')
            ->assertSee('Filters')
            ->assertSee('fi-ta-filters-dropdown', false)
            ->assertSee('SYN Gate Active Term')
            ->assertSee('pending outside this view.')
            ->assertActionVisible('accountingAllTerms')
            ->assertActionVisible('accountingChangeTerm')
            ->assertCanSeeTableRecords([$activeCase])
            ->assertCanNotSeeTableRecords([$olderCase]);

        $gated->callAction('accountingAllTerms')
            ->assertSet('accountingScope', 'all')
            ->assertDontSeeHtml('aria-label="Account term and program scope"')
            ->assertActionHidden('accountingAllTerms')
            ->assertDontSee('Choose a term or student')
            ->assertCanSeeTableRecords([$olderCase, $activeCase]);

        $gated->callAction('accountingChangeTerm')
            ->assertSee('Choose a term or student')
            ->assertCanNotSeeTableRecords([$olderCase, $activeCase]);
        $this->assertTrue($gated->instance()->accountingTermGateActive());

        Livewire::actingAs($actor)->withQueryParams(['tab' => 'accounts', 'account' => $olderCase->id])
            ->test(ListEnrollments::class)
            ->assertDontSee('Choose a term or student')
            ->assertSet('selectedAccountingId', $olderCase->id)
            ->assertSeeLivewire(AccountingAccountInspector::class);

        Livewire::actingAs($actor)->withQueryParams(['tab' => 'accounts', 'search' => 'SYN-GATE-OLDER'])
            ->test(ListEnrollments::class)
            ->assertDontSee('Choose a term or student')
            ->assertCanSeeTableRecords([$olderCase])
            ->assertCanNotSeeTableRecords([$activeCase]);

        Livewire::actingAs($actor)->withQueryParams(['tab' => 'student_changes', 'account' => $olderCase->id])
            ->test(ListEnrollments::class)
            ->assertDontSee('Choose a term or student')
            ->assertSet('selectedAccountingId', $olderCase->id)
            ->assertSeeLivewire(AccountingAccountInspector::class);

        Livewire::actingAs($actor)->withQueryParams(['tab' => 'accounts', 'filters' => ['term' => ['value' => (string) $olderTerm->id]]])
            ->test(ListEnrollments::class)
            ->assertDontSee('Choose a term or student')
            ->assertCanSeeTableRecords([$olderCase])
            ->assertCanNotSeeTableRecords([$activeCase]);
    }

    public function test_null_account_reviewed_student_change_keeps_history_reachable_and_withholds_correction(): void
    {
        $actor = $this->accounting();
        $case = Enrollment::factory()->create();
        $account = TermAccount::factory()->create(['enrollment_id' => $case->id, 'credential_user_id' => $case->credential_user_id, 'term_id' => $case->term_id]);
        $change = StudentLifecycleChange::factory()->create([
            'enrollment_id' => $case->id,
            'student_profile_id' => $case->student_profile_id,
            'term_id' => $case->term_id,
            'state' => StudentLifecycleChange::StateApplied,
        ]);
        $root = app(LifecycleFinanceAction::class)->execute($change, $actor);
        app(RecordLifecycleAccountingReview::class)->execute(
            $case,
            $actor,
            app(LifecycleAccountingReview::class)->snapshot($case, $root->id),
            ['outcome' => LifecycleAccountingReview::NoAccountChange, 'reason' => 'Reviewed prior to missing-account recovery.'],
        );

        Livewire::actingAs($actor)->test(AccountingAccountInspector::class, [
            'record' => $case->id,
            'enrollmentView' => 'history',
        ])->assertSee('Reviewed prior to missing-account recovery.')
            ->assertSee('Correct review result');

        $account->delete();

        Livewire::actingAs($actor)->withQueryParams(['tab' => 'accounts', 'account' => $case->id])
            ->test(ListEnrollments::class)
            ->assertSee('History')
            ->call('showAccountingSection', 'history')
            ->assertSet('accountingSection', 'history')
            ->assertSee('No charges or payments recorded yet; reviewed student changes appear below.')
            ->assertSee('Reviewed prior to missing-account recovery.')
            ->assertDontSee('Correct review result');

        $detail = Livewire::actingAs($actor)->withQueryParams(['view' => 'history'])
            ->test(ViewEnrollment::class, ['record' => $case->id])
            ->assertSee('Account history')
            ->assertSee('No charges or payments recorded yet; reviewed student changes appear below.')
            ->assertSee('Reviewed prior to missing-account recovery.')
            ->assertDontSee('Correct review result');
        $this->assertSame('accounting-history', $detail->instance()->focusedAccountingView());
    }

    public function test_student_changes_row_action_uses_review_changes_label(): void
    {
        $case = Enrollment::factory()->create();

        $this->assertSame('Review changes', StudentAccountsTable::rowData($case, 'student_changes')['action']);
        $this->assertSame('Open account', StudentAccountsTable::rowData($case, 'accounts')['action']);
    }

    public function test_accounting_overview_separates_all_terms_published_fee_plans_from_active_term_scope(): void
    {
        $actor = $this->accounting();
        $closedTerm = Term::factory()->create(['state' => Term::StateClosed, 'label' => 'SYN Overview Closed Term', 'starts_on' => now()->subYear()]);
        $activeTerm = Term::factory()->create(['state' => Term::StateActive, 'label' => 'SYN Overview Active Term', 'starts_on' => now()]);
        FeePlan::factory()->create(['term_id' => $activeTerm->id, 'state' => FeePlan::StatePublished, 'created_by' => $actor->id]);
        FeePlan::factory()->create(['term_id' => $closedTerm->id, 'state' => FeePlan::StatePublished, 'created_by' => $actor->id]);
        FeePlan::factory()->create(['term_id' => $closedTerm->id, 'state' => FeePlan::StateDraft, 'created_by' => $actor->id]);

        $case = Enrollment::factory()->create(['term_id' => $closedTerm->id]);
        $account = TermAccount::factory()->create([
            'enrollment_id' => $case->id,
            'credential_user_id' => $case->credential_user_id,
            'term_id' => $closedTerm->id,
        ]);
        $assessment = Assessment::factory()->create([
            'enrollment_id' => $case->id,
            'term_account_id' => $account->id,
            'activated_by' => $actor->id,
        ]);
        PaymentEvidenceVersion::factory()->create([
            'term_account_id' => $account->id,
            'assessment_id' => $assessment->id,
            'submitted_by' => $case->credential_user_id,
        ]);

        $page = Livewire::actingAs($actor)->test(ListEnrollments::class)
            ->assertSet('activeTab', 'overview')
            ->assertSee('1 account needs review')
            ->assertSee('Across all active and prior terms')
            ->assertDontSee('Active term:')
            ->assertSee('Active terms: SYN Overview Active Term')
            ->assertDontSee('Open any step directly')
            ->assertSeeInOrder(['Fee plans', '2', 'published'])
            ->assertSee('All terms · 1 draft')
            ->assertSee('SYN Overview Active Term: 1 published')
            ->assertSee('Review receipts')
            ->assertSeeInOrder(['Submitted receipts', '1', 'account']);

        $summary = $page->instance()->accountingOverviewSummary();
        $this->assertSame(2, $summary['feePlanAllPublishedCount']);
        $this->assertSame(1, $summary['feePlanPublishedCount']);
        $this->assertSame(1, $summary['feePlanDraftCount']);
    }

    public function test_accounting_overview_deduplicates_overlapping_queues_in_unique_review_count(): void
    {
        $actor = $this->accounting();
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $enrollment = Enrollment::factory()->create([
            'term_id' => $term->id,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
        ]);
        $account = TermAccount::factory()->create([
            'enrollment_id' => $enrollment->id,
            'credential_user_id' => $enrollment->credential_user_id,
            'term_id' => $term->id,
        ]);
        $assessment = Assessment::factory()->create([
            'enrollment_id' => $enrollment->id,
            'term_account_id' => $account->id,
            'activated_by' => $actor->id,
        ]);
        PaymentEvidenceVersion::factory()->create([
            'term_account_id' => $account->id,
            'assessment_id' => $assessment->id,
            'submitted_by' => $enrollment->credential_user_id,
        ]);
        $change = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $enrollment->student_profile_id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'state' => StudentLifecycleChange::StateApplied,
        ]);
        app(LifecycleFinanceAction::class)->execute($change, $actor);

        $page = Livewire::actingAs($actor)->test(ListEnrollments::class)
            ->assertSet('activeTab', 'overview')
            ->assertSee('1 account needs review');

        $counts = $page->instance()->accountingWorkCounts();
        $this->assertSame(1, $counts['proof']);
        $this->assertSame(1, $counts['student_changes']);
        $this->assertSame(1, $page->instance()->accountingUniqueReviewCount());
        $this->assertSame(1, $page->instance()->accountingOverviewSummary()['uniqueReviewCount']);
    }

    public function test_accounting_tab_normalization_and_bounded_query_count_protect_overview_and_gated_accounts(): void
    {
        $actor = $this->accounting();
        $closedTerm = Term::factory()->create(['state' => Term::StateClosed, 'starts_on' => now()->subYear()]);
        $activeTerm = Term::factory()->create(['state' => Term::StateActive, 'starts_on' => now()]);
        $unassessedCase = Enrollment::factory()->create([
            'term_id' => $activeTerm->id,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
        ]);
        $assessedCase = Enrollment::factory()->create([
            'term_id' => $closedTerm->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
        ]);
        $account = TermAccount::factory()->create([
            'enrollment_id' => $assessedCase->id,
            'credential_user_id' => $assessedCase->credential_user_id,
            'term_id' => $closedTerm->id,
        ]);
        Assessment::factory()->create([
            'enrollment_id' => $assessedCase->id,
            'term_account_id' => $account->id,
            'activated_by' => $actor->id,
        ]);

        Livewire::actingAs($actor)->withQueryParams(['tab' => 'assessment_reviews'])
            ->test(ListEnrollments::class)
            ->assertSet('activeTab', 'assessment_required')
            ->assertCanSeeTableRecords([$unassessedCase])
            ->assertCanNotSeeTableRecords([$assessedCase]);

        Livewire::actingAs($actor)->withQueryParams(['tab' => 'unsupported_tab_value'])
            ->test(ListEnrollments::class)
            ->assertSet('activeTab', 'overview')
            ->assertSee('Across all active and prior terms')
            ->assertCanNotSeeTableRecords([$unassessedCase, $assessedCase]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($actor)->test(ListEnrollments::class)->assertSet('activeTab', 'overview');
        $overviewQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        Livewire::actingAs($actor)->withQueryParams(['tab' => 'accounts'])
            ->test(ListEnrollments::class)
            ->assertSet('activeTab', 'accounts')
            ->assertSee('Choose a term or student');
        $gatedAccountsQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(30, $overviewQueries);
        $this->assertLessThanOrEqual(30, $gatedAccountsQueries);
    }

    public function test_accounting_refactors_keep_static_scope_quiet_clarify_tor_and_align_surfaces(): void
    {
        $actor = $this->accounting();
        $term = Term::factory()->create(['state' => Term::StateActive, 'label' => 'SYN Refactor Term', 'starts_on' => now()]);
        $case = Enrollment::factory()->create([
            'term_id' => $term->id,
            'case_reference' => 'SYN-REFACTOR-CASE',
            'canonical_outcome' => Enrollment::OutcomeCancelledByRegistrar,
        ]);
        $account = TermAccount::factory()->create([
            'enrollment_id' => $case->id,
            'credential_user_id' => $case->credential_user_id,
            'term_id' => $term->id,
        ]);
        Assessment::factory()->create([
            'enrollment_id' => $case->id,
            'term_account_id' => $account->id,
            'assessment_basis' => 'PublishedFeePlan',
            'state' => Assessment::StateActive,
            'activated_by' => $actor->id,
        ]);

        $awaitingCase = Enrollment::factory()->create([
            'term_id' => $term->id,
            'case_reference' => 'SYN-AWAITING-CASE',
            'canonical_outcome' => Enrollment::OutcomeCancelledByRegistrar,
        ]);

        Livewire::actingAs($actor)->test(ListEnrollments::class)
            ->assertSee('Transcript of Records (TOR)');

        Livewire::actingAs($actor)->withQueryParams([
            'tab' => 'accounts',
            'account' => $case->id,
            'filters' => ['term' => ['value' => (string) $term->id]],
        ])->test(ListEnrollments::class)
            ->assertSeeHtml('aria-label="Account term and program scope"')
            ->assertDontSeeHtml('<div class="tala-record-scope-bar__summary" role="status">');

        Livewire::actingAs($actor)->test(AccountingAccountInspector::class, ['record' => $case->id])
            ->assertSee('Standard fee plan');

        Livewire::actingAs($actor)->test(AccountingAccountInspector::class, ['record' => $awaitingCase->id])
            ->assertSeeHtml('<section class="accounting-awaiting-summary" aria-labelledby="accounting-awaiting-heading">');

        Livewire::actingAs($actor)->test(ViewEnrollment::class, ['record' => $awaitingCase->id])
            ->assertSeeHtml('<section class="accounting-awaiting-summary tala-task-surface" aria-labelledby="accounting-awaiting-heading">');

        $draftTotalsHtml = view('filament.admin.enrollments.accounting-draft-totals', [
            'chargeTotal' => 150000,
            'paymentTotal' => 150000,
        ])->render();
        $this->assertStringContainsString('class="tala-live-totals accounting-fee-plan-form-totals"', $draftTotalsHtml);
        $this->assertStringContainsString('role="status" aria-live="polite"', $draftTotalsHtml);
        $this->assertStringContainsString('Amounts match', $draftTotalsHtml);
    }

    public function test_accounting_breadcrumbs_trace_to_overview_and_split_inspector_surfaces_recovery_links(): void
    {
        $actor = $this->accounting();
        $program = Program::factory()->create(['name' => 'Bachelor of Science in Accountancy']);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $plan = FeePlan::factory()->create([
            'program_id' => $program->id,
            'term_id' => $term->id,
            'version' => 2,
            'state' => FeePlan::StatePublished,
            'created_by' => $actor->id,
        ]);

        $this->actingAs($actor);

        $this->assertSame([
            EnrollmentResource::getUrl('index') => 'Overview',
            'Fee plans',
        ], Livewire::test(ListFeePlans::class)->instance()->getBreadcrumbs());

        $this->assertSame([
            EnrollmentResource::getUrl('index') => 'Overview',
            FeePlanResource::getUrl('index') => 'Fee plans',
            'Bachelor of Science in Accountancy',
        ], Livewire::test(ViewFeePlan::class, ['record' => $plan->getRouteKey()])->instance()->getBreadcrumbs());

        $this->assertSame([
            EnrollmentResource::getUrl('index') => 'Overview',
            'Payment channels',
        ], Livewire::test(PaymentChannels::class)->instance()->getBreadcrumbs());

        $otherTerm = Term::factory()->create(['state' => Term::StateActive]);
        $case = Enrollment::factory()->create(['term_id' => $otherTerm->id]);
        $account = TermAccount::factory()->create(['enrollment_id' => $case->id, 'term_id' => $otherTerm->id]);
        Assessment::factory()->create([
            'enrollment_id' => $case->id,
            'term_account_id' => $account->id,
            'state' => Assessment::StateSuperseded,
            'activated_by' => $actor->id,
        ]);
        $change = StudentLifecycleChange::factory()->create([
            'enrollment_id' => $case->id,
            'student_profile_id' => $case->student_profile_id,
            'term_id' => $otherTerm->id,
            'state' => StudentLifecycleChange::StateApplied,
        ]);
        app(LifecycleFinanceAction::class)->execute($change, $actor);

        Livewire::test(AccountingAccountInspector::class, ['record' => $case->id])
            ->assertSee('Open Fee Plans')
            ->assertSee('View change');

        $emptyTerm = Term::factory()->create(['state' => Term::StateActive]);
        Livewire::withQueryParams([
            'tab' => 'accounts',
            'filters' => ['term' => ['value' => (string) $emptyTerm->id]],
        ])->test(ListEnrollments::class)
            ->assertActionDisabled('exportAccountStatus');
        Livewire::withQueryParams([]);
    }

    private function accounting(): User
    {
        Role::findOrCreate(User::StaffRoleAccounting, 'web');
        $actor = User::factory()->create(['status' => User::StatusActive]);
        $actor->assignRole(User::StaffRoleAccounting);

        return $actor;
    }
}
