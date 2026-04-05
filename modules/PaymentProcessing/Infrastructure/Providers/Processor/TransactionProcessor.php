<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Infrastructure\Providers\Processor;

use Modules\PaymentProcessing\Application\AuthorizeTransaction\ProcessorAuthorizationResult;
use Modules\PaymentProcessing\Application\CaptureTransaction\ProcessorCaptureResult;
use Modules\PaymentProcessing\Application\RefundTransaction\ProcessorRefundResult;
use Modules\PaymentProcessing\Domain\Transaction;

interface TransactionProcessor
{
    /**
     * @param array<string,mixed>|null $rateLock
     */
    public function authorize(Transaction $transaction, ?array $rateLock): ProcessorAuthorizationResult;

    public function inquire(string $idempotencyKey): ProcessorAuthorizationResult;

    public function capture(Transaction $transaction, string $amount): ProcessorCaptureResult;

    public function refund(Transaction $transaction, string $amount): ProcessorRefundResult;
}
