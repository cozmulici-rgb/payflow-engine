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
        'transaction_event_topic' => 'transaction.events',
        'idempotency_ttl_seconds' => 86400,
        'processor_timeout_retries' => 1,
        'default_processor' => 'processor_a',
    ],
    'fraud' => [
        'high_risk_channels' => ['fraud'],
        'default_action' => 'approve',
    ],
    'fx' => [
        'lock_ttl_seconds' => 1800,
        'default_rates' => [
            'CAD:USD' => '0.74000000',
            'USD:CAD' => '1.35000000',
        ],
    ],
    'settlement' => [
        'artifact_topic' => 'settlement.events',
        'artifact_disk' => 'storage/settlement_artifacts',
        'failure_processors' => [],
        'fail_artifact_writes' => false,
    ],
];
