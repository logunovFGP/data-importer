<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TBank;

use App\Exceptions\ImporterErrorException;
use App\Services\TBank\Authentication\SecretManager;
use App\Services\TBank\OAuthClient;
use Tests\TestCase;

/**
 * Fix #39: When $expectedClientState is non-empty but $clientState is empty,
 * the validation was skipped — allowing OAuth state bypass.
 * The fix rejects empty $clientState when $expectedClientState is set.
 *
 * @internal
 *
 * @coversDefaultClass \App\Services\TBank\OAuthClient
 */
final class OAuthClientStateTest extends TestCase
{
    private OAuthClient $oauthClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oauthClient = new OAuthClient();
    }

    /**
     * Empty clientState must be rejected when expectedClientState is non-empty.
     *
     * @covers ::exchangeAuthorizationCode
     */
    public function testEmptyClientStateIsRejectedWhenExpectedStateIsSet(): void
    {
        $expectedState = 'valid-state-abc123';
        SecretManager::saveOAuthState($expectedState);

        $this->expectException(ImporterErrorException::class);
        $this->expectExceptionMessage('TBank callback state mismatch');

        // clientState is empty string — should throw, not silently pass
        $this->oauthClient->exchangeAuthorizationCode('code123', 'state456', 'session789', '');
    }

    /**
     * Whitespace-only clientState must also be rejected.
     *
     * @covers ::exchangeAuthorizationCode
     */
    public function testWhitespaceOnlyClientStateIsRejected(): void
    {
        $expectedState = 'valid-state-abc123';
        SecretManager::saveOAuthState($expectedState);

        $this->expectException(ImporterErrorException::class);
        $this->expectExceptionMessage('TBank callback state mismatch');

        $this->oauthClient->exchangeAuthorizationCode('code123', 'state456', 'session789', '   ');
    }

    /**
     * Mismatched clientState must be rejected.
     *
     * @covers ::exchangeAuthorizationCode
     */
    public function testMismatchedClientStateIsRejected(): void
    {
        $expectedState = 'valid-state-abc123';
        SecretManager::saveOAuthState($expectedState);

        $this->expectException(ImporterErrorException::class);
        $this->expectExceptionMessage('TBank callback state mismatch');

        $this->oauthClient->exchangeAuthorizationCode('code123', 'state456', 'session789', 'wrong-state');
    }

    /**
     * When no expected state is stored (empty string), any clientState is accepted
     * (the state check is skipped entirely).
     * The method will still fail later (on the HTTP request), but NOT on state validation.
     *
     * @covers ::exchangeAuthorizationCode
     */
    public function testNoExpectedStateSkipsValidation(): void
    {
        SecretManager::clearOAuthState();

        // Missing required config will cause a different exception, not a state mismatch
        try {
            $this->oauthClient->exchangeAuthorizationCode('code123', 'state456', 'session789', '');
        } catch (ImporterErrorException $e) {
            // The error should NOT be about state mismatch — it should be about check_auth URL
            $this->assertStringNotContainsString('state mismatch', $e->getMessage());

            return;
        }

        $this->fail('Expected ImporterErrorException to be thrown (for missing config, not state mismatch)');
    }

    /**
     * Missing code/state/sessionState should throw before state validation.
     *
     * @covers ::exchangeAuthorizationCode
     */
    public function testMissingRequiredFieldsThrowsBeforeStateCheck(): void
    {
        SecretManager::saveOAuthState('some-state');

        $this->expectException(ImporterErrorException::class);
        $this->expectExceptionMessage('missing code, state or session_state');

        $this->oauthClient->exchangeAuthorizationCode('', 'state456', 'session789', 'some-state');
    }
}
