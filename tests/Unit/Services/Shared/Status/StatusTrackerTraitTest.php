<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shared\Status;

use App\Services\Shared\Conversion\ConversionStatus;
use App\Services\Shared\Import\Status\SubmissionStatus;
use Tests\TestCase;

/**
 * Test that the StatusTrackerTrait methods work correctly in both consuming classes.
 */
final class StatusTrackerTraitTest extends TestCase
{
    public function testAddErrorOnConversionStatus(): void
    {
        $status = new ConversionStatus();
        $status->addError(0, 'Something went wrong');

        $this->assertCount(1, $status->errors[0]);
        $this->assertSame('Something went wrong', $status->errors[0][0]);
    }

    public function testAddErrorOnSubmissionStatus(): void
    {
        $status = new SubmissionStatus();
        $status->addError(2, 'Test error');

        $this->assertCount(1, $status->errors[2]);
        $this->assertSame('Test error', $status->errors[2][0]);
    }

    public function testAddWarning(): void
    {
        $status = new ConversionStatus();
        $status->addWarning(1, 'A warning');

        $this->assertCount(1, $status->warnings[1]);
        $this->assertSame('A warning', $status->warnings[1][0]);
    }

    public function testAddMessage(): void
    {
        $status = new SubmissionStatus();
        $status->addMessage(0, 'Info message');

        $this->assertCount(1, $status->messages[0]);
        $this->assertSame('Info message', $status->messages[0][0]);
    }

    public function testAddActivity(): void
    {
        $status = new ConversionStatus();
        $status->addActivity('Step 1 complete');

        $this->assertCount(1, $status->activityLog);
        $this->assertSame('Step 1 complete', $status->activityLog[0]['message']);
        $this->assertArrayHasKey('time', $status->activityLog[0]);
    }

    public function testActivityLogCapsAt200(): void
    {
        $status = new SubmissionStatus();
        for ($i = 0; $i < 210; $i++) {
            $status->addActivity("Activity $i");
        }

        $this->assertCount(200, $status->activityLog);
        // The first 10 should have been trimmed
        $this->assertSame('Activity 10', $status->activityLog[0]['message']);
    }

    public function testAddBoardEntry(): void
    {
        $status = new ConversionStatus();
        $entry  = ['tx_id' => 'tx1', 'amount' => '100', 'status' => 'fetched', 'message' => ''];
        $status->addBoardEntry($entry);

        $this->assertCount(1, $status->transactionBoard);
        $this->assertSame(1, $status->transactionBoardTotal);
        $this->assertSame(0, $status->transactionBoardHidden);
        $this->assertSame('tx1', $status->transactionBoard[0]['tx_id']);
    }

    public function testBoardEntryCapsAt100(): void
    {
        $status = new SubmissionStatus();
        for ($i = 0; $i < 110; $i++) {
            $status->addBoardEntry(['tx_id' => "tx$i", 'status' => 'fetched', 'message' => '']);
        }

        $this->assertCount(100, $status->transactionBoard);
        $this->assertSame(110, $status->transactionBoardTotal);
        $this->assertSame(10, $status->transactionBoardHidden);
    }

    public function testUpdateBoardEntryStatus(): void
    {
        $status = new ConversionStatus();
        $status->addBoardEntry(['tx_id' => 'abc', 'status' => 'fetched', 'message' => '']);
        $status->addBoardEntry(['tx_id' => 'def', 'status' => 'fetched', 'message' => '']);

        $status->updateBoardEntryStatus('abc', 'submitted', 'OK');

        $this->assertSame('submitted', $status->transactionBoard[0]['status']);
        $this->assertSame('OK', $status->transactionBoard[0]['message']);
        // Second entry unchanged
        $this->assertSame('fetched', $status->transactionBoard[1]['status']);
    }

    public function testUpdateBoardEntryStatusWithUnknownId(): void
    {
        $status = new SubmissionStatus();
        $status->addBoardEntry(['tx_id' => 'xyz', 'status' => 'fetched', 'message' => '']);

        // Should not throw or change anything
        $status->updateBoardEntryStatus('nonexistent', 'error', 'Not found');

        $this->assertSame('fetched', $status->transactionBoard[0]['status']);
    }
}
