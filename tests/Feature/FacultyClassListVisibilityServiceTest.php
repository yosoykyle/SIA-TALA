<?php

namespace Tests\Feature;

use App\Actions\Faculty\FacultyClassListRow;
use App\Actions\Faculty\FacultyClassListService;
use Tests\TestCase;

class FacultyClassListVisibilityServiceTest extends TestCase
{
    public function test_faculty_class_list_row_exposes_only_allowed_payment_status_not_financial_details(): void
    {
        $reflection = new \ReflectionClass(FacultyClassListRow::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor);

        $parameters = collect($constructor->getParameters())->map(fn (\ReflectionParameter $parameter): string => $parameter->getName())->all();

        $this->assertContains('financeStatus', $parameters);
        $this->assertNotContains('currentBalance', $parameters);
        $this->assertNotContains('paymentHistory', $parameters);
        $this->assertNotContains('ledgerEntries', $parameters);
        $this->assertNotContains('promissoryNotes', $parameters);
    }

    public function test_faculty_class_list_requires_faculty_role_permission_and_assignment(): void
    {
        $source = $this->source(FacultyClassListService::class);

        $this->assertStringContainsString("hasRole('faculty')", $source);
        $this->assertStringContainsString("can('view-class-list')", $source);
        $this->assertStringContainsString('assertAssignedToSectionSubject', $source);
        $this->assertStringContainsString('Faculty can view only assigned section and subject class lists.', $source);
    }

    public function test_faculty_payment_visibility_is_reduced_to_paid_or_with_balance(): void
    {
        $source = $this->source(FacultyClassListService::class);

        $this->assertStringContainsString("return 'with_balance';", $source);
        $this->assertStringContainsString("return 'paid';", $source);
        $this->assertStringContainsString('hasPendingPaymentAttempt', $source);
        $this->assertStringContainsString('hasPromissoryHold', $source);
        $this->assertStringContainsString('hasDocumentShippingHold', $source);
    }

    private function source(string $class): string
    {
        $reflection = new \ReflectionClass($class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);

        return $source;
    }
}
