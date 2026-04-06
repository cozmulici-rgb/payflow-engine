<?php

declare(strict_types=1);

return [

    'app_name' => env('APP_NAME', 'Payflow Engine'),

    'operator' => [
        'secret' => env('OPERATOR_SECRET', 'op-secret-change-me'),
    ],

    'payment_processing' => [
        'transaction_command_topic' => env('KAFKA_TOPIC_TRANSACTION_COMMANDS', 'transaction.processing'),
        'transaction_event_topic'   => env('KAFKA_TOPIC_TRANSACTION_EVENTS', 'transaction.events'),
        'idempotency_ttl_seconds'   => (int) env('PAYMENT_IDEMPOTENCY_TTL', 86400),
        'processor_timeout_retries' => (int) env('PAYMENT_PROCESSOR_TIMEOUT_RETRIES', 1),
        'default_processor'         => env('PAYMENT_DEFAULT_PROCESSOR', 'processor_a'),
    ],

    'fraud' => [
        'high_risk_channels' => ['fraud'],
        'default_action'     => 'approve',
    ],

    'fx' => [
        'lock_ttl_seconds' => (int) env('FX_LOCK_TTL_SECONDS', 1800),
        'default_rates'    => [
            'CAD:USD' => '0.74000000',
            'USD:CAD' => '1.35000000',
        ],
    ],

    'settlement' => [
        'artifact_topic'      => env('KAFKA_TOPIC_SETTLEMENT_EVENTS', 'settlement.events'),
        // artifact_disk is a path relative to basePath used by the local test harness;
        // production overrides this via the filesystem driver (S3) configured in config/filesystems.php.
        'artifact_disk'       => env('SETTLEMENT_ARTIFACT_PATH', 'storage/settlement_artifacts'),
        'artifact_s3_bucket'  => env('SETTLEMENT_ARTIFACT_BUCKET', 'payflow-settlement-artifacts'),
        'failure_processors'  => [],
        'fail_artifact_writes' => false,
    ],

];
