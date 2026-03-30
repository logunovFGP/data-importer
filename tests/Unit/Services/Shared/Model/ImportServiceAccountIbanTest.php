<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shared\Model;

use App\Services\Shared\Model\ImportServiceAccount;
use Tests\TestCase;

/**
 * Test that IBAN sanitization via the extracted sanitizeIban() method works correctly.
 */
final class ImportServiceAccountIbanTest extends TestCase
{
    public function testValidIbanIsPreserved(): void
    {
        // DE89370400440532013000 is a well-known valid German IBAN
        $account = ImportServiceAccount::fromArray([
            'id'   => '1',
            'name' => 'Test',
            'iban' => 'DE89370400440532013000',
        ]);

        $this->assertSame('DE89370400440532013000', $account->iban);
    }

    public function testInvalidIbanIsCleared(): void
    {
        $account = ImportServiceAccount::fromArray([
            'id'   => '2',
            'name' => 'Test',
            'iban' => 'INVALID_IBAN_VALUE',
        ]);

        $this->assertSame('', $account->iban);
    }

    public function testEmptyIbanStaysEmpty(): void
    {
        $account = ImportServiceAccount::fromArray([
            'id'   => '3',
            'name' => 'Test',
            'iban' => '',
        ]);

        $this->assertSame('', $account->iban);
    }

    public function testNullIbanBecomesEmpty(): void
    {
        $account = ImportServiceAccount::fromArray([
            'id'   => '4',
            'name' => 'Test',
            'iban' => null,
        ]);

        $this->assertSame('', $account->iban);
    }

    public function testMissingIbanKeyBecomesEmpty(): void
    {
        $account = ImportServiceAccount::fromArray([
            'id'   => '5',
            'name' => 'Test',
        ]);

        $this->assertSame('', $account->iban);
    }
}
