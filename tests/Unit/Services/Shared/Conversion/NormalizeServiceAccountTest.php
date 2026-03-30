<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shared\Conversion;

use App\Services\Shared\Model\ImportServiceAccount;
use Tests\TestCase;

/**
 * Test the consolidated normalizeToArray() method on ImportServiceAccount.
 */
final class NormalizeServiceAccountTest extends TestCase
{
    public function testArrayWithCurrencyCodeKey(): void
    {
        $input = [
            'id'            => 'acct-1',
            'name'          => 'My Wallet',
            'currency_code' => 'USDT',
            'status'        => 'active',
        ];

        $result = ImportServiceAccount::normalizeToArray($input);

        $this->assertSame('acct-1', $result['id']);
        $this->assertSame('My Wallet', $result['name']);
        $this->assertSame('USDT', $result['currency_code']);
        $this->assertSame('', $result['iban']);
        $this->assertSame('', $result['bban']);
        $this->assertSame('active', $result['status']);
        $this->assertSame([], $result['extra']);
    }

    public function testArrayWithCurrencyKey(): void
    {
        $input = [
            'id'       => 'acct-2',
            'name'     => 'Other Wallet',
            'currency' => 'TRX',
        ];

        $result = ImportServiceAccount::normalizeToArray($input);

        // Without 'currency_code' key, falls through to generic path
        $this->assertSame('acct-2', $result['id']);
        $this->assertSame('Other Wallet', $result['name']);
        $this->assertSame('TRX', $result['currency_code']);
        $this->assertSame('active', $result['status']);
    }

    public function testEmptyArrayReturnsDefaults(): void
    {
        $result = ImportServiceAccount::normalizeToArray([]);

        $this->assertSame('', $result['id']);
        $this->assertSame('', $result['name']);
        $this->assertSame('', $result['currency_code']);
        $this->assertSame('', $result['iban']);
        $this->assertSame('', $result['bban']);
        $this->assertSame('active', $result['status']);
        $this->assertSame([], $result['extra']);
    }

    public function testCurrencyCodeTakesPrecedenceOverCurrency(): void
    {
        $input = [
            'id'            => 'acct-3',
            'name'          => 'Dual Keys',
            'currency_code' => 'USDT',
            'currency'      => 'TRX',
        ];

        $result = ImportServiceAccount::normalizeToArray($input);

        // currency_code key is present, so the shortcut path is used
        $this->assertSame('USDT', $result['currency_code']);
    }

    public function testMissingStatusDefaultsToActive(): void
    {
        $input = [
            'id'   => 'acct-4',
            'name' => 'No Status',
        ];

        $result = ImportServiceAccount::normalizeToArray($input);

        $this->assertSame('active', $result['status']);
    }
}
