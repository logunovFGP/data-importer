<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shared\SyncState;

use App\Services\Shared\SyncState\SyncStateManager;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Test for Fix #48: SyncStateManager::getIncrementalDateFromCursor mutates the passed Carbon object.
 *
 * @internal
 *
 * @coversNothing
 */
final class SyncStateManagerMutationTest extends TestCase
{
    public function testGetIncrementalDateFromCursorDoesNotMutateCursor(): void
    {
        $manager = new SyncStateManager();

        $cursor   = Carbon::parse('2026-03-15 14:30:00');
        $expected = $cursor->copy();

        $manager->getIncrementalDateFromCursor($cursor, 3);

        $this->assertTrue(
            $expected->eq($cursor),
            sprintf('Original cursor was mutated: expected %s, got %s', $expected->toDateTimeString(), $cursor->toDateTimeString())
        );
    }

    public function testGetIncrementalDateFromCursorWithZeroLookback(): void
    {
        $manager = new SyncStateManager();

        $cursor   = Carbon::parse('2026-03-15 14:30:00');
        $expected = $cursor->copy();

        $result = $manager->getIncrementalDateFromCursor($cursor, 0);

        $this->assertSame('2026-03-15', $result);
        $this->assertTrue(
            $expected->eq($cursor),
            'Original cursor was mutated even with zero lookback.'
        );
    }

    public function testGetIncrementalDateFromCursorWithLookback(): void
    {
        $manager = new SyncStateManager();

        $cursor = Carbon::parse('2026-03-15 14:30:00');
        $result = $manager->getIncrementalDateFromCursor($cursor, 5);

        $this->assertSame('2026-03-10', $result);
    }

    public function testGetIncrementalDateFromCursorWithNullReturnsNull(): void
    {
        $manager = new SyncStateManager();

        $this->assertNull($manager->getIncrementalDateFromCursor(null, 3));
    }
}
