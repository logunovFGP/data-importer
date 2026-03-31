<?php

/*
 * ApiSubmitter.php
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

namespace App\Services\Shared\Import\Routine;

use App\Exceptions\ImporterErrorException;
use App\Models\ImportJob;
use App\Repository\ImportJob\ImportJobRepository;
use App\Services\Shared\Authentication\SecretManager;
use App\Services\Shared\Configuration\Configuration;
use App\Services\Shared\Dedup\DuplicateDetector;
use App\Services\Shared\Dedup\DuplicateDetectorFactory;
use App\Services\Shared\Request\PostCurrencyRequest;
use Carbon\Carbon;
use GrumpyDictator\FFIIIApiSupport\Exceptions\ApiHttpException;
use GrumpyDictator\FFIIIApiSupport\Model\Transaction;
use GrumpyDictator\FFIIIApiSupport\Model\TransactionGroup;
use GrumpyDictator\FFIIIApiSupport\Request\GetCurrencyRequest;
use GrumpyDictator\FFIIIApiSupport\Request\GetSearchTransactionsRequest;
use GrumpyDictator\FFIIIApiSupport\Request\PostTagRequest;
use GrumpyDictator\FFIIIApiSupport\Request\PostTransactionRequest;
use GrumpyDictator\FFIIIApiSupport\Request\PutTransactionRequest;
use GrumpyDictator\FFIIIApiSupport\Response\GetCurrencyResponse;
use GrumpyDictator\FFIIIApiSupport\Response\GetTransactionsResponse;
use GrumpyDictator\FFIIIApiSupport\Response\PostTagResponse;
use GrumpyDictator\FFIIIApiSupport\Response\PostTransactionResponse;
use GrumpyDictator\FFIIIApiSupport\Response\ValidationErrorResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Class ApiSubmitter
 */
class ApiSubmitter
{
    private array               $accountInfo;
    private bool                $addTag;
    private Configuration       $configuration;
    private bool                $createdTag;
    private array               $mapping;
    private array               $availableCurrencies = [];
    private bool                $duplicateIndexReady = false;
    private array               $preloadedDuplicateIndex = [];
    private int                 $saveEveryRows = 10;
    private bool                $skipTagUpdates = false;
    private string              $tag;
    private string              $tagDate;
    private bool                $tagSkippingNotified = false;
    private string              $vanityURL;
    private ImportJob           $importJob;
    private ImportJobRepository $repository;
    private bool                $batchEndpointsAvailable = false;
    private ?BatchApiClient     $batchClient             = null;
    private int                 $importCount             = 0;
    private int                 $duplicateCount          = 0;
    private ?DuplicateDetector  $duplicateDetector       = null;

    public function setImportJob(ImportJob $importJob): void
    {
        $importJob->refreshInstanceIdentifier();
        $this->configuration = $importJob->getConfiguration();
        $this->mapping       = $this->configuration->getMapping();
        $this->repository    = new ImportJobRepository();

        // TODO(#83): remove this line to crash the submission routine without the user getting an error,
        $this->addTag        = $this->configuration->isAddImportTag();
        $this->availableCurrencies = [];
        $this->duplicateIndexReady = false;
        $this->preloadedDuplicateIndex = [];
        $this->saveEveryRows = max(1, (int)config('importer.submission.save_every_n_rows', 10));
        $this->skipTagUpdates = (bool)config('importer.submission.skip_tag_updates', false);
        $this->tagSkippingNotified = false;
        $this->importJob     = $importJob;
        $this->importCount   = 0;
        $this->duplicateCount = 0;

        // Probe Firefly III for batch endpoint support.
        $this->batchClient = new BatchApiClient(
            SecretManager::getBaseUrl(),
            SecretManager::getAccessToken(),
            (float) config('importer.connection.timeout', 31.415),
            config('importer.connection.verify', true),
        );
        try {
            $this->batchEndpointsAvailable = $this->batchClient->supportsBatchEndpoints();
        } catch (\Throwable $e) {
            Log::warning(sprintf('Could not detect batch endpoint support: %s', $e->getMessage()));
            $this->batchEndpointsAvailable = false;
        }
        Log::info(sprintf('Batch endpoints available: %s', $this->batchEndpointsAvailable ? 'yes' : 'no'));

        // V2 dedup pipeline: create the appropriate detector when enabled.
        $pipelineVersion = (int) config('importer.dedup.pipeline_version', 1);
        if (2 === $pipelineVersion) {
            $this->duplicateDetector = DuplicateDetectorFactory::create(
                $this->configuration->getFlow(),
                $this->batchClient,
                $this->batchEndpointsAvailable,
            );
            Log::info(sprintf('V2 dedup pipeline active, detector source: "%s".', $this->duplicateDetector->sourceName()));
        }
    }

    public function getImportJob(): ImportJob
    {
        return $this->importJob;
    }

