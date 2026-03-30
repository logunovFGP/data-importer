<?php

/*
 * TransactionProcessor.php
 * Copyright (c) 2025 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace App\Services\EnableBanking\Conversion\Routine;

use App\Exceptions\ImporterErrorException;
use App\Exceptions\ImporterHttpException;
use App\Models\ImportJob;
use App\Repository\ImportJob\ImportJobRepository;
use App\Services\EnableBanking\Request\GetTransactionsRequest;
use App\Services\EnableBanking\Response\TransactionsResponse;
use App\Services\Shared\Configuration\Configuration;
use App\Services\Shared\Conversion\CreatesAccounts;
use App\Services\Shared\Conversion\TransactionFilterTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Class TransactionProcessor
 */
class TransactionProcessor
{
    use CreatesAccounts;
    use TransactionFilterTrait;

    private const string DATE_TIME_FORMAT = 'Y-m-d H:i:s';

    private array $accounts               = [];
    private Configuration $configuration;
    private ?Carbon $notAfter             = null;
    private ?Carbon $notBefore            = null;
    private ImportJob $importJob;
    private ImportJobRepository $repository;

    public function __construct()
    {
        bcscale(12);
    }

    /**
     * @throws ImporterErrorException
     */
    public function download(): array
    {
        Log::debug(sprintf('[%s] Now in %s', config('importer.version'), __METHOD__));

        $this->notBefore = null;
        $this->notAfter  = null;
        $this->accounts  = [];

        if ('' !== $this->configuration->getDateNotBefore()) {
            $this->notBefore = new Carbon($this->configuration->getDateNotBefore());
        }

        if ('' !== $this->configuration->getDateNotAfter()) {
            $this->notAfter = new Carbon($this->configuration->getDateNotAfter());
        }

        $accounts        = $this->configuration->getAccounts();
        $return          = [];

        Log::debug(sprintf('Found %d accounts to download from.', count($accounts)));
        $total           = count($accounts);
        $index           = 1;

        foreach ($accounts as $accountUid => $destinationId) {
            Log::debug(sprintf(
                '[%s] [%d/%d] Going to download transactions for account "%s" (into #%d)',
                config('importer.version'),
                $index,
                $total,
                $accountUid,
                $destinationId
            ));

            if (0 === $destinationId) {
                Log::debug('No destination ID found, create account');
                $destinationId = $this->createNewAccount($accountUid);
                Log::debug(sprintf('Newly created account #%d', $destinationId));
            }

            $url                 = config('enablebanking.url');
            $dateFrom            = '' !== $this->configuration->getDateNotBefore() ? $this->configuration->getDateNotBefore() : null;
            $dateTo              = '' !== $this->configuration->getDateNotAfter() ? $this->configuration->getDateNotAfter() : null;

            Log::debug(sprintf(
                'GetTransactionsRequest parameters: url=%s, accountUid=%s, dateFrom=%s, dateTo=%s',
                $url,
                $accountUid,
                $dateFrom ?? 'null',
                $dateTo ?? 'null'
            ));

            $request             = new GetTransactionsRequest($url, $accountUid, $dateFrom, $dateTo);
            $request->setTimeOut(config('importer.connection.timeout'));

            try {
                /** @var TransactionsResponse $transactions */
                $transactions = $request->get();
                Log::debug(sprintf('TransactionsResponse: count %d transaction(s)', count($transactions)));
            } catch (ImporterHttpException $e) {
                Log::error(sprintf('Enable Banking API error: %s', $e->getMessage()));
                $this->importJob->conversionStatus->addWarning(0, $e->getMessage());
                $return[$accountUid] = [];
                ++$index;

                continue;
            }

            $return[$accountUid] = $this->filterTransactions($transactions);
            Log::debug(sprintf('[%s] [%d/%d] Done downloading transactions for account "%s"', config('importer.version'), $index, $total, $accountUid));
            ++$index;
        }

        Log::debug('Done with download of transactions.');

        return $return;
    }

    public function getAccounts(): array
    {
        return $this->accounts;
    }

    private function filterTransactions(TransactionsResponse $transactions): array
    {
        return $this->filterTransactionSet(
            $transactions,
            $this->configuration->getPendingTransactions(),
            $this->notBefore,
            $this->notAfter,
        );
    }

    private function isTransactionPending(object $transaction): bool
    {
        return 'pending' === $transaction->status;
    }

    private function getTransactionAmount(object $transaction): string
    {
        return $transaction->transactionAmount;
    }

    private function addZeroAmountWarning(object $transaction): void
    {
        $this->importJob->conversionStatus->addWarning(0, sprintf(
            'Transaction #%s ("%s") has an amount of zero and has been ignored.',
            $transaction->transactionId,
            $transaction->getDescription()
        ));
    }

    public function getImportJob(): ImportJob
    {
        return $this->importJob;
    }

    public function setImportJob(ImportJob $importJob): void
    {
        Log::debug('setImportJob in TransactionProcessor.');
        $importJob->refreshInstanceIdentifier();
        $this->repository    = new ImportJobRepository();
        $this->importJob     = $importJob;
        $this->configuration = $importJob->getConfiguration();
    }
}
