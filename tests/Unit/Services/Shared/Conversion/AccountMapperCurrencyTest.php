<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shared\Conversion;

use App\Services\Shared\Support\CurrencyCode;
use Tests\TestCase;

/**
 * Test that AccountMapper's currency normalization now delegates to CurrencyCode::normalizeOrEmpty().
 * Since the private method was removed, we test the shared utility directly.
 */
final class AccountMapperCurrencyTest extends TestCase
{
    public function testNormalizeValidCurrency(): void
    {
        $this->assertSame('USD', CurrencyCode::normalizeOrEmpty('usd'));
        $this->assertSame('EUR', CurrencyCode::normalizeOrEmpty('eur'));
        $this->assertSame('GBP', CurrencyCode::normalizeOrEmpty('  gbp  '));
    }

    public function testNormalizeEmptyReturnsEmpty(): void
    {
        $this->assertSame('', CurrencyCode::normalizeOrEmpty(''));
        $this->assertSame('', CurrencyCode::normalizeOrEmpty(null));
        $this->assertSame('', CurrencyCode::normalizeOrEmpty('  '));
    }

    public function testNormalizeNumericCode(): void
    {
        $this->assertSame('USD', CurrencyCode::normalizeOrEmpty('840'));
        $this->assertSame('EUR', CurrencyCode::normalizeOrEmpty('978'));
    }

    public function testNormalizeCryptoSymbols(): void
    {
        $this->assertSame('USDT', CurrencyCode::normalizeOrEmpty('USDT'));
        $this->assertSame('WTRX', CurrencyCode::normalizeOrEmpty('wtrx'));
    }

    public function testInvalidCodeReturnsEmpty(): void
    {
        // Too short
        $this->assertSame('', CurrencyCode::normalizeOrEmpty('AB'));
        // Too long
        $this->assertSame('', CurrencyCode::normalizeOrEmpty('ABCDEF'));
        // Contains digits (not a known numeric code)
        $this->assertSame('', CurrencyCode::normalizeOrEmpty('US1'));
    }
}
