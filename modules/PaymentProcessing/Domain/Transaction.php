<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Domain;

final class Transaction
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $merchantId,
        public readonly string $idempotencyKey,
        public readonly string $type,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $settlementCurrency,
        public readonly string $paymentMethodType,
        public readonly string $paymentMethodToken,
        public readonly string $captureMode,
        public readonly ?string $reference,
        public readonly TransactionStatus $status,
        public readonly ?string $processorReference,
        public readonly array $metadata,
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
            'merchant_id' => $this->merchantId,
            'idempotency_key' => $this->idempotencyKey,
            'type' => $this->type,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'settlement_currency' => $this->settlementCurrency,
            'payment_method_type' => $this->paymentMethodType,
            'payment_method_token' => $this->paymentMethodToken,
            'capture_mode' => $this->captureMode,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'processor_reference' => $this->processorReference,
            'metadata' => $this->metadata,
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
            merchantId: (string) $payload['merchant_id'],
            idempotencyKey: (string) $payload['idempotency_key'],
            type: (string) $payload['type'],
            amount: (string) $payload['amount'],
            currency: (string) $payload['currency'],
            settlementCurrency: (string) ($payload['settlement_currency'] ?? $payload['currency']),
            paymentMethodType: (string) $payload['payment_method_type'],
            paymentMethodToken: (string) $payload['payment_method_token'],
            captureMode: (string) $payload['capture_mode'],
            reference: isset($payload['reference']) ? (string) $payload['reference'] : null,
            status: TransactionStatus::from((string) $payload['status']),
            processorReference: isset($payload['processor_reference']) ? (string) $payload['processor_reference'] : null,
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
            createdAt: (string) $payload['created_at'],
            updatedAt: (string) $payload['updated_at']
        );
    }
}
