<?php

namespace Tests\Unit;

use App\Actions\Authentication\TalaAppAuthentication;
use App\Actions\Authentication\UserSessionService;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use PragmaRX\Google2FAQRCode\Google2FA;

final class StaffMfaAndSessionPolicyTest extends TestCase
{
    public function test_mfa_qr_code_normalization_removes_only_a_duplicate_svg_data_uri_layer(): void
    {
        $provider = new class(new Google2FA) extends TalaAppAuthentication
        {
            public function normalizeForTest(#[\SensitiveParameter] string $dataUri): string
            {
                return $this->normalizeQrCodeDataUri($dataUri);
            }
        };
        $svgDataUri = 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $duplicateSvgDataUri = 'data:image/svg+xml;base64,'.base64_encode($svgDataUri);
        $pngDataUri = 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\n");

        $this->assertSame($svgDataUri, $provider->normalizeForTest($duplicateSvgDataUri));
        $this->assertSame($svgDataUri, $provider->normalizeForTest($svgDataUri));
        $this->assertSame($pngDataUri, $provider->normalizeForTest($pngDataUri));
    }

    public function test_staff_capable_accounts_use_stricter_session_policy(): void
    {
        $staffLearner = new class extends User
        {
            public function isStaffCapable(): bool
            {
                return true;
            }
        };
        $learner = new class extends User
        {
            public function isStaffCapable(): bool
            {
                return false;
            }
        };

        $service = new UserSessionService;

        $this->assertFalse($service->rememberAllowed($staffLearner));
        $this->assertSame(30, $service->idleTimeoutMinutes($staffLearner));
        $this->assertTrue($service->rememberAllowed($learner));
        $this->assertSame(120, $service->idleTimeoutMinutes($learner));
    }
}
