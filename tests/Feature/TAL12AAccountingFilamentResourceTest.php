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
            'PromissoryNotes/PromissoryNoteResource.php',
            'InstallmentPolicies/InstallmentPolicyResource.php',
            'InstallmentPolicyMilestones/InstallmentPolicyMilestoneResource.php',
        ] as $relativePath) {
            $source = $this->resourceSource($relativePath);

            $this->assertStringContainsString("'Accounting'", $source);
            $this->assertStringContainsString('academic-head', $source);
        }
    }

    public function test_accounting_write_actions_are_guarded_by_assessment_payment_or_promissory_permissions(): void
    {
        $expectations = [
            'FeeTemplatePolicy.php' => 'create-assessments',
            'PaymentPolicy.php' => 'process-payments',
            'PaymentAttemptPolicy.php' => 'process-payments',
            'LedgerEntryPolicy.php' => 'process-payments',
            'PromissoryNotePolicy.php' => 'approve-promissory-notes',
            'InstallmentPolicyPolicy.php' => 'create-assessments',
            'InstallmentPolicyMilestonePolicy.php' => 'create-assessments',
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
            'PromissoryNotes/Schemas/PromissoryNoteForm.php' => ['amount', 'due_date', 'reason'],
            'InstallmentPolicies/Schemas/InstallmentPolicyForm.php' => ['max_months', 'grace_days', 'penalty_rate', 'penalty_frequency', 'due_day_rule'],
            'InstallmentPolicyMilestones/Schemas/InstallmentPolicyMilestoneForm.php' => ['sequence', 'month_offset', 'required_percentage', 'status'],
        ];

        foreach ($checks as $relativePath => $fields) {
            $source = $this->resourceSource($relativePath);

            foreach ($fields as $field) {
                $this->assertStringContainsString("'{$field}'", $source, "{$relativePath} should contain {$field}.");
            }
        }
    }

    public function test_promissory_notes_are_recorded_without_generic_status_editing(): void
    {
        foreach ([
            'PromissoryNotes/Pages/EditPromissoryNote.php',
        ] as $relativePath) {
            $this->assertFileDoesNotExist(app_path("Filament/Resources/{$relativePath}"));
        }

        $resource = $this->resourceSource('PromissoryNotes/PromissoryNoteResource.php');
        $form = $this->resourceSource('PromissoryNotes/Schemas/PromissoryNoteForm.php');
        $table = $this->resourceSource('PromissoryNotes/Tables/PromissoryNotesTable.php');
        $createPage = $this->resourceSource('PromissoryNotes/Pages/CreatePromissoryNote.php');
        $viewPage = $this->resourceSource('PromissoryNotes/Pages/ViewPromissoryNote.php');
        $policy = file_get_contents(app_path('Policies/PromissoryNotePolicy.php'));

        $this->assertIsString($policy);
        $this->assertStringContainsString("'create'", $resource);
        $this->assertStringNotContainsString("'edit'", $resource);
        $this->assertStringNotContainsString("Select::make('status')", $form);
        $this->assertStringNotContainsString('EditAction::make()', $table);
        $this->assertStringNotContainsString('EditAction::make()', $viewPage);
        $this->assertStringContainsString("\$data['status'] = 'approved';", $createPage);
        $this->assertStringContainsString("\$data['approved_by'] = Auth::id();", $createPage);
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
