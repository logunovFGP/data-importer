<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TRC20\Conversion;

use App\Services\TRC20\Conversion\Routine\TransactionProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tests for Fix #1: Normalize amount strings for stable Firefly III import_hash_v2.
 *
 * The bcmath refactor changed amount format from "123.45" to "123.450000000000".
 * Since Firefly III hashes the entire payload (including amount string) for dedup,
 * different string representations produce different hashes and cause duplicates.
 *
 * @internal
 * @coversNothing
 */
final class AmountNormalizationTest extends TestCase
{
    #[DataProvider('amountNormalizationProvider')]
    public function testNormalizeAmountForStableHash(string $input, string $expected): void
    {
        $result = TransactionProcessor::normalizeAmountForStableHash($input);
        $this->assertSame($expected, $result);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function amountNormalizationProvider(): array
    {
        return [
            'trailing zeros stripped'           => ['123.450000000000', '123.45'],
            'all fractional zeros stripped'      => ['1.000000000000', '1'],
            'negative trailing zeros stripped'   => ['-0.500000000000', '-0.5'],
            'no dot unchanged'                  => ['100', '100'],
            'zero unchanged'                    => ['0', '0'],
            'zero with dot'                     => ['0.000000000000', '0'],
            'negative zero'                     => ['-0.000000000000', '0'],
            'already clean decimal'             => ['42.5', '42.5'],
            'single trailing zero'              => ['10.10', '10.1'],
            'large number no trailing zeros'    => ['999999999.123456', '999999999.123456'],
            'negative integer via dot'          => ['-100.00', '-100'],
            'just a dot and zeros'              => ['0.0', '0'],
        ];
    }
}