    /**
     * @throws ImporterErrorException
     */
    public function processTransactions(): void
    {
        $lines            = $this->importJob->getConvertedTransactions();
        $this->createdTag = false;
        $this->tag        = $this->parseTag();
        $this->tagDate    = Carbon::now()->format('Y-m-d');
        $count            = count($lines);
        $importedCount    = 0;
        $duplicateCount   = 0;
        Log::info(sprintf('Going to submit %d transactions to your Firefly III instance.', $count));
        $this->importJob->submissionStatus->setTotals($count, 0, 0);
        $this->importJob->submissionStatus->addActivity(sprintf('Starting submission of %d transaction(s) to Firefly III...', $count));

        if (0 === $count) {
            $this->importJob->submissionStatus->addWarning(0, 'There are no transactions to be imported. Perhaps all your accounts are empty?');
        }

        $this->vanityURL  = SecretManager::getVanityURL();

        Log::debug(sprintf('Vanity URL : "%s"', $this->vanityURL));

        // V2 dedup pipeline: warm the new detector index before V1 warm-up.
        if (null !== $this->duplicateDetector) {
            $v2WarmStartedAt = microtime(true);
            $this->duplicateDetector->warmIndex($lines);
            $v2WarmElapsed = (microtime(true) - $v2WarmStartedAt) * 1000.0;
            Log::info(sprintf('[V2 dedup] Index warmed in %.1fms (source: %s).', $v2WarmElapsed, $this->duplicateDetector->sourceName()));
            $this->importJob->submissionStatus->addPerformanceSample('v2_dedup_warmup', $v2WarmElapsed);
        }

        $this->warmExternalIdDuplicateIndex($lines);
        $this->importJob->submissionStatus->setPerformanceMeta(
            'disk_saves',
            ['save_every_n_rows' => $this->saveEveryRows]
        );

        // When batch endpoints are available, use the bulk submission path.
        if ($this->batchEndpointsAvailable && null !== $this->batchClient) {
            $this->processTransactionsBatch($lines);
            $this->importJob->submissionStatus->setTotals($count, $this->importCount, $this->duplicateCount);
            $this->persistImportJob();
            Log::info(sprintf('Done submitting %d transactions via batch mode.', $count));
            Log::info(sprintf('Actually imported and not duplicate: %d.', $this->importCount));
            Log::info(sprintf('Skipped duplicates: %d.', $this->duplicateCount));
            $this->importJob->submissionStatus->addMessage(0, sprintf('Imported %d transactions (batch), skipped %d duplicate(s).', $this->importCount, $this->duplicateCount));

            return;
        }

        /**
         * @var int   $index
         * @var array $line
         */
        $position       = 0;
        $batchSize      = 10;
        $batchImported  = 0;
        $batchDuplicates = 0;
        foreach ($lines as $index => $line) {
            ++$position;
            Log::debug(sprintf('Now submitting transaction %d/%d', $position, $count));

            if (1 === $position || ($position % $batchSize) === 1) {
                $batchEnd = min($position + $batchSize - 1, $count);
                $this->importJob->submissionStatus->addActivity(sprintf('Processing transactions %d-%d of %d...', $position, $batchEnd, $count));
                $batchImported   = 0;
                $batchDuplicates = 0;
            }

            // Update progress tracking using sequential iteration position instead of sparse line index.
            $this->importJob->submissionStatus->updateProgress($position, $count);

            // Add board entry for this transaction
            $txDescription = (string)($line['transactions'][0]['description'] ?? 'Transaction #' . $position);
            $txAmount      = (string)($line['transactions'][0]['amount'] ?? '0');
            $txCurrency    = (string)($line['transactions'][0]['currency_code'] ?? '');
            $txDate        = (string)($line['transactions'][0]['date'] ?? '');
            $txExternalId  = (string)($line['transactions'][0]['external_id'] ?? '');
            $shortTxId     = strlen($txExternalId) > 14 ? substr($txExternalId, 0, 10) . '..' . substr($txExternalId, -2) : ($txExternalId ?: '#' . $position);
            $this->importJob->submissionStatus->addBoardEntry([
                'tx_id'        => $shortTxId,
                'amount'       => $txAmount,
                'currency'     => $txCurrency,
                'direction'    => str_starts_with($txAmount, '-') ? 'outgoing' : 'incoming',
                'counterparty' => substr($txDescription, 0, 20),
                'date'         => $txDate,
                'status'       => 'pending',
                'message'      => '',
            ]);
            // V2 dedup pipeline: check before V1 path
            if (null !== $this->duplicateDetector) {
                $v2CheckStartedAt = microtime(true);
                $firstTransaction = $line['transactions'][0] ?? [];
                $v2Result         = $this->duplicateDetector->isDuplicate($firstTransaction);
                $this->importJob->submissionStatus->addPerformanceSample('v2_dedup_checks', (microtime(true) - $v2CheckStartedAt) * 1000.0);
                if (null !== $v2Result && $v2Result->isDuplicate) {
                    Log::info(sprintf('[V2 dedup] Duplicate detected: %s (source: %s)', $v2Result->key->value, $v2Result->source));
                    $this->importJob->submissionStatus->addWarning(
                        $index,
                        sprintf('[V2 dedup] Duplicate: %s', $v2Result->key->value)
                    );
                    ++$duplicateCount;
                    ++$batchDuplicates;
                    $this->importJob->submissionStatus->updateBoardEntryStatus($shortTxId, 'duplicate');
                    $this->importJob->submissionStatus->setTotals($count, $importedCount, $duplicateCount);
                    if ($this->shouldPersistProgress($position, $count)) {
                        $this->persistImportJob();
                    }

                    continue;
                }
            }

            // V1 dedup: local duplicate transaction check (the "cell" method):
            $duplicateCheckStartedAt = microtime(true);
            $unique    = $this->uniqueTransaction($index, $line);
            $this->importJob->submissionStatus->addPerformanceSample('duplicate_checks', (microtime(true) - $duplicateCheckStartedAt) * 1000.0);
            if (null === $unique) {
                Log::warning(sprintf('[V1 dedup] Transaction %d: uniqueTransaction returned null (no dedup check performed)', $index));
            }
            if (false === $unique) {
                Log::debug(sprintf('Transaction #%d is NOT unique.', $index + 1));
                ++$duplicateCount;
                ++$batchDuplicates;
                $this->importJob->submissionStatus->updateBoardEntryStatus($shortTxId, 'duplicate');
                $this->importJob->submissionStatus->setTotals($count, $importedCount, $duplicateCount);
                if ($this->shouldPersistProgress($position, $count)) {
                    $this->persistImportJob();
                }

                continue;
            }

            // V2 dedup: stamp import_source on the transaction line before submission.
            if (null !== $this->duplicateDetector && '' !== $this->duplicateDetector->sourceName()) {
                foreach ($line['transactions'] as $txIdx => $tx) {
                    $line['transactions'][$txIdx]['import_source'] = $this->duplicateDetector->sourceName();
                }
            }

            $submissionStartedAt = microtime(true);
            $groupInfo = $this->processTransaction($index, $line);
            $this->importJob->submissionStatus->addPerformanceSample('firefly_submissions', (microtime(true) - $submissionStartedAt) * 1000.0);
            if ([] !== $groupInfo) {
                ++$importedCount;
                ++$batchImported;
                $this->importJob->submissionStatus->updateBoardEntryStatus($shortTxId, 'submitted');
            } else {
                $this->importJob->submissionStatus->updateBoardEntryStatus($shortTxId, 'error', 'Submission returned empty');
            }
            $this->rememberExternalIdsFromLine($line, $groupInfo);
            $tagStartedAt = microtime(true);
            $this->addTagToGroups($groupInfo);
            $this->importJob->submissionStatus->addPerformanceSample('tag_updates', (microtime(true) - $tagStartedAt) * 1000.0);
            $this->importJob->submissionStatus->setTotals($count, $importedCount, $duplicateCount);
            if ($this->shouldPersistProgress($position, $count)) {
                $this->persistImportJob();
            }

            if (($position % $batchSize) === 0 || $position === $count) {
                $avgMs = 0.0;
                $submissionPerf = $this->importJob->submissionStatus->performance['firefly_submissions'] ?? [];
                if (($submissionPerf['count'] ?? 0) > 0) {
                    $avgMs = round((float)($submissionPerf['average_ms'] ?? 0.0), 1);
                }
                $this->importJob->submissionStatus->addActivity(sprintf(
                    'Batch done: %d imported, %d duplicates (avg %.1fms/tx) — %d/%d total',
                    $batchImported, $batchDuplicates, $avgMs, $position, $count
                ));
            }

        }
        $this->importJob->submissionStatus->setTotals($count, $importedCount, $duplicateCount);
        $this->importJob->submissionStatus->addActivity(sprintf('Import complete: %d imported, %d duplicate(s) out of %d total', $importedCount, $duplicateCount, $count));
        $this->persistImportJob();

        Log::info(sprintf('Done submitting %d transactions to your Firefly III instance.', $count));
        Log::info(sprintf('Actually imported and not duplicate: %d.', $importedCount));
        Log::info(sprintf('Skipped duplicates: %d.', $duplicateCount));
        $this->importJob->submissionStatus->addMessage(0, sprintf('Imported %d transactions, skipped %d duplicate(s).', $importedCount, $duplicateCount));
    }

    private function parseTag(): string
    {
        // $this->tag        = sprintf('Data Import on %s', date('Y-m-d \@ H:i'));
        $customTag = $this->configuration->getCustomTag();
        if ('' === $customTag) {
            // return default tag:
            return sprintf('Data Import on %s', Carbon::now()->format('Y-m-d \@ H:i'));
        }
        $items     = [
            '%year%'        => Carbon::now()->format('Y'),
            '%month%'       => Carbon::now()->format('m'),
            '%month_full%'  => Carbon::now()->format('F'),
            '%day%'         => Carbon::now()->format('d'),
            '%day_of_week%' => Carbon::now()->format('l'),
            '%hour%'        => Carbon::now()->format('H'),
            '%minute%'      => Carbon::now()->format('i'),
            '%second%'      => Carbon::now()->format('s'),
            '%date%'        => Carbon::now()->format('Y-m-d'),
            '%time%'        => Carbon::now()->format('H:i'),
            '%datetime%'    => Carbon::now()->format('Y-m-d \@ H:i'),
            '%version%'     => config('importer.version'),
        ];
        $result    = str_replace(
            array_keys($items),
            array_values($items),
            $customTag
        );
        Log::debug(sprintf('Custom tag is "%s", parsed into "%s"', $customTag, $result));

        return $result;
    }

