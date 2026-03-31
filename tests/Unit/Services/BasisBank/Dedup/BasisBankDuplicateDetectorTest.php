<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BasisBank\Dedup;

use App\Services\BasisBank\Dedup\BasisBankDuplicateDetector;
use Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
final class BasisBankDuplicateDetectorTest extends TestCase
{
    private function createDetector(): BasisBankDuplicateDetector
    {
        return new BasisBankDuplicateDetector(null, false);
    }

    public function testSourceName(): void
    {
        $detector = $this->createDetector();
        $this->assertSame('basisbank', $detector->sourceName());
    }

    public function testExtractKeyWithExternalId(): void
    {
        $detector = $this->createDetector();
        $result   = $detector->extractKey(['external_id' => 'bb-txn-12345']);

        $this->assertSame('bb-txn-12345', $result);
    }

    public function testExtractKeyTrimsWhitespace(): void
    {
        $detector = $this->createDetector();
        $result   = $detector->extractKey(['external_id' => '  bb-txn-12345  ']);

        $this->assertSame('bb-txn-12345', $result);
    }

    public function testExtractKeyEmptyReturnsNull(): void
    {
        $detector = $this->createDetector();

        $this->assertNull($detector->extractKey(['external_id' => '']));
        $this->assertNull($detector->extractKey([]));
        $this->assertNull($detector->extractKey(['external_id' => '   ']));
    }

    public function testLegacyKeysDefaultEmpty(): void
    {
        $detector = $this->createDetector();

        $this->assertSame([], $detector->extractLegacyKeys(['external_id' => 'bb-txn-12345']));
    }
}
