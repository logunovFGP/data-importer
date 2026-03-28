<?php

declare(strict_types=1);

return [
    'api_key'                => env('TRC20_API_KEY', ''),
    'api_url'                => envNonEmpty('TRC20_API_URL', 'https://api.trongrid.io'),
    'transactions_endpoint'  => env('TRC20_TRANSACTIONS_ENDPOINT', '/v1/accounts/%s/transactions/trc20'),
    'wallets_endpoint'       => env('TRC20_WALLETS_ENDPOINT', '/v1/accounts/%s'),
    'supported_tokens'       => env('TRC20_TOKENS', 'TRX,USDT'),
    'trx_transactions_endpoint' => env('TRC20_TRX_TRANSACTIONS_ENDPOINT', '/v1/accounts/%s/transactions'),
    'request_timeout'        => (int) env('TRC20_REQUEST_TIMEOUT', 30),
    'page_size'              => (int) env('TRC20_PAGE_SIZE', 200),
    'max_pages'              => (int) env('TRC20_MAX_PAGES', 100),
    'wallets'                => env('TRC20_WALLETS', ''),
];
