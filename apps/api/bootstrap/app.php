<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'App\\' => __DIR__ . '/../src/',
        'Modules\\' => __DIR__ . '/../../../modules/',
        'Database\\' => __DIR__ . '/../database/',
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

// Minimal env() shim for when Laravel's helpers are not yet loaded (test harness,
// local dev without vendor). The real Laravel env() takes precedence if defined.
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return $value !== false ? $value : $default;
    }
}

require __DIR__ . '/../routes/api.php';
require __DIR__ . '/../routes/console.php';

function bootstrap_app(string $basePath): App\Support\Application
{
    $storagePath = $basePath . '/storage';
    if (!is_dir($storagePath)) {
        mkdir($storagePath, 0750, true);
    }

    return new App\Support\Application($basePath, $storagePath, api_routes());
}
