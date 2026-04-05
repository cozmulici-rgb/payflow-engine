<?php

declare(strict_types=1);

namespace Modules\MerchantManagement\Domain;

final class Merchant
{
    public function __construct(
        public readonly string $id,
        public readonly string $legalName,
        public readonly string $displayName,
        public readonly string $country,
        public readonly string $defaultCurrency,
        public readonly string $status,
        public readonly string $createdAt,
        /** @var array<int,array<string,string>> */
        public readonly array $credentials = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'legal_name' => $this->legalName,
            'display_name' => $this->displayName,
            'country' => $this->country,
            'default_currency' => $this->defaultCurrency,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'credentials' => $this->credentials,
        ];
    }
}