    /**
     * Verify if the transaction is unique, based on the configuration
     * and the content of the transaction. Returns a boolean.
     */
    private function uniqueTransaction(int $index, array $line): ?bool
    {
        if ('cell' !== $this->configuration->getDuplicateDetectionMethod()) {
            Log::debug(sprintf('Duplicate detection method is "%s", so this method is skipped (return true).', $this->configuration->getDuplicateDetectionMethod()));

            return null;
        }
        // do a search for the value and the field:
        $transactions = $line['transactions'] ?? [];
        $field        = $this->configuration->getUniqueColumnType();
        $field        = 'external-id' === $field ? 'external_id' : $field;
        $field        = 'note' === $field ? 'notes' : $field;
        $value        = '';
        foreach ($transactions as $transactionIndex => $transaction) {
            $value        = (string)($transaction[$field] ?? '');
            if ('' === $value) {
                Log::debug(sprintf('Identifier-based duplicate detection found no value ("") for field "%s" in transaction #%d (index #%d).', $field, $index, $transactionIndex));

                continue;
            }
            $expectedAccountIds = $this->extractAccountIdsFromImportTransaction($transaction);
            $searchResult = null;
            if ('external_id' === $field) {
                if (true === $this->duplicateIndexReady) {
                    $indexedResult = $this->preloadedDuplicateIndex[$value] ?? null;
                    if (null !== $indexedResult) {
                        $searchResult = $this->findDuplicateMatchForAccountContext($indexedResult, $expectedAccountIds);
                        if (null === $searchResult) {
                            Log::debug(
                                sprintf(
                                    'Found external_id "%s" in duplicate index, but account context does not overlap (expected: %s). Treat as unique.',
                                    $value,
                                    implode(',', $expectedAccountIds)
                                )
                            );

                            continue;
                        }
                    } else {
                        // Index miss: fall through to searchField API call below.
                        Log::debug(sprintf('No preloaded duplicate candidate found for external_id "%s", falling back to API search.', $value));
                    }
                }
                if (null === $searchResult) {
                    $searchResult = $this->searchField($field, $value, $expectedAccountIds);
                    if (null !== $searchResult) {
                        $searchResult = $this->findDuplicateMatchForAccountContext($searchResult, $expectedAccountIds);
                    }
                }
            } else {
                $searchResult = $this->searchField($field, $value);
            }
            if (null !== $searchResult) {
                Log::debug(sprintf('Looks like field "%s" with value "%s" is not unique, found in group #%d. Return false', $field, $value, $searchResult['id']));
                if ((int)$searchResult['id'] > 0) {
                    $message = sprintf(
                        '[a115]: There is already a transaction with %s "%s" (<a href="%s/transactions/show/%d">%s</a>, %s %s).',
                        $field,
                        $value,
                        $this->vanityURL,
                        $searchResult['id'],
                        e($searchResult['description']),
                        $searchResult['currency_code'],
                        bcround($searchResult['amount'], $searchResult['decimal_places'])
                    );
                }
                if ((int)$searchResult['id'] <= 0) {
                    $message = sprintf(
                        '[a115]: There is already a transaction with %s "%s" (%s %s).',
                        $field,
                        $value,
                        $searchResult['currency_code'],
                        bcround($searchResult['amount'], $searchResult['decimal_places'])
                    );
                }
                $this->importJob->submissionStatus->addWarning($index, $message);

                return false;
            }
        }
        Log::debug(sprintf('Looks like field "%s" with value "%s" is unique, return true.', $field, $value));

        return true;
    }

    /**
     * Do a search at Firefly III and return the ID of the group found.
     */
    private function searchField(string $field, string $value, array $expectedAccountIds = []): ?array
    {
        // search for the exact description and not just a part of it:
        $searchModifier = config(sprintf('csv.search_modifier.%s', $field));
        $query          = sprintf('%s:"%s"', $searchModifier, $value);

        Log::debug(sprintf('Going to search for %s:%s using query %s', $field, $value, $query));

        $url            = SecretManager::getBaseUrl();
        $token          = SecretManager::getAccessToken();
        $request        = new GetSearchTransactionsRequest($url, $token);
        $request->setTimeOut(config('importer.connection.timeout'));
        $request->setVerify(config('importer.connection.verify'));
        $request->setQuery($query);

        try {
            /** @var GetTransactionsResponse $response */
            $response = $request->get();
        } catch (ApiHttpException $e) {
            Log::error(sprintf('[%s]: %s', config('importer.version'), $e->getMessage()));

            return null;
        }
        if (0 === $response->count()) {
            return null;
        }
        $first          = $response->current();
        $array          = [
            'id'             => $first->id,
            'description'    => $first->transactions[0]->description ?? '(no description)',
            'currency_code'  => $first->transactions[0]->currencyCode ?? '(no currency)',
            'decimal_places' => $first->transactions[0]->currencyDecimalPlaces ?? 2,
            'amount'         => $first->transactions[0]->amount ?? '(unknown)',
            'account_ids'    => $this->extractAccountIdsFromApiTransaction($first->transactions[0] ?? null),
        ];
        if ('external_id' === $field && [] !== $expectedAccountIds && [] !== $array['account_ids']) {
            if (false === $this->hasAccountIdOverlap($expectedAccountIds, $array['account_ids'])) {
                Log::debug(
                    sprintf(
                        'Search result for external_id "%s" has non-overlapping account context (expected: %s, found: %s). Ignoring as duplicate.',
                        $value,
                        implode(',', $this->normalizeAccountIds($expectedAccountIds)),
                        implode(',', $array['account_ids'])
                    )
                );

                return null;
            }
        }
        Log::debug(sprintf('Found %d transaction(s). Return group ID #%d.', $response->count(), $first->id));

        return $array;
    }

    private function processTransaction(int $index, array $line): array
    {
        ++$index;
        $line    = $this->cleanupLine($line);
        $return  = [];
        $url     = SecretManager::getBaseUrl();
        $token   = SecretManager::getAccessToken();
        $request = new PostTransactionRequest($url, $token);
        $request->setVerify(config('importer.connection.verify'));
        $request->setTimeOut(config('importer.connection.timeout'));
        Log::debug(sprintf('Submitting to Firefly III: %s', json_encode($line)));
        $request->setBody($line);

        try {
            $response = $request->post();
        } catch (ApiHttpException $e) {
            $isDeleted = false;
            $body      = $request->getResponseBody();
            $json      = json_decode($body, true);
            // before we complain, first check what the error is:
            if (is_array($json) && array_key_exists('message', $json)) {
                if (str_contains((string)$json['message'], '200032')) {
                    $isDeleted = true;
                }
            }
            if (true === $isDeleted && false === config('importer.ignore_not_found_transactions')) {
                $this->importJob->submissionStatus->addWarning($index, 'The transaction was created, but deleted by a rule.');
                Log::error(sprintf('[%s]: %s', config('importer.version'), $e->getMessage()));

                return $return;
            }
            if (true === $isDeleted && true === config('importer.ignore_not_found_transactions')) {
                Log::info('The transaction was deleted by a rule, but this is ignored by the importer.');

                return $return;
            }
            $message   = sprintf('[a116]: Submission HTTP error: %s', e($e->getMessage()));
            Log::error(sprintf('[%s]: %s', config('importer.version'), $e->getMessage()));
            $this->importJob->submissionStatus->addError($index, $message);

            return $return;
        }

        if ($response instanceof ValidationErrorResponse) {
            if ($this->createMissingCurrenciesFromValidationErrors($response, $line)) {
                Log::warning(sprintf('Retrying transaction #%d after creating missing currency records in Firefly III.', $index));
                $request->setBody($line);
                try {
                    $retryResponse = $request->post();
                } catch (ApiHttpException $e) {
                    $message = sprintf('[a116]: Submission HTTP error: %s', e($e->getMessage()));
                    Log::error(sprintf('[%s]: %s', config('importer.version'), $e->getMessage()));
                    $this->importJob->submissionStatus->addError($index, $message);

                    return $return;
                }
                if ($retryResponse instanceof PostTransactionResponse) {
                    $response = $retryResponse;
                }
                if ($retryResponse instanceof ValidationErrorResponse) {
                    $response = $retryResponse;
                }
            }
        }

        if ($response instanceof ValidationErrorResponse) {
            if ($this->hasUnsupportedCurrencyValidationError($response)) {
                $lineWithoutCurrency = $this->stripUnsupportedCurrencyFields($line);
                if ($lineWithoutCurrency !== $line) {
                    Log::warning(sprintf('Retrying transaction #%d without explicit currency fields due to unavailable currency code in Firefly III.', $index));
                    $this->importJob->submissionStatus->addWarning($index, 'Currency code from the source is not available in Firefly III. Retrying this transaction using the mapped account currency.');
                    $request->setBody($lineWithoutCurrency);
                    try {
                        $retryResponse = $request->post();
                    } catch (ApiHttpException $e) {
                        $message = sprintf('[a116]: Submission HTTP error: %s', e($e->getMessage()));
                        Log::error(sprintf('[%s]: %s', config('importer.version'), $e->getMessage()));
                        $this->importJob->submissionStatus->addError($index, $message);

                        return $return;
                    }
                    if ($retryResponse instanceof PostTransactionResponse) {
                        $response = $retryResponse;
                        $line     = $lineWithoutCurrency;
                    }
                    if ($retryResponse instanceof ValidationErrorResponse) {
                        $response = $retryResponse;
                    }
                }
            }
        }

        if ($response instanceof ValidationErrorResponse) {
            foreach ($response->errors->messages() as $key => $errors) {
                Log::error(sprintf('Submission error: %d', $key), $errors);
                foreach ($errors as $error) {
                    $msg = sprintf('[a117]: %s: %s (original value: "%s")', $key, $error, $this->getOriginalValue($key, $line));
                    if ($this->isDuplicationError($key, $error)) {
                        $this->importJob->submissionStatus->addWarning($index, $msg);
                        Log::warning(sprintf('[%s]: %s', config('importer.version'), $msg));

                        continue;
                    }
                    $this->importJob->submissionStatus->addError($index, $msg);
                    Log::error(sprintf('[%s]: %s', config('importer.version'), $msg));
                }
            }

            return $return;
        }

        if ($response instanceof PostTransactionResponse) {
            /** @var TransactionGroup $group */
            $group  = $response->getTransactionGroup();
            if (null === $group) {
                $message = '[a118]: Could not create transaction. Unexpected empty response from Firefly III. Check the logs.';
                Log::error(sprintf('[%s] %s', config('importer.version'), $message), $response->getRawData());
                $this->importJob->submissionStatus->addError($index, $message);

                return $return;
            }

            // perhaps zero transactions in the array.
            if (0 === count($group->transactions)) {
                $message = '[a119]: Could not create transaction. Transaction-count from Firefly III is zero. Check the logs.';
                Log::error(sprintf('[%s] %s', config('importer.version'), $message), $response->getRawData());
                $this->importJob->submissionStatus->addError($index, $message);

                return $return;
            }

            $return = ['group_id' => $group->id, 'journals' => []];
            foreach ($group->transactions as $transaction) {
                $message                              = sprintf(
                    'Created %s <a target="_blank" href="%s">#%d "%s"</a> (%s %s)',
                    $transaction->type,
                    sprintf('%s/transactions/show/%d', $this->vanityURL, $group->id),
                    $group->id,
                    e($transaction->description),
                    $transaction->currencyCode,
                    round((float)$transaction->amount, (int)$transaction->currencyDecimalPlaces) // float but only for display purposes
                );
                // plus 1 to keep the count.
                $this->importJob->submissionStatus->addMessage($index, $message);
                $this->compareArrays($index, $line, $group);
                Log::info(sprintf('[%s] %s', config('importer.version'), $message));
                $return['journals'][$transaction->id] = $transaction->tags;
            }
        }

        return $return;
    }

