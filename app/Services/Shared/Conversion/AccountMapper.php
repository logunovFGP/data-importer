<?php

declare(strict_types=1);
/*
 * AccountMapper.php
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

namespace App\Services\Shared\Conversion;

use App\Exceptions\ImporterErrorException;
use App\Services\CSV\Converter\Iban as IbanConverter;
use App\Services\Shared\Authentication\SecretManager;
use App\Services\Shared\Model\ImportServiceAccount;
use App\Services\Shared\Support\CurrencyCode;
use App\Services\SimpleFIN\Request\PostAccountRequest;
use App\Services\SimpleFIN\Response\PostAccountResponse;
use Carbon\Carbon;
use Exception;
use GrumpyDictator\FFIIIApiSupport\Exceptions\ApiHttpException;
use GrumpyDictator\FFIIIApiSupport\Model\Account;
use GrumpyDictator\FFIIIApiSupport\Model\AccountType;
use GrumpyDictator\FFIIIApiSupport\Request\GetAccountsRequest;
use GrumpyDictator\FFIIIApiSupport\Request\GetSearchAccountRequest;
use GrumpyDictator\FFIIIApiSupport\Response\GetAccountsResponse;
use GrumpyDictator\FFIIIApiSupport\Response\Response;
use GrumpyDictator\FFIIIApiSupport\Response\ValidationErrorResponse;
use App\Services\Shared\Request\PostCurrencyRequest;
use Illuminate\Support\Facades\Log;

class AccountMapper
{
    private array $fireflyIIIAccounts = [];
    private array $accountMapping     = [];
    private array $createdAccounts    = [];
    private ?string $baseUrlOverride  = null;
    private ?string $accessTokenOverride = null;

    public function __construct(?string $baseUrl = null, ?string $accessToken = null)
    {
        // Defer account loading until actually needed to avoid authentication errors
        // during constructor when authentication context may not be available
        $normalizedBaseUrl = trim((string)$baseUrl);
        $normalizedAccessToken = trim((string)$accessToken);
        $this->baseUrlOverride = '' === $normalizedBaseUrl ? null : $normalizedBaseUrl;
        $this->accessTokenOverride = '' === $normalizedAccessToken ? null : $normalizedAccessToken;
    }

    /**
     * Find a matching Firefly III account for a SimpleFIN account
     */
    public function findMatchingFireflyIIIAccount(ImportServiceAccount $account, ?string $expectedCurrency = null): ?Account
    {
        $this->loadFireflyIIIAccounts();
        $expectedCurrency = CurrencyCode::normalizeOrEmpty(
            null !== $expectedCurrency ? $expectedCurrency : $account->currencyCode
        );

        // Try to find by name first
        $matchingAccounts = array_values(array_filter(
            $this->fireflyIIIAccounts,
            fn (Account $current) => strtolower((string)$current->name) === strtolower($account->name)
        ));

        if (0 === count($matchingAccounts)) {
            return null;
        }

        Log::debug(sprintf('Search for Firefly III account with name "%s" and expected currency "%s"', $account->name, $expectedCurrency));
        $matchingAccounts = $this->applyCurrencyGuard($matchingAccounts, $expectedCurrency, $account->name);
        if (0 === count($matchingAccounts)) {
            return null;
        }

        $identifierMatch = $this->preferIdentifierMatch($matchingAccounts, $account);
        if ($identifierMatch instanceof Account) {
            return $identifierMatch;
        }

        if (1 === count($matchingAccounts)) {
            return $matchingAccounts[0];
        }

        // Try to search via API
        try {
            $request  = new GetSearchAccountRequest($this->resolveBaseUrl(), $this->resolveAccessToken());
            $request->setField('name');
            $request->setQuery($account->name);
            $response = $request->get();

            if ($response instanceof GetAccountsResponse && count($response) > 0) {
                $searchMatches = [];
                foreach ($response as $current) {
                    if (strtolower($current->name) === strtolower($account->name)) {
                        $searchMatches[] = $current;
                    }
                }
                $searchMatches = $this->applyCurrencyGuard($searchMatches, $expectedCurrency, $account->name);
                if (count($searchMatches) > 0) {
                    $identifierMatch = $this->preferIdentifierMatch($searchMatches, $account);
                    if ($identifierMatch instanceof Account) {
                        return $identifierMatch;
                    }

                    return $searchMatches[0];
                }
            }
        } catch (ApiHttpException $e) {
            Log::warning(sprintf('Could not search for account "%s": %s', $account->name, $e->getMessage()));
        }

        return $matchingAccounts[0];
    }

    /**
     * TODO(#83): merge with trait CreatesAccounts
     * Create account immediately via Firefly III API
     */
    public function createFireflyIIIAccount(ImportServiceAccount $importServiceAccount, array $config): ?Account
    {
        $accountName    = $config['name'] ?? $importServiceAccount->name;
        $accountType    = $this->determineAccountType($config);
        $currencyCode   = $this->getCurrencyCode($importServiceAccount, $config);
        $openingBalance = $config['opening_balance'] ?? '0.00';

        Log::info(sprintf('Creating Firefly III account "%s" via API', $accountName));

        try {
            $request  = new PostAccountRequest($this->resolveBaseUrl(), $this->resolveAccessToken());

            // Build account creation payload
            $payload  = [
                'name'              => $accountName,
                'type'              => $accountType,
                'currency_code'     => $currencyCode,
                'active'            => true,
                'include_net_worth' => true,
            ];

            // Add opening balance date if opening balance is provided
            if ('' !== (string)$openingBalance && is_numeric($openingBalance) && '0.00' !== $openingBalance) {
                $payload['opening_balance']      = $openingBalance;
                $payload['opening_balance_date'] = $config['opening_balance_date'] ?? Carbon::now()->format('Y-m-d');
            }

            // Add account role for asset accounts
            if (AccountType::ASSET === $accountType) {
                $payload['account_role'] = $config['account_role'] ?? 'defaultAsset';
            }

            // Add liability-specific fields for liability accounts
            if (in_array($accountType, [AccountType::DEBT, AccountType::LOAN, AccountType::MORTGAGE, AccountType::LIABILITIES, 'liability'], true)) {
                // Map account type to liability type
                $liabilityTypeMap               = [
                    AccountType::DEBT        => 'debt',
                    AccountType::LOAN        => 'loan',
                    AccountType::MORTGAGE    => 'mortgage',
                    AccountType::LIABILITIES => 'debt', // Default generic liabilities to debt
                    'liability'              => 'debt', // Handle user-provided 'liability' type
                ];

                $payload['liability_type']      = $config['liability_type'] ?? $liabilityTypeMap[$accountType] ?? 'debt';
                $payload['liability_direction'] = $config['liability_direction'] ?? 'credit';
            }

            // Add IBAN if provided
            if (array_key_exists('iban', $config) && '' !== (string)$config['iban'] && IbanConverter::isValidIban((string)$config['iban'])) {
                $payload['iban'] = $config['iban'];
            }

            // Add account number if provided
            if (array_key_exists('account_number', $config) && '' !== (string)$config['account_number']) {
                $payload['account_number'] = $config['account_number'];
            }

            $request->setBody($payload);
            $response = $this->makeApiCallWithRetry($request, $accountName);

            if ($response instanceof ValidationErrorResponse) {
                $errors = $response->errors->toArray();
                if ($this->hasCurrencyCodeValidationError($errors) && array_key_exists('currency_code', $payload)) {
                    $currencyCode = (string)$payload['currency_code'];
                    Log::info(sprintf('Currency "%s" not in Firefly III. Attempting to create it for account "%s".', $currencyCode, $accountName));

                    // Try to auto-create the missing currency
                    $currencyCreated = $this->ensureCurrencyExists($currencyCode);
                    if ($currencyCreated) {
                        Log::info(sprintf('Currency "%s" created. Retrying account creation with currency.', $currencyCode));
                        $request->setBody($payload);
                        $response = $this->makeApiCallWithRetry($request, $accountName);
                    } else {
                        Log::warning(sprintf('Could not create currency "%s". Retrying account without currency code.', $currencyCode));
                        unset($payload['currency_code']);
                        $request->setBody($payload);
                        $response = $this->makeApiCallWithRetry($request, $accountName);
                    }
                }
            }

            if ($response instanceof ValidationErrorResponse) {
                Log::error(sprintf('Failed to create account "%s": %s', $accountName, json_encode($response->errors->toArray())));

                return null;
            }

            if ($response instanceof PostAccountResponse) {
                $account = $response->getAccount();
                if ($account instanceof Account) {
                    Log::info(sprintf('Successfully created account "%s" with ID %d', $accountName, $account->id));

                    // Add to our local cache
                    $this->fireflyIIIAccounts[] = $account;
                    $this->createdAccounts[]    = $account;

                    return $account;
                }
            }

            Log::error(sprintf('Unexpected response type when creating account "%s"', $accountName));

            return null;

        } catch (ApiHttpException $e) {
            Log::error(sprintf('API error creating account "%s": %s', $accountName, $e->getMessage()));

            return null;
        } catch (Exception $e) {
            Log::error(sprintf('Unexpected error creating account "%s": %s', $accountName, $e->getMessage()));

            return null;
        }
    }

    /**
     * Determine the appropriate Firefly III account type
     */
    private function determineAccountType(array $config): string
    {
        // Default to asset account for most SimpleFIN accounts
        return $config['type'] ?? AccountType::ASSET;
    }

    /**
     * Get currency code for account creation
     */
    private function getCurrencyCode(ImportServiceAccount $account, array $config): string
    {
        // 1. Use user-configured currency first
        if (array_key_exists('currency', $config) && '' !== (string)$config['currency']) {
            return (string)$config['currency'];
        }

        // 2. Fall back to account currency
        $currency = $account->currencyCode;

        // 3. Final fallback
        return '' !== $currency && '0' !== $currency ? $currency : 'EUR';
    }

    /**
     * Load all Firefly III accounts
     */
    private function loadFireflyIIIAccounts(): void
    {
        // Only load once
        if (count($this->fireflyIIIAccounts) > 0) {
            Log::debug('Already loaded Firefly III accounts, skipping reload');

            return;
        }

        try {
            // Verify authentication context before making API calls
            $baseUrl     = $this->resolveBaseUrl();
            $accessToken = $this->resolveAccessToken();

            if ('' === $baseUrl || '' === $accessToken) {
                Log::warning('Missing authentication context for Firefly III account loading');

                throw new ImporterErrorException('Authentication context not available for account loading');
            }

            $request     = new GetAccountsRequest($baseUrl, $accessToken);
            $request->setType(AccountType::ASSET);
            $response    = $request->get();

            if ($response instanceof GetAccountsResponse) {
                $this->fireflyIIIAccounts = iterator_to_array($response);
                Log::debug(sprintf('Loaded %d Firefly III accounts', count($this->fireflyIIIAccounts)));
            }
        } catch (ApiHttpException $e) {
            Log::error(sprintf('Could not load Firefly III accounts: %s', $e->getMessage()));

            throw new ImporterErrorException(sprintf('Could not load Firefly III accounts: %s', $e->getMessage()));
        }
    }

    /**
     * Make API call with DNS resilience retry pattern
     *
     * @throws ApiHttpException
     */
    private function makeApiCallWithRetry(PostAccountRequest $request, string $accountName): Response
    {
        $retryDelays   = [0, 2, 5]; // immediate, 2s delay, 5s delay
        $lastException = null;

        foreach ($retryDelays as $attempt => $delay) {
            try {
                if ($delay > 0) {
                    Log::debug(sprintf('Retrying account creation for "%s" after %ds delay (attempt %d)', $accountName, $delay, $attempt + 1));
                    sleep($delay);
                }

                return $request->post();

            } catch (ApiHttpException $e) {
                $lastException = $e;
                $errorMessage  = $e->getMessage();

                // Check if this is a DNS/connection timeout error that we should retry
                $shouldRetry   = $this->shouldRetryApiCall($errorMessage, $attempt, count($retryDelays));

                if (!$shouldRetry) {
                    Log::error(sprintf('Non-retryable API error for account "%s": %s', $accountName, $errorMessage));

                    throw $e;
                }

                Log::warning(sprintf('DNS/connection error for account "%s" (attempt %d): %s', $accountName, $attempt + 1, $errorMessage));

                // If this was the last attempt, we'll throw after the loop
                if ($attempt === count($retryDelays) - 1) {
                    break;
                }
            }
        }

        // All retries exhausted
        Log::error(sprintf('All retries exhausted for account "%s": %s', $accountName, $lastException->getMessage()));

        throw $lastException;
    }

    private function resolveBaseUrl(): string
    {
        if (null !== $this->baseUrlOverride && '' !== $this->baseUrlOverride) {
            return $this->baseUrlOverride;
        }

        return SecretManager::getBaseUrl();
    }

    private function resolveAccessToken(): string
    {
        if (null !== $this->accessTokenOverride && '' !== $this->accessTokenOverride) {
            return $this->accessTokenOverride;
        }

        return SecretManager::getAccessToken();
    }

    private function hasCurrencyCodeValidationError(array $errors): bool
    {
        if (!isset($errors['currency_code']) || !is_array($errors['currency_code'])) {
            return false;
        }

        foreach ($errors['currency_code'] as $message) {
            if (!is_string($message)) {
                continue;
            }
            if (false !== stripos($message, 'invalid')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<Account> $accounts
     * @return array<Account>
     */
    private function applyCurrencyGuard(array $accounts, string $expectedCurrency, string $accountName): array
    {
        if ('' === $expectedCurrency) {
            return $accounts;
        }

        $filtered = array_values(array_filter(
            $accounts,
            fn (Account $candidate) => CurrencyCode::normalizeOrEmpty((string)($candidate->currencyCode ?? '')) === $expectedCurrency
        ));

        if (0 === count($filtered)) {
            Log::warning(sprintf('Refusing to auto-reuse account "%s": name matches exist, but none match expected currency "%s".', $accountName, $expectedCurrency));
        }

        return $filtered;
    }

    /**
     * @param array<Account> $accounts
     */
    private function preferIdentifierMatch(array $accounts, ImportServiceAccount $account): ?Account
    {
        $iban = trim((string)$account->iban);
        $bban = trim((string)$account->bban);

        if ('' !== $iban) {
            foreach ($accounts as $candidate) {
                $candidateIban = trim((string)($candidate->iban ?? ''));
                $candidateNumber = trim((string)($candidate->accountNumber ?? ''));
                if ($candidateIban === $iban || $candidateNumber === $iban) {
                    return $candidate;
                }
            }
        }

        if ('' !== $bban) {
            foreach ($accounts as $candidate) {
                $candidateNumber = trim((string)($candidate->accountNumber ?? ''));
                if ('' !== $candidateNumber && $candidateNumber === $bban) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Determine if an API call should be retried based on the error
     */
    private function shouldRetryApiCall(string $errorMessage, int $attempt, int $maxAttempts): bool
    {
        // Don't retry if we've exhausted all attempts
        if ($attempt >= $maxAttempts - 1) {
            return false;
        }

        // Retry on DNS resolution timeouts, connection timeouts, and network errors
        $retryableErrors = [
            'Resolving timed out',
            'cURL error 28',
            'Connection timed out',
            'cURL error 6',  // Couldn't resolve host
            'cURL error 7',  // Couldn't connect to host
            'Failed to connect',
            'Name or service not known',
            'Temporary failure in name resolution',
        ];

        return array_any($retryableErrors, fn ($retryableError) => false !== stripos($errorMessage, $retryableError));

    }

    private function ensureCurrencyExists(string $code): bool
    {
        $url   = $this->resolveBaseUrl();
        $token = $this->resolveAccessToken();

        try {
            $request = new PostCurrencyRequest($url, $token);
            $request->setBody([
                'name'           => $code,
                'code'           => $code,
                'symbol'         => $code,
                'decimal_places' => 6,
                'enabled'        => true,
            ]);
            $response = $request->post();

            if ($response instanceof ValidationErrorResponse) {
                $errors = $response->errors->toArray();
                // If the currency already exists (unique constraint), that's fine
                $alreadyExists = false;
                foreach ($errors as $field => $messages) {
                    foreach ((array)$messages as $msg) {
                        if (is_string($msg) && (stripos($msg, 'already') !== false || stripos($msg, 'taken') !== false)) {
                            $alreadyExists = true;
                        }
                    }
                }
                if ($alreadyExists) {
                    Log::info(sprintf('Currency "%s" already exists in Firefly III.', $code));

                    // Enable it if it was disabled
                    return true;
                }
                Log::warning(sprintf('Failed to create currency "%s": %s', $code, json_encode($errors)));

                return false;
            }

            Log::info(sprintf('Created currency "%s" in Firefly III.', $code));

            return true;
        } catch (\Throwable $e) {
            Log::warning(sprintf('Exception creating currency "%s": %s', $code, $e->getMessage()));

            return false;
        }
    }
}
