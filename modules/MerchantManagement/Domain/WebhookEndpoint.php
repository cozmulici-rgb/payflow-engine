<?php

declare(strict_types=1);

namespace Modules\MerchantManagement\Domain;

final class WebhookEndpoint
{
    /**
     * @param list<string> $eventTypes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $merchantId,
        public readonly string $url,
        public readonly string $signingSecret,
        public readonly array $eventTypes,
        public readonly string $status,
        public readonly string $createdAt
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchantId,
            'url' => $this->url,
            'signing_secret' => $this->signingSecret,
            'event_types' => $this->eventTypes,
            'status' => $this->status,
            'created_at' => $this->createdAt,
        ];
    }
}
