<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Application\CreateTransaction;

use Modules\Audit\Application\WriteAuditRecord;
use Modules\PaymentProcessing\Domain\Transaction;
use Modules\PaymentProcessing\Domain\TransactionStatus;
use Modules\PaymentProcessing\Infrastructure\Messaging\KafkaCommandPublisher;
use Modules\PaymentProcessing\Infrastructure\Persistence\IdempotencyRepository;
use Modules\PaymentProcessing\Infrastructure\Persistence\TransactionRepository;

final class CreateTransactionHandler
{
    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly IdempotencyRepository $idempotency,
        private readonly KafkaCommandPublisher $publisher,
        private readonly WriteAuditRecord $auditWriter
    ) {
    }

    /**
     * @return array{status:int,body:array<string,mixed>}
     */
    public function handle(CreateTransactionCommand $command): array
    {
        $scopeKey = $this->scopeKey($command->merchantId, $command->idempotencyKey);
        $cached = $this->idempotency->findResponseByKey($scopeKey);
        if ($cached !== null) {
            return $cached;
        }

        $timestamp = gmdate(DATE_ATOM);
        $transaction = new Transaction(
            id: $this->uuid(),
            merchantId: $command->merchantId,
            idempotencyKey: $command->idempotencyKey,
            type: $command->type,
            amount: $command->amount,
            currency: $command->currency,
            settlementCurrency: $command->settlementCurrency,
            paymentMethodType: $command->paymentMethodType,
            paymentMethodToken: $command->paymentMethodToken,
            captureMode: $command->captureMode,
            reference: $command->reference,
            status: TransactionStatus::Pending,
            processorId: null,
            processorReference: null,
            settlementAmount: null,
            fxRateLockId: null,
            errorCode: null,
            errorMessage: null,
            metadata: $command->metadata,
            createdAt: $timestamp,
            updatedAt: $timestamp
        );

        $this->transactions->createPending($transaction);

        $this->publisher->publish([
            'command' => 'transaction.process',
            'transaction_id' => $transaction->id,
            'merchant_id' => $transaction->merchantId,
            'idempotency_key' => $transaction->idempotencyKey,
            'correlation_id' => $command->correlationId,
        ]);

        $this->auditWriter->handle([
            'event_type' => 'transaction.created',
            'actor_id' => $command->merchantId,
            'action' => 'create',
            'resource_type' => 'transaction',
            'resource_id' => $transaction->id,
            'correlation_id' => $command->correlationId,
            'context' => [
                'status' => $transaction->status->value,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
            ],
        ]);

        $response = [
            'status' => 202,
            'body' => [
                'data' => [
                    'transaction_id' => $transaction->id,
                    'status' => $transaction->status->value,
                    'idempotency_key' => $transaction->idempotencyKey,
                    'received_at' => $transaction->createdAt,
                ],
            ],
        ];

        $this->idempotency->storeAcceptedResponse(
            $scopeKey,
            $response,
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+1 day')
        );

        return $response;
    }

    private function scopeKey(string $merchantId, string $idempotencyKey): string
    {
        return $merchantId . ':' . $idempotencyKey;
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
