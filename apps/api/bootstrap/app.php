<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'App\\' => __DIR__ . '/../src/',
        'Modules\\' => __DIR__ . '/../../../modules/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $path = $baseDir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

require __DIR__ . '/../routes/api.php';
require __DIR__ . '/../routes/console.php';

function bootstrap_app(string $basePath): App\Support\Application
{
    $storagePath = $basePath . '/storage';
    if (!is_dir($storagePath)) {
        mkdir($storagePath, 0777, true);
    }

    return new App\Support\Application($basePath, $storagePath, api_routes());
}
