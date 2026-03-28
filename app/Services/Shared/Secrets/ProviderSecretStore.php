<?php

declare(strict_types=1);

namespace App\Services\Shared\Secrets;

use App\Services\Session\Constants;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProviderSecretStore
{
    private const string STORAGE_FILE = 'private/provider-secrets.enc';

    public function get(string $provider, string $key, mixed $default = null): mixed
    {
        $provider = trim($provider);
        $key      = trim($key);
        if ('' === $provider || '' === $key) {
            return $default;
        }

        $all      = $this->readAll();
        $scopeKey = $this->scopeKey();
        if (!isset($all[$scopeKey]) || !is_array($all[$scopeKey])) {
            return $default;
        }
        if (!isset($all[$scopeKey][$provider]) || !is_array($all[$scopeKey][$provider])) {
            return $default;
        }
        if (!array_key_exists($key, $all[$scopeKey][$provider])) {
            return $default;
        }

        return $all[$scopeKey][$provider][$key];
    }

    public function put(string $provider, string $key, mixed $value): void
    {
        $provider = trim($provider);
        $key      = trim($key);
        if ('' === $provider || '' === $key) {
            return;
        }

        $all                        = $this->readAll();
        $scopeKey                   = $this->scopeKey();
        $all[$scopeKey]           ??= [];
        $all[$scopeKey][$provider] ??= [];
        $all[$scopeKey][$provider][$key] = $value;

        $this->writeAll($all);
    }

    public function forgetProvider(string $provider): void
    {
        $provider = trim($provider);
        if ('' === $provider) {
            return;
        }
        $all      = $this->readAll();
        $scopeKey = $this->scopeKey();
        if (!isset($all[$scopeKey]) || !is_array($all[$scopeKey])) {
            return;
        }
        if (!array_key_exists($provider, $all[$scopeKey])) {
            return;
        }
        unset($all[$scopeKey][$provider]);
        if (0 === count($all[$scopeKey])) {
            unset($all[$scopeKey]);
        }

        $this->writeAll($all);
    }

    private function readAll(): array
    {
        $disk = $this->disk();
        if (!$disk->exists(self::STORAGE_FILE)) {
            return [];
        }

        $encrypted = trim((string)$disk->get(self::STORAGE_FILE));
        if ('' === $encrypted) {
            return [];
        }

        try {
            $json = Crypt::decryptString($encrypted);
        } catch (Throwable $e) {
            Log::warning(sprintf('Provider secret storage decrypt failed: %s', $e->getMessage()));

            return [];
        }

        if (!json_validate($json)) {
            Log::warning('Provider secret storage payload is not valid JSON.');

            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function writeAll(array $payload): void
    {
        try {
            $json      = json_encode($payload, JSON_THROW_ON_ERROR);
            $encrypted = Crypt::encryptString($json);
            $this->disk()->put(self::STORAGE_FILE, $encrypted);
        } catch (Throwable $e) {
            Log::warning(sprintf('Provider secret storage write failed: %s', $e->getMessage()));
        }
    }

    private function scopeKey(): string
    {
        $baseUrl  = (string)($this->sessionValue(Constants::SESSION_BASE_URL) ?? config('importer.url', ''));
        $clientId = (string)($this->sessionValue(Constants::SESSION_CLIENT_ID) ?? config('importer.client_id', ''));
        $baseUrl  = strtolower(rtrim(trim($baseUrl), '/'));
        if ('' === $baseUrl) {
            $baseUrl = 'default';
        }

        return hash('sha256', sprintf('%s|%s', $baseUrl, $clientId));
    }

    private function sessionValue(string $key): mixed
    {
        try {
            if (app()->bound('session')) {
                return session()->get($key);
            }
        } catch (Throwable) {
            // no active HTTP session context (eg. CLI worker)
        }

        return null;
    }

    private function disk(): Filesystem
    {
        return Storage::disk('local');
    }
}
