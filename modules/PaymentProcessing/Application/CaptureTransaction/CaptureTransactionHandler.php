<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Application\CaptureTransaction;

use Modules\Audit\Application\WriteAuditRecord;
use Modules\PaymentProcessing\Domain\TransactionStateMachine;
use Modules\PaymentProcessing\Domain\TransactionStatus;
use Modules\PaymentProcessing\Domain\Transaction;
use Modules\PaymentProcessing\Infrastructure\Messaging\KafkaCommandPublisher;
use Modules\PaymentProcessing\Infrastructure\Persistence\TransactionRepository;
use Modules\PaymentProcessing\Infrastructure\Providers\Processor\ProcessorRouter;

final class CaptureTransactionHandler
{
    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly ProcessorRouter $processors,
        private readonly KafkaCommandPublisher $events,
        private readonly WriteAuditRecord $auditWriter
    ) {
    }

    /**
     * @return array{status:int,body:array<string,mixed>}
     */
    public function handle(string $merchantId, string $transactionId, string $amount, string $correlationId): array
    {
        $transaction = $this->transactions->findById($transactionId);
        if ($transaction === null || $transaction->merchantId !== $merchantId) {
            return ['status' => 404, 'body' => ['message' => 'Transaction not found']];
        }

        if ($transaction->status !== TransactionStatus::Authorized
            || !TransactionStateMachine::canTransition($transaction->status, TransactionStatus::Captured)) {
            return ['status' => 422, 'body' => ['message' => 'Transaction is not eligible for capture']];
        }

        if (!$this->isPositiveAmount($amount) || bccomp($amount, $transaction->amount, 4) > 0) {
            return ['status' => 422, 'body' => ['message' => 'Capture amount is invalid']];
        }

        $processor = $this->processors->route($transaction);
        $result = $processor->capture($transaction, $amount);
        if (!$result->approved) {
            return ['status' => 422, 'body' => [
                'message' => 'Capture failed',
                'error_code' => $result->errorCode,
            ]];
        }

        $metadata = $transaction->metadata;
        $metadata['captured_amount'] = $this->normalizeAmount($amount);

        $captured = $this->transactions->updateStatus(
            $transaction->id,
            TransactionStatus::Authorized,
            TransactionStatus::Captured,
            [
                'processor_reference' => $result->processorReference,
                'settlement_amount' => $this->resolveSettlementAmount($transaction, $amount),
                'metadata' => $metadata,
            ]
        );

        $this->auditWriter->handle([
            'event_type' => 'transaction.captured',
            'actor_id' => $merchantId,
            'action' => 'capture',
            'resource_type' => 'transaction',
            'resource_id' => $captured->id,
            'correlation_id' => $correlationId,
            'context' => [
                'amount' => $amount,
                'processor_reference' => $captured->processorReference,
            ],
        ]);

        $this->events->publish([
            'event_id' => $this->uuid(),
            'event_type' => 'transaction.captured',
            'occurred_at' => gmdate(DATE_ATOM),
            'correlation_id' => $correlationId,
            'transaction_id' => $captured->id,
            'merchant_id' => $captured->merchantId,
            'amount' => $this->normalizeAmount($amount),
            'currency' => $captured->currency,
        ]);

        return [
            'status' => 202,
            'body' => [
                'data' => [
                    'transaction_id' => $captured->id,
                    'status' => $captured->status->value,
                    'captured_amount' => $metadata['captured_amount'],
                ],
            ],
        ];
    }

    private function isPositiveAmount(string $amount): bool
    {
        return is_numeric($amount) && bccomp($amount, '0', 4) > 0;
    }

    private function normalizeAmount(string $amount): string
    {
        return bcadd($amount, '0', 2);
    }

    private function resolveSettlementAmount(Transaction $transaction, string $amount): string
    {
        if ($transaction->currency !== $transaction->settlementCurrency && $transaction->settlementAmount !== null) {
            return $transaction->settlementAmount;
        }

        return $this->normalizeAmount($amount);
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
