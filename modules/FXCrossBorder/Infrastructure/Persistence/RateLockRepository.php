<?php

declare(strict_types=1);

namespace Modules\FXCrossBorder\Infrastructure\Persistence;

final class RateLockRepository
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function create(array $payload): array
    {
        $locks = $this->readAll();
        $record = array_merge($payload, [
            'id' => $this->uuid(),
            'created_at' => gmdate(DATE_ATOM),
            'used_at' => null,
        ]);
        $locks[] = $record;
        file_put_contents($this->path, json_encode($locks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

        return $record;
    }

    public function markUsed(string $rateLockId): void
    {
        $locks = $this->readAll();

        foreach ($locks as $index => $lock) {
            if (($lock['id'] ?? null) !== $rateLockId) {
                continue;
            }

            $locks[$index]['used_at'] = gmdate(DATE_ATOM);
            file_put_contents($this->path, json_encode($locks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
            return;
        }

        throw new \RuntimeException(sprintf('Rate lock [%s] not found', $rateLockId));
    }

    private function readAll(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }
}
