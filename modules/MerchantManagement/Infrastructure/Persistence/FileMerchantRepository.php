<?php

declare(strict_types=1);

namespace Modules\MerchantManagement\Infrastructure\Persistence;

use Modules\MerchantManagement\Domain\Merchant;

final class FileMerchantRepository
{
    public function __construct(private readonly string $path)
    {
    }

    public function save(Merchant $merchant): void
    {
        $items = $this->readAll();
        $items[] = $merchant->toArray();
        $this->writeAll($items);
    }

    public function find(string $merchantId): ?Merchant
    {
        foreach ($this->readAll() as $item) {
            if (($item['id'] ?? null) === $merchantId) {
                return new Merchant(
                    $item['id'],
                    $item['legal_name'],
                    $item['display_name'],
                    $item['country'],
                    $item['default_currency'],
                    $item['status'],
                    $item['created_at'],
                    $item['credentials'] ?? []
                );
            }
        }

        return null;
    }

    public function addCredential(string $merchantId, string $keyId, string $secretHash): void
    {
        $items = $this->readAll();
        foreach ($items as &$item) {
            if (($item['id'] ?? null) !== $merchantId) {
                continue;
            }

            $item['credentials'][] = [
                'key_id' => $keyId,
                'secret_hash' => $secretHash,
            ];
            $this->writeAll($items);
            return;
        }

        throw new \RuntimeException('Merchant not found');
    }

    private function readAll(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeAll(array $items): void
    {
        file_put_contents($this->path, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
