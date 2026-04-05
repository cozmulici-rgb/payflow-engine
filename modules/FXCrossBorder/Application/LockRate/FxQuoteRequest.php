<?php

declare(strict_types=1);

namespace Modules\FXCrossBorder\Application\LockRate;

final class FxQuoteRequest
{
    public function __construct(
        public readonly string $transactionId,
        public readonly string $amount,
        public readonly string $baseCurrency,
        public readonly string $quoteCurrency
    ) {
    }
}
