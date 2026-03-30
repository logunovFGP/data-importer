<?php

declare(strict_types=1);

namespace App\Services\Shared\Preflight;

use App\Exceptions\ImporterHttpException;
use App\Models\ImportJob;
use App\Services\BasisBank\Authentication\SecretManager as BasisBankSecretManager;
use App\Services\BasisBank\Request\GetTransactionsRequest as BasisBankGetTransactionsRequest;
use App\Services\Shared\Configuration\Configuration;
use App\Services\Shared\Secrets\ProviderSecretStore;
use App\Services\Shared\Support\CurrencyCode;
use App\Services\TBank\Authentication\SecretManager as TBankSecretManager;
use App\Services\TBank\Request\GetTransactionsRequest as TBankGetTransactionsRequest;
use App\Services\TRC20\Authentication\SecretManager as TRC20SecretManager;
use App\Services\TRC20\Request\GetTransactionsRequest as TRC20GetTransactionsRequest;
use Illuminate\Support\Facades\Log;

class ProviderCurrencyPreflightService
{
    private const string STORE_KEY = 'account_currency_overrides';
    private const int SAMPLE_SIZE = 10;
    private const array SUPPORTED_FLOWS = ['basisbank', 'tbank', 'trc20'];

    public function __construct(private readonly ProviderSecretStore $secretStore) {}

    public function supportsFlow(string $flow): bool
    {
        return in_array(strtolower(trim($flow)), self::SUPPORTED_FLOWS, true);
    }

