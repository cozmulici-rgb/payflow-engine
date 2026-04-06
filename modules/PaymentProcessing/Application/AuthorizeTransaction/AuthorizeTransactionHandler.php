<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Application\AuthorizeTransaction;

use Modules\Audit\Application\WriteAuditRecord;
use Modules\FXCrossBorder\Application\LockRate\FxQuoteRequest;
use Modules\FXCrossBorder\Application\LockRate\FxRateLockService;
use Modules\Ledger\Application\LedgerPostingService;
use Modules\PaymentProcessing\Domain\Events\TransactionAuthorized;
use Modules\PaymentProcessing\Domain\Events\TransactionFailed;
use Modules\PaymentProcessing\Domain\TransactionStateMachine;
use Modules\PaymentProcessing\Domain\TransactionStatus;
use Modules\PaymentProcessing\Infrastructure\Messaging\KafkaCommandPublisher;
use Modules\PaymentProcessing\Infrastructure\Persistence\TransactionRepository;
use Modules\PaymentProcessing\Infrastructure\Providers\Fraud\FraudScreeningService;
use Modules\PaymentProcessing\Infrastructure\Providers\Processor\ProcessorRouter;

final class AuthorizeTransactionHandler
{
    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly ProcessorRouter $processors,
        private readonly FraudScreeningService $fraud,
        private readonly FxRateLockService $fxLocks,
        private readonly KafkaCommandPublisher $events,
        private readonly WriteAuditRecord $auditWriter,
        private readonly LedgerPostingService $ledger,
        private readonly string $processedEventsPath,
        private readonly string $configPath
    ) {
    }

    /**
     * @param array<string,mixed> $command
     */
    public function handle(array $command): void
    {
        $eventId = (string) ($command['transaction_id'] ?? '');
        if ($eventId === '' || $this->alreadyProcessed($eventId)) {
            return;
        }

        $transaction = $this->transactions->findById($eventId);
        if ($transaction === null || $transaction->status !== TransactionStatus::Pending) {
            $this->markProcessed($eventId);
            return;
        }

        if (!TransactionStateMachine::canTransition($transaction->status, TransactionStatus::Authorized)
            && !TransactionStateMachine::canTransition($transaction->status, TransactionStatus::Failed)) {
            throw new \RuntimeException('Transaction state machine does not allow phase 3 transitions');
        }

        $rateLock = null;
        if ($transaction->currency !== $transaction->settlementCurrency) {
            $rateLock = $this->fxLocks->lock(new FxQuoteRequest(
                transactionId: $transaction->id,
                amount: $transaction->amount,
                baseCurrency: $transaction->currency,
                quoteCurrency: $transaction->settlementCurrency
            ));

            if (isset($rateLock['expires_at']) && strtotime((string) $rateLock['expires_at']) < time()) {
                throw new \RuntimeException('FX rate lock has expired before authorization could proceed');
            }
        }

        $fraudDecision = $this->fraud->screen($transaction);
        if (!$fraudDecision['approved']) {
            $failed = $this->transactions->updateStatus(
                $transaction->id,
                TransactionStatus::Pending,
                TransactionStatus::Failed,
                [
                    'error_code' => 'fraud_rejected',
                    'error_message' => (string) $fraudDecision['reason'],
                    'fx_rate_lock_id' => $rateLock['id'] ?? null,
                    'settlement_amount' => $rateLock['settlement_amount'] ?? null,
                ]
            );

            $this->auditWriter->handle([
                'event_type' => 'transaction.authorization_failed',
                'actor_id' => $transaction->merchantId,
                'action' => 'authorize',
                'resource_type' => 'transaction',
                'resource_id' => $transaction->id,
                'correlation_id' => (string) ($command['correlation_id'] ?? ''),
                'context' => ['error_code' => 'fraud_rejected'],
            ]);

            $this->events->publish((new TransactionFailed(
                correlationId: (string) ($command['correlation_id'] ?? ''),
                transactionId: $failed->id,
                merchantId: $failed->merchantId,
                errorCode: 'fraud_rejected',
                errorMessage: (string) $fraudDecision['reason']
            ))->toPayload());
            $this->markProcessed($eventId);
            return;
        }

        $processor = $this->processors->route($transaction);

        try {
            $result = $processor->authorize($transaction, $rateLock);
        } catch (\RuntimeException $exception) {
            $result = $processor->inquire($transaction->idempotencyKey);
        }

        if (!$result->approved) {
            $failed = $this->transactions->updateStatus(
                $transaction->id,
                TransactionStatus::Pending,
                TransactionStatus::Failed,
                [
                    'processor_id' => $result->processorId,
                    'processor_reference' => $result->processorReference,
                    'error_code' => $result->errorCode ?? 'processor_timeout',
                    'error_message' => $result->errorMessage ?? 'Processor authorization failed',
                    'fx_rate_lock_id' => $rateLock['id'] ?? null,
                    'settlement_amount' => $rateLock['settlement_amount'] ?? null,
                ]
            );

            $this->auditWriter->handle([
                'event_type' => 'transaction.authorization_failed',
                'actor_id' => $transaction->merchantId,
                'action' => 'authorize',
                'resource_type' => 'transaction',
                'resource_id' => $transaction->id,
                'correlation_id' => (string) ($command['correlation_id'] ?? ''),
                'context' => ['error_code' => $failed->errorCode],
            ]);

            $this->events->publish((new TransactionFailed(
                correlationId: (string) ($command['correlation_id'] ?? ''),
                transactionId: $failed->id,
                merchantId: $failed->merchantId,
                errorCode: (string) $failed->errorCode,
                errorMessage: (string) $failed->errorMessage
            ))->toPayload());
            $this->markProcessed($eventId);
            return;
        }

        ['transaction' => $authorized, 'journal_entry_id' => $journalEntryId] = $this->withAuthorizationLedgerTransaction(
            fn (): array => $this->authorizeAndPostLedger($transaction, $result, $rateLock)
        );

        if ($rateLock !== null) {
            $this->fxLocks->markUsed((string) $rateLock['id']);
        }

        $this->auditWriter->handleLedgerPosting([
            'actor_id' => $transaction->merchantId,
            'correlation_id' => (string) ($command['correlation_id'] ?? ''),
            'context' => [
                'transaction_id' => $authorized->id,
                'processor_id' => $authorized->processorId,
                'processor_reference' => $authorized->processorReference,
            ],
        ], $journalEntryId);

        $this->auditWriter->handle([
            'event_type' => 'transaction.authorized',
            'actor_id' => $transaction->merchantId,
            'action' => 'authorize',
            'resource_type' => 'transaction',
            'resource_id' => $transaction->id,
            'correlation_id' => (string) ($command['correlation_id'] ?? ''),
            'context' => [
                'processor_id' => $authorized->processorId,
                'processor_reference' => $authorized->processorReference,
            ],
        ]);

        $this->events->publish((new TransactionAuthorized(
            correlationId: (string) ($command['correlation_id'] ?? ''),
            transactionId: $authorized->id,
            merchantId: $authorized->merchantId,
            processorId: (string) $authorized->processorId,
            processorReference: (string) $authorized->processorReference,
            amount: $authorized->amount,
            currency: $authorized->currency,
            settlementAmount: (string) ($authorized->settlementAmount ?? $authorized->amount),
            settlementCurrency: $authorized->settlementCurrency
        ))->toPayload());

        $this->markProcessed($eventId);
    }

    private function alreadyProcessed(string $eventId): bool
    {
        foreach ($this->readProcessedEvents() as $event) {
            if (($event['consumer_group'] ?? null) === 'payment-worker'
                && ($event['event_id'] ?? null) === $eventId) {
                return true;
            }
        }

        return false;
    }

    private function markProcessed(string $eventId): void
    {
        $events = $this->readProcessedEvents();
        $events[] = [
            'id' => $this->uuid(),
            'consumer_group' => 'payment-worker',
            'event_id' => $eventId,
            'processed_at' => gmdate(DATE_ATOM),
        ];
        file_put_contents($this->processedEventsPath, json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function readProcessedEvents(): array
    {
        if (!is_file($this->processedEventsPath)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->processedEventsPath), true);
        return is_array($decoded) ? $decoded : [];
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

    /**
     * @param array<string,mixed>|null $rateLock
     * @return array{transaction:\Modules\PaymentProcessing\Domain\Transaction,journal_entry_id:string}
     */
    private function authorizeAndPostLedger(
        \Modules\PaymentProcessing\Domain\Transaction $transaction,
        ProcessorAuthorizationResult $result,
        ?array $rateLock
    ): array {
        $authorized = $this->transactions->updateStatus(
            $transaction->id,
            TransactionStatus::Pending,
            TransactionStatus::Authorized,
            [
                'processor_id' => $result->processorId,
                'processor_reference' => $result->processorReference,
                'fx_rate_lock_id' => $rateLock['id'] ?? null,
                'settlement_amount' => $rateLock['settlement_amount'] ?? $transaction->amount,
                'error_code' => null,
                'error_message' => null,
            ]
        );

        return [
            'transaction' => $authorized,
            'journal_entry_id' => $this->ledger->postAuthorization($authorized),
        ];
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function withAuthorizationLedgerTransaction(callable $callback): mixed
    {
        $storagePath = dirname($this->processedEventsPath);
        $snapshots = $this->captureSnapshots([
            $storagePath . '/transactions.json',
            $storagePath . '/transaction_status_history.json',
            $storagePath . '/rate_locks.json',
            $storagePath . '/journal_entries.json',
            $storagePath . '/ledger_entries.json',
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
}
