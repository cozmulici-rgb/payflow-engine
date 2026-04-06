<?php

declare(strict_types=1);

namespace Modules\MerchantManagement\Infrastructure\Persistence;

use Modules\MerchantManagement\Domain\WebhookEndpoint;

final class WebhookEndpointRepository
{
    public function __construct(private readonly string $path)
    {
    }

    public function save(WebhookEndpoint $endpoint): void
    {
        $rows = $this->readAll();
        $rows[] = $endpoint->toArray();
        $this->writeAll($rows);
    }

    /**
     * @return list<WebhookEndpoint>
     */
    public function findActiveByMerchantId(string $merchantId): array
    {
        $matches = [];

        foreach ($this->readAll() as $row) {
            if (($row['merchant_id'] ?? null) !== $merchantId || ($row['status'] ?? null) !== 'active') {
                continue;
            }

            $matches[] = new WebhookEndpoint(
                (string) $row['id'],
                (string) $row['merchant_id'],
                (string) $row['url'],
                (string) $row['signing_secret'],
                is_array($row['event_types'] ?? null) ? array_values($row['event_types']) : [],
                (string) $row['status'],
                (string) $row['created_at']
            );
        }

        return $matches;
    }

    private function readAll(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeAll(array $rows): void
    {
        file_put_contents($this->path, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
