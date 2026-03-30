<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TRC20;

use Tests\TestCase;

/**
 * Tests for Fix #3: TRC20 dateTo boundary bug.
 *
 * Verifies that toMillisecondTimestamp for dateTo includes the full last day (23:59:59),
 * not just midnight (00:00:00) which would drop all transactions on that date.
 *
 * @internal
 * @coversNothing
 */
final class GetTransactionsDateBoundaryTest extends TestCase
{
    /**
     * Invoke the private buildQuery method via reflection.
     */
    private function callBuildQuery(?string $dateFrom, ?string $dateTo, ?string $fingerprint): array
    {
        $request = new \App\Services\TRC20\Request\GetTransactionsRequest(
            'test-api-key',
            ['TTEST0000000000000000000000000000001'],
            200
        );

        $reflection = new \ReflectionMethod($request, 'buildQuery');
        $reflection->setAccessible(true);

        return $reflection->invoke($request, $dateFrom, $dateTo, $fingerprint);
    }

    /**
     * Invoke the private toMillisecondTimestamp method via reflection.
     */
    private function callToMillisecondTimestamp(?string $value): ?int
    {
        $request = new \App\Services\TRC20\Request\GetTransactionsRequest(
            'test-api-key',
            ['TTEST0000000000000000000000000000001'],
            200
        );

        $reflection = new \ReflectionMethod($request, 'toMillisecondTimestamp');
        $reflection->setAccessible(true);

        return $reflection->invoke($request, $value);
    }

    public function testDateToIncludesFullDay(): void
    {
        $query = $this->callBuildQuery(null, '2025-10-16', null);

        // 2025-10-16 23:59:59 in UTC = 1760831999 seconds * 1000 = 1760831999000 ms
        $expectedTimestamp = strtotime('2025-10-16 23:59:59') * 1000;
        $this->assertArrayHasKey('max_timestamp', $query);
        $this->assertSame($expectedTimestamp, $query['max_timestamp']);
    }

    public function testDateToAtMidnightWouldBeTooEarly(): void
    {
        // Verify the fix: dateTo should NOT resolve to midnight start-of-day
        $midnightTimestamp = strtotime('2025-10-16') * 1000;
        $endOfDayTimestamp = strtotime('2025-10-16 23:59:59') * 1000;

        $query = $this->callBuildQuery(null, '2025-10-16', null);

        // The max_timestamp must be end-of-day, not midnight
        $this->assertNotSame($midnightTimestamp, $query['max_timestamp']);
        $this->assertSame($endOfDayTimestamp, $query['max_timestamp']);
    }

    public function testDateFromIsNotModified(): void
    {
        // dateFrom should still resolve to midnight (start of day), not end of day
        $query = $this->callBuildQuery('2025-10-01', '2025-10-16', null);

        $expectedFromTimestamp = strtotime('2025-10-01') * 1000;
        $this->assertSame($expectedFromTimestamp, $query['min_timestamp']);
    }

    public function testNullDateToProducesNoMaxTimestamp(): void
    {
        $query = $this->callBuildQuery('2025-10-01', null, null);
        $this->assertArrayNotHasKey('max_timestamp', $query);
    }

    public function testToMillisecondTimestampReturnsNullForNull(): void
    {
        $this->assertNull($this->callToMillisecondTimestamp(null));
    }

    public function testToMillisecondTimestampReturnsNullForEmpty(): void
    {
        $this->assertNull($this->callToMillisecondTimestamp(''));
    }
}
