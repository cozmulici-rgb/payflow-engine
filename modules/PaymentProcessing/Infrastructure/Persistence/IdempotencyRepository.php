<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Infrastructure\Persistence;

final class IdempotencyRepository
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * @return array{status:int,body:array<string,mixed>}|null
     */
    public function findResponseByKey(string $key): ?array
    {
        foreach ($this->readAll() as $item) {
            if (($item['scope_key'] ?? null) !== $key) {
                continue;
            }

            if (isset($item['expires_at']) && strtotime((string) $item['expires_at']) < time()) {
                return null;
            }

            $response = $item['response'] ?? null;
            if (!is_array($response)) {
                return null;
            }

            return [
                'status' => (int) ($response['status'] ?? 202),
                'body' => is_array($response['body'] ?? null) ? $response['body'] : [],
            ];
        }

        return null;
    }

    /**
     * @param array{status:int,body:array<string,mixed>} $response
     */
    public function storeAcceptedResponse(string $key, array $response, \DateTimeImmutable $expiresAt): void
    {
        $items = array_values(array_filter(
            $this->readAll(),
            static fn (array $item): bool => ($item['scope_key'] ?? null) !== $key
        ));

        $items[] = [
            'id' => $this->uuid(),
            'scope_key' => $key,
            'response' => $response,
            'expires_at' => $expiresAt->format(DATE_ATOM),
            'created_at' => gmdate(DATE_ATOM),
        ];

        file_put_contents($this->path, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
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
