<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shared\SyncState;

use App\Services\Shared\SyncState\SyncStateManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Tests for Fix #12: SyncStateManager writes encrypted state with file locking.
 *
 * Verifies that:
 * 1. State is encrypted when written to disk.
 * 2. Encrypted state can be read back with full integrity.
 * 3. Legacy unencrypted JSON is accepted (fallback) and will be re-encrypted on next write.
 *
 * @internal
 * @coversNothing
 */
final class SyncStateManagerSecurityTest extends TestCase
{
    private string $stateFilePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateFilePath = storage_path('sync-state.json');

        // Ensure clean state for each test.
        if (file_exists($this->stateFilePath)) {
            unlink($this->stateFilePath);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->stateFilePath)) {
            unlink($this->stateFilePath);
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testWriteStateProducesEncryptedFile(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 30, 12, 0, 0));

        $manager = new SyncStateManager();
        $fingerprint = $manager->buildContextFingerprint('test-provider', ['ctx1']);

        $manager->setLookBackDate('test-provider', $fingerprint, 'acct-001', Carbon::parse('2026-03-15'));

        // The file on disk must NOT be valid JSON (it should be encrypted).
        $raw = file_get_contents($this->stateFilePath);
        $this->assertNotEmpty($raw);
        $this->assertFalse(json_validate($raw), 'State file must be encrypted, not raw JSON.');

        // Decrypting the file content must yield valid JSON with the expected state.
        $json = Crypt::decryptString($raw);
        $this->assertTrue(json_validate($json));
        $state = json_decode($json, true);
        $this->assertIsArray($state);

        // Find the key and verify payload.
        $keys = array_keys($state);
        $this->assertCount(1, $keys);
        $entry = $state[$keys[0]];
        $this->assertSame('2026-03-15', $entry['last_pull_date']);
        $this->assertSame('acct-001', $entry['account_id']);
        $this->assertSame('test-provider', $entry['provider']);
    }

    public function testReadStateDecryptsAndReturnsCorrectDate(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 30, 12, 0, 0));

        $manager = new SyncStateManager();
        $fingerprint = $manager->buildContextFingerprint('provider-x', ['wallet-abc']);

        $writeDate = Carbon::parse('2026-03-20');
        $manager->setLookBackDate('provider-x', $fingerprint, 'acct-abc', $writeDate);

        // Read back via the public API.
        $readDate = $manager->getLookBackDate('provider-x', $fingerprint, 'acct-abc');
        $this->assertNotNull($readDate);
        $this->assertSame('2026-03-20', $readDate->toDateString());
    }

    public function testLegacyUnencryptedJsonFallback(): void
    {
        // Write raw (unencrypted) JSON directly to the state file, simulating pre-encryption data.
        $legacyState = [
            'legacy-provider|abc123|acct-legacy' => [
                'last_pull_date' => '2026-01-10',
                'updated_at'     => '2026-01-10 08:00:00',
                'account_id'     => 'acct-legacy',
                'provider'       => 'legacy-provider',
                'context_hash'   => 'abc123',
            ],
        ];
        file_put_contents($this->stateFilePath, json_encode($legacyState, JSON_PRETTY_PRINT));

        $manager = new SyncStateManager();
        $date = $manager->getLookBackDate('legacy-provider', 'abc123', 'acct-legacy');

        // The legacy fallback must still read the data correctly.
        $this->assertNotNull($date);
        $this->assertSame('2026-01-10', $date->toDateString());
    }

    public function testLegacyDataIsReEncryptedOnNextWrite(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 30, 12, 0, 0));

        // Write raw JSON to simulate legacy state.
        $legacyState = [
            'legacy-provider|abc123|acct-legacy' => [
                'last_pull_date' => '2026-01-10',
                'updated_at'     => '2026-01-10 08:00:00',
                'account_id'     => 'acct-legacy',
                'provider'       => 'legacy-provider',
                'context_hash'   => 'abc123',
            ],
        ];
        file_put_contents($this->stateFilePath, json_encode($legacyState, JSON_PRETTY_PRINT));

        $manager = new SyncStateManager();
        $fingerprint = $manager->buildContextFingerprint('new-provider', ['ctx']);

        // Writing a new entry should re-encrypt the entire file (including legacy data).
        $manager->setLookBackDate('new-provider', $fingerprint, 'acct-new', Carbon::parse('2026-03-25'));

        // After write, the file must no longer be raw JSON.
        $raw = file_get_contents($this->stateFilePath);
        $this->assertFalse(json_validate($raw), 'After write, legacy data must be re-encrypted.');

        // Both old and new data must be readable.
        $oldDate = $manager->getLookBackDate('legacy-provider', 'abc123', 'acct-legacy');
        $newDate = $manager->getLookBackDate('new-provider', $fingerprint, 'acct-new');

        $this->assertNotNull($oldDate);
        $this->assertSame('2026-01-10', $oldDate->toDateString());
        $this->assertNotNull($newDate);
        $this->assertSame('2026-03-25', $newDate->toDateString());
    }
}