    public function evaluate(ImportJob $importJob, array $manualPayload = [], bool $persistManual = false): array
    {
        $flow = strtolower(trim($importJob->getFlow()));
        if (!$this->supportsFlow($flow)) {
            return [
                'supported'           => false,
                'entries'             => [],
                'errors'              => [],
                'has_blocking_errors' => false,
                'changed'             => false,
                'currency_options'    => [],
            ];
        }

        $configuration = $importJob->getConfiguration();
        if (!$configuration instanceof Configuration) {
            return [
                'supported'           => true,
                'entries'             => [],
                'errors'              => ['Currency preflight failed: import configuration is not available.'],
                'has_blocking_errors' => true,
                'changed'             => false,
                'currency_options'    => [],
            ];
        }

        $serviceAccounts    = $importJob->getServiceAccounts();
        $applicationAccounts = $importJob->getApplicationAccounts();
        $configuredAccounts = $configuration->getAccounts();
        $newAccounts        = $configuration->getNewAccounts();
        $manualCode         = $this->normalizeManualMap($manualPayload['code'] ?? []);
        $manualCustomCode   = $this->normalizeManualMap($manualPayload['custom_code'] ?? []);
        $manualDetails      = $this->normalizeManualMap($manualPayload['details'] ?? []);
        $manualFingerprint  = $this->normalizeManualMap($manualPayload['fingerprint'] ?? []);

        $entries              = [];
        $errors               = [];
        $serviceAccountsChanged = false;
        $newAccountsChanged   = false;
        $mappingChanged       = false;

        $serviceMetas = [];
        foreach ($serviceAccounts as $index => $serviceAccount) {
            $meta = $this->extractServiceAccountMeta($serviceAccount);
            if ('' === $meta['id']) {
                continue;
            }
            $serviceMetas[$index] = $meta;
        }

        $legacyMigration = $this->migrateLegacyAccountMappings($configuredAccounts, array_values($serviceMetas));
        $legacyStates    = is_array($legacyMigration['states'] ?? null) ? $legacyMigration['states'] : [];
        $mappingChanged  = (bool)($legacyMigration['changed'] ?? false);

        foreach ($serviceAccounts as $index => $serviceAccount) {
            $meta = $serviceMetas[$index] ?? $this->extractServiceAccountMeta($serviceAccount);
            if ('' === $meta['id']) {
                continue;
            }

            $accountId    = $meta['id'];
            $accountName  = '' !== $meta['name'] ? $meta['name'] : $accountId;
            $fingerprint  = $this->buildFingerprint($flow, $meta);
            $selected     = array_key_exists($accountId, $configuredAccounts);
            $mappedId     = (int)($configuredAccounts[$accountId] ?? 0);
            $mappedCurrency = $this->resolveFireflyCurrency($applicationAccounts, $mappedId);

            $override       = $this->getOverride($flow, $fingerprint);
            $overrideCode   = $this->normalizeCurrency($override['currency'] ?? '');
            $overrideDetails = trim((string)($override['details'] ?? ''));

            $postedFingerprint = trim((string)($manualFingerprint[$accountId] ?? ''));
            $postedCustomCode  = $this->normalizeCurrency($manualCustomCode[$accountId] ?? '');
            $postedCode        = '' !== $postedCustomCode ? $postedCustomCode : $this->normalizeCurrency($manualCode[$accountId] ?? '');
            $postedDetails     = trim((string)($manualDetails[$accountId] ?? ''));
            $manualAccepted    = '' !== $postedCode && ('' === $postedFingerprint || $postedFingerprint === $fingerprint);

            $sourceCurrency = '';
            $sourceKind     = 'none';
            $sampleCurrencies = [];
            $sampleError      = '';
            $requiresManual   = false;
            $details          = $overrideDetails;

            if ($manualAccepted) {
                $sourceCurrency = $postedCode;
                $sourceKind     = 'manual';
                $details        = $postedDetails;
                if ($persistManual) {
                    $this->putOverride($flow, $fingerprint, $sourceCurrency, $details, 'manual');
                }
            } elseif ('' !== $overrideCode) {
                $sourceCurrency = $overrideCode;
                $sourceKind     = 'stored';
            } else {
                $accountCurrency = $this->normalizeCurrency($meta['currency']);
                if ('' !== $accountCurrency) {
                    $sourceCurrency = $accountCurrency;
                    $sourceKind     = 'account';
                } else {
                    [$sampleCurrencies, $sampleError] = $this->sampleAccountCurrencies($flow, $accountId, $configuration);
                    $sampleError = $this->sanitizeSampleError($sampleError);
                    if (1 === count($sampleCurrencies)) {
                        $sourceCurrency = $sampleCurrencies[0];
                        $sourceKind     = 'sample';
                    } elseif (count($sampleCurrencies) > 1) {
                        $sourceKind     = 'sample_mixed';
                        $requiresManual = true;
                    } else {
                        $sourceKind     = 'sample_empty';
                        $requiresManual = true;
                    }
                }
            }

            if ('sample' === $sourceKind && '' !== $sourceCurrency) {
                $this->putOverride($flow, $fingerprint, $sourceCurrency, '', 'sample');
            }

            if ('' !== $sourceCurrency) {
                $serviceAccountsChanged = $this->applyServiceAccountCurrency($serviceAccounts[$index], $sourceCurrency) || $serviceAccountsChanged;
                if ($selected && 0 === $mappedId) {
                    if (!array_key_exists($accountId, $newAccounts) || !is_array($newAccounts[$accountId])) {
                        $newAccounts[$accountId] = [
                            'create'          => '1',
                            'name'            => $accountName,
                            'type'            => 'asset',
                            'currency'        => $sourceCurrency,
                            'opening_balance' => '0.00',
                        ];
                        $newAccountsChanged = true;
                    }
                    $currentNewAccountCurrency = $this->normalizeCurrency($newAccounts[$accountId]['currency'] ?? '');
                    if ($currentNewAccountCurrency !== $sourceCurrency) {
                        $newAccounts[$accountId]['currency'] = $sourceCurrency;
                        $newAccountsChanged                  = true;
                    }
                }
            }

            $status  = 'ok';
            $message = '';
            $apiHint = '';
            $blocking = false;
            $migrationState = $legacyStates[$accountId] ?? ['status' => 'none', 'message' => '', 'legacy_key' => ''];

            if ($requiresManual || '' === $sourceCurrency) {
                $status   = 'needs_currency';
                $blocking = $selected;
                if (count($sampleCurrencies) > 1) {
                    $message = sprintf('Mixed source currencies detected (%s). This account behaves as multi-currency. Select the currency for this import run and repeat import for other currencies.', implode(', ', $sampleCurrencies));
                } elseif ('' !== $sampleError) {
                    $message = sprintf('Could not infer currency automatically (%s). Select the account currency and provide details.', $sampleError);
                } else {
                    $message = 'Could not infer source currency from account data or sampled transactions. Select the account currency and provide details.';
                }
            } elseif ($selected && $mappedId > 0) {
                if ('' === $mappedCurrency) {
                    $status   = 'mismatch';
                    $blocking = true;
                    $message  = sprintf('Mapped Firefly account #%d has no currency metadata.', $mappedId);
                    $apiHint  = sprintf('PATCH /api/v1/accounts/%d with {"currency_code":"%s"}', $mappedId, $sourceCurrency);
                } elseif ($mappedCurrency !== $sourceCurrency) {
                    $status   = 'mismatch';
                    $blocking = true;
                    $message  = sprintf('Source currency is %s but mapped Firefly account #%d is %s.', $sourceCurrency, $mappedId, $mappedCurrency);
                    $apiHint  = sprintf('PATCH /api/v1/accounts/%d with {"currency_code":"%s"}', $mappedId, $sourceCurrency);
                }
            }

            if ($blocking) {
                $errorText = sprintf('Account "%s" (%s): %s', $accountName, $accountId, $message);
                if ('' !== $apiHint) {
                    $errorText = sprintf('%s. %s', $errorText, $apiHint);
                }
                $errors[] = $errorText;
            }

            $entries[$accountId] = [
                'account_id'               => $accountId,
                'account_name'             => $accountName,
                'fingerprint'              => $fingerprint,
                'status'                   => $status,
                'is_selected'              => $selected,
                'requires_manual_selection' => $status === 'needs_currency',
                'source_currency'          => $sourceCurrency,
                'source_kind'              => $sourceKind,
                'sample_currencies'        => $sampleCurrencies,
                'sample_error'             => $sampleError,
                'mapped_firefly_id'        => $mappedId,
                'mapped_firefly_currency'  => $mappedCurrency,
                'message'                  => $message,
                'api_hint'                 => $apiHint,
                'manual_details'           => $details,
                'migration_status'         => (string)($migrationState['status'] ?? 'none'),
                'migration_message'        => (string)($migrationState['message'] ?? ''),
                'migration_legacy_key'     => (string)($migrationState['legacy_key'] ?? ''),
            ];
        }

        if ($serviceAccountsChanged) {
            $importJob->setServiceAccounts($serviceAccounts);
        }
        if ($mappingChanged) {
            $configuration->setAccounts($configuredAccounts);
            $importJob->setConfiguration($configuration);
        }
        if ($newAccountsChanged) {
            $configuration->setNewAccounts($newAccounts);
            $importJob->setConfiguration($configuration);
        }

        return [
            'supported'           => true,
            'entries'             => $entries,
            'errors'              => $errors,
            'has_blocking_errors' => count($errors) > 0,
            'changed'             => $serviceAccountsChanged || $newAccountsChanged || $mappingChanged,
            'currency_options'    => $this->buildCurrencyOptions($importJob->getCurrencies(), $entries),
        ];
    }

