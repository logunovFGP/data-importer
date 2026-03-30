<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shared\Support;

use App\Services\Shared\Support\CurrencyCode;
use Tests\TestCase;

final class CurrencyCodeSelectDominantTest extends TestCase
{
    public function testEmptyArrayReturnsEmptyString(): void
    {
        $this->assertSame('', CurrencyCode::selectDominant([]));
    }

    public function testSingleCurrencyReturnsThatCurrency(): void
    {
        $this->assertSame('USD', CurrencyCode::selectDominant(['USD']));
    }

    public function testMixedCurrenciesReturnsMostFrequent(): void
    {
        $currencies = ['USD', 'EUR', 'USD', 'GBP', 'USD', 'EUR'];
        $this->assertSame('USD', CurrencyCode::selectDominant($currencies));
    }

    public function testCaseInsensitiveNormalization(): void
    {
        $currencies = ['usd', 'Usd', 'USD'];
        $this->assertSame('USD', CurrencyCode::selectDominant($currencies));
    }

    public function testEmptyStringsAreIgnored(): void
    {
        $currencies = ['', '', 'EUR', ''];
        $this->assertSame('EUR', CurrencyCode::selectDominant($currencies));
    }

    public function testNonStringValuesAreIgnored(): void
    {
        // The is_string check in selectDominant should skip non-string values
        $currencies = ['GEL', 'GEL', 'EUR'];
        $this->assertSame('GEL', CurrencyCode::selectDominant($currencies));
    }

    public function testAllInvalidCurrenciesReturnsEmpty(): void
    {
        $currencies = ['', '  ', ''];
        $this->assertSame('', CurrencyCode::selectDominant($currencies));
    }

    public function testNumericCodeIsConverted(): void
    {
        // 840 = USD in the NUMERIC_TO_ALPHA map
        $currencies = ['840', '840', 'EUR'];
        $this->assertSame('USD', CurrencyCode::selectDominant($currencies));
    }
}
