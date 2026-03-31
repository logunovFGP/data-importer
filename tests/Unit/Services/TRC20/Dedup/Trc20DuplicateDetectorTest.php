<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TRC20\Dedup;

use App\Services\TRC20\Dedup\Trc20DuplicateDetector;
use Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
final class Trc20DuplicateDetectorTest extends TestCase
{
    private function createDetector(): Trc20DuplicateDetector
    {
        return new Trc20DuplicateDetector(null, false);
    }

    public function testSourceName(): void
    {
        $detector = $this->createDetector();
        $this->assertSame('trc20', $detector->sourceName());
    }

    public function testExtractKeyNewFormatIn(): void
    {
        $detector = $this->createDetector();
        $result   = $detector->extractKey(['external_id' => 'trc20|in|abc123def456']);

        $this->assertSame('trc20|in|abc123def456', $result);
    }

    public function testExtractKeyNewFormatOut(): void
    {
        $detector = $this->createDetector();
        $result   = $detector->extractKey(['external_id' => 'trc20|out|xyz789']);

        $this->assertSame('trc20|out|xyz789', $result);
    }

    public function testExtractKeyOldFormatPassthrough(): void
    {
        $detector = $this->createDetector();
        $result   = $detector->extractKey(['external_id' => 'trc20|TAddr123|abc123']);

        $this->assertSame('trc20|TAddr123|abc123', $result);
    }

    public function testEmptyExternalIdReturnsNull(): void
    {
        $detector = $this->createDetector();

        $this->assertNull($detector->extractKey(['external_id' => '']));
        $this->assertNull($detector->extractKey([]));
        $this->assertNull($detector->extractKey(['external_id' => '   ']));
    }

    public function testLegacyKeysGeneratedForNewFormat(): void
    {
        $detector = $this->createDetector();
        $detector->setWallets(['TWallet1', 'TWallet2']);

        $legacyKeys = $detector->extractLegacyKeys(['external_id' => 'trc20|in|txHashABC']);

        $this->assertCount(2, $legacyKeys);
        $this->assertContains('trc20|TWallet1|txHashABC', $legacyKeys);
        $this->assertContains('trc20|TWallet2|txHashABC', $legacyKeys);
    }

    public function testLegacyKeysGeneratedForOutDirection(): void
    {
        $detector = $this->createDetector();
        $detector->setWallets(['TWalletX']);

        $legacyKeys = $detector->extractLegacyKeys(['external_id' => 'trc20|out|txHash999']);

        $this->assertCount(1, $legacyKeys);
        $this->assertSame('trc20|TWalletX|txHash999', $legacyKeys[0]);
    }

    public function testLegacyKeysEmptyForOldFormat(): void
    {
        $detector = $this->createDetector();
        $detector->setWallets(['TWallet1']);

        // Old format: trc20|walletAddress|txHash -- direction is neither 'in' nor 'out'
        $legacyKeys = $detector->extractLegacyKeys(['external_id' => 'trc20|TAddr123|abc123']);

        $this->assertSame([], $legacyKeys);
    }

    public function testLegacyKeysEmptyWhenNoWalletsConfigured(): void
    {
        $detector = $this->createDetector();
        // No wallets set

        $legacyKeys = $detector->extractLegacyKeys(['external_id' => 'trc20|in|txHash']);

        $this->assertSame([], $legacyKeys);
    }

    public function testLegacyKeysEmptyForEmptyExternalId(): void
    {
        $detector = $this->createDetector();
        $detector->setWallets(['TWallet1']);

        $this->assertSame([], $detector->extractLegacyKeys(['external_id' => '']));
        $this->assertSame([], $detector->extractLegacyKeys([]));
    }

    public function testLegacyKeysEmptyForNonTrc20Prefix(): void
    {
        $detector = $this->createDetector();
        $detector->setWallets(['TWallet1']);

        $this->assertSame([], $detector->extractLegacyKeys(['external_id' => 'nordigen|in|abc']));
    }
}
