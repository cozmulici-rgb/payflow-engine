<?php

declare(strict_types=1);

return [

    'default' => env('QUEUE_CONNECTION', 'kafka'),

    'connections' => [

        'kafka' => [
            'driver'   => 'kafka',
            'queue'    => env('KAFKA_TOPIC_TRANSACTION_COMMANDS', 'transaction.processing'),
            'brokers'  => env('KAFKA_BROKERS', 'localhost:9092'),
            'group_id' => env('KAFKA_CONSUMER_GROUP_ID', 'payflow-api'),
            'sleep_on_error' => 5,
        ],

        'redis' => [
            'driver'     => 'redis',
            'connection' => 'default',
            'queue'      => '{default}',
            'retry_after' => 90,
            'block_for'  => null,
        ],

        'sync' => [
            'driver' => 'sync',
        ],

    ],

    'failed' => [
        'driver'   => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table'    => 'failed_jobs',
    ],

];
