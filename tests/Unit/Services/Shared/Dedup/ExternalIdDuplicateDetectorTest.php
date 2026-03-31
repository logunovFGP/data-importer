<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shared\Dedup;

use App\Services\Shared\Dedup\ExternalIdDuplicateDetector;
use Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
final class ExternalIdDuplicateDetectorTest extends TestCase
{
    public function testSourceNameFromConstructor(): void
    {
        $detector = new ExternalIdDuplicateDetector(null, false, 'simplefin');
        $this->assertSame('simplefin', $detector->sourceName());
    }

    public function testSourceNameDefaultsToUnknown(): void
    {
        $detector = new ExternalIdDuplicateDetector(null, false);
        $this->assertSame('unknown', $detector->sourceName());
    }

    public function testExtractKeyWithExternalId(): void
    {
        $detector = new ExternalIdDuplicateDetector(null, false, 'spectre');
        $result   = $detector->extractKey(['external_id' => 'spectre-txn-abc']);

        $this->assertSame('spectre-txn-abc', $result);
    }

    public function testExtractKeyTrimsWhitespace(): void
    {
        $detector = new ExternalIdDuplicateDetector(null, false, 'csv');
        $result   = $detector->extractKey(['external_id' => '  csv-line-42  ']);

        $this->assertSame('csv-line-42', $result);
    }

    public function testExtractKeyEmptyReturnsNull(): void
    {
        $detector = new ExternalIdDuplicateDetector(null, false, 'csv');

        $this->assertNull($detector->extractKey(['external_id' => '']));
        $this->assertNull($detector->extractKey([]));
        $this->assertNull($detector->extractKey(['external_id' => '   ']));
    }

    public function testLegacyKeysDefaultEmpty(): void
    {
        $detector = new ExternalIdDuplicateDetector(null, false, 'sophtron');

        $this->assertSame([], $detector->extractLegacyKeys(['external_id' => 'soph-123']));
    }
}
