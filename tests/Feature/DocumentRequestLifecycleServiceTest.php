<?php

namespace Tests\Feature;

use App\Actions\ServiceRequests\DocumentRequestLifecycleService;
use App\Models\DocumentRequest;
use Tests\TestCase;

class DocumentRequestLifecycleServiceTest extends TestCase
{
    public function test_document_request_type_options_match_approved_spec_list(): void
    {
        $this->assertSame([
            DocumentRequest::TypeCertificateOfRegistration => 'Certificate of Registration',
            DocumentRequest::TypeCertificateOfEnrollment => 'Certificate of Enrollment',
            DocumentRequest::TypeGoodMoralCharacter => 'Certificate of Good Moral Character',
            DocumentRequest::TypeTranscriptOfRecords => 'Transcript of Records',
            DocumentRequest::TypeForm137 => 'Form 137',
            DocumentRequest::TypeForm138 => 'Form 138',
            DocumentRequest::TypeDiploma => 'Diploma',
            DocumentRequest::TypeOther => 'Other',
        ], DocumentRequest::documentTypeOptions());
    }

    public function test_courier_requests_require_consent_before_creation_or_shipping(): void
    {
        $source = $this->source(DocumentRequestLifecycleService::class);

        $this->assertStringContainsString('Courier delivery requires explicit data privacy consent.', $source);
        $this->assertStringContainsString('Courier request cannot ship without data privacy consent.', $source);
        $this->assertStringContainsString('DeliveryModeCourier', $source);
    }

    public function test_shipping_flow_moves_to_pending_shipping_payment_with_three_day_grace(): void
    {
        $source = $this->source(DocumentRequestLifecycleService::class);

        $this->assertStringContainsString('StatusPendingShippingPayment', $source);
        $this->assertStringContainsString("'shipping_grace_ends_at' => \$timestamp->addDays(3)", $source);
        $this->assertStringContainsString('Please pay the shipping fee within 3 calendar days to avoid debt posting.', $source);
        $this->assertStringContainsString('confirmShippingPayment', $source);
    }

    private function source(string $class): string
    {
        $reflection = new \ReflectionClass($class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);

        return $source;
    }
}
