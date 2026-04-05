<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Application\AuthorizeTransaction;

final class ProcessorAuthorizationResult
{
    public function __construct(
        public readonly bool $approved,
        public readonly string $processorId,
        public readonly string $processorReference,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null
    ) {
    }
}
