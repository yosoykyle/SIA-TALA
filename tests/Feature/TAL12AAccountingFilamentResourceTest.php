<?php

namespace Tests\Feature;

use Tests\TestCase;

class TAL12AAccountingFilamentResourceTest extends TestCase
{
    public function test_accounting_resources_use_accounting_navigation_group(): void
    {
        foreach ([
            'FeeTemplates/FeeTemplateResource.php',
            'PaymentAttempts/PaymentAttemptResource.php',
            'Payments/PaymentResource.php',
            'LedgerEntries/LedgerEntryResource.php',
            'AccountingAdjustments/AccountingAdjustmentResource.php',
            'InstallmentPolicies/InstallmentPolicyResource.php',
        ] as $relativePath) {
            $source = $this->resourceSource($relativePath);

            $this->assertStringContainsString("'Accounting'", $source);
            $this->assertStringContainsString('academic-head', $source);
        }

        $promissoryResource = $this->resourceSource('PromissoryNotes/PromissoryNoteResource.php');

        $this->assertStringContainsString("'Accounting'", $promissoryResource);
        $this->assertStringNotContainsString('academic-head', $promissoryResource);

        $milestoneResource = $this->resourceSource('InstallmentPolicyMilestones/InstallmentPolicyMilestoneResource.php');

        $this->assertStringContainsString("'Accounting'", $milestoneResource);
        $this->assertStringNotContainsString('academic-head', $milestoneResource);
    }

    public function test_accounting_write_actions_are_guarded_by_assessment_payment_or_promissory_permissions(): void
    {
        $expectations = [
            'FeeTemplatePolicy.php' => 'create-assessments',
            'PaymentPolicy.php' => 'process-payments',
            'PaymentAttemptPolicy.php' => 'process-payments',
            'LedgerEntryPolicy.php' => 'process-payments',
            'AccountingAdjustmentPolicy.php' => 'post-accounting-adjustments',
            'PromissoryNotePolicy.php' => 'approve-promissory-notes',
            'InstallmentPolicyPolicy.php' => 'create-assessments',
        ];

        foreach ($expectations as $policyFile => $permission) {
            $source = file_get_contents(app_path("Policies/{$policyFile}"));

            $this->assertIsString($source);
            $this->assertStringContainsString($permission, $source);
        }
    }

    public function test_accounting_forms_include_core_assessment_payment_promissory_and_installment_fields(): void
    {
        $checks = [
            'FeeTemplates/Schemas/FeeTemplateForm.php' => ['tuition_fee', 'laboratory_fee', 'misc_fee', 'other_fee', 'minimum_downpayment_percentage'],
            'AccountingAdjustments/Schemas/AccountingAdjustmentForm.php' => ['student_profile_id', 'adjustment_type', 'source_ledger_entry_id', 'amount', 'evidence_reference', 'posted_at', 'reason'],
            'PromissoryNotes/Schemas/PromissoryNoteForm.php' => ['amount', 'due_date', 'reason'],
            'InstallmentPolicies/Schemas/InstallmentPolicyForm.php' => ['max_months', 'grace_days', 'penalty_rate', 'penalty_frequency', 'due_day_rule'],
        ];

        foreach ($checks as $relativePath => $fields) {
            $source = $this->resourceSource($relativePath);

            foreach ($fields as $field) {
                $this->assertStringContainsString("'{$field}'", $source, "{$relativePath} should contain {$field}.");
            }
        }
    }

