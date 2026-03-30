<?php

/*
 * ConversionStatus.php
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

namespace App\Services\Shared\Conversion;

use App\Exceptions\ImporterErrorException;
use App\Services\Shared\Status\StatusTrackerTrait;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;

/**
 * Class ConversionStatus
 */
class ConversionStatus
{
    use StatusTrackerTrait;

    public const string PULL_STEP_PENDING = 'pending';
    public const string PULL_STEP_RUNNING = 'running';
    public const string PULL_STEP_DONE = 'done';
    public const string PULL_STEP_ERROR = 'error';
    public const string CONVERSION_DONE    = 'conv_done';

    public const string CONVERSION_ERRORED = 'conv_errored';

    public const string CONVERSION_RUNNING = 'conv_running';

    public const string CONVERSION_WAITING = 'waiting_to_start';
    public array   $errors                  = [];
    public array   $messages                = [];
    private string $status;
    public array   $warnings                = [];
    public array   $rateLimits              = [];
    public array   $pullChecklist           = [];
    public array   $pullProgress            = [];
    public array   $pullCursorCandidates    = [];
    public array   $activityLog             = [];
    public array   $transactionBoard        = [];
    public int     $transactionBoardTotal   = 0;
    public int     $transactionBoardHidden  = 0;

    /**
     * ConversionStatus constructor.
     */
    public function __construct()
    {
        $this->status = self::CONVERSION_WAITING;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        Log::debug(sprintf('Set conversion status: "%s"', $status));
        if (self::CONVERSION_RUNNING === $this->status && self::CONVERSION_WAITING === $status) {
            throw new ImporterErrorException(sprintf('Cowardly refuse to update conversion status from "%s" to "%s"', $this->status, $status));
        }
        $this->status = $status;
    }

    /**
     * @return static
     */
    public static function fromArray(array $array): self
    {
        $config             = new self();
        $config->status               = $array['status'];
        $config->errors               = $array['errors'] ?? [];
        $config->warnings             = $array['warnings'] ?? [];
        $config->messages             = $array['messages'] ?? [];
        $config->rateLimits           = $array['rate_limits'] ?? [];
        $config->pullChecklist        = $array['pull_checklist'] ?? [];
        $config->pullProgress         = $array['pull_progress'] ?? [];
        $config->pullCursorCandidates = $array['pull_cursor_candidates'] ?? [];
        $config->activityLog          = $array['activity_log'] ?? [];
        $config->transactionBoard     = $array['transaction_board'] ?? [];
        $config->transactionBoardTotal = $array['transaction_board_total'] ?? 0;
        $config->transactionBoardHidden = $array['transaction_board_hidden'] ?? 0;

        return $config;
    }

    public function toArray(): array
    {
        return [
            'status'      => $this->status,
            'errors'      => $this->errors,
            'warnings'    => $this->warnings,
            'messages'    => $this->messages,
            'rate_limits' => $this->rateLimits,
            'pull_checklist' => $this->pullChecklist,
            'pull_progress' => $this->pullProgress,
            'pull_cursor_candidates' => $this->pullCursorCandidates,
            'activity_log' => $this->activityLog,
            'transaction_board' => $this->transactionBoard,
            'transaction_board_total' => $this->transactionBoardTotal,
            'transaction_board_hidden' => $this->transactionBoardHidden,
        ];
    }

    public function setPullProgress(int $total, int $done = 0, string $status = self::PULL_STEP_PENDING): void
    {
        $total = max(0, $total);
        $done  = max(0, min($total, $done));
        $this->pullProgress = [
            'total'  => $total,
            'done'   => $done,
            'status' => $status,
        ];
        Log::debug(sprintf('Updated pull progress to %s/%s (%s).', $done, $total, $status));
    }

    public function incrementPullProgress(int $amount = 1): void
    {
        $total          = (int)($this->pullProgress['total'] ?? 0);
        $current        = (int)($this->pullProgress['done'] ?? 0);
        $this->pullProgress = [
            'total'  => $total,
            'done'   => max(0, min($total, $current + $amount)),
            'status' => self::PULL_STEP_RUNNING,
        ];
        Log::debug(sprintf('Incremented pull progress to %s/%s.', $current + $amount, $total));
    }

    public function setPullChecklistItem(string $accountId, string $status, string $message = '', array $meta = []): void
    {
        $this->pullChecklist[$accountId] = [
            'account_id' => $accountId,
            'status'     => $status,
            'message'    => $message,
            'meta'       => $meta,
            'updated_at' => now()->format(DateTimeInterface::ATOM),
        ];
    }

    public function addPullCursorCandidate(string $accountId, string $date): void
    {
        $this->pullCursorCandidates[$accountId] = $date;
    }

    public function getPullCursorCandidates(): array
    {
        return $this->pullCursorCandidates;
    }

    public function getPullProgress(): array
    {
        return $this->pullProgress;
    }

    public function getPullProgressPercentage(): int
    {
        $total = (int)($this->pullProgress['total'] ?? 0);
        if (0 === $total) {
            return 0;
        }
        $done = (int)($this->pullProgress['done'] ?? 0);

        return (int)round(($done / $total) * 100);
    }

    public function addRateLimit(int $index, string $message): void
    {
        Log::error(sprintf('[c] Add rate limit message to index #%d: %s', $index, $message));
        $this->rateLimits         ??= [];
        $this->rateLimits[$index] ??= [];
        $this->rateLimits[$index][] = $message;
    }
}
