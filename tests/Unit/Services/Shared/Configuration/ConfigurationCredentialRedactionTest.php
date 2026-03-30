<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shared\Configuration;

use App\Services\Shared\Configuration\Configuration;
use Tests\TestCase;

/**
 * Tests for Fix #11: Configuration.toExportArray() must redact credential fields.
 *
 * Verifies that:
 * 1. toExportArray() removes all credential keys from the output.
 * 2. toExportArray() preserves all non-credential fields.
 * 3. toArray() still includes credential fields (internal use is unchanged).
 *
 * @internal
 * @coversNothing
 */
final class ConfigurationCredentialRedactionTest extends TestCase
{
    /**
     * Credential keys that must be absent from exported configuration.
     */
    private const array CREDENTIAL_KEYS = [
        'basis_bank_login',
        'basis_bank_password',
        'basis_bank_session_artifact',
        't_bank_api_token',
        'trc20_api_key',
        'lunch_flow_api_key',
        'access_token',
    ];

    private function buildConfigurationWithCredentials(): Configuration
    {
        return Configuration::fromArray([
            'flow'                       => 'nordigen',
            'headers'                    => true,
            'date'                       => 'Y-m-d',
            'default_account'            => 1,
            'rules'                      => true,
            'basis_bank_login'           => 'secret-user@example.com',
            'basis_bank_password'        => 'super-secret-password-123',
            'basis_bank_session_artifact' => 'encrypted-session-blob-abc',
            't_bank_api_token'           => 'tbank-token-xyz',
            'trc20_api_key'              => 'trc20-key-456',
            'lunch_flow_api_key'         => 'lunch-flow-key-789',
            'access_token'               => 'simplefin-access-token-000',
            'nordigen_country'           => 'GE',
            'nordigen_bank'              => 'BASISBANK_BSGEGE22',
        ]);
    }

    public function testToExportArrayRemovesAllCredentialKeys(): void
    {
        $config = $this->buildConfigurationWithCredentials();
        $exported = $config->toExportArray();

        foreach (self::CREDENTIAL_KEYS as $key) {
            $this->assertArrayNotHasKey(
                $key,
                $exported,
                sprintf('Credential key "%s" must not appear in toExportArray() output.', $key)
            );
        }
    }

    public function testToExportArrayPreservesNonCredentialFields(): void
    {
        $config = $this->buildConfigurationWithCredentials();
        $exported = $config->toExportArray();

        // These non-credential fields must survive the redaction.
        $this->assertSame('nordigen', $exported['flow']);
        $this->assertTrue($exported['headers']);
        $this->assertSame('Y-m-d', $exported['date']);
        $this->assertSame(1, $exported['default_account']);
        $this->assertTrue($exported['rules']);
        $this->assertSame('GE', $exported['nordigen_country']);
        $this->assertSame('BASISBANK_BSGEGE22', $exported['nordigen_bank']);
    }

    public function testToArrayStillIncludesCredentialFields(): void
    {
        $config = $this->buildConfigurationWithCredentials();
        $full = $config->toArray();

        // toArray() is for internal use and MUST still contain credentials.
        $this->assertSame('secret-user@example.com', $full['basis_bank_login']);
        $this->assertSame('super-secret-password-123', $full['basis_bank_password']);
        $this->assertSame('encrypted-session-blob-abc', $full['basis_bank_session_artifact']);
        $this->assertSame('tbank-token-xyz', $full['t_bank_api_token']);
        $this->assertSame('trc20-key-456', $full['trc20_api_key']);
        $this->assertSame('lunch-flow-key-789', $full['lunch_flow_api_key']);
        $this->assertSame('simplefin-access-token-000', $full['access_token']);
    }

    public function testToExportArrayAndToArrayHaveCorrectKeyDifference(): void
    {
        $config = $this->buildConfigurationWithCredentials();
        $full = $config->toArray();
        $exported = $config->toExportArray();

        // The difference between toArray() and toExportArray() must be exactly the credential keys.
        $missingKeys = array_diff(array_keys($full), array_keys($exported));
        sort($missingKeys);

        $expectedRedacted = self::CREDENTIAL_KEYS;
        sort($expectedRedacted);

        $this->assertSame($expectedRedacted, $missingKeys);
    }
}