    public function test_accounting_adjustments_are_typed_service_posts_not_generic_crud(): void
    {
        foreach ([
            'AccountingAdjustments/Pages/EditAccountingAdjustment.php',
        ] as $relativePath) {
            $this->assertFileDoesNotExist(app_path("Filament/Resources/{$relativePath}"));
        }

        $resource = $this->resourceSource('AccountingAdjustments/AccountingAdjustmentResource.php');
        $form = $this->resourceSource('AccountingAdjustments/Schemas/AccountingAdjustmentForm.php');
        $infolist = $this->resourceSource('AccountingAdjustments/Schemas/AccountingAdjustmentInfolist.php');
        $table = $this->resourceSource('AccountingAdjustments/Tables/AccountingAdjustmentsTable.php');
        $createPage = $this->resourceSource('AccountingAdjustments/Pages/CreateAccountingAdjustment.php');
        $viewPage = $this->resourceSource('AccountingAdjustments/Pages/ViewAccountingAdjustment.php');
        $ledgerTable = $this->resourceSource('LedgerEntries/Tables/LedgerEntriesTable.php');
        $policy = file_get_contents(app_path('Policies/AccountingAdjustmentPolicy.php'));

        $this->assertIsString($policy);
        $this->assertStringContainsString("'create'", $resource);
        $this->assertStringNotContainsString("'edit'", $resource);
        $this->assertStringContainsString('AccountingAdjustment::enrollmentOptionsFor', $form);
        $this->assertStringContainsString('AccountingAdjustment::sourceLedgerOptionsFor', $form);
        $this->assertStringContainsString("DateTimePicker::make('posted_at')", $form);
        $this->assertStringContainsString("TextInput::make('amount')", $form);
        $this->assertStringContainsString("Textarea::make('reason')", $form);
        $this->assertStringContainsString('AccountingAdjustmentService', $createPage);
        $this->assertStringContainsString('->post($data, $actor', $createPage);
        $this->assertStringNotContainsString('EditAction::make()', $viewPage);
        $this->assertStringNotContainsString('EditAction::make()', $table);
        $this->assertStringNotContainsString('DeleteBulkAction::make()', $table);
        $this->assertStringContainsString('ViewAction::make()', $table);
        $this->assertStringContainsString("TextEntry::make('studentProfile.student_id')", $infolist);
        $this->assertStringContainsString("TextEntry::make('studentProfile.user.name')", $infolist);
        $this->assertStringContainsString('AccountingAdjustment::sourceLedgerOptionLabel', $infolist);
        $this->assertStringNotContainsString("TextEntry::make('student_profile_id')", $infolist);
        $this->assertStringNotContainsString("TextEntry::make('term_id')", $infolist);
        $this->assertStringNotContainsString("TextEntry::make('enrollment_id')", $infolist);
        $this->assertStringNotContainsString("TextEntry::make('ledger_entry_id')", $infolist);
        $this->assertStringNotContainsString("TextEntry::make('posted_by')", $infolist);
        $this->assertStringContainsString('post-accounting-adjustments', $policy);
        $this->assertStringContainsString("'accounting_adjustment' => 'Accounting Adjustment'", $ledgerTable);
    }

    public function test_installment_policy_milestones_are_child_schedule_rows_not_generic_crud(): void
    {
        foreach ([
            'InstallmentPolicyMilestones/Pages/CreateInstallmentPolicyMilestone.php',
            'InstallmentPolicyMilestones/Pages/EditInstallmentPolicyMilestone.php',
            'InstallmentPolicyMilestones/Schemas/InstallmentPolicyMilestoneForm.php',
        ] as $relativePath) {
            $this->assertFileDoesNotExist(app_path("Filament/Resources/{$relativePath}"));
        }

        $policyForm = $this->resourceSource('InstallmentPolicies/Schemas/InstallmentPolicyForm.php');
        $policyInfolist = $this->resourceSource('InstallmentPolicies/Schemas/InstallmentPolicyInfolist.php');
        $milestoneResource = $this->resourceSource('InstallmentPolicyMilestones/InstallmentPolicyMilestoneResource.php');
        $milestoneTable = $this->resourceSource('InstallmentPolicyMilestones/Tables/InstallmentPolicyMilestonesTable.php');
        $listPage = $this->resourceSource('InstallmentPolicyMilestones/Pages/ListInstallmentPolicyMilestones.php');
        $viewPage = $this->resourceSource('InstallmentPolicyMilestones/Pages/ViewInstallmentPolicyMilestone.php');
        $policy = file_get_contents(app_path('Policies/InstallmentPolicyMilestonePolicy.php'));

        $this->assertIsString($policy);
        $this->assertStringContainsString("Repeater::make('milestones')", $policyForm);
        $this->assertStringContainsString('->relationship()', $policyForm);
        $this->assertStringContainsString("TextInput::make('sequence')", $policyForm);
        $this->assertStringContainsString("TextInput::make('month_offset')", $policyForm);
        $this->assertStringContainsString("TextInput::make('required_percentage')", $policyForm);
        $this->assertStringContainsString("Toggle::make('status')", $policyForm);
        $this->assertStringNotContainsString("Select::make('status')", $policyForm);
        $this->assertStringContainsString("RepeatableEntry::make('milestones')", $policyInfolist);
        $this->assertStringNotContainsString("'create'", $milestoneResource);
        $this->assertStringNotContainsString("'edit'", $milestoneResource);
        $this->assertStringNotContainsString('function form(', $milestoneResource);
        $this->assertStringNotContainsString('CreateAction::make()', $listPage);
        $this->assertStringNotContainsString('EditAction::make()', $viewPage);
        $this->assertStringNotContainsString('EditAction::make()', $milestoneTable);
        $this->assertStringContainsString('public function create(User $user): bool', $policy);
        $this->assertStringContainsString('public function update(User $user, InstallmentPolicyMilestone $installmentPolicyMilestone): bool', $policy);
        $this->assertStringContainsString('return false;', $policy);
    }