    private function cleanupLine(array $line): array
    {
        Log::debug('Going to map data for this line.');
        if (array_key_exists(0, $this->mapping)) {
            Log::debug('Configuration has mapping for opposing account name!');

            /**
             * @var int   $index
             * @var array $transaction
             */
            foreach ($line['transactions'] as $index => $transaction) {
                if ('withdrawal' === $transaction['type']) {
                    // replace destination_name with destination_id
                    $destination = $transaction['destination_name'] ?? '';
                    if (array_key_exists($destination, $this->mapping[0])) {
                        unset($transaction['destination_name'], $transaction['destination_iban']);

                        $transaction['destination_id'] = $this->mapping[0][$destination];
                        Log::debug(sprintf('Replaced destination name "%s" with a reference to account id #%d', $destination, $this->mapping[0][$destination]));
                    }
                }
                if ('deposit' === $transaction['type']) {
                    // replace source_name with source_id
                    $source = $transaction['source_name'] ?? '';
                    if (array_key_exists($source, $this->mapping[0])) {
                        unset($transaction['source_name'], $transaction['source_iban']);

                        $transaction['source_id'] = $this->mapping[0][$source];
                        Log::debug(sprintf('Replaced source name "%s" with a reference to account id #%d', $source, $this->mapping[0][$source]));
                    }
                }
                if ('' === trim((string)$transaction['description'] ?? '')) {
                    $transaction['description'] = '(no description)';
                }
                $line['transactions'][$index] = $this->updateTransactionType($transaction);
            }
        }

        return $line;
    }

    private function updateTransactionType(array $transaction): array
    {
        if (array_key_exists('source_id', $transaction) && array_key_exists('destination_id', $transaction)) {
            Log::debug('Transaction has source_id/destination_id');
            $sourceId        = (int)$transaction['source_id'];
            $destinationId   = (int)$transaction['destination_id'];
            $sourceType      = $this->accountInfo[$sourceId] ?? 'unknown';
            $destinationType = $this->accountInfo[$destinationId] ?? 'unknown';
            $combi           = sprintf('%s-%s', $sourceType, $destinationType);
            Log::debug(sprintf('Account type combination is "%s"', $combi));
            if ('asset-asset' === $combi) {
                Log::debug('Both accounts are assets, so this transaction is a transfer.');
                $transaction['type'] = 'transfer';
            }
        }

        return $transaction;
    }

    private function getOriginalValue(string $key, array $transaction): string
    {
        $parts = explode('.', $key);
        if (1 === count($parts)) {
            return $transaction[$key] ?? '(not found)';
        }
        if (3 !== count($parts)) {
            return '(unknown)';
        }
        $index = (int)$parts[1];

        return (string)($transaction['transactions'][$index][$parts[2]] ?? '(not found)');
    }

    private function isDuplicationError(string $key, string $error): bool
    {
        if ('transactions.0.description' === $key && str_contains($error, 'Duplicate of transaction #')) {
            Log::debug('This is a duplicate transaction error');

            return true;
        }
        Log::debug('This is not a duplicate transaction error');

        return false;
    }

    private function createMissingCurrenciesFromValidationErrors(ValidationErrorResponse $response, array $line): bool
    {
        $available = false;
        foreach ($response->errors->messages() as $key => $errors) {
            if (1 !== preg_match('/^transactions\.(\d+)\.(currency_code|foreign_currency_code)$/', (string)$key, $matches)) {
                continue;
            }
            $hasInvalidMessage = false;
            foreach ($errors as $error) {
                if (is_string($error) && false !== stripos($error, 'invalid')) {
                    $hasInvalidMessage = true;
                    break;
                }
            }
            if (false === $hasInvalidMessage) {
                continue;
            }
            $transactionIndex = (int)$matches[1];
            $field            = (string)$matches[2];
            $code             = strtoupper(trim((string)($line['transactions'][$transactionIndex][$field] ?? '')));
            if ('' === $code || 1 !== preg_match('/^[A-Z0-9]{3,32}$/', $code)) {
                continue;
            }
            if ($this->ensureCurrencyAvailable($code)) {
                $available = true;
            }
        }

        return $available;
    }

    private function ensureCurrencyAvailable(string $code): bool
    {
        if (array_key_exists($code, $this->availableCurrencies)) {
            return true === $this->availableCurrencies[$code];
        }
        if ($this->currencyExists($code)) {
            $this->availableCurrencies[$code] = true;

            return true;
        }
        $created                          = $this->createCurrency($code);
        $this->availableCurrencies[$code] = $created;

        return $created;
    }

    private function currencyExists(string $code): bool
    {
        $url     = SecretManager::getBaseUrl();
        $token   = SecretManager::getAccessToken();
        $request = new GetCurrencyRequest($url, $token);
        $request->setCode($code);
        $request->setVerify(config('importer.connection.verify'));
        $request->setTimeOut(config('importer.connection.timeout'));

        try {
            $response = $request->get();
        } catch (ApiHttpException) {
            return false;
        }

        return $response instanceof GetCurrencyResponse;
    }

