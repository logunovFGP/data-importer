<?php

declare(strict_types=1);

return [
    'api_key'             => env('TRC20_API_KEY', ''),
    'api_url'             => envNonEmpty('TRC20_API_URL', 'https://apilist.tronscanapi.com/api/'),
    'wallets_endpoint'    => env('TRC20_WALLETS_ENDPOINT', 'account'),
    'transactions_endpoint' => env('TRC20_TRANSACTIONS_ENDPOINT', 'transfer'),
    'request_timeout'     => (int) env('TRC20_REQUEST_TIMEOUT', 30),
    'page_size'           => (int) env('TRC20_PAGE_SIZE', 100),
    'max_pages'           => (int) env('TRC20_MAX_PAGES', 100),
    'wallets'             => env('TRC20_WALLETS', ''),
];