    public function test_promissory_notes_use_lifecycle_queue_without_generic_status_editing(): void
    {
        foreach ([
            'PromissoryNotes/Pages/EditPromissoryNote.php',
        ] as $relativePath) {
            $this->assertFileDoesNotExist(app_path("Filament/Resources/{$relativePath}"));
        }

        $resource = $this->resourceSource('PromissoryNotes/PromissoryNoteResource.php');
        $form = $this->resourceSource('PromissoryNotes/Schemas/PromissoryNoteForm.php');
        $infolist = $this->resourceSource('PromissoryNotes/Schemas/PromissoryNoteInfolist.php');
        $table = $this->resourceSource('PromissoryNotes/Tables/PromissoryNotesTable.php');
        $createPage = $this->resourceSource('PromissoryNotes/Pages/CreatePromissoryNote.php');
        $viewPage = $this->resourceSource('PromissoryNotes/Pages/ViewPromissoryNote.php');
        $policy = file_get_contents(app_path('Policies/PromissoryNotePolicy.php'));

        $this->assertIsString($policy);
        $this->assertStringContainsString("'create'", $resource);
        $this->assertStringNotContainsString("'edit'", $resource);
        $this->assertStringNotContainsString("Select::make('status')", $form);
        $this->assertStringNotContainsString("->relationship('enrollment', 'id')", $form);
        $this->assertStringNotContainsString("->relationship('ledgerEntry', 'id')", $form);
        $this->assertStringContainsString('PromissoryNote::enrollmentOptionsFor', $form);
        $this->assertStringContainsString('PromissoryNote::ledgerEntryOptionsFor', $form);
        $this->assertStringContainsString('->live()', $form);
        $this->assertStringContainsString('->afterStateUpdated', $form);
        $this->assertStringContainsString("TextEntry::make('studentProfile.student_id')", $infolist);
        $this->assertStringContainsString("TextEntry::make('studentProfile.user.name')", $infolist);
        $this->assertStringContainsString("TextEntry::make('term.term_name')", $infolist);
        $this->assertStringContainsString('PromissoryNote::enrollmentOptionLabel', $infolist);
        $this->assertStringContainsString('PromissoryNote::ledgerEntryOptionLabel', $infolist);
        $this->assertStringContainsString("TextEntry::make('approver.name')", $infolist);
        $this->assertStringNotContainsString("TextEntry::make('student_profile_id')", $infolist);
        $this->assertStringNotContainsString("TextEntry::make('term_id')", $infolist);
        $this->assertStringNotContainsString("TextEntry::make('enrollment_id')", $infolist);
        $this->assertStringNotContainsString("TextEntry::make('ledger_entry_id')", $infolist);
        $this->assertStringNotContainsString("TextEntry::make('approved_by')", $infolist);
        $this->assertStringContainsString('PromissoryNote::enrollmentOptionLabel', $table);
        $this->assertStringContainsString('PromissoryNote::ledgerEntryOptionLabel', $table);
        $this->assertStringContainsString("Action::make('approve')", $table);
        $this->assertStringContainsString("Action::make('reject')", $table);
        $this->assertStringContainsString("Action::make('cancel')", $table);
        $this->assertStringContainsString('PromissoryNoteLifecycleService', $table);
        $this->assertStringNotContainsString('EditAction::make()', $table);
        $this->assertStringNotContainsString('EditAction::make()', $viewPage);
        $this->assertStringContainsString('PromissoryNoteLifecycleService', $createPage);
        $this->assertStringContainsString('->submit($data, $actor)', $createPage);
        $this->assertStringNotContainsString("\$data['status'] = 'approved';", $createPage);
        $this->assertStringNotContainsString("\$data['approved_by'] = Auth::id();", $createPage);
        $this->assertStringContainsString('return false;', $policy);
    }

