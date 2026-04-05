<?php

declare(strict_types=1);

namespace App\Support;

final class TestCase
{
    public static function assertTrue(bool $condition, string $message = 'Expected condition to be true'): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $detail = $message !== '' ? $message : sprintf('Expected %s, got %s', var_export($expected, true), var_export($actual, true));
            throw new \RuntimeException($detail);
        }
    }

    public static function assertArrayHasKey(string|int $key, array $array, string $message = ''): void
    {
        if (!array_key_exists($key, $array)) {
            throw new \RuntimeException($message !== '' ? $message : "Missing array key {$key}");
        }
    }
}
