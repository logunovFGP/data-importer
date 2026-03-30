<?php

declare(strict_types=1);

namespace App\Services\Shared\Status;

use Illuminate\Support\Facades\Log;

/**
 * Shared status tracking methods for ConversionStatus and SubmissionStatus.
 *
 * Classes using this trait must declare the following public array properties:
 *   - $errors, $warnings, $messages, $activityLog, $transactionBoard
 *   - $transactionBoardTotal (int), $transactionBoardHidden (int)
 */
trait StatusTrackerTrait
{
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
}