    public function test_payment_records_are_read_only_surfaces_created_by_services_and_webhooks(): void
    {
        foreach ([
            'Payments/Pages/CreatePayment.php',
            'Payments/Pages/EditPayment.php',
            'Payments/Schemas/PaymentForm.php',
            'PaymentAttempts/Pages/CreatePaymentAttempt.php',
            'PaymentAttempts/Pages/EditPaymentAttempt.php',
            'PaymentAttempts/Schemas/PaymentAttemptForm.php',
        ] as $relativePath) {
            $this->assertFileDoesNotExist(app_path("Filament/Resources/{$relativePath}"));
        }

        foreach ([
            'Payments/PaymentResource.php',
            'PaymentAttempts/PaymentAttemptResource.php',
        ] as $relativePath) {
            $source = $this->resourceSource($relativePath);

            $this->assertStringNotContainsString("'create'", $source);
            $this->assertStringNotContainsString("'edit'", $source);
            $this->assertStringNotContainsString('function form(', $source);
        }

        foreach ([
            'Payments/Pages/ListPayments.php',
            'Payments/Pages/ViewPayment.php',
            'PaymentAttempts/Pages/ListPaymentAttempts.php',
            'PaymentAttempts/Pages/ViewPaymentAttempt.php',
        ] as $relativePath) {
            $source = $this->resourceSource($relativePath);

            $this->assertStringNotContainsString('CreateAction::make()', $source);
            $this->assertStringNotContainsString('EditAction::make()', $source);
        }

        $enrollmentTable = $this->resourceSource('Enrollments/Tables/EnrollmentsTable.php');

        $this->assertStringContainsString('Payment::manualConfirmationChannelOptions()', $enrollmentTable);
        $this->assertStringContainsString("TextInput::make('payment_reference')", $enrollmentTable);
        $this->assertStringContainsString("DateTimePicker::make('confirmed_at')", $enrollmentTable);
        $this->assertStringNotContainsString("'paymongo_reconciled'", $enrollmentTable);
    }

    public function test_ledger_entries_are_immutable_evidence_not_generic_crud(): void
    {
        foreach ([
            'LedgerEntries/Pages/CreateLedgerEntry.php',
            'LedgerEntries/Pages/EditLedgerEntry.php',
            'LedgerEntries/Schemas/LedgerEntryForm.php',
        ] as $relativePath) {
            $this->assertFileDoesNotExist(app_path("Filament/Resources/{$relativePath}"));
        }

        $resource = $this->resourceSource('LedgerEntries/LedgerEntryResource.php');

        $this->assertStringNotContainsString("'create'", $resource);
        $this->assertStringNotContainsString("'edit'", $resource);
        $this->assertStringNotContainsString('function form(', $resource);

        foreach ([
            'LedgerEntries/Pages/ListLedgerEntries.php',
            'LedgerEntries/Pages/ViewLedgerEntry.php',
        ] as $relativePath) {
            $source = $this->resourceSource($relativePath);

            $this->assertStringNotContainsString('CreateAction::make()', $source);
            $this->assertStringNotContainsString('EditAction::make()', $source);
            $this->assertStringNotContainsString('DeleteAction::make()', $source);
        }
    }

    private function resourceSource(string $relativePath): string
    {
        $source = file_get_contents(app_path("Filament/Resources/{$relativePath}"));

        $this->assertIsString($source);

        return $source;
    }
}
