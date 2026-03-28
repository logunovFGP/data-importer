<?php

declare(strict_types=1);

return [
    'api_key'                => env('TRC20_API_KEY', ''),
    'api_url'                => envNonEmpty('TRC20_API_URL', 'https://api.trongrid.io'),
    'transactions_endpoint'  => env('TRC20_TRANSACTIONS_ENDPOINT', '/v1/accounts/%s/transactions/trc20'),
    'wallets_endpoint'       => env('TRC20_WALLETS_ENDPOINT', '/v1/accounts/%s'),
    'usdt_contract_address'  => env('TRC20_USDT_CONTRACT', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'),
    'request_timeout'        => (int) env('TRC20_REQUEST_TIMEOUT', 30),
    'page_size'              => (int) env('TRC20_PAGE_SIZE', 200),
    'max_pages'              => (int) env('TRC20_MAX_PAGES', 100),
    'wallets'                => env('TRC20_WALLETS', ''),
];
