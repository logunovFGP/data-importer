<?php

declare(strict_types=1);

return [
    'api_token'             => env('BASISBANK_API_TOKEN', ''),
    'consent_id'            => env('BASISBANK_CONSENT_ID', ''),
    'api_url'               => envNonEmpty('BASISBANK_API_URL', 'https://api.basisbank.com/psd2/v1/'),
    'accounts_endpoint'     => env('BASISBANK_ACCOUNTS_ENDPOINT', 'accounts'),
    'transactions_endpoint' => env('BASISBANK_TRANSACTIONS_ENDPOINT', 'accounts/{account_id}/transactions'),
    'psu_ip_address'        => env('BASISBANK_PSU_IP_ADDRESS', ''),
    'psu_id'                => env('BASISBANK_PSU_ID', ''),
    'auth_connect_timeout'  => (float) env('BASISBANK_AUTH_CONNECT_TIMEOUT', 15.0),
    'auth_request_timeout'  => (float) env('BASISBANK_AUTH_REQUEST_TIMEOUT', 45.0),
    'auth_timeout_retries'  => (int) env('BASISBANK_AUTH_TIMEOUT_RETRIES', 2),
    'auth_retry_delay_ms'   => (int) env('BASISBANK_AUTH_RETRY_DELAY_MS', 500),
    // How many years of statement history to request when no explicit start date is provided.
    // Default 25 covers essentially all account history. Can be overridden via env variable.
    'statement_history_years' => (int) env('BASISBANK_STATEMENT_HISTORY_YEARS', 25),

    'unique_column_options' => [
        'external-id' => 'External identifier',
    ],
];
