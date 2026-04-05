<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Infrastructure\Providers\Processor;

use Modules\PaymentProcessing\Application\AuthorizeTransaction\ProcessorAuthorizationResult;
use Modules\PaymentProcessing\Domain\Transaction;

final class ProcessorRouter
{
    public function route(Transaction $transaction): TransactionProcessor
    {
        $channel = (string) ($transaction->metadata['channel'] ?? 'default');

        return match ($channel) {
            'processor_b' => new class implements TransactionProcessor {
                public function authorize(Transaction $transaction, ?array $rateLock): ProcessorAuthorizationResult
                {
                    return new ProcessorAuthorizationResult(true, 'processor_b', 'proc_b_' . substr($transaction->id, 0, 8));
                }

                public function inquire(string $idempotencyKey): ProcessorAuthorizationResult
                {
                    return new ProcessorAuthorizationResult(false, 'processor_b', 'proc_b_inquiry', 'processor_timeout', 'Processor status unavailable');
                }
            },
            'timeout-confirm' => new class implements TransactionProcessor {
                public function authorize(Transaction $transaction, ?array $rateLock): ProcessorAuthorizationResult
                {
                    throw new \RuntimeException('timeout');
                }

                public function inquire(string $idempotencyKey): ProcessorAuthorizationResult
                {
                    return new ProcessorAuthorizationResult(true, 'processor_a', 'proc_inquiry_' . substr($idempotencyKey, -4));
                }
            },
            'timeout-fail' => new class implements TransactionProcessor {
                public function authorize(Transaction $transaction, ?array $rateLock): ProcessorAuthorizationResult
                {
                    throw new \RuntimeException('timeout');
                }

                public function inquire(string $idempotencyKey): ProcessorAuthorizationResult
                {
                    return new ProcessorAuthorizationResult(false, 'processor_a', 'proc_inquiry_fail', 'processor_timeout', 'Processor status unavailable');
                }
            },
            default => new class implements TransactionProcessor {
                public function authorize(Transaction $transaction, ?array $rateLock): ProcessorAuthorizationResult
                {
                    return new ProcessorAuthorizationResult(true, 'processor_a', 'proc_a_' . substr($transaction->id, 0, 8));
                }

                public function inquire(string $idempotencyKey): ProcessorAuthorizationResult
                {
                    return new ProcessorAuthorizationResult(false, 'processor_a', 'proc_a_inquiry', 'processor_timeout', 'Processor status unavailable');
                }
            },
        };
    }
}
