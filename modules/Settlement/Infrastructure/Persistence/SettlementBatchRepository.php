<?php

declare(strict_types=1);

namespace Modules\Settlement\Infrastructure\Persistence;

use Modules\PaymentProcessing\Domain\Transaction;
use Modules\Settlement\Domain\SettlementBatch;

final class SettlementBatchRepository
{
    public function __construct(
        private readonly string $batchesPath,
        private readonly string $itemsPath
    ) {
    }

    /**
     * @param list<Transaction> $transactions
     */
    public function createOpen(string $processorId, string $currency, string $batchDate, array $transactions): SettlementBatch
    {
        $timestamp = gmdate(DATE_ATOM);
        $batch = new SettlementBatch(
            id: $this->uuid(),
            processorId: $processorId,
            currency: $currency,
            batchDate: $batchDate,
            status: 'open',
            itemCount: count($transactions),
            totalAmount: $this->sumAmounts($transactions),
            artifactKey: null,
            submittedAt: null,
            exceptionReason: null,
            createdAt: $timestamp,
            updatedAt: $timestamp
        );

        $batches = $this->readJson($this->batchesPath);
        $batches[] = $batch->toArray();
        $this->writeJson($this->batchesPath, $batches);

        $items = $this->readJson($this->itemsPath);
        foreach ($transactions as $transaction) {
            $items[] = [
                'id' => $this->uuid(),
                'batch_id' => $batch->id,
                'transaction_id' => $transaction->id,
                'processor_id' => $transaction->processorId,
                'processor_reference' => $transaction->processorReference,
                'amount' => (string) ($transaction->settlementAmount ?? $transaction->amount),
                'currency' => strtoupper($transaction->settlementCurrency !== '' ? $transaction->settlementCurrency : $transaction->currency),
                'status' => 'pending_submission',
                'created_at' => $timestamp,
            ];
        }
        $this->writeJson($this->itemsPath, $items);

        return $batch;
    }

    public function find(string $batchId): ?SettlementBatch
    {
        foreach ($this->readJson($this->batchesPath) as $row) {
            if (($row['id'] ?? null) === $batchId) {
                return SettlementBatch::fromArray($row);
            }
        }

        return null;
    }

    /**
     * @return list<SettlementBatch>
     */
    public function all(): array
    {
        return array_map(
            static fn (array $row): SettlementBatch => SettlementBatch::fromArray($row),
            $this->readJson($this->batchesPath)
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function itemsForBatch(string $batchId): array
    {
        return array_values(array_filter(
            $this->readJson($this->itemsPath),
            static fn (array $row): bool => ($row['batch_id'] ?? null) === $batchId
        ));
    }

    /**
     * @return list<string>
     */
    public function batchedTransactionIds(): array
    {
        return array_values(array_map(
            static fn (array $row): string => (string) $row['transaction_id'],
            $this->readJson($this->itemsPath)
        ));
    }

    public function markSubmitted(string $batchId, string $artifactKey, \DateTimeImmutable $submittedAt): SettlementBatch
    {
        return $this->updateBatch($batchId, [
            'status' => 'submitted',
            'artifact_key' => $artifactKey,
            'submitted_at' => $submittedAt->format(DATE_ATOM),
            'exception_reason' => null,
        ]);
    }

    public function markException(string $batchId, string $reason): SettlementBatch
    {
        return $this->updateBatch($batchId, [
            'status' => 'exception',
            'exception_reason' => $reason,
        ]);
    }

    /**
     * @param array<string,mixed> $attributes
     */
    private function updateBatch(string $batchId, array $attributes): SettlementBatch
    {
        $rows = $this->readJson($this->batchesPath);
        foreach ($rows as $index => $row) {
            if (($row['id'] ?? null) !== $batchId) {
                continue;
            }

            $updated = array_merge($row, $attributes, [
                'updated_at' => gmdate(DATE_ATOM),
            ]);
            $rows[$index] = $updated;
            $this->writeJson($this->batchesPath, $rows);

            return SettlementBatch::fromArray($updated);
        }

        throw new \RuntimeException('Settlement batch not found');
    }

    /**
     * @param list<Transaction> $transactions
     */
    private function sumAmounts(array $transactions): string
    {
        $total = 0.0;
        foreach ($transactions as $transaction) {
            $total += (float) ($transaction->settlementAmount ?? $transaction->amount);
        }

        return number_format($total, 4, '.', '');
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
