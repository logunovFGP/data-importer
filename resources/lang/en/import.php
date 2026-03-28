<?php

/*
 * import.php
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

return [

    'roles_defined_warning' => 'It looks like you already defined the roles for this import job. If you change the settings below, your data mapping settings may become inaccurate.',
    'trc20'                 => 'TRC-20',

    // labels for authentication
    'label_nordigen_identifier'       => 'GoCardless Identifier',
    'label_nordigen_key'              => 'GoCardless Key',
    'placeholder_nordigen_identifier' => 'GoCardless Identifier',
    'placeholder_nordigen_key'        => 'GoCardless Key',
    'help_nordigen_identifier'        => 'Enter your GoCardless Identifier',
    'help_nordigen_key'               => 'Enter your GoCardless Key',

    'label_lunchflow_api_key'       => 'Lunch Flow API key',
    'placeholder_lunchflow_api_key' => 'Lunch Flow API key',
    'help_lunchflow_api_key'        => 'Enter your Lunch Flow API key',

    'label_basisbank_api_token'       => 'BasisBank login',
    'placeholder_basisbank_api_token' => 'BasisBank login',
    'help_basisbank_api_token'        => 'Enter your BasisBank login username',
    'label_basisbank_consent_id'      => 'BasisBank password',
    'placeholder_basisbank_consent_id' => 'BasisBank password',
    'help_basisbank_consent_id'       => 'Enter your BasisBank account password',

    'label_basisbank_login'            => 'BasisBank login',
    'placeholder_basisbank_login'      => 'BasisBank login',
    'help_basisbank_login'             => 'Enter your BasisBank login username',
    'label_basisbank_password'         => 'BasisBank password',
    'placeholder_basisbank_password'   => 'BasisBank password',
    'help_basisbank_password'          => 'Enter your BasisBank account password',
    'label_basisbank_otp_code'         => 'BasisBank OTP code',
    'placeholder_basisbank_otp_code'   => 'Enter OTP',
    'help_basisbank_otp_code'          => 'Enter the OTP from SMS or your authenticator app',
    'label_basisbank_request_sms_code'  => 'Request SMS code',
    'placeholder_basisbank_request_sms_code' => 'Request SMS code',
    'help_basisbank_request_sms_code'   => 'Mandatory. The importer always requests a fresh one-time code from BasisBank for the current step.',
    'label_basisbank_trust_device'      => 'Trust device',
    'placeholder_basisbank_trust_device' => 'Trust this device',
    'help_basisbank_trust_device'       => 'Trust this device and reduce OTP prompts for future logins',
    'basisbank_guidance_invalid_credentials' => 'BasisBank credentials were rejected. Re-enter login and password and retry.',
    'basisbank_guidance_invalid_otp' => 'The BasisBank OTP was not accepted. Request a new code and retry.',
    'basisbank_guidance_trust_device_required' => 'Trust-device confirmation is required. Re-run the same login step and enter the trusted-device code.',
    'basisbank_guidance_session_expired' => 'Your BasisBank web session appears expired. Restart authentication from login/password.',
    'basisbank_guidance_generic' => 'BasisBank authentication was not successful. Verify credentials and required options, then try again.',

    'label_tbank_api_token'       => 'TBank session ID (auto)',
    'placeholder_tbank_api_token' => 'TBank session ID (auto)',
    'help_tbank_api_token'        => 'This value is managed by the TBank web login flow.',
    'label_tbank_login'           => 'TBank session ID (auto)',
    'placeholder_tbank_login'     => 'TBank session ID (auto)',
    'help_tbank_login'            => 'This value is managed by the TBank web login flow.',
    'label_tbank_device_pin'      => 'TBank device PIN',
    'placeholder_tbank_device_pin' => 'Enter PIN set on TBank login flow',
    'help_tbank_device_pin'       => 'Required. Saved locally in importer secret storage and reused for later TBank sessions.',
    'tbank_auth_intro'            => 'Authentication uses TBank retail web login. After pressing Authenticate you will complete login at TBank and return to importer callback.',
    'tbank_session_detected'      => 'A reusable TBank session was found. You can still start a fresh web login if needed.',
    'tbank_session_missing'       => 'No active TBank session found. Start web login to authenticate.',
    'tbank_button_start_web_login' => 'Authenticate via TBank web login',
    'tbank_upload_state_ready'    => 'TBank session is active and ready for import.',
    'tbank_upload_state_missing'  => 'TBank session is missing or expired. Return to Authentication and login again.',
    'tbank_upload_hint'           => 'Importer uses the stored TBank session (session ID + cookies).',

    'label_trc20_api_key'       => 'TRC-20 API key',
    'placeholder_trc20_api_key'      => 'TRC-20 API key',
    'placeholder_trc20_api_key_keep' => 'Leave empty to keep existing key',
    'help_trc20_api_key'        => 'Enter your TRC-20 API key',
    'label_trc20_wallets'       => 'TRC-20 wallets',
    'placeholder_trc20_wallets' => 'TRC-20 wallet addresses, one per line or comma-separated',
    'help_trc20_wallets'        => 'Enter at least one TRC-20 wallet address (TRC-20 USDT-only). Separate multiple wallets with commas or new lines.',

    // sophtron auth
    'label_sophtron_user_id' => 'Sophtron User ID',
    'label_sophtron_access_key' => 'Sophtron Access Key',
    'help_sophtron_user_id' => 'You can find this value in your Sophtron API settings',
    'placeholder_sophtron_user_id' => 'Sophtron User ID',
    'placeholder_sophtron_access_key' => 'Sophtron Access Key',
    'help_sophtron_access_key' => 'You can find this value in your Sophtron API settings',

    'label_spectre_app_id'          => 'Spectre / Salt Edge App Id',
    'label_spectre_secret'          => 'Spectre / Salt Edge Secret',
    'placeholder_spectre_app_id'    => 'Spectre / Salt Edge App Id',
    'placeholder_spectre_secret'    => 'Spectre / Salt Edge Secret',
    'help_spectre_app_id'           => 'Enter your Spectre / Salt Edge App Id',
    'help_spectre_secret'           => 'Spectre / Salt Edge Secret',

    // Enable Banking auth
    'label_eb_app_id'               => 'Enable Banking Application ID',
    'placeholder_eb_app_id'         => 'Enable Banking Application ID',
    'help_eb_app_id'                => 'Enter your Enable Banking Application ID',
    'label_eb_private_key'          => 'Enable Banking Private Key',
    'placeholder_eb_private_key'    => 'Enable Banking Private Key (PEM format)',
    'help_eb_private_key'           => 'Paste your Enable Banking private key in PEM format',

    // column roles for CSV import:
    'column__ignore'                => '(ignore this column)',
    'column_account-iban'           => 'Asset account (IBAN)',
    'column_account-id'             => 'Asset account ID (matching FF3)',
    'column_account-name'           => 'Asset account (name)',
    'column_account-bic'            => 'Asset account (BIC)',
    'column_amount'                 => 'Amount',
    'column_amount_foreign'         => 'Amount (in foreign currency)',
    'column_amount_debit'           => 'Amount (debit column)',
    'column_amount_credit'          => 'Amount (credit column)',
    'column_amount_negated'         => 'Amount (negated column)',
    'column_amount-comma-separated' => 'Amount (comma as decimal separator)',
    'column_bill-id'                => 'Bill ID (matching FF3)',
    'column_bill-name'              => 'Bill name',
    'column_budget-id'              => 'Budget ID (matching FF3)',
    'column_budget-name'            => 'Budget name',
    'column_category-id'            => 'Category ID (matching FF3)',
    'column_category-name'          => 'Category name',
    'column_currency-code'          => 'Currency code (ISO 4217)',
    'column_foreign-currency-code'  => 'Foreign currency code (ISO 4217)',
    'column_currency-id'            => 'Currency ID (matching FF3)',
    'column_currency-name'          => 'Currency name (matching FF3)',
    'column_currency-symbol'        => 'Currency symbol (matching FF3)',
    'column_date_interest'          => 'Date (interest date)',
    'column_date_book'              => 'Date (booking date)',
    'column_date_process'           => 'Date (transaction processing date)',
    'column_date_transaction'       => 'Date (primary transaction date)',
    'column_date_due'               => 'Date (due date)',
    'column_date_payment'           => 'Date (payment date)',
    'column_date_invoice'           => 'Date (invoice date)',
    'column_description'            => 'Description',
    'column_opposing-iban'          => 'Opposing account (IBAN)',
    'column_opposing-bic'           => 'Opposing account (BIC)',
    'column_opposing-id'            => 'Opposing account ID (matching FF3)',
    'column_external-id'            => 'External ID',
    'column_external-url'           => 'External URL',
    'column_opposing-name'          => 'Opposing account (name)',
    'column_rabo-debit-credit'      => 'Rabobank specific debit/credit indicator',
    'column_ing-debit-credit'       => 'ING specific debit/credit indicator',
    'column_generic-debit-credit'   => 'Bank debit/credit indicator',
    'column_sepa_ct_id'             => 'SEPA end-to-end Identifier',
    'column_sepa_ct_op'             => 'SEPA Opposing Account Identifier',
    'column_sepa_db'                => 'SEPA Mandate Identifier',
    'column_sepa_cc'                => 'SEPA Clearing Code',
    'column_sepa_ci'                => 'SEPA Creditor Identifier',
    'column_sepa_ep'                => 'SEPA External Purpose',
    'column_sepa_country'           => 'SEPA Country Code',
    'column_sepa_batch_id'          => 'SEPA Batch ID',
    'column_tags-comma'             => 'Tags (comma separated)',
    'column_tags-space'             => 'Tags (space separated)',
    'column_account-number'         => 'Asset account (account number)',
    'column_opposing-number'        => 'Opposing account (account number)',
    'column_note'                   => 'Note(s)',
    'column_internal_reference'     => 'Internal reference',

    // pseudo identifiers for identifier-based deduplication:
    'pseudo_identifier_label'       => 'Identifier',
    'pseudo_identifier_badge'       => 'Pseudo Identifier',
    'pseudo_identifier_combines'    => 'Combines columns:',
    'pseudo_identifier_locked_to'   => 'This pseudo identifier is locked to',
    'pseudo_identifier_used_in'     => 'Used in pseudo identifier',

    'account_types_asset'           => 'Asset accounts',
    'account_types_liabilities'     => 'Liabilities',
    'account_types_revenue'         => 'Revenue accounts',
    'account_types_expense'         => 'Expense accounts',
    'account_types_cash'            => 'Cash accounts',
];
