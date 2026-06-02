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
            'Payments/Schemas/PaymentForm.php' => ['amount', 'channel', 'status', 'payment_reference'],
            'PromissoryNotes/Schemas/PromissoryNoteForm.php' => ['status', 'due_date', 'reason'],
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

    private function resourceSource(string $relativePath): string
    {
        $source = file_get_contents(app_path("Filament/Resources/{$relativePath}"));

        $this->assertIsString($source);

        return $source;
    }
}
