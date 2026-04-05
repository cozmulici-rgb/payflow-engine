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

    public function findByIdempotencyKey(string $merchantId, string $idempotencyKey): ?Transaction
    {
        foreach ($this->readJson($this->transactionsPath) as $row) {
            if (($row['merchant_id'] ?? null) === $merchantId && ($row['idempotency_key'] ?? null) === $idempotencyKey) {
                return (new EloquentTransaction($row))->toDomain();
            }
        }

        return null;
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
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
