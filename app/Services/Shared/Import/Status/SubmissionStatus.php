<?php

/*
 * SubmissionStatus.php
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

namespace App\Services\Shared\Import\Status;

use Illuminate\Support\Facades\Log;

class SubmissionStatus
{
    public const string SUBMISSION_DONE    = 'submission_done';
    public const string SUBMISSION_ERRORED = 'submission_errored';
    public const string SUBMISSION_RUNNING = 'submission_running';
    public const string SUBMISSION_WAITING = 'waiting_to_start';
    public array   $errors                 = [];
    public array   $messages               = [];
    private string $status;
    public array   $warnings               = [];
    public int     $currentTransaction     = 0;
    public int     $totalTransactions      = 0;
    public int     $uniqueTransactions     = 0;
    public int     $duplicateTransactions  = 0;
    public int     $progressPercentage     = 0;
    public array   $performance            = [];
    public array   $activityLog            = [];
    public array   $transactionBoard       = [];
    public int     $transactionBoardTotal  = 0;
    public int     $transactionBoardHidden = 0;

    /**
     * ImportJobStatus constructor.
     */
    public function __construct()
    {
        $this->status = self::SUBMISSION_WAITING;
        $this->performance = $this->defaultPerformanceBuckets();
    }

    public function setStatus(string $status): void
    {
        Log::debug(sprintf('Set submission status to "%s"', $status));
        $this->status = $status;
    }

    public function addError(int $index, string $error): void
    {
        $lineNo                 = $index + 1;
        Log::debug(sprintf('Add error on index #%d (line no. %d): %s', $index, $lineNo, $error));
        $this->errors[$index] ??= [];
        $this->errors[$index][] = $error;
    }

    public function addWarning(int $index, string $warning): void
    {
        $lineNo                   = $index + 1;
        Log::debug(sprintf('Add warning on index #%d (line no. %d): %s', $index, $lineNo, $warning));
        $this->warnings[$index] ??= [];
        $this->warnings[$index][] = $warning;
    }

    public function addMessage(int $index, string $message): void
    {
        $lineNo                   = $index + 1;
        Log::debug(sprintf('Add message on index #%d (line no. %d): %s', $index, $lineNo, $message));
        $this->messages[$index] ??= [];
        $this->messages[$index][] = $message;
    }

    public function updateProgress(int $currentTransaction, int $totalTransactions): void
    {
        Log::debug(sprintf('Update progress: %d/%d transactions', $currentTransaction, $totalTransactions));
        $this->currentTransaction = $currentTransaction;
        $this->totalTransactions  = $totalTransactions;
        $this->progressPercentage = $totalTransactions > 0 ? (int)round(($currentTransaction / $totalTransactions) * 100) : 0;
    }

    /**
     * @return static
     */
    public static function fromArray(array $array): self
    {
        $config                     = new self();
        $config->status             = $array['status'];
        $config->errors             = $array['errors'] ?? [];
        $config->warnings           = $array['warnings'] ?? [];
        $config->messages           = $array['messages'] ?? [];
        $config->currentTransaction = $array['currentTransaction'] ?? 0;
        $config->totalTransactions  = $array['totalTransactions'] ?? 0;
        $config->uniqueTransactions = $array['uniqueTransactions'] ?? 0;
        $config->duplicateTransactions = $array['duplicateTransactions'] ?? 0;
        $config->progressPercentage = $array['progressPercentage'] ?? 0;
        $config->performance        = $array['performance'] ?? $config->defaultPerformanceBuckets();
        $config->activityLog        = $array['activity_log'] ?? [];
        $config->transactionBoard   = $array['transaction_board'] ?? [];
        $config->transactionBoardTotal = $array['transaction_board_total'] ?? 0;
        $config->transactionBoardHidden = $array['transaction_board_hidden'] ?? 0;

        return $config;
    }

    public function toArray(): array
    {
        return [
            'status'             => $this->status,
            'errors'             => $this->errors,
            'warnings'           => $this->warnings,
            'messages'           => $this->messages,
            'currentTransaction' => $this->currentTransaction,
            'totalTransactions'  => $this->totalTransactions,
            'uniqueTransactions' => $this->uniqueTransactions,
            'duplicateTransactions' => $this->duplicateTransactions,
            'progressPercentage' => $this->progressPercentage,
            'performance'        => $this->performance,
            'activity_log'       => $this->activityLog,
            'transaction_board'  => $this->transactionBoard,
            'transaction_board_total' => $this->transactionBoardTotal,
            'transaction_board_hidden' => $this->transactionBoardHidden,
        ];
    }

    public function addActivity(string $message): void
    {
        $this->activityLog[] = [
            'time'    => date('H:i:s'),
            'message' => $message,
        ];
        if (count($this->activityLog) > 200) {
            $this->activityLog = array_slice($this->activityLog, -200);
        }
    }

    public function addBoardEntry(array $entry): void
    {
        $this->transactionBoardTotal++;
        $this->transactionBoard[] = $entry;
        if (count($this->transactionBoard) > 100) {
            $this->transactionBoard = array_slice($this->transactionBoard, -100);
        }
        $this->transactionBoardHidden = max(0, $this->transactionBoardTotal - count($this->transactionBoard));
    }

    public function updateBoardEntryStatus(string $txId, string $status, string $message = ''): void
    {
        foreach ($this->transactionBoard as &$entry) {
            if (($entry['tx_id'] ?? '') === $txId) {
                $entry['status']  = $status;
                $entry['message'] = $message;
                break;
            }
        }
        unset($entry);
    }

    public function setTotals(int $totalTransactions, int $uniqueTransactions, int $duplicateTransactions): void
    {
        $this->totalTransactions      = max(0, $totalTransactions);
        $this->uniqueTransactions     = max(0, $uniqueTransactions);
        $this->duplicateTransactions  = max(0, $duplicateTransactions);
    }

    public function addPerformanceSample(string $bucket, float $milliseconds, int $count = 1): void
    {
        if (!array_key_exists($bucket, $this->performance)) {
            $this->performance[$bucket] = ['count' => 0, 'milliseconds' => 0.0, 'average_ms' => 0.0];
        }
        $currentCount                                  = max(0, (int)($this->performance[$bucket]['count'] ?? 0));
        $currentMilliseconds                           = (float)($this->performance[$bucket]['milliseconds'] ?? 0.0);
        $newCount                                      = $currentCount + max(1, $count);
        $newMilliseconds                               = $currentMilliseconds + max(0.0, $milliseconds);
        $this->performance[$bucket]['count']           = $newCount;
        $this->performance[$bucket]['milliseconds']    = round($newMilliseconds, 3);
        $this->performance[$bucket]['average_ms']      = round($newMilliseconds / max(1, $newCount), 3);
    }

    public function setPerformanceMeta(string $bucket, array $meta): void
    {
        if (!array_key_exists($bucket, $this->performance)) {
            $this->performance[$bucket] = ['count' => 0, 'milliseconds' => 0.0, 'average_ms' => 0.0];
        }
        $this->performance[$bucket]['meta'] = $meta;
    }

    private function defaultPerformanceBuckets(): array
    {
        return [
            'duplicate_checks'        => ['count' => 0, 'milliseconds' => 0.0, 'average_ms' => 0.0],
            'firefly_submissions'     => ['count' => 0, 'milliseconds' => 0.0, 'average_ms' => 0.0],
            'tag_updates'             => ['count' => 0, 'milliseconds' => 0.0, 'average_ms' => 0.0],
            'disk_saves'              => ['count' => 0, 'milliseconds' => 0.0, 'average_ms' => 0.0],
            'duplicate_index_preload' => ['count' => 0, 'milliseconds' => 0.0, 'average_ms' => 0.0, 'meta' => []],
        ];
    }
}
