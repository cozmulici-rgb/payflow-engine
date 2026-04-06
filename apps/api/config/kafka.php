<?php

declare(strict_types=1);

return [

    'brokers' => env('KAFKA_BROKERS', 'localhost:9092'),

    'security' => [
        'protocol'   => env('KAFKA_SECURITY_PROTOCOL', 'PLAINTEXT'),
        'mechanisms' => env('KAFKA_SASL_MECHANISMS', ''),
        'username'   => env('KAFKA_SASL_USERNAME', ''),
        'password'   => env('KAFKA_SASL_PASSWORD', ''),
    ],

    'consumer' => [
        'group_id'          => env('KAFKA_CONSUMER_GROUP_ID', 'payflow-api'),
        'auto_offset_reset' => env('KAFKA_AUTO_OFFSET_RESET', 'latest'),
        'session_timeout_ms'  => 45000,
        'max_poll_interval_ms' => 300000,
    ],

    'producer' => [
        'acks'           => 'all',
        'retries'        => 3,
        'linger_ms'      => 5,
        'compression'    => 'snappy',
        'message_max_bytes' => 1048576,
    ],

    'topics' => [
        'transaction_commands' => env('KAFKA_TOPIC_TRANSACTION_COMMANDS', 'transaction.processing'),
        'transaction_events'   => env('KAFKA_TOPIC_TRANSACTION_EVENTS', 'transaction.events'),
        'settlement_events'    => env('KAFKA_TOPIC_SETTLEMENT_EVENTS', 'settlement.events'),
        'webhook_events'       => env('KAFKA_TOPIC_WEBHOOK_EVENTS', 'webhook.events'),
        'audit_events'         => env('KAFKA_TOPIC_AUDIT_EVENTS', 'audit.events'),
    ],

];