    private function normalizeManualMap(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }
        $result = [];
        foreach ($payload as $key => $value) {
            $result[(string)$key] = is_scalar($value) ? trim((string)$value) : '';
        }

        return $result;
    }

    private function extractServiceAccountMeta(array|object $serviceAccount): array
    {
        $id       = '';
        $name     = '';
        $currency = '';
        $iban     = '';
        $bban     = '';
        $syncIds  = [];

        if (is_array($serviceAccount)) {
            $id       = trim((string)($serviceAccount['id'] ?? ''));
            $name     = trim((string)($serviceAccount['name'] ?? ''));
            $currency = trim((string)($serviceAccount['currency'] ?? $serviceAccount['currency_code'] ?? ''));
            $iban     = trim((string)($serviceAccount['iban'] ?? ''));
            $bban     = trim((string)($serviceAccount['bban'] ?? ''));
            $rawSync  = $serviceAccount['sync_ids'] ?? [];
            if (is_array($rawSync)) {
                foreach ($rawSync as $raw) {
                    $candidate = trim((string)$raw);
                    if ('' !== $candidate) {
                        $syncIds[] = $candidate;
                    }
                }
            }
        }

        if (is_object($serviceAccount)) {
            $id       = '' !== $id ? $id : trim((string)($serviceAccount->id ?? ''));
            $name     = '' !== $name ? $name : trim((string)($serviceAccount->name ?? ''));
            $currency = '' !== $currency ? $currency : trim((string)($serviceAccount->currency ?? $serviceAccount->currency_code ?? $serviceAccount->currencyCode ?? ''));
            $iban     = '' !== $iban ? $iban : trim((string)($serviceAccount->iban ?? ''));
            $bban     = '' !== $bban ? $bban : trim((string)($serviceAccount->bban ?? ''));
            if (property_exists($serviceAccount, 'sync_ids') && is_array($serviceAccount->sync_ids)) {
                foreach ($serviceAccount->sync_ids as $raw) {
                    $candidate = trim((string)$raw);
                    if ('' !== $candidate) {
                        $syncIds[] = $candidate;
                    }
                }
            }
            if (method_exists($serviceAccount, 'toArray')) {
                $array = (array)$serviceAccount->toArray();
                if ('' === $currency) {
                    $currency = trim((string)($array['currency'] ?? $array['currency_code'] ?? ''));
                }
                if ('' === $iban) {
                    $iban = trim((string)($array['iban'] ?? ''));
                }
                if ('' === $bban) {
                    $bban = trim((string)($array['bban'] ?? ''));
                }
                $rawSync = $array['sync_ids'] ?? [];
                if (is_array($rawSync)) {
                    foreach ($rawSync as $raw) {
                        $candidate = trim((string)$raw);
                        if ('' !== $candidate) {
                            $syncIds[] = $candidate;
                        }
                    }
                }
            }
        }

        return [
            'id'       => $id,
            'name'     => $name,
            'currency' => $currency,
            'iban'     => $iban,
            'bban'     => $bban,
            'sync_ids' => array_values(array_unique($syncIds)),
        ];
    }

    private function buildFingerprint(string $flow, array $meta): string
    {
        $identityParts = [];
        foreach ($meta['sync_ids'] ?? [] as $syncId) {
            $candidate = trim((string)$syncId);
            if ('' !== $candidate) {
                $identityParts[] = strtoupper($candidate);
            }
        }

        foreach (['iban', 'bban', 'id', 'name'] as $field) {
            $candidate = trim((string)($meta[$field] ?? ''));
            if ('' !== $candidate) {
                $identityParts[] = strtoupper($candidate);
            }
        }

        $identity = implode('|', array_values(array_unique($identityParts)));

        return hash('sha256', sprintf('%s|%s', $flow, $identity));
    }

    /**
     * @return array{0: array<string>, 1: string}
     */
    private function sampleAccountCurrencies(string $flow, string $accountId, Configuration $configuration): array
    {
        try {
            $currencies = match ($flow) {
                'basisbank' => $this->sampleBasisBankCurrencies($configuration, $accountId),
                'tbank'     => $this->sampleTBankCurrencies($configuration, $accountId),
                'trc20'     => $this->sampleTRC20Currencies($configuration, $accountId),
                default     => [],
            };
        } catch (ImporterHttpException $e) {
            Log::warning(sprintf('Currency preflight sampling failed for %s/%s: %s', $flow, $accountId, $e->getMessage()));

            return [[], $e->getMessage()];
        } catch (\Throwable $e) {
            Log::warning(sprintf('Currency preflight sampling crashed for %s/%s: %s', $flow, $accountId, $e->getMessage()));

            return [[], 'unexpected error'];
        }

        $normalized = [];
        foreach ($currencies as $currency) {
            $code = $this->normalizeCurrency((string)$currency);
            if ('' === $code) {
                continue;
            }
            if (!in_array($code, $normalized, true)) {
                $normalized[] = $code;
            }
        }

        return [$normalized, ''];
    }

    /**
     * @throws ImporterHttpException
     */
    private function sampleBasisBankCurrencies(Configuration $configuration, string $accountId): array
    {
        $request = new BasisBankGetTransactionsRequest(
            BasisBankSecretManager::getApiToken($configuration),
            BasisBankSecretManager::getConsentId($configuration),
            $accountId,
            BasisBankSecretManager::getSessionArtifact($configuration)
        );
        $request->setTimeOut((float)config('importer.connection.timeout'));

        return $request->sampleAccountCurrencies(null, null, self::SAMPLE_SIZE);
    }

    /**
     * @throws ImporterHttpException
     */
    private function sampleTBankCurrencies(Configuration $configuration, string $accountId): array
    {
        $request = new TBankGetTransactionsRequest(
            TBankSecretManager::getSessionId($configuration),
            TBankSecretManager::getCookieHeader($configuration),
            $accountId
        );
        $request->setTimeOut((float)config('importer.connection.timeout'));

        return $request->sampleAccountCurrencies(null, null, self::SAMPLE_SIZE);
    }

    /**
     * @throws ImporterHttpException
     */
    private function sampleTRC20Currencies(Configuration $configuration, string $accountId): array
    {
        // TRC20 account IDs are "wallet|SYMBOL" — extract currency directly from the ID
        if (str_contains($accountId, '|')) {
            $parts  = explode('|', $accountId, 2);
            $symbol = strtoupper(trim($parts[1] ?? ''));
            if ('' !== $symbol) {
                return [$symbol];
            }
            $accountId = $parts[0];
        }

        $request = new TRC20GetTransactionsRequest(
            TRC20SecretManager::getApiKey($configuration),
            [$accountId],
            self::SAMPLE_SIZE
        );
        $request->setTimeOut((float)config('importer.connection.timeout'));

        return $request->sampleAccountCurrencies(null, null, self::SAMPLE_SIZE);
    }

    private function resolveFireflyCurrency(array $applicationAccounts, int $accountId): string
    {
        if ($accountId <= 0) {
            return '';
        }

        foreach (['assets', 'asset_accounts', 'liabilities'] as $group) {
            if (!is_array($applicationAccounts[$group] ?? null)) {
                continue;
            }
            foreach ($applicationAccounts[$group] as $account) {
                $candidateId = 0;
                $candidateCurrency = '';
                if (is_array($account)) {
                    $candidateId       = (int)($account['id'] ?? 0);
                    $candidateCurrency = (string)($account['currency_code'] ?? $account['currencyCode'] ?? '');
                } elseif (is_object($account)) {
                    $candidateId       = (int)($account->id ?? 0);
                    $candidateCurrency = (string)($account->currencyCode ?? $account->currency_code ?? '');
                }
                if ($candidateId !== $accountId) {
                    continue;
                }

                return $this->normalizeCurrency($candidateCurrency);
            }
        }

        return '';
    }

    private function applyServiceAccountCurrency(array|object &$serviceAccount, string $currency): bool
    {
        $normalized = $this->normalizeCurrency($currency);
        if ('' === $normalized) {
            return false;
        }

        if (is_array($serviceAccount)) {
            $current = $this->normalizeCurrency((string)($serviceAccount['currency'] ?? $serviceAccount['currency_code'] ?? ''));
            if ($current === $normalized) {
                return false;
            }
            $serviceAccount['currency']      = $normalized;
            $serviceAccount['currency_code'] = $normalized;

            return true;
        }

        if (is_object($serviceAccount)) {
            $current = $this->normalizeCurrency((string)($serviceAccount->currency ?? $serviceAccount->currency_code ?? $serviceAccount->currencyCode ?? ''));
            if ($current === $normalized) {
                return false;
            }
            if (property_exists($serviceAccount, 'currency')) {
                $serviceAccount->currency = $normalized;
            }
            if (property_exists($serviceAccount, 'currency_code')) {
                $serviceAccount->currency_code = $normalized;
            }
            if (property_exists($serviceAccount, 'currencyCode')) {
                $serviceAccount->currencyCode = $normalized;
            }

            return true;
        }

        return false;
    }

    private function normalizeCurrency(?string $currency): string
    {
        return CurrencyCode::normalizeOrEmpty($currency);
    }

    private function getOverride(string $flow, string $fingerprint): array
    {
        $all = $this->secretStore->get($flow, self::STORE_KEY, []);
        if (!is_array($all)) {
            return [];
        }
        if (!array_key_exists($fingerprint, $all) || !is_array($all[$fingerprint])) {
            return [];
        }

        return $all[$fingerprint];
    }

    private function putOverride(string $flow, string $fingerprint, string $currency, string $details, string $source): void
    {
        $all = $this->secretStore->get($flow, self::STORE_KEY, []);
        if (!is_array($all)) {
            $all = [];
        }

        $all[$fingerprint] = [
            'currency'   => $currency,
            'details'    => $details,
            'source'     => $source,
            'updated_at' => date(DATE_ATOM),
        ];

        $this->secretStore->put($flow, self::STORE_KEY, $all);
    }

    private function sanitizeSampleError(string $raw): string
    {
        $value = trim($raw);
        if ('' === $value) {
            return '';
        }
        $lower = strtolower($value);
        if (str_contains($lower, 'redirect') || str_contains($lower, 'login form') || str_contains($lower, 'session')) {
            return 'provider requested re-authentication or returned non-transaction response';
        }

        return $value;
    }

    /**
     * @param array<int, array{id:string,name:string,currency:string,iban:string,bban:string,sync_ids:array}> $serviceMetas
     */
    private function migrateLegacyAccountMappings(array &$configuredAccounts, array $serviceMetas): array
    {
        $states = [];
        $changed = false;
        $serviceIds = [];
        foreach ($serviceMetas as $meta) {
            $serviceIds[] = (string)($meta['id'] ?? '');
        }

        $legacyTargets = [];
        foreach ($serviceMetas as $meta) {
            $id = (string)($meta['id'] ?? '');
            if ('' === $id) {
                continue;
            }
            [$baseId, $currency] = $this->splitCompositeSourceId($id);
            if ('' === $baseId || '' === $currency) {
                continue;
            }
            if (!array_key_exists($baseId, $configuredAccounts)) {
                continue;
            }
            if (in_array($baseId, $serviceIds, true)) {
                continue;
            }
            $legacyTargets[$baseId][] = $id;
        }

        foreach ($legacyTargets as $legacyKey => $targets) {
            $uniqueTargets = array_values(array_unique($targets));
            if (1 === count($uniqueTargets)) {
                $target = $uniqueTargets[0];
                $configuredAccounts[$target] = $configuredAccounts[$legacyKey];
                unset($configuredAccounts[$legacyKey]);
                $states[$target] = [
                    'status'     => 'success',
                    'message'    => sprintf('Auto-migrated mapping from legacy source "%s" to currency-scoped source "%s".', $legacyKey, $target),
                    'legacy_key' => $legacyKey,
                ];
                $changed = true;

                continue;
            }
            foreach ($uniqueTargets as $target) {
                $states[$target] = [
                    'status'     => 'failed',
                    'message'    => sprintf('Legacy mapping "%s" expands to %d currency-scoped rows. Confirm or edit mapping manually for "%s".', $legacyKey, count($uniqueTargets), $target),
                    'legacy_key' => $legacyKey,
                ];
            }
        }

        return ['states' => $states, 'changed' => $changed];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitCompositeSourceId(string $sourceId): array
    {
        $raw = trim($sourceId);
        if ('' === $raw || !str_contains($raw, '|')) {
            return [$raw, ''];
        }

        [$baseId, $currency] = explode('|', $raw, 2);

        return [trim((string)$baseId), $this->normalizeCurrency((string)$currency)];
    }

    private function buildCurrencyOptions(array $currencies, array $entries): array
    {
        $codes = [];

        foreach ($currencies as $display) {
            if (!is_scalar($display)) {
                continue;
            }
            foreach ($this->extractCodesFromText((string)$display) as $code) {
                $codes[$code] = true;
            }
        }

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $resolved = $this->normalizeCurrency((string)($entry['source_currency'] ?? ''));
            if ('' !== $resolved) {
                $codes[$resolved] = true;
            }
            foreach ($entry['sample_currencies'] ?? [] as $sampleCode) {
                $normalized = $this->normalizeCurrency((string)$sampleCode);
                if ('' !== $normalized) {
                    $codes[$normalized] = true;
                }
            }
        }

        $codes['USDT'] = true;
        ksort($codes);

        return array_keys($codes);
    }

    /**
     * @return array<string>
     */
    private function extractCodesFromText(string $display): array
    {
        $result = [];

        if (preg_match_all('/\(([A-Z]{3})\)/', $display, $matches) > 0) {
            foreach ($matches[1] as $match) {
                $code = $this->normalizeCurrency((string)$match);
                if ('' !== $code) {
                    $result[$code] = true;
                }
            }
        }

        if (preg_match_all('/\b([A-Z]{3})\b/', $display, $matchesText) > 0) {
            foreach ($matchesText[1] as $match) {
                $code = $this->normalizeCurrency((string)$match);
                if ('' !== $code) {
                    $result[$code] = true;
                }
            }
        }

        return array_keys($result);
    }
}
