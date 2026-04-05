<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Application\CreateTransaction;

final class CreateTransactionCommand
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
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
        public readonly array $metadata,
        public readonly string $correlationId
    ) {
    }
}
