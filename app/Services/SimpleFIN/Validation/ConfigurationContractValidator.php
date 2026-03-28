<?php

/*
 * ConfigurationContractValidator.php
 */

declare(strict_types=1);

namespace App\Services\SimpleFIN\Validation;

use App\Services\Shared\Configuration\Configuration;
use Illuminate\Support\Facades\Session;

class ConfigurationContractValidator
{
    private const array REQUIRED_ACCOUNT_FIELDS = ['id', 'name', 'currency', 'balance', 'balance-date', 'org'];
    private const array ALLOWED_ACCOUNT_TYPES = ['asset', 'liability', 'expense', 'revenue'];
    private const array ALLOWED_ASSET_ROLES = [
        'defaultAsset',
        'savingAsset',
        'sharedAsset',
        'ccAsset',
        'cashWallet',
    ];

    public function validateConfigurationContract(Configuration $configuration): ValidationResult
    {
        $errors = [];
        $warnings = [];

        if ('simplefin' !== strtolower(trim($configuration->getFlow()))) {
            $this->addError($errors, 'flow', 'Configuration must use the SimpleFIN flow.', $configuration->getFlow());
        }

        $accountsData = Session::get('simplefin_accounts_data');
        if (!is_array($accountsData) || [] === $accountsData) {
            $this->addError($errors, 'simplefin_accounts_data', 'SimpleFIN accounts data missing from session.', $accountsData);

            return new ValidationResult(false, $errors, $warnings);
        }

        $knownAccountIds = [];
        foreach ($accountsData as $index => $accountRow) {
            if (!is_array($accountRow)) {
                $this->addError($errors, sprintf('simplefin_accounts_data.%s', (string)$index), 'SimpleFIN account entry must be an array.', $accountRow);
                continue;
            }
            foreach (self::REQUIRED_ACCOUNT_FIELDS as $field) {
                if (!array_key_exists($field, $accountRow) || !$this->hasRequiredValue($accountRow[$field])) {
                    $this->addError(
                        $errors,
                        sprintf('simplefin_accounts_data.%s.%s', (string)$index, $field),
                        sprintf('SimpleFIN account field "%s" is required.', $field),
                        $accountRow[$field] ?? null
                    );
                }
            }
            $id = trim((string)($accountRow['id'] ?? ''));
            if ('' !== $id) {
                $knownAccountIds[$id] = true;
            }
        }

        $accountMappings = $configuration->getAccounts();
        if (!is_array($accountMappings)) {
            $this->addError($errors, 'accounts', 'SimpleFIN account mappings must be an array.', $accountMappings);
            $accountMappings = [];
        }

        foreach ($accountMappings as $accountId => $mappedId) {
            $normalizedAccountId = trim((string)$accountId);
            if ('' === $normalizedAccountId) {
                $this->addError($errors, 'accounts', 'SimpleFIN account mapping key cannot be empty.', $accountId);
                continue;
            }
            if (!is_numeric($mappedId)) {
                $this->addError($errors, sprintf('accounts.%s', $normalizedAccountId), 'Mapped Firefly III account id must be numeric.', $mappedId);
                continue;
            }
            if ((int)$mappedId < 0) {
                $this->addError($errors, sprintf('accounts.%s', $normalizedAccountId), 'Mapped Firefly III account id cannot be negative.', $mappedId);
            }
            if (!isset($knownAccountIds[$normalizedAccountId])) {
                $warnings[] = [
                    'field' => sprintf('accounts.%s', $normalizedAccountId),
                    'message' => sprintf('Account "%s" is mapped but is not present in current SimpleFIN session data.', $normalizedAccountId),
                    'value' => $mappedId,
                ];
            }
        }

        $newAccounts = $configuration->getNewAccounts();
        if (!is_array($newAccounts)) {
            $newAccounts = [];
        }
        foreach ($accountMappings as $accountId => $mappedId) {
            if ((int)$mappedId !== 0) {
                continue;
            }
            $normalizedAccountId = trim((string)$accountId);
            if ('' === $normalizedAccountId) {
                continue;
            }
            if (!isset($newAccounts[$normalizedAccountId]) || !is_array($newAccounts[$normalizedAccountId])) {
                $this->addError(
                    $errors,
                    sprintf('new_account.%s', $normalizedAccountId),
                    sprintf('New account configuration missing for "%s".', $normalizedAccountId),
                    $newAccounts[$normalizedAccountId] ?? null
                );
                continue;
            }
            $this->validateNewAccountConfiguration($normalizedAccountId, $newAccounts[$normalizedAccountId], $errors);
        }

        $doImport = Session::get('do_import', []);
        if (!is_array($doImport)) {
            $this->addError($errors, 'do_import', 'SimpleFIN import selection must be an array.', $doImport);
        } else {
            foreach ($doImport as $accountId => $selected) {
                if (!$this->isTruthy($selected)) {
                    continue;
                }
                if (!array_key_exists((string)$accountId, $accountMappings)) {
                    $this->addError(
                        $errors,
                        sprintf('do_import.%s', (string)$accountId),
                        sprintf('Account "%s" is selected for import but not in account mappings.', (string)$accountId),
                        $selected
                    );
                }
            }
        }

        return new ValidationResult([] === $errors, $errors, $warnings);
    }

