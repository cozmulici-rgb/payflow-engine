<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Infrastructure\Persistence;

use Modules\PaymentProcessing\Domain\Transaction;
use Modules\PaymentProcessing\Domain\TransactionStatus;

final class TransactionRepository
{
    public function __construct(
        private readonly string $transactionsPath,
        private readonly string $statusHistoryPath
    ) {
    }

    public function createPending(Transaction $transaction): Transaction
    {
        $rows = $this->readJson($this->transactionsPath);
        $rows[] = EloquentTransaction::fromDomain($transaction)->attributes;
        $this->writeJson($this->transactionsPath, $rows);

        $history = $this->readJson($this->statusHistoryPath);
        $history[] = [
            'id' => $this->uuid(),
            'transaction_id' => $transaction->id,
            'status' => TransactionStatus::Pending->value,
            'reason' => null,
            'created_at' => $transaction->createdAt,
        ];
        $this->writeJson($this->statusHistoryPath, $history);

        return $transaction;
    }

    public function findById(string $transactionId): ?Transaction
    {
        foreach ($this->readJson($this->transactionsPath) as $row) {
            if (($row['id'] ?? null) === $transactionId) {
                return (new EloquentTransaction($row))->toDomain();
            }
        }

        return null;
    }

    /**
     * @return list<Transaction>
     */
    public function all(): array
    {
        return array_map(
            static fn (array $row): Transaction => (new EloquentTransaction($row))->toDomain(),
            $this->readJson($this->transactionsPath)
        );
    }

    public function findByIdempotencyKey(string $merchantId, string $idempotencyKey): ?Transaction
    {
        foreach ($this->readJson($this->transactionsPath) as $row) {
            if (($row['merchant_id'] ?? null) === $merchantId && ($row['idempotency_key'] ?? null) === $idempotencyKey) {
                return (new EloquentTransaction($row))->toDomain();
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function updateStatus(
        string $transactionId,
        TransactionStatus $expected,
        TransactionStatus $next,
        array $attributes = []
    ): Transaction {
        $rows = $this->readJson($this->transactionsPath);

        foreach ($rows as $index => $row) {
            if (($row['id'] ?? null) !== $transactionId) {
                continue;
            }

            $current = Transaction::fromArray($row);
            if ($current->status !== $expected) {
                throw new \RuntimeException(sprintf(
                    'Illegal transition from %s to %s',
                    $current->status->value,
                    $next->value
                ));
            }

            $updated = array_merge($row, $attributes, [
                'status' => $next->value,
                'updated_at' => gmdate(DATE_ATOM),
            ]);

            $rows[$index] = $updated;
            $this->writeJson($this->transactionsPath, $rows);

            $history = $this->readJson($this->statusHistoryPath);
            $history[] = [
                'id' => $this->uuid(),
                'transaction_id' => $transactionId,
                'status' => $next->value,
                'reason' => $attributes['error_code'] ?? null,
                'created_at' => $updated['updated_at'],
            ];
            $this->writeJson($this->statusHistoryPath, $history);

            return Transaction::fromArray($updated);
        }

        throw new \RuntimeException('Transaction not found');
    }

    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeJson(string $path, array $payload): void
    {
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
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
