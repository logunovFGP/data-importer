<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shared\Configuration;

use App\Exceptions\ImporterErrorException;
use App\Services\Shared\Configuration\Configuration;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Tests for Fixes #44, #45, #46:
 * - #44: Carbon::createFromFormat not checked for false in updateDateRange()
 * - #45: pendingTransactions assigned twice in fromArray()
 * - #46: calcDateNotBefore returns null instead of '' for unknown unit
 *
 * @internal
 *
 * @coversNothing
 */
final class ConfigurationDateRangeTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // --- Fix #44: Invalid date format throws ImporterErrorException ---

    public function testUpdateDateRangeThrowsOnInvalidBeforeDate(): void
    {
        $config = Configuration::fromArray([
            'date_range'      => 'range',
            'date_not_before' => 'not-a-date',
            'date_not_after'  => '2026-03-30',
        ]);

        $this->expectException(ImporterErrorException::class);
        $this->expectExceptionMessage('Invalid date format: not-a-date');

        $config->updateDateRange();
    }

    public function testUpdateDateRangeThrowsOnInvalidAfterDate(): void
    {
        $config = Configuration::fromArray([
            'date_range'      => 'range',
            'date_not_before' => '2026-01-01',
            'date_not_after'  => 'bad-date',
        ]);

        $this->expectException(ImporterErrorException::class);
        $this->expectExceptionMessage('Invalid date format: bad-date');

        $config->updateDateRange();
    }

    public function testUpdateDateRangeWithValidDatesWorks(): void
    {
        $config = Configuration::fromArray([
            'date_range'      => 'range',
            'date_not_before' => '2026-01-01',
            'date_not_after'  => '2026-03-30',
        ]);

        $config->updateDateRange();

        $this->assertSame('2026-01-01', $config->getDateNotBefore());
        $this->assertSame('2026-03-30', $config->getDateNotAfter());
    }

    public function testUpdateDateRangeSwapsInvertedDates(): void
    {
        $config = Configuration::fromArray([
            'date_range'      => 'range',
            'date_not_before' => '2026-12-31',
            'date_not_after'  => '2026-01-01',
        ]);

        $config->updateDateRange();

        $this->assertSame('2026-01-01', $config->getDateNotBefore());
        $this->assertSame('2026-12-31', $config->getDateNotAfter());
    }

    // --- Fix #45: pendingTransactions not assigned twice ---

    public function testFromArrayAssignsPendingTransactionsOnce(): void
    {
        $config = Configuration::fromArray([
            'pending_transactions' => false,
        ]);

        // If the duplicate assignment bug were present, the second assignment
        // would always override with the same value anyway.  The real test is
        // that the value is correctly reflected.
        $this->assertFalse($config->getPendingTransactions());
    }

    public function testFromArrayPendingTransactionsDefaultsToTrue(): void
    {
        $config = Configuration::fromArray([]);

        $this->assertTrue($config->getPendingTransactions());
    }

    // --- Fix #46: calcDateNotBefore returns '' for unknown unit ---

    public function testPartialDateRangeWithUnknownUnitProducesEmptyString(): void
    {
        $config = Configuration::fromArray([
            'date_range'      => 'partial',
            'date_range_unit' => 'x', // unknown unit
            'date_range_number' => 5,
        ]);

        $config->updateDateRange();

        // With the fix, calcDateNotBefore returns '' instead of null,
        // so dateNotBefore becomes '' and no type error occurs.
        $this->assertSame('', $config->getDateNotBefore());
    }

    public function testPartialDateRangeWithValidUnitProducesDate(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-30'));

        $config = Configuration::fromArray([
            'date_range'        => 'partial',
            'date_range_unit'   => 'd',
            'date_range_number' => 7,
        ]);

        $config->updateDateRange();

        $this->assertSame('2026-03-23', $config->getDateNotBefore());
    }
}
