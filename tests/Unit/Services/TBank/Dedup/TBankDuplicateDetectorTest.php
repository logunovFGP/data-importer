<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TBank\Dedup;

use App\Services\TBank\Dedup\TBankDuplicateDetector;
use Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
final class TBankDuplicateDetectorTest extends TestCase
{
    private function createDetector(): TBankDuplicateDetector
    {
        return new TBankDuplicateDetector(null, false);
    }

    public function testSourceName(): void
    {
        $detector = $this->createDetector();
        $this->assertSame('tbank', $detector->sourceName());
    }

    public function testExtractKeyWithOperationId(): void
    {
        $detector = $this->createDetector();
        $result   = $detector->extractKey(['external_id' => 'op-123456789']);

        $this->assertSame('op-123456789', $result);
    }

    public function testExtractKeyWithMd5Hash(): void
    {
        $detector = $this->createDetector();
        $hash     = md5('account|100.50|RUB|2026-03-30|Coffee|Starbucks');
        $result   = $detector->extractKey(['external_id' => $hash]);

        $this->assertSame($hash, $result);
    }

    public function testExtractKeyTrimsWhitespace(): void
    {
        $detector = $this->createDetector();
        $result   = $detector->extractKey(['external_id' => '  op-123  ']);

        $this->assertSame('op-123', $result);
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

        $this->assertSame([], $detector->extractLegacyKeys(['external_id' => 'op-123']));
    }
}
