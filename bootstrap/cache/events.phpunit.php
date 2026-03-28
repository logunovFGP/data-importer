<?php return array (
  'App\\Providers\\EventServiceProvider' => 
  array (
    'Illuminate\\Auth\\Events\\Registered' => 
    array (
      0 => 'Illuminate\\Auth\\Listeners\\SendEmailVerificationNotification',
    ),
    'App\\Events\\ImportedTransactions' => 
    array (
      0 => 'App\\Handlers\\Events\\ImportedTransactionsEventHandler@sendReportOverMail',
    ),
    'App\\Events\\DownloadedSimpleFINAccounts' => 
    array (
      0 => 'App\\Handlers\\Events\\ImportFlowHandler@handleDownloadedSimpleFINAccounts',
    ),
  ),
);