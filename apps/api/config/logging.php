<?php

declare(strict_types=1);

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace'   => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    'channels' => [

        'stack' => [
            'driver'            => 'stack',
            'channels'          => explode(',', env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path'   => __DIR__ . '/../logs/laravel.log',
            'level'  => env('LOG_LEVEL', 'debug'),
            'days'   => 14,
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path'   => __DIR__ . '/../logs/laravel.log',
            'level'  => env('LOG_LEVEL', 'debug'),
            'days'   => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'stderr' => [
            'driver'    => 'monolog',
            'level'     => env('LOG_LEVEL', 'debug'),
            'handler'   => Monolog\Handler\StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with'      => ['stream' => 'php://stderr'],
            'processors' => [Monolog\Processor\PsrLogMessageProcessor::class],
        ],

        'null' => [
            'driver'  => 'monolog',
            'handler' => Monolog\Handler\NullHandler::class,
        ],

    ],

];
