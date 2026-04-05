<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Application\RefundTransaction;

use Modules\Audit\Application\WriteAuditRecord;
use Modules\Ledger\Application\LedgerPostingService;
use Modules\PaymentProcessing\Domain\Transaction;
use Modules\PaymentProcessing\Domain\TransactionStateMachine;
use Modules\PaymentProcessing\Domain\TransactionStatus;
use Modules\PaymentProcessing\Infrastructure\Messaging\KafkaCommandPublisher;
use Modules\PaymentProcessing\Infrastructure\Persistence\TransactionRepository;
use Modules\PaymentProcessing\Infrastructure\Providers\Processor\ProcessorRouter;

final class RefundTransactionHandler
{
    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly ProcessorRouter $processors,
        private readonly KafkaCommandPublisher $events,
        private readonly WriteAuditRecord $auditWriter,
        private readonly LedgerPostingService $ledger,
        private readonly string $storagePath
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

        if (!in_array($transaction->status, [TransactionStatus::Captured, TransactionStatus::Settled], true)
            || !TransactionStateMachine::canTransition($transaction->status, TransactionStatus::RefundPending)) {
            return ['status' => 422, 'body' => ['message' => 'Transaction is not eligible for refund']];
        }

        $capturedAmount = (string) ($transaction->metadata['captured_amount'] ?? $transaction->amount);
        if (!$this->isPositiveAmount($amount) || (float) $amount > (float) $capturedAmount) {
            return ['status' => 422, 'body' => ['message' => 'Refund amount is invalid']];
        }

        $processor = $this->processors->route($transaction);
        $result = $processor->refund($transaction, $amount);
        if (!$result->approved) {
            return ['status' => 422, 'body' => [
                'message' => 'Refund failed',
                'error_code' => $result->errorCode,
            ]];
        }

        ['transaction' => $refunded, 'journal_entry_id' => $journalEntryId] = $this->withLedgerTransaction(
            fn (): array => $this->refundAndPostLedger($transaction, $amount)
        );

        $this->auditWriter->handleLedgerPosting([
            'actor_id' => $merchantId,
            'correlation_id' => $correlationId,
            'event_type' => 'ledger.refund_posted',
            'context' => [
                'transaction_id' => $refunded->id,
                'refund_amount' => $this->normalizeAmount($amount),
            ],
        ], $journalEntryId);

        $this->auditWriter->handle([
            'event_type' => 'transaction.refunded',
            'actor_id' => $merchantId,
            'action' => 'refund',
            'resource_type' => 'transaction',
            'resource_id' => $refunded->id,
            'correlation_id' => $correlationId,
            'context' => [
                'amount' => $this->normalizeAmount($amount),
                'processor_reference' => $result->processorReference,
            ],
        ]);

        $this->events->publish([
            'event_id' => $this->uuid(),
            'event_type' => 'transaction.refunded',
            'occurred_at' => gmdate(DATE_ATOM),
            'correlation_id' => $correlationId,
            'transaction_id' => $refunded->id,
            'merchant_id' => $refunded->merchantId,
            'amount' => $this->normalizeAmount($amount),
            'currency' => $refunded->currency,
        ]);

        return [
            'status' => 202,
            'body' => [
                'data' => [
                    'transaction_id' => $refunded->id,
                    'status' => $refunded->status->value,
                    'refund_amount' => $refunded->metadata['refunded_amount'] ?? null,
                ],
            ],
        ];
    }

    /**
     * @return array{transaction:Transaction,journal_entry_id:string}
     */
    private function refundAndPostLedger(Transaction $transaction, string $amount): array
    {
        $metadata = $transaction->metadata;
        $metadata['refunded_amount'] = $this->normalizeAmount($amount);

        $pending = $this->transactions->updateStatus(
            $transaction->id,
            $transaction->status,
            TransactionStatus::RefundPending,
            [
                'metadata' => $metadata,
            ]
        );

        $refunded = $this->transactions->updateStatus(
            $pending->id,
            TransactionStatus::RefundPending,
            TransactionStatus::Refunded,
            [
                'metadata' => $metadata,
            ]
        );

        return [
            'transaction' => $refunded,
            'journal_entry_id' => $this->ledger->postRefund($refunded),
        ];
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function withLedgerTransaction(callable $callback): mixed
    {
        $snapshots = $this->captureSnapshots([
            $this->storagePath . '/transactions.json',
            $this->storagePath . '/transaction_status_history.json',
            $this->storagePath . '/journal_entries.json',
            $this->storagePath . '/ledger_entries.json',
        ]);

        try {
            return $callback();
        } catch (\Throwable $exception) {
            $this->restoreSnapshots($snapshots);
            throw $exception;
        }
    }

    /**
     * @param list<string> $paths
     * @return array<string,string|null>
     */
    private function captureSnapshots(array $paths): array
    {
        $snapshots = [];

        foreach ($paths as $path) {
            $snapshots[$path] = is_file($path) ? (string) file_get_contents($path) : null;
        }

        return $snapshots;
    }

    /**
     * @param array<string,string|null> $snapshots
     */
    private function restoreSnapshots(array $snapshots): void
    {
        foreach ($snapshots as $path => $contents) {
            if ($contents === null) {
                if (is_file($path)) {
                    unlink($path);
                }

                continue;
            }

            file_put_contents($path, $contents);
        }
    }

    private function isPositiveAmount(string $amount): bool
    {
        return is_numeric($amount) && (float) $amount > 0;
    }

    private function normalizeAmount(string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
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
