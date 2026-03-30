<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Camt\Conversion;

use App\Services\Camt\Conversion\CamtAccountTypeResolver;
use Tests\TestCase;

/**
 * Tests for Fix #36: Verify that getAccountType logic extracted into
 * CamtAccountTypeResolver works correctly.
 *
 * @internal
 * @coversNothing
 */
final class TransactionMapperAccountTypeTest extends TestCase
{
    private function makeAccount(string $iban, string $type, int $id = 1): object
    {
        return (object) [
            'id'     => $id,
            'iban'   => $iban,
            'number' => '',
            'name'   => '',
            'type'   => $type,
        ];
    }

    public function testResolvesAssetAccountByIban(): void
    {
        $resolver = new CamtAccountTypeResolver();
        $accounts = [$this->makeAccount('NL02ABNA0123456789', 'asset')];

        $result = $resolver->resolve($accounts, 'iban', 'NL02ABNA0123456789', true);

        $this->assertSame('asset', $result);
    }

    public function testReturnsNullWhenNoMatch(): void
    {
        $resolver = new CamtAccountTypeResolver();
        $accounts = [$this->makeAccount('NL02ABNA0123456789', 'asset')];

        $result = $resolver->resolve($accounts, 'iban', 'DE89370400440532013000', true);

        $this->assertNull($result);
    }

    public function testResolvesExpenseWhenAmountNegativeAndBothTypes(): void
    {
        $resolver = new CamtAccountTypeResolver();
        $accounts = [
            $this->makeAccount('NL02ABNA0123456789', 'revenue', 1),
            $this->makeAccount('NL02ABNA0123456789', 'expense', 2),
        ];

        $result = $resolver->resolve($accounts, 'iban', 'NL02ABNA0123456789', true);

        $this->assertSame('expense', $result);
    }

    public function testResolvesRevenueWhenAmountPositiveAndBothTypes(): void
    {
        $resolver = new CamtAccountTypeResolver();
        $accounts = [
            $this->makeAccount('NL02ABNA0123456789', 'revenue', 1),
            $this->makeAccount('NL02ABNA0123456789', 'expense', 2),
        ];

        $result = $resolver->resolve($accounts, 'iban', 'NL02ABNA0123456789', false);

        $this->assertSame('revenue', $result);
    }

    public function testFirstMatchTrumpsWhenSameType(): void
    {
        $resolver = new CamtAccountTypeResolver();
        $accounts = [
            $this->makeAccount('NL02ABNA0123456789', 'asset', 1),
            $this->makeAccount('NL02ABNA0123456789', 'asset', 2),
        ];

        $result = $resolver->resolve($accounts, 'iban', 'NL02ABNA0123456789', true);

        $this->assertSame('asset', $result);
    }
}
