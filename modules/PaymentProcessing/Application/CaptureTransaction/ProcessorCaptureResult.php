<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Application\CaptureTransaction;

final class ProcessorCaptureResult
{
    public function __construct(
        public readonly bool $approved,
        public readonly string $processorReference,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null
    ) {
    }
}
