<?php

declare(strict_types=1);

namespace Modules\Shared\Infrastructure\Persistence;

final class WebhookDeliveryRepository
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * @param array<string,mixed> $delivery
     */
    public function append(array $delivery): void
    {
        $rows = $this->readAll();
        $rows[] = $delivery;
        file_put_contents($this->path, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function readAll(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);
        return is_array($decoded) ? $decoded : [];
    }
}
