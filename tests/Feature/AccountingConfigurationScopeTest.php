<?php

namespace Tests\Feature;

use App\Models\FeeTemplate;
use App\Models\InstallmentPolicy;
use App\Models\Program;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountingConfigurationScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounting_configuration_forms_use_college_only_year_level_scope_selects(): void
    {
        $feeTemplateForm = file_get_contents(app_path('Filament/Resources/FeeTemplates/Schemas/FeeTemplateForm.php'));
        $installmentPolicyForm = file_get_contents(app_path('Filament/Resources/InstallmentPolicies/Schemas/InstallmentPolicyForm.php'));

        $this->assertIsString($feeTemplateForm);
        $this->assertIsString($installmentPolicyForm);

        foreach ([$feeTemplateForm, $installmentPolicyForm] as $form) {
            $this->assertStringContainsString("Select::make('year_level')", $form);
            $this->assertStringContainsString("->placeholder('All year levels')", $form);
            $this->assertStringContainsString('->options(', $form);
            $this->assertStringNotContainsString("Hidden::make('education_level')", $form);
            $this->assertStringNotContainsString("Select::make('education_level')", $form);
            $this->assertStringNotContainsString("TextInput::make('year_level')", $form);
        }

        $this->assertSame([
            '1st Year' => '1st Year',
            '2nd Year' => '2nd Year',
            '3rd Year' => '3rd Year',
            '4th Year' => '4th Year',
        ], FeeTemplate::yearLevelOptions());
    }

    public function test_fee_templates_allow_only_one_active_template_per_scope(): void
    {
        $program = Program::factory()->create();

        FeeTemplate::query()->create([
            'name' => 'College Standard',
            'program_id' => $program->id,
            'year_level' => ' 1st Year ',
            'tuition_fee' => '10000.00',
            'laboratory_fee' => '500.00',
            'misc_fee' => '800.00',
            'other_fee' => '200.00',
            'minimum_downpayment_percentage' => '20.00',
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);

        FeeTemplate::query()->create([
            'name' => 'Duplicate Active Scope',
            'program_id' => $program->id,
            'year_level' => '1st Year',
            'tuition_fee' => '12000.00',
            'laboratory_fee' => '500.00',
            'misc_fee' => '800.00',
            'other_fee' => '200.00',
            'minimum_downpayment_percentage' => '25.00',
            'is_active' => true,
        ]);
    }

    public function test_inactive_fee_templates_can_share_a_scope_for_history(): void
    {
        $program = Program::factory()->create();

        FeeTemplate::query()->create([
            'name' => 'Active Scope',
            'program_id' => $program->id,
            'year_level' => '1st Year',
            'tuition_fee' => '10000.00',
            'laboratory_fee' => '500.00',
            'misc_fee' => '800.00',
            'other_fee' => '200.00',
            'minimum_downpayment_percentage' => '20.00',
            'is_active' => true,
        ]);

        $inactiveTemplate = FeeTemplate::query()->create([
            'name' => 'Inactive Historical Scope',
            'program_id' => $program->id,
            'year_level' => '1st Year',
            'tuition_fee' => '12000.00',
            'laboratory_fee' => '500.00',
            'misc_fee' => '800.00',
            'other_fee' => '200.00',
            'minimum_downpayment_percentage' => '25.00',
            'is_active' => false,
        ]);

        $this->assertFalse($inactiveTemplate->is_active);
    }

    public function test_installment_policies_allow_only_one_active_policy_per_scope(): void
    {
        $program = Program::factory()->create();

        InstallmentPolicy::query()->create($this->installmentPolicyData([
            'name' => 'College Policy',
            'program_id' => $program->id,
            'year_level' => ' 1st Year ',
            'is_active' => true,
        ]));

        $this->expectException(ValidationException::class);

        InstallmentPolicy::query()->create($this->installmentPolicyData([
            'name' => 'Duplicate Active Policy',
            'program_id' => $program->id,
            'year_level' => '1st Year',
            'is_active' => true,
        ]));
    }

    public function test_inactive_installment_policies_can_share_a_scope_for_history(): void
    {
        $program = Program::factory()->create();

        InstallmentPolicy::query()->create($this->installmentPolicyData([
            'name' => 'Active Policy',
            'program_id' => $program->id,
            'year_level' => '1st Year',
            'is_active' => true,
        ]));

        $inactivePolicy = InstallmentPolicy::query()->create($this->installmentPolicyData([
            'name' => 'Inactive Historical Policy',
            'program_id' => $program->id,
            'year_level' => '1st Year',
            'is_active' => false,
        ]));

        $this->assertFalse($inactivePolicy->is_active);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function installmentPolicyData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Default Policy',
            'program_id' => null,
            'year_level' => null,
            'max_months' => 10,
            'due_day_rule' => 'end_of_month',
            'grace_days' => 3,
            'penalty_rate' => '5.00',
            'penalty_frequency' => 'per_missed_month',
            'allow_partial_payments' => false,
            'promissory_is_non_clearing' => true,
            'is_active' => true,
            'meta' => null,
        ], $overrides);
    }
}
