<?php

/*
 * ImportServiceAccount.php
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

namespace App\Services\Shared\Model;

use App\Exceptions\ImporterErrorException;
use App\Services\CSV\Converter\Iban as IbanConverter;
use App\Services\LunchFlow\Model\Account as LunchFlowAccount;
use App\Services\Nordigen\Model\Account as NordigenAccount;
use App\Services\Nordigen\Model\Balance;
use App\Services\SimpleFIN\Model\Account as SimpleFinAccount;
use App\Services\Sophtron\Model\UserInstitutionAccount as SophtronAccount;
use App\Services\Spectre\Model\Account as SpectreAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ImportServiceAccount
{
    public string $bban;
    public string $currencyCode;
    public ?string $balance = null;
    public ?string $availableBalance = null;
    public bool $isCard = false;
    public array  $extra;
    public string $iban;
    public string $id;
    public string $name;
    public string $status;

    public static function convertSingleAccount(array|object $account): self
    {
        // probably simpleFIN.
        if (is_array($account)) {
            $timestamp  = (int)($account['balance-date'] ?? 0);
            $dateString = '';
            if ($timestamp > 100) {
                $carbon     = Carbon::createFromTimestamp($timestamp);
                $dateString = $carbon->format('Y-m-d H:i:s');
            }

            return self::fromArray(
                [
                    'id'            => $account['id'], // Expected by component for form elements, and by getMappedTo (as 'identifier')
                    'name'          => $account['name'], // Expected by getMappedTo, display in component
                    'currency_code' => $account['currency'] ?? null, // SimpleFIN currency field
                    'iban'          => null,
                    'bban'          => '',
                    'status'        => 'active', // Expected by view for status checks
                    'extra'         => [
                        'Balance'      => $account['balance'] ?? null, // SimpleFIN balance (numeric string)
                        'Balance date' => $dateString, // SimpleFIN balance timestamp
                        'Organization' => $account['org']['name'] ?? null, // SimpleFIN organization data
                    ],
                ]
            );
        }
        if ($account instanceof SimpleFinAccount) {
            $dateString = '';
            if ($account->balanceDate > 100) {
                $carbon     = Carbon::createFromTimestamp($account->balanceDate);
                $dateString = $carbon->format('Y-m-d H:i:s');
            }

            return self::fromArray(
                [
                    'id'            => (string)$account->id,
                    'name'          => $account->name,
                    'currency_code' => (string)$account->currency,
                    'iban'          => '',
                    'bban'          => '',
                    'status'        => 'active',
                    'extra'         => [
                        'Balance'      => $account->balance,
                        'Balance date' => $dateString, // SimpleFIN balance timestamp
                        'Organization' => (string)$account->getOrganizationName(),
                    ],
                ]
            );
        }
        if ($account instanceof LunchFlowAccount) {
            $iban = self::sanitizeIban(trim((string)($account->iban ?? '')));
            $bban = trim((string)($account->bban ?? ''));
            $syncIds = is_array($account->syncIds ?? null) ? $account->syncIds : [];
            $sourceExtra = is_array($account->extra ?? null) ? $account->extra : [];
            $extra = array_merge(
                [
                    'Currency'          => (string)$account->currency,
                    'IBAN'              => $iban,
                    'BBAN'              => $bban,
                    'Balance'           => null !== $account->balance ? (string)$account->balance : null,
                    'Available balance' => null !== $account->available ? (string)$account->available : null,
                    'Card account'      => $account->isCard ? 'yes' : 'no',
                ],
                $sourceExtra
            );
            if ([] !== $syncIds) {
                $extra['Sync IDs'] = implode(', ', array_map(static fn ($value): string => (string)$value, $syncIds));
            }

            return self::fromArray(
                [
                    'id'            => (string)$account->id,
                    'name'          => $account->name,
                    'currency_code' => (string)$account->currency,
                    'iban'          => $iban,
                    'bban'          => $bban,
                    'status'        => $account->status,
                    'balance'       => null !== $account->balance ? (string)$account->balance : null,
                    'available_balance' => null !== $account->available ? (string)$account->available : null,
                    'is_card'       => $account->isCard,
                    'extra'         => $extra,
                ]
            );
        }
        if ($account instanceof NordigenAccount) {
            return self::fromArray(
                [
                    'id'            => $account->getIdentifier(),
                    'name'          => $account->getName(),
                    'currency_code' => $account->getCurrency(),
                    'iban'          => $account->getIban(),
                    'bban'          => $account->getBban(),
                    'status'        => $account->getStatus(),
                    'extra'         => [
                        'Currency' => $account->getCurrency(),
                        'IBAN'     => $account->getIban(),
                        'BBAN'     => $account->getBban(),
                        'BIC'      => $account->getBic(),
                    ],
                ]
            );
        }
        if ($account instanceof SophtronAccount) {
            $iban = self::sanitizeIban($account->accountNumber);

            return self::fromArray(
                [
                    'id'            => $account->id,
                    'name'          => $account->accountName,
                    'currency_code' => $account->balanceCurrency,
                    'iban'          => $iban,
                    'bban'          => $account->accountNumber,
                    'status'        => $account->status,
                    'extra'         => [
                        'Bank name'         => $account->userInstitution?->companyName,
                        'Balance'           => $account->balance,
                        'Available balance' => $account->availableBalance,
                        'Currency'          => $account->balanceCurrency,
                        'IBAN'              => $iban,
                        'BBAN'              => $account->accountNumber,
                    ],
                ]
            );
        }

        throw new ImporterErrorException(sprintf('Cannot convert object of class %s to ImportServiceAccount in ImportServiceAccount class .', $account::class));
    }

    /**
     * @return array<ImportServiceAccount>
     */
    public static function convertNordigenArray(array $accounts): array
    {
        Log::debug(sprintf('[%s] Now in %s', config('importer.version'), __METHOD__));
        $return = [];

        /** @var NordigenAccount $account */
        foreach ($accounts as $account) {
            $iban     = self::sanitizeIban($account->getIban());

            $current  = self::fromArray(
                [
                    'id'            => $account->getIdentifier(),
                    'name'          => $account->getFullName(),
                    'currency_code' => $account->getCurrency(),
                    'iban'          => $iban,
                    'bban'          => $account->getBban(),
                    'status'        => '',
                    'extra'         => [
                        'Name'         => $account->getName(),
                        'Display name' => $account->getDisplayName(),
                        'Owner name'   => $account->getOwnerName(),
                        'Currency'     => $account->getCurrency(),
                        'IBAN'         => $iban,
                        'BBAN'         => $account->getBban(),
                    ],
                ]
            );

            /** @var Balance $balance */
            foreach ($account->getBalances() as $balance) {
                $key                  = sprintf('Balance (%s) (%s)', $balance->type, $balance->currency);
                $current->extra[$key] = $balance->amount;
            }
            $return[] = $current;
        }

        return $return;
    }

    public static function convertSimpleFINArray(array $accounts): array
    {
        $return = [];

        /** @var SimpleFinAccount $account */
        foreach ($accounts as $account) {

            $timestamp  = $account->getBalanceDate();
            $dateString = '';
            if ($timestamp > 100) {
                $carbon     = Carbon::createFromTimestamp($timestamp);
                $dateString = $carbon->format('Y-m-d H:i:s');
            }
            $current    = self::fromArray(
                [
                    'id'            => $account->getId(), // Expected by component for form elements, and by getMappedTo (as 'identifier')
                    'name'          => $account->getName(), // Expected by getMappedTo, display in component
                    'currency_code' => $account->getCurrency(), // SimpleFIN currency field
                    'iban'          => null,
                    'bban'          => '',
                    'status'        => 'active', // Expected by view for status checks
                    'extra'         => [
                        'Balance'      => $account->getBalance() ?? null, // SimpleFIN balance (numeric string)
                        'Balance date' => $dateString, // SimpleFIN balance timestamp
                        'Organization' => $account->getOrganizationName(), // SimpleFIN organization data
                    ],
                ]
            );
            foreach ($account->getExtra() as $key => $value) {
                if (!array_key_exists($key, $current->extra)) {
                    $current->extra[$key] = $value;
                }
            }
            $return[]   = $current;
            //            $return[] = ['import_account'       => $importAccountRepresentation, // The DTO-like object for the component
            //                         'mapped_to'            => $this->getMappedTo((object)['identifier' => $importAccountRepresentation->id, 'name' => $importAccountRepresentation->name], $fireflyAccounts), // getMappedTo needs 'identifier'
            //                         'type'                 => 'source', // Indicates it's an account from the import source
            //                         'firefly_iii_accounts' => $fireflyAccounts, // Required by x-importer-account component
            //            ];
        }

        return $return;
    }

    /**
     * @return $this
     */
    public static function fromArray(array $array): self
    {
        Log::debug('Create generic account from', $array);
        $iban                  = self::sanitizeIban((string)($array['iban'] ?? ''));
        $account               = new self();
        $extra                 = is_array($array['extra'] ?? null) ? $array['extra'] : [];
        $balanceRaw            = $array['balance'] ?? ($extra['Balance'] ?? null);
        $availableRaw          = $array['available_balance'] ?? ($array['available'] ?? ($extra['Available balance'] ?? null));
        $account->id           = (string)($array['id'] ?? '');
        $account->name         = (string)($array['name'] ?? '');
        $account->iban         = $iban;
        $account->bban         = (string)($array['bban'] ?? '');
        $account->currencyCode = (string)($array['currency_code'] ?? '');
        $account->status       = (string)($array['status'] ?? 'active');
        $account->balance      = self::normalizeOptionalString($balanceRaw);
        $account->availableBalance = self::normalizeOptionalString($availableRaw);
        $account->isCard       = (bool)($array['is_card'] ?? false);
        $account->extra        = $extra;

        return $account;
    }

    /**
     * Convert TRC20 service accounts (plain arrays from GetWalletRequest::buildAccount)
     * into ImportServiceAccount objects. The arrays already use the same keys as fromArray().
     */
    public static function convertTRC20Array(array $serviceAccounts): array
    {
        $return = [];
        foreach ($serviceAccounts as $account) {
            if (is_array($account)) {
                $return[] = self::fromArray($account);
            } elseif (is_object($account) && method_exists($account, 'toArray')) {
                $return[] = self::fromArray($account->toArray());
            }
        }

        return $return;
    }

    public static function convertSophtronArray(array $serviceAccounts): array
    {
        $return = [];

        /** @var SophtronAccount $account */
        foreach ($serviceAccounts as $account) {
            $iban     = self::sanitizeIban($account->accountNumber);
            $return[] = self::fromArray(
                [
                    'id'            => $account->id,
                    'name'          => $account->accountName,
                    'currency_code' => $account->balanceCurrency,
                    'iban'          => $iban,
                    'bban'          => $account->accountNumber,
                    'status'        => $account->status,
                    'extra'         => [
                        'Bank name'         => $account->userInstitution?->companyName,
                        'Balance'           => $account->balance,
                        'Available balance' => $account->availableBalance,
                        'Currency'          => $account->balanceCurrency,
                        'IBAN'              => $iban,
                        'BBAN'              => $account->accountNumber,
                    ],
                ]
            );
        }

        return $return;
    }

    public static function convertSpectreArray(array $spectre): array
    {
        $return = [];

        /** @var SpectreAccount $account */
        foreach ($spectre as $account) {
            $iban     = self::sanitizeIban((string)$account->iban);
            $return[] = self::fromArray(
                [
                    'id'            => $account->id,
                    'name'          => $account->name,
                    'currency_code' => $account->currencyCode,
                    'iban'          => $iban,
                    'bban'          => $account->accountNumber,
                    'status'        => $account->status,
                    'extra'         => [
                        'Currency' => $account->currencyCode,
                        'IBAN'     => $iban,
                        'BBAN'     => $account->accountNumber,
                    ],
                ]
            );
        }

        return $return;
    }

    public static function convertLunchFlowArray(array $lunchFlow): array
    {
        $return = [];

        /** @var LunchFlowAccount $account */
        foreach ($lunchFlow as $account) {
            $iban = self::sanitizeIban(trim((string)($account->iban ?? '')));
            $bban = trim((string)($account->bban ?? ''));
            $syncIds = is_array($account->syncIds ?? null) ? $account->syncIds : [];
            $sourceExtra = is_array($account->extra ?? null) ? $account->extra : [];
            $extra = array_merge(
                [
                    'Currency'          => (string)$account->currency,
                    'IBAN'              => $iban,
                    'BBAN'              => $bban,
                    'Balance'           => null !== $account->balance ? (string)$account->balance : null,
                    'Available balance' => null !== $account->available ? (string)$account->available : null,
                    'Card account'      => $account->isCard ? 'yes' : 'no',
                ],
                $sourceExtra
            );
            if ([] !== $syncIds) {
                $extra['Sync IDs'] = implode(', ', array_map(static fn ($value): string => (string)$value, $syncIds));
            }

            $return[] = self::fromArray(
                [
                    'id'            => (string)$account->id,
                    'name'          => $account->name,
                    'currency_code' => (string)$account->currency,
                    'iban'          => $iban,
                    'bban'          => $bban,
                    'status'        => $account->status,
                    'balance'       => null !== $account->balance ? (string)$account->balance : null,
                    'available_balance' => null !== $account->available ? (string)$account->available : null,
                    'is_card'       => $account->isCard,
                    'extra'         => $extra,
                ]
            );
        }

        return $return;
    }

    /**
     * Normalize a raw service account (array or object) into a standard array shape.
     * Handles both arrays with 'currency_code' key and generic object/array conversion.
     */
    public static function normalizeToArray(array|object $account): array
    {
        if (is_array($account) && array_key_exists('currency_code', $account)) {
            return [
                'id'            => (string)($account['id'] ?? ''),
                'name'          => (string)($account['name'] ?? ''),
                'currency_code' => (string)($account['currency_code'] ?? ''),
                'iban'          => '',
                'bban'          => '',
                'status'        => (string)($account['status'] ?? 'active'),
                'extra'         => [],
            ];
        }

        $payload = [];
        if (is_array($account)) {
            $payload = $account;
        } elseif (is_object($account) && method_exists($account, 'toArray')) {
            $payload = (array)$account->toArray();
        }

        return [
            'id'            => (string)($payload['id'] ?? ''),
            'name'          => (string)($payload['name'] ?? ''),
            'currency_code' => (string)($payload['currency_code'] ?? $payload['currency'] ?? ''),
            'iban'          => '',
            'bban'          => '',
            'status'        => (string)($payload['status'] ?? 'active'),
            'extra'         => [],
        ];
    }

    /**
     * Validate and sanitize an IBAN string. Returns '' if the IBAN is invalid.
     */
    private static function sanitizeIban(string $iban): string
    {
        if ('' !== $iban && false === IbanConverter::isValidIban($iban)) {
            Log::debug(sprintf('IBAN "%s" is invalid so it will be ignored.', $iban));

            return '';
        }

        return $iban;
    }

    private static function normalizeOptionalString(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!is_scalar($value)) {
            return null;
        }
        $result = trim((string)$value);
        if ('' === $result) {
            return null;
        }

        return $result;
    }
}
