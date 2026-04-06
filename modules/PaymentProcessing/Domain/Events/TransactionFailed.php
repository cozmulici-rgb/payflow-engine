<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Domain\Events;

final class TransactionFailed
{
    private readonly string $eventId;
    private readonly string $occurredAt;

    public function __construct(
        private readonly string $correlationId,
        private readonly string $transactionId,
        private readonly string $merchantId,
        private readonly string $errorCode,
        private readonly string $errorMessage
    ) {
        $this->eventId = $this->uuid();
        $this->occurredAt = gmdate(DATE_ATOM);
    }

    /**
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_type' => 'transaction.failed',
            'occurred_at' => $this->occurredAt,
            'correlation_id' => $this->correlationId,
            'transaction_id' => $this->transactionId,
            'merchant_id' => $this->merchantId,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
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
