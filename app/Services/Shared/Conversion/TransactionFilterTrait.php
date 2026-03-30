<?php

declare(strict_types=1);

namespace App\Services\Shared\Conversion;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Trait TransactionFilterTrait
 *
 * Shared transaction filtering logic for TransactionProcessor classes across providers.
 * Each provider must define getTransactionPendingStatus() to return the field/method
 * used to determine whether a transaction is pending.
 */
trait TransactionFilterTrait
{
    private const string FILTER_DATE_TIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * Filter transactions based on date range, pending status, and zero-amount check.
     *
     * @param iterable $transactions  The transactions to filter
     * @param bool     $getPending    Whether to include pending transactions
     * @param Carbon|null $notBefore  Earliest allowed date (inclusive)
     * @param Carbon|null $notAfter   Latest allowed date (inclusive)
     *
     * @return array Filtered transactions
     */
    private function filterTransactionSet(
        iterable $transactions,
        bool $getPending,
        ?Carbon $notBefore,
        ?Carbon $notAfter,
    ): array {
        Log::info(sprintf('Going to filter downloaded transactions. Original set length is %d', count($transactions)));

        if ($notBefore instanceof Carbon) {
            Log::info(sprintf('Will not grab transactions before "%s"', $notBefore->format(self::FILTER_DATE_TIME_FORMAT)));
        }
        if ($notAfter instanceof Carbon) {
            Log::info(sprintf('Will not grab transactions after "%s"', $notAfter->format(self::FILTER_DATE_TIME_FORMAT)));
        }

        if ($getPending) {
            Log::info('Will include pending transactions.');
        }
        if (!$getPending) {
            Log::info('Will NOT include pending transactions.');
        }

        $return = [];

        foreach ($transactions as $transaction) {
            $madeOn = $transaction->getDate();

            if (!$getPending && $this->isTransactionPending($transaction)) {
                Log::debug(sprintf('Skip pending transaction made on "%s".', $madeOn->format(self::FILTER_DATE_TIME_FORMAT)));

                continue;
            }

            if ($notBefore instanceof Carbon && $madeOn->lt($notBefore)) {
                Log::debug(sprintf(
                    'Skip transaction because "%s" is before "%s".',
                    $madeOn->format(self::FILTER_DATE_TIME_FORMAT),
                    $notBefore->format(self::FILTER_DATE_TIME_FORMAT)
                ));

                continue;
            }

            if ($notAfter instanceof Carbon && $madeOn->gt($notAfter)) {
                Log::debug(sprintf(
                    'Skip transaction because "%s" is after "%s".',
                    $madeOn->format(self::FILTER_DATE_TIME_FORMAT),
                    $notAfter->format(self::FILTER_DATE_TIME_FORMAT)
                ));

                continue;
            }

            $zeroAmount = $this->getTransactionAmount($transaction);
            if (0 === bccomp('0', $zeroAmount)) {
                $this->addZeroAmountWarning($transaction);
                Log::debug(sprintf('Skip transaction because amount is zero: "%s".', $zeroAmount));

                continue;
            }

            Log::debug(sprintf('Include transaction because date is "%s".', $madeOn->format(self::FILTER_DATE_TIME_FORMAT)));
            $return[] = $transaction;
        }

        Log::info(sprintf('After filtering, set is %d transaction(s)', count($return)));

        return $return;
    }

    /**
     * Determine if a transaction is pending. Override per provider.
     */
    abstract private function isTransactionPending(object $transaction): bool;

    /**
     * Get the transaction amount string for zero-check. Override per provider.
     */
    abstract private function getTransactionAmount(object $transaction): string;

    /**
     * Log a zero-amount warning. Override per provider to include provider-specific fields.
     */
    abstract private function addZeroAmountWarning(object $transaction): void;
}