    private function createCurrency(string $code): bool
    {
        $url     = SecretManager::getBaseUrl();
        $token   = SecretManager::getAccessToken();
        $request = new PostCurrencyRequest($url, $token);
        $request->setVerify(config('importer.connection.verify'));
        $request->setTimeOut(config('importer.connection.timeout'));
        $request->setBody(
            [
                'name'           => sprintf('Currency %s', $code),
                'code'           => $code,
                'symbol'         => $code,
                'decimal_places' => 2,
                'enabled'        => true,
                'default'        => false,
            ]
        );

        try {
            $response = $request->post();
        } catch (ApiHttpException $e) {
            Log::warning(sprintf('Could not create missing Firefly III currency "%s": %s', $code, $e->getMessage()));

            return false;
        }

        if ($response instanceof ValidationErrorResponse) {
            foreach ($response->errors->messages() as $field => $errors) {
                if ('code' !== $field) {
                    continue;
                }
                foreach ($errors as $error) {
                    if (!is_string($error)) {
                        continue;
                    }
                    if (false !== stripos($error, 'taken')) {
                        Log::info(sprintf('Currency "%s" appears to exist already; continuing import.', $code));

                        return true;
                    }
                }
            }
            Log::warning(sprintf('Validation blocked currency creation for "%s".', $code), $response->errors->toArray());

            return false;
        }
        Log::info(sprintf('Created missing Firefly III currency "%s".', $code));

        return true;
    }

