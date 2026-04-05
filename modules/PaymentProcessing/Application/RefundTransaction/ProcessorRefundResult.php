<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Application\RefundTransaction;

final class ProcessorRefundResult
{
    public function __construct(
        public readonly bool $approved,
        public readonly string $processorReference,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null
    ) {
    }
}
