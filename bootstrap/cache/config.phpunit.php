<?php return array (
  'concurrency' => 
  array (
    'default' => 'process',
  ),
  'app' => 
  array (
    'name' => 'Laravel',
    'env' => 'testing',
    'debug' => false,
    'url' => 'http://localhost',
    'frontend_url' => 'http://localhost:3000',
    'asset_url' => NULL,
    'timezone' => 'Europe/Amsterdam',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'faker_locale' => 'en_US',
    'cipher' => 'AES-256-CBC',
    'key' => 'PSPGRY5PWJ6D1UMZLBL5BNAZIN4I1QSD',
    'previous_keys' => 
    array (
    ),
    'maintenance' => 
    array (
      'driver' => 'file',
      'store' => 'database',
    ),
    'providers' => 
    array (
      0 => 'Illuminate\\Auth\\AuthServiceProvider',
      1 => 'Illuminate\\Broadcasting\\BroadcastServiceProvider',
      2 => 'Illuminate\\Bus\\BusServiceProvider',
      3 => 'Illuminate\\Cache\\CacheServiceProvider',
      4 => 'Illuminate\\Foundation\\Providers\\ConsoleSupportServiceProvider',
      5 => 'Illuminate\\Cookie\\CookieServiceProvider',
      6 => 'Illuminate\\Database\\DatabaseServiceProvider',
      7 => 'Illuminate\\Encryption\\EncryptionServiceProvider',
      8 => 'Illuminate\\Filesystem\\FilesystemServiceProvider',
      9 => 'Illuminate\\Foundation\\Providers\\FoundationServiceProvider',
      10 => 'Illuminate\\Hashing\\HashServiceProvider',
      11 => 'Illuminate\\Mail\\MailServiceProvider',
      12 => 'Illuminate\\Notifications\\NotificationServiceProvider',
      13 => 'Illuminate\\Pagination\\PaginationServiceProvider',
      14 => 'Illuminate\\Pipeline\\PipelineServiceProvider',
      15 => 'Illuminate\\Queue\\QueueServiceProvider',
      16 => 'Illuminate\\Redis\\RedisServiceProvider',
      17 => 'Illuminate\\Auth\\Passwords\\PasswordResetServiceProvider',
      18 => 'Illuminate\\Session\\SessionServiceProvider',
      19 => 'Illuminate\\Translation\\TranslationServiceProvider',
      20 => 'Illuminate\\Validation\\ValidationServiceProvider',
      21 => 'Illuminate\\View\\ViewServiceProvider',
      22 => 'App\\Providers\\AppServiceProvider',
      23 => 'App\\Providers\\AuthServiceProvider',
      24 => 'App\\Providers\\EventServiceProvider',
      25 => 'App\\Providers\\RouteServiceProvider',
    ),
    'aliases' => 
    array (
      'App' => 'Illuminate\\Support\\Facades\\App',
      'Arr' => 'Illuminate\\Support\\Arr',
      'Artisan' => 'Illuminate\\Support\\Facades\\Artisan',
      'Auth' => 'Illuminate\\Support\\Facades\\Auth',
      'Blade' => 'Illuminate\\Support\\Facades\\Blade',
      'Broadcast' => 'Illuminate\\Support\\Facades\\Broadcast',
      'Bus' => 'Illuminate\\Support\\Facades\\Bus',
      'Cache' => 'Illuminate\\Support\\Facades\\Cache',
      'Config' => 'Illuminate\\Support\\Facades\\Config',
      'Cookie' => 'Illuminate\\Support\\Facades\\Cookie',
      'Crypt' => 'Illuminate\\Support\\Facades\\Crypt',
      'DB' => 'Illuminate\\Support\\Facades\\DB',
      'Eloquent' => 'Illuminate\\Database\\Eloquent\\Model',
      'Event' => 'Illuminate\\Support\\Facades\\Event',
      'File' => 'Illuminate\\Support\\Facades\\File',
      'Gate' => 'Illuminate\\Support\\Facades\\Gate',
      'Hash' => 'Illuminate\\Support\\Facades\\Hash',
      'Http' => 'Illuminate\\Support\\Facades\\Http',
      'Lang' => 'Illuminate\\Support\\Facades\\Lang',
      'Log' => 'Illuminate\\Support\\Facades\\Log',
      'Mail' => 'Illuminate\\Support\\Facades\\Mail',
      'Notification' => 'Illuminate\\Support\\Facades\\Notification',
      'Password' => 'Illuminate\\Support\\Facades\\Password',
      'Queue' => 'Illuminate\\Support\\Facades\\Queue',
      'Redirect' => 'Illuminate\\Support\\Facades\\Redirect',
      'Redis' => 'Illuminate\\Support\\Facades\\Redis',
      'Request' => 'Illuminate\\Support\\Facades\\Request',
      'Response' => 'Illuminate\\Support\\Facades\\Response',
      'Route' => 'Illuminate\\Support\\Facades\\Route',
      'Schema' => 'Illuminate\\Support\\Facades\\Schema',
      'Session' => 'Illuminate\\Support\\Facades\\Session',
      'Storage' => 'Illuminate\\Support\\Facades\\Storage',
      'Str' => 'Illuminate\\Support\\Str',
      'URL' => 'Illuminate\\Support\\Facades\\URL',
      'Validator' => 'Illuminate\\Support\\Facades\\Validator',
      'View' => 'Illuminate\\Support\\Facades\\View',
      'Steam' => 'App\\Support\\Facades\\Steam',
    ),
  ),
  'auth' => 
  array (
    'defaults' => 
    array (
      'guard' => 'web',
      'passwords' => 'users',
    ),
    'guards' => 
    array (
      'web' => 
      array (
        'driver' => 'session',
        'provider' => 'users',
      ),
      'api' => 
      array (
        'driver' => 'token',
        'provider' => 'users',
        'hash' => false,
      ),
    ),
    'providers' => 
    array (
      'users' => 
      array (
        'driver' => 'eloquent',
        'model' => 'App\\User',
      ),
    ),
    'passwords' => 
    array (
      'users' => 
      array (
        'provider' => 'users',
        'table' => 'password_resets',
        'expire' => 60,
        'throttle' => 60,
      ),
    ),
    'password_timeout' => 10800,
    'line_a' => 'Said the apple to the orange',
    'line_b' => 'Oh I wanted you to come',
    'line_c' => 'Close to me and kiss me to the core',
    'line_d' => 'Then you might know me like no other orange',
    'line_e' => 'Has ever done before',
  ),
  'basisbank' => 
  array (
    'api_token' => '',
    'consent_id' => '',
    'api_url' => 'https://api.basisbank.com/psd2/v1/',
    'accounts_endpoint' => 'accounts',
    'transactions_endpoint' => 'accounts/{account_id}/transactions',
    'psu_ip_address' => '',
    'psu_id' => '',
    'unique_column_options' => 
    array (
      'external-id' => 'External identifier',
    ),
  ),
  'broadcasting' => 
  array (
    'default' => 'null',
    'connections' => 
    array (
      'reverb' => 
      array (
        'driver' => 'reverb',
        'key' => NULL,
        'secret' => NULL,
        'app_id' => NULL,
        'options' => 
        array (
          'host' => NULL,
          'port' => 443,
          'scheme' => 'https',
          'useTLS' => true,
        ),
        'client_options' => 
        array (
        ),
      ),
      'pusher' => 
      array (
        'driver' => 'pusher',
        'key' => NULL,
        'secret' => NULL,
        'app_id' => NULL,
        'options' => 
        array (
          'cluster' => NULL,
          'useTLS' => true,
        ),
      ),
      'ably' => 
      array (
        'driver' => 'ably',
        'key' => NULL,
      ),
      'log' => 
      array (
        'driver' => 'log',
      ),
      'null' => 
      array (
        'driver' => 'null',
      ),
      'redis' => 
      array (
        'driver' => 'redis',
        'connection' => 'default',
      ),
    ),
  ),
  'cache' => 
  array (
    'default' => 'array',
    'stores' => 
    array (
      'array' => 
      array (
        'driver' => 'array',
        'serialize' => false,
      ),
      'session' => 
      array (
        'driver' => 'session',
        'key' => '_cache',
      ),
      'database' => 
      array (
        'driver' => 'database',
        'table' => 'cache',
        'connection' => NULL,
      ),
      'file' => 
      array (
        'driver' => 'file',
        'path' => '/var/www/html/storage/framework/cache/data',
      ),
      'memcached' => 
      array (
        'driver' => 'memcached',
        'persistent_id' => NULL,
        'sasl' => 
        array (
          0 => NULL,
          1 => NULL,
        ),
        'options' => 
        array (
        ),
        'servers' => 
        array (
          0 => 
          array (
            'host' => '127.0.0.1',
            'port' => 11211,
            'weight' => 100,
          ),
        ),
      ),
      'redis' => 
      array (
        'driver' => 'redis',
        'connection' => 'cache',
      ),
      'dynamodb' => 
      array (
        'driver' => 'dynamodb',
        'key' => NULL,
        'secret' => NULL,
        'region' => 'us-east-1',
        'table' => 'cache',
        'endpoint' => NULL,
      ),
      'octane' => 
      array (
        'driver' => 'octane',
      ),
      'failover' => 
      array (
        'driver' => 'failover',
        'stores' => 
        array (
          0 => 'database',
          1 => 'array',
        ),
      ),
      'apc' => 
      array (
        'driver' => 'apc',
      ),
    ),
    'prefix' => 'laravel_cache',
  ),
  'camt' => 
  array (
    'roles' => 
    array (
      'level_a' => 
      array (
        '_ignore' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'field' => 'ignored',
          'converter' => 'Ignore',
          'mapper' => NULL,
          'append_value' => false,
        ),
        'note' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'field' => 'note',
          'converter' => 'CleanNlString',
          'append_value' => true,
        ),
      ),
      'level_b' => 
      array (
        '_ignore' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'field' => 'ignored',
          'converter' => 'Ignore',
          'mapper' => NULL,
          'append_value' => false,
        ),
        'note' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'field' => 'note',
          'converter' => 'CleanNlString',
          'append_value' => true,
        ),
      ),
      'dates' => 
      array (
        '_ignore' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'field' => 'ignored',
          'converter' => 'Ignore',
          'mapper' => NULL,
          'append_value' => false,
        ),
        'date_transaction' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'converter' => 'Date',
          'field' => 'date',
          'append_value' => false,
        ),
        'date_interest' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'converter' => 'Date',
          'field' => 'date-interest',
          'append_value' => false,
        ),
        'date_book' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'converter' => 'Date',
          'field' => 'date-book',
          'append_value' => false,
        ),
        'date_process' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'converter' => 'Date',
          'field' => 'date-process',
          'append_value' => false,
        ),
        'date_due' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'converter' => 'Date',
          'field' => 'date-due',
          'append_value' => false,
        ),
        'date_payment' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'converter' => 'Date',
          'field' => 'date-payment',
          'append_value' => false,
        ),
        'date_invoice' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'converter' => 'Date',
          'field' => 'date-invoice',
          'append_value' => false,
        ),
      ),
      'iban' => 
      array (
        '_ignore' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'field' => 'ignored',
          'converter' => 'Ignore',
          'mapper' => NULL,
          'append_value' => false,
        ),
        'account-iban' => 
        array (
          'mappable' => true,
          'pre-process-map' => false,
          'field' => 'asset-account-iban',
          'converter' => 'Iban',
          'mapper' => 'AssetAccounts',
          'append_value' => false,
        ),
        'opposing-iban' => 
        array (
          'mappable' => true,
          'pre-process-map' => false,
          'field' => 'opposing-account-iban',
          'converter' => 'Iban',
          'mapper' => 'OpposingAccounts',
          'append_value' => false,
        ),
      ),
      'account_number' => 
      array (
        '_ignore' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'field' => 'ignored',
          'converter' => 'Ignore',
          'mapper' => NULL,
          'append_value' => false,
        ),
        'account-number' => 
        array (
          'mappable' => true,
          'pre-process-map' => false,
          'field' => 'asset-account-number',
          'converter' => 'CleanString',
          'mapper' => 'AssetAccounts',
          'append_value' => false,
        ),
        'opposing-number' => 
        array (
          'mappable' => true,
          'pre-process-map' => false,
          'field' => 'opposing-account-number',
          'converter' => 'CleanString',
          'mapper' => 'OpposingAccounts',
          'append_value' => false,
        ),
      ),
      'account_name' => 
      array (
        '_ignore' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'field' => 'ignored',
          'converter' => 'Ignore',
          'mapper' => NULL,
          'append_value' => false,
        ),
        'account-name' => 
        array (
          'mappable' => true,
          'pre-process-map' => false,
          'field' => 'asset-account-name',
          'converter' => 'CleanString',
          'mapper' => 'AssetAccounts',
          'append_value' => false,
        ),
        'opposing-name' => 
        array (
          'mappable' => true,
          'pre-process-map' => false,
          'field' => 'opposing-account-name',
          'converter' => 'CleanString',
          'mapper' => 'OpposingAccounts',
          'append_value' => false,
        ),
      ),
      'meta' => 
      array (
        '_ignore' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'field' => 'ignored',
          'converter' => 'Ignore',
          'mapper' => NULL,
          'append_value' => false,
        ),
        'description' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'converter' => 'CleanString',
          'field' => 'description',
          'append_value' => true,
        ),
        'note' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'field' => 'note',
          'converter' => 'CleanNlString',
          'append_value' => true,
        ),
        'external-id' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'converter' => 'CleanString',
          'field' => 'external-id',
          'append_value' => false,
        ),
        'external-url' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'converter' => 'CleanUrl',
          'field' => 'external-url',
          'append_value' => false,
        ),
        'internal_reference' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'converter' => 'Description',
          'field' => 'internal_reference',
          'append_value' => true,
        ),
      ),
      'amount' => 
      array (
        '_ignore' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'field' => 'ignored',
          'converter' => 'Ignore',
          'mapper' => NULL,
          'append_value' => false,
        ),
        'amount' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'converter' => 'Amount',
          'field' => 'amount',
          'append_value' => false,
        ),
        'amount_debit' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'converter' => 'AmountDebit',
          'field' => 'amount_debit',
          'append_value' => false,
        ),
        'amount_credit' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'converter' => 'AmountCredit',
          'field' => 'amount_credit',
          'append_value' => false,
        ),
        'amount_negated' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'converter' => 'AmountNegated',
          'field' => 'amount_negated',
          'append_value' => false,
        ),
        'amount_foreign' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'converter' => 'Amount',
          'field' => 'amount_foreign',
          'append_value' => false,
        ),
      ),
      'currency' => 
      array (
        '_ignore' => 
        array (
          'mappable' => false,
          'pre-process-map' => false,
          'field' => 'ignored',
          'converter' => 'Ignore',
          'mapper' => NULL,
          'append_value' => false,
        ),
        'currency-id' => 
        array (
          'mappable' => true,
          'pre-process-map' => false,
          'field' => 'currency',
          'converter' => 'CleanId',
          'mapper' => 'TransactionCurrencies',
          'append_value' => false,
        ),
        'currency-name' => 
        array (
          'mappable' => true,
          'pre-process-map' => false,
          'converter' => 'CleanString',
          'field' => 'currency',
          'mapper' => 'TransactionCurrencies',
          'append_value' => false,
        ),
        'currency-symbol' => 
        array (
          'mappable' => true,
          'pre-process-map' => false,
          'converter' => 'CleanString',
          'field' => 'currency',
          'mapper' => 'TransactionCurrencies',
          'append_value' => false,
        ),
        'currency-code' => 
        array (
          'mappable' => true,
          'pre-process-map' => false,
          'converter' => 'CleanString',
          'field' => 'currency',
          'mapper' => 'TransactionCurrencies',
          'append_value' => false,
        ),
        'foreign-currency-code' => 
        array (
          'mappable' => true,
          'pre-process-map' => false,
          'converter' => 'CleanString',
          'field' => 'foreign_currency',
          'mapper' => 'TransactionCurrencies',
          'append_value' => false,
        ),
      ),
    ),
    'all_roles' => 
    array (
      '_ignore' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'field' => 'ignored',
        'converter' => 'Ignore',
        'mapper' => NULL,
        'append_value' => false,
      ),
      'note' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'field' => 'note',
        'converter' => 'CleanNlString',
        'append_value' => true,
      ),
      'date_transaction' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Date',
        'field' => 'date',
        'append_value' => false,
      ),
      'date_interest' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Date',
        'field' => 'date-interest',
        'append_value' => false,
      ),
      'date_book' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Date',
        'field' => 'date-book',
        'append_value' => false,
      ),
      'date_process' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Date',
        'field' => 'date-process',
        'append_value' => false,
      ),
      'date_due' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Date',
        'field' => 'date-due',
        'append_value' => false,
      ),
      'date_payment' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Date',
        'field' => 'date-payment',
        'append_value' => false,
      ),
      'date_invoice' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Date',
        'field' => 'date-invoice',
        'append_value' => false,
      ),
      'account-iban' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'field' => 'asset-account-iban',
        'converter' => 'Iban',
        'mapper' => 'AssetAccounts',
        'append_value' => false,
      ),
      'opposing-iban' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'field' => 'opposing-account-iban',
        'converter' => 'Iban',
        'mapper' => 'OpposingAccounts',
        'append_value' => false,
      ),
      'account-number' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'field' => 'asset-account-number',
        'converter' => 'CleanString',
        'mapper' => 'AssetAccounts',
        'append_value' => false,
      ),
      'opposing-number' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'field' => 'opposing-account-number',
        'converter' => 'CleanString',
        'mapper' => 'OpposingAccounts',
        'append_value' => false,
      ),
      'external-id' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'CleanString',
        'field' => 'external-id',
        'append_value' => false,
      ),
      'external-url' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'CleanUrl',
        'field' => 'external-url',
        'append_value' => false,
      ),
      'internal_reference' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Description',
        'field' => 'internal_reference',
        'append_value' => true,
      ),
      'amount' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Amount',
        'field' => 'amount',
        'append_value' => false,
      ),
      'amount_debit' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'AmountDebit',
        'field' => 'amount_debit',
        'append_value' => false,
      ),
      'amount_credit' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'AmountCredit',
        'field' => 'amount_credit',
        'append_value' => false,
      ),
      'amount_negated' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'AmountNegated',
        'field' => 'amount_negated',
        'append_value' => false,
      ),
      'amount_foreign' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Amount',
        'field' => 'amount_foreign',
        'append_value' => false,
      ),
      'currency-id' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'field' => 'currency',
        'converter' => 'CleanId',
        'mapper' => 'TransactionCurrencies',
        'append_value' => false,
      ),
      'currency-name' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'converter' => 'CleanString',
        'field' => 'currency',
        'mapper' => 'TransactionCurrencies',
        'append_value' => false,
      ),
      'currency-code' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'converter' => 'CleanString',
        'field' => 'currency',
        'mapper' => 'TransactionCurrencies',
        'append_value' => false,
      ),
      'foreign-currency-code' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'converter' => 'CleanString',
        'field' => 'foreign_currency',
        'mapper' => 'TransactionCurrencies',
        'append_value' => false,
      ),
      'currency-symbol' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'converter' => 'CleanString',
        'field' => 'currency',
        'mapper' => 'TransactionCurrencies',
        'append_value' => false,
      ),
      'account-name' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'field' => 'asset-account-name',
        'converter' => 'CleanString',
        'mapper' => 'AssetAccounts',
        'append_value' => false,
      ),
      'opposing-name' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'field' => 'opposing-account-name',
        'converter' => 'CleanString',
        'mapper' => 'OpposingAccounts',
        'append_value' => false,
      ),
      'description' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'CleanString',
        'field' => 'description',
        'append_value' => true,
      ),
      'generic-debit-credit' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'BankDebitCredit',
        'field' => 'amount-modifier',
        'append_value' => false,
      ),
    ),
    'fields' => 
    array (
      'messageId' => 
      array (
        'title' => 'messageId',
        'roles' => 'level_a',
        'mappable' => false,
        'default_role' => 'note',
        'level' => 'A',
      ),
      'statementId' => 
      array (
        'title' => 'statementId',
        'roles' => 'level_b',
        'mappable' => false,
        'default_role' => 'note',
        'level' => 'B',
      ),
      'statementCreationDate' => 
      array (
        'title' => 'statementCreationDate',
        'roles' => 'dates',
        'mappable' => false,
        'default_role' => 'date_process',
        'level' => 'B',
      ),
      'statementAccountIban' => 
      array (
        'title' => 'statementAccountIban',
        'roles' => 'iban',
        'mappable' => true,
        'default_role' => 'account-iban',
        'level' => 'B',
      ),
      'statementAccountNumber' => 
      array (
        'title' => 'statementAccountNumber',
        'roles' => 'account_number',
        'mappable' => true,
        'default_role' => 'account-number',
        'level' => 'B',
      ),
      'entryAccountServicerReference' => 
      array (
        'section' => false,
        'title' => 'entryAccountServicerReference',
        'default_role' => 'external-id',
        'roles' => 'meta',
        'mappable' => false,
        'level' => 'C',
      ),
      'entryReference' => 
      array (
        'section' => false,
        'title' => 'entryReference',
        'default_role' => 'note',
        'roles' => 'meta',
        'mappable' => false,
        'level' => 'C',
      ),
      'entryAdditionalInfo' => 
      array (
        'section' => false,
        'title' => 'entryAdditionalInfo',
        'default_role' => 'description',
        'roles' => 'meta',
        'mappable' => false,
        'level' => 'C',
      ),
      'entryAmount' => 
      array (
        'section' => false,
        'title' => 'entryAmount',
        'default_role' => 'amount',
        'roles' => 'amount',
        'mappable' => false,
        'level' => 'C',
      ),
      'entryAmountCurrency' => 
      array (
        'title' => 'entryAmountCurrency',
        'default_role' => 'currency-code',
        'roles' => 'currency',
        'mappable' => true,
        'level' => 'C',
      ),
      'entryValueDate' => 
      array (
        'title' => 'entryValueDate',
        'default_role' => 'date_payment',
        'roles' => 'dates',
        'mappable' => false,
        'level' => 'C',
      ),
      'entryBookingDate' => 
      array (
        'title' => 'entryBookingDate',
        'default_role' => 'date_book',
        'roles' => 'dates',
        'mappable' => false,
        'level' => 'C',
      ),
      'entryBtcDomainCode' => 
      array (
        'title' => 'entryBtcDomainCode',
        'default_role' => 'note',
        'roles' => 'meta',
        'mappable' => false,
        'level' => 'C',
      ),
      'entryBtcFamilyCode' => 
      array (
        'title' => 'entryBtcFamilyCode',
        'default_role' => 'note',
        'roles' => 'meta',
        'mappable' => false,
        'level' => 'C',
      ),
      'entryBtcSubFamilyCode' => 
      array (
        'title' => 'entryBtcSubFamilyCode',
        'default_role' => 'note',
        'roles' => 'meta',
        'mappable' => false,
        'level' => 'C',
      ),
      'entryDetailAccountServicerReference' => 
      array (
        'title' => 'entryDetailAccountServicerReference',
        'default_role' => 'external-id',
        'roles' => 'meta',
        'mappable' => false,
        'level' => 'D',
      ),
      'entryDetailEndToEndId' => 
      array (
        'title' => 'entryDetailEndToEndId',
        'default_role' => 'external-id',
        'roles' => 'meta',
        'mappable' => false,
        'level' => 'D',
      ),
      'entryDetailRemittanceInformationUnstructuredBlockMessage' => 
      array (
        'title' => 'entryDetailRemittanceInformationUnstructuredBlockMessage',
        'default_role' => 'description',
        'roles' => 'meta',
        'mappable' => false,
        'level' => 'D',
      ),
      'entryDetailRemittanceInformationStructuredBlockAdditionalRemittanceInformation' => 
      array (
        'title' => 'entryDetailRemittanceInformationStructuredBlockAdditionalRemittanceInformation',
        'default_role' => 'description',
        'roles' => 'meta',
        'mappable' => false,
        'level' => 'D',
      ),
      'entryDetailAmount' => 
      array (
        'title' => 'entryDetailAmount',
        'default_role' => 'amount',
        'roles' => 'amount',
        'mappable' => false,
        'level' => 'D',
      ),
      'entryDetailAmountCurrency' => 
      array (
        'title' => 'entryDetailAmountCurrency',
        'default_role' => 'currency-code',
        'roles' => 'currency',
        'mappable' => true,
        'level' => 'D',
      ),
      'entryDetailBtcDomainCode' => 
      array (
        'title' => 'entryDetailBtcDomainCode',
        'default_role' => 'note',
        'roles' => 'meta',
        'mappable' => false,
        'level' => 'D',
      ),
      'entryDetailBtcFamilyCode' => 
      array (
        'title' => 'entryDetailBtcFamilyCode',
        'default_role' => 'note',
        'roles' => 'meta',
        'mappable' => false,
        'level' => 'D',
      ),
      'entryDetailBtcSubFamilyCode' => 
      array (
        'title' => 'entryDetailBtcSubFamilyCode',
        'default_role' => 'note',
        'roles' => 'meta',
        'mappable' => false,
        'level' => 'D',
      ),
      'entryDetailOpposingAccountIban' => 
      array (
        'title' => 'entryDetailOpposingAccountIban',
        'default_role' => 'opposing-iban',
        'roles' => 'iban',
        'mappable' => true,
        'level' => 'D',
      ),
      'entryDetailOpposingAccountNumber' => 
      array (
        'title' => 'entryDetailOpposingAccountNumber',
        'default_role' => 'opposing-number',
        'roles' => 'account_number',
        'mappable' => true,
        'level' => 'D',
      ),
      'entryDetailOpposingName' => 
      array (
        'title' => 'entryDetailOpposingName',
        'default_role' => 'opposing-name',
        'roles' => 'account_name',
        'mappable' => true,
        'level' => 'D',
      ),
    ),
  ),
  'cors' => 
  array (
    'paths' => 
    array (
      0 => 'api/*',
    ),
    'allowed_methods' => 
    array (
      0 => '*',
    ),
    'allowed_origins' => 
    array (
      0 => '*',
    ),
    'allowed_origins_patterns' => 
    array (
    ),
    'allowed_headers' => 
    array (
      0 => '*',
    ),
    'exposed_headers' => 
    array (
    ),
    'max_age' => 0,
    'supports_credentials' => true,
  ),
  'csv' => 
  array (
    'delimiters' => 
    array (
      'comma' => ',',
      'semicolon' => ';',
      'tab' => '	',
      ',' => ',',
      ';' => ';',
      '	' => '	',
    ),
    'fallback_locale' => NULL,
    'delimiters_reversed' => 
    array (
      'comma' => 'comma',
      'semicolon' => 'semicolon',
      'tab' => 'tab',
      ',' => 'comma',
      ';' => 'semicolon',
      '	' => 'tab',
    ),
    'classic_roles' => 
    array (
      'original-source' => 'original_source',
      'sepa-cc' => 'sepa_cc',
      'sepa-ct-op' => 'sepa_ct_op',
      'sepa-ct-id' => 'sepa_ct_id',
      'sepa-db' => 'sepa_db',
      'sepa-country' => 'sepa_country',
      'sepa-ep' => 'sepa_ep',
      'sepa-ci' => 'sepa_ci',
      'sepa-batch-id' => 'sepa_batch_id',
      'internal-reference' => 'internal_reference',
      'date-interest' => 'date_interest',
      'date-invoice' => 'date_invoice',
      'date-book' => 'date_book',
      'date-payment' => 'date_payment',
      'date-process' => 'date_process',
      'date-due' => 'date_due',
      'date-transaction' => 'date_transaction',
    ),
    'transaction_tasks' => 
    array (
      0 => 'App\\Services\\CSV\\Conversion\\Task\\Amount',
      1 => 'App\\Services\\CSV\\Conversion\\Task\\Tags',
      2 => 'App\\Services\\CSV\\Conversion\\Task\\Currency',
      3 => 'App\\Services\\CSV\\Conversion\\Task\\Accounts',
      4 => 'App\\Services\\CSV\\Conversion\\Task\\PositiveAmount',
      5 => 'App\\Services\\CSV\\Conversion\\Task\\EmptyDescription',
      6 => 'App\\Services\\CSV\\Conversion\\Task\\EmptyAccounts',
    ),
    'import_roles' => 
    array (
      '_ignore' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'field' => 'ignored',
        'converter' => 'Ignore',
        'mapper' => NULL,
        'append_value' => false,
      ),
      'bill-id' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'field' => 'bill',
        'converter' => 'CleanId',
        'mapper' => 'Bills',
        'append_value' => false,
      ),
      'note' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'field' => 'note',
        'converter' => 'CleanNlString',
        'append_value' => true,
      ),
      'bill-name' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'field' => 'bill',
        'converter' => 'CleanString',
        'mapper' => 'Bills',
        'append_value' => false,
      ),
      'currency-id' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'field' => 'currency',
        'converter' => 'CleanId',
        'mapper' => 'TransactionCurrencies',
        'append_value' => false,
      ),
      'currency-name' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'converter' => 'CleanString',
        'field' => 'currency',
        'mapper' => 'TransactionCurrencies',
        'append_value' => false,
      ),
      'currency-code' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'converter' => 'CleanString',
        'field' => 'currency',
        'mapper' => 'TransactionCurrencies',
        'append_value' => false,
      ),
      'foreign-currency-code' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'converter' => 'CleanString',
        'field' => 'foreign_currency',
        'mapper' => 'TransactionCurrencies',
        'append_value' => false,
      ),
      'external-id' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'CleanString',
        'field' => 'external-id',
        'append_value' => false,
      ),
      'external-url' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'CleanUrl',
        'field' => 'external-url',
        'append_value' => false,
      ),
      'currency-symbol' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'converter' => 'CleanString',
        'field' => 'currency',
        'mapper' => 'TransactionCurrencies',
        'append_value' => false,
      ),
      'description' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'CleanString',
        'field' => 'description',
        'append_value' => true,
      ),
      'date_transaction' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Date',
        'field' => 'date',
        'append_value' => false,
      ),
      'date_interest' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Date',
        'field' => 'date-interest',
        'append_value' => false,
      ),
      'date_book' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Date',
        'field' => 'date-book',
        'append_value' => false,
      ),
      'date_process' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Date',
        'field' => 'date-process',
        'append_value' => false,
      ),
      'date_due' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Date',
        'field' => 'date-due',
        'append_value' => false,
      ),
      'date_payment' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Date',
        'field' => 'date-payment',
        'append_value' => false,
      ),
      'date_invoice' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Date',
        'field' => 'date-invoice',
        'append_value' => false,
      ),
      'budget-id' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'converter' => 'CleanId',
        'field' => 'budget',
        'mapper' => 'Budgets',
        'append_value' => false,
      ),
      'budget-name' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'converter' => 'CleanString',
        'field' => 'budget',
        'mapper' => 'Budgets',
        'append_value' => false,
      ),
      'rabo-debit-credit' => 
      array (
        'mappable   ' => false,
        'pre-process-map' => false,
        'converter' => 'BankDebitCredit',
        'field' => 'amount-modifier',
        'append_value' => false,
      ),
      'ing-debit-credit' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'BankDebitCredit',
        'field' => 'amount-modifier',
        'append_value' => false,
      ),
      'generic-debit-credit' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'BankDebitCredit',
        'field' => 'amount-modifier',
        'append_value' => false,
      ),
      'category-id' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'converter' => 'CleanId',
        'field' => 'category',
        'mapper' => 'Categories',
        'append_value' => false,
      ),
      'category-name' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'converter' => 'CleanString',
        'field' => 'category',
        'mapper' => 'Categories',
        'append_value' => false,
      ),
      'tags-comma' => 
      array (
        'mappable' => false,
        'pre-process-map' => true,
        'pre-process-mapper' => 'TagsComma',
        'field' => 'tags',
        'converter' => 'TagsComma',
        'mapper' => 'Tags',
        'append_value' => true,
      ),
      'tags-space' => 
      array (
        'mappable' => false,
        'pre-process-map' => true,
        'pre-process-mapper' => 'TagsSpace',
        'field' => 'tags',
        'converter' => 'TagsSpace',
        'mapper' => 'Tags',
        'append_value' => true,
      ),
      'account-id' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'field' => 'asset-account-id',
        'converter' => 'CleanId',
        'mapper' => 'AssetAccounts',
        'append_value' => false,
      ),
      'account-name' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'field' => 'asset-account-name',
        'converter' => 'CleanString',
        'mapper' => 'AssetAccounts',
        'append_value' => false,
      ),
      'account-iban' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'field' => 'asset-account-iban',
        'converter' => 'Iban',
        'mapper' => 'AssetAccounts',
        'append_value' => false,
      ),
      'account-number' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'field' => 'asset-account-number',
        'converter' => 'AccountNumber',
        'mapper' => 'AssetAccounts',
        'append_value' => false,
      ),
      'account-bic' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'field' => 'asset-account-bic',
        'converter' => 'AccountNumber',
        'append_value' => false,
      ),
      'opposing-id' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'field' => 'opposing-account-id',
        'converter' => 'CleanId',
        'mapper' => 'OpposingAccounts',
        'append_value' => false,
      ),
      'opposing-bic' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'field' => 'opposing-account-bic',
        'converter' => 'AccountNumber',
        'append_value' => false,
      ),
      'opposing-name' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'field' => 'opposing-account-name',
        'converter' => 'CleanString',
        'mapper' => 'OpposingAccounts',
        'append_value' => false,
      ),
      'opposing-iban' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'field' => 'opposing-account-iban',
        'converter' => 'Iban',
        'mapper' => 'OpposingAccounts',
        'append_value' => false,
      ),
      'opposing-number' => 
      array (
        'mappable' => true,
        'pre-process-map' => false,
        'field' => 'opposing-account-number',
        'converter' => 'AccountNumber',
        'mapper' => 'OpposingAccounts',
        'append_value' => false,
      ),
      'amount' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Amount',
        'field' => 'amount',
        'append_value' => false,
      ),
      'amount_debit' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'AmountDebit',
        'field' => 'amount_debit',
        'append_value' => false,
      ),
      'amount_credit' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'AmountCredit',
        'field' => 'amount_credit',
        'append_value' => false,
      ),
      'amount_negated' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'AmountNegated',
        'field' => 'amount_negated',
        'append_value' => false,
      ),
      'amount_foreign' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Amount',
        'field' => 'amount_foreign',
        'append_value' => false,
      ),
      'sepa_ct_id' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Description',
        'field' => 'sepa_ct_id',
        'append_value' => false,
      ),
      'sepa_ct_op' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Description',
        'field' => 'sepa_ct_op',
        'append_value' => false,
      ),
      'sepa_db' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Description',
        'field' => 'sepa_db',
        'append_value' => false,
      ),
      'sepa_cc' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Description',
        'field' => 'sepa_cc',
        'append_value' => false,
      ),
      'sepa_country' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Description',
        'field' => 'sepa_country',
        'append_value' => false,
      ),
      'sepa_ep' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Description',
        'field' => 'sepa_ep',
        'append_value' => false,
      ),
      'sepa_ci' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Description',
        'field' => 'sepa_ci',
        'append_value' => false,
      ),
      'sepa_batch_id' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Description',
        'field' => 'sepa_batch',
        'append_value' => false,
      ),
      'internal_reference' => 
      array (
        'mappable' => false,
        'pre-process-map' => false,
        'converter' => 'Description',
        'field' => 'internal_reference',
        'append_value' => true,
      ),
    ),
    'role_to_transaction' => 
    array (
      'account-id' => 'source_id',
      'account-iban' => 'source_iban',
      'account-name' => 'source_name',
      'account-number' => 'source_number',
      'account-bic' => 'source_bic',
      'opposing-id' => 'destination_id',
      'opposing-iban' => 'destination_iban',
      'opposing-name' => 'destination_name',
      'opposing-number' => 'destination_number',
      'opposing-bic' => 'destination_bic',
      'sepa_cc' => 'sepa_cc',
      'sepa_ct_op' => 'sepa_ct_op',
      'sepa_ct_id' => 'sepa_ct_id',
      'sepa_db' => 'sepa_db',
      'sepa_country' => 'sepa_country',
      'sepa_ep' => 'sepa_ep',
      'sepa_ci' => 'sepa_ci',
      'sepa_batch_id' => 'sepa_batch_id',
      'amount' => 'amount',
      'amount_debit' => 'amount_debit',
      'amount_credit' => 'amount_credit',
      'amount_negated' => 'amount_negated',
      'amount_foreign' => 'foreign_amount',
      'foreign-currency-id' => 'foreign_currency_id',
      'foreign-currency-code' => 'foreign_currency_code',
      'bill-id' => 'bill_id',
      'bill-name' => 'bill_name',
      'budget-id' => 'budget_id',
      'budget-name' => 'budget_name',
      'category-id' => 'category_id',
      'category-name' => 'category_name',
      'currency-id' => 'currency_id',
      'currency-name' => 'currency_name',
      'currency-symbol' => 'currency_symbol',
      'description' => 'description',
      'note' => 'notes',
      'ing-debit-credit' => 'amount_modifier',
      'rabo-debit-credit' => 'amount_modifier',
      'generic-debit-credit' => 'amount_modifier',
      'external-id' => 'external_id',
      'external-url' => 'external_url',
      'internal_reference' => 'internal_reference',
      'original-source' => 'original_source',
      'tags-comma' => 'tags_comma',
      'tags-space' => 'tags_space',
      'date_transaction' => 'date',
      'date_interest' => 'interest_date',
      'date_book' => 'book_date',
      'date_process' => 'process_date',
      'date_due' => 'due_date',
      'date_payment' => 'payment_date',
      'date_invoice' => 'invoice_date',
      'currency-code' => 'currency_code',
    ),
    'search_modifier' => 
    array (
      'note' => 'notes_are',
      'notes' => 'notes_are',
      'external-id' => 'external_id_is',
      'external_id' => 'external_id_is',
      'description' => 'description_is',
      'internal_reference' => 'internal_reference_is',
      'internal-reference' => 'internal_reference_is',
    ),
  ),
  'database' => 
  array (
    'default' => 'sqlite',
    'connections' => 
    array (
      'sqlite' => 
      array (
        'driver' => 'sqlite',
        'url' => NULL,
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => '1',
      ),
      'mysql' => 
      array (
        'driver' => 'mysql',
        'url' => NULL,
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => ':memory:',
        'username' => 'forge',
        'password' => '',
        'unix_socket' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'prefix_indexes' => true,
        'strict' => true,
        'engine' => NULL,
      ),
      'mariadb' => 
      array (
        'driver' => 'mariadb',
        'url' => NULL,
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => ':memory:',
        'username' => 'root',
        'password' => '',
        'unix_socket' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'prefix_indexes' => true,
        'strict' => true,
        'engine' => NULL,
        'options' => 
        array (
        ),
      ),
      'pgsql' => 
      array (
        'driver' => 'pgsql',
        'url' => NULL,
        'host' => '127.0.0.1',
        'port' => '5432',
        'database' => ':memory:',
        'username' => 'forge',
        'password' => '',
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'public',
        'sslmode' => 'prefer',
      ),
      'sqlsrv' => 
      array (
        'driver' => 'sqlsrv',
        'url' => NULL,
        'host' => 'localhost',
        'port' => '1433',
        'database' => ':memory:',
        'username' => 'forge',
        'password' => '',
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
      ),
    ),
    'migrations' => 'migrations',
    'redis' => 
    array (
      'client' => 'phpredis',
      'options' => 
      array (
        'cluster' => 'redis',
        'prefix' => 'laravel_database_',
      ),
      'default' => 
      array (
        'url' => NULL,
        'host' => '127.0.0.1',
        'password' => NULL,
        'port' => '6379',
        'database' => '0',
      ),
      'cache' => 
      array (
        'url' => NULL,
        'host' => '127.0.0.1',
        'password' => NULL,
        'port' => '6379',
        'database' => '1',
      ),
    ),
  ),
  'file' => 
  array (
    'unique_column_options' => 
    array (
      'note' => 'The notes',
      'external-id' => 'External identifier',
      'description' => 'Transaction description',
      'internal_reference' => 'Internal reference',
    ),
  ),
  'filesystems' => 
  array (
    'default' => 'local',
    'disks' => 
    array (
      'local' => 
      array (
        'driver' => 'local',
        'root' => '/var/www/html/storage/app',
      ),
      'public' => 
      array (
        'driver' => 'local',
        'root' => '/var/www/html/storage/app/public',
        'url' => '/storage',
        'visibility' => 'public',
      ),
      's3' => 
      array (
        'driver' => 's3',
        'key' => NULL,
        'secret' => NULL,
        'region' => NULL,
        'bucket' => NULL,
        'url' => NULL,
        'endpoint' => NULL,
      ),
      'uploads' => 
      array (
        'driver' => 'local',
        'root' => '/var/www/html/storage/uploads',
      ),
      'jobs' => 
      array (
        'driver' => 'local',
        'root' => '/var/www/html/storage/jobs',
      ),
      'import-jobs' => 
      array (
        'driver' => 'local',
        'root' => '/var/www/html/storage/import-jobs',
      ),
      'conversion-routines' => 
      array (
        'driver' => 'local',
        'root' => '/var/www/html/storage/conversion-routines',
      ),
      'submission-routines' => 
      array (
        'driver' => 'local',
        'root' => '/var/www/html/storage/submission-routines',
      ),
      'configurations' => 
      array (
        'driver' => 'local',
        'root' => '/var/www/html/storage/configurations',
      ),
    ),
    'links' => 
    array (
      '/var/www/html/public/storage' => '/var/www/html/storage/app/public',
    ),
    'cloud' => 's3',
  ),
  'hashing' => 
  array (
    'driver' => 'bcrypt',
    'bcrypt' => 
    array (
      'rounds' => '4',
    ),
    'argon' => 
    array (
      'memory' => 1024,
      'threads' => 2,
      'time' => 2,
    ),
    'rehash_on_login' => true,
  ),
  'ide-helper' => 
  array (
    'filename' => '_ide_helper',
    'models_filename' => '_ide_helper_models.php',
    'meta_filename' => '.phpstorm.meta.php',
    'include_fluent' => false,
    'include_factory_builders' => false,
    'write_model_magic_where' => true,
    'write_model_external_builder_methods' => true,
    'write_model_relation_count_properties' => true,
    'write_model_relation_exists_properties' => false,
    'write_eloquent_model_mixins' => false,
    'include_helpers' => false,
    'helper_files' => 
    array (
      0 => '/var/www/html/vendor/laravel/framework/src/Illuminate/Support/helpers.php',
    ),
    'model_locations' => 
    array (
      0 => 'app',
    ),
    'ignored_models' => 
    array (
    ),
    'model_hooks' => 
    array (
    ),
    'extra' => 
    array (
      'Eloquent' => 
      array (
        0 => 'Illuminate\\Database\\Eloquent\\Builder',
        1 => 'Illuminate\\Database\\Query\\Builder',
      ),
      'Session' => 
      array (
        0 => 'Illuminate\\Session\\Store',
      ),
    ),
    'magic' => 
    array (
    ),
    'interfaces' => 
    array (
    ),
    'model_camel_case_properties' => false,
    'type_overrides' => 
    array (
      'integer' => 'int',
      'boolean' => 'bool',
    ),
    'include_class_docblocks' => false,
    'force_fqn' => false,
    'use_generics_annotations' => true,
    'macro_default_return_types' => 
    array (
      'Illuminate\\Http\\Client\\Factory' => 'Illuminate\\Http\\Client\\PendingRequest',
    ),
    'additional_relation_types' => 
    array (
    ),
    'additional_relation_return_types' => 
    array (
    ),
    'enforce_nullable_relationships' => true,
    'post_migrate' => 
    array (
    ),
    'format' => 'php',
    'custom_db_types' => 
    array (
    ),
  ),
  'importer' => 
  array (
    'version' => '2.0.5',
    'build_time' => 1768581519,
    'fake_data' => false,
    'providers' => 
    array (
      'file' => 
      array (
        'title' => 'File',
        'explanation' => 'CSV or CAMT.* files',
        'enabled' => true,
        'conversion_before_mapping' => false,
        'supports_new_accounts' => false,
      ),
      'sophtron' => 
      array (
        'title' => 'Sophtron',
        'enabled' => true,
        'conversion_before_mapping' => true,
        'explanation' => '',
        'supports_new_accounts' => true,
      ),
      'nordigen' => 
      array (
        'title' => 'GoCardless',
        'enabled' => true,
        'conversion_before_mapping' => true,
        'explanation' => '',
        'supports_new_accounts' => true,
      ),
      'simplefin' => 
      array (
        'title' => 'SimpleFIN',
        'enabled' => true,
        'conversion_before_mapping' => true,
        'explanation' => '',
        'supports_new_accounts' => true,
      ),
      'lunchflow' => 
      array (
        'title' => 'Lunch Flow',
        'enabled' => true,
        'conversion_before_mapping' => true,
        'explanation' => '',
        'supports_new_accounts' => true,
      ),
      'basisbank' => 
      array (
        'title' => 'BasisBank',
        'enabled' => true,
        'conversion_before_mapping' => true,
        'explanation' => '',
        'supports_new_accounts' => true,
      ),
      'tbank' => 
      array (
        'title' => 'TBank',
        'enabled' => true,
        'conversion_before_mapping' => true,
        'explanation' => '',
        'supports_new_accounts' => true,
      ),
      'trc20' => 
      array (
        'title' => 'TRC-20',
        'enabled' => true,
        'conversion_before_mapping' => true,
        'explanation' => '',
        'supports_new_accounts' => true,
      ),
      'obg' => 
      array (
        'title' => 'Open Banking Gateway',
        'enabled' => false,
        'conversion_before_mapping' => true,
        'explanation' => '',
        'supports_new_accounts' => true,
      ),
      'eb' => 
      array (
        'title' => 'Enable Banking',
        'enabled' => false,
        'conversion_before_mapping' => true,
        'explanation' => '',
        'supports_new_accounts' => true,
      ),
      'teller' => 
      array (
        'title' => 'teller.io',
        'enabled' => false,
        'conversion_before_mapping' => true,
        'explanation' => '',
        'supports_new_accounts' => true,
      ),
      'fints' => 
      array (
        'title' => 'FinTS/HBCI',
        'enabled' => false,
        'conversion_before_mapping' => false,
        'explanation' => '',
        'supports_new_accounts' => true,
      ),
      'basiq' => 
      array (
        'title' => 'basiq.io',
        'enabled' => false,
        'conversion_before_mapping' => true,
        'explanation' => '',
        'supports_new_accounts' => true,
      ),
    ),
    'docker' => 
    array (
      'is_docker' => false,
      'base_build' => '366',
    ),
    'fallback_in_dir' => false,
    'fallback_configuration' => '_fallback.json',
    'import_dir_allowlist' => 
    array (
      0 => '',
    ),
    'auto_import_secret' => '',
    'can_post_autoimport' => false,
    'can_post_files' => false,
    'access_token' => '',
    'url' => 'http://app:8080',
    'client_id' => '',
    'upload_path' => '/var/www/html/storage/uploads',
    'log_return_json' => false,
    'expect_secure_url' => false,
    'is_external' => false,
    'ignore_duplicate_errors' => false,
    'ignore_not_found_transactions' => false,
    'namespace' => 'c40dcba2-411d-11ec-973a-0242ac130003',
    'use_cache' => false,
    'minimum_version' => '6.4.14',
    'cache_api_calls' => false,
    'ignored_files' => 
    array (
      0 => '.gitignore',
    ),
    'tracker_site_id' => '',
    'tracker_url' => '',
    'vanity_url' => 'http://localhost:9999',
    'connection' => 
    array (
      'verify' => true,
      'timeout' => 31.415,
    ),
    'trusted_proxies' => '**',
    'encoding' => 
    array (
      0 => 'Quoted-Printable',
      1 => '7bit',
      2 => '8bit',
      3 => 'UCS-4',
      4 => 'UCS-4BE',
      5 => 'UCS-4LE',
      6 => 'UCS-2',
      7 => 'UCS-2BE',
      8 => 'UCS-2LE',
      9 => 'UTF-32',
      10 => 'UTF-32BE',
      11 => 'UTF-32LE',
      12 => 'UTF-16',
      13 => 'UTF-16BE',
      14 => 'UTF-16LE',
      15 => 'UTF-8',
      16 => 'UTF-7',
      17 => 'UTF7-IMAP',
      18 => 'ASCII',
      19 => 'Windows-1252',
      20 => 'Windows-1254',
      21 => 'ISO-8859-1',
      22 => 'ISO-8859-2',
      23 => 'ISO-8859-3',
      24 => 'ISO-8859-4',
      25 => 'ISO-8859-5',
      26 => 'ISO-8859-6',
      27 => 'ISO-8859-7',
      28 => 'ISO-8859-8',
      29 => 'ISO-8859-9',
      30 => 'ISO-8859-10',
      31 => 'ISO-8859-13',
      32 => 'ISO-8859-14',
      33 => 'ISO-8859-15',
      34 => 'ISO-8859-16',
      35 => 'Windows-1251',
    ),
    'line_a' => 'Everything precious is fragile',
    'line_b' => 'Forgive yourself for not being at peace.',
    'line_c' => 'Doesnt look like anything to me.',
    'line_d' => 'Don’t feel so sorry for yourself. Make do.',
    'line_e' => 'All the decisive blows are struck left-handed.',
    'http_codes' => 
    array (
      0 => 'Unknown Error',
      100 => 'Continue',
      101 => 'Switching Protocols',
      102 => 'Processing',
      103 => 'Checkpoint',
      200 => 'OK',
      201 => 'Created',
      202 => 'Accepted',
      203 => 'Non-Authoritative Information',
      204 => 'No Content',
      205 => 'Reset Content',
      206 => 'Partial Content',
      207 => 'Multi-Status',
      300 => 'Multiple Choices',
      301 => 'Moved Permanently',
      302 => 'Found',
      303 => 'See Other',
      304 => 'Not Modified',
      305 => 'Use Proxy',
      306 => 'Switch Proxy',
      307 => 'Temporary Redirect',
      400 => 'Bad Request',
      401 => 'Unauthorized',
      402 => 'Payment Required',
      403 => 'Forbidden',
      404 => 'Not Found',
      405 => 'Method Not Allowed',
      406 => 'Not Acceptable',
      407 => 'Proxy Authentication Required',
      408 => 'Request Timeout',
      409 => 'Conflict',
      410 => 'Gone',
      411 => 'Length Required',
      412 => 'Precondition Failed',
      413 => 'Request Entity Too Large',
      414 => 'Request-URI Too Long',
      415 => 'Unsupported Media Type',
      416 => 'Requested Range Not Satisfiable',
      417 => 'Expectation Failed',
      418 => 'I\'m a teapot',
      422 => 'Unprocessable Entity',
      423 => 'Locked',
      424 => 'Failed Dependency',
      425 => 'Unordered Collection',
      426 => 'Upgrade Required',
      429 => 'Too Many Requests',
      449 => 'Retry With',
      450 => 'Blocked by Windows Parental Controls',
      500 => 'Internal Server Error',
      501 => 'Not Implemented',
      502 => 'Bad Gateway',
      503 => 'Service Unavailable',
      504 => 'Gateway Timeout',
      505 => 'HTTP Version Not Supported',
      506 => 'Variant Also Negotiates',
      507 => 'Insufficient Storage',
      509 => 'Bandwidth Limit Exceeded',
      510 => 'Not Extended',
    ),
  ),
  'logging' => 
  array (
    'default' => 'stack',
    'deprecations' => 
    array (
      'channel' => 'null',
      'trace' => false,
    ),
    'channels' => 
    array (
      'stack' => 
      array (
        'driver' => 'stack',
        'channels' => 
        array (
          0 => 'daily',
          1 => 'stdout',
        ),
        'ignore_exceptions' => false,
        'level' => 'debug',
      ),
      'single' => 
      array (
        'driver' => 'single',
        'path' => '/var/www/html/storage/logs/laravel.log',
        'level' => 'debug',
      ),
      'daily' => 
      array (
        'driver' => 'daily',
        'path' => '/var/www/html/storage/logs/data-import.log',
        'level' => 'debug',
        'days' => 14,
      ),
      'slack' => 
      array (
        'driver' => 'slack',
        'url' => NULL,
        'username' => 'Laravel Log',
        'emoji' => ':boom:',
        'level' => 'critical',
      ),
      'papertrail' => 
      array (
        'driver' => 'monolog',
        'level' => 'debug',
        'handler' => 'Monolog\\Handler\\SyslogUdpHandler',
        'handler_with' => 
        array (
          'host' => NULL,
          'port' => NULL,
        ),
      ),
      'stderr' => 
      array (
        'driver' => 'monolog',
        'handler' => 'Monolog\\Handler\\StreamHandler',
        'formatter' => NULL,
        'with' => 
        array (
          'stream' => 'php://stderr',
        ),
      ),
      'syslog' => 
      array (
        'driver' => 'syslog',
        'level' => 'debug',
      ),
      'errorlog' => 
      array (
        'driver' => 'errorlog',
        'level' => 'debug',
      ),
      'null' => 
      array (
        'driver' => 'monolog',
        'handler' => 'Monolog\\Handler\\NullHandler',
      ),
      'emergency' => 
      array (
        'path' => '/var/www/html/storage/logs/laravel.log',
      ),
      'cloud' => 
      array (
        'driver' => 'stack',
        'channels' => 
        array (
          0 => 'daily',
          1 => 'papertrail',
        ),
        'ignore_exceptions' => false,
        'level' => 'debug',
      ),
      'stdout' => 
      array (
        'driver' => 'monolog',
        'handler' => 'Monolog\\Handler\\StreamHandler',
        'level' => 'debug',
        'formatter' => NULL,
        'with' => 
        array (
          'stream' => 'php://stdout',
        ),
      ),
    ),
    'level' => 'debug',
  ),
  'lunchflow' => 
  array (
    'api_key' => '',
    'unique_column_options' => 
    array (
      'external-id' => 'External identifier',
    ),
    'api_url' => 'https://lunchflow.app/api/v1/',
  ),
  'mail' => 
  array (
    'default' => '',
    'mailers' => 
    array (
      'smtp' => 
      array (
        'transport' => 'smtp',
        'host' => 'smtp.mailgun.org',
        'port' => 587,
        'encryption' => 'tls',
        'username' => NULL,
        'password' => NULL,
        'timeout' => NULL,
        'verify_peer' => false,
      ),
      'ses' => 
      array (
        'transport' => 'ses',
      ),
      'postmark' => 
      array (
        'transport' => 'postmark',
      ),
      'resend' => 
      array (
        'transport' => 'resend',
      ),
      'sendmail' => 
      array (
        'transport' => 'sendmail',
        'path' => '/usr/sbin/sendmail -bs',
      ),
      'log' => 
      array (
        'transport' => 'log',
        'channel' => NULL,
      ),
      'array' => 
      array (
        'transport' => 'array',
      ),
      'failover' => 
      array (
        'transport' => 'failover',
        'mailers' => 
        array (
          0 => 'smtp',
          1 => 'log',
        ),
        'retry_after' => 60,
      ),
      'roundrobin' => 
      array (
        'transport' => 'roundrobin',
        'mailers' => 
        array (
          0 => 'ses',
          1 => 'postmark',
        ),
        'retry_after' => 60,
      ),
      'mailgun' => 
      array (
        'transport' => 'mailgun',
      ),
    ),
    'from' => 
    array (
      'address' => 'hello@example.com',
      'name' => 'Firefly III Data Importer',
    ),
    'markdown' => 
    array (
      'theme' => 'default',
      'paths' => 
      array (
        0 => '/var/www/html/resources/views/vendor/mail',
      ),
    ),
    'destination' => '',
    'enable_mail_report' => false,
  ),
  'nordigen' => 
  array (
    'id' => '',
    'key' => '',
    'url' => 'https://bankaccountdata.gocardless.com',
    'use_sandbox' => false,
    'respect_rate_limit' => true,
    'exit_for_rate_limit' => false,
    'get_account_details' => false,
    'get_balance_details' => false,
    'unique_column_options' => 
    array (
      'external-id' => 'External identifier',
      'additional-information' => 'Additional information',
    ),
    'countries' => 
    array (
      'AF' => 'Afghanistan',
      'AX' => 'Åland Islands',
      'AL' => 'Albania',
      'DZ' => 'Algeria',
      'AS' => 'American Samoa',
      'AD' => 'Andorra',
      'AO' => 'Angola',
      'AI' => 'Anguilla',
      'AQ' => 'Antarctica',
      'AG' => 'Antigua and Barbuda',
      'AR' => 'Argentina',
      'AM' => 'Armenia',
      'AW' => 'Aruba',
      'AU' => 'Australia',
      'AT' => 'Austria',
      'AZ' => 'Azerbaijan',
      'BS' => 'Bahamas',
      'BH' => 'Bahrain',
      'BD' => 'Bangladesh',
      'BB' => 'Barbados',
      'BY' => 'Belarus',
      'BE' => 'Belgium',
      'BZ' => 'Belize',
      'BJ' => 'Benin',
      'BM' => 'Bermuda',
      'BT' => 'Bhutan',
      'BO' => 'Bolivia, Plurinational State of',
      'BQ' => 'Bonaire, Sint Eustatius and Saba',
      'BA' => 'Bosnia and Herzegovina',
      'BW' => 'Botswana',
      'BV' => 'Bouvet Island',
      'BR' => 'Brazil',
      'IO' => 'British Indian Ocean Territory',
      'BN' => 'Brunei Darussalam',
      'BG' => 'Bulgaria',
      'BF' => 'Burkina Faso',
      'BI' => 'Burundi',
      'KH' => 'Cambodia',
      'CM' => 'Cameroon',
      'CA' => 'Canada',
      'CV' => 'Cape Verde',
      'KY' => 'Cayman Islands',
      'CF' => 'Central African Republic',
      'TD' => 'Chad',
      'CL' => 'Chile',
      'CN' => 'China',
      'CX' => 'Christmas Island',
      'CC' => 'Cocos (Keeling) Islands',
      'CO' => 'Colombia',
      'KM' => 'Comoros',
      'CG' => 'Congo',
      'CD' => 'Congo, the Democratic Republic of the',
      'CK' => 'Cook Islands',
      'CR' => 'Costa Rica',
      'CI' => 'Côte d\'Ivoire',
      'HR' => 'Croatia',
      'CU' => 'Cuba',
      'CW' => 'Curaçao',
      'CY' => 'Cyprus',
      'CZ' => 'Czech Republic',
      'DK' => 'Denmark',
      'DJ' => 'Djibouti',
      'DM' => 'Dominica',
      'DO' => 'Dominican Republic',
      'EC' => 'Ecuador',
      'EG' => 'Egypt',
      'SV' => 'El Salvador',
      'GQ' => 'Equatorial Guinea',
      'ER' => 'Eritrea',
      'EE' => 'Estonia',
      'ET' => 'Ethiopia',
      'FK' => 'Falkland Islands (Malvinas)',
      'FO' => 'Faroe Islands',
      'FJ' => 'Fiji',
      'FI' => 'Finland',
      'FR' => 'France',
      'GF' => 'French Guiana',
      'PF' => 'French Polynesia',
      'TF' => 'French Southern Territories',
      'GA' => 'Gabon',
      'GM' => 'Gambia',
      'GE' => 'Georgia',
      'DE' => 'Germany',
      'GH' => 'Ghana',
      'GI' => 'Gibraltar',
      'GR' => 'Greece',
      'GL' => 'Greenland',
      'GD' => 'Grenada',
      'GP' => 'Guadeloupe',
      'GU' => 'Guam',
      'GT' => 'Guatemala',
      'GG' => 'Guernsey',
      'GN' => 'Guinea',
      'GW' => 'Guinea-Bissau',
      'GY' => 'Guyana',
      'HT' => 'Haiti',
      'HM' => 'Heard Island and McDonald Islands',
      'VA' => 'Holy See (Vatican City State)',
      'HN' => 'Honduras',
      'HK' => 'Hong Kong',
      'HU' => 'Hungary',
      'IS' => 'Iceland',
      'IN' => 'India',
      'ID' => 'Indonesia',
      'IR' => 'Iran, Islamic Republic of',
      'IQ' => 'Iraq',
      'IE' => 'Ireland',
      'IM' => 'Isle of Man',
      'IL' => 'Israel',
      'IT' => 'Italy',
      'JM' => 'Jamaica',
      'JP' => 'Japan',
      'JE' => 'Jersey',
      'JO' => 'Jordan',
      'KZ' => 'Kazakhstan',
      'KE' => 'Kenya',
      'KI' => 'Kiribati',
      'KP' => 'Korea, Democratic People\'s Republic of',
      'KR' => 'Korea, Republic of',
      'KW' => 'Kuwait',
      'KG' => 'Kyrgyzstan',
      'LA' => 'Lao People\'s Democratic Republic',
      'LV' => 'Latvia',
      'LB' => 'Lebanon',
      'LS' => 'Lesotho',
      'LR' => 'Liberia',
      'LY' => 'Libya',
      'LI' => 'Liechtenstein',
      'LT' => 'Lithuania',
      'LU' => 'Luxembourg',
      'MO' => 'Macao',
      'MK' => 'Macedonia, the Former Yugoslav Republic of',
      'MG' => 'Madagascar',
      'MW' => 'Malawi',
      'MY' => 'Malaysia',
      'MV' => 'Maldives',
      'ML' => 'Mali',
      'MT' => 'Malta',
      'MH' => 'Marshall Islands',
      'MQ' => 'Martinique',
      'MR' => 'Mauritania',
      'MU' => 'Mauritius',
      'YT' => 'Mayotte',
      'MX' => 'Mexico',
      'FM' => 'Micronesia, Federated States of',
      'MD' => 'Moldova, Republic of',
      'MC' => 'Monaco',
      'MN' => 'Mongolia',
      'ME' => 'Montenegro',
      'MS' => 'Montserrat',
      'MA' => 'Morocco',
      'MZ' => 'Mozambique',
      'MM' => 'Myanmar',
      'NA' => 'Namibia',
      'NR' => 'Nauru',
      'NP' => 'Nepal',
      'NL' => 'Netherlands',
      'NC' => 'New Caledonia',
      'NZ' => 'New Zealand',
      'NI' => 'Nicaragua',
      'NE' => 'Niger',
      'NG' => 'Nigeria',
      'NU' => 'Niue',
      'NF' => 'Norfolk Island',
      'MP' => 'Northern Mariana Islands',
      'NO' => 'Norway',
      'OM' => 'Oman',
      'PK' => 'Pakistan',
      'PW' => 'Palau',
      'PS' => 'Palestine, State of',
      'PA' => 'Panama',
      'PG' => 'Papua New Guinea',
      'PY' => 'Paraguay',
      'PE' => 'Peru',
      'PH' => 'Philippines',
      'PN' => 'Pitcairn',
      'PL' => 'Poland',
      'PT' => 'Portugal',
      'PR' => 'Puerto Rico',
      'QA' => 'Qatar',
      'RE' => 'Réunion',
      'RO' => 'Romania',
      'RU' => 'Russian Federation',
      'RW' => 'Rwanda',
      'BL' => 'Saint Barthélemy',
      'SH' => 'Saint Helena, Ascension and Tristan da Cunha',
      'KN' => 'Saint Kitts and Nevis',
      'LC' => 'Saint Lucia',
      'MF' => 'Saint Martin (French part)',
      'PM' => 'Saint Pierre and Miquelon',
      'VC' => 'Saint Vincent and the Grenadines',
      'WS' => 'Samoa',
      'SM' => 'San Marino',
      'ST' => 'Sao Tome and Principe',
      'SA' => 'Saudi Arabia',
      'SN' => 'Senegal',
      'RS' => 'Serbia',
      'SC' => 'Seychelles',
      'SL' => 'Sierra Leone',
      'SG' => 'Singapore',
      'SX' => 'Sint Maarten (Dutch part)',
      'SK' => 'Slovakia',
      'SI' => 'Slovenia',
      'SB' => 'Solomon Islands',
      'SO' => 'Somalia',
      'ZA' => 'South Africa',
      'GS' => 'South Georgia and the South Sandwich Islands',
      'SS' => 'South Sudan',
      'ES' => 'Spain',
      'LK' => 'Sri Lanka',
      'SD' => 'Sudan',
      'SR' => 'Suriname',
      'SJ' => 'Svalbard and Jan Mayen',
      'SZ' => 'Swaziland',
      'SE' => 'Sweden',
      'CH' => 'Switzerland',
      'SY' => 'Syrian Arab Republic',
      'TW' => 'Taiwan',
      'TJ' => 'Tajikistan',
      'TZ' => 'Tanzania, United Republic of',
      'TH' => 'Thailand',
      'TL' => 'Timor-Leste',
      'TG' => 'Togo',
      'TK' => 'Tokelau',
      'TO' => 'Tonga',
      'TT' => 'Trinidad and Tobago',
      'TN' => 'Tunisia',
      'TR' => 'Turkey',
      'TM' => 'Turkmenistan',
      'TC' => 'Turks and Caicos Islands',
      'TV' => 'Tuvalu',
      'UG' => 'Uganda',
      'UA' => 'Ukraine',
      'AE' => 'United Arab Emirates',
      'GB' => 'United Kingdom',
      'US' => 'United States',
      'UM' => 'United States Minor Outlying Islands',
      'UY' => 'Uruguay',
      'UZ' => 'Uzbekistan',
      'VU' => 'Vanuatu',
      'VE' => 'Venezuela, Bolivarian Republic of',
      'VN' => 'Viet Nam',
      'VG' => 'Virgin Islands, British',
      'VI' => 'Virgin Islands, U.S.',
      'WF' => 'Wallis and Futuna',
      'EH' => 'Western Sahara',
      'YE' => 'Yemen',
      'ZM' => 'Zambia',
      'ZW' => 'Zimbabwe',
    ),
  ),
  'queue' => 
  array (
    'default' => 'sync',
    'connections' => 
    array (
      'sync' => 
      array (
        'driver' => 'sync',
      ),
      'database' => 
      array (
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
      ),
      'beanstalkd' => 
      array (
        'driver' => 'beanstalkd',
        'host' => 'localhost',
        'queue' => 'default',
        'retry_after' => 90,
        'block_for' => 0,
      ),
      'sqs' => 
      array (
        'driver' => 'sqs',
        'key' => NULL,
        'secret' => NULL,
        'prefix' => 'https://sqs.us-east-1.amazonaws.com/your-account-id',
        'queue' => 'your-queue-name',
        'suffix' => NULL,
        'region' => 'us-east-1',
      ),
      'redis' => 
      array (
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => 'default',
        'retry_after' => 90,
        'block_for' => NULL,
      ),
      'deferred' => 
      array (
        'driver' => 'deferred',
      ),
      'failover' => 
      array (
        'driver' => 'failover',
        'connections' => 
        array (
          0 => 'database',
          1 => 'deferred',
        ),
      ),
    ),
    'batching' => 
    array (
      'database' => 'sqlite',
      'table' => 'job_batches',
    ),
    'failed' => 
    array (
      'driver' => 'database',
      'database' => 'sqlite',
      'table' => 'failed_jobs',
    ),
  ),
  'services' => 
  array (
    'postmark' => 
    array (
      'token' => NULL,
    ),
    'resend' => 
    array (
      'key' => NULL,
    ),
    'ses' => 
    array (
      'key' => NULL,
      'secret' => NULL,
      'region' => 'us-east-1',
    ),
    'slack' => 
    array (
      'notifications' => 
      array (
        'bot_user_oauth_token' => NULL,
        'channel' => NULL,
      ),
    ),
    'mailgun' => 
    array (
      'domain' => NULL,
      'secret' => NULL,
      'endpoint' => 'api.mailgun.net',
    ),
  ),
  'session' => 
  array (
    'driver' => 'array',
    'lifetime' => 120,
    'expire_on_close' => false,
    'encrypt' => false,
    'files' => '/var/www/html/storage/framework/sessions',
    'connection' => NULL,
    'table' => 'sessions',
    'store' => NULL,
    'lottery' => 
    array (
      0 => 2,
      1 => 100,
    ),
    'cookie' => 'data_session',
    'path' => '/',
    'domain' => NULL,
    'secure' => NULL,
    'http_only' => true,
    'same_site' => 'lax',
    'partitioned' => false,
  ),
  'simplefin' => 
  array (
    'token' => '',
    'origin_url' => '',
    'demo_url' => 'https://bridge.simplefin.org/simplefin/create',
    'demo_token' => 'aHR0cHM6Ly9iZXRhLWJyaWRnZS5zaW1wbGVmaW4ub3JnL3NpbXBsZWZpbi9jbGFpbS9ERU1P',
    'connection_timeout' => 30,
    'request_timeout' => 60,
    'unique_column_options' => 
    array (
      'external-id' => 'External identifier',
    ),
    'account_types' => 
    array (
      'checking' => 'asset',
      'savings' => 'asset',
      'credit' => 'debt',
      'loan' => 'loan',
      'mortgage' => 'mortgage',
      'investment' => 'asset',
    ),
    'max_transactions' => 10000,
    'default_date_range' => 90,
    'enable_caching' => true,
    'cache_duration' => 3600,
    'smart_expense_matching' => true,
    'expense_matching_threshold' => 0.7,
    'auto_create_expense_accounts' => true,
    'enable_transaction_clustering' => true,
    'clustering_similarity_threshold' => 0.7,
    'retry_attempts' => 3,
    'retry_delay' => 1,
  ),
  'sophtron' => 
  array (
    'url' => 'https://api.sophtron.com',
    'user_id' => '',
    'access_key' => '',
    'unique_column_options' => 
    array (
      'external-id' => 'External identifier',
    ),
  ),
  'spectre' => 
  array (
    'customer_identifier' => 'default_ff3_customer',
    'app_id' => '',
    'secret' => '',
    'url' => 'https://www.saltedge.com/api/v6',
    'unique_column_options' => 
    array (
      'external-id' => 'External identifier',
    ),
  ),
  'tbank' => 
  array (
    'api_token' => '',
    'api_url' => 'https://business.tinkoff.ru/openapi/api/',
    'accounts_endpoint' => 'v4/bank-accounts',
    'transactions_endpoint' => 'v1/bank-statement',
    'unique_column_options' => 
    array (
      'external-id' => 'External identifier',
    ),
  ),
  'transaction_types' => 
  array (
    'account_to_transaction' => 
    array (
      'asset' => 
      array (
        'asset' => 'transfer',
        'cash' => 'withdrawal',
        'debt' => 'withdrawal',
        'expense' => 'withdrawal',
        'initial balance' => 'opening balance',
        'loan' => 'withdrawal',
        'mortgage' => 'withdrawal',
        'liabilities' => 'withdrawal',
        'reconciliation' => 'reconciliation',
      ),
      'cash' => 
      array (
        'asset' => 'deposit',
      ),
      'debt' => 
      array (
        'asset' => 'deposit',
        'expense' => 'withdrawal',
        'initial balance' => 'opening balance',
        'debt' => 'transfer',
        'loan' => 'transfer',
        'mortgage' => 'transfer',
        'liabilities' => 'transfer',
      ),
      'liabilities' => 
      array (
        'asset' => 'deposit',
        'expense' => 'withdrawal',
        'initial balance' => 'opening balance',
        'debt' => 'transfer',
        'loan' => 'transfer',
        'mortgage' => 'transfer',
        'liabilities' => 'transfer',
      ),
      'initial balance' => 
      array (
        'asset' => 'opening balance',
        'debt' => 'opening balance',
        'loan' => 'opening balance',
        'mortgage' => 'opening balance',
        'liabilities' => 'opening balance',
      ),
      'loan' => 
      array (
        'asset' => 'deposit',
        'expense' => 'withdrawal',
        'initial balance' => 'opening balance',
        'debt' => 'transfer',
        'loan' => 'transfer',
        'mortgage' => 'transfer',
        'liabilities' => 'transfer',
      ),
      'mortgage' => 
      array (
        'asset' => 'deposit',
        'expense' => 'withdrawal',
        'initial balance' => 'opening balance',
        'debt' => 'transfer',
        'loan' => 'transfer',
        'mortgage' => 'transfer',
        'liabilities' => 'transfer',
      ),
      'reconciliation' => 
      array (
        'asset' => 'reconciliation',
      ),
      'revenue' => 
      array (
        'asset' => 'deposit',
        'debt' => 'deposit',
        'loan' => 'deposit',
        'mortgage' => 'deposit',
        'liabilities' => 'deposit',
      ),
    ),
  ),
  'trc20' => 
  array (
    'api_key' => '',
    'api_url' => 'https://apilist.tronscanapi.com/api/',
    'wallets_endpoint' => 'api/account',
    'transactions_endpoint' => 'api/transfer',
    'request_timeout' => 30,
    'page_size' => 100,
    'max_pages' => 100,
    'wallets' => '',
  ),
  'trustedproxy' => 
  array (
    'proxies' => '**',
  ),
  'view' => 
  array (
    'paths' => 
    array (
      0 => '/var/www/html/resources/views/v2',
    ),
    'compiled' => '/var/www/html/storage/framework/views',
    'layout' => 'v2',
  ),
  'debugbar' => 
  array (
    'enabled' => NULL,
    'hide_empty_tabs' => true,
    'except' => 
    array (
      0 => 'telescope*',
      1 => 'horizon*',
      2 => '_boost/browser-logs',
    ),
    'storage' => 
    array (
      'enabled' => true,
      'open' => NULL,
      'driver' => 'file',
      'path' => '/var/www/html/storage/debugbar',
      'connection' => NULL,
      'provider' => '',
      'hostname' => '127.0.0.1',
      'port' => 2304,
    ),
    'editor' => 'phpstorm',
    'remote_sites_path' => NULL,
    'local_sites_path' => NULL,
    'include_vendors' => true,
    'capture_ajax' => true,
    'add_ajax_timing' => false,
    'ajax_handler_auto_show' => true,
    'ajax_handler_enable_tab' => true,
    'defer_datasets' => false,
    'error_handler' => false,
    'error_level' => 30719,
    'clockwork' => false,
    'collectors' => 
    array (
      'phpinfo' => false,
      'messages' => true,
      'time' => true,
      'memory' => true,
      'exceptions' => true,
      'log' => true,
      'db' => true,
      'views' => true,
      'route' => false,
      'auth' => false,
      'gate' => true,
      'session' => false,
      'symfony_request' => true,
      'mail' => true,
      'laravel' => true,
      'events' => false,
      'default_request' => false,
      'logs' => false,
      'files' => false,
      'config' => false,
      'cache' => false,
      'models' => true,
      'livewire' => true,
      'jobs' => false,
      'pennant' => false,
    ),
    'options' => 
    array (
      'time' => 
      array (
        'memory_usage' => false,
      ),
      'messages' => 
      array (
        'trace' => true,
        'capture_dumps' => false,
      ),
      'memory' => 
      array (
        'reset_peak' => false,
        'with_baseline' => false,
        'precision' => 0,
      ),
      'auth' => 
      array (
        'show_name' => true,
        'show_guards' => true,
      ),
      'gate' => 
      array (
        'trace' => false,
      ),
      'db' => 
      array (
        'with_params' => true,
        'exclude_paths' => 
        array (
        ),
        'backtrace' => true,
        'backtrace_exclude_paths' => 
        array (
        ),
        'timeline' => false,
        'duration_background' => true,
        'explain' => 
        array (
          'enabled' => false,
        ),
        'hints' => false,
        'show_copy' => true,
        'only_slow_queries' => true,
        'slow_threshold' => false,
        'memory_usage' => false,
        'soft_limit' => 100,
        'hard_limit' => 500,
      ),
      'mail' => 
      array (
        'timeline' => true,
        'show_body' => true,
      ),
      'views' => 
      array (
        'timeline' => true,
        'data' => false,
        'group' => 50,
        'inertia_pages' => 'js/Pages',
        'exclude_paths' => 
        array (
          0 => 'vendor/filament',
        ),
      ),
      'route' => 
      array (
        'label' => true,
      ),
      'session' => 
      array (
        'hiddens' => 
        array (
        ),
      ),
      'symfony_request' => 
      array (
        'label' => true,
        'hiddens' => 
        array (
        ),
      ),
      'events' => 
      array (
        'data' => false,
        'excluded' => 
        array (
        ),
      ),
      'logs' => 
      array (
        'file' => NULL,
      ),
      'cache' => 
      array (
        'values' => true,
      ),
    ),
    'inject' => true,
    'route_prefix' => '_debugbar',
    'route_middleware' => 
    array (
    ),
    'route_domain' => NULL,
    'theme' => 'auto',
    'debug_backtrace_limit' => 50,
  ),
);
