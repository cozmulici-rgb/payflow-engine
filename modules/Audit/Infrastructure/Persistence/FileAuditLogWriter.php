<?php

declare(strict_types=1);

namespace Modules\Audit\Infrastructure\Persistence;

final class FileAuditLogWriter implements AuditLogWriter
{
    public function __construct(private readonly string $path)
    {
    }

    public function append(array $record): void
    {
        $items = [];
        if (is_file($this->path)) {
            $decoded = json_decode((string) file_get_contents($this->path), true);
            $items = is_array($decoded) ? $decoded : [];
        }

        $items[] = $record;
        file_put_contents($this->path, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
