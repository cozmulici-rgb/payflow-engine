<?php

declare(strict_types=1);

return [
    'app_name' => 'Payflow Engine',
    'modules' => [
        'MerchantManagement',
        'PaymentProcessing',
        'Audit',
        'Shared',
    ],
    'payment_processing' => [
        'transaction_command_topic' => 'transaction.processing',
        'idempotency_ttl_seconds' => 86400,
    ],
];
