<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Nordigen\Dedup;

use App\Services\Nordigen\Dedup\NordigenDuplicateDetector;
use Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
final class NordigenDuplicateDetectorTest extends TestCase
{
    private function createDetector(): NordigenDuplicateDetector
    {
        return new NordigenDuplicateDetector(null, false);
    }

    public function testSourceName(): void
    {
        $detector = $this->createDetector();
        $this->assertSame('nordigen', $detector->sourceName());
    }

    public function testExtractKeyWithExternalId(): void
    {
        $detector = $this->createDetector();
        $result   = $detector->extractKey(['external_id' => 'IBAN123-txn456']);

        $this->assertSame('IBAN123-txn456', $result);
    }

    public function testExtractKeyTrimsWhitespace(): void
    {
        $detector = $this->createDetector();
        $result   = $detector->extractKey(['external_id' => '  IBAN123-txn456  ']);

        $this->assertSame('IBAN123-txn456', $result);
    }

    public function testExtractKeyEmptyReturnsNull(): void
    {
        $detector = $this->createDetector();

        $this->assertNull($detector->extractKey(['external_id' => '']));
        $this->assertNull($detector->extractKey([]));
        $this->assertNull($detector->extractKey(['external_id' => '   ']));
    }

    public function testLegacyKeysReturnEmptyArray(): void
    {
        $detector = $this->createDetector();

        // Without internal_reference, legacy keys are empty
        $this->assertSame([], $detector->extractLegacyKeys(['external_id' => 'IBAN123-txn456']));

        // Even with internal_reference, legacy keys are empty (best-effort, returns [])
        $this->assertSame([], $detector->extractLegacyKeys([
            'external_id'        => 'IBAN123-txn456',
            'internal_reference' => 'IBAN123',
        ]));
    }
}
