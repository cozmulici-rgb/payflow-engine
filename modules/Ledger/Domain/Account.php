<?php

declare(strict_types=1);

namespace Modules\Ledger\Domain;

final class Account
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
        public readonly string $type,
        public readonly string $normalBalance,
        public readonly ?string $currency,
        public readonly string $createdAt,
        public readonly string $updatedAt
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'normal_balance' => $this->normalBalance,
            'currency' => $this->currency,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            id: (string) $payload['id'],
            code: (string) $payload['code'],
            name: (string) $payload['name'],
            type: (string) $payload['type'],
            normalBalance: (string) $payload['normal_balance'],
            currency: isset($payload['currency']) ? (string) $payload['currency'] : null,
            createdAt: (string) $payload['created_at'],
            updatedAt: (string) $payload['updated_at']
        );
    }
}
