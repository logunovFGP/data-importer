<?php

declare(strict_types=1);

namespace App\Services\Shared\SyncState;

use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncStateManager
{
    private const string STATE_FILE = 'sync-state.json';
    private const string KEY_DELIMITER = '::';

    /**
     * Build a stable context fingerprint from relevant provider context.
     */
    public function buildContextFingerprint(string $provider, array $context): string
    {
        $parts = [$provider];
        foreach ($context as $part) {
            $parts[] = is_scalar($part) ? (string)$part : json_encode($part, JSON_THROW_ON_ERROR);
        }

        return hash('sha256', implode(self::KEY_DELIMITER, $parts));
    }

    public function getLookBackDate(string $provider, string $contextFingerprint, string $accountId): ?Carbon
    {
        $state = $this->readState();
        $key   = $this->buildKey($provider, $contextFingerprint, $accountId);

        if (!array_key_exists($key, $state)) {
            Log::debug(sprintf('No sync state found for key %s', $key));

            return null;
        }

        $payload = $state[$key];
        if (!is_array($payload)) {
            Log::warning(sprintf('Sync state for key %s is malformed, ignoring.', $key));

            return null;
        }

        $date = $payload['last_pull_date'] ?? null;
        if (!is_string($date) || '' === trim($date)) {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (\Exception) {
            Log::warning(sprintf('Could not parse last pull date %s for key %s', $date, $key));

            return null;
        }
    }

    public function setLookBackDate(
        string $provider,
        string $contextFingerprint,
        string $accountId,
        Carbon $date
    ): void {
        $state           = $this->readState();
        $key             = $this->buildKey($provider, $contextFingerprint, $accountId);
        $current         = $date->copy()->startOfSecond();
        $existing        = $state[$key]['last_pull_date'] ?? null;
        $existingDate    = is_string($existing) ? Carbon::parse($existing) : null;
        if (null !== $existingDate && $existingDate->greaterThanOrEqualTo($current)) {
            Log::debug(sprintf(
                'Refusing to move sync cursor backwards for key %s. Existing: %s, incoming: %s',
                $key,
                $existing,
                $current->format('Y-m-d H:i:s')
            ));

            return;
        }

        $state[$key] = [
            'last_pull_date' => $current->format('Y-m-d'),
            'updated_at'     => Carbon::now()->toDateTimeString(),
            'account_id'     => $accountId,
            'provider'       => $provider,
            'context_hash'   => $contextFingerprint,
        ];

        $this->writeState($state);
        Log::info(sprintf('Updated sync cursor for key %s => %s', $key, $current->format('Y-m-d')));
    }

    public function getIncrementalDateFromCursor(?Carbon $cursor, int $lookbackDays): ?string
    {
        if (null === $cursor) {
            return null;
        }

        $lookback = max(0, $lookbackDays);
        $date     = $cursor->copy()->startOfDay();

        return 0 === $lookback ? $date->toDateString() : $date->subDays($lookback)->toDateString();
    }

    private function buildKey(string $provider, string $contextFingerprint, string $accountId): string
    {
        return sprintf('%s|%s|%s', $provider, $contextFingerprint, $accountId);
    }

    private function readState(): array
    {
        $path = storage_path(self::STATE_FILE);

        if (!file_exists($path)) {
            return [];
        }

        $raw = trim((string)file_get_contents($path));
        if ('' === $raw) {
            return [];
        }

        // Try decrypting first (encrypted format, matching ProviderSecretStore pattern).
        $json = null;
        try {
            $json = Crypt::decryptString($raw);
        } catch (Throwable) {
            // Legacy fallback: file may be stored as unencrypted JSON from before
            // encryption was added. Accept it, and it will be re-encrypted on next write.
            $json = $raw;
            Log::info('Sync state file is not encrypted; will encrypt on next write (legacy fallback).');
        }

        if (!json_validate($json)) {
            Log::warning(sprintf('Sync state JSON is invalid, resetting state file at %s', $path));

            return [];
        }

        $state = json_decode($json, true);
        if (!is_array($state)) {
            return [];
        }

        return $state;
    }

    private function writeState(array $state): void
    {
        $path = storage_path(self::STATE_FILE);
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Encrypt before writing to protect sync state at rest (matching ProviderSecretStore pattern).
        // LOCK_EX prevents corruption from concurrent imports writing simultaneously.
        $encrypted = Crypt::encryptString($json);
        file_put_contents($path, $encrypted, LOCK_EX);
    }
}
