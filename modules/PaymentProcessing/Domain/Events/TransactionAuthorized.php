<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Domain\Events;

final class TransactionAuthorized
{
    public function __construct(
        private readonly string $correlationId,
        private readonly string $transactionId,
        private readonly string $merchantId,
        private readonly string $processorId,
        private readonly string $processorReference,
        private readonly string $amount,
        private readonly string $currency,
        private readonly string $settlementAmount,
        private readonly string $settlementCurrency
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return [
            'event_id' => $this->uuid(),
            'event_type' => 'transaction.authorized',
            'occurred_at' => gmdate(DATE_ATOM),
            'correlation_id' => $this->correlationId,
            'transaction_id' => $this->transactionId,
            'merchant_id' => $this->merchantId,
            'processor_id' => $this->processorId,
            'processor_reference' => $this->processorReference,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'settlement_amount' => $this->settlementAmount,
            'settlement_currency' => $this->settlementCurrency,
        ];
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
