<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BasisBank\Authentication;

use App\Services\BasisBank\Authentication\SecretManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Override;
use Tests\TestCase;

/**
 * Verifies that BasisBank credentials are encrypted when stored in the PHP session
 * and decrypted transparently when read back. Also verifies legacy plaintext
 * fallback for sessions that pre-date the encryption change.
 *
 * @internal
 *
 * @coversNothing
 */
final class SecretManagerEncryptionTest extends TestCase
{
    /** Session keys that are sensitive strings (not booleans). */
    private const array SENSITIVE_SESSION_KEYS = [
        SecretManager::LOGIN,
        SecretManager::API_TOKEN,
        SecretManager::PASSWORD,
        SecretManager::CONSENT_ID,
        SecretManager::OTP_CODE,
        SecretManager::AUTH_STATE,
        SecretManager::SESSION_ARTIFACT,
    ];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        // Ensure clean state: clear both session and persisted store before each test.
        SecretManager::clearSavedCredentials();
    }

    #[Override]
    protected function tearDown(): void
    {
        SecretManager::clearSavedCredentials();
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Login / API Token
    // -----------------------------------------------------------------------

    public function testSaveLoginEncryptsSessionValue(): void
    {
        $plaintext = 'my-bank-username';

        SecretManager::saveLogin($plaintext);

        $rawLogin    = Session::get(SecretManager::LOGIN);
        $rawApiToken = Session::get(SecretManager::API_TOKEN);

        // Raw session value must NOT be the plaintext credential.
        $this->assertNotEquals($plaintext, $rawLogin, 'Login stored as plaintext in session');
        $this->assertNotEquals($plaintext, $rawApiToken, 'API token stored as plaintext in session');

        // But must be decryptable back to the original value.
        $this->assertSame($plaintext, Crypt::decryptString($rawLogin));
        $this->assertSame($plaintext, Crypt::decryptString($rawApiToken));
    }

    public function testGetLoginDecryptsSessionValue(): void
    {
        $plaintext = 'user@bank.ge';

        SecretManager::saveLogin($plaintext);

        $this->assertSame($plaintext, SecretManager::getLogin());
    }

    public function testGetLoginHandlesLegacyPlaintextInSession(): void
    {
        $plaintext = 'legacy-login-value';

        // Simulate a session that was written before encryption was added.
        Session::put(SecretManager::LOGIN, $plaintext);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $msg): bool => str_contains($msg, 'legacy plaintext fallback'));

        $this->assertSame($plaintext, SecretManager::getLogin());
    }

    // -----------------------------------------------------------------------
    // Password / Consent ID
    // -----------------------------------------------------------------------

    public function testSavePasswordEncryptsSessionValue(): void
    {
        $plaintext = 'S3cret!Pass';

        SecretManager::savePassword($plaintext);

        $rawPassword  = Session::get(SecretManager::PASSWORD);
        $rawConsentId = Session::get(SecretManager::CONSENT_ID);

        $this->assertNotEquals($plaintext, $rawPassword, 'Password stored as plaintext in session');
        $this->assertNotEquals($plaintext, $rawConsentId, 'Consent ID stored as plaintext in session');

        $this->assertSame($plaintext, Crypt::decryptString($rawPassword));
        $this->assertSame($plaintext, Crypt::decryptString($rawConsentId));
    }

    public function testGetPasswordDecryptsSessionValue(): void
    {
        $plaintext = 'hunter2';

        SecretManager::savePassword($plaintext);

        $this->assertSame($plaintext, SecretManager::getPassword());
    }

    public function testGetPasswordHandlesLegacyPlaintextInSession(): void
    {
        $plaintext = 'legacy-password';

        Session::put(SecretManager::PASSWORD, $plaintext);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $msg): bool => str_contains($msg, 'legacy plaintext fallback'));

        $this->assertSame($plaintext, SecretManager::getPassword());
    }

    // -----------------------------------------------------------------------
    // OTP Code
    // -----------------------------------------------------------------------

    public function testSaveOtpCodeEncryptsSessionValue(): void
    {
        $plaintext = '123456';

        SecretManager::saveOtpCode($plaintext);

        $rawOtp = Session::get(SecretManager::OTP_CODE);

        $this->assertNotEquals($plaintext, $rawOtp, 'OTP code stored as plaintext in session');
        $this->assertSame($plaintext, Crypt::decryptString($rawOtp));
    }

    public function testGetOtpCodeDecryptsSessionValue(): void
    {
        $plaintext = '789012';

        SecretManager::saveOtpCode($plaintext);

        $this->assertSame($plaintext, SecretManager::getOtpCode());
    }

    public function testGetOtpCodeHandlesLegacyPlaintextInSession(): void
    {
        $plaintext = '999999';

        Session::put(SecretManager::OTP_CODE, $plaintext);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $msg): bool => str_contains($msg, 'legacy plaintext fallback'));

        $this->assertSame($plaintext, SecretManager::getOtpCode());
    }

    // -----------------------------------------------------------------------
    // Auth State
    // -----------------------------------------------------------------------

    public function testSaveAuthStateEncryptsSessionValue(): void
    {
        $plaintext = 'otp_required';

        SecretManager::saveAuthState($plaintext);

        $rawState = Session::get(SecretManager::AUTH_STATE);

        $this->assertNotEquals($plaintext, $rawState, 'Auth state stored as plaintext in session');
        $this->assertSame($plaintext, Crypt::decryptString($rawState));
    }

    public function testGetAuthStateDecryptsSessionValue(): void
    {
        $plaintext = 'authenticated';

        SecretManager::saveAuthState($plaintext);

        $this->assertSame($plaintext, SecretManager::getAuthState());
    }

    public function testGetAuthStateHandlesLegacyPlaintextInSession(): void
    {
        $plaintext = 'legacy-state';

        Session::put(SecretManager::AUTH_STATE, $plaintext);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $msg): bool => str_contains($msg, 'legacy plaintext fallback'));

        $this->assertSame($plaintext, SecretManager::getAuthState());
    }

    // -----------------------------------------------------------------------
    // Session Artifact
    // -----------------------------------------------------------------------

    public function testSaveSessionArtifactEncryptsSessionValue(): void
    {
        $plaintext = 'ASP.NET_SessionId=abc123xyz';

        SecretManager::saveSessionArtifact($plaintext);

        $rawArtifact = Session::get(SecretManager::SESSION_ARTIFACT);

        $this->assertNotEquals($plaintext, $rawArtifact, 'Session artifact stored as plaintext in session');
        $this->assertSame($plaintext, Crypt::decryptString($rawArtifact));
    }

    public function testGetSessionArtifactDecryptsSessionValue(): void
    {
        $plaintext = 'cookie=value; path=/';

        SecretManager::saveSessionArtifact($plaintext);

        $this->assertSame($plaintext, SecretManager::getSessionArtifact());
    }

    public function testGetSessionArtifactHandlesLegacyPlaintextInSession(): void
    {
        $plaintext = 'legacy-cookie-data';

        Session::put(SecretManager::SESSION_ARTIFACT, $plaintext);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $msg): bool => str_contains($msg, 'legacy plaintext fallback'));

        $this->assertSame($plaintext, SecretManager::getSessionArtifact());
    }

    // -----------------------------------------------------------------------
    // Boolean flags must NOT be encrypted (no change expected)
    // -----------------------------------------------------------------------

    public function testBooleanFlagsAreNotEncrypted(): void
    {
        SecretManager::saveRequestSmsCode(true);
        SecretManager::saveTrustDevice(false);

        // Boolean flags are stored as native PHP booleans, not encrypted strings.
        $this->assertTrue(Session::get(SecretManager::REQUEST_SMS_CODE));
        $this->assertFalse(Session::get(SecretManager::TRUST_DEVICE));
    }

    // -----------------------------------------------------------------------
    // Round-trip: save then read via public alias methods
    // -----------------------------------------------------------------------

    public function testApiTokenAliasRoundTrip(): void
    {
        $plaintext = 'api-token-value';

        SecretManager::saveApiToken($plaintext);

        $this->assertSame($plaintext, SecretManager::getApiToken());
        // Raw session value must be encrypted, not plaintext.
        $this->assertNotEquals($plaintext, Session::get(SecretManager::LOGIN));
    }

    public function testConsentIdAliasRoundTrip(): void
    {
        $plaintext = 'consent-id-value';

        SecretManager::saveConsentId($plaintext);

        $this->assertSame($plaintext, SecretManager::getConsentId());
        $this->assertNotEquals($plaintext, Session::get(SecretManager::PASSWORD));
    }

    // -----------------------------------------------------------------------
    // Edge case: empty string should not be encrypted/decrypted
    // -----------------------------------------------------------------------

    public function testEmptyStringReturnsEmptyWithoutDecryption(): void
    {
        // When no credential is stored, getters should return '' without
        // attempting decryption. Config fallbacks also return '' in test env.
        $this->assertSame('', SecretManager::getOtpCode());
        $this->assertSame('', SecretManager::getAuthState());
        $this->assertSame('', SecretManager::getSessionArtifact());
    }
}
