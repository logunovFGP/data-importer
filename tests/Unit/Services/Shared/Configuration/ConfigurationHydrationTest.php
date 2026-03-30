<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shared\Configuration;

use App\Services\Shared\Configuration\Configuration;
use Tests\TestCase;

final class ConfigurationHydrationTest extends TestCase
{
    public function testFromArrayHydratesProviderCredentials(): void
    {
        $data = [
            'lunch_flow_api_key'          => 'lf-key-123',
            'basis_bank_login'            => 'bb-user',
            'basis_bank_password'         => 'bb-pass',
            'basis_bank_auth_state'       => 'authenticated',
            'basis_bank_session_artifact' => 'session-abc',
            'basis_bank_request_sms_code' => true,
            'basis_bank_trust_device'     => true,
            't_bank_api_token'            => 'tb-token',
            'trc20_api_key'               => 'trc-key',
            'trc20_wallets'               => 'TWallet1,TWallet2',
            'incremental_sync_enabled'    => true,
            'incremental_lookback_days'   => 7,
        ];

        $config = Configuration::fromArray($data);

        $this->assertSame('lf-key-123', $config->getLunchFlowApiKey());
        $this->assertSame('bb-user', $config->getBasisBankLogin());
        $this->assertSame('bb-pass', $config->getBasisBankPassword());
        $this->assertSame('authenticated', $config->getBasisBankAuthState());
        $this->assertSame('session-abc', $config->getBasisBankSessionArtifact());
        $this->assertTrue($config->isBasisBankRequestSmsCode());
        $this->assertTrue($config->isBasisBankTrustDevice());
        $this->assertSame('tb-token', $config->getTBankApiToken());
        $this->assertSame('trc-key', $config->getTrc20ApiKey());
        $this->assertSame('TWallet1,TWallet2', $config->getTrc20Wallets());
        $this->assertTrue($config->isIncrementalSyncEnabled());
        $this->assertSame(7, $config->getIncrementalLookbackDays());
    }

    public function testFromArrayWithLegacyBasisBankKeys(): void
    {
        $data = [
            'basis_bank_api_token'  => 'legacy-login',
            'basis_bank_consent_id' => 'legacy-consent',
        ];

        $config = Configuration::fromArray($data);

        // Legacy keys should be mapped to login/password
        $this->assertSame('legacy-login', $config->getBasisBankLogin());
        $this->assertSame('legacy-consent', $config->getBasisBankPassword());
    }

    public function testFromArrayDefaultCredentials(): void
    {
        $config = Configuration::fromArray([]);

        $this->assertSame('', $config->getLunchFlowApiKey());
        $this->assertSame('', $config->getBasisBankLogin());
        $this->assertSame('', $config->getBasisBankPassword());
        $this->assertSame('', $config->getBasisBankAuthState());
        $this->assertSame('', $config->getBasisBankSessionArtifact());
        $this->assertFalse($config->isBasisBankRequestSmsCode());
        $this->assertFalse($config->isBasisBankTrustDevice());
        $this->assertSame('', $config->getTBankApiToken());
        $this->assertSame('', $config->getTrc20ApiKey());
        $this->assertSame('', $config->getTrc20Wallets());
        $this->assertFalse($config->isIncrementalSyncEnabled());
        $this->assertSame(3, $config->getIncrementalLookbackDays());
    }
}
