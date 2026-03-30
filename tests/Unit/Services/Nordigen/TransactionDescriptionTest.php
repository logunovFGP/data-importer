<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Nordigen;

use App\Services\Nordigen\Model\Transaction;
use Tests\TestCase;

/**
 * Tests for Fix #6 (str_contains arguments reversed) and Fix #7 (preg_replace missing delimiters).
 *
 * Fix #6: str_contains("\n", $description) had haystack and needle swapped.
 *         Fixed to str_contains($description, "\n").
 *
 * Fix #7: preg_replace("\r", '', $description) used a regex function without delimiters.
 *         Fixed to str_replace("\r", '', $description).
 *
 * @internal
 * @coversNothing
 */
final class TransactionDescriptionTest extends TestCase
{
    /**
     * Create a Nordigen Transaction from a minimal array.
     */
    private function makeTransaction(array $overrides = []): Transaction
    {
        $defaults = [
            'key'                        => 'booked',
            'transactionId'              => 'test-tx-001',
            'internalTransactionId'      => 'internal-001',
            'bookingDate'                => '2025-10-16',
            'transactionAmount'          => ['amount' => '-12.50', 'currency' => 'EUR'],
            'remittanceInformationUnstructured' => 'Single line description',
            'additionalInformation'      => '',
        ];

        return Transaction::fromArray(array_merge($defaults, $overrides));
    }

    public function testGetCleanDescriptionRemovesNewlines(): void
    {
        $tx = $this->makeTransaction([
            'remittanceInformationUnstructured' => "Line one\nLine two\nLine three",
        ]);

        $clean = $tx->getCleanDescription();

        // Newlines should be replaced with spaces
        $this->assertStringNotContainsString("\n", $clean);
        $this->assertStringContainsString('Line one', $clean);
        $this->assertStringContainsString('Line two', $clean);
        $this->assertStringContainsString('Line three', $clean);
    }

    public function testGetCleanDescriptionRemovesCarriageReturns(): void
    {
        $tx = $this->makeTransaction([
            'remittanceInformationUnstructured' => "Line one\r\nLine two\r\nLine three",
        ]);

        $clean = $tx->getCleanDescription();

        // \r should be removed (Fix #7) and \n replaced with spaces
        $this->assertStringNotContainsString("\r", $clean);
        $this->assertStringNotContainsString("\n", $clean);
        $this->assertStringContainsString('Line one', $clean);
    }

    public function testGetCleanDescriptionReturnsSingleLineUnchanged(): void
    {
        $tx = $this->makeTransaction([
            'remittanceInformationUnstructured' => 'No newlines here',
        ]);

        $clean = $tx->getCleanDescription();
        $this->assertSame('No newlines here', $clean);
    }

    public function testGetNotesIncludesMultiLineDescription(): void
    {
        // When description contains newlines, getNotes() should include the full description.
        // The old code with reversed str_contains would never detect the newline.
        $tx = $this->makeTransaction([
            'remittanceInformationUnstructured' => "Payment ref 123\nMerchant: Shop ABC",
            'additionalInformation'             => 'Additional info text',
        ]);

        $notes = $tx->getNotes();

        // Notes should contain additional info AND the multiline description
        $this->assertStringContainsString('Additional info text', $notes);
        $this->assertStringContainsString("Payment ref 123\nMerchant: Shop ABC", $notes);
    }

    public function testGetNotesDoesNotIncludeSingleLineDescription(): void
    {
        // When description has no newlines, it should NOT be appended to notes.
        $tx = $this->makeTransaction([
            'remittanceInformationUnstructured' => 'Simple payment',
            'additionalInformation'             => 'Some additional info',
        ]);

        $notes = $tx->getNotes();

        $this->assertStringContainsString('Some additional info', $notes);
        // The single-line description should not appear twice (once in description, once in notes)
        $this->assertStringNotContainsString('Simple payment', $notes);
    }

    public function testStrContainsWithSwappedArgsWouldFail(): void
    {
        // Demonstrate the bug: str_contains("\n", $description) always returns false
        // because "\n" (the single-char haystack) never contains a multi-char description.
        $description = "Line one\nLine two";

        // The WRONG way (old code): haystack="\n", needle=$description
        $wrongResult = str_contains("\n", $description);
        $this->assertFalse($wrongResult, 'str_contains with swapped args should always be false');

        // The CORRECT way (fix): haystack=$description, needle="\n"
        $correctResult = str_contains($description, "\n");
        $this->assertTrue($correctResult, 'str_contains with correct args should detect newline');
    }
}