    public function validateFormFieldStructure(array $formData): ValidationResult
    {
        $errors = [];
        $warnings = [];

        $requiredArrayFields = ['do_import', 'accounts', 'new_account'];
        foreach ($requiredArrayFields as $field) {
            if (!array_key_exists($field, $formData)) {
                $this->addError($errors, $field, sprintf('Form field "%s" is required.', $field), null);
                continue;
            }
            if (!is_array($formData[$field])) {
                $this->addError($errors, $field, sprintf('Form field "%s" must be an array.', $field), $formData[$field]);
            }
        }

        return new ValidationResult([] === $errors, $errors, $warnings);
    }

    private function validateNewAccountConfiguration(string $accountId, array $config, array &$errors): void
    {
        $name = trim((string)($config['name'] ?? ''));
        if ('' === $name) {
            $this->addError($errors, sprintf('new_account.%s.name', $accountId), 'New account name is required.', $name);
        }

        $type = strtolower(trim((string)($config['type'] ?? '')));
        if (!in_array($type, self::ALLOWED_ACCOUNT_TYPES, true)) {
            $this->addError($errors, sprintf('new_account.%s.type', $accountId), 'Invalid account type.', $config['type'] ?? null);
        }

        $currency = strtoupper(trim((string)($config['currency'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $this->addError($errors, sprintf('new_account.%s.currency', $accountId), 'Currency must be a 3-letter ISO code.', $config['currency'] ?? null);
        }

        $openingBalance = $config['opening_balance'] ?? null;
        if (!is_numeric($openingBalance)) {
            $this->addError($errors, sprintf('new_account.%s.opening_balance', $accountId), 'Opening balance must be numeric.', $openingBalance);
        }

        if ('liability' === $type) {
            $liabilityType = trim((string)($config['liability_type'] ?? ''));
            if ('' === $liabilityType) {
                $this->addError($errors, sprintf('new_account.%s.liability_type', $accountId), 'Liability type required.', $liabilityType);
            }
            $liabilityDirection = trim((string)($config['liability_direction'] ?? ''));
            if ('' === $liabilityDirection) {
                $this->addError($errors, sprintf('new_account.%s.liability_direction', $accountId), 'Liability direction required.', $liabilityDirection);
            }
        }

        if ('asset' === $type) {
            $accountRole = trim((string)($config['account_role'] ?? ''));
            if ('' !== $accountRole && !in_array($accountRole, self::ALLOWED_ASSET_ROLES, true)) {
                $this->addError($errors, sprintf('new_account.%s.account_role', $accountId), 'Invalid account role for asset account.', $accountRole);
            }
        }
    }

    private function addError(array &$errors, string $field, string $message, mixed $value): void
    {
        $errors[] = [
            'field' => $field,
            'message' => $message,
            'value' => $value,
        ];
    }

    private function isTruthy(mixed $value): bool
    {
        if (true === $value || 1 === $value) {
            return true;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }

        return false;
    }

    private function hasRequiredValue(mixed $value): bool
    {
        if (null === $value) {
            return false;
        }
        if (is_string($value)) {
            return '' !== trim($value);
        }
        if (is_array($value)) {
            return [] !== $value;
        }

        return true;
    }
}
