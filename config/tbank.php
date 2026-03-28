<?php

declare(strict_types=1);

return [
    'api_token'             => env('TBANK_API_TOKEN', ''),
    'api_url'               => envNonEmpty('TBANK_API_URL', 'https://www.tbank.ru/api/common/v1/'),
    'accounts_endpoint'     => env('TBANK_ACCOUNTS_ENDPOINT', 'accounts_light_ib'),
    'transactions_endpoint' => env('TBANK_TRANSACTIONS_ENDPOINT', 'operations'),
    'session_endpoint'      => envNonEmpty('TBANK_SESSION_ENDPOINT', 'https://www.tbank.ru/api/common/v1/session'),
    'session_authorize_url' => envNonEmpty('TBANK_SESSION_AUTHORIZE_URL', 'https://www.tbank.ru/api/common/v1/session/authorize/'),
    'session_check_auth_url' => envNonEmpty('TBANK_SESSION_CHECK_AUTH_URL', 'https://www.tbank.ru/api/common/v1/session/check_auth'),
    'callback_uri'          => envNonEmpty('TBANK_CALLBACK_URI', 'http://localhost:9998/authenticate-flow/tbank/callback'),
    'origin'                => envNonEmpty('TBANK_ORIGIN', 'web,ib5,platform'),
    'app_name'              => envNonEmpty('TBANK_APP_NAME', 'supreme'),
    'app_version'           => envNonEmpty('TBANK_APP_VERSION', '0.0.1'),
    'platform'              => envNonEmpty('TBANK_PLATFORM', 'web'),
    'session_app_name'      => envNonEmpty('TBANK_SESSION_APP_NAME', 'pwaplatform'),
    'session_app_version'   => envNonEmpty('TBANK_SESSION_APP_VERSION', 'pwaplatform-prod-v0.1.0'),
    'referer'               => envNonEmpty('TBANK_REFERER', 'https://www.tbank.ru/mybank/'),
    'unique_column_options' => [
        'external-id' => 'External identifier',
    ],
];
