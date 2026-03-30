<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BasisBank\Auth;

use App\Services\BasisBank\Auth\BasisBankHttpTransport;
use App\Services\BasisBank\Auth\BasisBankWebAuthClient;
use Tests\TestCase;

/**
 * Tests for Fix #35: Verify that HTTP transport methods extracted into
 * BasisBankHttpTransport trait are accessible from BasisBankWebAuthClient.
 *
 * @internal
 * @coversNothing
 */
final class BasisBankWebAuthClientTest extends TestCase
{
    public function testClassUsesHttpTransportTrait(): void
    {
        $traits = class_uses(BasisBankWebAuthClient::class);
        $this->assertArrayHasKey(
            BasisBankHttpTransport::class,
            $traits,
            'BasisBankWebAuthClient must use the BasisBankHttpTransport trait.'
        );
    }

    public function testTransportMethodsExistOnClient(): void
    {
        $reflection = new \ReflectionClass(BasisBankWebAuthClient::class);

        $expectedMethods = [
            'requestGet',
            'requestGetManual',
            'requestPost',
            'requestLoginSessionId',
            'requestDeviceBindingSessionId',
            'probeCheckSession',
            'createClient',
            'defaultHeaders',
            'ajaxSessionHeaders',
            'isTimeoutException',
            'normalizeRedirectPath',
            'extractCookieValue',
        ];

        foreach ($expectedMethods as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                sprintf('Method "%s" must be accessible on BasisBankWebAuthClient (via trait).', $method)
            );
        }
    }

    public function testAuthFlowMethodsRemainOnClient(): void
    {
        $reflection = new \ReflectionClass(BasisBankWebAuthClient::class);

        $authMethods = [
            'start',
            'submitOtp',
            'submitTrustedDeviceCode',
            'refreshOtpChallenge',
            'continueFlow',
        ];

        foreach ($authMethods as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                sprintf('Auth flow method "%s" must remain on BasisBankWebAuthClient.', $method)
            );
        }
    }
}
