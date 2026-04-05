<?php

declare(strict_types=1);

namespace Modules\Settlement\Domain;

final class SettlementBatch
{
    public function __construct(
        public readonly string $id,
        public readonly string $processorId,
        public readonly string $currency,
        public readonly string $batchDate,
        public readonly string $status,
        public readonly int $itemCount,
        public readonly string $totalAmount,
        public readonly ?string $artifactKey,
        public readonly ?string $submittedAt,
        public readonly ?string $exceptionReason,
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
            'processor_id' => $this->processorId,
            'currency' => $this->currency,
            'batch_date' => $this->batchDate,
            'status' => $this->status,
            'item_count' => $this->itemCount,
            'total_amount' => $this->totalAmount,
            'artifact_key' => $this->artifactKey,
            'submitted_at' => $this->submittedAt,
            'exception_reason' => $this->exceptionReason,
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
            processorId: (string) $payload['processor_id'],
            currency: (string) $payload['currency'],
            batchDate: (string) $payload['batch_date'],
            status: (string) $payload['status'],
            itemCount: (int) $payload['item_count'],
            totalAmount: (string) $payload['total_amount'],
            artifactKey: isset($payload['artifact_key']) ? (string) $payload['artifact_key'] : null,
            submittedAt: isset($payload['submitted_at']) ? (string) $payload['submitted_at'] : null,
            exceptionReason: isset($payload['exception_reason']) ? (string) $payload['exception_reason'] : null,
            createdAt: (string) $payload['created_at'],
            updatedAt: (string) $payload['updated_at']
        );
    }
}
