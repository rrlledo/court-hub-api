<?php

namespace Tests\Unit;

use App\Services\TotpService;
use ReflectionMethod;
use Tests\TestCase;

class TotpServiceTest extends TestCase
{
    public function test_generates_a_valid_base32_secret(): void
    {
        $secret = app(TotpService::class)->generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
    }

    public function test_generates_a_standard_provisioning_uri(): void
    {
        $uri = app(TotpService::class)->provisioningUri('JBSWY3DPEHPK3PXP', 'owner@example.com');

        $this->assertStringContainsString('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('issuer=Court%20Hub', $uri);
    }

    public function test_implements_the_rfc_6238_six_digit_sha1_vector(): void
    {
        $method = new ReflectionMethod(TotpService::class, 'code');
        $code = $method->invoke(app(TotpService::class), 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1);

        $this->assertSame('287082', $code);
    }

    public function test_rejects_invalid_totp_codes(): void
    {
        $this->assertFalse(app(TotpService::class)->verify('JBSWY3DPEHPK3PXP', 'not-a-code'));
    }
}