    private function hasUnsupportedCurrencyValidationError(ValidationErrorResponse $response): bool
    {
        foreach ($response->errors->messages() as $key => $errors) {
            if (1 !== preg_match('/^transactions\.\d+\.currency_code$/', (string)$key)) {
                continue;
            }
            foreach ($errors as $error) {
                if (!is_string($error)) {
                    continue;
                }
                if (false !== stripos($error, 'invalid')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function stripUnsupportedCurrencyFields(array $line): array
    {
        if (!isset($line['transactions']) || !is_array($line['transactions'])) {
            return $line;
        }
        foreach ($line['transactions'] as $transactionIndex => $transaction) {
            if (!is_array($transaction)) {
                continue;
            }
            unset(
                $transaction['currency_code'],
                $transaction['currency_id'],
                $transaction['foreign_currency_code'],
                $transaction['foreign_currency_id']
            );
            $line['transactions'][$transactionIndex] = $transaction;
        }

        return $line;
    }

    private function compareArrays(int $lineIndex, array $line, TransactionGroup $group): void
    {
        // some fields may not have survived. Be sure to warn the user about this.
        /** @var Transaction $transaction */
        foreach ($group->transactions as $index => $transaction) {
            // compare currency ID
            if (array_key_exists('currency_id', $line['transactions'][$index]) && null !== $line['transactions'][$index]['currency_id'] && (int)$line['transactions'][$index]['currency_id'] !== (int)$transaction->currencyId) {
                $this->importJob->submissionStatus->addWarning($lineIndex, sprintf('Line #%d may have had its currency changed (from ID #%d to ID #%d). This happens because the associated asset account overrules the currency of the transaction.', $lineIndex, $line['transactions'][$index]['currency_id'], (int)$transaction->currencyId));
            }
            // compare currency code:
            if (array_key_exists('currency_code', $line['transactions'][$index]) && null !== $line['transactions'][$index]['currency_code'] && $line['transactions'][$index]['currency_code'] !== $transaction->currencyCode) {
                $this->importJob->submissionStatus->addWarning($lineIndex, sprintf('Line #%d may have had its currency changed (from "%s" to "%s"). This happens because the associated asset account overrules the currency of the transaction.', $lineIndex, $line['transactions'][$index]['currency_code'], $transaction->currencyCode));
            }
        }
    }

    private function addTagToGroups(array $groupInfo): void
    {
        if ([] === $groupInfo) {
            Log::debug('Group is empty, may not have been stored correctly.');

            return;
        }
        if (false === $this->addTag) {
            Log::debug('Will not add import tag.');

            return;
        }
        if (true === $this->skipTagUpdates) {
            if (false === $this->tagSkippingNotified) {
                $this->importJob->submissionStatus->addWarning(0, '[a131]: Import tag updates are skipped for this submission to improve performance.');
                $this->tagSkippingNotified = true;
            }
            Log::debug('Configured to skip tag updates for performance.');

            return;
        }
        if (false === $this->createdTag) {
            $this->createTag();
            $this->createdTag = true;
        }

        $groupId = (int)$groupInfo['group_id'];
        Log::debug(sprintf('Going to add import tag to transaction group #%d', $groupId));
        $body    = ['transactions' => []];

        /**
         * @var int   $journalId
         * @var array $currentTags
         */
        foreach ($groupInfo['journals'] as $journalId => $currentTags) {
            $currentTags[]          = $this->tag;
            $body['transactions'][] = ['transaction_journal_id' => $journalId, 'tags' => $currentTags];
        }
        $url     = SecretManager::getBaseUrl();
        $token   = SecretManager::getAccessToken();
        $request = new PutTransactionRequest($url, $token, $groupId);
        $request->setVerify(config('importer.connection.verify'));
        $request->setTimeOut(config('importer.connection.timeout'));
        $request->setBody($body);
        $tagApplied = false;

        try {
            $request->put();
            $tagApplied = true;
        } catch (ApiHttpException $e) {
            Log::error(sprintf('[%s]: %s', config('importer.version'), $e->getMessage()));
            // Transaction has already been created at this point.
            // This path only means import tag update failed.
            if (str_contains($e->getMessage(), 'storage/framework/cache/data') && str_contains($e->getMessage(), 'No such file or directory')) {
                $this->importJob->submissionStatus->addWarning(
                    0,
                    sprintf(
                        '[a120]: Transaction was stored, but import tag could not be added to group #%d because Firefly cache storage is unavailable. Please fix Firefly cache directory permissions/existence and retry tagging if needed.',
                        $groupId
                    )
                );

                return;
            }
            $this->importJob->submissionStatus->addWarning(
                0,
                sprintf(
                    '[a120]: Transaction was stored, but import tag update failed for group #%d: %s',
                    $groupId,
                    e($e->getMessage())
                )
            );

            return;
        }
        if (true === $tagApplied) {
            Log::debug(sprintf('Added import tag to transaction group #%d', $groupId));
        }
    }

    private function createTag(): void
    {
        if (false === $this->addTag) {
            Log::debug('Not instructed to add a tag, so will not create one.');

            return;
        }
        $url     = SecretManager::getBaseUrl();
        $token   = SecretManager::getAccessToken();
        $request = new PostTagRequest($url, $token);
        $request->setVerify(config('importer.connection.verify'));
        $request->setTimeOut(config('importer.connection.timeout'));
        $body    = ['tag' => $this->tag, 'date' => $this->tagDate];
        $request->setBody($body);

        try {
            /** @var PostTagResponse $response */
            $response = $request->post();
        } catch (ApiHttpException $e) {
            $message = sprintf('[a121]: Could not create tag. %s', $e->getMessage());
            Log::error(sprintf('[%s] %s', config('importer.version'), $message));
            $this->importJob->submissionStatus->addError(0, $message);

            return;
        }
        if ($response instanceof ValidationErrorResponse) {
            Log::error(json_encode($response->errors->toArray()));

            return;
        }
        if (null !== $response->getTag()) {
            Log::info(sprintf('Created tag #%d "%s"', $response->getTag()->id, $response->getTag()->tag));
        }
    }

    public function setAccountInfo(array $accountInfo): void
    {
        $this->accountInfo = $accountInfo;
    }

    private function shouldPersistProgress(int $position, int $count): bool
    {
        if ($position >= $count) {
            return true;
        }

        return 0 === $position % $this->saveEveryRows;
    }

    private function persistImportJob(): void
    {
        $saveStartedAt = microtime(true);
        $this->repository->saveToDisk($this->importJob);
        $this->importJob->submissionStatus->addPerformanceSample('disk_saves', (microtime(true) - $saveStartedAt) * 1000.0);
    }

    private function rememberExternalIdsFromLine(array $line, array $groupInfo): void
    {
        if (false === $this->duplicateIndexReady) {
            return;
        }
        $transactions = $line['transactions'] ?? [];
        $groupId      = (int)($groupInfo['group_id'] ?? 0);
        foreach ($transactions as $transaction) {
            $externalId = trim((string)($transaction['external_id'] ?? ''));
            if ('' === $externalId) {
                continue;
            }
            $candidate = [
                'id'             => $groupId,
                'description'    => (string)($transaction['description'] ?? '(no description)'),
                'currency_code'  => (string)($transaction['currency_code'] ?? '(no currency)'),
                'decimal_places' => 2,
                'amount'         => (string)($transaction['amount'] ?? '0'),
                'account_ids'    => $this->extractAccountIdsFromImportTransaction($transaction),
            ];
            $this->preloadedDuplicateIndex[$externalId] = $this->mergeDuplicateCandidate($this->preloadedDuplicateIndex[$externalId] ?? null, $candidate);
        }
    }

    private function warmExternalIdDuplicateIndex(array $lines): void
    {
        if ('cell' !== $this->configuration->getDuplicateDetectionMethod()) {
            return;
        }
        $field = $this->configuration->getUniqueColumnType();
        $field = 'external-id' === $field ? 'external_id' : $field;
        if ('external_id' !== $field) {
            return;
        }
        $externalIds = [];
        $startDate   = null;
        $endDate     = null;
        foreach ($lines as $line) {
            $transactions = $line['transactions'] ?? [];
            foreach ($transactions as $transaction) {
                $externalId = trim((string)($transaction['external_id'] ?? ''));
                if ('' !== $externalId) {
                    $externalIds[$externalId] = true;
                }
                $dateString = (string)($transaction['date'] ?? $transaction['datetime'] ?? '');
                if ('' === $dateString) {
                    continue;
                }
                try {
                    $parsedDate = Carbon::parse($dateString)->format('Y-m-d');
                } catch (\Throwable) {
                    continue;
                }
                if (null === $startDate || $parsedDate < $startDate) {
                    $startDate = $parsedDate;
                }
                if (null === $endDate || $parsedDate > $endDate) {
                    $endDate = $parsedDate;
                }
            }
        }
        if (0 === count($externalIds)) {
            $this->duplicateIndexReady = true;

            return;
        }

        // Attempt batch external_id search when batch endpoints are available.
        // Chunk into groups of 450 (under the 500 server limit) for large imports.
        if ($this->batchEndpointsAvailable && null !== $this->batchClient) {
            $batchStartedAt = microtime(true);
            try {
                // Cast all IDs to strings — the batch endpoint validates string type.
                // Some external_ids are numeric (MD5 hash fallback), which PHP array_keys returns as int.
                $allIds = array_map('strval', array_keys($externalIds));
                $matches = [];
                foreach (array_chunk($allIds, 450) as $idChunk) {
                    $batchResult = $this->batchClient->batchSearchExternalIds(
                        $idChunk,
                        $startDate,
                        $endDate
                    );
                    $chunkMatches = (array) ($batchResult['results'] ?? []);
                    $matches = array_merge($matches, $chunkMatches);
                }
                foreach ($matches as $extId => $match) {
                    if (null === $match || !is_array($match)) {
                        continue;
                    }
                    $this->preloadedDuplicateIndex[(string) $extId] = [
                        'id'             => (int) ($match['transaction_group_id'] ?? 0),
                        'description'    => (string) ($match['description'] ?? '(no description)'),
                        'currency_code'  => (string) ($match['currency_code'] ?? '(no currency)'),
                        'decimal_places' => (int) ($match['decimal_places'] ?? 2),
                        'amount'         => (string) ($match['amount'] ?? '0'),
                        'account_ids'    => array_filter([
                            (int) ($match['source_account_id'] ?? 0),
                            (int) ($match['destination_account_id'] ?? 0),
                        ]),
                    ];
                }
                $this->duplicateIndexReady = true;
                $batchElapsed = (microtime(true) - $batchStartedAt) * 1000.0;
                $this->importJob->submissionStatus->addPerformanceSample('duplicate_index_preload', $batchElapsed);
                $this->importJob->submissionStatus->setPerformanceMeta(
                    'duplicate_index_preload',
                    [
                        'mode'                   => 'batch',
                        'external_ids_in_import' => count($externalIds),
                        'existing_ids_found'     => count($this->preloadedDuplicateIndex),
                        'start'                  => $startDate,
                        'end'                    => $endDate,
                    ]
                );
                Log::info(sprintf(
                    'Batch external_id search found %d matches out of %d IDs.',
                    count(array_filter($matches)),
                    count($externalIds)
                ));

                return;
            } catch (\Throwable $e) {
                Log::warning(sprintf('Batch external_id search failed, falling back to pagination: %s', $e->getMessage()));
            }
        }

        $startedAt = microtime(true);
        try {
            $result = $this->fetchExistingExternalIds(array_keys($externalIds), $startDate, $endDate);
        } catch (\Throwable $throwable) {
            Log::warning(sprintf('Could not preload duplicate external_id index: %s', $throwable->getMessage()));
            $this->duplicateIndexReady = false;

            return;
        }
        $this->preloadedDuplicateIndex = $result['index'];
        $this->duplicateIndexReady     = true;
        $elapsed                       = (microtime(true) - $startedAt) * 1000.0;
        $this->importJob->submissionStatus->addPerformanceSample('duplicate_index_preload', $elapsed);
        $this->importJob->submissionStatus->setPerformanceMeta(
            'duplicate_index_preload',
            [
                'external_ids_in_import' => count($externalIds),
                'existing_ids_found'     => count($this->preloadedDuplicateIndex),
                'pages_scanned'          => $result['pages'],
                'groups_scanned'         => $result['groups'],
                'start'                  => $startDate,
                'end'                    => $endDate,
            ]
        );
        Log::info(
            sprintf(
                'Preloaded duplicate external_id index: found %d existing out of %d import IDs (pages=%d, groups=%d).',
                count($this->preloadedDuplicateIndex),
                count($externalIds),
                $result['pages'],
                $result['groups']
            )
        );
    }

    private function fetchExistingExternalIds(array $externalIds, ?string $startDate, ?string $endDate): array
    {
        $targetSet    = array_fill_keys($externalIds, true);
        $found        = [];
        $pagesScanned = 0;
        $groupsScanned = 0;
        $page         = 1;
        $maxPages     = max(1, (int)config('importer.submission.duplicate_index_max_pages', 200));
        $pageSize     = max(10, (int)config('importer.submission.duplicate_index_page_size', 100));
        $baseUrl      = rtrim(SecretManager::getBaseUrl(), '/');
        $token        = SecretManager::getAccessToken();
        $url          = sprintf('%s/v1/transactions', $baseUrl);

        while ($page <= $maxPages) {
            ++$pagesScanned;
            $query = ['page' => $page, 'limit' => $pageSize, 'type' => 'default'];
            if (null !== $startDate) {
                $query['start'] = $startDate;
            }
            if (null !== $endDate) {
                $query['end'] = $endDate;
            }
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout((int)ceil((float)config('importer.connection.timeout', 31.415)))
                ->withOptions(['verify' => config('importer.connection.verify')])
                ->get($url, $query);

            if (!$response->successful()) {
                throw new ImporterErrorException(sprintf('Could not preload duplicate index: %s %s', $response->status(), $response->body()));
            }

            $payload = $response->json();
            if (!is_array($payload)) {
                break;
            }
            $groups = $payload['data'] ?? [];
            if (!is_array($groups) || 0 === count($groups)) {
                break;
            }
            foreach ($groups as $group) {
                ++$groupsScanned;
                $groupId      = (int)($group['id'] ?? 0);
                $transactions = $group['attributes']['transactions'] ?? [];
                if (!is_array($transactions)) {
                    continue;
                }
                foreach ($transactions as $transaction) {
                    $externalId = trim((string)($transaction['external_id'] ?? ($transaction['attributes']['external_id'] ?? '')));
                    if ('' === $externalId || !array_key_exists($externalId, $targetSet)) {
                        continue;
                    }
                    $candidate = [
                        'id'             => $groupId,
                        'description'    => (string)($transaction['description'] ?? ($transaction['attributes']['description'] ?? '(no description)')),
                        'currency_code'  => (string)($transaction['currency_code'] ?? ($transaction['attributes']['currency_code'] ?? '(no currency)')),
                        'decimal_places' => (int)($transaction['currency_decimal_places'] ?? ($transaction['attributes']['currency_decimal_places'] ?? 2)),
                        'amount'         => (string)($transaction['amount'] ?? ($transaction['attributes']['amount'] ?? '0')),
                        'account_ids'    => $this->extractAccountIdsFromApiTransaction($transaction),
                    ];
                    $found[$externalId] = $this->mergeDuplicateCandidate($found[$externalId] ?? null, $candidate);
                }
                if (count($found) >= count($targetSet)) {
                    break;
                }
            }
            if (count($found) >= count($targetSet)) {
                break;
            }

            $totalPages = (int)($payload['meta']['pagination']['total_pages'] ?? $page);
            $nextLink   = (string)($payload['links']['next'] ?? '');
            if ($page >= $totalPages || '' === trim($nextLink)) {
                break;
            }
            ++$page;
        }

        return ['index' => $found, 'pages' => $pagesScanned, 'groups' => $groupsScanned];
    }

    private function mergeDuplicateCandidate(?array $existing, array $candidate): array
    {
        $candidate['account_ids'] = $this->normalizeAccountIds((array)($candidate['account_ids'] ?? []));
        if (null === $existing || [] === $existing) {
            $candidate['matches'] = [$candidate];

            return $candidate;
        }
        $matches = $this->duplicateCandidatesFromEntry($existing);
        $matches[] = $candidate;
        $existing['matches'] = $matches;
        if (!array_key_exists('id', $existing)) {
            $existing['id'] = $candidate['id'] ?? 0;
        }
        if (!array_key_exists('description', $existing)) {
            $existing['description'] = $candidate['description'] ?? '(no description)';
        }
        if (!array_key_exists('currency_code', $existing)) {
            $existing['currency_code'] = $candidate['currency_code'] ?? '(no currency)';
        }
        if (!array_key_exists('decimal_places', $existing)) {
            $existing['decimal_places'] = $candidate['decimal_places'] ?? 2;
        }
        if (!array_key_exists('amount', $existing)) {
            $existing['amount'] = $candidate['amount'] ?? '0';
        }
        if (!array_key_exists('account_ids', $existing)) {
            $existing['account_ids'] = $candidate['account_ids'] ?? [];
        }

        return $existing;
    }

    private function duplicateCandidatesFromEntry(array $entry): array
    {
        $matches = $entry['matches'] ?? null;
        if (!is_array($matches) || [] === $matches) {
            return [$entry];
        }

        return $matches;
    }

    private function findDuplicateMatchForAccountContext(array $entry, array $expectedAccountIds): ?array
    {
        $candidates = $this->duplicateCandidatesFromEntry($entry);
        if ([] === $candidates) {
            return null;
        }
        $expected = $this->normalizeAccountIds($expectedAccountIds);
        if ([] === $expected) {
            return $candidates[0];
        }
        $fallbackWithoutContext = null;
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $candidateAccountIds = $this->normalizeAccountIds((array)($candidate['account_ids'] ?? []));
            if ([] === $candidateAccountIds) {
                if (null === $fallbackWithoutContext) {
                    $fallbackWithoutContext = $candidate;
                }

                continue;
            }
            if ([] !== array_intersect($expected, $candidateAccountIds)) {
                return $candidate;
            }
        }

        return $fallbackWithoutContext;
    }

    private function extractAccountIdsFromImportTransaction(array $transaction): array
    {
        return $this->normalizeAccountIds([
            $transaction['source_id'] ?? null,
            $transaction['destination_id'] ?? null,
        ]);
    }

    private function extractAccountIdsFromApiTransaction(mixed $transaction): array
    {
        if (null === $transaction) {
            return [];
        }
        if (is_array($transaction)) {
            return $this->normalizeAccountIds([
                $transaction['source_id'] ?? null,
                $transaction['destination_id'] ?? null,
                $transaction['sourceId'] ?? null,
                $transaction['destinationId'] ?? null,
            ]);
        }
        if (!is_object($transaction)) {
            return [];
        }

        $values = [];
        foreach (['source_id', 'destination_id', 'sourceId', 'destinationId'] as $property) {
            if (isset($transaction->{$property})) {
                $values[] = $transaction->{$property};
            }
        }

        return $this->normalizeAccountIds($values);
    }

    private function normalizeAccountIds(array $accountIds): array
    {
        $normalized = [];
        foreach ($accountIds as $accountId) {
            if (is_string($accountId)) {
                $accountId = trim($accountId);
            }
            if (null === $accountId || '' === $accountId || !is_numeric((string)$accountId)) {
                continue;
            }
            $integer = (int)$accountId;
            if ($integer <= 0) {
                continue;
            }
            $normalized[$integer] = true;
        }

        return array_keys($normalized);
    }

    private function hasAccountIdOverlap(array $left, array $right): bool
    {
        $leftIds  = $this->normalizeAccountIds($left);
        $rightIds = $this->normalizeAccountIds($right);
        if ([] === $leftIds || [] === $rightIds) {
            return false;
        }

        return [] !== array_intersect($leftIds, $rightIds);
    }

    // -----------------------------------------------------------------------
    //  Batch submission methods
    // -----------------------------------------------------------------------

    /**
     * Bulk-submit transactions via the Firefly III batch endpoint.
     *
     * Phase A filters out locally-known duplicates using the preloaded index.
     * Phase B sends remaining items in adaptive chunks and processes per-item results.
     */
    private function processTransactionsBatch(array $lines): void
    {
        $count = count($lines);

        // Phase A: Duplicate pre-check using preloaded index.
        $uniqueLines = [];
        $position    = 0;
        foreach ($lines as $index => $line) {
            ++$position;
            $this->importJob->submissionStatus->updateProgress($position, $count);

            // V2 dedup pipeline in batch path
            if (null !== $this->duplicateDetector) {
                $v2CheckStartedAt = microtime(true);
                $firstTransaction = $line['transactions'][0] ?? [];
                $v2Result         = $this->duplicateDetector->isDuplicate($firstTransaction);
                $this->importJob->submissionStatus->addPerformanceSample('v2_dedup_checks', (microtime(true) - $v2CheckStartedAt) * 1000.0);
                if (null !== $v2Result && $v2Result->isDuplicate) {
                    Log::info(sprintf('[V2 dedup][batch] Duplicate detected: %s (source: %s)', $v2Result->key->value, $v2Result->source));
                    ++$this->duplicateCount;
                    $this->importJob->submissionStatus->setTotals($count, $this->importCount, $this->duplicateCount);

                    continue;
                }
            }

            $duplicateCheckStartedAt = microtime(true);
            $unique                  = $this->uniqueTransaction($index, $line);
            $this->importJob->submissionStatus->addPerformanceSample(
                'duplicate_checks',
                (microtime(true) - $duplicateCheckStartedAt) * 1000.0
            );
            if (false === $unique) {
                ++$this->duplicateCount;
                $this->importJob->submissionStatus->setTotals($count, $this->importCount, $this->duplicateCount);

                continue;
            }
            $uniqueLines[$index] = $line;
        }

        if ([] === $uniqueLines) {
            Log::info('Batch mode: all transactions were duplicates, nothing to submit.');

            return;
        }

        // Phase B: Batch submission in adaptive chunks.
        $chunkSize = $this->estimateChunkSize($uniqueLines);
        $chunks    = array_chunk($uniqueLines, $chunkSize, true);
        Log::info(sprintf('Batch mode: submitting %d unique lines in %d chunks of up to %d.', count($uniqueLines), count($chunks), $chunkSize));

        foreach ($chunks as $chunk) {
            $batchPayload = [];
            $indexMap     = []; // maps batch position -> original line index
            $i            = 0;
            foreach ($chunk as $originalIndex => $line) {
                // V2 dedup: stamp import_source on each transaction in the line.
                if (null !== $this->duplicateDetector && '' !== $this->duplicateDetector->sourceName()) {
                    foreach ($line['transactions'] as $txIdx => $tx) {
                        $line['transactions'][$txIdx]['import_source'] = $this->duplicateDetector->sourceName();
                    }
                }
                $batchPayload[] = $this->cleanupLine($line);
                $indexMap[$i]   = $originalIndex;
                ++$i;
            }

            $submissionStartedAt = microtime(true);
            try {
                $batchResults = $this->batchClient->batchStoreTransactions($batchPayload);
            } catch (\Throwable $e) {
                // On total failure, attempt idempotent retry then fall back to individual submission.
                Log::warning(sprintf('Batch store failed, attempting idempotent retry: %s', $e->getMessage()));
                $chunk = $this->removeAlreadyCommittedItems($chunk);

                Log::warning(sprintf('Falling back to individual submission for %d remaining items.', count($chunk)));
                foreach ($chunk as $originalIndex => $line) {
                    $groupInfo = $this->processTransaction($originalIndex, $line);
                    if ([] !== $groupInfo) {
                        ++$this->importCount;
                    }
                    $this->rememberExternalIdsFromLine($line, $groupInfo);
                    $this->addTagToGroups($groupInfo);
                }
                $this->importJob->submissionStatus->addPerformanceSample(
                    'firefly_submissions',
                    (microtime(true) - $submissionStartedAt) * 1000.0,
                    count($chunk)
                );
                $this->importJob->submissionStatus->setTotals($count, $this->importCount, $this->duplicateCount);
                $this->persistImportJob();

                continue;
            }
            $this->importJob->submissionStatus->addPerformanceSample(
                'firefly_submissions',
                (microtime(true) - $submissionStartedAt) * 1000.0,
                count($batchPayload)
            );

            $results = (array) ($batchResults['results'] ?? []);
            foreach ($results as $result) {
                $batchIndex    = (int) ($result['index'] ?? -1);
                $originalIndex = $indexMap[$batchIndex] ?? -1;
                $status        = (string) ($result['status'] ?? 'error');

                if ('success' === $status) {
                    ++$this->importCount;
                    $this->rememberExternalIdsFromBatchResult($result);

                    // Log creation message consistent with the single-transaction path.
                    $data = $result['data'] ?? null;
                    if (is_array($data)) {
                        $groupId      = (int) ($data['id'] ?? 0);
                        $transactions = $data['attributes']['transactions'] ?? [];
                        foreach ($transactions as $tx) {
                            $message = sprintf(
                                'Created %s <a target="_blank" href="%s">#%d "%s"</a> (%s %s)',
                                $tx['type'] ?? 'transaction',
                                sprintf('%s/transactions/show/%d', $this->vanityURL, $groupId),
                                $groupId,
                                e((string) ($tx['description'] ?? '(no description)')),
                                $tx['currency_code'] ?? '',
                                round((float) ($tx['amount'] ?? 0), (int) ($tx['currency_decimal_places'] ?? 2))
                            );
                            $this->importJob->submissionStatus->addMessage($originalIndex, $message);
                        }
                        // Build groupInfo for tag application.
                        $groupInfo = ['group_id' => $groupId, 'journals' => []];
                        foreach ($transactions as $tx) {
                            $journalId = (int) ($tx['transaction_journal_id'] ?? 0);
                            if ($journalId > 0) {
                                $groupInfo['journals'][$journalId] = $tx['tags'] ?? [];
                            }
                        }
                        $tagStartedAt = microtime(true);
                        $this->addTagToGroups($groupInfo);
                        $this->importJob->submissionStatus->addPerformanceSample(
                            'tag_updates',
                            (microtime(true) - $tagStartedAt) * 1000.0
                        );
                    }
                } elseif ('duplicate' === $status) {
                    ++$this->duplicateCount;
                    $extId = (string) ($result['external_id'] ?? '');
                    $this->importJob->submissionStatus->addWarning(
                        $originalIndex,
                        sprintf('[a115]: Duplicate detected in batch (external_id: %s).', $extId)
                    );
                } else {
                    $errors   = (array) ($result['errors'] ?? []);
                    $errorMsg = json_encode($errors, JSON_UNESCAPED_UNICODE);
                    $this->importJob->submissionStatus->addError(
                        $originalIndex,
                        sprintf('[a117]: Batch item error: %s', $errorMsg)
                    );
                }
            }

            $this->importJob->submissionStatus->setTotals($count, $this->importCount, $this->duplicateCount);
            $this->persistImportJob();
        }
    }

    /**
     * Estimate an optimal chunk size based on the average number of transaction
     * splits per import line. Targets ~100 splits per batch request.
     */
    private function estimateChunkSize(array $lines): int
    {
        if ([] === $lines) {
            return 100;
        }
        $sample      = array_slice($lines, 0, min(10, count($lines)));
        $totalSplits = 0;
        foreach ($sample as $line) {
            $totalSplits += count($line['transactions'] ?? []);
        }
        $avgSplits = max(1, $totalSplits / count($sample));

        return max(10, min(100, (int) floor(100 / $avgSplits)));
    }

    /**
     * Record external_ids from a batch result item into the preloaded duplicate
     * index so that later items in the same import can detect them as duplicates.
     */
    private function rememberExternalIdsFromBatchResult(array $result): void
    {
        $data = $result['data'] ?? null;
        if (!is_array($data)) {
            return;
        }
        $groupId      = (int) ($data['id'] ?? 0);
        $transactions = $data['attributes']['transactions'] ?? [];
        foreach ($transactions as $tx) {
            $extId = (string) ($tx['external_id'] ?? '');
            if ('' === $extId) {
                continue;
            }
            $candidate = [
                'id'             => $groupId,
                'description'    => (string) ($tx['description'] ?? '(no description)'),
                'currency_code'  => (string) ($tx['currency_code'] ?? '(no currency)'),
                'decimal_places' => (int) ($tx['currency_decimal_places'] ?? 2),
                'amount'         => (string) ($tx['amount'] ?? '0'),
                'account_ids'    => array_filter([
                    (int) ($tx['source_id'] ?? 0),
                    (int) ($tx['destination_id'] ?? 0),
                ]),
            ];
            $this->preloadedDuplicateIndex[$extId] = $this->mergeDuplicateCandidate(
                $this->preloadedDuplicateIndex[$extId] ?? null,
                $candidate
            );
        }
    }

    /**
     * After a batch store timeout, re-check which items were already committed
     * by their external_ids and remove them from the chunk. Remaining items can
     * be safely retried via individual submission without creating duplicates.
     *
     * @param  array $chunk  Subset of import lines keyed by original index.
     *
     * @return array The chunk with already-committed items removed.
     */
    private function removeAlreadyCommittedItems(array $chunk): array
    {
        if (null === $this->batchClient) {
            return $chunk;
        }

        $chunkExternalIds = [];
        foreach ($chunk as $line) {
            foreach ($line['transactions'] ?? [] as $tx) {
                $extId = (string) ($tx['external_id'] ?? '');
                if ('' !== $extId) {
                    $chunkExternalIds[] = $extId;
                }
            }
        }

        if ([] === $chunkExternalIds) {
            return $chunk;
        }

        try {
            $recheck   = $this->batchClient->batchSearchExternalIds($chunkExternalIds);
            $committed = (array) ($recheck['results'] ?? []);
        } catch (\Throwable) {
            // Cannot re-check; fall back to individual submission where server-side dedup catches doubles.
            return $chunk;
        }

        $removedCount = 0;
        foreach ($chunk as $idx => $line) {
            foreach ($line['transactions'] ?? [] as $tx) {
                $extId = (string) ($tx['external_id'] ?? '');
                if ('' !== $extId && isset($committed[$extId]) && null !== $committed[$extId]) {
                    ++$this->importCount;
                    ++$removedCount;
                    unset($chunk[$idx]);

                    break;
                }
            }
        }

        Log::info(sprintf('Idempotent retry: %d items already committed, %d remaining for individual fallback.', $removedCount, count($chunk)));

        return $chunk;
    }
}
